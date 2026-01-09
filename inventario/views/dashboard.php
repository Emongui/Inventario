<?php
// views/dashboard.php
// CTI Professional Dashboard (v3) - safer date handling + filled daily series + clean layout

ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);
mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

require_once '../includes/auth.php';
require_once '../includes/db_connection.php';
include '../includes/header.php';

function h($v): string {
    return htmlspecialchars((string)($v ?? ''), ENT_QUOTES, 'UTF-8');
}

/**
 * Build an array of YYYY-MM-DD => 0 for all days between start/end (inclusive)
 */
function buildDailySeries(string $startDate, string $endDate): array {
    $out = [];
    $start = DateTime::createFromFormat('Y-m-d', $startDate) ?: new DateTime();
    $end   = DateTime::createFromFormat('Y-m-d', $endDate)   ?: new DateTime();

    // Inclusive end
    $endInclusive = clone $end;
    $endInclusive->modify('+1 day');

    $period = new DatePeriod($start, new DateInterval('P1D'), $endInclusive);
    foreach ($period as $dt) {
        $key = $dt->format('Y-m-d');
        $out[$key] = 0;
    }
    return $out;
}

/**
 * Safe date read
 */
function readDateParam(string $key): ?string {
    $v = trim($_GET[$key] ?? '');
    if ($v === '') return null;
    $d = DateTime::createFromFormat('Y-m-d', $v);
    if (!$d) return null;
    return $d->format('Y-m-d');
}

// =====================
// Date Filters
// =====================
$today = new DateTime();
$filter = $_GET['filter'] ?? 'today';

$startDate = $today->format('Y-m-d');
$endDate   = $today->format('Y-m-d');

switch ($filter) {
    case 'week': {
        $start = clone $today;
        $start->modify('monday this week');
        $end = clone $start;
        $end->modify('sunday this week');
        $startDate = $start->format('Y-m-d');
        $endDate   = $end->format('Y-m-d');
        break;
    }

    case 'month': {
        $start = new DateTime($today->format('Y-m-01'));
        $end   = clone $start;
        $end->modify('last day of this month');
        $startDate = $start->format('Y-m-d');
        $endDate   = $end->format('Y-m-d');
        break;
    }

    case 'custom': {
        $s = readDateParam('start_date');
        $e = readDateParam('end_date');
        if ($s && $e) {
            // If inverted, swap
            if ($s > $e) {
                $tmp = $s; $s = $e; $e = $tmp;
            }
            $startDate = $s;
            $endDate   = $e;
        } else {
            // fallback
            $filter = 'today';
        }
        break;
    }

    case 'today':
    default:
        $filter = 'today';
        break;
}
// Buscador
$binModel = trim($_GET['bin_model'] ?? '');
$binResults = [];

if ($binModel !== '') {
    $like = '%' . $binModel . '%';

    $sqlBins = "
    SELECT
        l.id AS location_id,
        COALESCE(l.location_name, di.bin_location, 'NO_BIN') AS bin,
        COUNT(*) AS qty
    FROM defectives_inventory di
    LEFT JOIN product_ids p ON di.product_id = p.id
    LEFT JOIN locations l ON di.location_id = l.id
    WHERE (p.product_id LIKE ? OR p.description LIKE ?)
      AND di.removal_status = 'In Stock'
    GROUP BY l.id, bin
    ORDER BY qty DESC, bin ASC
";


    $stmt = $conn->prepare($sqlBins);
    $stmt->bind_param('ss', $like, $like);
    $stmt->execute();
    $res = $stmt->get_result();
    while ($row = $res->fetch_assoc()) {
        $binResults[] = $row;
    }
    $stmt->close();
}


// =====================
// Quick KPIs (Work Orders in range)
// =====================
$woStatus = [];
$stmt = $conn->prepare("
    SELECT COALESCE(status,'Unknown') AS status, COUNT(*) AS total
    FROM work_orders
    WHERE DATE(created_at) BETWEEN ? AND ?
    GROUP BY COALESCE(status,'Unknown')
");
$stmt->bind_param('ss', $startDate, $endDate);
$stmt->execute();
$res = $stmt->get_result();
while ($row = $res->fetch_assoc()) {
    $woStatus[$row['status']] = (int)$row['total'];
}
$stmt->close();

$stmt = $conn->prepare("SELECT COUNT(*) AS total
                        FROM work_orders
                        WHERE DATE(created_at) BETWEEN ? AND ?");
$stmt->bind_param('ss', $startDate, $endDate);
$stmt->execute();
$woInRange = (int)($stmt->get_result()->fetch_assoc()['total'] ?? 0);
$stmt->close();

$stmt = $conn->prepare("SELECT COUNT(*) AS total
                        FROM work_orders
                        WHERE DATE(created_at) BETWEEN ? AND ?
                          AND status IN ('Pending','In Progress')");
$stmt->bind_param('ss', $startDate, $endDate);
$stmt->execute();
$woOpen = (int)($stmt->get_result()->fetch_assoc()['total'] ?? 0);
$stmt->close();

$stmt = $conn->prepare("SELECT COUNT(*) AS total
                        FROM work_orders
                        WHERE DATE(created_at) BETWEEN ? AND ?
                          AND status = 'Completed'");
$stmt->bind_param('ss', $startDate, $endDate);
$stmt->execute();
$woCompleted = (int)($stmt->get_result()->fetch_assoc()['total'] ?? 0);
$stmt->close();

// =====================
// Defectives (overall status)
// =====================
$defectivesStatus = [];
$res = $conn->query("SELECT COALESCE(status,'Unknown') AS status, COUNT(*) AS total
                     FROM defectives_inventory
                     GROUP BY COALESCE(status,'Unknown')");
if ($res) {
    while ($row = $res->fetch_assoc()) {
        $defectivesStatus[$row['status']] = (int)$row['total'];
    }
}
$defInStock  = $defectivesStatus['In Stock']  ?? 0;
$defInRepair = $defectivesStatus['In Repair'] ?? 0;
$defScrap    = ($defectivesStatus['Scrap'] ?? 0) + ($defectivesStatus['Scrapped'] ?? 0);

// Defectives received in range (extra KPI)
$stmt = $conn->prepare("SELECT COUNT(*) AS total
                        FROM defectives_inventory
                        WHERE DATE(date_added) BETWEEN ? AND ?");
$stmt->bind_param('ss', $startDate, $endDate);
$stmt->execute();
$defReceivedRange = (int)($stmt->get_result()->fetch_assoc()['total'] ?? 0);
$stmt->close();

// Parts / eBay queue (overall in-system, not removed)
$stmt = $conn->prepare("SELECT COUNT(*) AS total
                        FROM defectives_inventory
                        WHERE process_area = 'PARTS'
                          AND removal_status = 'In Stock'");
$stmt->execute();
$defParts = (int)($stmt->get_result()->fetch_assoc()['total'] ?? 0);
$stmt->close();

$stmt = $conn->prepare("SELECT COUNT(*) AS total
                        FROM defectives_inventory
                        WHERE process_area = 'EBAY'
                          AND removal_status = 'In Stock'");
$stmt->execute();
$defEbay = (int)($stmt->get_result()->fetch_assoc()['total'] ?? 0);
$stmt->close();


// =====================
// Defectives In Stock by Category
// =====================
$categoryStock = [];
$res = $conn->query("SELECT COALESCE(category,'Unknown') AS category, COUNT(*) AS total
                     FROM defectives_inventory
                     WHERE status = 'In Stock'
                     GROUP BY COALESCE(category,'Unknown')
                     ORDER BY total DESC");
if ($res) {
    while ($row = $res->fetch_assoc()) {
        $categoryStock[$row['category']] = (int)$row['total'];
    }
}

// =====================
// Technician ranking (Completed WOs overall)
// =====================
$techRanking = [];
$sqlTech = "
    SELECT u.username,
           COUNT(wo.id) AS completed_wos
    FROM users u
    LEFT JOIN work_orders wo
        ON wo.assigned_to = u.id
       AND wo.status = 'Completed'
    WHERE u.role = 'technician'
    GROUP BY u.id, u.username
    ORDER BY completed_wos DESC, u.username ASC
";
$res = $conn->query($sqlTech);
if ($res) {
    while ($row = $res->fetch_assoc()) {
        $techRanking[] = $row;
    }
}

// =====================
// Last 5 WOs in range
// =====================
$lastWOs = [];
$sqlLast = "
    SELECT wo.id, wo.wo_number, wo.title, wo.status, wo.priority, wo.created_at,
           u.username AS tech_name
    FROM work_orders wo
    LEFT JOIN users u ON wo.assigned_to = u.id
    WHERE DATE(wo.created_at) BETWEEN ? AND ?
    ORDER BY wo.created_at DESC
    LIMIT 5
";
$stmt = $conn->prepare($sqlLast);
$stmt->bind_param('ss', $startDate, $endDate);
$stmt->execute();
$res = $stmt->get_result();
while ($row = $res->fetch_assoc()) {
    $lastWOs[] = $row;
}
$stmt->close();

// =====================
// WOs per day (filled series)
// =====================
$woPerDay = buildDailySeries($startDate, $endDate);
$sqlPerDay = "
    SELECT DATE(created_at) AS day, COUNT(*) AS total
    FROM work_orders
    WHERE DATE(created_at) BETWEEN ? AND ?
    GROUP BY DATE(created_at)
    ORDER BY DATE(created_at)
";
$stmt = $conn->prepare($sqlPerDay);
$stmt->bind_param('ss', $startDate, $endDate);
$stmt->execute();
$res = $stmt->get_result();
while ($row = $res->fetch_assoc()) {
    $day = $row['day'];
    if (isset($woPerDay[$day])) $woPerDay[$day] = (int)$row['total'];
}
$stmt->close();

// =====================
// Defectives received per day (filled series)
// =====================
$defPerDay = buildDailySeries($startDate, $endDate);
$sqlDefDay = "
    SELECT DATE(date_added) AS day, COUNT(*) AS total
    FROM defectives_inventory
    WHERE DATE(date_added) BETWEEN ? AND ?
    GROUP BY DATE(date_added)
    ORDER BY DATE(date_added)
";
$stmt = $conn->prepare($sqlDefDay);
$stmt->bind_param('ss', $startDate, $endDate);
$stmt->execute();
$res = $stmt->get_result();
while ($row = $res->fetch_assoc()) {
    $day = $row['day'];
    if (isset($defPerDay[$day])) $defPerDay[$day] = (int)$row['total'];
}
$stmt->close();

// Prepare data for JS
$woStatusLabels = array_keys($woStatus);
$woStatusData   = array_values($woStatus);

$catLabels = array_keys($categoryStock);
$catData   = array_values($categoryStock);

$woDayLabels = array_keys($woPerDay);
$woDayData   = array_values($woPerDay);

$defDayLabels = array_keys($defPerDay);
$defDayData   = array_values($defPerDay);
?>

<h2 class="mb-3">CTI Dashboard</h2>

<!-- Date Filter -->
<div class="card mb-3">
    <div class="card-body">
        <form class="row g-2" method="get" action="dashboard.php">
            <div class="col-md-3">
                <label class="form-label mb-1">Quick Filter</label>
                <select name="filter" class="form-select form-select-sm" onchange="onFilterChange(this.value)">
                    <option value="today"  <?php if ($filter === 'today') echo 'selected'; ?>>Today</option>
                    <option value="week"   <?php if ($filter === 'week') echo 'selected'; ?>>This Week</option>
                    <option value="month"  <?php if ($filter === 'month') echo 'selected'; ?>>This Month</option>
                    <option value="custom" <?php if ($filter === 'custom') echo 'selected'; ?>>Custom Range</option>
                </select>
            </div>

            <div class="col-md-3">
                <label class="form-label mb-1">Start Date</label>
                <input type="date" name="start_date" id="start_date" class="form-control form-control-sm"
                       value="<?php echo h($startDate); ?>">
            </div>

            <div class="col-md-3">
                <label class="form-label mb-1">End Date</label>
                <input type="date" name="end_date" id="end_date" class="form-control form-control-sm"
                       value="<?php echo h($endDate); ?>">
            </div>

            <div class="col-md-3 d-flex align-items-end">
                <button type="submit" class="btn btn-primary btn-sm me-2">Apply</button>
                <a href="dashboard.php" class="btn btn-outline-secondary btn-sm">Reset</a>
            </div>
        </form>

        <div class="small text-muted mt-2">
            Range: <strong><?php echo h($startDate); ?></strong> → <strong><?php echo h($endDate); ?></strong>
        </div>
    </div>
</div>
<!-- IU BUSCADOR -->
 <div class="card mb-4">
  <div class="card-header bg-white">
    <strong>BIN Finder (by Model / Product ID)</strong>
  </div>
  <div class="card-body">
    <form class="row g-2" method="get" action="dashboard.php">
      <!-- keep your current date filter params -->
      <input type="hidden" name="filter" value="<?php echo h($filter); ?>">
      <input type="hidden" name="start_date" value="<?php echo h($startDate); ?>">
      <input type="hidden" name="end_date" value="<?php echo h($endDate); ?>">

      <div class="col-md-8">
        <input type="text" name="bin_model" class="form-control form-control-sm"
               placeholder="Example: IPAD10-64W-SLV or MACBOOK..." value="<?php echo h($binModel); ?>">
      </div>
      <div class="col-md-4">
        <button class="btn btn-primary btn-sm">Search BINs</button>
        <a class="btn btn-outline-secondary btn-sm" href="dashboard.php?filter=<?php echo h($filter); ?>&start_date=<?php echo h($startDate); ?>&end_date=<?php echo h($endDate); ?>">Clear</a>
      </div>
    </form>

    <?php if ($binModel !== ''): ?>
      <hr>
      <div class="small text-muted mb-2">Results for: <strong><?php echo h($binModel); ?></strong></div>

      <?php if (!empty($binResults)): ?>
        <div class="table-responsive">
          <table class="table table-sm table-striped align-middle">
            <thead>
              <tr>
                <th>BIN</th>
                <th class="text-end">Qty</th>
              </tr>
            <thead>
                <tr>
                <th>BIN</th>
                <th class="text-end">Qty</th>
                <th class="text-end">Action</th>
                </tr>
                </thead>
                <tbody>
                <?php foreach ($binResults as $r): ?>
                <tr>
                <td><?php echo h($r['bin']); ?></td>
                <td class="text-end"><?php echo (int)$r['qty']; ?></td>
                <td class="text-end">
                    <?php if (!empty($r['location_id'])): ?>
                    <a href="manage_bins.php?location_id=<?php echo (int)$r['location_id']; ?>&product_q=<?php echo urlencode($binModel); ?>"
                    class="btn btn-sm btn-outline-primary">
                    Manage BIN
                    </a>
                    <?php else: ?>
                    <span class="text-muted small">No BIN</span>
                    <?php endif; ?>
                </td>
                </tr>
                <?php endforeach; ?>
                </tbody>
          </table>
        </div>
      <?php else: ?>
        <div class="alert alert-warning mb-0">No bins found for that model.</div>
      <?php endif; ?>
    <?php endif; ?>
  </div>
</div>



<!-- KPI row -->
<div class="row g-3 mb-4">

    <div class="col-md-3">
        <div class="card border-0 shadow-sm">
            <div class="card-body">
                <div class="d-flex justify-content-between align-items-center">
                    <div>
                        <div class="text-muted small">Work Orders (range)</div>
                        <div class="h3 mb-0"><?php echo (int)$woInRange; ?></div>
                    </div>
                    <div class="text-info"><i class="bi bi-clipboard-data" style="font-size: 2rem;"></i></div>
                </div>
                <div class="small text-muted mt-1">Created in selected range</div>
            </div>
        </div>
    </div>

    <div class="col-md-3">
        <div class="card border-0 shadow-sm">
            <div class="card-body">
                <div class="d-flex justify-content-between align-items-center">
                    <div>
                        <div class="text-muted small">Open Work Orders</div>
                        <div class="h3 mb-0"><?php echo (int)$woOpen; ?></div>
                    </div>
                    <div class="text-warning"><i class="bi bi-hammer" style="font-size: 2rem;"></i></div>
                </div>
                <div class="small text-muted mt-1">Pending + In Progress (range)</div>
            </div>
        </div>
    </div>

    <div class="col-md-3">
        <div class="card border-0 shadow-sm">
            <div class="card-body">
                <div class="d-flex justify-content-between align-items-center">
                    <div>
                        <div class="text-muted small">Completed WOs</div>
                        <div class="h3 mb-0"><?php echo (int)$woCompleted; ?></div>
                    </div>
                    <div class="text-success"><i class="bi bi-check-circle" style="font-size: 2rem;"></i></div>
                </div>
                <div class="small text-muted mt-1">Completed in range</div>
            </div>
        </div>
    </div>

    <div class="col-md-3">
        <div class="card border-0 shadow-sm">
            <div class="card-body">
                <div class="d-flex justify-content-between align-items-center">
                    <div>
                        <div class="text-muted small">Defectives Received</div>
                        <div class="h3 mb-0"><?php echo (int)$defReceivedRange; ?></div>
                    </div>
                    <div class="text-primary"><i class="bi bi-inbox" style="font-size: 2rem;"></i></div>
                </div>
                <div class="small text-muted mt-1">Received in selected range</div>
            </div>
        </div>
    </div>

</div>

<!-- Secondary KPI row -->
<div class="row g-3 mb-4">
  <div class="col-md-3">
    <div class="card border-0 shadow-sm">
      <div class="card-body d-flex justify-content-between align-items-center">
        <div>
          <div class="text-muted small">Defectives In Stock (overall)</div>
          <div class="h4 mb-0"><?php echo (int)$defInStock; ?></div>
        </div>
        <div class="text-primary"><i class="bi bi-box-seam" style="font-size: 1.8rem;"></i></div>
      </div>
    </div>
  </div>

  <div class="col-md-3">
    <div class="card border-0 shadow-sm">
      <div class="card-body d-flex justify-content-between align-items-center">
        <div>
          <div class="text-muted small">Defectives In Repair (overall)</div>
          <div class="h4 mb-0"><?php echo (int)$defInRepair; ?></div>
        </div>
        <div class="text-warning"><i class="bi bi-wrench-adjustable" style="font-size: 1.8rem;"></i></div>
      </div>
    </div>
  </div>

  <div class="col-md-2">
    <div class="card border-0 shadow-sm">
      <div class="card-body d-flex justify-content-between align-items-center">
        <div>
          <div class="text-muted small">Parts (queue)</div>
          <div class="h4 mb-0"><?php echo (int)$defParts; ?></div>
        </div>
        <div class="text-info"><i class="bi bi-tools" style="font-size: 1.8rem;"></i></div>
      </div>
    </div>
  </div>

  <div class="col-md-2">
    <div class="card border-0 shadow-sm">
      <div class="card-body d-flex justify-content-between align-items-center">
        <div>
          <div class="text-muted small">eBay (queue)</div>
          <div class="h4 mb-0"><?php echo (int)$defEbay; ?></div>
        </div>
        <div class="text-dark"><i class="bi bi-bag-check" style="font-size: 1.8rem;"></i></div>
      </div>
    </div>
  </div>

  <div class="col-md-2">
    <div class="card border-0 shadow-sm">
      <div class="card-body d-flex justify-content-between align-items-center">
        <div>
          <div class="text-muted small">Defectives Scrap (overall)</div>
          <div class="h4 mb-0"><?php echo (int)$defScrap; ?></div>
        </div>
        <div class="text-danger"><i class="bi bi-trash3" style="font-size: 1.8rem;"></i></div>
      </div>
    </div>
  </div>
</div>


<!-- Charts row -->
<div class="row g-3 mb-4">
    <div class="col-md-6">
        <div class="card border-0 shadow-sm h-100">
            <div class="card-header bg-white"><strong>Work Orders by Status (range)</strong></div>
            <div class="card-body"><canvas id="woStatusChart" height="150"></canvas></div>
        </div>
    </div>

    <div class="col-md-6">
        <div class="card border-0 shadow-sm h-100">
            <div class="card-header bg-white"><strong>Defectives In Stock by Category</strong></div>
            <div class="card-body"><canvas id="categoryChart" height="150"></canvas></div>
        </div>
    </div>
</div>

<!-- Row: WOs per day + Technician ranking -->
<div class="row g-3 mb-4">
    <div class="col-md-8">
        <div class="card border-0 shadow-sm h-100">
            <div class="card-header bg-white"><strong>Work Orders per Day (range)</strong></div>
            <div class="card-body"><canvas id="woPerDayChart" height="120"></canvas></div>
        </div>
    </div>

    <div class="col-md-4">
        <div class="card border-0 shadow-sm h-100">
            <div class="card-header bg-white"><strong>Technician Ranking (Completed WOs)</strong></div>
            <div class="card-body">
                <ul class="list-group list-group-flush small">
                    <?php if (!empty($techRanking)): ?>
                        <?php foreach ($techRanking as $t): ?>
                            <li class="list-group-item d-flex justify-content-between align-items-center">
                                <span><?php echo h($t['username']); ?></span>
                                <span class="badge bg-success rounded-pill"><?php echo (int)$t['completed_wos']; ?></span>
                            </li>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <li class="list-group-item">No technicians or completed work orders yet.</li>
                    <?php endif; ?>
                </ul>
            </div>
        </div>
    </div>
</div>

<!-- Row: Defectives received per day -->
<div class="row g-3 mb-4">
    <div class="col-md-12">
        <div class="card border-0 shadow-sm h-100">
            <div class="card-header bg-white"><strong>Defectives Received per Day (date_added)</strong></div>
            <div class="card-body"><canvas id="defPerDayChart" height="120"></canvas></div>
        </div>
    </div>
</div>

<!-- Last Work Orders -->
<div class="row g-3 mb-4">
    <div class="col-md-12">
        <div class="card border-0 shadow-sm">
            <div class="card-header bg-white d-flex justify-content-between align-items-center">
                <strong>Last 5 Work Orders (in range)</strong>
                <a href="work_orders_list.php" class="btn btn-sm btn-outline-primary">View all</a>
            </div>
            <div class="card-body">
                <div class="table-responsive">
                    <table class="table table-sm table-striped align-middle">
                        <thead>
                            <tr>
                                <th>WO #</th>
                                <th>Title</th>
                                <th>Status</th>
                                <th>Priority</th>
                                <th>Assigned To</th>
                                <th>Created</th>
                                <th>Open</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (!empty($lastWOs)): ?>
                                <?php foreach ($lastWOs as $wo): ?>
                                    <tr>
                                        <td><?php echo h($wo['wo_number']); ?></td>
                                        <td><?php echo h($wo['title']); ?></td>
                                        <td><?php echo h($wo['status']); ?></td>
                                        <td><?php echo h($wo['priority']); ?></td>
                                        <td><?php echo h($wo['tech_name'] ?? ''); ?></td>
                                        <td><?php echo h($wo['created_at']); ?></td>
                                        <td>
                                            <a href="work_order_view.php?id=<?php echo (int)$wo['id']; ?>" class="btn btn-sm btn-outline-secondary">
                                                Open
                                            </a>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php else: ?>
                                <tr><td colspan="7">No work orders available in this range.</td></tr>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.1/dist/chart.umd.min.js"></script>
<script>
// Enable/disable date inputs based on filter
function onFilterChange(value) {
    const isCustom = (value === 'custom');
    const s = document.getElementById('start_date');
    const e = document.getElementById('end_date');
    if (!s || !e) return;

    s.disabled = !isCustom;
    e.disabled = !isCustom;

    // UX: if not custom, keep values but prevent edits
    s.classList.toggle('bg-light', !isCustom);
    e.classList.toggle('bg-light', !isCustom);
}
onFilterChange('<?php echo h($filter); ?>');

// Chart.js data from PHP
const woStatusLabels = <?php echo json_encode($woStatusLabels); ?>;
const woStatusData   = <?php echo json_encode($woStatusData); ?>;

const categoryLabels = <?php echo json_encode($catLabels); ?>;
const categoryData   = <?php echo json_encode($catData); ?>;

const woDayLabels = <?php echo json_encode($woDayLabels); ?>;
const woDayData   = <?php echo json_encode($woDayData); ?>;

const defDayLabels = <?php echo json_encode($defDayLabels); ?>;
const defDayData   = <?php echo json_encode($defDayData); ?>;

// Work Orders by Status (Bar)
const ctxStatus = document.getElementById('woStatusChart');
if (ctxStatus && woStatusLabels.length) {
    new Chart(ctxStatus, {
        type: 'bar',
        data: { labels: woStatusLabels, datasets: [{ label: 'Work Orders', data: woStatusData }] },
        options: { responsive: true, plugins: { legend: { display: false } }, scales: { y: { beginAtZero: true } } }
    });
}

// Defectives In Stock by Category (Doughnut)
const ctxCat = document.getElementById('categoryChart');
if (ctxCat && categoryLabels.length) {
    new Chart(ctxCat, {
        type: 'doughnut',
        data: { labels: categoryLabels, datasets: [{ label: 'In Stock', data: categoryData }] },
        options: { responsive: true, plugins: { legend: { position: 'bottom' } } }
    });
}

// Work Orders per Day (Line)
const ctxPerDay = document.getElementById('woPerDayChart');
if (ctxPerDay && woDayLabels.length) {
    new Chart(ctxPerDay, {
        type: 'line',
        data: { labels: woDayLabels, datasets: [{ label: 'Work Orders', data: woDayData, tension: 0.2 }] },
        options: { responsive: true, plugins: { legend: { display: false } }, scales: { y: { beginAtZero: true } } }
    });
}

// Defectives received per day (Bar)
const ctxDefPerDay = document.getElementById('defPerDayChart');
if (ctxDefPerDay && defDayLabels.length) {
    new Chart(ctxDefPerDay, {
        type: 'bar',
        data: { labels: defDayLabels, datasets: [{ label: 'Defectives received', data: defDayData }] },
        options: { responsive: true, plugins: { legend: { display: false } }, scales: { y: { beginAtZero: true } } }
    });
}
</script>

<?php include '../includes/footer.php'; ?>
