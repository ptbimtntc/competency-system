<?php
require_once "auth.php";
require_once "../config/database.php";
require_once "../includes/competency_helper.php";
/*
|--------------------------------------------------------------------------
| Export required competency per posisi
|--------------------------------------------------------------------------
*/
$query = "
    SELECT pr.position, c.code AS competency_code, c.name AS competency_name
    FROM position_requirements pr
    INNER JOIN competencies c ON c.id = pr.competency_id
    ORDER BY pr.position ASC, c.name ASC
";
$result = mysqli_query($conn, $query);
if ($result === false) {
    die("Tabel position_requirements belum tersedia. Jalankan migrasi database/migrations/2026_08_27_add_position_requirements.sql.");
}
/*
|--------------------------------------------------------------------------
| Output CSV
|--------------------------------------------------------------------------
*/
header("Content-Type: text/csv; charset=utf-8");
header("Content-Disposition: attachment; filename=position_requirements_" . date('Ymd_His') . ".csv");
header("Pragma: no-cache");

$output = fopen('php://output', 'w');
fputs($output, "\xEF\xBB\xBF");
fputcsv($output, ['position', 'competency_code', 'competency_name']);
while ($row = mysqli_fetch_assoc($result)) {
    fputcsv($output, [
        csvSafeValue($row['position']),
        csvSafeValue($row['competency_code']),
        csvSafeValue($row['competency_name']),
    ]);
}
fclose($output);
exit;
