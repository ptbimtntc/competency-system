<?php
session_start();
require_once __DIR__ . "/csrf.php";
require_once __DIR__ . "/roles.php";
/*
|--------------------------------------------------------------------------
| Cek apakah admin sudah login
|--------------------------------------------------------------------------
*/
if (!isset($_SESSION['admin_id'])) {
    header("Location: login.php");
    exit;
}
/*
|--------------------------------------------------------------------------
| Isi role ke sesi kalau belum ada (sesi lama / setelah migrasi)
|--------------------------------------------------------------------------
|
| Default 'admin' kalau lookup gagal (mis. kolom role belum dibuat) supaya
| perilaku lama -- semua admin bisa menulis -- tetap jalan sampai migrasi
| 2026_08_27_add_admin_roles.sql dijalankan.
|
*/
if (!isset($_SESSION['admin_role'])) {
    $_SESSION['admin_role'] = 'admin';
    require_once __DIR__ . "/../config/database.php";
    try {
        $roleStmt = mysqli_prepare($conn, "SELECT role FROM admins WHERE id = ? LIMIT 1");
        if ($roleStmt !== false) {
            mysqli_stmt_bind_param($roleStmt, "i", $_SESSION['admin_id']);
            mysqli_stmt_execute($roleStmt);
            $roleRow = mysqli_fetch_assoc(mysqli_stmt_get_result($roleStmt));
            if ($roleRow && !empty($roleRow['role'])) {
                $_SESSION['admin_role'] = $roleRow['role'];
            }
        }
    } catch (\Throwable $e) {
        // kolom role belum ada -> biarkan default 'admin'
    }
}
