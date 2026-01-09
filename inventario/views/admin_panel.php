<?php
// views/admin_panel.php
// Simple admin panel to manage users and roles.

ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

require_once '../includes/auth.php';
require_once '../includes/db_connection.php';
include '../includes/header.php';

// Only admin can access this page
if ($logged_role !== 'admin') {
    echo "<p>You do not have permission to access this page.</p>";
    exit;
}

$msg = "";

// Handle add user
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'add_user') {
    $new_username = trim($_POST['new_username'] ?? '');
    $new_password = trim($_POST['new_password'] ?? '');
    $new_role     = $_POST['new_role'] ?? 'viewer';

    if ($new_username === '' || $new_password === '') {
        $msg = "<div class='alert alert-danger'>Username and password are required.</div>";
    } else {
        $hash = password_hash($new_password, PASSWORD_DEFAULT);
        $stmt = $conn->prepare('INSERT INTO users (username, password, role) VALUES (?, ?, ?)');
        $stmt->bind_param('sss', $new_username, $hash, $new_role);
        if ($stmt->execute()) {
            $msg = "<div class='alert alert-success'>User added successfully.</div>";
        } else {
            $msg = "<div class='alert alert-danger'>Error adding user: " . htmlspecialchars($stmt->error) . "</div>";
        }
        $stmt->close();
    }
}

// Handle role update
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'update_role') {
    $user_id   = (int)($_POST['user_id'] ?? 0);
    $new_role  = $_POST['role'] ?? 'viewer';

    if ($user_id > 0) {
        $stmt = $conn->prepare('UPDATE users SET role = ? WHERE id = ?');
        $stmt->bind_param('si', $new_role, $user_id);
        if ($stmt->execute()) {
            $msg = "<div class='alert alert-success'>Role updated successfully.</div>";
        } else {
            $msg = "<div class='alert alert-danger'>Error updating role: " . htmlspecialchars($stmt->error) . "</div>";
        }
        $stmt->close();
    }
}

// Handle password reset (default: Laptop99)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'reset_password') {
    $user_id   = (int)($_POST['user_id'] ?? 0);
    $new_pass  = $_POST['new_password'] ?? 'Laptop99';

    if ($user_id > 0) {
        $hash = password_hash($new_pass, PASSWORD_DEFAULT);
        $stmt = $conn->prepare('UPDATE users SET password = ? WHERE id = ?');
        $stmt->bind_param('si', $hash, $user_id);
        if ($stmt->execute()) {
            $msg = "<div class='alert alert-success'>Password reset successfully.</div>";
        } else {
            $msg = "<div class='alert alert-danger'>Error resetting password: " . htmlspecialchars($stmt->error) . "</div>";
        }
        $stmt->close();
    }
}

// Fetch all users
$result = $conn->query('SELECT id, username, role, created_at FROM users ORDER BY id ASC');
if (!$result) {
    die('Error fetching users: ' . $conn->error);
}

$roles = ['admin','supervisor','technician','inventory','qc','viewer'];
?>
<h2>Admin Panel - Users &amp; Roles</h2>

<?php if ($msg) echo $msg; ?>

<div class="card mb-4">
    <div class="card-header">
        <strong>Add New User</strong>
    </div>
    <div class="card-body">
        <form method="post" class="row g-3">
            <input type="hidden" name="action" value="add_user">
            <div class="col-md-4">
                <label class="form-label">Username</label>
                <input type="text" name="new_username" class="form-control" required>
            </div>
            <div class="col-md-4">
                <label class="form-label">Password</label>
                <input type="text" name="new_password" class="form-control" value="Laptop99" required>
                <div class="form-text">You can change this default password.</div>
            </div>
            <div class="col-md-3">
                <label class="form-label">Role</label>
                <select name="new_role" class="form-select">
                    <?php foreach ($roles as $r): ?>
                        <option value="<?php echo $r; ?>"><?php echo ucfirst($r); ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-md-1 d-flex align-items-end">
                <button type="submit" class="btn btn-primary w-100">Add</button>
            </div>
        </form>
    </div>
</div>

<div class="card">
    <div class="card-header">
        <strong>Existing Users</strong>
    </div>
    <div class="card-body">
        <table class="table table-sm table-striped align-middle">
            <thead>
                <tr>
                    <th>ID</th>
                    <th>Username</th>
                    <th>Role</th>
                    <th>Created</th>
                    <th>Update Role</th>
                    <th>Reset Password</th>
                </tr>
            </thead>
            <tbody>
            <?php while ($u = $result->fetch_assoc()): ?>
                <tr>
                    <td><?php echo (int)$u['id']; ?></td>
                    <td><?php echo htmlspecialchars($u['username']); ?></td>
                    <td><?php echo htmlspecialchars($u['role']); ?></td>
                    <td><?php echo htmlspecialchars($u['created_at'] ?? ''); ?></td>
                    <td>
                        <form method="post" class="d-flex">
                            <input type="hidden" name="action" value="update_role">
                            <input type="hidden" name="user_id" value="<?php echo (int)$u['id']; ?>">
                            <select name="role" class="form-select form-select-sm me-2">
                                <?php foreach ($roles as $r): ?>
                                    <option value="<?php echo $r; ?>" <?php if ($u['role'] === $r) echo 'selected'; ?>>
                                        <?php echo ucfirst($r); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                            <button type="submit" class="btn btn-sm btn-outline-primary">Save</button>
                        </form>
                    </td>
                    <td>
                        <form method="post" class="d-flex">
                            <input type="hidden" name="action" value="reset_password">
                            <input type="hidden" name="user_id" value="<?php echo (int)$u['id']; ?>">
                            <input type="text" name="new_password"
                                   class="form-control form-control-sm me-2"
                                   value="Laptop99">
                            <button type="submit" class="btn btn-sm btn-outline-danger">Reset</button>
                        </form>
                    </td>
                </tr>
            <?php endwhile; ?>
            </tbody>
        </table>
    </div>
</div>

</div> <!-- container from header -->
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
