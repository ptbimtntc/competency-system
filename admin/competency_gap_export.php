<?php
require_once "auth.php";
require_once "../config/database.php";
require_once "../includes/competency_helper.php";
/*
|--------------------------------------------------------------------------
| Filter (mengikuti filter aktif di halaman Competency Gap)
|--------------------------------------------------------------------------
*/
$allowedTeams = ['A', 'B', 'C', 'D', 'NS'];
$team = strtoupper(trim($_GET['team'] ?? ''));
if (!in_array($team, $allowedTeams, true)) {
    $team = '';
}
$department = trim($_GET['department'] ?? '');
$supervisor = trim($_GET['supervisor'] ?? '');
$position = trim($_GET['position'] ?? '');
$search = trim($_GET['search'] ?? '');
$onlyGaps = ($_GET['only_gaps'] ?? '1') !== '0';
/*
|--------------------------------------------------------------------------
| Required competency per posisi
|--------------------------------------------------------------------------
*/
$requirementsByPosition = [];
try {
    $reqStmt = mysqli_prepare($conn, "SELECT position, competency_id FROM position_requirements");
    if ($reqStmt !== false) {
        mysqli_stmt_execute($reqStmt);
        $reqResult = mysqli_stmt_get_result($reqStmt);
        while ($row = mysqli_fetch_assoc($reqResult)) {
            $requirementsByPosition[$row['position']][] = (int) $row['competency_id'];
        }
    }
} catch (\Throwable $e) {
    // tabel belum ada -> laporan kosong
}
$competencyMeta = [];
$metaResult = mysqli_query($conn, "SELECT id, code, name, passing_score FROM competencies");
while ($row = mysqli_fetch_assoc($metaResult)) {
    $competencyMeta[(int) $row['id']] = $row;
}
/*
|--------------------------------------------------------------------------
| Employee (difilter)
|--------------------------------------------------------------------------
*/
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
if ($position !== '') {
    $conditions[] = "position = ?";
    $params[] = $position;
    $types .= "s";
}
if ($search !== '') {
    $conditions[] = "(nik LIKE ? OR name LIKE ?)";
    $keyword = "%" . $search . "%";
    $params[] = $keyword;
    $params[] = $keyword;
    $types .= "ss";
}
$employeeQuery = "SELECT id, nik, name, team, department, position, supervisor FROM employees";
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
        ec.employee_id, ec.competency_id, ec.training_date, ec.scheduled_training_date,
        ec.expiry_date, ec.score, c.passing_score
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
| Output CSV
|--------------------------------------------------------------------------
*/
header("Content-Type: text/csv; charset=utf-8");
header("Content-Disposition: attachment; filename=competency_gap_" . date('Ymd_His') . ".csv");
header("Pragma: no-cache");

$output = fopen('php://output', 'w');
fputs($output, "\xEF\xBB\xBF");
fputcsv($output, [
    'nik',
    'name',
    'team',
    'department',
    'position',
    'supervisor',
    'competency_code',
    'competency_name',
    'current_status',
    'is_gap',
]);

$statusLabelMap = [
    'VALID' => 'Valid',
    'EXPIRING_SOON' => 'Expiring Soon',
    'EXPIRED' => 'Expired',
    'FAILED' => 'Failed',
    'ASSIGNED' => 'Assigned',
    'NOT_TAKEN' => 'Not Taken',
];

foreach ($employees as $employee) {
    $requiredIds = $requirementsByPosition[$employee['position']] ?? [];
    foreach ($requiredIds as $competencyId) {
        $meta = $competencyMeta[$competencyId] ?? null;
        if ($meta === null) {
            continue;
        }
        $status = $statusMap[(int) $employee['id']][$competencyId] ?? '';
        $isCompliant = ($status === 'VALID' || $status === 'EXPIRING_SOON');
        if ($onlyGaps && $isCompliant) {
            continue;
        }
        fputcsv($output, [
            csvSafeValue($employee['nik']),
            csvSafeValue($employee['name']),
            csvSafeValue($employee['team']),
            csvSafeValue($employee['department']),
            csvSafeValue($employee['position']),
            csvSafeValue($employee['supervisor']),
            csvSafeValue($meta['code']),
            csvSafeValue($meta['name']),
            $status !== '' ? ($statusLabelMap[$status] ?? $status) : 'Belum ada data',
            $isCompliant ? 'no' : 'yes',
        ]);
    }
}
fclose($output);
exit;
