<?php
// views/inventory_logs.php
// Inventory movements log viewer.

ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

require_once '../includes/auth.php';
require_once '../includes/db_connection.php';
require_once '../includes/inventory_log.php';
include '../includes/header.php';

// Only admin, supervisor and inventory can see logs
if (!in_array($logged_role, ['admin', 'supervisor', 'inventory'])) {
    echo "<p>You do not have permission to access this page.</p>";
    exit;
}

// Filters
$movement_type = $_GET['movement_type'] ?? '';
$serial        = trim($_GET['serial'] ?? '');
$user_id       = (int)($_GET['user_id'] ?? 0);
$start_date    = $_GET['start_date'] ?? '';
$end_date      = $_GET['end_date'] ?? '';

if ($start_date === '' || $end_date === '') {
    // Default: last 7 days
    $end_date = date('Y-m-d');
    $start_date = date('Y-m-d', strtotime('-7 days'));
}

// Fetch users for filter
$userOptions = [];
$res = $conn->query("SELECT id, username FROM users ORDER BY username ASC");
if ($res) {
    while ($row = $res->fetch_assoc()) {
        $userOptions[] = $row;
    }
}

// Build query
$sql = "
    SELECT m.*,
           u.username,
           lf.location_name AS from_location,
           lt.location_name AS to_location
    FROM inventory_movements m
    LEFT JOIN users u ON m.user_id = u.id
    LEFT JOIN locations lf ON m.from_location_id = lf.id
    LEFT JOIN locations lt ON m.to_location_id = lt.id
    WHERE DATE(m.created_at) BETWEEN ? AND ?
";

$params = [$start_date, $end_date];
$types  = 'ss';

if ($movement_type !== '') {
    $sql   .= " AND m.movement_type = ? ";
    $params[] = $movement_type;
    $types   .= 's';
}
if ($serial !== '') {
    $sql   .= " AND m.serial_number LIKE ? ";
    $params[] = '%' . $serial . '%';
    $types   .= 's';
}
if ($user_id > 0) {
    $sql   .= " AND m.user_id = ? ";
    $params[] = $user_id;
    $types   .= 'i';
}

$sql .= " ORDER BY m.created_at DESC, m.id DESC LIMIT 500";

$stmt = $conn->prepare($sql);
$stmt->bind_param($types, ...$params);
$stmt->execute();
$result = $stmt->get_result();

?>
<h2 class="mb-3">Inventory Movements Log</h2>

<div class="card mb-3">
    <div class="card-body">
        <form class="row g-2" method="get" action="inventory_logs.php">
            <div class="col-md-2">
                <label class="form-label mb-1">Movement Type</label>
                <select name="movement_type" class="form-select form-select-sm">
                    <option value="">All</option>
                    <option value="IN" <?php if ($movement_type === 'IN') echo 'selected'; ?>>IN (Received)</option>
                    <option value="OUT" <?php if ($movement_type === 'OUT') echo 'selected'; ?>>OUT (Removed)</option>
                    <option value="MOVE" <?php if ($movement_type === 'MOVE') echo 'selected'; ?>>MOVE (Bin change)</option>
                    <option value="STATUS_CHANGE" <?php if ($movement_type === 'STATUS_CHANGE') echo 'selected'; ?>>Status change</option>
                    <option value="ADJUST" <?php if ($movement_type === 'ADJUST') echo 'selected'; ?>>Adjustment</option>
                </select>
            </div>

            <div class="col-md-2">
                <label class="form-label mb-1">Serial</label>
                <input type="text" name="serial" class="form-control form-control-sm"
                       value="<?php echo htmlspecialchars($serial); ?>">
            </div>

            <div class="col-md-2">
                <label class="form-label mb-1">User</label>
                <select name="user_id" class="form-select form-select-sm">
                    <option value="0">All</option>
                    <?php foreach ($userOptions as $u): ?>
                        <option value="<?php echo (int)$u['id']; ?>"
                            <?php if ($user_id === (int)$u['id']) echo 'selected'; ?>>
                            <?php echo htmlspecialchars($u['username']); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div class="col-md-2">
                <label class="form-label mb-1">Start Date</label>
                <input type="date" name="start_date" class="form-control form-control-sm"
                       value="<?php echo htmlspecialchars($start_date); ?>">
            </div>

            <div class="col-md-2">
                <label class="form-label mb-1">End Date</label>
                <input type="date" name="end_date" class="form-control form-control-sm"
                       value="<?php echo htmlspecialchars($end_date); ?>">
            </div>

            <div class="col-md-2 d-flex align-items-end">
                <button type="submit" class="btn btn-primary btn-sm me-2">Filter</button>
                <a href="inventory_logs.php" class="btn btn-outline-secondary btn-sm">Reset</a>
            </div>
        </form>
        <div class="small text-muted mt-2">
            Showing movements from <strong><?php echo htmlspecialchars($start_date); ?></strong>
            to <strong><?php echo htmlspecialchars($end_date); ?></strong>.
        </div>
    </div>
</div>

<div class="card">
    <div class="card-header bg-white d-flex justify-content-between align-items-center">
        <strong>Last movements (max 500)</strong>
    </div>
    <div class="card-body">
        <div class="table-responsive">
            <table class="table table-sm table-striped align-middle">
                <thead>
                    <tr>
                        <th>Date / Time</th>
                        <th>Type</th>
                        <th>Serial</th>
                        <th>Defective ID</th>
                        <th>From Bin</th>
                        <th>To Bin</th>
                        <th>From Status</th>
                        <th>To Status</th>
                        <th>Reason</th>
                        <th>User</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if ($result && $result->num_rows > 0): ?>
                        <?php while ($m = $result->fetch_assoc()): ?>
                            <tr>
                                <td><?php echo htmlspecialchars($m['created_at']); ?></td>
                                <td><?php echo htmlspecialchars($m['movement_type']); ?></td>
                                <td><?php echo htmlspecialchars($m['serial_number']); ?></td>
                                <td><?php echo (int)$m['defectives_id']; ?></td>
                                <td><?php echo htmlspecialchars($m['from_location'] ?? ''); ?></td>
                                <td><?php echo htmlspecialchars($m['to_location'] ?? ''); ?></td>
                                <td><?php echo htmlspecialchars($m['from_status']); ?></td>
                                <td><?php echo htmlspecialchars($m['to_status']); ?></td>
                                <td><?php echo htmlspecialchars($m['reason']); ?></td>
                                <td><?php echo htmlspecialchars($m['username'] ?? ''); ?></td>
                            </tr>
                        <?php endwhile; ?>
                    <?php else: ?>
                        <tr>
                            <td colspan="10">No movements found for this filter.</td>
                        </tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

</div> <!-- container from header -->

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
