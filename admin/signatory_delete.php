<?php
require_once "auth.php";
require_once "../config/database.php";
$id = isset($_GET['id']) ? (int) $_GET['id'] : 0;
if ($id <= 0) {
    header("Location: signatories.php");
    exit;
}
/*
|--------------------------------------------------------------------------
| Ambil file tanda tangan
|--------------------------------------------------------------------------
*/
$query = "SELECT signature FROM signatories WHERE id = ? LIMIT 1";
$stmt = mysqli_prepare($conn, $query);
mysqli_stmt_bind_param($stmt, "i", $id);
mysqli_stmt_execute($stmt);
$result = mysqli_stmt_get_result($stmt);
$signatory = mysqli_fetch_assoc($result);
if (!$signatory) {
    header("Location: signatories.php");
    exit;
}
/*
|--------------------------------------------------------------------------
| Delete
|--------------------------------------------------------------------------
|
| Referensi di employee_competencies (trainer_signatory_id /
| authorizer_signatory_id) otomatis menjadi NULL lewat ON DELETE SET NULL,
| jadi riwayat training tetap aman.
|
*/
$deleteQuery = "DELETE FROM signatories WHERE id = ?";
$deleteStmt = mysqli_prepare($conn, $deleteQuery);
mysqli_stmt_bind_param($deleteStmt, "i", $id);
if (mysqli_stmt_execute($deleteStmt)) {
    if (!empty($signatory['signature'])) {
        $path = "../uploads/signatures/" . $signatory['signature'];
        if (file_exists($path)) {
            unlink($path);
        }
    }
}
header("Location: signatories.php");
exit;
