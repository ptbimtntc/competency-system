<?php
require_once "auth.php";
require_once "../config/database.php";
require_once "../includes/competency_helper.php";
/*
|--------------------------------------------------------------------------
| Filter (mengikuti filter aktif di halaman Recertification Due)
|--------------------------------------------------------------------------
*/
$allowedWindows = ['30', '60', '90', 'expired', 'all'];
$window = trim($_GET['window'] ?? '60');
if (!in_array($window, $allowedWindows, true)) {
    $window = '60';
}
$allowedTeams = ['A', 'B', 'C', 'D', 'NS'];
$team = strtoupper(trim($_GET['team'] ?? ''));
if (!in_array($team, $allowedTeams, true)) {
    $team = '';
}
$competencyId = isset($_GET['competency_id']) ? (int) $_GET['competency_id'] : 0;
$search = trim($_GET['search'] ?? '');

$conditions = ["ec.is_active = 1", "ec.expiry_date IS NOT NULL", "e.is_deleted = 0"];
$params = [];
$types = "";
if ($team !== '') {
    $conditions[] = "e.team = ?";
    $params[] = $team;
    $types .= "s";
}
if ($competencyId > 0) {
    $conditions[] = "ec.competency_id = ?";
    $params[] = $competencyId;
    $types .= "i";
}
if ($search !== '') {
    $conditions[] = "(e.nik LIKE ? OR e.name LIKE ?)";
    $keyword = "%" . $search . "%";
    $params[] = $keyword;
    $params[] = $keyword;
    $types .= "ss";
}
if ($window === 'expired') {
    $conditions[] = "ec.expiry_date < CURDATE()";
} elseif (in_array($window, ['30', '60', '90'], true)) {
    $conditions[] = "ec.expiry_date <= DATE_ADD(CURDATE(), INTERVAL ? DAY)";
    $params[] = (int) $window;
    $types .= "i";
}
$query = "
    SELECT
        ec.training_date,
        ec.expiry_date,
        ec.score,
        c.passing_score,
        DATEDIFF(ec.expiry_date, CURDATE()) AS days_remaining,
        e.nik,
        e.name AS employee_name,
        e.team,
        e.department,
        e.position,
        e.supervisor,
        c.code AS competency_code,
        c.name AS competency_name
    FROM employee_competencies ec
    INNER JOIN employees e ON e.id = ec.employee_id
    INNER JOIN competencies c ON c.id = ec.competency_id
    WHERE " . implode(" AND ", $conditions) . "
    ORDER BY ec.expiry_date ASC, e.name ASC
";
$stmt = mysqli_prepare($conn, $query);
if (count($params) > 0) {
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
header("Content-Disposition: attachment; filename=recertification_due_" . date('Ymd_His') . ".csv");
header("Pragma: no-cache");

$output = fopen('php://output', 'w');
fputs($output, "\xEF\xBB\xBF");
fputcsv($output, [
    'nik',
    'employee_name',
    'team',
    'department',
    'position',
    'supervisor',
    'competency_code',
    'competency_name',
    'training_date',
    'expiry_date',
    'days_remaining',
    'status',
]);
while ($row = mysqli_fetch_assoc($result)) {
    $status = calculateCompetencyStatus($row['training_date'], $row['expiry_date']);
    $status = applyPassingScoreGate(
        $status,
        $row['score'] !== null ? (int) $row['score'] : null,
        $row['passing_score'] !== null ? (int) $row['passing_score'] : null
    );
    fputcsv($output, [
        csvSafeValue($row['nik']),
        csvSafeValue($row['employee_name']),
        csvSafeValue($row['team']),
        csvSafeValue($row['department']),
        csvSafeValue($row['position']),
        csvSafeValue($row['supervisor']),
        csvSafeValue($row['competency_code']),
        csvSafeValue($row['competency_name']),
        $row['training_date'],
        $row['expiry_date'],
        (int) $row['days_remaining'],
        competencyStatusLabel($status),
    ]);
}
fclose($output);
exit;
