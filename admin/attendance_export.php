<?php
require_once "auth.php";
require_once "../config/database.php";
require_once "../includes/competency_helper.php";
/*
|--------------------------------------------------------------------------
| Export roster kehadiran sesi training yang sedang dipilih di attendance.php
|--------------------------------------------------------------------------
*/
$competencyId = isset($_GET['competency_id']) ? (int) $_GET['competency_id'] : 0;
$scheduledDate = trim($_GET['scheduled_date'] ?? '');
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $scheduledDate) || $competencyId <= 0) {
    die("Pilih competency dan tanggal sesi training terlebih dahulu di halaman Attendance.");
}
$competencyNameStmt = mysqli_prepare($conn, "SELECT name FROM competencies WHERE id = ? LIMIT 1");
mysqli_stmt_bind_param($competencyNameStmt, "i", $competencyId);
mysqli_stmt_execute($competencyNameStmt);
$competencyRow = mysqli_fetch_assoc(mysqli_stmt_get_result($competencyNameStmt));
if (!$competencyRow) {
    die("Competency tidak ditemukan.");
}

$employeeResult = mysqli_query(
    $conn,
    "SELECT id, nik, name, department, team FROM employees WHERE is_deleted = 0 ORDER BY name ASC"
);
$employees = [];
while ($row = mysqli_fetch_assoc($employeeResult)) {
    $employees[] = $row;
}

$assignedStmt = mysqli_prepare(
    $conn,
    "SELECT employee_id, scheduled_training_date, attendance_confirmed
    FROM employee_competencies
    WHERE competency_id = ? AND is_active = 1"
);
mysqli_stmt_bind_param($assignedStmt, "i", $competencyId);
mysqli_stmt_execute($assignedStmt);
$assignedResult = mysqli_stmt_get_result($assignedStmt);
$assignedRows = [];
while ($row = mysqli_fetch_assoc($assignedResult)) {
    $assignedRows[(int) $row['employee_id']] = $row;
}
/*
|--------------------------------------------------------------------------
| Output CSV
|--------------------------------------------------------------------------
*/
header("Content-Type: text/csv; charset=utf-8");
header("Content-Disposition: attachment; filename=attendance_" . $scheduledDate . "_" . date('Ymd_His') . ".csv");
header("Pragma: no-cache");

$output = fopen('php://output', 'w');
fputs($output, "\xEF\xBB\xBF");
fputcsv($output, ['nik', 'name', 'department', 'team', 'competency', 'scheduled_training_date', 'assigned', 'hadir']);
foreach ($employees as $employee) {
    $row = $assignedRows[(int) $employee['id']] ?? null;
    $isThisSession = $row !== null && $row['scheduled_training_date'] === $scheduledDate;
    fputcsv($output, [
        csvSafeValue($employee['nik']),
        csvSafeValue($employee['name']),
        csvSafeValue($employee['department']),
        csvSafeValue($employee['team']),
        csvSafeValue($competencyRow['name']),
        $scheduledDate,
        $row !== null ? 'yes' : 'no',
        $isThisSession && (int) $row['attendance_confirmed'] === 1 ? 'yes' : 'no',
    ]);
}
fclose($output);
exit;
