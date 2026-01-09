<?php
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

require_once './includes/db_connection.php';

$report = [];
$logFile = 'import_report.txt';

if (isset($_POST['submit'])) {
    if (is_uploaded_file($_FILES['csv_file']['tmp_name'])) {
        $file = fopen($_FILES['csv_file']['tmp_name'], 'r');
        fgetcsv($file); // Saltar encabezado
        $rowNumber = 1;
        file_put_contents($logFile, ''); // Limpiar log anterior

        while (($row = fgetcsv($file, 1000, ",")) !== FALSE) {
            $rowNumber++;

            // Estructura CSV:
            // 0 = serial_number
            // 1 = product_id (texto, ej: IPAD8-32W-GY)
            // 2 = category
            // 3 = grade
            // 4 = defect_code_id (texto, ej: MICROPHONE)
            // 5 = bin_location
            // 6 = status
            // 7 = invoice_number
            $serial_number   = trim($row[0] ?? '');
            $product_id_txt  = trim($row[1] ?? '');
            $category        = trim($row[2] ?? '');
            $grade           = trim($row[3] ?? '');
            $defect_code_txt = trim($row[4] ?? '');
            $bin_location    = trim($row[5] ?? '');
            $status          = trim($row[6] ?? '');
            $invoice_number  = trim($row[7] ?? '');

            if ($status === '') {
                $status = 'In Stock';
            }

            // Validar campos obligatorios
            if (
                $serial_number   === '' ||
                $product_id_txt  === '' ||
                $category        === '' ||
                $grade           === '' ||
                $defect_code_txt === '' ||
                $bin_location    === ''
            ) {
                $msg = "Fila $rowNumber ignorada: campos obligatorios vacíos.";
                $report[] = $msg;
                file_put_contents($logFile, "$msg\n", FILE_APPEND);
                continue;
            }

            // ==============================
            // 1. Obtener o insertar Product ID (product_ids.product_id = texto)
            // ==============================
            $stmtProd = $conn->prepare("SELECT id FROM product_ids WHERE product_id = ? LIMIT 1");
            $stmtProd->bind_param("s", $product_id_txt);
            $stmtProd->execute();
            $resProd = $stmtProd->get_result();

            if ($resProd->num_rows == 0) {
                $stmtInsertProd = $conn->prepare("INSERT INTO product_ids (product_id) VALUES (?)");
                $stmtInsertProd->bind_param("s", $product_id_txt);
                $stmtInsertProd->execute();
                $product_id = (int)$stmtInsertProd->insert_id;
                $stmtInsertProd->close();

                $msg = "Fila $rowNumber: Product ID '$product_id_txt' creado.";
                file_put_contents($logFile, "$msg\n", FILE_APPEND);
            } else {
                $rowProd   = $resProd->fetch_assoc();
                $product_id = (int)$rowProd['id'];
            }
            $stmtProd->close();

            // ==============================
            // 2. Obtener o insertar Defect Code (defect_codes.defect_code = texto)
            // ==============================
            $stmtDef = $conn->prepare("SELECT id FROM defect_codes WHERE defect_code = ? LIMIT 1");
            $stmtDef->bind_param("s", $defect_code_txt);
            $stmtDef->execute();
            $resDef = $stmtDef->get_result();

            if ($resDef->num_rows == 0) {
                $stmtInsertDef = $conn->prepare("INSERT INTO defect_codes (defect_code) VALUES (?)");
                $stmtInsertDef->bind_param("s", $defect_code_txt);
                $stmtInsertDef->execute();
                $defect_code_id = (int)$stmtInsertDef->insert_id;
                $stmtInsertDef->close();

                $msg = "Fila $rowNumber: Defect Code '$defect_code_txt' creado.";
                file_put_contents($logFile, "$msg\n", FILE_APPEND);
            } else {
                $rowDef        = $resDef->fetch_assoc();
                $defect_code_id = (int)$rowDef['id'];
            }
            $stmtDef->close();

            // ==============================
            // 3. FORZAR: Location (BIN) debe existir
            // ==============================
            $stmtLoc = $conn->prepare("SELECT id FROM locations WHERE location_name = ? LIMIT 1");
            $stmtLoc->bind_param("s", $bin_location);
            $stmtLoc->execute();
            $resLoc = $stmtLoc->get_result();

            if ($resLoc->num_rows == 0) {
                $msg = "Fila $rowNumber ERROR: BIN '$bin_location' no existe en locations. Fila ignorada.";
                $report[] = $msg;
                file_put_contents($logFile, "$msg\n", FILE_APPEND);
                $stmtLoc->close();
                continue;
            }
            $rowLoc      = $resLoc->fetch_assoc();
            $location_id = (int)$rowLoc['id'];
            $stmtLoc->close();

            // ==============================
            // 4. Verificar serial duplicado
            // ==============================
            $stmtCheckSerial = $conn->prepare("SELECT id FROM defectives_inventory WHERE serial_number = ? LIMIT 1");
            $stmtCheckSerial->bind_param("s", $serial_number);
            $stmtCheckSerial->execute();
            $resSerial = $stmtCheckSerial->get_result();

            if ($resSerial->num_rows > 0) {
                $msg = "Fila $rowNumber ignorada: Serial '$serial_number' ya existe.";
                $report[] = $msg;
                file_put_contents($logFile, "$msg\n", FILE_APPEND);
                $stmtCheckSerial->close();
                continue;
            }
            $stmtCheckSerial->close();

            // ==============================
            // 5. Insertar en defectives_inventory
            // ==============================
            $stmtInsert = $conn->prepare("
                INSERT INTO defectives_inventory 
                (serial_number, product_id, category, grade, defect_code_id, status, bin_location, location_id, invoice_number)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)
            ");
            $stmtInsert->bind_param(
                "sississis",
                $serial_number,
                $product_id,
                $category,
                $grade,
                $defect_code_id,
                $status,
                $bin_location,
                $location_id,
                $invoice_number
            );

            if ($stmtInsert->execute()) {
                $msg = "Fila $rowNumber insertada correctamente: Serial '$serial_number'.";
            } else {
                $msg = "Fila $rowNumber ERROR al insertar: " . $stmtInsert->error;
            }

            $report[] = $msg;
            file_put_contents($logFile, "$msg\n", FILE_APPEND);
            $stmtInsert->close();
        }

        fclose($file);
    } else {
        $msg = "❌ Error al subir el archivo CSV.";
        $report[] = $msg;
        file_put_contents($logFile, "$msg\n", FILE_APPEND);
    }
}
?>

<h2>Importar Inventario en Bulk desde CSV</h2>
<form method="post" enctype="multipart/form-data">
    <input type="file" name="csv_file" required>
    <input type="submit" name="submit" value="Importar CSV">
</form>

<?php if (!empty($report)): ?>
    <h4>Resultado de la Importación:</h4>
    <ul>
        <?php foreach ($report as $line): ?>
            <li><?= htmlspecialchars($line) ?></li>
        <?php endforeach; ?>
    </ul>
    <p>
        <a href="<?= $logFile ?>" download>📥 Descargar reporte completo</a>
    </p>
<?php endif; ?>
