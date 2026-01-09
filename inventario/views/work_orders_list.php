<?php
// work_orders_list.php (role-based: Admin full / Technician limited) - Option A routes + robust view link

ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);
mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

require_once '../includes/auth.php';
require_once '../includes/db_connection.php';
include '../includes/header.php';

/**
 * Safe escape helper
 */
function h($value): string {
    if ($value === null) return '';
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}

// ---------------------------------------------------------
// Pick the correct WO view file (prevents "Actions not working")
// Prefer view_work_order.php (your dashboard uses it). Fallback to work_order_view.php
// ---------------------------------------------------------
$woViewFile = 'work_order_view.php';
if (file_exists(__DIR__ . '/view_work_order.php')) {
    $woViewFile = 'view_work_order.php';
}

// ---------------------------------------------------------
// Role / User
// ---------------------------------------------------------
$currentUserId   = isset($_SESSION['user_id']) ? (int)$_SESSION['user_id'] : 0;
$currentUserRole = $_SESSION['role'] ?? 'viewer';

$isTechnician = ($currentUserRole === 'technician');
$isAdminLike  = in_array($currentUserRole, ['admin', 'inventory_manager'], true);

// ---------------------------------------------------------
// Status / Priority mapping (Option A: status includes routes)
// ---------------------------------------------------------
$STATUS_LABELS = [
    'Pending'              => 'Pending',
    'In Progress'          => 'In Progress',
    'Completed'            => 'Completed',
    'Closed'               => 'Closed',
    'Canceled'             => 'Canceled',
    'Routed to Production' => 'Routed to Production',
    'Routed to eBay'       => 'Routed to eBay',
    'Routed to Parts'      => 'Routed to Parts',
    'Routed to Scrap'      => 'Routed to Scrap',
];

$STATUS_BADGE_CLASS = [
    'Pending'              => 'bg-warning text-dark',
    'In Progress'          => 'bg-info text-dark',
    'Completed'            => 'bg-success',
    'Closed'               => 'bg-success',
    'Canceled'             => 'bg-secondary',
    'Routed to Production' => 'bg-primary',
    'Routed to eBay'       => 'bg-dark',
    'Routed to Parts'      => 'bg-info text-dark',
    'Routed to Scrap'      => 'bg-danger',
];

// Closed statuses for "Only open"
$CLOSED_STATUSES = [
    'Completed',
    'Closed',
    'Canceled',

];

$PRIORITY_LABELS = [
    0 => 'Normal',
    1 => 'High',
    2 => 'Urgent',
];

$PRIORITY_BADGE_CLASS = [
    0 => 'bg-secondary',
    1 => 'bg-warning text-dark',
    2 => 'bg-danger',
];

// ---------------------------------------------------------
// Filters (GET)
// ---------------------------------------------------------
$filterStatus = isset($_GET['status']) ? trim($_GET['status']) : '';
$filterTech   = isset($_GET['assigned_to']) ? trim($_GET['assigned_to']) : '';
$filterSearch = isset($_GET['q']) ? trim($_GET['q']) : '';

// UX toggles
$onlyOpen      = isset($_GET['only_open']) ? ((int)$_GET['only_open'] === 1) : false;
$showCompleted = isset($_GET['show_completed']) ? ((int)$_GET['show_completed'] === 1) : false;

// Technician rules (enforced)
if ($isTechnician) {
    $filterTech = (string)$currentUserId;

    // Default: hide closed unless explicitly requested
    if (!$showCompleted) {
        $onlyOpen = true;
    }

    // Technician ignores manual status filter
    $filterStatus = '';
}

// ---------------------------------------------------------
// Technician list (Admin-like only)
// ---------------------------------------------------------
$techList = [];
$rows = [];
$errorMsg = '';

if ($isAdminLike) {
    try {
        $sqlTech = "
            SELECT DISTINCT
                w.assigned_to,
                u.username
            FROM work_orders w
            LEFT JOIN users u ON w.assigned_to = u.id
            WHERE w.assigned_to IS NOT NULL
              AND w.assigned_to <> ''
            ORDER BY u.username ASC
        ";
        $resultTech = $conn->query($sqlTech);
        while ($t = $resultTech->fetch_assoc()) {
            $techList[] = [
                'id'   => $t['assigned_to'],
                'name' => $t['username'] ?? $t['assigned_to']
            ];
        }
        $resultTech->close();
    } catch (Exception $e) {
        $errorMsg = "Error loading technicians: " . $e->getMessage();
    }
}

// ---------------------------------------------------------
// Work Orders query (role-aware)
// ---------------------------------------------------------
try {
    $sql = "
        SELECT
            w.id,
            w.wo_number,
            w.title,
            w.status,
            w.priority,
            w.assigned_to,
            w.created_at,
            w.updated_at,
            u.username AS technician_name
        FROM work_orders w
        LEFT JOIN users u ON w.assigned_to = u.id
        WHERE 1 = 1
    ";

    $params = [];
    $types  = '';

    // Status exact filter (admin-like only)
    if ($isAdminLike && $filterStatus !== '') {
        $sql .= " AND w.status = ?";
        $params[] = $filterStatus;
        $types    .= 's';
    }

    // assigned_to filter (tech forced to self)
    if ($filterTech !== '') {
        $sql .= " AND w.assigned_to = ?";
        $params[] = $filterTech;
        $types    .= 's';
    }

    // Only open toggle (NOT IN closed statuses)
    if ($onlyOpen) {
        if (!empty($CLOSED_STATUSES)) {
            $placeholders = implode(',', array_fill(0, count($CLOSED_STATUSES), '?'));
            $sql .= " AND (w.status IS NULL OR w.status NOT IN ($placeholders))";
            foreach ($CLOSED_STATUSES as $st) {
                $params[] = $st;
                $types   .= 's';
            }
        } else {
            $sql .= " AND (w.status IS NULL OR w.status <> 'Completed')";
        }
    }

    // Search
    if ($filterSearch !== '') {
        $sql .= " AND (w.wo_number LIKE ? OR w.title LIKE ?)";
        $like = '%' . $filterSearch . '%';
        $params[] = $like;
        $params[] = $like;
        $types    .= 'ss';
    }

    $sql .= " ORDER BY w.created_at DESC, w.id DESC LIMIT 300";

    $stmt = $conn->prepare($sql);
    if (!empty($params)) {
        $stmt->bind_param($types, ...$params);
    }
    $stmt->execute();
    $result = $stmt->get_result();

    while ($row = $result->fetch_assoc()) {
        $rows[] = $row;
    }
    $stmt->close();

} catch (Exception $e) {
    $errorMsg = "Error loading work orders: " . $e->getMessage();
}
?>

<div class="container mt-4">
    <h2 class="mb-3">
        <i class="bi bi-hammer"></i>
        <?php echo $isTechnician ? 'My Work Orders' : 'Work Orders'; ?>
    </h2>

    <?php if ($errorMsg): ?>
        <div class="alert alert-danger">
            <?php echo h($errorMsg); ?>
        </div>
    <?php endif; ?>

    <!-- Filters -->
    <form method="get" class="card mb-3 p-3">
        <div class="row g-2">

            <?php if ($isAdminLike): ?>
                <div class="col-md-3">
                    <label class="form-label">Status</label>
                    <select name="status" class="form-select">
                        <option value="">All</option>
                        <?php foreach ($STATUS_LABELS as $code => $label): ?>
                            <option value="<?php echo h($code); ?>" <?php echo $filterStatus === $code ? 'selected' : ''; ?>>
                                <?php echo h($label); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="col-md-3">
                    <label class="form-label">Technician</label>
                    <select name="assigned_to" class="form-select">
                        <option value="">All</option>
                        <?php foreach ($techList as $tech): ?>
                            <option
                                value="<?php echo h($tech['id']); ?>"
                                <?php echo $filterTech === (string)$tech['id'] ? 'selected' : ''; ?>
                            >
                                <?php echo h($tech['name']); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
            <?php else: ?>
                <input type="hidden" name="assigned_to" value="<?php echo h((string)$currentUserId); ?>">
            <?php endif; ?>

            <div class="<?php echo $isAdminLike ? 'col-md-4' : 'col-md-6'; ?>">
                <label class="form-label">Search (WO # / Title)</label>
                <input
                    type="text"
                    name="q"
                    class="form-control"
                    value="<?php echo h($filterSearch); ?>"
                    placeholder="e.g. WO-20251202, Batch repair..."
                >
            </div>

            <div class="<?php echo $isAdminLike ? 'col-md-2' : 'col-md-6'; ?> d-flex align-items-end gap-2">
                <?php if ($isTechnician): ?>
                    <div class="form-check">
                        <input class="form-check-input" type="checkbox" id="show_completed" name="show_completed" value="1"
                               <?php echo $showCompleted ? 'checked' : ''; ?>>
                        <label class="form-check-label" for="show_completed">Show completed</label>
                    </div>
                    <input type="hidden" name="only_open" value="<?php echo $showCompleted ? '0' : '1'; ?>">
                <?php else: ?>
                    <div class="form-check">
                        <input class="form-check-input" type="checkbox" id="only_open" name="only_open" value="1"
                               <?php echo $onlyOpen ? 'checked' : ''; ?>>
                        <label class="form-check-label" for="only_open">Only open (hide closed)</label>
                    </div>
                <?php endif; ?>
            </div>
        </div>

        <div class="mt-3 d-flex justify-content-end">
            <a href="work_orders_list.php" class="btn btn-outline-secondary me-2">
                <i class="bi bi-x-circle"></i> Clear
            </a>
            <button type="submit" class="btn btn-primary">
                <i class="bi bi-funnel"></i> Apply
            </button>
        </div>
    </form>

    <!-- Table -->
    <div class="card">
        <div class="card-body p-0">
            <div class="table-responsive">
                <table class="table table-striped table-hover table-sm align-middle mb-0">
                    <thead class="table-light">
                        <tr>
                            <th>WO #</th>
                            <th>Title</th>
                            <th>Status</th>
                            <th>Priority</th>
                            <?php if (!$isTechnician): ?>
                                <th>Technician</th>
                            <?php endif; ?>
                            <th>Created</th>
                            <th>Updated</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($rows)): ?>
                            <tr>
                                <td colspan="<?php echo $isTechnician ? 7 : 8; ?>" class="text-center py-3">
                                    No work orders found.
                                </td>
                            </tr>
                        <?php else: ?>
                            <?php foreach ($rows as $row): ?>
                                <?php
                                    $status = (string)$row['status'];
                                    $statusLabel = $STATUS_LABELS[$status] ?? ($status ?: 'Unknown');
                                    $statusClass = $STATUS_BADGE_CLASS[$status] ?? 'bg-secondary';

                                    $priorityVal = is_null($row['priority']) ? null : (int)$row['priority'];
                                    $priorityLabel = ($priorityVal !== null && array_key_exists($priorityVal, $PRIORITY_LABELS))
                                        ? $PRIORITY_LABELS[$priorityVal]
                                        : '';
                                    $priorityClass = ($priorityVal !== null && array_key_exists($priorityVal, $PRIORITY_BADGE_CLASS))
                                        ? $PRIORITY_BADGE_CLASS[$priorityVal]
                                        : 'bg-secondary';
                                ?>
                                <tr>
                                    <td><?php echo h($row['wo_number']); ?></td>
                                    <td><?php echo h($row['title']); ?></td>
                                    <td>
                                        <span class="badge <?php echo h($statusClass); ?>">
                                            <?php echo h($statusLabel); ?>
                                        </span>
                                    </td>
                                    <td>
                                        <?php if ($priorityLabel !== ''): ?>
                                            <span class="badge <?php echo h($priorityClass); ?>">
                                                <?php echo h($priorityLabel); ?>
                                            </span>
                                        <?php endif; ?>
                                    </td>

                                    <?php if (!$isTechnician): ?>
                                        <td><?php echo h($row['technician_name'] ?? $row['assigned_to']); ?></td>
                                    <?php endif; ?>

                                    <td><?php echo h($row['created_at']); ?></td>
                                    <td><?php echo h($row['updated_at']); ?></td>

                                    <td>
                                        <?php if ($isTechnician): ?>
                                            <a href="<?php echo h($woViewFile); ?>?id=<?php echo (int)$row['id']; ?>"
                                               class="btn btn-sm btn-primary">
                                                <i class="bi bi-tools"></i> Work
                                            </a>
                                        <?php else: ?>
                                            <a href="<?php echo h($woViewFile); ?>?id=<?php echo (int)$row['id']; ?>"
                                               class="btn btn-sm btn-outline-primary">
                                                <i class="bi bi-eye"></i> View
                                            </a>
                                        <?php endif; ?>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    <div class="mt-3 small text-muted">
        Note: “Due date” will be added after we create the column in <code>work_orders</code>.
    </div>
</div>

<?php include '../includes/footer.php'; ?>
