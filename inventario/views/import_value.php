<?php
// /views/import_value.php
// Upload CSV (Excel) and update defectives_inventory.value
// Matches by ID if present, otherwise by serial_number.
//
// Required columns:
//  - value
//  - and (id OR serial_number / Serial No variations)
// Empty value clears it (sets NULL)

ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);
mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

require_once '../includes/auth.php';
require_once '../includes/db_connection.php';

function h($v): string {
  return $v === null ? '' : htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
}

$currentRole = $_SESSION['role'] ?? 'viewer';
$canImport = in_array($currentRole, ['admin','supervisor','inventory','inventory_manager'], true);

function normalizeHeader(string $s): string {
  $s = trim($s);
  $s = strtolower($s);
  $s = preg_replace('/[^a-z0-9_]+/', '_', $s);
  $s = trim($s, '_');
  return $s;
}

function parseMoney($v): ?float {
  if ($v === null) return null;
  $v = trim((string)$v);
  if ($v === '') return null;

  $v = str_replace([',', '$', ' '], '', $v);
  if (!preg_match('/^\d+(\.\d{1,2})?$/', $v)) return null;
  return (float)$v;
}

$flash = '';
$flashType = 'success';

$preview = [];
$previewMax = 8;

$stats = [
  'rows_total' => 0,
  'rows_skipped' => 0,
  'updated' => 0,
  'cleared' => 0,
  'not_found' => 0,
  'invalid_value' => 0,
];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  if (!$canImport) {
    http_response_code(403);
    $flash = "You don't have permission to import values.";
    $flashType = 'danger';
  } else {
    if (!isset($_FILES['csv_file']) || $_FILES['csv_file']['error'] !== UPLOAD_ERR_OK) {
      $flash = "Upload failed. Please select a CSV file.";
      $flashType = 'danger';
    } else {
      $tmpPath = $_FILES['csv_file']['tmp_name'];
      $apply = ($_POST['apply'] ?? '') === '1';

      $fh = fopen($tmpPath, 'r');
      if (!$fh) {
        $flash = "Could not read uploaded file.";
        $flashType = 'danger';
      } else {
        $rawHeaders = fgetcsv($fh);
        if (!$rawHeaders) {
          $flash = "CSV is empty or invalid.";
          $flashType = 'danger';
          fclose($fh);
        } else {
          $headers = array_map(fn($x) => normalizeHeader((string)$x), $rawHeaders);
          $idx = array_flip($headers);

          // Required: value
          $hasValue = isset($idx['value']);
          $valueKey = 'value';

          // id OR serial_number (accept some common variants)
          $hasId = isset($idx['id']);

          $serialKey = '';
          foreach (['serial_number','serial_no','serialno','serial'] as $k) {
            if (isset($idx[$k])) { $serialKey = $k; break; }
          }
          $hasSerial = ($serialKey !== '');

          if (!$hasValue) {
            $flash = "Missing required column: value.";
            $flashType = 'danger';
            fclose($fh);
          } elseif (!$hasId && !$hasSerial) {
            $flash = "CSV must include identifier column: id or serial_number.";
            $flashType = 'danger';
            fclose($fh);
          } else {

            // Prepare update statements
            $stmtByIdSet   = $conn->prepare("UPDATE defectives_inventory SET value = ?, last_updated = NOW() WHERE id = ? LIMIT 1");
            $stmtByIdClear = $conn->prepare("UPDATE defectives_inventory SET value = NULL, last_updated = NOW() WHERE id = ? LIMIT 1");

            $stmtBySerialSet   = $conn->prepare("UPDATE defectives_inventory SET value = ?, last_updated = NOW() WHERE serial_number = ? LIMIT 1");
            $stmtBySerialClear = $conn->prepare("UPDATE defectives_inventory SET value = NULL, last_updated = NOW() WHERE serial_number = ? LIMIT 1");

            // Existence checks (for better stats)
            $stmtCheckId     = $conn->prepare("SELECT id FROM defectives_inventory WHERE id = ? LIMIT 1");
            $stmtCheckSerial = $conn->prepare("SELECT id FROM defectives_inventory WHERE serial_number = ? LIMIT 1");

            if ($apply) $conn->begin_transaction();

            $lineNo = 1; // header line
            while (($row = fgetcsv($fh)) !== false) {
              $lineNo++;
              $stats['rows_total']++;

              $get = function(string $key) use ($idx, $row) {
                $i = $idx[$key] ?? null;
                if ($i === null) return null;
                return array_key_exists($i, $row) ? $row[$i] : null;
              };

              $rawValue = $get($valueKey);
              $value = parseMoney($rawValue);

              $idVal = $hasId ? trim((string)$get('id')) : '';
              $serialVal = $hasSerial ? trim((string)$get($serialKey)) : '';

              if ($idVal === '' && $serialVal === '') {
                $stats['rows_skipped']++;
                continue;
              }

              if (count($preview) < $previewMax) {
                $preview[] = [
                  'line' => $lineNo,
                  'id' => $idVal,
                  'serial' => $serialVal,
                  'value_raw' => (string)$rawValue,
                  'parsed' => ($rawValue === '' ? '(NULL)' : ($value === null ? '(INVALID)' : number_format($value, 2))),
                ];
              }

              // invalid value: non-empty but not parseable
              if (trim((string)$rawValue) !== '' && $value === null) {
                $stats['invalid_value']++;
                continue;
              }

              if ($apply) {
                $did = false;

                if ($idVal !== '') {
                  $idInt = (int)$idVal;

                  $stmtCheckId->bind_param("i", $idInt);
                  $stmtCheckId->execute();
                  $exists = $stmtCheckId->get_result()->fetch_assoc();
                  if (!$exists) { $stats['not_found']++; continue; }

                  if ($rawValue === null || trim((string)$rawValue) === '') {
                    $stmtByIdClear->bind_param("i", $idInt);
                    $stmtByIdClear->execute();
                    $stats['cleared']++;
                    $did = true;
                  } else {
                    $stmtByIdSet->bind_param("di", $value, $idInt);
                    $stmtByIdSet->execute();
                    $stats['updated']++;
                    $did = true;
                  }
                } else {
                  $serial = $serialVal;

                  $stmtCheckSerial->bind_param("s", $serial);
                  $stmtCheckSerial->execute();
                  $exists = $stmtCheckSerial->get_result()->fetch_assoc();
                  if (!$exists) { $stats['not_found']++; continue; }

                  if ($rawValue === null || trim((string)$rawValue) === '') {
                    $stmtBySerialClear->bind_param("s", $serial);
                    $stmtBySerialClear->execute();
                    $stats['cleared']++;
                    $did = true;
                  } else {
                    $stmtBySerialSet->bind_param("ds", $value, $serial);
                    $stmtBySerialSet->execute();
                    $stats['updated']++;
                    $did = true;
                  }
                }

                if (!$did) $stats['rows_skipped']++;
              }
            }

            fclose($fh);

            if ($apply) {
              $conn->commit();
              $flash = "Import applied. Updated: {$stats['updated']}, Cleared: {$stats['cleared']}, Not found: {$stats['not_found']}, Invalid rows: {$stats['invalid_value']}.";
              $flashType = 'success';
            } else {
              $flash = "Preview only. If it looks good, click 'Apply Import'.";
              $flashType = 'info';
            }
          }
        }
      }
    }
  }
}

require_once '../includes/header.php';
?>

<div class="container mt-3">
  <div class="d-flex justify-content-between align-items-center mb-2">
    <div>
      <h4 class="mb-0">Import Value (CSV)</h4>
      <div class="text-muted small">Excel → Save As CSV → upload. Updates <code>defectives_inventory.value</code>.</div>
    </div>
    <a class="btn btn-outline-secondary btn-sm" href="inventory_value_manager.php">Back</a>
  </div>

  <?php if ($flash): ?>
    <div class="alert alert-<?= h($flashType) ?>"><?= h($flash) ?></div>
  <?php endif; ?>

  <div class="card shadow-sm mb-3">
    <div class="card-body">
      <?php if (!$canImport): ?>
        <div class="alert alert-warning mb-0">
          Read-only. Role: <strong><?= h($currentRole) ?></strong>
        </div>
      <?php else: ?>
        <form method="post" enctype="multipart/form-data" class="row g-2 align-items-end">
          <div class="col-12 col-md-6">
            <label class="form-label mb-1">CSV file</label>
            <input type="file" name="csv_file" accept=".csv,text/csv" class="form-control" required>
            <div class="form-text">
              Required: <code>value</code> + (<code>id</code> or <code>serial_number</code>)
            </div>
          </div>

          <div class="col-6 col-md-3">
            <button class="btn btn-primary w-100" type="submit" name="apply" value="0">Preview Import</button>
          </div>

          <div class="col-6 col-md-3">
            <button class="btn btn-success w-100" type="submit" name="apply" value="1"
              onclick="return confirm('Apply import? This will update the database.');">
              Apply Import
            </button>
          </div>
        </form>
      <?php endif; ?>
    </div>
  </div>

  <?php if (!empty($preview)): ?>
    <div class="card shadow-sm">
      <div class="card-header d-flex justify-content-between align-items-center">
        <strong>Preview (first <?= (int)count($preview) ?> rows)</strong>
        <span class="text-muted small">
          Total rows read: <?= (int)$stats['rows_total'] ?> | Invalid rows: <?= (int)$stats['invalid_value'] ?>
        </span>
      </div>

      <div class="table-responsive">
        <table class="table table-striped mb-0">
          <thead class="table-dark">
            <tr>
              <th>Line</th>
              <th>ID</th>
              <th>Serial</th>
              <th>Raw value</th>
              <th>Parsed</th>
            </tr>
          </thead>
          <tbody>
            <?php foreach ($preview as $p): ?>
              <tr>
                <td><?= (int)$p['line'] ?></td>
                <td><?= h($p['id']) ?></td>
                <td class="font-monospace"><?= h($p['serial']) ?></td>
                <td><?= h($p['value_raw']) ?></td>
                <td><?= h($p['parsed']) ?></td>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>

      <div class="card-body small text-muted">
        <ul class="mb-0">
          <li>Leave <code>value</code> blank to clear it (NULL).</li>
          <li>If both <code>id</code> and serial exist, it prioritizes <code>id</code>.</li>
          <li>Accepted formats: <code>45</code>, <code>45.5</code>, <code>45.50</code>, <code>1,234.56</code></li>
        </ul>
      </div>
    </div>
  <?php endif; ?>
</div>

<?php require_once '../includes/footer.php'; ?>

