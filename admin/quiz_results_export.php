<?php
require_once "auth.php";
require_once "../config/database.php";
require_once "../includes/competency_helper.php";
/*
|--------------------------------------------------------------------------
| Export hasil quiz -- mengikuti filter yang sedang aktif di quiz_results.php
|--------------------------------------------------------------------------
*/
$filterCompetencyId = isset($_GET['competency_id']) ? (int) $_GET['competency_id'] : 0;
$quizStatusFilter = isset($_GET['quiz_status']) ? trim($_GET['quiz_status']) : '';
if (!in_array($quizStatusFilter, ['passed', 'failed'], true)) {
    $quizStatusFilter = '';
}
$quizSearch = isset($_GET['quiz_search']) ? trim($_GET['quiz_search']) : '';

$quizConditions = ["e.is_deleted = 0", "ec.is_active = 1", "ec.quiz_submitted_at IS NOT NULL"];
$quizParams = [];
$quizTypes = "";
if ($filterCompetencyId > 0) {
    $quizConditions[] = "ec.competency_id = ?";
    $quizParams[] = $filterCompetencyId;
    $quizTypes .= "i";
}
if ($quizStatusFilter === 'passed') {
    $quizConditions[] = "ec.status != 'FAILED'";
} elseif ($quizStatusFilter === 'failed') {
    $quizConditions[] = "ec.status = 'FAILED'";
}
if ($quizSearch !== '') {
    $quizConditions[] = "(e.nik LIKE ? OR e.name LIKE ? OR e.department LIKE ?)";
    $quizSearchLike = '%' . $quizSearch . '%';
    $quizParams[] = $quizSearchLike;
    $quizParams[] = $quizSearchLike;
    $quizParams[] = $quizSearchLike;
    $quizTypes .= "sss";
}
$quizWhereSql = implode(' AND ', $quizConditions);

$query = "
    SELECT
        e.nik,
        e.name AS employee_name,
        e.department,
        c.name AS competency_name,
        ec.score,
        c.passing_score,
        ec.status,
        ec.quiz_submitted_at
    FROM employee_competencies ec
    INNER JOIN employees e ON e.id = ec.employee_id
    INNER JOIN competencies c ON c.id = ec.competency_id
    WHERE {$quizWhereSql}
    ORDER BY ec.quiz_submitted_at DESC
";
$stmt = mysqli_prepare($conn, $query);
if (!empty($quizParams)) {
    mysqli_stmt_bind_param($stmt, $quizTypes, ...$quizParams);
}
mysqli_stmt_execute($stmt);
$result = mysqli_stmt_get_result($stmt);
/*
|--------------------------------------------------------------------------
| Output CSV
|--------------------------------------------------------------------------
*/
header("Content-Type: text/csv; charset=utf-8");
header("Content-Disposition: attachment; filename=quiz_results_" . date('Ymd_His') . ".csv");
header("Pragma: no-cache");

$output = fopen('php://output', 'w');
fputs($output, "\xEF\xBB\xBF");
fputcsv($output, [
    'nik', 'name', 'department', 'competency', 'score', 'passing_score', 'status', 'quiz_submitted_at',
]);
while ($row = mysqli_fetch_assoc($result)) {
    $isPassed = $row['status'] !== 'FAILED';
    fputcsv($output, [
        csvSafeValue($row['nik']),
        csvSafeValue($row['employee_name']),
        csvSafeValue($row['department']),
        csvSafeValue($row['competency_name']),
        csvSafeValue((string) $row['score']),
        csvSafeValue((string) $row['passing_score']),
        $isPassed ? 'Lulus' : 'Gagal',
        $row['quiz_submitted_at'],
    ]);
}
fclose($output);
exit;
