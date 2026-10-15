<?php
require_once "auth.php";
require_once "../config/database.php";
require_once "../includes/competency_helper.php";
/*
|--------------------------------------------------------------------------
| Export daftar QR code -- mengikuti filter yang sedang aktif di qr_codes.php
|--------------------------------------------------------------------------
*/
$settingRow = mysqli_fetch_assoc(
    mysqli_query($conn, "SELECT base_url FROM settings WHERE id = 1")
);
$baseUrl = $settingRow['base_url'] ?? '';

$search = trim($_GET['search'] ?? '');
$supervisorFilter = trim($_GET['supervisor'] ?? '');
$conditions = ["is_deleted = 0"];
$params = [];
$types = "";
if ($search !== '') {
    $conditions[] = "(nik LIKE ? OR name LIKE ? OR department LIKE ?)";
    $keyword = "%" . $search . "%";
    array_push($params, $keyword, $keyword, $keyword);
    $types .= "sss";
}
if ($supervisorFilter !== '') {
    $conditions[] = "supervisor = ?";
    $params[] = $supervisorFilter;
    $types .= "s";
}
$query = "SELECT nik, name, department, position FROM employees WHERE " . implode(" AND ", $conditions) . " ORDER BY name ASC";
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
header("Content-Disposition: attachment; filename=qr_codes_" . date('Ymd_His') . ".csv");
header("Pragma: no-cache");

$output = fopen('php://output', 'w');
fputs($output, "\xEF\xBB\xBF");
fputcsv($output, ['nik', 'name', 'department', 'position', 'url']);
while ($employee = mysqli_fetch_assoc($result)) {
    $url = $baseUrl !== '' ? $baseUrl . '/?nik=' . rawurlencode($employee['nik']) : '';
    fputcsv($output, [
        csvSafeValue($employee['nik']),
        csvSafeValue($employee['name']),
        csvSafeValue($employee['department']),
        csvSafeValue($employee['position']),
        csvSafeValue($url),
    ]);
}
fclose($output);
exit;
