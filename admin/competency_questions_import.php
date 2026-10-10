<?php
require_once "auth.php";
require_once "../config/database.php";
require_once "../includes/competency_helper.php";
$competencyId = isset($_GET['competency_id'])
    ? (int) $_GET['competency_id']
    : 0;
if ($competencyId <= 0) {
    header("Location: competencies.php");
    exit;
}
$competencyStmt = mysqli_prepare(
    $conn,
    "SELECT id, name FROM competencies WHERE id = ? LIMIT 1"
);
mysqli_stmt_bind_param($competencyStmt, "i", $competencyId);
mysqli_stmt_execute($competencyStmt);
$competency = mysqli_fetch_assoc(mysqli_stmt_get_result($competencyStmt));
if (!$competency) {
    die("Competency tidak ditemukan.");
}
$results = null;
$error = "";
/*
|--------------------------------------------------------------------------
| Download template CSV
|--------------------------------------------------------------------------
*/
if (isset($_GET['template'])) {
    header("Content-Type: text/csv; charset=utf-8");
    header("Content-Disposition: attachment; filename=questions_import_template.csv");
    $templateOutput = fopen('php://output', 'w');
    fputs($templateOutput, "\xEF\xBB\xBF");
    fputcsv($templateOutput, [
        'question_id', 'question_text', 'allow_multiple_answers', 'option_label', 'option_text', 'is_correct', 'points',
    ]);
    $sampleQuestion = 'Apa yang harus dilakukan sebelum melakukan LOTO pada mesin?';
    fputcsv($templateOutput, ['', $sampleQuestion, '0', 'A', 'Langsung mematikan mesin', '0', '0']);
    fputcsv($templateOutput, ['', $sampleQuestion, '0', 'B', 'Informasikan ke supervisor & pasang lock/tag', '1', '25']);
    fputcsv($templateOutput, ['', $sampleQuestion, '0', 'C', 'Menunggu instruksi rekan kerja', '0', '0']);
    fputcsv($templateOutput, ['', $sampleQuestion, '0', 'D', 'Mengabaikan prosedur karena terburu-buru', '0', '0']);
    fclose($templateOutput);
    exit;
}
/*
|--------------------------------------------------------------------------
| Proses upload CSV
|--------------------------------------------------------------------------
|
| 1 soal = 4 baris berurutan (A, B, C, D). question_id kosong = soal baru;
| question_id terisi = update in-place (pilihan jawaban tidak pernah
| dihapus+insert ulang, karena employee_quiz_answers.choice_id pakai FK
| ON DELETE RESTRICT).
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
                    $requiredColumns = ['question_text', 'option_label', 'option_text', 'is_correct', 'points'];
                    $missingColumns = array_diff($requiredColumns, array_keys($columnIndex));
                    if (count($missingColumns) > 0) {
                        $error = "Kolom wajib tidak ditemukan di header CSV: " . implode(', ', $missingColumns);
                    } else {
                        $getColumn = function (array $row) use ($columnIndex): array {
                            $get = function (string $col) use ($row, $columnIndex): string {
                                return isset($columnIndex[$col]) && isset($row[$columnIndex[$col]])
                                    ? trim((string) $row[$columnIndex[$col]])
                                    : '';
                            };
                            return [
                                'question_id' => $get('question_id'),
                                'question_text' => $get('question_text'),
                                'allow_multiple_answers' => $get('allow_multiple_answers'),
                                'option_label' => strtoupper($get('option_label')),
                                'option_text' => $get('option_text'),
                                'is_correct' => $get('is_correct'),
                                'points' => $get('points'),
                            ];
                        };
                        /*
                        | Baca semua baris data, buang baris yang benar-benar
                        | kosong (tidak dihitung ke pengelompokan 4 baris).
                        */
                        $rows = [];
                        $csvRowNumber = 1;
                        $rowNumbers = [];
                        while (($raw = fgetcsv($handle)) !== false) {
                            $csvRowNumber++;
                            $isEmptyRow = true;
                            foreach ($raw as $cell) {
                                if (trim((string) $cell) !== '') {
                                    $isEmptyRow = false;
                                    break;
                                }
                            }
                            if ($isEmptyRow) {
                                continue;
                            }
                            $rows[] = $getColumn($raw);
                            $rowNumbers[] = $csvRowNumber;
                        }
                        if (count($rows) === 0) {
                            $error = "File CSV tidak berisi data.";
                        } elseif (count($rows) % 4 !== 0) {
                            $error = "Jumlah baris data harus kelipatan 4 (1 soal = 4 pilihan A-D). "
                                . "Ditemukan " . count($rows) . " baris.";
                        } else {
                            $created = 0;
                            $updated = 0;
                            $skipped = [];
                            $groups = array_chunk($rows, 4);
                            $groupRowNumbers = array_chunk($rowNumbers, 4);
                            mysqli_begin_transaction($conn);
                            try {
                                foreach ($groups as $groupIndex => $group) {
                                    $startRow = $groupRowNumbers[$groupIndex][0];
                                    $labels = array_column($group, 'option_label');
                                    if ($labels !== ['A', 'B', 'C', 'D']) {
                                        $skipped[] = "Baris {$startRow}: urutan option_label harus A, B, C, D berurutan.";
                                        continue;
                                    }
                                    $questionText = $group[0]['question_text'];
                                    $allowMultiple = in_array(
                                        strtolower($group[0]['allow_multiple_answers']),
                                        ['1', 'true', 'yes', 'ya'],
                                        true
                                    );
                                    $questionId = $group[0]['question_id'] !== '' ? (int) $group[0]['question_id'] : 0;

                                    if ($questionText === '') {
                                        $skipped[] = "Baris {$startRow}: question_text wajib diisi.";
                                        continue;
                                    }

                                    $choices = [];
                                    foreach ($group as $row) {
                                        $choices[$row['option_label']] = [
                                            'text' => $row['option_text'],
                                            'is_correct' => in_array(strtolower($row['is_correct']), ['1', 'true', 'yes', 'ya'], true),
                                            'points' => ctype_digit($row['points']) ? (int) $row['points'] : -1,
                                        ];
                                    }
                                    $pointsInvalid = false;
                                    foreach ($choices as $choice) {
                                        if ($choice['points'] < 0) {
                                            $pointsInvalid = true;
                                        }
                                    }
                                    if ($pointsInvalid) {
                                        $skipped[] = "Baris {$startRow}: kolom points harus angka >= 0.";
                                        continue;
                                    }
                                    $validationError = validateQuestionChoices($choices, $allowMultiple);
                                    if ($validationError !== null) {
                                        $skipped[] = "Baris {$startRow}: {$validationError}";
                                        continue;
                                    }

                                    if ($questionId > 0) {
                                        $existingStmt = mysqli_prepare(
                                            $conn,
                                            "SELECT id FROM competency_questions WHERE id = ? AND competency_id = ? LIMIT 1"
                                        );
                                        mysqli_stmt_bind_param($existingStmt, "ii", $questionId, $competencyId);
                                        mysqli_stmt_execute($existingStmt);
                                        $existing = mysqli_fetch_assoc(mysqli_stmt_get_result($existingStmt));
                                        if (!$existing) {
                                            $skipped[] = "Baris {$startRow}: question_id {$questionId} tidak ditemukan "
                                                . "untuk kompetensi ini.";
                                            continue;
                                        }
                                        $allowMultipleInt = $allowMultiple ? 1 : 0;
                                        $updateQuestionStmt = mysqli_prepare(
                                            $conn,
                                            "UPDATE competency_questions SET question_text = ?, allow_multiple_answers = ? WHERE id = ?"
                                        );
                                        mysqli_stmt_bind_param($updateQuestionStmt, "sii", $questionText, $allowMultipleInt, $questionId);
                                        mysqli_stmt_execute($updateQuestionStmt);

                                        $updateChoiceStmt = mysqli_prepare(
                                            $conn,
                                            "UPDATE competency_question_choices
                                            SET choice_text = ?, is_correct = ?, points = ?
                                            WHERE question_id = ? AND option_label = ?"
                                        );
                                        foreach (['A', 'B', 'C', 'D'] as $label) {
                                            $isCorrectInt = $choices[$label]['is_correct'] ? 1 : 0;
                                            $points = $choices[$label]['points'];
                                            mysqli_stmt_bind_param(
                                                $updateChoiceStmt,
                                                "siiis",
                                                $choices[$label]['text'],
                                                $isCorrectInt,
                                                $points,
                                                $questionId,
                                                $label
                                            );
                                            mysqli_stmt_execute($updateChoiceStmt);
                                        }
                                        $updated++;
                                    } else {
                                        $allowMultipleInt = $allowMultiple ? 1 : 0;
                                        $insertQuestionStmt = mysqli_prepare(
                                            $conn,
                                            "INSERT INTO competency_questions (competency_id, question_text, allow_multiple_answers)
                                            VALUES (?, ?, ?)"
                                        );
                                        mysqli_stmt_bind_param($insertQuestionStmt, "isi", $competencyId, $questionText, $allowMultipleInt);
                                        mysqli_stmt_execute($insertQuestionStmt);
                                        $newQuestionId = mysqli_insert_id($conn);

                                        $insertChoiceStmt = mysqli_prepare(
                                            $conn,
                                            "INSERT INTO competency_question_choices (question_id, option_label, choice_text, is_correct, points)
                                            VALUES (?, ?, ?, ?, ?)"
                                        );
                                        foreach (['A', 'B', 'C', 'D'] as $label) {
                                            $isCorrectInt = $choices[$label]['is_correct'] ? 1 : 0;
                                            $points = $choices[$label]['points'];
                                            mysqli_stmt_bind_param(
                                                $insertChoiceStmt,
                                                "issii",
                                                $newQuestionId,
                                                $label,
                                                $choices[$label]['text'],
                                                $isCorrectInt,
                                                $points
                                            );
                                            mysqli_stmt_execute($insertChoiceStmt);
                                        }
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
        Import Questions - <?php echo htmlspecialchars($competency['name']); ?>
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
                    Import Questions
                </h1>
                <p>
                    Kompetensi: <strong><?php echo htmlspecialchars($competency['name']); ?></strong>
                </p>
            </div>
            <a href="competency_questions.php?competency_id=<?php echo $competencyId; ?>"
                class="btn btn-outline-secondary">
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
                Import selesai. Ditambahkan: <?php echo $results['created']; ?> soal,
                Diperbarui: <?php echo $results['updated']; ?> soal,
                Dilewati: <?php echo count($results['skipped']); ?> soal.
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
                Setiap soal = 4 baris berurutan (option_label A, B, C, D). Kolom:
                <code>question_id</code> (kosongkan untuk soal baru; isi untuk update soal yang sudah ada),
                <code>question_text</code> (sama di keempat baris soal yang sama),
                <code>allow_multiple_answers</code> (0/1, sama di keempat baris),
                <code>option_label</code> (A/B/C/D), <code>option_text</code>,
                <code>is_correct</code> (0/1), <code>points</code> (angka >= 0).
                Minimal 1 pilihan harus <code>is_correct=1</code>; kalau
                <code>allow_multiple_answers=0</code> hanya boleh 1 pilihan benar.
            </p>
            <a href="competency_questions_import.php?competency_id=<?php echo $competencyId; ?>&template=1"
                class="btn btn-sm btn-outline-secondary mb-4">
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
