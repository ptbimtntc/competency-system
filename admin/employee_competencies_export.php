<?php
require_once "auth.php";
require_once "../config/database.php";
require_once "../includes/competency_helper.php";
/*
|--------------------------------------------------------------------------
| Filter (mengikuti filter yang sedang aktif di halaman Employees)
|--------------------------------------------------------------------------
*/
$search = trim($_GET['search'] ?? '');
$teamFilter = trim($_GET['team'] ?? '');
$allowedTeams = ['A', 'B', 'C', 'D', 'NS'];
if (!in_array($teamFilter, $allowedTeams, true)) {
    $teamFilter = '';
}
$conditions = ["e.is_deleted = 0"];
$params = [];
$types = "";
if ($search !== '') {
    $conditions[] = "(e.nik LIKE ? OR e.name LIKE ? OR e.department LIKE ? OR e.position LIKE ? OR e.supervisor LIKE ?)";
    $keyword = "%" . $search . "%";
    array_push($params, $keyword, $keyword, $keyword, $keyword, $keyword);
    $types .= "sssss";
}
if ($teamFilter !== '') {
    $conditions[] = "e.team = ?";
    $params[] = $teamFilter;
    $types .= "s";
}
/*
|--------------------------------------------------------------------------
| Gabungan employee + competency yang dimiliki
|--------------------------------------------------------------------------
|
| LEFT JOIN supaya employee tanpa competency aktif tetap muncul (kolom
| competency kosong). Hanya assignment aktif (is_active = 1) yang diambil,
| karena tujuannya menunjukkan competency yang sedang dipegang employee.
|
*/
$query = "
    SELECT
        e.nik,
        e.name AS employee_name,
        e.department,
        e.position,
        e.supervisor,
        e.team,
        c.code AS competency_code,
        c.name AS competency_name,
        c.passing_score,
        ec.training_date,
        ec.scheduled_training_date,
        ec.expiry_date,
        ec.issue_date,
        ec.score,
        ec.certificate_number,
        ec.trainer,
        ec.quiz_submitted_at
    FROM employees e
    LEFT JOIN employee_competencies ec
        ON ec.employee_id = e.id
        AND ec.is_active = 1
    LEFT JOIN competencies c
        ON c.id = ec.competency_id
";
if (count($conditions) > 0) {
    $query .= " WHERE " . implode(" AND ", $conditions);
}
$query .= " ORDER BY e.name ASC, c.name ASC";
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
header("Content-Disposition: attachment; filename=employee_competencies_export_" . date('Ymd_His') . ".csv");
header("Pragma: no-cache");

$output = fopen('php://output', 'w');
fputs($output, "\xEF\xBB\xBF");
fputcsv($output, [
    'nik',
    'employee_name',
    'department',
    'position',
    'supervisor',
    'team',
    'competency_code',
    'competency_name',
    'status',
    'score',
    'training_date',
    'issue_date',
    'expiry_date',
    'certificate_number',
    'trainer',
]);
while ($row = mysqli_fetch_assoc($result)) {
    $hasCompetency = $row['competency_name'] !== null;
    if ($hasCompetency) {
        $status = calculateCompetencyStatusWithSchedule(
            $row['training_date'],
            $row['expiry_date'],
            $row['scheduled_training_date'],
            $row['quiz_submitted_at']
        );
        $status = applyPassingScoreGate(
            $status,
            $row['score'] !== null ? (int) $row['score'] : null,
            $row['passing_score'] !== null ? (int) $row['passing_score'] : null
        );
        $statusLabel = competencyStatusLabel($status);
    } else {
        $statusLabel = '';
    }
    fputcsv($output, [
        csvSafeValue($row['nik']),
        csvSafeValue($row['employee_name']),
        csvSafeValue($row['department']),
        csvSafeValue($row['position']),
        csvSafeValue($row['supervisor']),
        csvSafeValue($row['team']),
        csvSafeValue($row['competency_code']),
        csvSafeValue($row['competency_name']),
        $statusLabel,
        $hasCompetency ? $row['score'] : '',
        $row['training_date'],
        $row['issue_date'],
        $row['expiry_date'],
        csvSafeValue($row['certificate_number']),
        csvSafeValue($row['trainer']),
    ]);
}
fclose($output);
exit;
