<?php
require_once "auth.php";
require_once "../config/database.php";
require_once "../includes/competency_helper.php";
/*
|--------------------------------------------------------------------------
| Filter (mengikuti filter aktif di halaman Competency Matrix)
|--------------------------------------------------------------------------
*/
$allowedTeams = ['A', 'B', 'C', 'D', 'NS'];
$team = strtoupper(trim($_GET['team'] ?? ''));
if (!in_array($team, $allowedTeams, true)) {
    $team = '';
}
$department = trim($_GET['department'] ?? '');
$supervisor = trim($_GET['supervisor'] ?? '');
$search = trim($_GET['search'] ?? '');
/*
|--------------------------------------------------------------------------
| Kolom (competency) & baris (employee)
|--------------------------------------------------------------------------
*/
$competencyResult = mysqli_query(
    $conn,
    "SELECT id, name FROM competencies ORDER BY name ASC"
);
$competencies = [];
while ($row = mysqli_fetch_assoc($competencyResult)) {
    $competencies[] = $row;
}

$conditions = ["is_deleted = 0"];
$params = [];
$types = "";
if ($team !== '') {
    $conditions[] = "team = ?";
    $params[] = $team;
    $types .= "s";
}
if ($department !== '') {
    $conditions[] = "department = ?";
    $params[] = $department;
    $types .= "s";
}
if ($supervisor !== '') {
    $conditions[] = "supervisor = ?";
    $params[] = $supervisor;
    $types .= "s";
}
if ($search !== '') {
    $conditions[] = "(nik LIKE ? OR name LIKE ?)";
    $keyword = "%" . $search . "%";
    $params[] = $keyword;
    $params[] = $keyword;
    $types .= "ss";
}
$employeeQuery = "SELECT id, nik, name, team, department, position FROM employees";
if (count($conditions) > 0) {
    $employeeQuery .= " WHERE " . implode(" AND ", $conditions);
}
$employeeQuery .= " ORDER BY name ASC";
$employeeStmt = mysqli_prepare($conn, $employeeQuery);
if (count($params) > 0) {
    mysqli_stmt_bind_param($employeeStmt, $types, ...$params);
}
mysqli_stmt_execute($employeeStmt);
$employeeResult = mysqli_stmt_get_result($employeeStmt);
$employees = [];
while ($row = mysqli_fetch_assoc($employeeResult)) {
    $employees[] = $row;
}
/*
|--------------------------------------------------------------------------
| Peta status
|--------------------------------------------------------------------------
*/
$statusMap = [];
$assignmentResult = mysqli_query(
    $conn,
    "SELECT
        ec.employee_id,
        ec.competency_id,
        ec.training_date,
        ec.scheduled_training_date,
        ec.expiry_date,
        ec.score,
        c.passing_score
    FROM employee_competencies ec
    INNER JOIN competencies c ON c.id = ec.competency_id
    WHERE ec.is_active = 1"
);
while ($row = mysqli_fetch_assoc($assignmentResult)) {
    $status = calculateCompetencyStatusWithSchedule(
        $row['training_date'],
        $row['expiry_date'],
        $row['scheduled_training_date']
    );
    $status = applyPassingScoreGate(
        $status,
        $row['score'] !== null ? (int) $row['score'] : null,
        $row['passing_score'] !== null ? (int) $row['passing_score'] : null
    );
    $statusMap[(int) $row['employee_id']][(int) $row['competency_id']] = $status;
}
/*
|--------------------------------------------------------------------------
| Output CSV (format lebar: 1 kolom per competency)
|--------------------------------------------------------------------------
*/
header("Content-Type: text/csv; charset=utf-8");
header("Content-Disposition: attachment; filename=competency_matrix_" . date('Ymd_His') . ".csv");
header("Pragma: no-cache");

$output = fopen('php://output', 'w');
fputs($output, "\xEF\xBB\xBF");

$headerRow = ['nik', 'name', 'team', 'department', 'position'];
foreach ($competencies as $competency) {
    $headerRow[] = csvSafeValue($competency['name']);
}
fputcsv($output, $headerRow);

foreach ($employees as $employee) {
    $line = [
        csvSafeValue($employee['nik']),
        csvSafeValue($employee['name']),
        csvSafeValue($employee['team']),
        csvSafeValue($employee['department']),
        csvSafeValue($employee['position']),
    ];
    foreach ($competencies as $competency) {
        $cellStatus = $statusMap[(int) $employee['id']][(int) $competency['id']] ?? '';
        $line[] = $cellStatus !== '' ? competencyStatusLabel($cellStatus) : '';
    }
    fputcsv($output, $line);
}
fclose($output);
exit;
