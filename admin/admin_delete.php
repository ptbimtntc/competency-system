<?php
require_once "auth.php";
require_once "../config/database.php";
require_superadmin();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header("Location: admins.php");
    exit;
}
csrf_validate();

$id = isset($_POST['id']) ? (int) $_POST['id'] : 0;
if ($id <= 0) {
    header("Location: admins.php");
    exit;
}
if ($id === (int) $_SESSION['admin_id']) {
    header("Location: admins.php?error=self");
    exit;
}

$stmt = mysqli_prepare($conn, "SELECT role FROM admins WHERE id = ? LIMIT 1");
mysqli_stmt_bind_param($stmt, "i", $id);
mysqli_stmt_execute($stmt);
$target = mysqli_fetch_assoc(mysqli_stmt_get_result($stmt));
if (!$target) {
    header("Location: admins.php");
    exit;
}

/*
| Jangan hapus super admin terakhir.
*/
if ($target['role'] === 'superadmin') {
    $count = (int) mysqli_fetch_assoc(mysqli_query(
        $conn,
        "SELECT COUNT(*) AS total FROM admins WHERE role = 'superadmin'"
    ))['total'];
    if ($count <= 1) {
        header("Location: admins.php?error=last_superadmin");
        exit;
    }
}

$deleteStmt = mysqli_prepare($conn, "DELETE FROM admins WHERE id = ?");
mysqli_stmt_bind_param($deleteStmt, "i", $id);
mysqli_stmt_execute($deleteStmt);

header("Location: admins.php?success=deleted");
exit;
