<?php
// /inventario/views/import_paste.php
// Expected TSV paste columns (Excel/Sheets):
// Date | Model | Serial No | Defect | Status | Loss Of Value
//
// IMPORTANT (run once if you want to store Loss Of Value):
// ALTER TABLE defectives_inventory ADD COLUMN loss_of_value DECIMAL(10,3) NULL AFTER invoice_number;

ini_set('display_errors', 1);
error_reporting(E_ALL);
mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

require_once '../includes/auth.php';
require_once '../includes/db_connection.php';

function h($v): string { return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); }

$logged_role = $_SESSION['role'] ?? 'viewer';
$canImport = in_array($logged_role, ['admin', 'supervisor', 'inventory'], true);

if (!$canImport) {
    http_response_code(403);
    exit('No permission.');
}

$errors = [];
$rows = [];
$preview = false;
$imported = 0;
$skippedDuplicates = 0;

// Defaults applied to all rows in this paste
$defaultCategory = trim($_POST['default_category'] ?? 'tablet');
$defaultGrade    = trim($_POST['default_grade'] ?? 'D');
$defaultBin      = trim($_POST['default_bin'] ?? 'BIN1000');
$defaultInvoice  = trim($_POST['default_invoice'] ?? '');

// Status mapping allowed
$allowedProcessArea = ['REPAIR','RMA','EBAY','PARTS','SCRAP','RECEIVED'];

function parse_tsv(string $raw): array {
    $raw = trim(str_replace("\r\n", "\n", $raw));
    $lines = array_values(array_filter(array_map('trim', explode("\n", $raw)), fn($l)=>$l!==''));
    $out = [];
    foreach ($lines as $line) {
        $cols = preg_split("/\t+/", $line);
        $out[] = array_map('trim', $cols);
    }
    return $out;
}

function normalize_date(?string $s): ?string {
    if (!$s) return null;
    $s = trim($s);

    if (preg_match('/^(\d{1,2})\/(\d{1,2})\/(\d{4})$/', $s, $m)) {
        $mm = str_pad($m[1], 2, '0', STR_PAD_LEFT);
        $dd = str_pad($m[2], 2, '0', STR_PAD_LEFT);
        return "{$m[3]}-{$mm}-{$dd}";
    }

    if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $s)) return $s;

    return null;
}

function get_location_id_by_name(mysqli $conn, string $locName): ?int {
    $stmt = $conn->prepare("SELECT id FROM locations WHERE location_name = ? LIMIT 1");
    $stmt->bind_param("s", $locName);
    $stmt->execute();
    $res = $stmt->get_result();
    $id = null;
    if ($row = $res->fetch_assoc()) $id = (int)$row['id'];
    $stmt->close();
    return $id;
}

function get_or_create_product_id(mysqli $conn, string $productCode): int {
    $productCode = trim($productCode);

    $stmt = $conn->prepare("SELECT id FROM product_ids WHERE product_id = ? LIMIT 1");
    $stmt->bind_param("s", $productCode);
    $stmt->execute();
    $res = $stmt->get_result();
    if ($row = $res->fetch_assoc()) {
        $stmt->close();
        return (int)$row['id'];
    }
    $stmt->close();

    $ins = $conn->prepare("INSERT INTO product_ids (product_id, description) VALUES (?, NULL)");
    $ins->bind_param("s", $productCode);
    $ins->execute();
    $newId = (int)$ins->insert_id;
    $ins->close();
    return $newId;
}

function get_or_create_defect_id(mysqli $conn, string $defectCode): int {
    $defectCode = trim($defectCode);

    $stmt = $conn->prepare("SELECT id FROM defect_codes WHERE defect_code = ? LIMIT 1");
    $stmt->bind_param("s", $defectCode);
    $stmt->execute();
    $res = $stmt->get_result();
    if ($row = $res->fetch_assoc()) {
        $stmt->close();
        return (int)$row['id'];
    }
    $stmt->close();

    $ins = $conn->prepare("
        INSERT INTO defect_codes (main_category, sub_category, defect_code, defect_description, description)
        VALUES (NULL, NULL, ?, NULL, 'Imported from paste')
    ");
    $ins->bind_param("s", $defectCode);
    $ins->execute();
    $newId = (int)$ins->insert_id;
    $ins->close();
    return $newId;
}

function map_status_to_process_area(string $statusRaw): ?string {
    $st = strtoupper(trim($statusRaw));
    $map = [
        'REPAIR'   => 'REPAIR',
        'RMA'      => 'RMA',
        'EBAY'     => 'EBAY',
        'PARTS'    => 'PARTS',
        'SCRAP'    => 'SCRAP',
        'RECEIVED' => 'RECEIVED',
    ];
    return $map[$st] ?? null;
}

function parse_loss(?string $lossRaw, int $lineNo, array &$errors): ?float {
    if ($lossRaw === null) return null;
    $lossRaw = trim((string)$lossRaw);
    if ($lossRaw === '') return null;

    $lossClean = preg_replace('/[^0-9.\-]/', '', $lossRaw);
    if ($lossClean === '' || !is_numeric($lossClean)) {
        $errors[] = "Line {$lineNo}: Loss Of Value invalid '{$lossRaw}'.";
        return null;
    }
    return (float)$lossClean;
}

// Handle POST
$locId = null;
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? 'preview';
    $raw = $_POST['paste_data'] ?? '';

    // Validate defaults
    if ($defaultCategory === '') $errors[] = "Default Category is required.";
    if ($defaultGrade === '')    $errors[] = "Default Grade is required.";
    if ($defaultBin === '')      $errors[] = "Default BIN is required.";

    if ($defaultBin !== '') {
        $locId = get_location_id_by_name($conn, $defaultBin);
        if (!$locId) $errors[] = "Default BIN '{$defaultBin}' does not exist in locations.";
    }

    if (trim($raw) === '') $errors[] = "Paste is empty.";

    if (empty($errors)) {
        $parsed = parse_tsv($raw);

        // Header detection
        $first = $parsed[0] ?? [];
        $looksHeader = isset($first[0]) && stripos($first[0], 'date') !== false;
        if ($looksHeader) array_shift($parsed);

        foreach ($parsed as $i => $c) {
            // 0 Date, 1 Model, 2 Serial No, 3 Defect, 4 Status, 5 Loss Of Value
            // 0 Date, 1 Model, 2 Serial No, 3 Defect, 4 Status, 5 Loss Of Value, 6 BIN (optional)
            $dateRaw   = $c[0] ?? '';
            $model     = $c[1] ?? '';
            $serial    = $c[2] ?? '';
            $defect    = $c[3] ?? '';
            $statusRaw = $c[4] ?? '';
            $lossRaw   = $c[5] ?? null;
            $binRaw    = $c[6] ?? null;

            $lineNo = $i + 1;

            // Date
            $date = normalize_date($dateRaw);
            if (!$date) $errors[] = "Line {$lineNo}: Invalid date '{$dateRaw}'.";

            // Required fields
            if ($model === '')  $errors[] = "Line {$lineNo}: Missing model.";
            if ($serial === '') $errors[] = "Line {$lineNo}: Missing serial.";
            if ($defect === '') $errors[] = "Line {$lineNo}: Missing defect.";
            if (trim($statusRaw) === '') $errors[] = "Line {$lineNo}: Missing status.";

            // Status → process_area
            $processArea = map_status_to_process_area($statusRaw);
            if (!$processArea) {
                $errors[] = "Line {$lineNo}: Status '{$statusRaw}' not allowed.";
            }

            // Loss Of Value
            $lossNum = parse_loss($lossRaw, $lineNo, $errors);

            // BIN logic (per-row override)
            $binFinal = $defaultBin;
            $locationIdFinal = $locId;

            if ($binRaw !== null && trim($binRaw) !== '') {
                $binCandidate = trim($binRaw);
                $locTmp = get_location_id_by_name($conn, $binCandidate);
                if (!$locTmp) {
                    $errors[] = "Line {$lineNo}: BIN '{$binCandidate}' does not exist in locations.";
                } else {
                    $binFinal = $binCandidate;
                    $locationIdFinal = $locTmp;
                }
            }

            $rows[] = [
                'date' => $date,
                'model' => $model,
                'serial_number' => $serial,
                'defect' => $defect,
                'process_area' => $processArea,
                'loss_of_value' => $lossNum,
                'category' => $defaultCategory,
                'grade' => $defaultGrade,
                'bin_location' => $binFinal,
                'location_id' => $locationIdFinal,
                'invoice_number' => ($defaultInvoice !== '' ? $defaultInvoice : null),
            ];

        }
    }

    if ($action === 'preview') $preview = true;

    if ($action === 'import' && empty($errors) && count($rows) > 0) {
        $conn->begin_transaction();
        try {
            $stmtInv = $conn->prepare("
                INSERT INTO defectives_inventory
                  (serial_number, product_id, category, grade, defect_code_id, status, process_area,
                   removal_status, removal_destination, removal_date,
                   bin_location, date_added, last_updated, invoice_number, loss_of_value, location_id)
                VALUES
                  (?, ?, ?, ?, ?, ?, ?, 'In Stock', NULL, NULL, ?, ?, NOW(), ?, ?, ?)
            ");

            $stmtLink = $conn->prepare("
                INSERT INTO inventory_defect_links (inventory_id, defect_code_id)
                VALUES (?, ?)
            ");

            foreach ($rows as $r) {
                $product_id = get_or_create_product_id($conn, $r['model']);
                $defect_id  = get_or_create_defect_id($conn, $r['defect']);

                $invStatus = ($r['process_area'] === 'REPAIR') ? 'In Repair' : 'In Stock';
                $dateAdded = $r['date'] . " 00:00:00";

                $invoice    = $r['invoice_number'];   // nullable
                $loss       = $r['loss_of_value'];    // nullable
                $locationId = (int)$r['location_id'];

                $stmtInv->bind_param(
                    "sississsssdi",
                    $r['serial_number'],
                    $product_id,
                    $r['category'],
                    $r['grade'],
                    $defect_id,
                    $invStatus,
                    $r['process_area'],
                    $r['bin_location'],
                    $dateAdded,
                    $invoice,
                    $loss,
                    $locationId
                );

                try {
                    $stmtInv->execute();
                    $newInvId = (int)$stmtInv->insert_id;

                    $stmtLink->bind_param("ii", $newInvId, $defect_id);
                    $stmtLink->execute();

                    $imported++;
                } catch (mysqli_sql_exception $e) {
                    if (str_contains($e->getMessage(), 'Duplicate entry')) {
                        $skippedDuplicates++;
                        continue;
                    }
                    throw $e;
                }
            }

            $stmtLink->close();
            $stmtInv->close();

            $conn->commit();
            $preview = false;
            $rows = [];
        } catch (mysqli_sql_exception $e) {
            $conn->rollback();
            $errors[] = "DB error: " . $e->getMessage();
        }
    }
}

require_once '../includes/header.php';
?>

<style>
    /* Small UI polish that matches your dark navbar + cyan accent */
    .cti-card {
        border: 0;
        border-radius: 14px;
        box-shadow: 0 8px 24px rgba(0,0,0,.06);
    }
    .cti-accent {
        border-left: 4px solid #4cc9f0;
    }
    .cti-label {
        font-size: 12px;
        letter-spacing: .3px;
        color: #6c757d;
        text-transform: uppercase;
        font-weight: 700;
    }
    .cti-help {
        font-size: 13px;
        color: #6c757d;
    }
    .cti-mono {
        font-family: ui-monospace, SFMono-Regular, Menlo, Monaco, Consolas, "Liberation Mono", "Courier New", monospace;
        font-size: 12px;
    }
    .cti-table th {
        white-space: nowrap;
    }
    .sticky-actions {
        position: sticky;
        bottom: 12px;
        z-index: 10;
    }
</style>

<div class="d-flex align-items-center justify-content-between mb-3">
    <div>
        <h3 class="mb-1"><i class="bi bi-clipboard-plus"></i> Import by Paste</h3>
        <div class="cti-help">
            Paste rows directly from Excel/Google Sheets and import them into <span class="cti-mono">defectives_inventory</span>.
        </div>
    </div>
    <div class="text-end">
        <span class="badge text-bg-dark"><i class="bi bi-shield-lock"></i> Role: <?= h($logged_role) ?></span>
    </div>
</div>

<?php if ($imported > 0 || $skippedDuplicates > 0): ?>
    <div class="alert alert-success cti-card cti-accent">
        <div class="d-flex align-items-start gap-3">
            <div class="fs-4"><i class="bi bi-check-circle-fill"></i></div>
            <div>
                <div class="fw-bold">Import completed</div>
                <div>
                    Imported: <b><?= (int)$imported ?></b> |
                    Duplicates skipped: <b><?= (int)$skippedDuplicates ?></b>
                </div>
                <div class="cti-help mt-1">
                    Duplicates are skipped when <span class="cti-mono">serial_number</span> already exists.
                </div>
            </div>
        </div>
    </div>
<?php endif; ?>

<?php if (!empty($errors)): ?>
    <div class="alert alert-danger cti-card cti-accent">
        <div class="d-flex align-items-start gap-3">
            <div class="fs-4"><i class="bi bi-exclamation-triangle-fill"></i></div>
            <div class="w-100">
                <div class="fw-bold mb-1">Fix these issues</div>
                <ul class="mb-0">
                    <?php foreach ($errors as $e): ?>
                        <li><?= h($e) ?></li>
                    <?php endforeach; ?>
                </ul>
            </div>
        </div>
    </div>
<?php endif; ?>

<div class="row g-3">

    <div class="col-lg-5">
        <div class="card cti-card">
            <div class="card-body">
                <div class="d-flex align-items-center justify-content-between mb-2">
                    <div>
                        <div class="cti-label">Paste format</div>
                        <div class="fw-semibold">Excel/Sheets (TSV)</div>
                    </div>
                    <span class="badge text-bg-info"><i class="bi bi-info-circle"></i> 6 columns</span>
                </div>

                <div class="cti-help mb-3">
                Columns:
                        <div class="mt-2 cti-mono">
                            Date | Model | Serial No | Defect | Status | Loss Of Value | BIN (optional)
                        </div>
                        <div class="mt-1">
                            If BIN is empty or not provided, the <b>Default BIN</b> will be used.
                        </div>
                    </div>


                <div class="alert alert-warning mb-3">
                    <div class="fw-semibold"><i class="bi bi-lightning-charge"></i> Status mapping</div>
                    <div class="cti-help">
                        “Repair” → <b>REPAIR</b>, “RMA” → <b>RMA</b>, “eBay” → <b>EBAY</b>, “Parts” → <b>PARTS</b>, “Scrap” → <b>SCRAP</b>, “Received” → <b>RECEIVED</b>.
                    </div>
                </div>

                <div class="cti-help">
                    <i class="bi bi-database"></i>
                    If you want to save <b>Loss Of Value</b>, add the column once:
                    <div class="mt-2 cti-mono">
                        ALTER TABLE defectives_inventory
                        ADD COLUMN loss_of_value DECIMAL(10,3) NULL AFTER invoice_number;
                    </div>
                </div>
            </div>
        </div>

        <div class="card cti-card mt-3">
            <div class="card-body">
                <div class="cti-label mb-2">Defaults for this paste</div>

                <form method="post" id="pasteForm">
                    <div class="mb-3">
                        <label class="form-label fw-semibold">Default Category</label>
                        <input class="form-control" name="default_category" value="<?= h($defaultCategory) ?>" placeholder="tablet / ipad / macbook / surface">
                    </div>

                    <div class="mb-3">
                        <label class="form-label fw-semibold">Default Grade</label>
                        <input class="form-control" name="default_grade" value="<?= h($defaultGrade) ?>" placeholder="A/B/C/D">
                    </div>

                    <div class="mb-3">
                        <label class="form-label fw-semibold">Default BIN (must exist in locations)</label>
                        <input class="form-control" name="default_bin" value="<?= h($defaultBin) ?>" placeholder="BIN1000">
                        <div class="cti-help mt-1">This sets both <span class="cti-mono">bin_location</span> and <span class="cti-mono">location_id</span>.</div>
                    </div>

                    <div class="mb-3">
                        <label class="form-label fw-semibold">Invoice (optional)</label>
                        <input class="form-control" name="default_invoice" value="<?= h($defaultInvoice) ?>" placeholder="RETURNS / invoice #">
                    </div>

                    <div class="cti-label mb-2">Paste data</div>
                    <textarea class="form-control cti-mono" name="paste_data" rows="10"
                              placeholder="Paste from Excel/Sheets..."><?= h($_POST['paste_data'] ?? '') ?></textarea>

                    <div class="sticky-actions mt-3">
                        <div class="d-flex gap-2">
                            <button class="btn btn-outline-primary w-100" type="submit" name="action" value="preview">
                                <i class="bi bi-eye"></i> Preview
                            </button>

                            <?php if ($preview && empty($errors) && count($rows) > 0): ?>
                                <button class="btn btn-primary w-100" type="submit" name="action" value="import"
                                        onclick="return confirm('Import <?= count($rows) ?> rows? Duplicates will be skipped.');">
                                    <i class="bi bi-cloud-upload"></i> Import
                                </button>
                            <?php else: ?>
                                <button class="btn btn-primary w-100" type="button" disabled>
                                    <i class="bi bi-cloud-upload"></i> Import
                                </button>
                            <?php endif; ?>
                        </div>

                        <div class="cti-help mt-2">
                            Preview first. Import button unlocks only when there are no validation errors.
                        </div>
                    </div>

                </form>
            </div>
        </div>
    </div>

    <div class="col-lg-7">
        <div class="card cti-card">
            <div class="card-body">
                <div class="d-flex align-items-center justify-content-between">
                    <div>
                        <div class="cti-label">Preview</div>
                        <div class="fw-semibold">
                            <?= $preview ? (count($rows) . " rows ready") : "Nothing to preview yet" ?>
                        </div>
                    </div>

                    <?php if ($preview && count($rows) > 0 && empty($errors)): ?>
                        <span class="badge text-bg-success"><i class="bi bi-check2-circle"></i> Valid</span>
                    <?php elseif ($preview && !empty($errors)): ?>
                        <span class="badge text-bg-danger"><i class="bi bi-x-circle"></i> Fix errors</span>
                    <?php else: ?>
                        <span class="badge text-bg-secondary"><i class="bi bi-hourglass-split"></i> Pending</span>
                    <?php endif; ?>
                </div>

                <hr>

                <?php if (!$preview): ?>
                    <div class="text-center py-5">
                        <div class="fs-1 text-secondary"><i class="bi bi-clipboard-data"></i></div>
                        <div class="fw-semibold mt-2">Paste data and click Preview</div>
                        <div class="cti-help">You’ll see a clean table here before importing.</div>
                    </div>
                <?php elseif ($preview && count($rows) === 0): ?>
                    <div class="alert alert-secondary mb-0">No rows found.</div>
                <?php else: ?>

                    <div class="table-responsive">
                        <table class="table table-sm table-hover align-middle cti-table">
                            <thead class="table-light">
                                <tr>
                                    <th>Date</th>
                                    <th>Model</th>
                                    <th>Serial</th>
                                    <th>Defect</th>
                                    <th>Status</th>
                                    <th class="text-end">Loss</th>
                                    <th>Category</th>
                                    <th>Grade</th>
                                    <th>BIN</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($rows as $r): ?>
                                    <tr>
                                        <td><?= h($r['date']) ?></td>
                                        <td><span class="badge text-bg-dark"><?= h($r['model']) ?></span></td>
                                        <td class="cti-mono"><?= h($r['serial_number']) ?></td>
                                        <td><?= h($r['defect']) ?></td>
                                        <td>
                                            <?php
                                                $pa = $r['process_area'];
                                                $badge = 'secondary';
                                                if ($pa === 'REPAIR') $badge = 'primary';
                                                elseif ($pa === 'RMA') $badge = 'warning';
                                                elseif ($pa === 'EBAY') $badge = 'info';
                                                elseif ($pa === 'PARTS') $badge = 'dark';
                                                elseif ($pa === 'SCRAP') $badge = 'danger';
                                                elseif ($pa === 'RECEIVED') $badge = 'success';
                                            ?>
                                            <span class="badge text-bg-<?= $badge ?>"><?= h($pa) ?></span>
                                        </td>
                                        <td class="text-end"><?= h($r['loss_of_value'] ?? '') ?></td>
                                        <td><?= h($r['category']) ?></td>
                                        <td><span class="badge text-bg-light text-dark"><?= h($r['grade']) ?></span></td>
                                        <td class="cti-mono"><?= h($r['bin_location']) ?></td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>

                    <div class="cti-help mt-2">
                        Import will: create missing <span class="cti-mono">product_ids</span> and <span class="cti-mono">defect_codes</span> automatically, skip duplicate serials, and create the link in <span class="cti-mono">inventory_defect_links</span>.
                    </div>

                <?php endif; ?>
            </div>
        </div>
    </div>

</div>

<?php
require_once '../includes/footer.php';
