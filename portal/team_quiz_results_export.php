<?php
require_once "../includes/portal_auth.php";
portal_require_view('team_quiz_results');
require_once "../includes/competency_helper.php";
/*
|--------------------------------------------------------------------------
| Export hasil quiz tim -- mengikuti filter aktif di team_quiz_results.php
|--------------------------------------------------------------------------
*/
$scopeNiks = portal_scope_niks($conn);
[$scopeClause, $scopeParams] = portal_scope_where($scopeNiks);

$filterCompetencyId = isset($_GET['competency_id']) ? (int) $_GET['competency_id'] : 0;
$quizStatusFilter = isset($_GET['quiz_status']) ? trim($_GET['quiz_status']) : '';
if (!in_array($quizStatusFilter, ['passed', 'failed'], true)) {
    $quizStatusFilter = '';
}
$quizSearch = isset($_GET['quiz_search']) ? trim($_GET['quiz_search']) : '';

$competencyClause = $filterCompetencyId > 0 ? " AND ec.competency_id = ?" : "";
$quizStatusClause = '';
if ($quizStatusFilter === 'passed') {
    $quizStatusClause = " AND ec.status != 'FAILED'";
} elseif ($quizStatusFilter === 'failed') {
    $quizStatusClause = " AND ec.status = 'FAILED'";
}
$quizSearchClause = '';
$quizSearchParams = [];
$quizSearchTypes = '';
if ($quizSearch !== '') {
    $quizSearchClause = " AND (e.nik LIKE ? OR e.name LIKE ? OR e.department LIKE ?)";
    $quizSearchLike = '%' . $quizSearch . '%';
    $quizSearchParams = [$quizSearchLike, $quizSearchLike, $quizSearchLike];
    $quizSearchTypes = 'sss';
}

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
    WHERE e.is_deleted = 0 AND ec.is_active = 1 AND ec.quiz_submitted_at IS NOT NULL
        {$scopeClause}{$competencyClause}{$quizStatusClause}{$quizSearchClause}
    ORDER BY ec.quiz_submitted_at DESC
";
$params = $filterCompetencyId > 0 ? array_merge($scopeParams, [$filterCompetencyId]) : $scopeParams;
$params = array_merge($params, $quizSearchParams);
$types = str_repeat('s', count($scopeParams)) . ($filterCompetencyId > 0 ? 'i' : '') . $quizSearchTypes;
$stmt = mysqli_prepare($conn, $query);
if (!empty($params)) {
    mysqli_stmt_bind_param($stmt, $types, ...$params);
}
mysqli_stmt_execute($stmt);
$result = mysqli_stmt_get_result($stmt);
/*
|--------------------------------------------------------------------------
| Output CSV
|--------------------------------------------------------------------------
*/
header("Content-Type: text/csv; charset=utf-8");
header("Content-Disposition: attachment; filename=team_quiz_results_" . date('Ymd_His') . ".csv");
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
