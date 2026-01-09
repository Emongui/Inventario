<?php
require_once './includes/db_connection.php';

if (isset($_POST['submit'])) {
    if (is_uploaded_file($_FILES['csv_file']['tmp_name'])) {
        $file = fopen($_FILES['csv_file']['tmp_name'], 'r');
        fgetcsv($file); // Ignora encabezado

        while (($row = fgetcsv($file, 1000, ",")) !== FALSE) {
            $product_id = $conn->real_escape_string($row[0]);
            $description = $conn->real_escape_string($row[1]);

            $check = $conn->query("SELECT id FROM product_ids WHERE product_id = '$product_id'");
            if ($check->num_rows == 0) {
                $sql = "INSERT INTO product_ids (product_id, description) VALUES ('$product_id', '$description')";
                $conn->query($sql);
            }
        }
        fclose($file);
        echo "Importación completada correctamente.";
    } else {
        echo "Error al cargar el archivo.";
    }
}
?>

<form method="post" enctype="multipart/form-data">
    <input type="file" name="csv_file" required>
    <input type="submit" name="submit" value="Importar CSV">
</form>
