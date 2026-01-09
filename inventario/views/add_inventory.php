<?php
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);
mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

require_once '../includes/auth.php';
require_once '../includes/db_connection.php';
include '../includes/header.php';

$msg = "";
$product_id = null;

/* Helper: map location_name -> id (returns null if not found) */
function get_location_id_by_name(mysqli $conn, string $name): ?int {
    $name = trim($name);
    if ($name === '') return null;
    $stmt = $conn->prepare("SELECT id FROM locations WHERE location_name = ? LIMIT 1");
    $stmt->bind_param("s", $name);
    $stmt->execute();
    $stmt->bind_result($loc_id);
    $found = $stmt->fetch();
    $stmt->close();
    return $found ? (int)$loc_id : null;
}

try {
    if (isset($_POST['submit'])) {
        $serial_number  = trim($_POST['serial_number'] ?? '');
        $product_id_raw = trim($_POST['product_id'] ?? '');
        $category       = $_POST['category'] ?? '';
        $grade          = trim($_POST['grade'] ?? '');
        $defect_code_id = (int)($_POST['defect_code'] ?? 0);
        $bin_name_input = trim($_POST['bin_name'] ?? '');
        $invoice_number = isset($_POST['invoice_number']) && $_POST['invoice_number'] !== '' ? $_POST['invoice_number'] : null;
        $status_value   = 'In Stock'; // coincide con ENUM de la columna status

        // --- Product ID: find or create ---
        if ($product_id_raw !== '') {
            $stmt = $conn->prepare("SELECT id FROM product_ids WHERE product_id = ? LIMIT 1");
            $stmt->bind_param("s", $product_id_raw);
            $stmt->execute();
            $stmt->bind_result($pid);
            if ($stmt->fetch()) {
                $product_id = (int)$pid;
            }
            $stmt->close();

            if (!$product_id) {
                $stmt = $conn->prepare("INSERT INTO product_ids (product_id) VALUES (?)");
                $stmt->bind_param("s", $product_id_raw);
                $stmt->execute();
                $product_id = $stmt->insert_id;
                $stmt->close();
            }
        }

        // --- Location: BIN/RMA name -> location_id ---
        $location_id = get_location_id_by_name($conn, $bin_name_input);

        // --- Validaciones ---
        if (
            $serial_number === '' || empty($product_id) || $category === '' ||
            $grade === '' || empty($defect_code_id) || $bin_name_input === ''
        ) {
            $msg = "<div class='alert alert-danger'>Todos los campos son obligatorios.</div>";
        } elseif ($location_id === null) {
            $msg = "<div class='alert alert-danger'>La locación <b>" . htmlspecialchars($bin_name_input) . "</b> no existe. Selecciona un BIN válido.</div>";
        } else {
            // Serial duplicado
            $stmt = $conn->prepare("SELECT id FROM defectives_inventory WHERE serial_number = ? LIMIT 1");
            $stmt->bind_param("s", $serial_number);
            $stmt->execute();
            $stmt->store_result();
            $exists = $stmt->num_rows > 0;
            $stmt->close();

            if ($exists) {
                $msg = "<div class='alert alert-warning'>Error: El Serial Number ya existe.</div>";
            } else {
                $sql = "INSERT INTO defectives_inventory 
					(serial_number, product_id, category, grade, defect_code_id, location_id, bin_location, status, invoice_number, date_added, last_updated) 
					VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, NOW(), NOW())";

					$stmt = $conn->prepare($sql);
					// tipos: s i s s i i s s s
					$stmt->bind_param(
					"sissiisss",
					$serial_number,     // s
					$product_id,        // i
					$category,          // s
					$grade,             // s
					$defect_code_id,    // i
					$location_id,       // i
					$bin_name_input,    // s  <- aquí guardas el nombre del BIN
					$status_value,      // s
					$invoice_number     // s
					);

                if ($stmt->execute()) {
                    $msg = "<div class='alert alert-success'>Equipo ingresado correctamente.</div>";
                    $_POST = []; // limpia el formulario
                } else {
                    $msg = "<div class='alert alert-danger'>Error al ingresar: ".htmlspecialchars($stmt->error)."</div>";
                }
                $stmt->close();
            }
        }
    }
} catch (Throwable $e) {
    $msg = "<div class='alert alert-danger'>Error: ".htmlspecialchars($e->getMessage())."</div>";
}

/* Cargar defect codes agrupados */
$defect_codes = $conn->query("SELECT id, main_category, sub_category, defect_code FROM defect_codes ORDER BY main_category, sub_category, defect_code");

/* Preload locations for datalist */
$locations_rs = $conn->query("SELECT location_name FROM locations ORDER BY location_name ASC LIMIT 1500");
$locations = [];
while ($r = $locations_rs->fetch_assoc()) { $locations[] = $r['location_name']; }
$locations_rs->close();
?>

<div class="card">
  <div class="card-header">Ingreso de Inventario Defectivo</div>
  <div class="card-body">
    <?= $msg ?>
    <form method="post" autocomplete="off">
      <div class="mb-3">
        <label>Serial Number:</label>
        <input type="text" name="serial_number" class="form-control" value="<?= isset($_POST['serial_number']) ? htmlspecialchars($_POST['serial_number']) : '' ?>" required>
      </div>

      <div class="mb-3 position-relative">
        <label for="product_id" class="form-label">Product ID</label>
        <input type="text" id="product_id" name="product_id" class="form-control" placeholder="Buscar o ingresar nuevo Product ID" autocomplete="off" required value="<?= isset($_POST['product_id']) ? htmlspecialchars($_POST['product_id']) : '' ?>">
        <div id="product_suggestions" class="list-group position-absolute w-100" style="z-index: 1000;"></div>
      </div>

      <div class="mb-3">
        <label>Categoría:</label>
        <select class="form-control" name="category" required>
          <option value="">Seleccione</option>
          <option value="MacBook" <?= (isset($_POST['category']) && $_POST['category'] == 'MacBook') ? 'selected' : '' ?>>MacBook</option>
          <option value="iPad" <?= (isset($_POST['category']) && $_POST['category'] == 'iPad') ? 'selected' : '' ?>>iPad</option>
          <option value="Tablet" <?= (isset($_POST['category']) && $_POST['category'] == 'Tablet') ? 'selected' : '' ?>>Tablet</option>
          <option value="Surface" <?= (isset($_POST['category']) && $_POST['category'] == 'Surface') ? 'selected' : '' ?>>Surface</option>
          <option value="Others" <?= (isset($_POST['category']) && $_POST['category'] == 'others') ? 'selected' : '' ?>>Others</option>
        </select>
      </div>

      <div class="mb-3">
        <label>Grade:</label>
        <input type="text" class="form-control" name="grade" required value="<?= isset($_POST['grade']) ? htmlspecialchars($_POST['grade']) : '' ?>">
      </div>

      <div class="mb-3">
        <label>Defect Code:</label>
        <select class="form-control" name="defect_code" required>
          <option value="">Seleccione</option>
          <?php
          $defect_codes = $conn->query("SELECT id, main_category, sub_category, defect_code FROM defect_codes ORDER BY main_category, sub_category, defect_code");
          $current_main = '';
          $current_sub  = '';
          while($row = $defect_codes->fetch_assoc()) {
              if ($current_main !== $row['main_category']) {
                  if ($current_main !== '') {
                      if ($current_sub !== '') { echo "</optgroup>"; $current_sub = ''; }
                      echo "</optgroup>";
                  }
                  echo "<optgroup label='⮞ " . htmlspecialchars($row['main_category']) . "'>";
                  $current_main = $row['main_category'];
              }
              if ($current_sub !== $row['sub_category']) {
                  if ($current_sub !== '') { echo "</optgroup>"; }
                  echo "<optgroup label='--- " . htmlspecialchars($row['sub_category']) . "'>";
                  $current_sub = $row['sub_category'];
              }
              $sel = (isset($_POST['defect_code']) && (int)$_POST['defect_code'] === (int)$row['id']) ? 'selected' : '';
              echo "<option value='" . (int)$row['id'] . "' $sel>" . htmlspecialchars($row['defect_code']) . "</option>";
          }
          if ($current_sub !== '') echo "</optgroup>";
          if ($current_main !== '') echo "</optgroup>";
          ?>
        </select>
      </div>

      <!-- Bin Location con datalist (buscador) -->
      <div class="mb-3">
        <label>Bin Location:</label>
        <input type="text" class="form-control" name="bin_name" list="bins_list" placeholder="Ej: BIN034" required value="<?= isset($_POST['bin_name']) ? htmlspecialchars($_POST['bin_name']) : '' ?>">
        <datalist id="bins_list">
          <?php foreach($locations as $loc): ?>
            <option value="<?= htmlspecialchars($loc) ?>"></option>
          <?php endforeach; ?>
        </datalist>
        <small class="text-muted">Escribe para buscar: BIN001…BIN500, RMA###, etc.</small>
      </div>

      <div class="form-group mb-3">
        <label for="invoice_number">Invoice Number (opcional):</label>
        <input type="text" name="invoice_number" id="invoice_number" class="form-control" value="<?= isset($_POST['invoice_number']) ? htmlspecialchars($_POST['invoice_number']) : '' ?>">
      </div>

      <button type="submit" name="submit" class="btn btn-primary">Ingresar Equipo</button>
    </form>
  </div>
</div>

<script>
document.addEventListener("DOMContentLoaded", function () {
    const input = document.getElementById("product_id");
    const suggestions = document.getElementById("product_suggestions");

    input.addEventListener("keyup", function () {
        const query = input.value.trim();

        if (query.length >= 2) {
            fetch("../search_product_ids.php?q=" + encodeURIComponent(query))
                .then(response => response.json())
                .then(data => {
                    suggestions.innerHTML = "";
                    data.forEach(item => {
                        const option = document.createElement("button");
                        option.type = "button";
                        option.className = "list-group-item list-group-item-action";
                        option.textContent = item.product_id;
                        option.onclick = () => {
                            input.value = item.product_id;
                            suggestions.innerHTML = "";
                        };
                        suggestions.appendChild(option);
                    });
                })
                .catch(error => {
                    console.error("Error en búsqueda:", error);
                });
        } else {
            suggestions.innerHTML = "";
        }
    });

    document.addEventListener("click", function (event) {
        if (!suggestions.contains(event.target) && event.target !== input) {
            suggestions.innerHTML = "";
        }
    });
});
</script>

<?php include '../includes/footer.php'; ?>
