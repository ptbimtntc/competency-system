<?php
require_once "auth.php";
require_once "../config/database.php";
$results = null;
$error = "";
/*
|--------------------------------------------------------------------------
| Download template CSV
|--------------------------------------------------------------------------
*/
if (isset($_GET['template'])) {
    header("Content-Type: text/csv; charset=utf-8");
    header("Content-Disposition: attachment; filename=competencies_import_template.csv");
    $templateOutput = fopen('php://output', 'w');
    fputs($templateOutput, "\xEF\xBB\xBF");
    fputcsv($templateOutput, [
        'code', 'name', 'description', 'scope', 'validity_months', 'passing_score', 'validity_note',
        'default_trainer', 'default_training_provider', 'default_authorizer_title', 'default_authorizer_name',
    ]);
    fputcsv($templateOutput, [
        'ELE',
        'Electrical Basic',
        'Dasar kelistrikan',
        "Mengoperasikan panel listrik\nMelakukan perawatan berkala",
        '12',
        '70',
        'Kompetensi ini berlaku selama 1 tahun sejak tanggal training.',
        '',
        '',
        'Maintenance Manager',
        '',
    ]);
    fclose($templateOutput);
    exit;
}
/*
|--------------------------------------------------------------------------
| Proses upload CSV
|--------------------------------------------------------------------------
|
| Upsert berdasarkan code (unik). Nama tetap divalidasi supaya tidak
| bentrok dengan competency lain, sesuai aturan yang sama dengan halaman
| Add/Edit Competency.
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
                    $requiredColumns = ['code', 'name'];
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
                                $code = strtoupper($getColumn('code'));
                                $name = $getColumn('name');
                                $description = $getColumn('description');
                                $scope = $getColumn('scope');
                                $validityNote = $getColumn('validity_note');
                                $validityMonthsRaw = $getColumn('validity_months');
                                $passingScoreRaw = $getColumn('passing_score');
                                $defaultTrainer = $getColumn('default_trainer');
                                $defaultTrainingProvider = $getColumn('default_training_provider');
                                $defaultAuthorizerTitle = $getColumn('default_authorizer_title');
                                $defaultAuthorizerName = $getColumn('default_authorizer_name');

                                if ($name === '' || !preg_match('/^[A-Z]{3}$/', $code)) {
                                    $skipped[] = "Baris {$rowNumber}: name wajib diisi dan code harus 3 huruf kapital (A-Z).";
                                    continue;
                                }
                                $validityMonths = null;
                                if ($validityMonthsRaw !== '') {
                                    if (
                                        !ctype_digit($validityMonthsRaw) ||
                                        (int) $validityMonthsRaw < 1 ||
                                        (int) $validityMonthsRaw > 120
                                    ) {
                                        $skipped[] = "Baris {$rowNumber}: validity_months harus angka 1-120.";
                                        continue;
                                    }
                                    $validityMonths = (int) $validityMonthsRaw;
                                }
                                $passingScore = null;
                                if ($passingScoreRaw !== '') {
                                    if (
                                        !ctype_digit($passingScoreRaw) ||
                                        (int) $passingScoreRaw < 0 ||
                                        (int) $passingScoreRaw > 100
                                    ) {
                                        $skipped[] = "Baris {$rowNumber}: passing_score harus angka 0-100.";
                                        continue;
                                    }
                                    $passingScore = (int) $passingScoreRaw;
                                }
                                /*
                                | Cek bentrok nama/code dengan competency lain (aturan sama
                                | seperti Add/Edit Competency: name & code harus unik).
                                */
                                $conflictStmt = mysqli_prepare(
                                    $conn,
                                    "SELECT id, code FROM competencies WHERE name = ? OR code = ?"
                                );
                                mysqli_stmt_bind_param($conflictStmt, "ss", $name, $code);
                                mysqli_stmt_execute($conflictStmt);
                                $conflictResult = mysqli_stmt_get_result($conflictStmt);
                                $existingByCode = null;
                                $nameConflict = false;
                                while ($conflictRow = mysqli_fetch_assoc($conflictResult)) {
                                    if ($conflictRow['code'] === $code) {
                                        $existingByCode = $conflictRow;
                                    } else {
                                        $nameConflict = true;
                                    }
                                }
                                if ($nameConflict) {
                                    $skipped[] = "Baris {$rowNumber}: nama '{$name}' sudah dipakai kompetensi lain.";
                                    continue;
                                }

                                if ($existingByCode) {
                                    $updateStmt = mysqli_prepare(
                                        $conn,
                                        "UPDATE competencies
                                        SET name = ?, description = ?, scope = ?, validity_note = ?, validity_months = ?,
                                            passing_score = ?,
                                            default_trainer = ?, default_training_provider = ?,
                                            default_authorizer_title = ?, default_authorizer_name = ?
                                        WHERE id = ?"
                                    );
                                    $types = "";
                                    $params = [];
                                    $types .= "s"; $params[] = $name;
                                    $types .= "s"; $params[] = $description;
                                    $types .= "s"; $params[] = $scope;
                                    $types .= "s"; $params[] = $validityNote;
                                    $types .= "i"; $params[] = $validityMonths;
                                    $types .= "i"; $params[] = $passingScore;
                                    $types .= "s"; $params[] = $defaultTrainer;
                                    $types .= "s"; $params[] = $defaultTrainingProvider;
                                    $types .= "s"; $params[] = $defaultAuthorizerTitle;
                                    $types .= "s"; $params[] = $defaultAuthorizerName;
                                    $types .= "i"; $params[] = $existingByCode['id'];
                                    mysqli_stmt_bind_param($updateStmt, $types, ...$params);
                                    mysqli_stmt_execute($updateStmt);
                                    $updated++;
                                } else {
                                    $insertStmt = mysqli_prepare(
                                        $conn,
                                        "INSERT INTO competencies
                                        (name, code, description, scope, validity_note, validity_months, passing_score,
                                         default_trainer, default_training_provider,
                                         default_authorizer_title, default_authorizer_name)
                                        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)"
                                    );
                                    $types = "";
                                    $params = [];
                                    $types .= "s"; $params[] = $name;
                                    $types .= "s"; $params[] = $code;
                                    $types .= "s"; $params[] = $description;
                                    $types .= "s"; $params[] = $scope;
                                    $types .= "s"; $params[] = $validityNote;
                                    $types .= "i"; $params[] = $validityMonths;
                                    $types .= "i"; $params[] = $passingScore;
                                    $types .= "s"; $params[] = $defaultTrainer;
                                    $types .= "s"; $params[] = $defaultTrainingProvider;
                                    $types .= "s"; $params[] = $defaultAuthorizerTitle;
                                    $types .= "s"; $params[] = $defaultAuthorizerName;
                                    mysqli_stmt_bind_param($insertStmt, $types, ...$params);
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
        Import Competencies - Bekaert Competency
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
                    Import Competencies
                </h1>
                <p>
                    Upload file CSV untuk menambah atau memperbarui data kompetensi secara massal
                </p>
            </div>
            <a href="competencies.php" class="btn btn-outline-secondary">
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
                Kolom wajib: <code>code</code> (3 huruf kapital, unik), <code>name</code> (unik).
                Kolom opsional: <code>description</code>, <code>scope</code> (satu baris per item),
                <code>validity_months</code> (1-120), <code>passing_score</code> (0-100), <code>validity_note</code>,
                <code>default_trainer</code>, <code>default_training_provider</code>,
                <code>default_authorizer_title</code>, <code>default_authorizer_name</code>.
                Data dicocokkan berdasarkan code &mdash; kalau sudah ada, datanya diperbarui;
                kalau belum, dibuat kompetensi baru.
            </p>
            <a href="competencies_import.php?template=1" class="btn btn-sm btn-outline-secondary mb-4">
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
