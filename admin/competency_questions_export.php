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

$query = "
    SELECT
        cq.id AS question_id,
        cq.question_text,
        cq.allow_multiple_answers,
        cqc.option_label,
        cqc.choice_text,
        cqc.is_correct,
        cqc.points
    FROM competency_questions cq
    INNER JOIN competency_question_choices cqc ON cqc.question_id = cq.id
    WHERE cq.competency_id = ?
    ORDER BY cq.id ASC, cqc.option_label ASC
";
$stmt = mysqli_prepare($conn, $query);
mysqli_stmt_bind_param($stmt, "i", $competencyId);
mysqli_stmt_execute($stmt);
$result = mysqli_stmt_get_result($stmt);

$safeName = preg_replace('/[^A-Za-z0-9_\-]/', '_', $competency['name']);
header("Content-Type: text/csv; charset=utf-8");
header("Content-Disposition: attachment; filename=questions_{$safeName}_" . date('Ymd_His') . ".csv");
header("Pragma: no-cache");

$output = fopen('php://output', 'w');
fputs($output, "\xEF\xBB\xBF");
fputcsv($output, [
    'question_id',
    'question_text',
    'allow_multiple_answers',
    'option_label',
    'option_text',
    'is_correct',
    'points',
]);
while ($row = mysqli_fetch_assoc($result)) {
    fputcsv($output, [
        $row['question_id'],
        csvSafeValue($row['question_text']),
        $row['allow_multiple_answers'],
        $row['option_label'],
        csvSafeValue($row['choice_text']),
        $row['is_correct'],
        $row['points'],
    ]);
}
fclose($output);
exit;
