<?php
require_once "auth.php";
require_once "../config/database.php";
require_once "../includes/competency_helper.php";

$query = "
    SELECT
        code,
        name,
        description,
        scope,
        validity_months,
        passing_score,
        validity_note,
        default_trainer,
        default_training_provider,
        default_authorizer_title,
        default_authorizer_name
    FROM competencies
    ORDER BY name ASC
";
$result = mysqli_query($conn, $query);

header("Content-Type: text/csv; charset=utf-8");
header("Content-Disposition: attachment; filename=competencies_export_" . date('Ymd_His') . ".csv");
header("Pragma: no-cache");

$output = fopen('php://output', 'w');
fputs($output, "\xEF\xBB\xBF");
fputcsv($output, [
    'code',
    'name',
    'description',
    'scope',
    'validity_months',
    'passing_score',
    'validity_note',
    'default_trainer',
    'default_training_provider',
    'default_authorizer_title',
    'default_authorizer_name',
]);
while ($competency = mysqli_fetch_assoc($result)) {
    fputcsv($output, [
        csvSafeValue($competency['code']),
        csvSafeValue($competency['name']),
        csvSafeValue($competency['description']),
        csvSafeValue($competency['scope']),
        $competency['validity_months'],
        $competency['passing_score'],
        csvSafeValue($competency['validity_note']),
        csvSafeValue($competency['default_trainer']),
        csvSafeValue($competency['default_training_provider']),
        csvSafeValue($competency['default_authorizer_title']),
        csvSafeValue($competency['default_authorizer_name']),
    ]);
}
fclose($output);
exit;
