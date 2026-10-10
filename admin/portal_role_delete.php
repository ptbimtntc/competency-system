<?php
require_once "auth.php";
require_once "../config/database.php";
require_superadmin();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header("Location: portal_roles.php");
    exit;
}
csrf_validate();

$id = isset($_POST['id']) ? (int) $_POST['id'] : 0;
if ($id <= 0) {
    header("Location: portal_roles.php");
    exit;
}

$stmt = mysqli_prepare($conn, "SELECT is_protected FROM portal_roles WHERE id = ? LIMIT 1");
mysqli_stmt_bind_param($stmt, "i", $id);
mysqli_stmt_execute($stmt);
$role = mysqli_fetch_assoc(mysqli_stmt_get_result($stmt));
if (!$role) {
    header("Location: portal_roles.php");
    exit;
}
if ((int) $role['is_protected'] === 1) {
    header("Location: portal_roles.php?error=protected");
    exit;
}

$usageStmt = mysqli_prepare($conn, "SELECT COUNT(*) AS total FROM employees WHERE portal_role_id = ?");
mysqli_stmt_bind_param($usageStmt, "i", $id);
mysqli_stmt_execute($usageStmt);
$inUse = (int) mysqli_fetch_assoc(mysqli_stmt_get_result($usageStmt))['total'] > 0;
if ($inUse) {
    header("Location: portal_roles.php?error=in_use");
    exit;
}

$deleteStmt = mysqli_prepare($conn, "DELETE FROM portal_roles WHERE id = ?");
mysqli_stmt_bind_param($deleteStmt, "i", $id);
mysqli_stmt_execute($deleteStmt);

header("Location: portal_roles.php?success=deleted");
exit;
