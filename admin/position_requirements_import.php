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
    header("Content-Disposition: attachment; filename=position_requirements_import_template.csv");
    $templateOutput = fopen('php://output', 'w');
    fputs($templateOutput, "\xEF\xBB\xBF");
    fputcsv($templateOutput, ['position', 'competency_code']);
    fputcsv($templateOutput, ['Technician', 'K3-001']);
    fputcsv($templateOutput, ['Technician', 'ELEC-002']);
    fclose($templateOutput);
    exit;
}
/*
|--------------------------------------------------------------------------
| Proses upload CSV
|--------------------------------------------------------------------------
|
| Untuk setiap posisi yang muncul di file, SELURUH required competency
| posisi itu diganti dengan isi file (replace per posisi) -- sama seperti
| menyimpan form checklist di position_requirements.php, supaya baris yang
| dihapus dari file juga ikut terhapus dari posisi tersebut, bukan hanya
| ditambahkan.
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
                    $requiredColumns = ['position', 'competency_code'];
                    $missingColumns = array_diff($requiredColumns, array_keys($columnIndex));
                    if (count($missingColumns) > 0) {
                        $error = "Kolom wajib tidak ditemukan di header CSV: " . implode(', ', $missingColumns);
                    } else {
                        $competencyByCode = [];
                        $competencyResult = mysqli_query($conn, "SELECT id, code FROM competencies WHERE code IS NOT NULL AND code <> ''");
                        while ($row = mysqli_fetch_assoc($competencyResult)) {
                            $competencyByCode[strtoupper(trim($row['code']))] = (int) $row['id'];
                        }

                        $rowsByPosition = [];
                        $skipped = [];
                        $rowNumber = 1;
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
                            $position = $getColumn('position');
                            $competencyCode = strtoupper($getColumn('competency_code'));

                            if ($position === '' || $competencyCode === '') {
                                $skipped[] = "Baris {$rowNumber}: position dan competency_code wajib diisi.";
                                continue;
                            }
                            if (!isset($competencyByCode[$competencyCode])) {
                                $skipped[] = "Baris {$rowNumber}: competency_code '{$competencyCode}' tidak ditemukan.";
                                continue;
                            }
                            $rowsByPosition[$position][$competencyByCode[$competencyCode]] = true;
                        }

                        $positionsUpdated = 0;
                        $requirementsCreated = 0;
                        mysqli_begin_transaction($conn);
                        try {
                            $deleteStmt = mysqli_prepare($conn, "DELETE FROM position_requirements WHERE position = ?");
                            $insertStmt = mysqli_prepare(
                                $conn,
                                "INSERT INTO position_requirements (position, competency_id) VALUES (?, ?)"
                            );
                            foreach ($rowsByPosition as $position => $competencyIds) {
                                mysqli_stmt_bind_param($deleteStmt, "s", $position);
                                mysqli_stmt_execute($deleteStmt);
                                foreach (array_keys($competencyIds) as $competencyId) {
                                    mysqli_stmt_bind_param($insertStmt, "si", $position, $competencyId);
                                    mysqli_stmt_execute($insertStmt);
                                    $requirementsCreated++;
                                }
                                $positionsUpdated++;
                            }
                            mysqli_commit($conn);
                            $results = [
                                'positions_updated' => $positionsUpdated,
                                'requirements_created' => $requirementsCreated,
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
    <link rel="icon" type="image/x-icon" href="../favicon.ico">
    <link rel="icon" type="image/png" sizes="32x32" href="../assets/images/favicon-32.png">
    <link rel="apple-touch-icon" href="../assets/images/apple-touch-icon.png">
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>
        Import Required Competency - Bekaert Competency
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
                    Import Required Competency
                </h1>
                <p>
                    Upload file CSV untuk mengatur competency wajib per posisi secara massal
                </p>
            </div>
            <a href="position_requirements.php" class="btn btn-outline-secondary">
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
                Import selesai. Posisi diperbarui: <?php echo $results['positions_updated']; ?>,
                Total requirement disimpan: <?php echo $results['requirements_created']; ?>,
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
                Kolom wajib: <code>position</code>, <code>competency_code</code> (harus cocok dengan code
                competency yang sudah ada di menu Competencies).
                Untuk setiap posisi yang muncul di file, SELURUH required competency posisi itu akan diganti
                dengan isi file ini (baris lama yang tidak ada di file akan terhapus untuk posisi tersebut).
                Posisi yang tidak disebutkan di file sama sekali tidak akan diubah.
            </p>
            <a href="position_requirements_import.php?template=1" class="btn btn-sm btn-outline-secondary mb-4">
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
