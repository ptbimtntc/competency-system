<?php
require_once "../includes/portal_auth.php";
portal_require_view('team_members');
require_once "../includes/competency_helper.php";

$scopeNiks = portal_scope_niks($conn);
[$scopeClause, $scopeParams] = portal_scope_where($scopeNiks, 'nik');

$query = "SELECT nik, name, department, position, team, license_id FROM employees WHERE is_deleted = 0 {$scopeClause} ORDER BY name ASC";
$stmt = mysqli_prepare($conn, $query);
if (!empty($scopeParams)) {
    mysqli_stmt_bind_param($stmt, str_repeat("s", count($scopeParams)), ...$scopeParams);
}
mysqli_stmt_execute($stmt);
$result = mysqli_stmt_get_result($stmt);
/*
|--------------------------------------------------------------------------
| Output CSV
|--------------------------------------------------------------------------
*/
header("Content-Type: text/csv; charset=utf-8");
header("Content-Disposition: attachment; filename=team_members_" . date('Ymd_His') . ".csv");
header("Pragma: no-cache");

$output = fopen('php://output', 'w');
fputs($output, "\xEF\xBB\xBF");
fputcsv($output, ['nik', 'name', 'department', 'position', 'team', 'license_id']);
while ($employee = mysqli_fetch_assoc($result)) {
    fputcsv($output, [
        csvSafeValue($employee['nik']),
        csvSafeValue($employee['name']),
        csvSafeValue($employee['department']),
        csvSafeValue($employee['position']),
        csvSafeValue($employee['team']),
        csvSafeValue($employee['license_id']),
    ]);
}
fclose($output);
exit;
