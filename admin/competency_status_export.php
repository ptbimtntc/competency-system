<?php
require_once "auth.php";
require_once "../config/database.php";
require_once "../includes/competency_helper.php";
/*
|--------------------------------------------------------------------------
| Export daftar competency per status -- mengikuti filter yang sedang aktif
| di competency_status.php (atau kartu statistik di dashboard)
|--------------------------------------------------------------------------
*/
$allowedStatuses = ['VALID', 'EXPIRING_SOON', 'EXPIRED', 'NOT_TAKEN', 'ASSIGNED', 'FAILED'];
$status = strtoupper(trim($_GET['status'] ?? ''));
if (!in_array($status, $allowedStatuses, true)) {
    $status = 'EXPIRED';
}

$search = trim($_GET['search'] ?? '');
$competencyFilter = isset($_GET['competency_id']) ? (int) $_GET['competency_id'] : 0;
$teamFilter = trim($_GET['team'] ?? '');
$allowedTeams = ['A', 'B', 'C', 'D', 'NS'];
if (!in_array($teamFilter, $allowedTeams, true)) {
    $teamFilter = '';
}
$supervisorFilter = trim($_GET['supervisor'] ?? '');
$attendanceFilter = trim($_GET['attendance'] ?? '');
if ($status !== 'ASSIGNED' || !in_array($attendanceFilter, ['confirmed', 'not_confirmed'], true)) {
    $attendanceFilter = '';
}

$conditions = ["ec.status = ?", "ec.is_active = 1", "e.is_deleted = 0"];
$params = [$status];
$types = "s";
if ($search !== '') {
    $conditions[] = "(e.nik LIKE ? OR e.name LIKE ? OR e.department LIKE ?)";
    $keyword = "%" . $search . "%";
    array_push($params, $keyword, $keyword, $keyword);
    $types .= "sss";
}
if ($competencyFilter > 0) {
    $conditions[] = "c.id = ?";
    $params[] = $competencyFilter;
    $types .= "i";
}
if ($teamFilter !== '') {
    $conditions[] = "e.team = ?";
    $params[] = $teamFilter;
    $types .= "s";
}
if ($supervisorFilter !== '') {
    $conditions[] = "e.supervisor = ?";
    $params[] = $supervisorFilter;
    $types .= "s";
}
if ($attendanceFilter === 'confirmed') {
    $conditions[] = "ec.attendance_confirmed = 1";
} elseif ($attendanceFilter === 'not_confirmed') {
    $conditions[] = "ec.attendance_confirmed = 0";
}

$query = "
    SELECT
        ec.training_date,
        ec.scheduled_training_date,
        ec.attendance_confirmed,
        ec.expiry_date,
        ec.trainer,
        ec.certificate_number,
        e.nik,
        e.name AS employee_name,
        e.department,
        e.position,
        e.team,
        e.supervisor,
        ec.score,
        c.name AS competency_name,
        c.passing_score
    FROM employee_competencies ec
    INNER JOIN employees e ON ec.employee_id = e.id
    INNER JOIN competencies c ON ec.competency_id = c.id
    WHERE " . implode(' AND ', $conditions) . "
    ORDER BY e.supervisor ASC, e.name ASC
";
$stmt = mysqli_prepare($conn, $query);
mysqli_stmt_bind_param($stmt, $types, ...$params);
mysqli_stmt_execute($stmt);
$result = mysqli_stmt_get_result($stmt);

/*
|--------------------------------------------------------------------------
| Output CSV
|--------------------------------------------------------------------------
*/
header("Content-Type: text/csv; charset=utf-8");
header("Content-Disposition: attachment; filename=competency_" . strtolower($status) . "_" . date('Ymd_His') . ".csv");
header("Pragma: no-cache");

$output = fopen('php://output', 'w');
fputs($output, "\xEF\xBB\xBF");
fputcsv($output, [
    'supervisor', 'nik', 'name', 'department', 'position', 'team', 'competency',
    'score', 'passing_score',
    $status === 'ASSIGNED' ? 'scheduled_training_date' : 'training_date',
    $status === 'ASSIGNED' ? 'attendance' : 'expiry_date',
    'status', 'certificate_number', 'trainer',
]);
while ($row = mysqli_fetch_assoc($result)) {
    if ($status === 'ASSIGNED') {
        $dateValue = empty($row['scheduled_training_date']) ? '' : date('Y-m-d', strtotime($row['scheduled_training_date']));
        $secondValue = (int) $row['attendance_confirmed'] === 1 ? 'confirmed' : 'not_confirmed';
    } else {
        $dateValue = empty($row['training_date']) ? '' : date('Y-m-d', strtotime($row['training_date']));
        $secondValue = empty($row['expiry_date']) ? 'no_expiry' : date('Y-m-d', strtotime($row['expiry_date']));
    }
    fputcsv($output, [
        csvSafeValue($row['supervisor']),
        csvSafeValue($row['nik']),
        csvSafeValue($row['employee_name']),
        csvSafeValue($row['department']),
        csvSafeValue($row['position']),
        csvSafeValue($row['team']),
        csvSafeValue($row['competency_name']),
        $row['score'] !== null ? $row['score'] : '',
        $row['passing_score'] !== null ? $row['passing_score'] : '',
        $dateValue,
        $secondValue,
        competencyStatusLabel($status),
        csvSafeValue($row['certificate_number']),
        csvSafeValue($row['trainer']),
    ]);
}
fclose($output);
exit;
