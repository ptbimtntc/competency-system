<?php
require_once "auth.php";
require_once "../config/database.php";
require_once "pagination.php";
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
/*
|--------------------------------------------------------------------------
| Hitung total + pagination
|--------------------------------------------------------------------------
*/
$countStmt = mysqli_prepare(
    $conn,
    "SELECT COUNT(*) AS total FROM competency_questions WHERE competency_id = ?"
);
mysqli_stmt_bind_param($countStmt, "i", $competencyId);
mysqli_stmt_execute($countStmt);
$totalRows = (int) mysqli_fetch_assoc(mysqli_stmt_get_result($countStmt))['total'];
$pg = paginate($totalRows, 25);
/*
|--------------------------------------------------------------------------
| Ambil soal + pilihan jawaban
|--------------------------------------------------------------------------
*/
$questionStmt = mysqli_prepare(
    $conn,
    "SELECT id, question_text, allow_multiple_answers FROM competency_questions
    WHERE competency_id = ? ORDER BY id ASC LIMIT ? OFFSET ?"
);
mysqli_stmt_bind_param($questionStmt, "iii", $competencyId, $pg['per_page'], $pg['offset']);
mysqli_stmt_execute($questionStmt);
$questionResult = mysqli_stmt_get_result($questionStmt);
$questions = [];
while ($q = mysqli_fetch_assoc($questionResult)) {
    $questions[] = $q;
}

$paginationBaseParams = array_filter([
    'competency_id' => $competencyId,
], function ($value) {
    return $value !== null && $value !== '';
});
$choicesByQuestion = [];
if (count($questions) > 0) {
    $questionIds = array_column($questions, 'id');
    $placeholders = implode(',', array_fill(0, count($questionIds), '?'));
    $types = str_repeat('i', count($questionIds));
    $choiceStmt = mysqli_prepare(
        $conn,
        "SELECT question_id, option_label, choice_text, is_correct, points
        FROM competency_question_choices
        WHERE question_id IN ({$placeholders})
        ORDER BY question_id ASC, option_label ASC"
    );
    mysqli_stmt_bind_param($choiceStmt, $types, ...$questionIds);
    mysqli_stmt_execute($choiceStmt);
    $choiceResult = mysqli_stmt_get_result($choiceStmt);
    while ($c = mysqli_fetch_assoc($choiceResult)) {
        $choicesByQuestion[$c['question_id']][] = $c;
    }
}
?>

<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>
        Questions - <?php echo htmlspecialchars($competency['name']); ?>
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
                    Questions
                </h1>
                <p>
                    Bank soal kuis untuk kompetensi
                    <strong><?php echo htmlspecialchars($competency['name']); ?></strong>
                </p>
            </div>
            <div class="d-flex gap-2">
                <a href="competencies.php" class="btn btn-outline-secondary">
                    &larr; Back to Competencies
                </a>
                <a href="competency_questions_export.php?competency_id=<?php echo $competencyId; ?>"
                    class="btn btn-outline-secondary">
                    Export CSV
                </a>
                <?php if (admin_can_write()): ?>
                    <a href="competency_questions_import.php?competency_id=<?php echo $competencyId; ?>"
                        class="btn btn-outline-secondary">
                        Import CSV
                    </a>
                    <a href="competency_question_add.php?competency_id=<?php echo $competencyId; ?>"
                        class="btn btn-primary">
                        + Add Question
                    </a>
                <?php endif; ?>
            </div>
        </div>
        <div class="employee-table-card">
            <div class="table-responsive">
                <table class="table table-hover align-middle mb-0">
                    <thead>
                        <tr>
                            <th>
                                #
                            </th>
                            <th>
                                Question
                            </th>
                            <th>
                                Type
                            </th>
                            <th>
                                Correct Answer
                            </th>
                            <th>
                                Max Points
                            </th>
                            <th>
                                Action
                            </th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (count($questions) > 0): ?>
                            <?php $number = $pg['offset'] + 1; ?>
                            <?php foreach ($questions as $question): ?>
                                <?php
                                $choices = $choicesByQuestion[$question['id']] ?? [];
                                $correctLabels = [];
                                $maxPoints = 0;
                                foreach ($choices as $choice) {
                                    if ((int) $choice['is_correct'] === 1) {
                                        $correctLabels[] = $choice['option_label'];
                                        $maxPoints += (int) $choice['points'];
                                    }
                                }
                                ?>
                                <tr>
                                    <td>
                                        <?php echo $number++; ?>
                                    </td>
                                    <td>
                                        <?php echo htmlspecialchars($question['question_text']); ?>
                                    </td>
                                    <td>
                                        <?php if ((int) $question['allow_multiple_answers'] === 1): ?>
                                            <span class="badge text-bg-info">Multiple</span>
                                        <?php else: ?>
                                            <span class="badge text-bg-secondary">Single</span>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <?php echo htmlspecialchars(implode(', ', $correctLabels)); ?>
                                    </td>
                                    <td>
                                        <?php echo $maxPoints; ?>
                                    </td>
                                    <td>
                                        <div class="employee-actions">
                                            <a href="competency_question_edit.php?id=<?php echo $question['id']; ?>"
                                                class="btn btn-sm btn-outline-primary">
                                                <?php echo admin_can_write() ? 'Edit' : 'Detail'; ?>
                                            </a>
                                            <?php if (admin_can_write()): ?>
                                                <form method="POST" action="competency_question_delete.php" class="d-inline"
                                                    onsubmit="return confirm('Apakah Anda yakin ingin menghapus soal ini?');">
                                                    <?php echo csrf_input(); ?>
                                                    <input type="hidden" name="id" value="<?php echo $question['id']; ?>">
                                                    <button type="submit" class="btn btn-sm btn-outline-danger">
                                                        Delete
                                                    </button>
                                                </form>
                                            <?php endif; ?>
                                        </div>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        <?php else: ?>
                            <tr>
                                <td colspan="6" class="text-center py-5">
                                    Belum ada soal untuk kompetensi ini.
                                </td>
                            </tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
            <?php if ($totalRows > 0): ?>
                <div class="d-flex flex-wrap justify-content-between align-items-center gap-2 p-3 border-top">
                    <span class="text-muted" style="font-size:13px;">
                        Menampilkan
                        <?php echo $pg['offset'] + 1; ?>&ndash;<?php echo min($pg['offset'] + $pg['per_page'], $totalRows); ?>
                        dari <?php echo $totalRows; ?> soal
                    </span>
                    <?php echo render_pagination($pg, $paginationBaseParams); ?>
                </div>
            <?php endif; ?>
        </div>
    </div>
</main>
</div>
</body>

</html>
