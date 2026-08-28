<?php
require_once "auth.php";
require_once "../config/database.php";
require_once "../includes/competency_helper.php";
$id = isset($_GET['id'])
    ? (int) $_GET['id']
    : 0;
if ($id <= 0) {
    header("Location: competencies.php");
    exit;
}
$questionStmt = mysqli_prepare(
    $conn,
    "SELECT cq.id, cq.competency_id, cq.question_text, cq.allow_multiple_answers, c.name AS competency_name
    FROM competency_questions cq
    INNER JOIN competencies c ON c.id = cq.competency_id
    WHERE cq.id = ?
    LIMIT 1"
);
mysqli_stmt_bind_param($questionStmt, "i", $id);
mysqli_stmt_execute($questionStmt);
$question = mysqli_fetch_assoc(mysqli_stmt_get_result($questionStmt));
if (!$question) {
    die("Soal tidak ditemukan.");
}
$competencyId = (int) $question['competency_id'];
/*
|--------------------------------------------------------------------------
| Cek apakah soal ini sudah pernah dijawab
|--------------------------------------------------------------------------
*/
$answeredStmt = mysqli_prepare(
    $conn,
    "SELECT COUNT(DISTINCT employee_competency_id) AS total FROM employee_quiz_answers WHERE question_id = ?"
);
mysqli_stmt_bind_param($answeredStmt, "i", $id);
mysqli_stmt_execute($answeredStmt);
$answeredCount = (int) mysqli_fetch_assoc(mysqli_stmt_get_result($answeredStmt))['total'];
/*
|--------------------------------------------------------------------------
| Ambil pilihan jawaban saat ini
|--------------------------------------------------------------------------
*/
$choiceStmt = mysqli_prepare(
    $conn,
    "SELECT option_label, choice_text, is_correct, points
    FROM competency_question_choices WHERE question_id = ? ORDER BY option_label ASC"
);
mysqli_stmt_bind_param($choiceStmt, "i", $id);
mysqli_stmt_execute($choiceStmt);
$choiceResult = mysqli_stmt_get_result($choiceStmt);
$choiceInput = [];
while ($row = mysqli_fetch_assoc($choiceResult)) {
    $choiceInput[$row['option_label']] = [
        'text' => $row['choice_text'],
        'is_correct' => (int) $row['is_correct'] === 1,
        'points' => (int) $row['points'],
    ];
}
$questionText = $question['question_text'];
$allowMultiple = (int) $question['allow_multiple_answers'] === 1;
$error = "";
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_validate();
    require_writer();
    $questionText = trim($_POST['question_text'] ?? '');
    $allowMultiple = isset($_POST['allow_multiple_answers']);
    foreach (['A', 'B', 'C', 'D'] as $label) {
        $choiceInput[$label] = [
            'text' => trim($_POST['choice_text'][$label] ?? ''),
            'is_correct' => isset($_POST['is_correct'][$label]),
            'points' => (int) ($_POST['points'][$label] ?? 0),
        ];
    }
    if ($questionText === '') {
        $error = "Pertanyaan wajib diisi.";
    } else {
        $error = validateQuestionChoices($choiceInput, $allowMultiple) ?? '';
    }
    if ($error === '') {
        mysqli_begin_transaction($conn);
        try {
            $updateQuestionStmt = mysqli_prepare(
                $conn,
                "UPDATE competency_questions SET question_text = ?, allow_multiple_answers = ? WHERE id = ?"
            );
            $allowMultipleInt = $allowMultiple ? 1 : 0;
            mysqli_stmt_bind_param($updateQuestionStmt, "sii", $questionText, $allowMultipleInt, $id);
            mysqli_stmt_execute($updateQuestionStmt);

            /*
            | Update pilihan in-place (bukan delete+reinsert), karena
            | employee_quiz_answers.choice_id pakai FK ON DELETE RESTRICT.
            */
            $updateChoiceStmt = mysqli_prepare(
                $conn,
                "UPDATE competency_question_choices
                SET choice_text = ?, is_correct = ?, points = ?
                WHERE question_id = ? AND option_label = ?"
            );
            foreach (['A', 'B', 'C', 'D'] as $label) {
                $isCorrectInt = $choiceInput[$label]['is_correct'] ? 1 : 0;
                $points = $choiceInput[$label]['points'];
                mysqli_stmt_bind_param(
                    $updateChoiceStmt,
                    "siiis",
                    $choiceInput[$label]['text'],
                    $isCorrectInt,
                    $points,
                    $id,
                    $label
                );
                mysqli_stmt_execute($updateChoiceStmt);
            }
            mysqli_commit($conn);
            header("Location: competency_questions.php?competency_id=" . $competencyId);
            exit;
        } catch (Exception $e) {
            mysqli_rollback($conn);
            $error = "Gagal menyimpan soal: " . $e->getMessage();
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
        Edit Question - <?php echo htmlspecialchars($question['competency_name']); ?>
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
                    Edit Question
                </h1>
                <p>
                    Kompetensi: <strong><?php echo htmlspecialchars($question['competency_name']); ?></strong>
                </p>
            </div>
            <a href="competency_questions.php?competency_id=<?php echo $competencyId; ?>"
                class="btn btn-outline-secondary">
                &larr; Back
            </a>
        </div>
        <?php if ($answeredCount > 0): ?>
            <div class="alert alert-warning">
                Soal ini sudah pernah dijawab oleh <?php echo $answeredCount; ?> karyawan.
                Perubahan tidak memengaruhi skor yang sudah tersimpan.
            </div>
        <?php endif; ?>
        <?php if (!empty($error)): ?>
            <div class="alert alert-danger">
                <?php echo htmlspecialchars($error); ?>
            </div>
        <?php endif; ?>
        <div class="form-card">
            <form method="POST" id="questionForm">
                <?php echo csrf_input(); ?>
                <div class="mb-3">
                    <label class="form-label">
                        Question Text
                    </label>
                    <textarea name="question_text" class="form-control" rows="3" required><?php
                        echo htmlspecialchars($questionText);
                        ?></textarea>
                </div>
                <div class="mb-4 form-check">
                    <input class="form-check-input" type="checkbox" name="allow_multiple_answers" id="allowMultiple"
                        value="1" <?php echo $allowMultiple ? 'checked' : ''; ?>>
                    <label class="form-check-label" for="allowMultiple">
                        Boleh pilih lebih dari 1 jawaban benar (multiple answers)
                    </label>
                </div>
                <hr>
                <h5 class="mb-3">
                    Pilihan Jawaban
                </h5>
                <div class="form-text mb-3">
                    Isi keempat pilihan A-D, tandai jawaban yang benar, dan isi poin untuk masing-masing pilihan.
                </div>
                <?php foreach (['A', 'B', 'C', 'D'] as $label): ?>
                    <div class="row g-3 align-items-center mb-3">
                        <div class="col-md-1">
                            <span class="badge text-bg-secondary fs-6">
                                <?php echo $label; ?>
                            </span>
                        </div>
                        <div class="col-md-6">
                            <input type="text" name="choice_text[<?php echo $label; ?>]" class="form-control"
                                placeholder="Teks pilihan <?php echo $label; ?>" required
                                value="<?php echo htmlspecialchars($choiceInput[$label]['text'] ?? ''); ?>">
                        </div>
                        <div class="col-md-2">
                            <input type="number" name="points[<?php echo $label; ?>]" class="form-control" min="0"
                                placeholder="Poin"
                                value="<?php echo (int) ($choiceInput[$label]['points'] ?? 0); ?>">
                        </div>
                        <div class="col-md-3">
                            <div class="form-check">
                                <input class="form-check-input correct-checkbox" type="checkbox"
                                    name="is_correct[<?php echo $label; ?>]" id="correct<?php echo $label; ?>"
                                    value="1" <?php echo !empty($choiceInput[$label]['is_correct']) ? 'checked' : ''; ?>>
                                <label class="form-check-label" for="correct<?php echo $label; ?>">
                                    Jawaban Benar
                                </label>
                            </div>
                        </div>
                    </div>
                <?php endforeach; ?>
                <hr class="my-4">
                <div class="d-flex gap-2 align-items-center">
                    <?php if (admin_can_write()): ?>
                        <button type="submit" class="btn btn-primary">
                            Save Changes
                        </button>
                    <?php else: ?>
                        <span class="text-muted">Akun read-only &mdash; perubahan tidak bisa disimpan.</span>
                    <?php endif; ?>
                    <a href="competency_questions.php?competency_id=<?php echo $competencyId; ?>"
                        class="btn btn-outline-secondary">
                        Cancel
                    </a>
                </div>
            </form>
        </div>
    </div>
    <script>
        (function () {
            var allowMultipleCheckbox = document.getElementById('allowMultiple');
            var correctCheckboxes = document.querySelectorAll('.correct-checkbox');
            correctCheckboxes.forEach(function (cb) {
                cb.addEventListener('change', function () {
                    if (allowMultipleCheckbox.checked) {
                        return;
                    }
                    if (cb.checked) {
                        correctCheckboxes.forEach(function (other) {
                            if (other !== cb) {
                                other.checked = false;
                            }
                        });
                    }
                });
            });
        })();
    </script>
</body>

</html>
