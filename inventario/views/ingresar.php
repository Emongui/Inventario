<?php 
include('conexion.php'); 
include('lang.php'); 
$text = $dictionary[$lang]; 
include('defectos.php');
include('header.php'); ?>

<h2 class="mb-4 text-center"><?= $text['insert_title'] ?></h2>

<form id="inventoryForm" class="card p-4 shadow-sm bg-white rounded">
    <div class="mb-3">
        <label><?= $text['serial'] ?>:</label>
        <input type="text" class="form-control" name="serial" required>
    </div>

    <div class="mb-3">
        <label><?= $text['device_type'] ?>:</label>
        <select class="form-control" name="tipo" required>
            <option value="MacBook">MacBook</option>
            <option value="iPad">iPad</option>
            <option value="Chromebook">Chromebook</option>
            <option value="Tablet">Tablet</option>
        </select>
    </div>

    <div class="mb-3">
        <label>Modelo:</label>
        <input type="text" class="form-control" name="modelo" required>
    </div>

    <div class="mb-3">
        <label>Main Category:</label>
        <select class="form-control" id="main_category" name="main_category" required>
            <option value="">Select</option>
            <?php foreach(array_keys($defectos) as $main): ?>
                <option value="<?= $main ?>"><?= $main ?></option>
            <?php endforeach; ?>
        </select>
    </div>

    <div class="mb-3">
        <label>Sub Category:</label>
        <select class="form-control" id="sub_category" name="sub_category" required></select>
    </div>

    <div class="mb-3">
        <label>Defect:</label>
        <select class="form-control" id="defecto" name="defecto" required></select>
    </div>

    <div class="mb-3">
        <label>Usuario:</label>
        <input type="text" class="form-control" name="usuario" required>
    </div>

    <button type="submit" class="btn btn-success w-100"><?= $text['save'] ?></button>
</form>

<div id="response" class="mt-3"></div>

<script>
<?php include('defectos.php'); ?>
const defectos = <?= json_encode($defectos) ?>;

document.getElementById("main_category").addEventListener("change", function() {
    const main = this.value;
    const subSelect = document.getElementById("sub_category");
    subSelect.innerHTML = '<option value="">Select</option>';
    if (main && defectos[main]) {
        Object.keys(defectos[main]).forEach(sub => {
            subSelect.innerHTML += `<option value="${sub}">${sub}</option>`;
        });
    }
});

document.getElementById("sub_category").addEventListener("change", function() {
    const main = document.getElementById("main_category").value;
    const sub = this.value;
    const defectSelect = document.getElementById("defecto");
    defectSelect.innerHTML = '<option value="">Select</option>';
    if (main && sub && defectos[main][sub]) {
        defectos[main][sub].forEach(defect => {
            defectSelect.innerHTML += `<option value="${defect}">${defect}</option>`;
        });
    }
});

document.getElementById("inventoryForm").addEventListener("submit", function(e) {
    e.preventDefault();
    const formData = new FormData(this);
    fetch("add_inventory.php", { method: "POST", body: formData })
    .then(response => response.json())
    .then(data => {
        document.getElementById("response").innerHTML = 
            data.status === "success" 
            ? `<div class="alert alert-success">${data.message}</div>` 
            : `<div class="alert alert-danger">${data.message}</div>`;
        if (data.status === "success") document.getElementById("inventoryForm").reset();
    })
    .catch(error => {
        document.getElementById("response").innerHTML = `<div class="alert alert-danger">Error: ${error}</div>`;
    });
});
</script>

<?php include('footer.php'); ?>
