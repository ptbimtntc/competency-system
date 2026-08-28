<?php
require_once "config/database.php";
require_once "includes/competency_helper.php";
/*
|--------------------------------------------------------------------------
| Ambil data employee competency
|--------------------------------------------------------------------------
*/
$id = isset($_GET['id'])
    ? (int) $_GET['id']
    : 0;
if ($id <= 0) {
    die("Data kompetensi tidak valid.");
}
$query = "
    SELECT
        ec.id,
        ec.competency_id,
        ec.scheduled_training_date,
        ec.attendance_confirmed,
        ec.quiz_submitted_at,
        e.nik,
        e.name AS employee_name,
        c.name AS competency_name
    FROM employee_competencies ec
    INNER JOIN employees e ON e.id = ec.employee_id
    INNER JOIN competencies c ON c.id = ec.competency_id
    WHERE ec.id = ?
        AND e.is_deleted = 0
    LIMIT 1
";
$stmt = mysqli_prepare($conn, $query);
mysqli_stmt_bind_param($stmt, "i", $id);
mysqli_stmt_execute($stmt);
$data = mysqli_fetch_assoc(mysqli_stmt_get_result($stmt));
if (!$data) {
    die("Data kompetensi tidak ditemukan.");
}
$eligibility = getQuizEligibility($conn, $data);
$questions = [];
if ($eligibility['eligible']) {
    $questionStmt = mysqli_prepare(
        $conn,
        "SELECT id, question_text, allow_multiple_answers FROM competency_questions
        WHERE competency_id = ? ORDER BY id ASC"
    );
    mysqli_stmt_bind_param($questionStmt, "i", $data['competency_id']);
    mysqli_stmt_execute($questionStmt);
    $questionResult = mysqli_stmt_get_result($questionStmt);
    while ($q = mysqli_fetch_assoc($questionResult)) {
        $choiceStmt = mysqli_prepare(
            $conn,
            "SELECT id, option_label, choice_text FROM competency_question_choices
            WHERE question_id = ? ORDER BY option_label ASC"
        );
        mysqli_stmt_bind_param($choiceStmt, "i", $q['id']);
        mysqli_stmt_execute($choiceStmt);
        $choiceResult = mysqli_stmt_get_result($choiceStmt);
        $choices = [];
        while ($c = mysqli_fetch_assoc($choiceResult)) {
            $choices[] = $c;
        }
        $q['choices'] = $choices;
        $questions[] = $q;
    }
}
$submitError = isset($_GET['error']) && $_GET['error'] === '1';
?>

<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>
        Kuis - <?php echo htmlspecialchars($data['competency_name']); ?>
    </title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="assets/css/style.css">
</head>

<body>
    <div class="container py-4">
        <div class="verification-card">
            <div class="verification-header">
                <div class="logo">
                    <img src="assets/images/Bekaert_logo_neg_RGB.png" alt="Bekaert" class="brand-logo">
                </div>
                <div class="verification-title">
                    Kuis Kompetensi
                </div>
            </div>
            <div class="employee-profile">
                <h2>
                    <?php echo htmlspecialchars($data['employee_name']); ?>
                </h2>
                <div class="employee-position">
                    <?php echo htmlspecialchars($data['competency_name']); ?>
                </div>
            </div>
            <div class="p-4">
                <div class="back-link mb-3">
                    <a href="employee.php?id=<?php echo $id; ?>">
                        ← Kembali ke Detail Kompetensi
                    </a>
                </div>
                <?php if ($submitError): ?>
                    <div class="alert alert-danger">
                        Gagal mengirim jawaban, silakan coba lagi.
                    </div>
                <?php endif; ?>
                <?php if (!$eligibility['eligible']): ?>
                    <div class="alert alert-warning">
                        <?php
                        echo htmlspecialchars(
                            quizEligibilityMessage($eligibility['reason'], $data['scheduled_training_date'])
                        );
                        ?>
                    </div>
                <?php else: ?>
                    <form method="POST" action="quiz_submit.php" id="quizForm">
                        <input type="hidden" name="id" value="<?php echo $id; ?>">
                        <?php foreach ($questions as $index => $question): ?>
                            <div class="quiz-question-card mb-4">
                                <div class="quiz-question-text mb-3">
                                    <strong><?php echo ($index + 1) . ". "; ?></strong>
                                    <?php echo nl2br(htmlspecialchars($question['question_text'])); ?>
                                    <?php if ((int) $question['allow_multiple_answers'] === 1): ?>
                                        <span class="badge text-bg-info ms-2">Boleh pilih lebih dari 1</span>
                                    <?php endif; ?>
                                </div>
                                <?php foreach ($question['choices'] as $choice): ?>
                                    <div class="form-check mb-2">
                                        <input class="form-check-input" type="<?php
                                            echo (int) $question['allow_multiple_answers'] === 1 ? 'checkbox' : 'radio';
                                            ?>" name="answers[<?php echo $question['id']; ?>]<?php
                                            echo (int) $question['allow_multiple_answers'] === 1 ? '[]' : '';
                                            ?>" id="choice<?php echo $choice['id']; ?>" value="<?php echo $choice['id']; ?>">
                                        <label class="form-check-label" for="choice<?php echo $choice['id']; ?>">
                                            <strong><?php echo htmlspecialchars($choice['option_label']); ?>.</strong>
                                            <?php echo htmlspecialchars($choice['choice_text']); ?>
                                        </label>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        <?php endforeach; ?>
                        <button type="submit" class="btn btn-primary w-100"
                            onclick="return confirm('Jawaban tidak dapat diubah setelah dikirim. Lanjutkan?');">
                            Kirim Jawaban
                        </button>
                    </form>
                <?php endif; ?>
            </div>
        </div>
    </div>
</body>

</html>
