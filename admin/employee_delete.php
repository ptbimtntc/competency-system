<?php
require_once "auth.php";
require_once "../config/database.php";
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
| Soft-delete: tandai is_deleted, jangan hapus baris / riwayat
|--------------------------------------------------------------------------
|
| Baris employees, employee_competencies, dan competency_history dibiarkan
| utuh supaya riwayat training/sertifikat tetap bisa diaudit. Foto juga
| tidak dihapus supaya bisa dipulihkan lewat employee_restore.php.
|
*/
$stmt = mysqli_prepare(
    $conn,
    "UPDATE employees SET is_deleted = 1, deleted_at = NOW() WHERE id = ? AND is_deleted = 0"
);
mysqli_stmt_bind_param($stmt, "i", $id);
mysqli_stmt_execute($stmt);

header("Location: employees.php");
exit;
