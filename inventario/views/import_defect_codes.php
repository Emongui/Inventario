<?php
require_once '../includes/db_connection.php';

// Limpiar defect_codes si se envió el formulario "limpiar"
if (isset($_POST['clear_table'])) {
    // Desactivamos claves foráneas temporalmente
    $conn->query("SET FOREIGN_KEY_CHECKS = 0");
    $conn->query("TRUNCATE TABLE defect_codes");
    $conn->query("SET FOREIGN_KEY_CHECKS = 1");
    echo "<p style='color: green;'>✅ La tabla defect_codes fue limpiada correctamente.</p>";
}

// Procesar archivo CSV si se envió
if (isset($_POST['import_csv']) && isset($_FILES['csv_file'])) {
    $file = $_FILES['csv_file']['tmp_name'];

    if (($handle = fopen($file, "r")) !== FALSE) {
        $row = 0;
        $inserted = 0;
        $skipped = 0;

        while (($data = fgetcsv($handle, 1000, ",")) !== FALSE) {
            if ($row === 0 && strtolower(trim($data[0])) === "main category") {
                $row++;
                continue;
            }

            $main_category = isset($data[0]) ? trim($data[0]) : '';
            $sub_category  = isset($data[1]) ? trim($data[1]) : '';
            $defect_code   = isset($data[2]) ? trim($data[2]) : '';
            $description   = isset($data[3]) ? trim($data[3]) : '';

            if ($defect_code === '') {
                continue;
            }

            $stmt = $conn->prepare("SELECT id FROM defect_codes WHERE defect_code = ?");
            $stmt->bind_param("s", $defect_code);
            $stmt->execute();
            $stmt->store_result();

            if ($stmt->num_rows === 0) {
                $stmt->close();
                $insert = $conn->prepare("INSERT INTO defect_codes (main_category, sub_category, defect_code, description) VALUES (?, ?, ?, ?)");
                $insert->bind_param("ssss", $main_category, $sub_category, $defect_code, $description);
                $insert->execute();
                $insert->close();
                $inserted++;
            } else {
                $stmt->close();
                $skipped++;
            }

            $row++;
        }

        fclose($handle);

        echo "<p style='color: green;'>✔️ Insertados: $inserted</p>";
        echo "<p style='color: orange;'>⛔ Duplicados ignorados: $skipped</p>";
    } else {
        echo "<p style='color: red;'>❌ Error al leer el archivo CSV.</p>";
    }
}
?>

<!-- FORMULARIO DE CARGA -->
<h2>Importar códigos de defecto</h2>

<form method="post" enctype="multipart/form-data" onsubmit="return confirm('¿Estás seguro de subir este archivo CSV?')">
    <label>Archivo CSV:</label>
    <input type="file" name="csv_file" accept=".csv" required>
    <br><br>
    <input type="submit" name="import_csv" value="Importar CSV">
</form>

<!-- FORMULARIO DE LIMPIEZA -->
<form method="post" onsubmit="return confirm('⚠️ Esto borrará todos los defect codes. ¿Estás seguro?')">
    <input type="submit" name="clear_table" value="🧹 Limpiar tabla defect_codes">
</form>
