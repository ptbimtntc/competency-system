<?php
require_once "../includes/portal_auth.php";
portal_require_view('dashboard');
require_once "../includes/competency_helper.php";
/*
|--------------------------------------------------------------------------
| Export Resume Assignment & Kehadiran Training (tim) -- mengikuti filter
| yang sedang aktif di team_training_resume.php (atau kartu statistik di
| dashboard)
|--------------------------------------------------------------------------
*/
$scopeNiks = portal_scope_niks($conn);
[$scopeClause, $scopeParams] = portal_scope_where($scopeNiks);

$competencyFilter = isset($_GET['competency_id']) ? (int) $_GET['competency_id'] : 0;
$attendanceFilter = trim($_GET['attendance'] ?? '');
if (!in_array($attendanceFilter, ['confirmed', 'not_confirmed', 'waiting_quiz'], true)) {
    $attendanceFilter = '';
}
$search = trim($_GET['search'] ?? '');

$conditions = [
    "e.is_deleted = 0",
    "ec.is_active = 1",
    "(ec.scheduled_training_date IS NOT NULL OR ec.training_date IS NOT NULL)",
];
$params = [];
$types = "";
if ($competencyFilter > 0) {
    $conditions[] = "ec.competency_id = ?";
    $params[] = $competencyFilter;
    $types .= "i";
}
if ($attendanceFilter === 'confirmed') {
    $conditions[] = "(ec.attendance_confirmed = 1 OR ec.training_date IS NOT NULL)";
} elseif ($attendanceFilter === 'not_confirmed') {
    $conditions[] = "ec.attendance_confirmed = 0 AND ec.training_date IS NULL";
} elseif ($attendanceFilter === 'waiting_quiz') {
    $conditions[] = "ec.status = 'ASSIGNED' AND ec.attendance_confirmed = 1";
}
if ($search !== '') {
    $conditions[] = "(e.nik LIKE ? OR e.name LIKE ? OR e.department LIKE ?)";
    $keyword = "%" . $search . "%";
    array_push($params, $keyword, $keyword, $keyword);
    $types .= "sss";
}
$params = array_merge($params, $scopeParams);
$types .= str_repeat('s', count($scopeParams));

$whereSql = implode(' AND ', $conditions) . $scopeClause;

$query = "
    SELECT
        ec.scheduled_training_date,
        ec.training_date,
        ec.attendance_confirmed,
        ec.status,
        ec.score,
        e.nik,
        e.name AS employee_name,
        e.department,
        e.team,
        c.name AS competency_name
    FROM employee_competencies ec
    INNER JOIN employees e ON e.id = ec.employee_id
    INNER JOIN competencies c ON c.id = ec.competency_id
    WHERE " . $whereSql . "
    ORDER BY COALESCE(ec.scheduled_training_date, ec.training_date) DESC, e.name ASC
";
$stmt = mysqli_prepare($conn, $query);
if (!empty($params)) {
    mysqli_stmt_bind_param($stmt, $types, ...$params);
}
mysqli_stmt_execute($stmt);
$result = mysqli_stmt_get_result($stmt);

$statusLabelMap = [
    'VALID' => 'Valid',
    'EXPIRING_SOON' => 'Expiring Soon',
    'EXPIRED' => 'Expired',
    'FAILED' => 'Failed',
    'ASSIGNED' => 'Assigned',
];
/*
|--------------------------------------------------------------------------
| Output CSV
|--------------------------------------------------------------------------
*/
header("Content-Type: text/csv; charset=utf-8");
header("Content-Disposition: attachment; filename=team_training_resume_" . date('Ymd_His') . ".csv");
header("Pragma: no-cache");

$output = fopen('php://output', 'w');
fputs($output, "\xEF\xBB\xBF");
fputcsv($output, [
    'nik', 'name', 'department', 'team', 'competency',
    'training_date', 'attendance', 'status', 'score',
]);
while ($row = mysqli_fetch_assoc($result)) {
    $isAttended = (int) $row['attendance_confirmed'] === 1 || !empty($row['training_date']);
    $displayDate = $row['training_date'] ?? $row['scheduled_training_date'];
    fputcsv($output, [
        csvSafeValue($row['nik']),
        csvSafeValue($row['employee_name']),
        csvSafeValue($row['department']),
        csvSafeValue($row['team']),
        csvSafeValue($row['competency_name']),
        empty($displayDate) ? '' : date('Y-m-d', strtotime($displayDate)),
        $isAttended ? 'yes' : 'no',
        $statusLabelMap[$row['status']] ?? $row['status'],
        $row['score'] !== null ? $row['score'] : '',
    ]);
}
fclose($output);
exit;
