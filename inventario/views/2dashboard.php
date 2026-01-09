<?php
// views/dashboard.php
// CTI Professional Dashboard with filters, categories and charts

ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

require_once '../includes/auth.php';
require_once '../includes/db_connection.php';
include '../includes/header.php';

// =====================
// Date Filters
// =====================
$today = new DateTime();
$filter = $_GET['filter'] ?? 'today';

$startDate = null;
$endDate   = null;

switch ($filter) {
    case 'week':
        // Monday to Sunday of current week
        $start = clone $today;
        $start->modify('monday this week');
        $end = clone $start;
        $end->modify('sunday this week');
        $startDate = $start->format('Y-m-d');
        $endDate   = $end->format('Y-m-d');
        break;

    case 'month':
        $start = new DateTime($today->format('Y-m-01'));
        $end   = clone $start;
        $end->modify('last day of this month');
        $startDate = $start->format('Y-m-d');
        $endDate   = $end->format('Y-m-d');
        break;

    case 'custom':
        $startDate = $_GET['start_date'] ?? '';
        $endDate   = $_GET['end_date'] ?? '';
        if (!$startDate || !$endDate) {
            // fallback if invalid custom
            $startDate = $today->format('Y-m-d');
            $endDate   = $today->format('Y-m-d');
            $filter    = 'today';
        }
        break;

    case 'today':
    default:
        $startDate = $today->format('Y-m-d');
        $endDate   = $today->format('Y-m-d');
        $filter    = 'today';
        break;
}

// =====================
// Quick KPIs (Filtered by date for Work Orders)
// =====================

// Work orders by status in range
$woStatus = [];
$stmt = $conn->prepare("SELECT status, COUNT(*) AS total
                        FROM work_orders
                        WHERE DATE(created_at) BETWEEN ? AND ?
                        GROUP BY status");
$stmt->bind_param('ss', $startDate, $endDate);
$stmt->execute();
$res = $stmt->get_result();
while ($row = $res->fetch_assoc()) {
    $woStatus[$row['status'] ?: 'Unknown'] = (int)$row['total'];
}
$stmt->close();

// Total WOs in range
$stmt = $conn->prepare("SELECT COUNT(*) AS total
                        FROM work_orders
                        WHERE DATE(created_at) BETWEEN ? AND ?");
$stmt->bind_param('ss', $startDate, $endDate);
$stmt->execute();
$r = $stmt->get_result()->fetch_assoc();
$woInRange = (int)($r['total'] ?? 0);
$stmt->close();

// Open WOs (Pending + In Progress) in range
$stmt = $conn->prepare("SELECT COUNT(*) AS total
                        FROM work_orders
                        WHERE DATE(created_at) BETWEEN ? AND ?
                          AND status IN ('Pending','In Progress')");
$stmt->bind_param('ss', $startDate, $endDate);
$stmt->execute();
$r = $stmt->get_result()->fetch_assoc();
$woOpen = (int)($r['total'] ?? 0);
$stmt->close();

// Completed WOs in range
$stmt = $conn->prepare("SELECT COUNT(*) AS total
                        FROM work_orders
                        WHERE DATE(created_at) BETWEEN ? AND ?
                          AND status = 'Completed'");
$stmt->bind_param('ss', $startDate, $endDate);
$stmt->execute();
$r = $stmt->get_result()->fetch_assoc();
$woCompleted = (int)($r['total'] ?? 0);
$stmt->close();

// =====================
// Defectives - overall (not filtered by date for now)
// =====================
$defectivesStatus = [];
$res = $conn->query("SELECT status, COUNT(*) AS total
                     FROM defectives_inventory
                     GROUP BY status");
if ($res) {
    while ($row = $res->fetch_assoc()) {
        $defectivesStatus[$row['status'] ?: 'Unknown'] = (int)$row['total'];
    }
}

$defInStock  = $defectivesStatus['In Stock']  ?? 0;
$defInRepair = $defectivesStatus['In Repair'] ?? 0;
$defScrap    = ($defectivesStatus['Scrap'] ?? 0) + ($defectivesStatus['Scrapped'] ?? 0);

// =====================
// Defectives in Stock by Category
// (assuming column `category` in defectives_inventory: ipad, macbook, chromebook, surface, etc.)
// =====================
$categoryStock = [];
$res = $conn->query("SELECT category, COUNT(*) AS total
                     FROM defectives_inventory
                     WHERE status = 'In Stock'
                     GROUP BY category");
if ($res) {
    while ($row = $res->fetch_assoc()) {
        $cat = $row['category'] ?: 'Unknown';
        $categoryStock[$cat] = (int)$row['total'];
    }
}

// =====================
// Technician ranking by completed WOs (overall)
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
// WOs per day in range (for line chart)
// =====================
$woPerDay = [];
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
    $woPerDay[$row['day']] = (int)$row['total'];
}
$stmt->close();

// Prepare data for JS (Chart.js)
$woStatusLabels = array_keys($woStatus);
$woStatusData   = array_values($woStatus);

$catLabels = array_keys($categoryStock);
$catData   = array_values($categoryStock);

$woDayLabels = array_keys($woPerDay);
$woDayData   = array_values($woPerDay);

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
                       value="<?php echo htmlspecialchars($startDate); ?>">
            </div>

            <div class="col-md-3">
                <label class="form-label mb-1">End Date</label>
                <input type="date" name="end_date" id="end_date" class="form-control form-control-sm"
                       value="<?php echo htmlspecialchars($endDate); ?>">
            </div>

            <div class="col-md-3 d-flex align-items-end">
                <button type="submit" class="btn btn-primary btn-sm me-2">Apply</button>
                <a href="dashboard.php" class="btn btn-outline-secondary btn-sm">Reset</a>
            </div>
        </form>
        <div class="small text-muted mt-2">
            Showing work orders from <strong><?php echo htmlspecialchars($startDate); ?></strong>
            to <strong><?php echo htmlspecialchars($endDate); ?></strong>.
        </div>
    </div>
</div>

<div class="row g-3 mb-4">

    <!-- KPI: WOs in range -->
    <div class="col-md-3">
        <div class="card border-0 shadow-sm">
            <div class="card-body">
                <div class="d-flex justify-content-between align-items-center">
                    <div>
                        <div class="text-muted small">Work Orders (range)</div>
                        <div class="h3 mb-0"><?php echo $woInRange; ?></div>
                    </div>
                    <div class="text-info">
                        <i class="bi bi-clipboard-data" style="font-size: 2rem;"></i>
                    </div>
                </div>
                <div class="small text-muted mt-1">
                    Created in selected date range
                </div>
            </div>
        </div>
    </div>

    <!-- KPI: Open WOs -->
    <div class="col-md-3">
        <div class="card border-0 shadow-sm">
            <div class="card-body">
                <div class="d-flex justify-content-between align-items-center">
                    <div>
                        <div class="text-muted small">Open Work Orders</div>
                        <div class="h3 mb-0"><?php echo $woOpen; ?></div>
                    </div>
                    <div class="text-warning">
                        <i class="bi bi-hammer" style="font-size: 2rem;"></i>
                    </div>
                </div>
                <div class="small text-muted mt-1">
                    Pending + In Progress (range)
                </div>
            </div>
        </div>
    </div>

    <!-- KPI: Completed WOs -->
    <div class="col-md-3">
        <div class="card border-0 shadow-sm">
            <div class="card-body">
                <div class="d-flex justify-content-between align-items-center">
                    <div>
                        <div class="text-muted small">Completed WOs</div>
                        <div class="h3 mb-0"><?php echo $woCompleted; ?></div>
                    </div>
                    <div class="text-success">
                        <i class="bi bi-check-circle" style="font-size: 2rem;"></i>
                    </div>
                </div>
                <div class="small text-muted mt-1">
                    Completed in this date range
                </div>
            </div>
        </div>
    </div>

    <!-- KPI: Defectives In Stock -->
    <div class="col-md-3">
        <div class="card border-0 shadow-sm">
            <div class="card-body">
                <div class="d-flex justify-content-between align-items-center">
                    <div>
                        <div class="text-muted small">Defectives In Stock</div>
                        <div class="h3 mb-0"><?php echo $defInStock; ?></div>
                    </div>
                    <div class="text-primary">
                        <i class="bi bi-box-seam" style="font-size: 2rem;"></i>
                    </div>
                </div>
                <div class="small text-muted mt-1">
                    Overall, ready for processing
                </div>
            </div>
        </div>
    </div>

</div>

<!-- Charts row -->
<div class="row g-3 mb-4">

    <!-- Chart: WOs by Status (bar) -->
    <div class="col-md-6">
        <div class="card border-0 shadow-sm h-100">
            <div class="card-header bg-white">
                <strong>Work Orders by Status (range)</strong>
            </div>
            <div class="card-body">
                <canvas id="woStatusChart" height="150"></canvas>
            </div>
        </div>
    </div>

    <!-- Chart: Defectives In Stock by Category (doughnut) -->
    <div class="col-md-6">
        <div class="card border-0 shadow-sm h-100">
            <div class="card-header bg-white">
                <strong>Defectives In Stock by Category</strong>
            </div>
            <div class="card-body">
                <canvas id="categoryChart" height="150"></canvas>
            </div>
        </div>
    </div>

</div>

<!-- Row: WOs per day + Technician ranking -->
<div class="row g-3 mb-4">

    <!-- WOs per day (line chart) -->
    <div class="col-md-8">
        <div class="card border-0 shadow-sm h-100">
            <div class="card-header bg-white">
                <strong>Work Orders per Day (range)</strong>
            </div>
            <div class="card-body">
                <canvas id="woPerDayChart" height="120"></canvas>
            </div>
        </div>
    </div>

    <!-- Technician ranking -->
    <div class="col-md-4">
        <div class="card border-0 shadow-sm h-100">
            <div class="card-header bg-white">
                <strong>Technician Ranking (Completed WOs)</strong>
            </div>
            <div class="card-body">
                <ul class="list-group list-group-flush small">
                    <?php if (!empty($techRanking)): ?>
                        <?php foreach ($techRanking as $t): ?>
                            <li class="list-group-item d-flex justify-content-between align-items-center">
                                <span><?php echo htmlspecialchars($t['username']); ?></span>
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
                                        <td><?php echo htmlspecialchars($wo['wo_number']); ?></td>
                                        <td><?php echo htmlspecialchars($wo['title']); ?></td>
                                        <td><?php echo htmlspecialchars($wo['status']); ?></td>
                                        <td><?php echo htmlspecialchars($wo['priority']); ?></td>
                                        <td><?php echo htmlspecialchars($wo['tech_name'] ?? ''); ?></td>
                                        <td><?php echo htmlspecialchars($wo['created_at']); ?></td>
                                        <td>
                                            <a href="view_work_order.php?id=<?php echo (int)$wo['id']; ?>" class="btn btn-sm btn-outline-secondary">
                                                Open
                                            </a>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php else: ?>
                                <tr>
                                    <td colspan="7">No work orders available in this range.</td>
                                </tr>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>
</div>

</div> <!-- container from header -->

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.1/dist/chart.umd.min.js"></script>
<script>
// Enable/disable date inputs based on filter
function onFilterChange(value) {
    const isCustom = (value === 'custom');
    document.getElementById('start_date').readOnly = !isCustom;
    document.getElementById('end_date').readOnly   = !isCustom;
}

// Init state on load
onFilterChange('<?php echo $filter; ?>');

// Chart.js data from PHP
const woStatusLabels = <?php echo json_encode($woStatusLabels); ?>;
const woStatusData   = <?php echo json_encode($woStatusData); ?>;

const categoryLabels = <?php echo json_encode($catLabels); ?>;
const categoryData   = <?php echo json_encode($catData); ?>;

const woDayLabels = <?php echo json_encode($woDayLabels); ?>;
const woDayData   = <?php echo json_encode($woDayData); ?>;

// Chart: Work Orders by Status (Bar)
const ctxStatus = document.getElementById('woStatusChart');
if (ctxStatus && woStatusLabels.length > 0) {
    new Chart(ctxStatus, {
        type: 'bar',
        data: {
            labels: woStatusLabels,
            datasets: [{
                label: 'Work Orders',
                data: woStatusData
            }]
        },
        options: {
            responsive: true,
            plugins: {
                legend: { display: false }
            },
            scales: {
                y: { beginAtZero: true }
            }
        }
    });
}

// Chart: Defectives In Stock by Category (Doughnut)
const ctxCat = document.getElementById('categoryChart');
if (ctxCat && categoryLabels.length > 0) {
    new Chart(ctxCat, {
        type: 'doughnut',
        data: {
            labels: categoryLabels,
            datasets: [{
                label: 'In Stock',
                data: categoryData
            }]
        },
        options: {
            responsive: true,
            plugins: {
                legend: { position: 'bottom' }
            }
        }
    });
}

// Chart: Work Orders per Day (Line)
const ctxPerDay = document.getElementById('woPerDayChart');
if (ctxPerDay && woDayLabels.length > 0) {
    new Chart(ctxPerDay, {
        type: 'line',
        data: {
            labels: woDayLabels,
            datasets: [{
                label: 'Work Orders',
                data: woDayData,
                tension: 0.2
            }]
        },
        options: {
            responsive: true,
            plugins: {
                legend: { display: false }
            },
            scales: {
                y: { beginAtZero: true }
            }
        }
    });
}
</script>
</body>
</html>
