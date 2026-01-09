<?php
ini_set('display_errors', 1);
error_reporting(E_ALL);

require_once '../includes/auth.php';
require_once '../includes/db_connection.php';
require_once '../includes/inventory_log.php';
include '../includes/header.php';

$msg = "";

// Load BIN locations
$locations = [];
$res = $conn->query("SELECT id, location_name FROM locations ORDER BY location_name ASC");
while ($row = $res->fetch_assoc()) {
    $locations[] = $row;
}

// MOVEMENT PROCESS
if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    $serial = trim($_POST['serial'] ?? '');
    $new_location_id = (int)($_POST['new_location'] ?? 0);

    if ($serial === '' || $new_location_id === 0) {
        $msg = "<div class='alert alert-danger'>Serial and destination BIN are required.</div>";
    } else {
        // Fetch item
        $stmt = $conn->prepare("
            SELECT id, serial_number, location_id, bin_location, status
            FROM defectives_inventory
            WHERE serial_number = ?
            LIMIT 1
        ");
        $stmt->bind_param("s", $serial);
        $stmt->execute();
        $item = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        if (!$item) {
            $msg = "<div class='alert alert-danger'>Serial not found in inventory.</div>";
        } else {
            $old_location_id = (int)$item['location_id'];
            $old_bin         = $item['bin_location'];
            $old_status      = $item['status'];

            // Get new BIN name from locations table
            $stmt = $conn->prepare("SELECT location_name FROM locations WHERE id = ? LIMIT 1");
            $stmt->bind_param("i", $new_location_id);
            $stmt->execute();
            $stmt->bind_result($new_bin_name);
            $stmt->fetch();
            $stmt->close();

            if (!$new_bin_name) {
                $msg = "<div class='alert alert-danger'>Destination BIN not found in locations table.</div>";
            } else {
                // Update location_id + bin_location
                $stmt = $conn->prepare("
                    UPDATE defectives_inventory
                    SET location_id = ?, bin_location = ?, last_updated = NOW()
                    WHERE id = ?
                ");
                $stmt->bind_param("isi", $new_location_id, $new_bin_name, $item['id']);

                if ($stmt->execute()) {

                    // LOG THE MOVEMENT
                    log_inventory_movement($conn, [
                        'defectives_id'    => $item['id'],
                        'serial_number'    => $serial,
                        'movement_type'    => 'MOVE',
                        'from_location_id' => $old_location_id,
                        'to_location_id'   => $new_location_id,
                        'from_status'      => $old_status,
                        'to_status'        => $old_status,
                        'reason'           => 'BIN Change (move_bin.php)',
                        'user_id'          => $_SESSION['user_id'] ?? null
                    ]);

                    $msg = "<div class='alert alert-success'>
                                BIN updated successfully.<br>
                                <strong>$serial</strong>: moved from 
                                <strong>" . htmlspecialchars($old_bin ?: 'N/A') . "</strong> 
                                to 
                                <strong>" . htmlspecialchars($new_bin_name) . "</strong>.
                            </div>";
                } else {
                    $msg = "<div class='alert alert-danger'>Error updating BIN location.</div>";
                }

                $stmt->close();
            }
        }
    }
}
?>

<h2 class="mb-3">Move BIN</h2>

<?php if ($msg) echo $msg; ?>

<div class="card">
    <div class="card-body">

        <form method="post" class="row g-3">

            <div class="col-md-4">
                <label class="form-label">Serial Number</label>
                <input type="text" name="serial" class="form-control" required>
            </div>

            <div class="col-md-4">
                <label class="form-label">New BIN</label>
                <select name="new_location" class="form-select" required>
                    <option value="">Select BIN...</option>
                    <?php foreach ($locations as $loc): ?>
                        <option value="<?php echo $loc['id']; ?>">
                            <?php echo htmlspecialchars($loc['location_name']); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div class="col-md-4 d-flex align-items-end">
                <button type="submit" class="btn btn-primary w-100">Move BIN</button>
            </div>

        </form>

    </div>
</div>

</div> <!-- container -->
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
