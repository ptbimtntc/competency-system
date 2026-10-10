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
$conditions = ["is_deleted = 0"];
$params = [];
$types = "";
if ($search !== '') {
    $conditions[] = "(nik LIKE ? OR name LIKE ? OR department LIKE ? OR position LIKE ? OR supervisor LIKE ?)";
    $keyword = "%" . $search . "%";
    array_push($params, $keyword, $keyword, $keyword, $keyword, $keyword);
    $types .= "sssss";
}
if ($teamFilter !== '') {
    $conditions[] = "team = ?";
    $params[] = $teamFilter;
    $types .= "s";
}
$query = "SELECT nik, name, department, position, supervisor, supervisor_nik, team, license_id FROM employees";
if (count($conditions) > 0) {
    $query .= " WHERE " . implode(" AND ", $conditions);
}
$query .= " ORDER BY name ASC";
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
header("Content-Disposition: attachment; filename=employees_export_" . date('Ymd_His') . ".csv");
header("Pragma: no-cache");

$output = fopen('php://output', 'w');
fputs($output, "\xEF\xBB\xBF");
fputcsv($output, ['nik', 'name', 'department', 'position', 'supervisor', 'supervisor_nik', 'team', 'license_id']);
while ($employee = mysqli_fetch_assoc($result)) {
    fputcsv($output, [
        csvSafeValue($employee['nik']),
        csvSafeValue($employee['name']),
        csvSafeValue($employee['department']),
        csvSafeValue($employee['position']),
        csvSafeValue($employee['supervisor']),
        csvSafeValue($employee['supervisor_nik']),
        csvSafeValue($employee['team']),
        csvSafeValue($employee['license_id']),
    ]);
}
fclose($output);
exit;
