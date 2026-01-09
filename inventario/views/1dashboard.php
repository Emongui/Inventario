<?php
// views/dashboard.php
// CTI Professional Dashboard

ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

require_once '../includes/auth.php';
require_once '../includes/db_connection.php';
include '../includes/header.php';

// =====================
// Quick KPIs
// =====================

// Defectives by status
$defectivesStatus = [];
$res = $conn->query("SELECT status, COUNT(*) AS total FROM defectives_inventory GROUP BY status");
if ($res) {
    while ($row = $res->fetch_assoc()) {
        $defectivesStatus[$row['status'] ?: 'Unknown'] = (int)$row['total'];
    }
}

// Work orders by status
$woStatus = [];
$res = $conn->query("SELECT status, COUNT(*) AS total FROM work_orders GROUP BY status");
if ($res) {
    while ($row = $res->fetch_assoc()) {
        $woStatus[$row['status'] ?: 'Unknown'] = (int)$row['total'];
    }
}

// Total WOs today
$todayDate = date('Y-m-d');
$woToday = 0;
$stmt = $conn->prepare("SELECT COUNT(*) AS total FROM work_orders WHERE DATE(created_at) = ?");
$stmt->bind_param('s', $todayDate);
$stmt->execute();
$r = $stmt->get_result()->fetch_assoc();
$woToday = (int)($r['total'] ?? 0);
$stmt->close();

// Open WOs (Pending + In Progress)
$woOpen = 0;
$res = $conn->query("SELECT COUNT(*) AS total FROM work_orders WHERE status IN ('Pending','In Progress')");
if ($res) {
    $row = $res->fetch_assoc();
    $woOpen = (int)($row['total'] ?? 0);
}

// Completed WOs
$woCompleted = 0;
$res = $conn->query("SELECT COUNT(*) AS total FROM work_orders WHERE status = 'Completed'");
if ($res) {
    $row = $res->fetch_assoc();
    $woCompleted = (int)($row['total'] ?? 0);
}

// Total defectives in stock / in repair / scrap
$defInStock = $defectivesStatus['In Stock'] ?? 0;
$defInRepair = $defectivesStatus['In Repair'] ?? 0;
$defScrap = ($defectivesStatus['Scrap'] ?? 0) + ($defectivesStatus['Scrapped'] ?? 0);

// Technician ranking by completed WOs
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

// Last 5 work orders
$lastWOs = [];
$sqlLast = "
    SELECT wo.id, wo.wo_number, wo.title, wo.status, wo.priority, wo.created_at,
           u.username AS tech_name
    FROM work_orders wo
    LEFT JOIN users u ON wo.assigned_to = u.id
    ORDER BY wo.created_at DESC
    LIMIT 5
";
$res = $conn->query($sqlLast);
if ($res) {
    while ($row = $res->fetch_assoc()) {
        $lastWOs[] = $row;
    }
}
?>
<h2 class="mb-4">CTI Dashboard</h2>

<div class="row g-3 mb-4">

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
                    Pending + In Progress
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
                        <div class="text-muted small">Completed Work Orders</div>
                        <div class="h3 mb-0"><?php echo $woCompleted; ?></div>
                    </div>
                    <div class="text-success">
                        <i class="bi bi-check-circle" style="font-size: 2rem;"></i>
                    </div>
                </div>
                <div class="small text-muted mt-1">
                    All time completed
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
                    Ready for repair / processing
                </div>
            </div>
        </div>
    </div>

    <!-- KPI: Defectives In Repair / Scrap -->
    <div class="col-md-3">
        <div class="card border-0 shadow-sm">
            <div class="card-body">
                <div class="d-flex justify-content-between align-items-center">
                    <div>
                        <div class="text-muted small">In Repair / Scrap</div>
                        <div class="h5 mb-0"><?php echo $defInRepair; ?> in repair</div>
                        <div class="small text-danger"><?php echo $defScrap; ?> scrap</div>
                    </div>
                    <div class="text-danger">
                        <i class="bi bi-exclamation-triangle" style="font-size: 2rem;"></i>
                    </div>
                </div>
                <div class="small text-muted mt-1">
                    Current repair load & losses
                </div>
            </div>
        </div>
    </div>

</div>

<!-- Row: Today + WO Status Distribution -->
<div class="row g-3 mb-4">

    <!-- WOs today -->
    <div class="col-md-4">
        <div class="card border-0 shadow-sm h-100">
            <div class="card-header bg-white">
                <strong>Today</strong>
            </div>
            <div class="card-body">
                <p class="mb-1"><strong>Date:</strong> <?php echo date('Y-m-d'); ?></p>
                <p class="mb-2"><strong>Work Orders created today:</strong> <?php echo $woToday; ?></p>

                <ul class="list-group list-group-flush small">
                    <?php foreach ($woStatus as $st => $count): ?>
                        <li class="list-group-item d-flex justify-content-between align-items-center">
                            <span><?php echo htmlspecialchars($st); ?></span>
                            <span class="badge bg-secondary rounded-pill"><?php echo $count; ?></span>
                        </li>
                    <?php endforeach; ?>
                    <?php if (empty($woStatus)): ?>
                        <li class="list-group-item">No work orders yet.</li>
                    <?php endif; ?>
                </ul>
            </div>
        </div>
    </div>

    <!-- Defectives distribution -->
    <div class="col-md-4">
        <div class="card border-0 shadow-sm h-100">
            <div class="card-header bg-white">
                <strong>Defectives by Status</strong>
            </div>
            <div class="card-body">
                <ul class="list-group list-group-flush small">
                    <?php foreach ($defectivesStatus as $st => $count): ?>
                        <li class="list-group-item d-flex justify-content-between align-items-center">
                            <span><?php echo htmlspecialchars($st); ?></span>
                            <span class="badge bg-primary rounded-pill"><?php echo $count; ?></span>
                        </li>
                    <?php endforeach; ?>
                    <?php if (empty($defectivesStatus)): ?>
                        <li class="list-group-item">No defectives registered yet.</li>
                    <?php endif; ?>
                </ul>
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
                <strong>Last 5 Work Orders</strong>
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
                                    <td colspan="7">No work orders available.</td>
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
</body>
</html>
