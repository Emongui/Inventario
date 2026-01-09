<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

$logged_user = $_SESSION['user']  ?? '';
$logged_role = $_SESSION['role']  ?? '';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <link rel='stylesheet' href='https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css'>
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.1/font/bootstrap-icons.css">

    <style>
        body { background: #f5f6fa; }

        .navbar-custom {
            background: #1e1e2f;
            border-bottom: 3px solid #4cc9f0;
        }

        .nav-link {
            color: #d7d7e0 !important;
            font-weight: 500;
        }
        .nav-link:hover {
            color: #4cc9f0 !important;
        }

        .brand-title {
            font-weight: bold;
            letter-spacing: 0.5px;
            color: #4cc9f0 !important;
        }

        .user-chip {
            background: #4cc9f0;
            padding: 4px 8px;
            border-radius: 6px;
            font-size: 13px;
            color: black;
            margin-right: 6px;
        }
        .role-chip {
            background: #ffbe0b;
            padding: 4px 8px;
            border-radius: 6px;
            font-size: 13px;
            color: #000;
            margin-right: 10px;
        }

        .avatar {
            width: 32px;
            height: 32px;
            background: #ffbe0b;
            border-radius: 50%;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            color: black;
            font-weight: bold;
            margin-right: 10px;
        }

        .logout-btn {
            border: 1px solid #ff4d6d;
            color: #ff4d6d;
            font-weight: bold;
        }
        .logout-btn:hover {
            background: #ff4d6d;
            color: white;
        }

    </style>
</head>

<body>

<nav class="navbar navbar-expand-lg navbar-custom px-3">
    <a class="navbar-brand brand-title" href="/inventario/views/dashboard.php">
        <i class="bi bi-cpu"></i> CTI Inventory
    </a>

    <button class="navbar-toggler bg-light" type="button" data-bs-toggle="collapse" data-bs-target="#mainNav">
        <span class="navbar-toggler-icon"></span>
    </button>

    <div class="collapse navbar-collapse" id="mainNav">

        <ul class="navbar-nav me-auto">

            <!-- Dashboard -->
            <li class="nav-item">
                <a class="nav-link" href="/inventario/views/dashboard.php">
                    <i class="bi bi-house-door"></i> Dashboard
                </a>
            </li>

            <!-- Work Orders -->
            <li class="nav-item">
                <a class="nav-link" href="/inventario/views/work_orders_list.php">
                    <i class="bi bi-hammer"></i> Work Orders
                </a>
            </li>

            <!-- Create WO (Only admin + supervisor) -->
            <?php if (in_array($logged_role, ['admin','supervisor'])): ?>
            <li class="nav-item">
                <a class="nav-link" href="/inventario/views/manage_process_area.php">
                    <i class="bi bi-plus-square"></i> Create WO
                </a>
            </li>
            <?php endif; ?>

            <!-- Inventory FULL submenu -->
            <?php if (in_array($logged_role, ['admin','inventory','supervisor'])): ?>
            <li class="nav-item dropdown">
                <a class="nav-link dropdown-toggle" href="#" data-bs-toggle="dropdown">
                    <i class="bi bi-box-seam"></i> Inventory Tools
                </a>
                <ul class="dropdown-menu">

                    <li>
                        <a class="dropdown-item" href="/inventario/views/add_inventory.php">
                            <i class="bi bi-plus-circle"></i> Ingresar Inventario
                        </a>
                    </li>

                    <li>
                        <a class="dropdown-item" href="/inventario/views/search_inventory.php">
                            <i class="bi bi-search"></i> Buscar
                        </a>
                    </li>

                    <li>
                        <a class="dropdown-item" href="/inventario/views/remove_inventory.php">
                            <i class="bi bi-trash"></i> Retirar
                        </a>
                    </li>

                    <!-- Aquí reemplazamos el viejo move_bin.php por el nuevo manage_bins.php -->
                    <li>
                        <a class="dropdown-item" href="/inventario/views/manage_bins.php">
                            <i class="bi bi-arrow-left-right"></i> Manage BIN
                        </a>
                    </li>

                    <li>
                       <a class="dropdown-item" href="/inventario/views/manage_process_area.php">
                            <i class="bi bi-diagram-3"></i> Process Area
                        </a>
                    </li>


                    <li>
                        <a class="dropdown-item" href="/inventario/views/export_inventory.php">
                            <i class="bi bi-file-earmark-arrow-down"></i> Exportar
                        </a>
                    </li>
                    <li>
                        <a class="dropdown-item" href="/inventario/views/inventory_logs.php">
                            <i class="bi bi-clock-history"></i> Inventory Logs
                        </a>
                    </li>
                    <li>
                        <a class="dropdown-item" href="/inventario/views/import_paste.php">
                            <i class="bi bi-clipboard-plus"></i> Import by Paste
                        </a>
                     </li>


                </ul>
            </li>
            <?php endif; ?>

            <!-- Admin Panel -->
            <?php if ($logged_role === 'admin'): ?>
            <li class="nav-item">
                <a class="nav-link" href="/inventario/views/admin_panel.php">
                    <i class="bi bi-gear"></i> Admin Panel
                </a>
            </li>
            <?php endif; ?>

        </ul>

        <!-- USER PROFILE + LOGOUT -->
        <div class="d-flex align-items-center">

            <div class="avatar">
                <?php echo strtoupper(substr($logged_user, 0, 1)); ?>
            </div>

            <span class="user-chip"><?php echo htmlspecialchars($logged_user); ?></span>
            <span class="role-chip"><?php echo htmlspecialchars($logged_role); ?></span>

            <a href="/inventario/logout.php" class="btn logout-btn btn-sm ms-2">
                <i class="bi bi-box-arrow-right"></i> Logout
            </a>
        </div>

    </div>
</nav>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>

<div class="container mt-4">
