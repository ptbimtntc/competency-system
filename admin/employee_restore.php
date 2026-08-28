<?php
require_once "auth.php";
require_once "../config/database.php";
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header("Location: employees.php");
    exit;
}
csrf_validate();
require_writer();
$id = isset($_POST['id']) ? (int) $_POST['id'] : 0;
if ($id <= 0) {
    header("Location: employees.php?deleted=1");
    exit;
}
$stmt = mysqli_prepare(
    $conn,
    "UPDATE employees SET is_deleted = 0, deleted_at = NULL WHERE id = ?"
);
mysqli_stmt_bind_param($stmt, "i", $id);
mysqli_stmt_execute($stmt);

header("Location: employees.php?deleted=1");
exit;
