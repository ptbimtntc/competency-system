<?php
require_once "auth.php";
require_once "../config/database.php";
require_once "../includes/competency_helper.php";
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header("Location: employees.php");
    exit;
}
csrf_validate();
require_writer();
$id = isset($_POST['id'])
    ? (int) $_POST['id']
    : 0;
if ($id <= 0) {
    header("Location: employees.php");
    exit;
}
/*
|--------------------------------------------------------------------------
| Halaman kembali (Back)
|--------------------------------------------------------------------------
*/
$back = trim($_POST['back'] ?? '');
if (!preg_match('/^[a-zA-Z0-9_\-]+\.php(\?[a-zA-Z0-9_\-\.=&%]*)?$/', $back)) {
    $back = '';
}
/*
|--------------------------------------------------------------------------
| Ambil data employee competency
|--------------------------------------------------------------------------
*/
$query = "
    SELECT id, scheduled_training_date, quiz_submitted_at
    FROM employee_competencies
    WHERE id = ?
    LIMIT 1
";
$stmt = mysqli_prepare($conn, $query);
mysqli_stmt_bind_param($stmt, "i", $id);
mysqli_stmt_execute($stmt);
$data = mysqli_fetch_assoc(mysqli_stmt_get_result($stmt));
if (!$data) {
    die("Data competency employee tidak ditemukan.");
}
/*
|--------------------------------------------------------------------------
| Reset hanya kalau kuis pernah dikerjakan
|--------------------------------------------------------------------------
*/
if (!empty($data['quiz_submitted_at'])) {
    $newStatus = calculateCompetencyStatusWithSchedule(
        null,
        null,
        $data['scheduled_training_date']
    );
    mysqli_begin_transaction($conn);
    try {
        $deleteStmt = mysqli_prepare(
            $conn,
            "DELETE FROM employee_quiz_answers WHERE employee_competency_id = ?"
        );
        mysqli_stmt_bind_param($deleteStmt, "i", $id);
        mysqli_stmt_execute($deleteStmt);

        $updateStmt = mysqli_prepare(
            $conn,
            "UPDATE employee_competencies
            SET quiz_submitted_at = NULL,
                training_date = NULL,
                expiry_date = NULL,
                certificate_number = NULL,
                score = NULL,
                status = ?
            WHERE id = ?"
        );
        mysqli_stmt_bind_param($updateStmt, "si", $newStatus, $id);
        mysqli_stmt_execute($updateStmt);

        mysqli_commit($conn);
    } catch (Exception $e) {
        mysqli_rollback($conn);
    }
}

$redirect = "employee_competency_edit.php?id=" . $id . "&success=1";
if ($back !== '') {
    $redirect .= "&back=" . urlencode($back);
}
header("Location: " . $redirect);
exit;
