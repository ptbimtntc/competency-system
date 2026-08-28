<?php
require_once "auth.php";
require_once "../config/database.php";
$allowedTeams = ['A', 'B', 'C', 'D', 'NS'];
$results = null;
$error = "";
/*
|--------------------------------------------------------------------------
| Download template CSV
|--------------------------------------------------------------------------
*/
if (isset($_GET['template'])) {
    header("Content-Type: text/csv; charset=utf-8");
    header("Content-Disposition: attachment; filename=employees_import_template.csv");
    $templateOutput = fopen('php://output', 'w');
    fputs($templateOutput, "\xEF\xBB\xBF");
    fputcsv($templateOutput, ['nik', 'name', 'department', 'position', 'supervisor', 'team']);
    fputcsv($templateOutput, ['12345', 'Budi Santoso', 'Maintenance', 'Technician', 'Andi Wijaya', 'A']);
    fclose($templateOutput);
    exit;
}
/*
|--------------------------------------------------------------------------
| Proses upload CSV
|--------------------------------------------------------------------------
|
| Upsert berdasarkan NIK: kalau NIK sudah ada, data karyawan diupdate.
| Kalau belum ada, dibuat baris baru. License ID selalu digenerate ulang
| otomatis (BKT-<NIK>) supaya konsisten dengan halaman Add Employee.
|
*/
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_validate();
    require_writer();
    if (!isset($_FILES['csv_file']) || $_FILES['csv_file']['error'] !== UPLOAD_ERR_OK) {
        $error = "File CSV wajib diupload.";
    } else {
        $extension = strtolower(pathinfo($_FILES['csv_file']['name'], PATHINFO_EXTENSION));
        if ($extension !== 'csv') {
            $error = "File harus berformat .csv.";
        } else {
            $handle = fopen($_FILES['csv_file']['tmp_name'], 'r');
            if (!$handle) {
                $error = "File CSV gagal dibaca.";
            } else {
                $bom = fread($handle, 3);
                if ($bom !== "\xEF\xBB\xBF") {
                    rewind($handle);
                }
                $header = fgetcsv($handle);
                if ($header === false) {
                    $error = "File CSV kosong.";
                } else {
                    $header = array_map(function ($col) {
                        return strtolower(trim((string) $col));
                    }, $header);
                    $columnIndex = array_flip($header);
                    $requiredColumns = ['nik', 'name', 'department', 'position'];
                    $missingColumns = array_diff($requiredColumns, array_keys($columnIndex));
                    if (count($missingColumns) > 0) {
                        $error = "Kolom wajib tidak ditemukan di header CSV: " . implode(', ', $missingColumns);
                    } else {
                        $created = 0;
                        $updated = 0;
                        $skipped = [];
                        $rowNumber = 1;
                        mysqli_begin_transaction($conn);
                        try {
                            while (($row = fgetcsv($handle)) !== false) {
                                $rowNumber++;
                                $isEmptyRow = true;
                                foreach ($row as $cell) {
                                    if (trim((string) $cell) !== '') {
                                        $isEmptyRow = false;
                                        break;
                                    }
                                }
                                if ($isEmptyRow) {
                                    continue;
                                }
                                $getColumn = function (string $col) use ($row, $columnIndex): string {
                                    return isset($columnIndex[$col]) && isset($row[$columnIndex[$col]])
                                        ? trim((string) $row[$columnIndex[$col]])
                                        : '';
                                };
                                $nik = $getColumn('nik');
                                $name = $getColumn('name');
                                $department = $getColumn('department');
                                $position = $getColumn('position');
                                $supervisor = $getColumn('supervisor');
                                $team = strtoupper($getColumn('team'));

                                if ($nik === '' || $name === '' || $department === '' || $position === '') {
                                    $skipped[] = "Baris {$rowNumber}: NIK, name, department, dan position wajib diisi.";
                                    continue;
                                }
                                if ($team !== '' && !in_array($team, $allowedTeams, true)) {
                                    $skipped[] = "Baris {$rowNumber}: Team '{$team}' tidak valid (harus A/B/C/D/NS).";
                                    continue;
                                }
                                $teamValue = $team !== '' ? $team : null;
                                $supervisorValue = $supervisor !== '' ? $supervisor : null;
                                $licenseId = 'BKT-' . $nik;

                                $checkStmt = mysqli_prepare($conn, "SELECT id FROM employees WHERE nik = ? LIMIT 1");
                                mysqli_stmt_bind_param($checkStmt, "s", $nik);
                                mysqli_stmt_execute($checkStmt);
                                $existing = mysqli_fetch_assoc(mysqli_stmt_get_result($checkStmt));

                                if ($existing) {
                                    $updateStmt = mysqli_prepare(
                                        $conn,
                                        "UPDATE employees
                                        SET name = ?, department = ?, position = ?, supervisor = ?, team = ?, license_id = ?,
                                            is_deleted = 0, deleted_at = NULL
                                        WHERE id = ?"
                                    );
                                    mysqli_stmt_bind_param(
                                        $updateStmt,
                                        "ssssssi",
                                        $name,
                                        $department,
                                        $position,
                                        $supervisorValue,
                                        $teamValue,
                                        $licenseId,
                                        $existing['id']
                                    );
                                    mysqli_stmt_execute($updateStmt);
                                    $updated++;
                                } else {
                                    $insertStmt = mysqli_prepare(
                                        $conn,
                                        "INSERT INTO employees (nik, name, department, position, supervisor, team, license_id)
                                        VALUES (?, ?, ?, ?, ?, ?, ?)"
                                    );
                                    mysqli_stmt_bind_param(
                                        $insertStmt,
                                        "sssssss",
                                        $nik,
                                        $name,
                                        $department,
                                        $position,
                                        $supervisorValue,
                                        $teamValue,
                                        $licenseId
                                    );
                                    mysqli_stmt_execute($insertStmt);
                                    $created++;
                                }
                            }
                            mysqli_commit($conn);
                            $results = [
                                'created' => $created,
                                'updated' => $updated,
                                'skipped' => $skipped,
                            ];
                        } catch (Exception $e) {
                            mysqli_rollback($conn);
                            $error = "Import gagal: " . $e->getMessage();
                        }
                    }
                }
                fclose($handle);
            }
        }
    }
}
?>

<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>
        Import Employees - Bekaert Competency
    </title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="../assets/css/style.css">
</head>

<body class="admin-page">
    <nav class="admin-navbar">
        <div class="admin-brand">
            <img src="../assets/images/Bekaert_logo_neg_RGB.png" alt="Bekaert" class="brand-logo">
            <span>
                Competency System
            </span>
        </div>
        <div class="admin-user">
            <span>
                <?php echo htmlspecialchars($_SESSION['admin_name']); ?>
            </span>
            <a href="logout.php">
                Logout
            </a>
        </div>
    </nav>
    <div class="admin-container">
        <div class="page-header">
            <div>
                <h1>
                    Import Employees
                </h1>
                <p>
                    Upload file CSV untuk menambah atau memperbarui data karyawan secara massal
                </p>
            </div>
            <a href="employees.php" class="btn btn-outline-secondary">
                &larr; Back
            </a>
        </div>
        <?php if ($error !== ''): ?>
            <div class="alert alert-danger">
                <?php echo htmlspecialchars($error); ?>
            </div>
        <?php endif; ?>
        <?php if ($results !== null): ?>
            <div class="alert alert-success">
                Import selesai. Ditambahkan: <?php echo $results['created']; ?>,
                Diperbarui: <?php echo $results['updated']; ?>,
                Dilewati: <?php echo count($results['skipped']); ?>.
            </div>
            <?php if (count($results['skipped']) > 0): ?>
                <div class="alert alert-warning">
                    <strong>Baris yang dilewati:</strong>
                    <ul class="mb-0">
                        <?php foreach ($results['skipped'] as $reason): ?>
                            <li><?php echo htmlspecialchars($reason); ?></li>
                        <?php endforeach; ?>
                    </ul>
                </div>
            <?php endif; ?>
        <?php endif; ?>
        <div class="form-card">
            <h5 class="mb-3">
                Format CSV
            </h5>
            <p class="text-muted">
                Kolom wajib: <code>nik</code>, <code>name</code>, <code>department</code>, <code>position</code>.
                Kolom opsional: <code>supervisor</code>, <code>team</code> (A/B/C/D/NS).
                Data dicocokkan berdasarkan NIK &mdash; kalau NIK sudah terdaftar, datanya akan diperbarui;
                kalau belum, akan dibuat karyawan baru. License ID otomatis mengikuti format BKT-&lt;NIK&gt;.
            </p>
            <a href="employees_import.php?template=1" class="btn btn-sm btn-outline-secondary mb-4">
                Download Template CSV
            </a>
            <hr>
            <form method="POST" enctype="multipart/form-data">
                <?php echo csrf_input(); ?>
                <div class="mb-3">
                    <label class="form-label">
                        File CSV
                    </label>
                    <input type="file" name="csv_file" class="form-control" accept=".csv" required>
                </div>
                <button type="submit" class="btn btn-primary">
                    Upload &amp; Import
                </button>
            </form>
        </div>
    </div>
</body>

</html>
