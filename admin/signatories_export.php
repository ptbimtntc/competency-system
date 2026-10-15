<?php
require_once "auth.php";
require_once "../config/database.php";
require_once "../includes/competency_helper.php";
/*
|--------------------------------------------------------------------------
| Export daftar signatory -- mengikuti filter yang sedang aktif di signatories.php
|--------------------------------------------------------------------------
|
| File tanda tangan (gambar) tidak diikutkan -- CSV tidak bisa membawa
| file biner, hanya ditandai lewat kolom has_signature.
|
*/
$search = trim($_GET['search'] ?? '');
$conditions = [];
$params = [];
$types = "";
if ($search !== '') {
    $conditions[] = "(name LIKE ? OR title LIKE ?)";
    $keyword = "%" . $search . "%";
    array_push($params, $keyword, $keyword);
    $types .= "ss";
}
$query = "SELECT name, title, signature FROM signatories";
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
header("Content-Disposition: attachment; filename=signatories_" . date('Ymd_His') . ".csv");
header("Pragma: no-cache");

$output = fopen('php://output', 'w');
fputs($output, "\xEF\xBB\xBF");
fputcsv($output, ['name', 'title', 'has_signature']);
while ($signatory = mysqli_fetch_assoc($result)) {
    fputcsv($output, [
        csvSafeValue($signatory['name']),
        csvSafeValue($signatory['title']),
        !empty($signatory['signature']) ? 'yes' : 'no',
    ]);
}
fclose($output);
exit;
