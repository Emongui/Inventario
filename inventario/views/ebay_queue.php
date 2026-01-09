<?php
// /inventario/views/ebay_queue.php

ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);
mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

require_once '../includes/auth.php';
require_once '../includes/db_connection.php';
include '../includes/header.php';

function h($v): string {
  return $v === null ? '' : htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
}

$role = $_SESSION['role'] ?? 'viewer';
$canUse = in_array($role, ['admin','inventory','inventory_manager','supervisor'], true);
if (!$canUse) {
  http_response_code(403);
  echo "<div class='container mt-4'><div class='alert alert-danger'>Access denied.</div></div>";
  include '../includes/footer.php';
  exit;
}

$q      = trim($_GET['q'] ?? '');
$status = trim($_GET['status'] ?? '');
$errMsg = trim($_GET['err'] ?? '');

$where  = [];
$params = [];
$types  = '';

$where[] = "(d.process_area = 'EBAY' OR d.bin_location LIKE 'EBAY%')";

if ($q !== '') {
  $where[] = "(d.serial_number LIKE CONCAT('%', ?, '%') OR p.product_id LIKE CONCAT('%', ?, '%'))";
  $types  .= "ss";
  $params[] = $q;
  $params[] = $q;
}

if ($status !== '') {
  $where[] = "COALESCE(el.status, 'QUEUE') = ?";
  $types  .= "s";
  $params[] = $status;
}

$sql = "
  SELECT
    d.id,
    d.serial_number,
    d.category,
    d.grade,
    d.bin_location,
    d.last_updated,
    p.product_id AS product_sku,
    dc.defect_code,

    el.id AS listing_id,
    COALESCE(el.status, 'QUEUE') AS listing_status,
    el.price,
    el.ebay_item_id,
    el.ebay_listing_url,

    COALESCE((
      SELECT COUNT(*)
      FROM ebay_listing_photos ph
      WHERE ph.listing_id = el.id
    ), 0) AS photos_count

  FROM defectives_inventory d
  LEFT JOIN product_ids p ON p.id = d.product_id
  LEFT JOIN defect_codes dc ON dc.id = d.defect_code_id
  LEFT JOIN ebay_listings el ON el.inventory_id = d.id

  WHERE " . implode(" AND ", $where) . "
  ORDER BY d.last_updated DESC
  LIMIT 500
";

$stmt = $conn->prepare($sql);
if ($types !== '') {
  $stmt->bind_param($types, ...$params);
}
$stmt->execute();
$res  = $stmt->get_result();
$rows = $res->fetch_all(MYSQLI_ASSOC);
$stmt->close();

$opts = ['QUEUE','PREP','PHOTO_PENDING','READY_TO_LIST','LISTED','SOLD','CANCELLED','ERROR'];
?>

<div class="container mt-4">
  <div class="d-flex justify-content-between align-items-center mb-3">
    <h3 class="m-0"><i class="bi bi-bag"></i> eBay Queue</h3>
    <div class="text-muted small">Max 500 results</div>
  </div>

  <?php if ($errMsg !== ''): ?>
    <div class="alert alert-warning">
      <b>Action blocked:</b> <?= h($errMsg) ?>
    </div>
  <?php endif; ?>

  <form class="row g-2 mb-3" method="get">
    <div class="col-md-6">
      <input class="form-control" name="q" value="<?= h($q) ?>" placeholder="Search by Serial or Product SKU...">
    </div>

    <div class="col-md-3">
      <select class="form-select" name="status">
        <option value="">All statuses</option>
        <?php foreach ($opts as $opt): ?>
          <option value="<?= h($opt) ?>" <?= ($status === $opt ? 'selected' : '') ?>><?= h($opt) ?></option>
        <?php endforeach; ?>
      </select>
    </div>

    <div class="col-md-3 d-grid">
      <button class="btn btn-primary"><i class="bi bi-search"></i> Filter</button>
    </div>
  </form>

  <div class="card shadow-sm">
    <div class="card-body p-0">
      <div class="table-responsive">
        <table class="table table-striped table-hover mb-0 align-middle">
          <thead class="table-dark">
            <tr>
              <th>ID</th>
              <th>Serial</th>
              <th>SKU</th>
              <th>Category</th>
              <th>Grade</th>
              <th>Defect</th>
              <th>BIN</th>
              <th>Listing</th>
              <th>Photos</th>
              <th>Price</th>
              <th style="width: 520px;">Actions</th>
            </tr>
          </thead>
          <tbody>
            <?php if (!$rows): ?>
              <tr><td colspan="11" class="text-center p-4 text-muted">No items found.</td></tr>
            <?php else: foreach ($rows as $r): ?>
              <?php
                $invId     = (int)$r['id'];
                $lstStatus = (string)($r['listing_status'] ?? 'QUEUE');
                $photos    = (int)($r['photos_count'] ?? 0);
                $listingId = (int)($r['listing_id'] ?? 0);

                $modalListedId = "markListedModal_" . $invId;
                $modalPriceId  = "priceModal_" . $invId;

                $price = $r['price'];
                $priceText = ($price !== null && $price !== '') ? '$'.number_format((float)$price, 2) : '';
              ?>
              <tr>
                <td><?= $invId ?></td>
                <td><code><?= h($r['serial_number']) ?></code></td>
                <td><?= h($r['product_sku']) ?></td>
                <td><?= h($r['category']) ?></td>
                <td><?= h($r['grade']) ?></td>
                <td><?= h($r['defect_code']) ?></td>
                <td><?= h($r['bin_location']) ?></td>

                <td>
                  <span class="badge bg-secondary"><?= h($lstStatus) ?></span>
                  <?php if (!empty($r['ebay_item_id'])): ?>
                    <div class="small text-muted">Item: <?= h($r['ebay_item_id']) ?></div>
                  <?php endif; ?>
                </td>

                <td><span class="badge bg-info text-dark"><?= $photos ?>/7</span></td>

                <td>
                  <?= $priceText !== '' ? h($priceText) : "<span class='text-muted'>—</span>" ?>
                </td>

                <td>
                  <div class="d-flex gap-2 flex-wrap align-items-center">

                    <form method="post" action="/inventario/views/ebay_create_draft.php" class="d-inline">
                      <input type="hidden" name="id" value="<?= $invId ?>">
                      <button class="btn btn-outline-primary btn-sm" type="submit">
                        <i class="bi bi-file-earmark-plus"></i> Create Draft
                      </button>
                    </form>

                    <a class="btn btn-outline-success btn-sm"
                       href="/inventario/views/ebay_photos.php?id=<?= $invId ?>">
                      <i class="bi bi-camera"></i> Photos
                    </a>

                    <!-- Set Price -->
                    <button class="btn btn-outline-info btn-sm"
                            type="button"
                            data-bs-toggle="modal"
                            data-bs-target="#<?= h($modalPriceId) ?>"
                            <?= ($listingId <= 0 ? 'disabled' : '') ?>>
                      <i class="bi bi-cash-coin"></i> Set Price
                    </button>

                    <form method="post" action="/inventario/views/ebay_mark_ready.php" class="d-inline">
                      <input type="hidden" name="id" value="<?= $invId ?>">
                      <button class="btn btn-outline-dark btn-sm" type="submit">
                        <i class="bi bi-check2-circle"></i> Mark Ready
                      </button>
                    </form>

                    <a class="btn btn-outline-secondary btn-sm"
                       href="/inventario/views/ebay_template.php?id=<?= $invId ?>"
                       target="_blank" rel="noopener">
                      <i class="bi bi-eye"></i> Template
                    </a>

                    <!-- Mark Listed -->
                    <button class="btn btn-outline-warning btn-sm"
                            type="button"
                            data-bs-toggle="modal"
                            data-bs-target="#<?= h($modalListedId) ?>"
                            <?= ($listingId <= 0 ? 'disabled' : '') ?>>
                      <i class="bi bi-upload"></i> Mark Listed
                    </button>

                  </div>

                  <!-- Price Modal -->
                  <div class="modal fade" id="<?= h($modalPriceId) ?>" tabindex="-1" aria-hidden="true">
                    <div class="modal-dialog">
                      <div class="modal-content">
                        <form method="post" action="/inventario/views/ebay_set_price.php">
                          <div class="modal-header">
                            <h5 class="modal-title">Set Price</h5>
                            <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                          </div>
                          <div class="modal-body">
                            <input type="hidden" name="id" value="<?= $invId ?>">

                            <div class="mb-2">
                              <label class="form-label">New Price (USD)</label>
                              <input class="form-control" name="price"
                                     value="<?= h($price !== null && $price !== '' ? number_format((float)$price, 2) : '') ?>"
                                     placeholder="e.g. 199.99" required>
                            </div>

                            <div class="alert alert-info small m-0">
                              Manual pricing.
                            </div>
                          </div>
                          <div class="modal-footer">
                            <button class="btn btn-secondary" type="button" data-bs-dismiss="modal">Cancel</button>
                            <button class="btn btn-info" type="submit">
                              <i class="bi bi-check2"></i> Save
                            </button>
                          </div>
                        </form>
                      </div>
                    </div>
                  </div>

                  <!-- Mark Listed Modal -->
                  <div class="modal fade" id="<?= h($modalListedId) ?>" tabindex="-1" aria-hidden="true">
                    <div class="modal-dialog">
                      <div class="modal-content">
                        <form method="post" action="/inventario/views/ebay_mark_listed.php">
                          <div class="modal-header">
                            <h5 class="modal-title">Mark as LISTED</h5>
                            <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                          </div>
                          <div class="modal-body">
                            <input type="hidden" name="id" value="<?= $invId ?>">

                            <div class="mb-2">
                              <label class="form-label">eBay Item ID</label>
                              <input class="form-control" name="ebay_item_id" value="<?= h($r['ebay_item_id']) ?>" placeholder="e.g. 123456789012">
                            </div>

                            <div class="mb-2">
                              <label class="form-label">eBay Listing URL</label>
                              <input class="form-control" name="ebay_listing_url" value="<?= h($r['ebay_listing_url']) ?>" placeholder="https://www.ebay.com/itm/...">
                            </div>

                            <div class="alert alert-info small m-0">
                              Paste at least <b>Item ID</b> or <b>URL</b>. Status will be set to <b>LISTED</b>.
                            </div>
                          </div>
                          <div class="modal-footer">
                            <button class="btn btn-secondary" type="button" data-bs-dismiss="modal">Cancel</button>
                            <button class="btn btn-warning" type="submit">
                              <i class="bi bi-check2"></i> Save
                            </button>
                          </div>
                        </form>
                      </div>
                    </div>
                  </div>

                </td>
              </tr>
            <?php endforeach; endif; ?>
          </tbody>
        </table>
      </div>
    </div>
  </div>

  <div class="alert alert-info mt-3">
    <b>Flow:</b> Create Draft → Upload Photos (7) → Set Price (manual) → Mark Ready → Publish on eBay → Mark Listed
  </div>
</div>

<?php include '../includes/footer.php'; ?>
