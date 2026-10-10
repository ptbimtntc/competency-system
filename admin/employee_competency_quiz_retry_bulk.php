<?php
require_once "auth.php";
require_once "../config/database.php";
require_once "../includes/competency_helper.php";
/*
|--------------------------------------------------------------------------
| Reset massal FAILED -> ASSIGNED dari daftar Failed Competencies
|--------------------------------------------------------------------------
|
| Dipakai bersama logic resetEmployeeCompetencyQuiz() yang juga dipakai
| oleh reset satuan (employee_competency_quiz_retry.php) supaya efeknya
| konsisten: scheduled_training_date & attendance_confirmed dipaksa ke
| hari ini, quiz & skor lama dihapus, dan notes dicatat "training kedua".
|
*/
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header("Location: competency_status.php?status=FAILED");
    exit;
}
csrf_validate();
require_writer();

$ids = $_POST['reset_ids'] ?? [];
if (!is_array($ids)) {
    $ids = [];
}
$ids = array_values(array_unique(array_filter(array_map('intval', $ids), fn($v) => $v > 0)));

if (count($ids) > 0) {
    $placeholders = implode(',', array_fill(0, count($ids), '?'));
    $types = str_repeat('i', count($ids));
    $failedOnlyStmt = mysqli_prepare(
        $conn,
        "SELECT id FROM employee_competencies WHERE status = 'FAILED' AND id IN ({$placeholders})"
    );
    mysqli_stmt_bind_param($failedOnlyStmt, $types, ...$ids);
    mysqli_stmt_execute($failedOnlyStmt);
    $failedResult = mysqli_stmt_get_result($failedOnlyStmt);
    while ($row = mysqli_fetch_assoc($failedResult)) {
        resetEmployeeCompetencyQuiz($conn, (int) $row['id'], 1);
    }
}

$back = trim($_POST['back'] ?? '');
if (!preg_match('/^[a-zA-Z0-9_\-]+\.php(\?[a-zA-Z0-9_\-\.=&%]*)?$/', $back)) {
    $back = 'competency_status.php?status=FAILED';
}
$separator = strpos($back, '?') !== false ? '&' : '?';
header("Location: " . $back . $separator . "quiz_retry=1");
exit;
