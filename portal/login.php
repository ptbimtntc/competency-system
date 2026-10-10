<?php

session_start();

require_once "../admin/csrf.php";
require_once "../config/database.php";
require_once "../includes/portal_helper.php";

/*
|--------------------------------------------------------------------------
| Jika sudah login, langsung ke dashboard
|--------------------------------------------------------------------------
*/
if (isset($_SESSION['portal_employee_id'])) {
    header("Location: dashboard.php");
    exit;
}

$error = "";

/*
|--------------------------------------------------------------------------
| Proses Login (NIK saja, tanpa password)
|--------------------------------------------------------------------------
*/
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_validate();

    $nik = trim($_POST['nik'] ?? '');

    if ($nik === '') {
        $error = "NIK wajib diisi.";
    } else {
        $query = "
            SELECT id, nik, name, portal_role_id
            FROM employees
            WHERE nik = ? AND is_deleted = 0
            LIMIT 1
        ";
        $stmt = mysqli_prepare($conn, $query);
        mysqli_stmt_bind_param($stmt, "s", $nik);
        mysqli_stmt_execute($stmt);
        $employee = mysqli_fetch_assoc(mysqli_stmt_get_result($stmt));

        $role = $employee ? resolve_portal_role($conn, $employee) : null;

        if (!$employee || !$role || $role['slug'] === 'disabled') {
            $error = "NIK tidak ditemukan atau tidak memiliki akses ke portal ini.";
        } else {
            session_regenerate_id(true);
            $_SESSION['csrf_token'] = bin2hex(random_bytes(32));

            $_SESSION['portal_employee_id'] = $employee['id'];
            $_SESSION['portal_nik'] = $employee['nik'];
            $_SESSION['portal_name'] = $employee['name'];
            $_SESSION['portal_role_id'] = $role['id'];
            $_SESSION['portal_role_slug'] = $role['slug'];
            $_SESSION['portal_role_name'] = $role['name'];

            header("Location: dashboard.php");
            exit;
        }
    }
}

?>

<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Employee Portal Login - Bekaert Competency</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="../assets/css/style.css?v=<?php echo filemtime(__DIR__ . '/../assets/css/style.css'); ?>">
</head>

<body class="admin-login-page">
    <div class="login-card">
        <div class="login-logo">
            <img src="../assets/images/Bekaert_logo_pos_RGB.png" alt="Bekaert" class="brand-logo">
        </div>
        <div class="login-title">
            EMPLOYEE PORTAL
        </div>

        <?php if ($error !== ''): ?>
            <div class="alert alert-danger">
                <?php echo htmlspecialchars($error); ?>
            </div>
        <?php endif; ?>

        <form method="POST" action="">
            <?php echo csrf_input(); ?>
            <div class="mb-4">
                <label class="form-label">NIK</label>
                <input type="text" name="nik" class="form-control" autocomplete="username" autofocus required>
            </div>
            <button type="submit" class="btn btn-primary w-100">LOGIN</button>
        </form>

        <div class="login-footer">
            Employee Competency Verification System
        </div>
    </div>
</body>

</html>
