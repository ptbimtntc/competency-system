<?php
require_once "auth.php";
require_once "../config/database.php";
require_once "../includes/competency_helper.php";
/*
|--------------------------------------------------------------------------
| Export Nilai Quiz per Karyawan -- mengikuti filter yang sedang aktif di
| seksi "Nilai Quiz per Karyawan" pada dashboard.php
|--------------------------------------------------------------------------
*/
$filterCompetencyId = isset($_GET['competency_id']) ? (int) $_GET['competency_id'] : 0;

$filterTrainingDate = '';
if (isset($_GET['training_date']) && $_GET['training_date'] !== '') {
    $candidateDate = $_GET['training_date'];
    $dateParts = explode('-', $candidateDate);
    if (count($dateParts) === 3 && checkdate((int) $dateParts[1], (int) $dateParts[2], (int) $dateParts[0])) {
        $filterTrainingDate = $candidateDate;
    }
}

$quizStatusFilter = isset($_GET['quiz_status']) ? trim($_GET['quiz_status']) : '';
if (!in_array($quizStatusFilter, ['passed', 'failed'], true)) {
    $quizStatusFilter = '';
}
$quizSearch = isset($_GET['quiz_search']) ? trim($_GET['quiz_search']) : '';

$quizConditions = ["e.is_deleted = 0", "ec.is_active = 1", "ec.score IS NOT NULL"];
$quizParams = [];
$quizTypes = "";
if ($filterCompetencyId > 0) {
    $quizConditions[] = "ec.competency_id = ?";
    $quizParams[] = $filterCompetencyId;
    $quizTypes .= "i";
}
if ($filterTrainingDate !== '') {
    $quizConditions[] = "(ec.scheduled_training_date = ? OR ec.training_date = ?)";
    $quizParams[] = $filterTrainingDate;
    $quizParams[] = $filterTrainingDate;
    $quizTypes .= "ss";
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
        e.supervisor,
        c.name AS competency_name,
        ec.score,
        c.passing_score,
        ec.status,
        ec.quiz_submitted_at,
        COALESCE(ec.quiz_submitted_at, ec.training_date, ec.updated_at) AS score_date
    FROM employee_competencies ec
    INNER JOIN employees e ON e.id = ec.employee_id
    INNER JOIN competencies c ON c.id = ec.competency_id
    WHERE {$quizWhereSql}
    ORDER BY ec.score DESC, COALESCE(ec.quiz_submitted_at, ec.training_date, ec.updated_at) ASC, e.name ASC
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
header("Content-Disposition: attachment; filename=nilai_quiz_karyawan_" . date('Ymd_His') . ".csv");
header("Pragma: no-cache");

$output = fopen('php://output', 'w');
fputs($output, "\xEF\xBB\xBF");
fputcsv($output, [
    'nik', 'name', 'department', 'supervisor', 'competency', 'score', 'passing_score',
    'status', 'source', 'score_date',
]);
while ($row = mysqli_fetch_assoc($result)) {
    $isPassed = $row['status'] !== 'FAILED';
    $fromQuiz = !empty($row['quiz_submitted_at']);
    fputcsv($output, [
        csvSafeValue($row['nik']),
        csvSafeValue($row['employee_name']),
        csvSafeValue($row['department']),
        csvSafeValue($row['supervisor']),
        csvSafeValue($row['competency_name']),
        $row['score'] !== null ? $row['score'] : '',
        $row['passing_score'] !== null ? $row['passing_score'] : '',
        $isPassed ? 'Lulus' : 'Gagal',
        $fromQuiz ? 'Quiz' : 'Input Manual',
        empty($row['score_date']) ? '' : date('Y-m-d', strtotime($row['score_date'])),
    ]);
}
fclose($output);
exit;
