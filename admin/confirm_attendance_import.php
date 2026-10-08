<?php
require_once "auth.php";
require_once "../config/database.php";
require_once "../includes/competency_helper.php";
$results = null;
$error = "";
/*
|--------------------------------------------------------------------------
| Export daftar Assigned saat ini sebagai CSV (buat diisi kolom attended)
|--------------------------------------------------------------------------
|
| Dicocokkan lagi berdasarkan nik + competency_code saat diupload, jadi
| kolom lain di CSV ini cuma buat referensi admin -- boleh dihapus/diubah
| tanpa pengaruh ke proses import.
|
*/
if (isset($_GET['export'])) {
    header("Content-Type: text/csv; charset=utf-8");
    header("Content-Disposition: attachment; filename=assigned_attendance_" . date('Y-m-d') . ".csv");
    $out = fopen('php://output', 'w');
    fputs($out, "\xEF\xBB\xBF");
    fputcsv($out, ['nik', 'name', 'competency_code', 'competency_name', 'scheduled_training_date', 'attended']);
    $exportResult = mysqli_query(
        $conn,
        "SELECT e.nik, e.name, c.code AS competency_code, c.name AS competency_name, ec.scheduled_training_date
        FROM employee_competencies ec
        INNER JOIN employees e ON e.id = ec.employee_id
        INNER JOIN competencies c ON c.id = ec.competency_id
        WHERE ec.status = 'ASSIGNED' AND ec.is_active = 1 AND e.is_deleted = 0
        ORDER BY e.name ASC"
    );
    while ($row = mysqli_fetch_assoc($exportResult)) {
        fputcsv($out, [
            $row['nik'],
            $row['name'],
            $row['competency_code'],
            $row['competency_name'],
            $row['scheduled_training_date'],
            '',
        ]);
    }
    fclose($out);
    exit;
}
/*
|--------------------------------------------------------------------------
| Download template CSV kosong
|--------------------------------------------------------------------------
*/
if (isset($_GET['template'])) {
    header("Content-Type: text/csv; charset=utf-8");
    header("Content-Disposition: attachment; filename=confirm_attendance_template.csv");
    $out = fopen('php://output', 'w');
    fputs($out, "\xEF\xBB\xBF");
    fputcsv($out, ['nik', 'competency_code', 'attended']);
    fputcsv($out, ['12345', 'ELE', 'yes']);
    fclose($out);
    exit;
}
/*
|--------------------------------------------------------------------------
| Proses upload CSV
|--------------------------------------------------------------------------
|
| Konfirmasi (atau batalkan konfirmasi) kehadiran secara massal lewat CSV,
| dicocokkan berdasarkan nik + competency_code. Hanya menyentuh assignment
| yang statusnya masih ASSIGNED -- sama seperti confirm_attendance_bulk.php
| (checkbox massal di halaman Assigned Competencies). Begitu attended=yes
| dan training_date masih kosong, diisi otomatis tanggal hari ini dan
| scheduled_training_date disamakan -- lihat
| calculateCompetencyStatusWithSchedule().
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
                    $requiredColumns = ['nik', 'competency_code'];
                    $missingColumns = array_diff($requiredColumns, array_keys($columnIndex));
                    if (count($missingColumns) > 0) {
                        $error = "Kolom wajib tidak ditemukan di header CSV: " . implode(', ', $missingColumns);
                    } else {
                        $competencyByCode = [];
                        $competencyResult = mysqli_query($conn, "SELECT id, code FROM competencies");
                        while ($row = mysqli_fetch_assoc($competencyResult)) {
                            $competencyByCode[strtoupper((string) $row['code'])] = (int) $row['id'];
                        }

                        $todayDate = date('Y-m-d');
                        $confirmed = 0;
                        $unconfirmed = 0;
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
                                $competencyCode = strtoupper($getColumn('competency_code'));
                                $attendedRaw = strtolower($getColumn('attended'));

                                if ($nik === '' || $competencyCode === '') {
                                    $skipped[] = "Baris {$rowNumber}: nik dan competency_code wajib diisi.";
                                    continue;
                                }
                                if (in_array($attendedRaw, ['', 'yes', 'y', '1', 'hadir', 'true'], true)) {
                                    $attended = true;
                                } elseif (in_array($attendedRaw, ['no', 'n', '0', 'tidak', 'false'], true)) {
                                    $attended = false;
                                } else {
                                    $skipped[] = "Baris {$rowNumber}: nilai attended '{$attendedRaw}' tidak valid (pakai yes/no).";
                                    continue;
                                }
                                if (!isset($competencyByCode[$competencyCode])) {
                                    $skipped[] = "Baris {$rowNumber}: competency dengan code '{$competencyCode}' tidak ditemukan.";
                                    continue;
                                }
                                $competencyId = $competencyByCode[$competencyCode];

                                $empStmt = mysqli_prepare($conn, "SELECT id FROM employees WHERE nik = ? AND is_deleted = 0 LIMIT 1");
                                mysqli_stmt_bind_param($empStmt, "s", $nik);
                                mysqli_stmt_execute($empStmt);
                                $employee = mysqli_fetch_assoc(mysqli_stmt_get_result($empStmt));
                                if (!$employee) {
                                    $skipped[] = "Baris {$rowNumber}: employee dengan NIK '{$nik}' tidak ditemukan.";
                                    continue;
                                }
                                $employeeId = (int) $employee['id'];

                                $existingStmt = mysqli_prepare(
                                    $conn,
                                    "SELECT id, status, training_date FROM employee_competencies
                                    WHERE employee_id = ? AND competency_id = ? AND is_active = 1 LIMIT 1"
                                );
                                mysqli_stmt_bind_param($existingStmt, "ii", $employeeId, $competencyId);
                                mysqli_stmt_execute($existingStmt);
                                $existing = mysqli_fetch_assoc(mysqli_stmt_get_result($existingStmt));
                                if (!$existing) {
                                    $skipped[] = "Baris {$rowNumber}: NIK '{$nik}' belum di-assign ke competency '{$competencyCode}'.";
                                    continue;
                                }
                                if ($existing['status'] !== 'ASSIGNED') {
                                    $skipped[] = "Baris {$rowNumber}: NIK '{$nik}' / '{$competencyCode}' statusnya bukan Assigned (saat ini: "
                                        . competencyStatusLabel($existing['status']) . ").";
                                    continue;
                                }

                                if ($attended) {
                                    $updateStmt = mysqli_prepare(
                                        $conn,
                                        "UPDATE employee_competencies
                                        SET attendance_confirmed = 1,
                                            training_date = COALESCE(training_date, ?),
                                            scheduled_training_date = training_date
                                        WHERE id = ?"
                                    );
                                    mysqli_stmt_bind_param($updateStmt, "si", $todayDate, $existing['id']);
                                    mysqli_stmt_execute($updateStmt);
                                    $confirmed++;
                                } else {
                                    $updateStmt = mysqli_prepare(
                                        $conn,
                                        "UPDATE employee_competencies SET attendance_confirmed = 0 WHERE id = ?"
                                    );
                                    mysqli_stmt_bind_param($updateStmt, "i", $existing['id']);
                                    mysqli_stmt_execute($updateStmt);
                                    $unconfirmed++;
                                }
                            }
                            mysqli_commit($conn);
                            $results = [
                                'confirmed' => $confirmed,
                                'unconfirmed' => $unconfirmed,
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
        Import Attendance - Bekaert Competency
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
                    Import Attendance
                </h1>
                <p>
                    Upload file CSV untuk konfirmasi (atau batalkan konfirmasi) kehadiran training secara massal
                </p>
            </div>
            <a href="competency_status.php?status=ASSIGNED" class="btn btn-outline-secondary">
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
                Import selesai. Dikonfirmasi hadir: <?php echo $results['confirmed']; ?>,
                Dibatalkan konfirmasi: <?php echo $results['unconfirmed']; ?>,
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
                Kolom wajib: <code>nik</code>, <code>competency_code</code> (kode 3 huruf competency,
                mis. <code>ELE</code>). Kolom opsional: <code>attended</code>
                (<code>yes</code>/<code>no</code>, default <code>yes</code> kalau dikosongkan).
            </p>
            <p class="text-muted">
                Dicocokkan berdasarkan <code>nik</code> + <code>competency_code</code>, dan hanya menyentuh
                assignment yang statusnya masih <strong>Assigned</strong> (sama seperti centang massal di
                halaman Assigned Competencies). Baris untuk assignment yang tidak ditemukan atau sudah
                bukan Assigned (sudah Valid/Failed/dst.) akan dilewati dan dilaporkan.
            </p>
            <p class="text-muted">
                Begitu <code>attended</code> = <code>yes</code> dan training belum pernah tercatat,
                training date otomatis diisi tanggal hari ini upload dilakukan, dan scheduled date
                disamakan. Status tetap <strong>Assigned</strong> sampai karyawan submit kuis.
            </p>
            <div class="d-flex gap-2 mb-4">
                <a href="confirm_attendance_import.php?export=1" class="btn btn-sm btn-outline-secondary">
                    Export Daftar Assigned Saat Ini
                </a>
                <a href="confirm_attendance_import.php?template=1" class="btn btn-sm btn-outline-secondary">
                    Download Template Kosong
                </a>
            </div>
            <hr>
            <?php if (admin_can_write()): ?>
                <form method="POST" enctype="multipart/form-data">
                    <?php echo csrf_input(); ?>
                    <div class="mb-3">
                        <label class="form-label">
                            File CSV
                        </label>
                        <input type="file" name="csv_file" class="form-control" accept=".csv" required>
                    </div>
                    <button type="submit" class="btn btn-primary">
                        Upload &amp; Proses
                    </button>
                </form>
            <?php else: ?>
                <span class="text-muted">Akun read-only &mdash; tidak bisa mengupload.</span>
            <?php endif; ?>
        </div>
    </div>
</body>

</html>
