<?php
require_once "auth.php";
require_once "../config/database.php";
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header("Location: employees.php?deleted=1");
    exit;
}
csrf_validate();
require_superadmin();
$id = isset($_POST['id']) ? (int) $_POST['id'] : 0;
if ($id <= 0) {
    header("Location: employees.php?deleted=1");
    exit;
}
/*
|--------------------------------------------------------------------------
| Hapus permanen: hanya untuk karyawan yang sudah di-soft-delete
|--------------------------------------------------------------------------
|
| Tidak ada FOREIGN KEY ON DELETE CASCADE di skema ini, jadi baris anak
| (employee_quiz_answers, competency_history, employee_competencies)
| dihapus manual sebelum baris employees, supaya tidak ada data yatim.
| Foto juga dihapus dari disk karena tidak bisa di-restore lagi.
|
*/
$employeeStmt = mysqli_prepare(
    $conn,
    "SELECT photo FROM employees WHERE id = ? AND is_deleted = 1 LIMIT 1"
);
mysqli_stmt_bind_param($employeeStmt, "i", $id);
mysqli_stmt_execute($employeeStmt);
$employee = mysqli_fetch_assoc(mysqli_stmt_get_result($employeeStmt));
if (!$employee) {
    header("Location: employees.php?deleted=1");
    exit;
}

mysqli_begin_transaction($conn);
try {
    $deleteAnswersStmt = mysqli_prepare(
        $conn,
        "DELETE qa FROM employee_quiz_answers qa
        INNER JOIN employee_competencies ec ON ec.id = qa.employee_competency_id
        WHERE ec.employee_id = ?"
    );
    mysqli_stmt_bind_param($deleteAnswersStmt, "i", $id);
    mysqli_stmt_execute($deleteAnswersStmt);

    $deleteHistoryStmt = mysqli_prepare(
        $conn,
        "DELETE FROM competency_history WHERE employee_id = ?"
    );
    mysqli_stmt_bind_param($deleteHistoryStmt, "i", $id);
    mysqli_stmt_execute($deleteHistoryStmt);

    $deleteCompetenciesStmt = mysqli_prepare(
        $conn,
        "DELETE FROM employee_competencies WHERE employee_id = ?"
    );
    mysqli_stmt_bind_param($deleteCompetenciesStmt, "i", $id);
    mysqli_stmt_execute($deleteCompetenciesStmt);

    $deleteEmployeeStmt = mysqli_prepare(
        $conn,
        "DELETE FROM employees WHERE id = ? AND is_deleted = 1"
    );
    mysqli_stmt_bind_param($deleteEmployeeStmt, "i", $id);
    mysqli_stmt_execute($deleteEmployeeStmt);

    mysqli_commit($conn);
} catch (\Throwable $e) {
    mysqli_rollback($conn);
    header("Location: employees.php?deleted=1&permanent_error=1");
    exit;
}

if (!empty($employee['photo'])) {
    $photoPath = __DIR__ . "/../uploads/employees/" . $employee['photo'];
    if (is_file($photoPath)) {
        unlink($photoPath);
    }
}

header("Location: employees.php?deleted=1&permanent_deleted=1");
exit;
