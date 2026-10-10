<?php
require_once "auth.php";
require_once "../config/database.php";
require_once "../includes/competency_helper.php";
$results = null;
$error = "";
/*
|--------------------------------------------------------------------------
| Parsing tanggal dari CSV
|--------------------------------------------------------------------------
|
| Terima beberapa format umum (Excel kadang menulis d/m/Y). Kembalikan:
|   null   -> sel kosong
|   false  -> ada isinya tapi bukan tanggal valid
|   string -> tanggal ter-normalisasi ke Y-m-d
|
*/
function parseImportDate(string $value)
{
    $value = trim($value);
    if ($value === '') {
        return null;
    }
    foreach (['Y-m-d', 'd/m/Y', 'd-m-Y', 'Y/m/d', 'm/d/Y'] as $format) {
        $date = DateTime::createFromFormat('!' . $format, $value);
        if ($date === false) {
            continue;
        }
        $errors = DateTime::getLastErrors();
        if ($errors === false || ($errors['warning_count'] === 0 && $errors['error_count'] === 0)) {
            return $date->format('Y-m-d');
        }
    }
    return false;
}
/*
|--------------------------------------------------------------------------
| Download template CSV
|--------------------------------------------------------------------------
*/
if (isset($_GET['template'])) {
    header("Content-Type: text/csv; charset=utf-8");
    header("Content-Disposition: attachment; filename=employee_competencies_import_template.csv");
    $templateOutput = fopen('php://output', 'w');
    fputs($templateOutput, "\xEF\xBB\xBF");
    fputcsv($templateOutput, [
        'nik', 'competency_code', 'training_date', 'score', 'trainer',
        'training_provider', 'issue_date', 'expiry_date', 'certificate_number', 'notes',
    ]);
    fputcsv($templateOutput, [
        '12345', 'ELE', '2026-08-01', '85', 'Andi Wijaya',
        'PT Training Nusantara', '', '', '', 'Lulus training Electric Basic',
    ]);
    fclose($templateOutput);
    exit;
}
/*
|--------------------------------------------------------------------------
| Proses upload CSV
|--------------------------------------------------------------------------
|
| Mencatat training yang SUDAH SELESAI ke banyak employee sekaligus untuk
| satu (atau beberapa) competency. Dicocokkan berdasarkan nik + kode
| competency. Kalau assignment-nya belum ada -> dibuat; kalau sudah ada
| -> diperbarui dan diaktifkan kembali (riwayat lama ditimpa oleh data
| training dari CSV).
|
| Kolom yang dikosongkan diisi otomatis:
|   issue_date         -> sama dengan training_date
|   expiry_date        -> training_date + validity_months milik competency
|   certificate_number -> digenerate (PTBI/tahun/bulan romawi/kode/urut)
|   trainer/provider   -> default info training milik competency
|   status             -> dihitung dari tanggal + passing score
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
                    $requiredColumns = ['nik', 'competency_code', 'training_date'];
                    $missingColumns = array_diff($requiredColumns, array_keys($columnIndex));
                    if (count($missingColumns) > 0) {
                        $error = "Kolom wajib tidak ditemukan di header CSV: " . implode(', ', $missingColumns);
                    } else {
                        /*
                        | Cache semua competency berdasarkan code supaya tidak
                        | query berulang-ulang per baris.
                        */
                        $competencyByCode = [];
                        $competencyResult = mysqli_query(
                            $conn,
                            "SELECT
                                id, code, name, validity_months, passing_score,
                                default_trainer, default_training_provider,
                                default_authorizer_title, default_authorizer_name,
                                default_trainer_signatory_id, default_authorizer_signatory_id
                            FROM competencies"
                        );
                        while ($row = mysqli_fetch_assoc($competencyResult)) {
                            $competencyByCode[strtoupper((string) $row['code'])] = $row;
                        }

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
                                $competencyCode = strtoupper($getColumn('competency_code'));
                                $trainingDateRaw = $getColumn('training_date');
                                $scoreRaw = $getColumn('score');
                                $trainer = $getColumn('trainer');
                                $trainingProvider = $getColumn('training_provider');
                                $issueDateRaw = $getColumn('issue_date');
                                $expiryDateRaw = $getColumn('expiry_date');
                                $certificateNumber = $getColumn('certificate_number');
                                $notes = $getColumn('notes');

                                if ($nik === '' || $competencyCode === '') {
                                    $skipped[] = "Baris {$rowNumber}: nik dan competency_code wajib diisi.";
                                    continue;
                                }
                                if (!isset($competencyByCode[$competencyCode])) {
                                    $skipped[] = "Baris {$rowNumber}: competency dengan code '{$competencyCode}' tidak ditemukan.";
                                    continue;
                                }
                                $competency = $competencyByCode[$competencyCode];
                                $competencyId = (int) $competency['id'];

                                $empStmt = mysqli_prepare($conn, "SELECT id FROM employees WHERE nik = ? LIMIT 1");
                                mysqli_stmt_bind_param($empStmt, "s", $nik);
                                mysqli_stmt_execute($empStmt);
                                $employee = mysqli_fetch_assoc(mysqli_stmt_get_result($empStmt));
                                if (!$employee) {
                                    $skipped[] = "Baris {$rowNumber}: employee dengan NIK '{$nik}' tidak ditemukan.";
                                    continue;
                                }
                                $employeeId = (int) $employee['id'];

                                $trainingDate = parseImportDate($trainingDateRaw);
                                if ($trainingDate === null || $trainingDate === false) {
                                    $skipped[] = "Baris {$rowNumber}: training_date wajib diisi dengan tanggal valid (YYYY-MM-DD).";
                                    continue;
                                }

                                $score = null;
                                if ($scoreRaw !== '') {
                                    if (!ctype_digit($scoreRaw) || (int) $scoreRaw < 0 || (int) $scoreRaw > 100) {
                                        $skipped[] = "Baris {$rowNumber}: score harus angka 0-100.";
                                        continue;
                                    }
                                    $score = (int) $scoreRaw;
                                }

                                $issueDate = parseImportDate($issueDateRaw);
                                if ($issueDate === false) {
                                    $skipped[] = "Baris {$rowNumber}: issue_date bukan tanggal valid.";
                                    continue;
                                }
                                if ($issueDate === null) {
                                    $issueDate = $trainingDate;
                                }

                                $expiryDate = parseImportDate($expiryDateRaw);
                                if ($expiryDate === false) {
                                    $skipped[] = "Baris {$rowNumber}: expiry_date bukan tanggal valid.";
                                    continue;
                                }
                                if ($expiryDate === null) {
                                    $validityMonths = $competency['validity_months'] !== null
                                        ? (int) $competency['validity_months']
                                        : 12;
                                    $expiryDate = (new DateTime($trainingDate))
                                        ->modify("+{$validityMonths} months")
                                        ->format('Y-m-d');
                                }
                                if ($expiryDate < $issueDate) {
                                    $skipped[] = "Baris {$rowNumber}: expiry_date lebih awal dari issue_date.";
                                    continue;
                                }

                                $passingScore = $competency['passing_score'] !== null
                                    ? (int) $competency['passing_score']
                                    : null;
                                $status = calculateCompetencyStatus($trainingDate, $expiryDate);
                                $status = applyPassingScoreGate($status, $score, $passingScore);

                                /*
                                | Cek assignment yang sudah ada untuk pasangan
                                | employee + competency ini.
                                */
                                $existingStmt = mysqli_prepare(
                                    $conn,
                                    "SELECT id, certificate_number, trainer, training_provider, notes
                                    FROM employee_competencies
                                    WHERE employee_id = ? AND competency_id = ?
                                    LIMIT 1"
                                );
                                mysqli_stmt_bind_param($existingStmt, "ii", $employeeId, $competencyId);
                                mysqli_stmt_execute($existingStmt);
                                $existing = mysqli_fetch_assoc(mysqli_stmt_get_result($existingStmt));

                                $effTrainer = $trainer !== ''
                                    ? $trainer
                                    : ($existing['trainer'] ?? $competency['default_trainer'] ?? null);
                                $effProvider = $trainingProvider !== ''
                                    ? $trainingProvider
                                    : ($existing['training_provider'] ?? $competency['default_training_provider'] ?? null);
                                $effNotes = $notes !== ''
                                    ? $notes
                                    : ($existing['notes'] ?? null);

                                /*
                                | Nomor sertifikat: pakai dari CSV kalau diisi,
                                | kalau tidak pertahankan yang lama, kalau masih
                                | kosong baru digenerate.
                                */
                                $effCertificate = $certificateNumber;
                                if ($effCertificate === '') {
                                    $effCertificate = trim((string) ($existing['certificate_number'] ?? ''));
                                }
                                if ($effCertificate === '') {
                                    $effCertificate = generateCertificateNumber($conn, $competencyId, $trainingDate);
                                }

                                if ($existing) {
                                    $updateStmt = mysqli_prepare(
                                        $conn,
                                        "UPDATE employee_competencies
                                        SET
                                            is_active = 1,
                                            training_date = ?,
                                            issue_date = ?,
                                            expiry_date = ?,
                                            score = ?,
                                            trainer = ?,
                                            training_provider = ?,
                                            certificate_number = ?,
                                            notes = ?,
                                            status = ?
                                        WHERE id = ?"
                                    );
                                    // training_date, issue_date, expiry_date, score, trainer,
                                    // training_provider, certificate_number, notes, status, id
                                    $types = "sssisssssi";
                                    $params = [
                                        $trainingDate,
                                        $issueDate,
                                        $expiryDate,
                                        $score,
                                        $effTrainer,
                                        $effProvider,
                                        $effCertificate,
                                        $effNotes,
                                        $status,
                                        (int) $existing['id'],
                                    ];
                                    mysqli_stmt_bind_param($updateStmt, $types, ...$params);
                                    mysqli_stmt_execute($updateStmt);
                                    recordCompetencyHistory(
                                        $conn,
                                        (int) $existing['id'],
                                        'import',
                                        $_SESSION['admin_name'] ?? null
                                    );
                                    $updated++;
                                } else {
                                    $authorizerTitle = $competency['default_authorizer_title'] ?? 'Maintenance Manager';
                                    $authorizerName = $competency['default_authorizer_name'] ?? null;
                                    $trainerSignatoryId = !empty($competency['default_trainer_signatory_id'])
                                        ? (int) $competency['default_trainer_signatory_id']
                                        : null;
                                    $authorizerSignatoryId = !empty($competency['default_authorizer_signatory_id'])
                                        ? (int) $competency['default_authorizer_signatory_id']
                                        : null;
                                    $insertStmt = mysqli_prepare(
                                        $conn,
                                        "INSERT INTO employee_competencies
                                        (
                                            employee_id, competency_id, status, is_active,
                                            training_date, issue_date, expiry_date, score,
                                            trainer, training_provider, certificate_number,
                                            authorizer_title, authorizer_name,
                                            trainer_signatory_id, authorizer_signatory_id, notes
                                        )
                                        VALUES (?, ?, ?, 1, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)"
                                    );
                                    $types = "iis" . "sssi" . "sss" . "ss" . "ii" . "s";
                                    $params = [
                                        $employeeId,
                                        $competencyId,
                                        $status,
                                        $trainingDate,
                                        $issueDate,
                                        $expiryDate,
                                        $score,
                                        $effTrainer,
                                        $effProvider,
                                        $effCertificate,
                                        $authorizerTitle,
                                        $authorizerName,
                                        $trainerSignatoryId,
                                        $authorizerSignatoryId,
                                        $effNotes,
                                    ];
                                    mysqli_stmt_bind_param($insertStmt, $types, ...$params);
                                    mysqli_stmt_execute($insertStmt);
                                    recordCompetencyHistory(
                                        $conn,
                                        (int) mysqli_insert_id($conn),
                                        'import',
                                        $_SESSION['admin_name'] ?? null
                                    );
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
        Import Training Records - Bekaert Competency
    </title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet">
    <link rel="stylesheet" href="../assets/css/style.css?v=<?php echo filemtime(__DIR__ . '/../assets/css/style.css'); ?>">
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
                <i class="bi bi-box-arrow-right"></i> Logout
            </a>
        </div>
    </nav>
<div class="admin-layout">
    <?php include "../includes/admin_sidebar.php"; ?>
    <main class="admin-content">
    <div class="admin-container">
        <div class="page-header">
            <div>
                <h1>
                    Import Training Records
                </h1>
                <p>
                    Upload file CSV untuk mencatat training yang sudah selesai ke banyak karyawan sekaligus
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
                Kolom wajib: <code>nik</code>, <code>competency_code</code> (kode 3 huruf competency,
                mis. <code>ELE</code>), <code>training_date</code> (tanggal training sudah selesai, format
                <code>YYYY-MM-DD</code>).
            </p>
            <p class="text-muted">
                Kolom opsional: <code>score</code> (0-100), <code>trainer</code>,
                <code>training_provider</code>, <code>issue_date</code>, <code>expiry_date</code>,
                <code>certificate_number</code>, <code>notes</code>.
                Yang dikosongkan diisi otomatis &mdash; <code>issue_date</code> = <code>training_date</code>,
                <code>expiry_date</code> = <code>training_date</code> + masa berlaku competency,
                <code>certificate_number</code> digenerate, <code>trainer</code>/<code>training_provider</code>
                memakai default info training milik competency.
            </p>
            <p class="text-muted">
                Satu baris = satu karyawan untuk satu competency. Dicocokkan berdasarkan
                <code>nik</code> + <code>competency_code</code>: kalau karyawan itu belum punya competency
                tersebut akan dibuat, kalau sudah punya datanya diperbarui (diaktifkan kembali dan
                ditimpa dengan data training dari CSV). Status dihitung otomatis dari tanggal dan
                nilai kelulusan minimum.
            </p>
            <a href="employee_competencies_import.php?template=1" class="btn btn-sm btn-outline-secondary mb-4">
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
</main>
</div>
</body>

</html>
