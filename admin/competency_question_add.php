<?php
require_once "auth.php";
require_once "../config/database.php";
require_writer();
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
$error = "";
$questionText = "";
$allowMultiple = false;
$choiceInput = [
    'A' => ['text' => '', 'is_correct' => false, 'points' => 0],
    'B' => ['text' => '', 'is_correct' => false, 'points' => 0],
    'C' => ['text' => '', 'is_correct' => false, 'points' => 0],
    'D' => ['text' => '', 'is_correct' => false, 'points' => 0],
];
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
            $insertQuestionStmt = mysqli_prepare(
                $conn,
                "INSERT INTO competency_questions (competency_id, question_text, allow_multiple_answers)
                VALUES (?, ?, ?)"
            );
            $allowMultipleInt = $allowMultiple ? 1 : 0;
            mysqli_stmt_bind_param(
                $insertQuestionStmt,
                "isi",
                $competencyId,
                $questionText,
                $allowMultipleInt
            );
            mysqli_stmt_execute($insertQuestionStmt);
            $questionId = mysqli_insert_id($conn);

            $insertChoiceStmt = mysqli_prepare(
                $conn,
                "INSERT INTO competency_question_choices (question_id, option_label, choice_text, is_correct, points)
                VALUES (?, ?, ?, ?, ?)"
            );
            foreach (['A', 'B', 'C', 'D'] as $label) {
                $isCorrectInt = $choiceInput[$label]['is_correct'] ? 1 : 0;
                $points = $choiceInput[$label]['points'];
                mysqli_stmt_bind_param(
                    $insertChoiceStmt,
                    "issii",
                    $questionId,
                    $label,
                    $choiceInput[$label]['text'],
                    $isCorrectInt,
                    $points
                );
                mysqli_stmt_execute($insertChoiceStmt);
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
        Add Question - <?php echo htmlspecialchars($competency['name']); ?>
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
                    Add Question
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
                                value="<?php echo htmlspecialchars($choiceInput[$label]['text']); ?>">
                        </div>
                        <div class="col-md-2">
                            <input type="number" name="points[<?php echo $label; ?>]" class="form-control" min="0"
                                placeholder="Poin"
                                value="<?php echo (int) $choiceInput[$label]['points']; ?>">
                        </div>
                        <div class="col-md-3">
                            <div class="form-check">
                                <input class="form-check-input correct-checkbox" type="checkbox"
                                    name="is_correct[<?php echo $label; ?>]" id="correct<?php echo $label; ?>"
                                    value="1" <?php echo $choiceInput[$label]['is_correct'] ? 'checked' : ''; ?>>
                                <label class="form-check-label" for="correct<?php echo $label; ?>">
                                    Jawaban Benar
                                </label>
                            </div>
                        </div>
                    </div>
                <?php endforeach; ?>
                <hr class="my-4">
                <div class="d-flex gap-2">
                    <button type="submit" class="btn btn-primary">
                        Save Question
                    </button>
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
            function enforceSingleAnswer() {
                if (allowMultipleCheckbox.checked) {
                    return;
                }
                correctCheckboxes.forEach(function (cb) {
                    cb.addEventListener('change', function () {
                        if (cb.checked) {
                            correctCheckboxes.forEach(function (other) {
                                if (other !== cb) {
                                    other.checked = false;
                                }
                            });
                        }
                    });
                });
            }
            enforceSingleAnswer();
        })();
    </script>
</main>
</div>
</body>

</html>
