<?php
ini_set('display_errors', 1);
error_reporting(E_ALL);
mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

require_once '../includes/auth.php';
require_once '../includes/db_connection.php';

/* -----------------------------------------------------------
   Helper para imprimir valores sin warnings (NULL-safe)
----------------------------------------------------------- */
function h($value): string {
    return htmlspecialchars((string)($value ?? ''), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function normalizeHeader(string $s): string {
    $s = trim($s);
    $s = strtolower($s);
    $s = preg_replace('/[^a-z0-9_]+/', '_', $s);
    return trim($s, '_');
}

function parseMoney($v): ?float {
    if ($v === null) return null;
    $v = trim((string)$v);
    if ($v === '') return null;
    $v = str_replace([',', '$', ' '], '', $v);
    if (!preg_match('/^\d+(\.\d{1,2})?$/', $v)) return null;
    return (float)$v;
}

$currentRole = $_SESSION['role'] ?? 'viewer';
$canImportValue = in_array($currentRole, ['admin','supervisor','inventory','inventory_manager'], true);

/* -----------------------------------------------------------
   GET FILTERS
----------------------------------------------------------- */
$selected_status      = $_GET['status']       ?? '';
$selected_category    = $_GET['category']     ?? '';
$selected_bin_id      = $_GET['bin_id']       ?? '';   // now location_id (clean)
$selected_grade       = $_GET['grade']        ?? '';
$selected_defect_code = $_GET['defect_code']  ?? '';
$start_date           = $_GET['start_date']   ?? '';
$end_date             = $_GET['end_date']     ?? '';

/* -----------------------------------------------------------
   HANDLE IMPORT VALUE (POST) - BEFORE ANY HTML OUTPUT
----------------------------------------------------------- */
$importFlash = '';
$importFlashType = 'success';
$importPreview = [];
$importPreviewMax = 8;

$importStats = [
    'rows_total' => 0,
    'rows_skipped' => 0,
    'updated' => 0,
    'cleared' => 0,
    'not_found' => 0,
    'invalid_value' => 0,
];

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'import_value') {

    if (!$canImportValue) {
        http_response_code(403);
        $importFlash = "You don't have permission to import value.";
        $importFlashType = 'danger';
    } elseif (!isset($_FILES['csv_file']) || $_FILES['csv_file']['error'] !== UPLOAD_ERR_OK) {
        $importFlash = "Upload failed. Please select a CSV file.";
        $importFlashType = 'danger';
    } else {
        $apply = (($_POST['apply'] ?? '0') === '1');
        $tmpPath = $_FILES['csv_file']['tmp_name'];

        $fh = fopen($tmpPath, 'r');
        if (!$fh) {
            $importFlash = "Could not read uploaded file.";
            $importFlashType = 'danger';
        } else {
            $rawHeaders = fgetcsv($fh);
            if (!$rawHeaders) {
                $importFlash = "CSV is empty or invalid.";
                $importFlashType = 'danger';
                fclose($fh);
            } else {
                $headers = array_map(fn($x) => normalizeHeader((string)$x), $rawHeaders);
                $idx = array_flip($headers);

                // Must include value
                if (!isset($idx['value'])) {
                    $importFlash = "Missing required column: value.";
                    $importFlashType = 'danger';
                    fclose($fh);
                } else {
                    // id OR serial_number (accept variants)
                    $hasId = isset($idx['id']);
                    $serialKey = '';
                    foreach (['serial_number','serial_no','serialno','serial'] as $k) {
                        if (isset($idx[$k])) { $serialKey = $k; break; }
                    }
                    $hasSerial = ($serialKey !== '');

                    if (!$hasId && !$hasSerial) {
                        $importFlash = "CSV must include identifier: id OR serial_number.";
                        $importFlashType = 'danger';
                        fclose($fh);
                    } else {
                        // Prepare statements
                        $stmtByIdSet   = $conn->prepare("UPDATE defectives_inventory SET value = ?, last_updated = NOW() WHERE id = ? LIMIT 1");
                        $stmtByIdClear = $conn->prepare("UPDATE defectives_inventory SET value = NULL, last_updated = NOW() WHERE id = ? LIMIT 1");

                        $stmtBySerialSet   = $conn->prepare("UPDATE defectives_inventory SET value = ?, last_updated = NOW() WHERE serial_number = ? LIMIT 1");
                        $stmtBySerialClear = $conn->prepare("UPDATE defectives_inventory SET value = NULL, last_updated = NOW() WHERE serial_number = ? LIMIT 1");

                        $stmtCheckId     = $conn->prepare("SELECT id FROM defectives_inventory WHERE id = ? LIMIT 1");
                        $stmtCheckSerial = $conn->prepare("SELECT id FROM defectives_inventory WHERE serial_number = ? LIMIT 1");

                        if ($apply) $conn->begin_transaction();

                        $lineNo = 1;
                        while (($row = fgetcsv($fh)) !== false) {
                            $lineNo++;
                            $importStats['rows_total']++;

                            $get = function(string $key) use ($idx, $row) {
                                $i = $idx[$key] ?? null;
                                if ($i === null) return null;
                                return array_key_exists($i, $row) ? $row[$i] : null;
                            };

                            $rawValue = $get('value');
                            $parsedValue = parseMoney($rawValue);

                            $idVal = $hasId ? trim((string)$get('id')) : '';
                            $serialVal = $hasSerial ? trim((string)$get($serialKey)) : '';

                            if ($idVal === '' && $serialVal === '') {
                                $importStats['rows_skipped']++;
                                continue;
                            }

                            if (count($importPreview) < $importPreviewMax) {
                                $importPreview[] = [
                                    'line' => $lineNo,
                                    'id' => $idVal,
                                    'serial' => $serialVal,
                                    'raw' => (string)$rawValue,
                                    'parsed' => ($rawValue === '' ? '(NULL)' : ($parsedValue === null ? '(INVALID)' : number_format($parsedValue, 2)))
                                ];
                            }

                            // invalid value: non-empty but not parseable
                            if (trim((string)$rawValue) !== '' && $parsedValue === null) {
                                $importStats['invalid_value']++;
                                continue;
                            }

                            if ($apply) {
                                // prioritize id if present
                                if ($idVal !== '') {
                                    $idInt = (int)$idVal;

                                    $stmtCheckId->bind_param("i", $idInt);
                                    $stmtCheckId->execute();
                                    $exists = $stmtCheckId->get_result()->fetch_assoc();
                                    if (!$exists) { $importStats['not_found']++; continue; }

                                    if ($rawValue === null || trim((string)$rawValue) === '') {
                                        $stmtByIdClear->bind_param("i", $idInt);
                                        $stmtByIdClear->execute();
                                        $importStats['cleared']++;
                                    } else {
                                        $stmtByIdSet->bind_param("di", $parsedValue, $idInt);
                                        $stmtByIdSet->execute();
                                        $importStats['updated']++;
                                    }
                                } else {
                                    $serial = $serialVal;

                                    $stmtCheckSerial->bind_param("s", $serial);
                                    $stmtCheckSerial->execute();
                                    $exists = $stmtCheckSerial->get_result()->fetch_assoc();
                                    if (!$exists) { $importStats['not_found']++; continue; }

                                    if ($rawValue === null || trim((string)$rawValue) === '') {
                                        $stmtBySerialClear->bind_param("s", $serial);
                                        $stmtBySerialClear->execute();
                                        $importStats['cleared']++;
                                    } else {
                                        $stmtBySerialSet->bind_param("ds", $parsedValue, $serial);
                                        $stmtBySerialSet->execute();
                                        $importStats['updated']++;
                                    }
                                }
                            }
                        }

                        fclose($fh);

                        if ($apply) {
                            $conn->commit();
                            $importFlash = "Import applied. Updated: {$importStats['updated']}, Cleared: {$importStats['cleared']}, Not found: {$importStats['not_found']}, Invalid: {$importStats['invalid_value']}.";
                            $importFlashType = 'success';
                        } else {
                            $importFlash = "Preview only. If it looks good, click Apply Import.";
                            $importFlashType = 'info';
                        }
                    }
                }
            }
        }
    }
}

/* -----------------------------------------------------------
   BASE QUERY WITH JOINS (product_ids + defect_codes + locations)
----------------------------------------------------------- */
$sqlBase = "
SELECT 
    di.id,
    di.serial_number,
    p.product_id AS product_name,
    di.category,
    di.grade,
    dc.defect_code AS defect_name,
    l.location_name AS bin_location,
    di.status,
    di.invoice_number,
    di.value,
    di.date_added
FROM defectives_inventory di
LEFT JOIN product_ids   p  ON di.product_id     = p.id
LEFT JOIN defect_codes  dc ON di.defect_code_id = dc.id
LEFT JOIN locations     l  ON di.location_id    = l.id
WHERE 1=1
";

$params = [];
$types  = "";

/* STATUS */
if ($selected_status !== "") {
    $sqlBase .= " AND di.status = ? ";
    $params[] = $selected_status;
    $types   .= "s";
}

/* CATEGORY */
if ($selected_category !== "") {
    $sqlBase .= " AND di.category = ? ";
    $params[] = $selected_category;
    $types   .= "s";
}

/* BIN (location_id) */
if ($selected_bin_id !== "") {
    $sqlBase .= " AND di.location_id = ? ";
    $params[] = (int)$selected_bin_id;
    $types   .= "i";
}

/* GRADE */
if ($selected_grade !== "") {
    $sqlBase .= " AND di.grade = ? ";
    $params[] = $selected_grade;
    $types   .= "s";
}

/* DEFECT CODE ID */
if ($selected_defect_code !== "") {
    $sqlBase .= " AND di.defect_code_id = ? ";
    $params[] = (int)$selected_defect_code;
    $types   .= "i";
}

/* DATE RANGE */
if ($start_date !== "" && $end_date !== "") {
    $sqlBase .= " AND DATE(di.date_added) BETWEEN ? AND ? ";
    $params[] = $start_date;
    $params[] = $end_date;
    $types   .= "ss";
}

/* -----------------------------------------------------------
   EXPORT CSV (BEFORE ANY HTML)
----------------------------------------------------------- */
if (isset($_GET['export']) && $_GET['export'] == "1") {

    $sqlExport = $sqlBase . " ORDER BY di.date_added DESC";
    $stmt = $conn->prepare($sqlExport);

    if (!empty($params)) {
        $stmt->bind_param($types, ...$params);
    }

    $stmt->execute();
    $result = $stmt->get_result();

    header('Content-Type: text/csv');
    header('Content-Disposition: attachment; filename="defectives_export.csv"');

    $output = fopen("php://output", "w");

    // CSV Headers (include Value)
    fputcsv($output, [
        "ID",
        "Serial Number",
        "Product",
        "Category",
        "Grade",
        "Defect Code",
        "BIN",
        "Status",
        "Invoice Number",
        "Value",
        "Date Added"
    ]);

    while ($row = $result->fetch_assoc()) {
        fputcsv($output, [
            $row["id"],
            (string)($row["serial_number"]  ?? ''),
            (string)($row["product_name"]   ?? ''),
            (string)($row["category"]       ?? ''),
            (string)($row["grade"]          ?? ''),
            (string)($row["defect_name"]    ?? ''),
            (string)($row["bin_location"]   ?? ''),
            (string)($row["status"]         ?? ''),
            (string)($row["invoice_number"] ?? ''),
            (string)($row["value"]          ?? ''),
            (string)($row["date_added"]     ?? '')
        ]);
    }

    fclose($output);
    exit;
}

/* -----------------------------------------------------------
   Load options for filters
----------------------------------------------------------- */
function fetchColumn($conn, $sql) {
    $arr = [];
    $res = $conn->query($sql);
    while ($row = $res->fetch_assoc()) {
        $arr[] = array_values($row)[0];
    }
    return $arr;
}

$statuses   = fetchColumn($conn, "SELECT DISTINCT status   FROM defectives_inventory ORDER BY status ASC");
$categories = fetchColumn($conn, "SELECT DISTINCT category FROM defectives_inventory ORDER BY category ASC");
$grades     = fetchColumn($conn, "SELECT DISTINCT grade    FROM defectives_inventory ORDER BY grade ASC");

// BINS list as (id + name) so filter is clean by location_id
$bins = [];
$resBins = $conn->query("SELECT id, location_name FROM locations ORDER BY location_name ASC");
while ($row = $resBins->fetch_assoc()) {
    $bins[] = $row;
}

// Defect codes (id + defect_code)
$defectCodes = [];
$res = $conn->query("SELECT id, defect_code FROM defect_codes ORDER BY defect_code ASC");
while ($row = $res->fetch_assoc()) {
    $defectCodes[] = $row;
}

/* -----------------------------------------------------------
   PREVIEW (first 200)
----------------------------------------------------------- */
$sqlPreview = $sqlBase . " ORDER BY di.date_added DESC LIMIT 200";
$stmtPrev = $conn->prepare($sqlPreview);
if (!empty($params)) {
    $stmtPrev->bind_param($types, ...$params);
}
$stmtPrev->execute();
$preview = $stmtPrev->get_result();

include '../includes/header.php';
?>

<h2 class="mb-4">Export Inventory</h2>

<?php if ($importFlash): ?>
    <div class="alert alert-<?= h($importFlashType) ?>"><?= h($importFlash) ?></div>
<?php endif; ?>

<!-- EXPORT FILTERS -->
<div class="card mb-4">
    <div class="card-body">
        <form method="GET" class="row g-3">

            <div class="col-md-3">
                <label class="form-label">Status</label>
                <select name="status" class="form-select">
                    <option value="">All</option>
                    <?php foreach ($statuses as $s): ?>
                        <option value="<?= h($s) ?>" <?= $selected_status === $s ? 'selected' : '' ?>>
                            <?= h($s) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div class="col-md-3">
                <label class="form-label">Category</label>
                <select name="category" class="form-select">
                    <option value="">All</option>
                    <?php foreach ($categories as $c): ?>
                        <option value="<?= h($c) ?>" <?= $selected_category === $c ? 'selected' : '' ?>>
                            <?= h($c) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div class="col-md-3">
                <label class="form-label">BIN</label>
                <select name="bin_id" class="form-select">
                    <option value="">All</option>
                    <?php foreach ($bins as $b): ?>
                        <option value="<?= (int)$b['id'] ?>" <?= (string)$selected_bin_id === (string)$b['id'] ? 'selected' : '' ?>>
                            <?= h($b['location_name']) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div class="col-md-3">
                <label class="form-label">Grade</label>
                <select name="grade" class="form-select">
                    <option value="">All</option>
                    <?php foreach ($grades as $g): ?>
                        <option value="<?= h($g) ?>" <?= $selected_grade === $g ? 'selected' : '' ?>>
                            <?= h($g) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div class="col-md-3">
                <label class="form-label">Defect Code</label>
                <select name="defect_code" class="form-select">
                    <option value="">All</option>
                    <?php foreach ($defectCodes as $dc): ?>
                        <option value="<?= (int)$dc['id'] ?>" <?= (string)$selected_defect_code === (string)$dc['id'] ? 'selected' : '' ?>>
                            <?= h($dc['defect_code']) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div class="col-md-3">
                <label class="form-label">Start Date</label>
                <input type="date" name="start_date" class="form-control" value="<?= h($start_date) ?>">
            </div>

            <div class="col-md-3">
                <label class="form-label">End Date</label>
                <input type="date" name="end_date" class="form-control" value="<?= h($end_date) ?>">
            </div>

            <div class="col-md-3 d-flex align-items-end gap-2">
                <button type="submit" class="btn btn-outline-primary w-100">
                    Apply Filters
                </button>
                <button type="submit" name="export" value="1" class="btn btn-success w-100">
                    Export CSV
                </button>
            </div>

        </form>

        <div class="mt-2 small text-muted">
            Preview shows first 200 results. Export includes <strong>Value</strong>.
        </div>
    </div>
</div>

<!-- IMPORT VALUE -->
<div class="card mb-4">
    <div class="card-header bg-white">
        <strong>Import Value (CSV)</strong>
        <div class="small text-muted">Upload the same export file after adding/updating the <code>Value</code> column in Excel.</div>
    </div>
    <div class="card-body">
        <?php if (!$canImportValue): ?>
            <div class="alert alert-warning mb-0">
                Read-only. Role: <strong><?= h($currentRole) ?></strong>
            </div>
        <?php else: ?>
            <form method="POST" enctype="multipart/form-data" class="row g-2 align-items-end">
                <input type="hidden" name="action" value="import_value">

                <div class="col-12 col-md-6">
                    <label class="form-label mb-1">CSV file</label>
                    <input type="file" name="csv_file" accept=".csv,text/csv" class="form-control" required>
                    <div class="form-text">
                        Required columns: <code>ID</code> (or <code>Serial Number</code>) + <code>Value</code>.
                        Empty Value clears it (NULL).
                    </div>
                </div>

                <div class="col-6 col-md-3">
                    <button class="btn btn-primary w-100" type="submit" name="apply" value="0">
                        Preview Import
                    </button>
                </div>

                <div class="col-6 col-md-3">
                    <button class="btn btn-success w-100" type="submit" name="apply" value="1"
                            onclick="return confirm('Apply import? This will update the database.');">
                        Apply Import
                    </button>
                </div>
            </form>

            <?php if (!empty($importPreview)): ?>
                <hr>
                <div class="d-flex justify-content-between align-items-center mb-2">
                    <strong>Import Preview (first <?= (int)count($importPreview) ?> rows)</strong>
                    <span class="small text-muted">
                        Rows read: <?= (int)$importStats['rows_total'] ?> |
                        Invalid: <?= (int)$importStats['invalid_value'] ?>
                    </span>
                </div>

                <div class="table-responsive">
                    <table class="table table-sm table-striped">
                        <thead class="table-dark">
                            <tr>
                                <th>Line</th>
                                <th>ID</th>
                                <th>Serial</th>
                                <th>Raw Value</th>
                                <th>Parsed</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($importPreview as $p): ?>
                                <tr>
                                    <td><?= (int)$p['line'] ?></td>
                                    <td><?= h($p['id']) ?></td>
                                    <td class="font-monospace"><?= h($p['serial']) ?></td>
                                    <td><?= h($p['raw']) ?></td>
                                    <td><?= h($p['parsed']) ?></td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>

                <div class="small text-muted">
                    Accepted formats: <code>45</code>, <code>45.5</code>, <code>45.50</code>, <code>1,234.56</code>.
                </div>
            <?php endif; ?>

        <?php endif; ?>
    </div>
</div>

<!-- PREVIEW TABLE -->
<div class="card">
    <div class="card-header bg-white">
        <strong>Preview (first 200 results)</strong>
    </div>

    <div class="card-body">
        <div class="table-responsive">

            <table class="table table-striped table-hover table-sm">
                <thead>
                    <tr>
                        <th>ID</th>
                        <th>Serial</th>
                        <th>Product</th>
                        <th>Category</th>
                        <th>Grade</th>
                        <th>Defect Code</th>
                        <th>BIN</th>
                        <th>Status</th>
                        <th>Invoice #</th>
                        <th>Value</th>
                        <th>Date Added</th>
                    </tr>
                </thead>

                <tbody>
                    <?php if ($preview->num_rows > 0): ?>
                        <?php while ($row = $preview->fetch_assoc()): ?>
                            <tr>
                                <td><?= (int)$row['id'] ?></td>
                                <td><?= h($row['serial_number']) ?></td>
                                <td><?= h($row['product_name']) ?></td>
                                <td><?= h($row['category']) ?></td>
                                <td><?= h($row['grade']) ?></td>
                                <td><?= h($row['defect_name']) ?></td>
                                <td><?= h($row['bin_location']) ?></td>
                                <td><?= h($row['status']) ?></td>
                                <td><?= h($row['invoice_number']) ?></td>
                                <td><?= h($row['value']) ?></td>
                                <td><?= h($row['date_added']) ?></td>
                            </tr>
                        <?php endwhile; ?>
                    <?php else: ?>
                        <tr><td colspan="11" class="text-center">No results found</td></tr>
                    <?php endif; ?>
                </tbody>
            </table>

        </div>
    </div>
</div>

<?php include '../includes/footer.php'; ?>
