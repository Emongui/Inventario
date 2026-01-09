<?php
// /inventario/views/ebay_photos.php
// Uses ebay_listing_photos.photo_url + position
// Files stored in /inventario/uploads/ebay/{inventory_id}/

ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);
mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

require_once '../includes/auth.php';
require_once '../includes/db_connection.php';

function h($v): string {
  return $v === null ? '' : htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
}

$role = $_SESSION['role'] ?? 'viewer';
if (!in_array($role, ['admin','inventory','inventory_manager','supervisor'], true)) {
  http_response_code(403);
  exit('Access denied');
}

$inventoryId = (int)($_GET['id'] ?? 0);
if ($inventoryId <= 0) exit('Invalid id');

// Listing must exist
$st = $conn->prepare("SELECT id FROM ebay_listings WHERE inventory_id = ? LIMIT 1");
$st->bind_param("i", $inventoryId);
$st->execute();
$listing = $st->get_result()->fetch_assoc();
$st->close();

if (!$listing) {
  // No header include yet (safe)
  include '../includes/header.php';
  echo "<div class='container mt-4'>
          <div class='alert alert-warning'>
            No listing exists yet. Click <b>Create Draft</b> first.
          </div>
          <a class='btn btn-secondary' href='/inventario/views/ebay_queue.php'>Back</a>
        </div>";
  include '../includes/footer.php';
  exit;
}

$listingId = (int)$listing['id'];

/* =========================
   DELETE PHOTO (POST)
========================= */
if (isset($_POST['delete_photo_id'])) {
  $photoId = (int)$_POST['delete_photo_id'];

  $get = $conn->prepare("
    SELECT photo_url
    FROM ebay_listing_photos
    WHERE id=? AND listing_id=?
    LIMIT 1
  ");
  $get->bind_param("ii", $photoId, $listingId);
  $get->execute();
  $row = $get->get_result()->fetch_assoc();
  $get->close();

  if ($row && !empty($row['photo_url'])) {
    $abs = $_SERVER['DOCUMENT_ROOT'] . $row['photo_url'];
    if (is_file($abs)) @unlink($abs);
  }

  $del = $conn->prepare("DELETE FROM ebay_listing_photos WHERE id=? AND listing_id=? LIMIT 1");
  $del->bind_param("ii", $photoId, $listingId);
  $del->execute();
  $del->close();

  header("Location: /inventario/views/ebay_photos.php?id=".$inventoryId);
  exit;
}

/* =========================
   UPLOAD PHOTOS (POST)
========================= */
$uploadError = '';

if (isset($_FILES['photos'])) {

  $baseRel = "/inventario/uploads/ebay/".$inventoryId."/";
  $baseAbs = $_SERVER['DOCUMENT_ROOT'] . $baseRel;

  if (!is_dir($baseAbs) && !@mkdir($baseAbs, 0775, true)) {
    $uploadError = "Cannot create directory: ".$baseRel;
  }

  if ($uploadError === '') {

    $allowed = ['jpg','jpeg','png','webp'];
    $maxSize = 8 * 1024 * 1024;

    $names = $_FILES['photos']['name'] ?? [];
    $tmp   = $_FILES['photos']['tmp_name'] ?? [];
    $sizes = $_FILES['photos']['size'] ?? [];
    $errs  = $_FILES['photos']['error'] ?? [];

    // current max position
    $mx = $conn->prepare("
      SELECT COALESCE(MAX(position),0) AS m
      FROM ebay_listing_photos
      WHERE listing_id=?
    ");
    $mx->bind_param("i", $listingId);
    $mx->execute();
    $mrow = $mx->get_result()->fetch_assoc();
    $mx->close();
    $pos = (int)($mrow['m'] ?? 0);

    $ins = $conn->prepare("
      INSERT INTO ebay_listing_photos
        (listing_id, position, photo_url, sha1, created_at)
      VALUES
        (?, ?, ?, ?, CURRENT_TIMESTAMP)
    ");

    for ($i=0; $i<count($names); $i++) {

      if (($errs[$i] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) continue;
      if (($sizes[$i] ?? 0) <= 0 || (int)$sizes[$i] > $maxSize) continue;

      $ext = strtolower(pathinfo((string)$names[$i], PATHINFO_EXTENSION));
      if (!in_array($ext, $allowed, true)) continue;

      $safe = preg_replace('/[^a-zA-Z0-9_\-]/', '_', pathinfo((string)$names[$i], PATHINFO_FILENAME));
      $filename = $safe.'_'.date('Ymd_His').'_'.bin2hex(random_bytes(3)).'.'.$ext;

      if (move_uploaded_file((string)$tmp[$i], $baseAbs.$filename)) {
        $pos++;
        $rel = $baseRel.$filename;
        $sha = @sha1_file($baseAbs.$filename) ?: null;

        // sha1 column allows NULL (YES in your table screenshot)
        $ins->bind_param("iiss", $listingId, $pos, $rel, $sha);
        $ins->execute();
      }
    }
    $ins->close();

    // Mark PHOTO_PENDING (optional but helpful)
    $st2 = $conn->prepare("
      UPDATE ebay_listings
      SET status='PHOTO_PENDING', updated_at=CURRENT_TIMESTAMP
      WHERE id=?
    ");
    $st2->bind_param("i", $listingId);
    $st2->execute();
    $st2->close();

    header("Location: /inventario/views/ebay_photos.php?id=".$inventoryId);
    exit;
  }
}

/* =========================
   FETCH PHOTOS (GET)
========================= */
$ps = $conn->prepare("
  SELECT id, photo_url, position
  FROM ebay_listing_photos
  WHERE listing_id=?
  ORDER BY position ASC
");
$ps->bind_param("i", $listingId);
$ps->execute();
$photos = $ps->get_result()->fetch_all(MYSQLI_ASSOC);
$ps->close();

$count = count($photos);

// NOW we can output HTML safely
include '../includes/header.php';
?>

<div class="container mt-4">
  <h3><i class="bi bi-camera"></i> Photos (<?= (int)$count ?>/7)</h3>

  <?php if ($uploadError): ?>
    <div class="alert alert-danger"><?= h($uploadError) ?></div>
  <?php endif; ?>

  <form method="post" enctype="multipart/form-data" class="mb-3">
    <input class="form-control" type="file" name="photos[]" multiple required>
    <button class="btn btn-primary mt-2">Upload</button>
  </form>

  <div class="row g-3">
    <?php foreach ($photos as $p): ?>
      <div class="col-md-3">
        <div class="border p-2 rounded">
          <img src="<?= h($p['photo_url']) ?>" class="img-fluid mb-2" alt="photo">
          <form method="post" onsubmit="return confirm('Delete photo?')">
            <input type="hidden" name="delete_photo_id" value="<?= (int)$p['id'] ?>">
            <button class="btn btn-sm btn-danger w-100" type="submit">Delete</button>
          </form>
        </div>
      </div>
    <?php endforeach; ?>
  </div>

  <div class="mt-3">
    <a class="btn btn-secondary" href="/inventario/views/ebay_queue.php">Back to Queue</a>
  </div>
</div>

<?php include '../includes/footer.php'; ?>
