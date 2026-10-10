<?php
require_once "auth.php";
require_once "../config/database.php";
require_superadmin();

$id = isset($_GET['id']) ? (int) $_GET['id'] : (int) ($_POST['id'] ?? 0);
if ($id <= 0) {
    header("Location: admins.php");
    exit;
}

$stmt = mysqli_prepare($conn, "SELECT id, username, name, role FROM admins WHERE id = ? LIMIT 1");
mysqli_stmt_bind_param($stmt, "i", $id);
mysqli_stmt_execute($stmt);
$admin = mysqli_fetch_assoc(mysqli_stmt_get_result($stmt));
if (!$admin) {
    header("Location: admins.php");
    exit;
}

$isSelf = (int) $admin['id'] === (int) $_SESSION['admin_id'];
$error = "";
$name = $admin['name'];
$role = $admin['role'];

/*
| Jumlah super admin lain (untuk cegah lockout).
*/
function otherSuperadminCount(mysqli $conn, int $exceptId): int
{
    $stmt = mysqli_prepare(
        $conn,
        "SELECT COUNT(*) AS total FROM admins WHERE role = 'superadmin' AND id <> ?"
    );
    mysqli_stmt_bind_param($stmt, "i", $exceptId);
    mysqli_stmt_execute($stmt);
    return (int) mysqli_fetch_assoc(mysqli_stmt_get_result($stmt))['total'];
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_validate();
    $name = trim($_POST['name'] ?? '');
    $role = trim($_POST['role'] ?? '');
    $password = $_POST['password'] ?? '';

    if ($name === '') {
        $error = "Nama wajib diisi.";
    } elseif (!in_array($role, allowed_admin_roles(), true)) {
        $error = "Role tidak valid.";
    } elseif ($password !== '' && strlen($password) < 8) {
        $error = "Password baru minimal 8 karakter.";
    } elseif (
        $admin['role'] === 'superadmin' &&
        $role !== 'superadmin' &&
        otherSuperadminCount($conn, $id) === 0
    ) {
        $error = "Tidak bisa menurunkan role: ini satu-satunya Super Admin.";
    } else {
        if ($password !== '') {
            $hash = password_hash($password, PASSWORD_DEFAULT);
            $updateStmt = mysqli_prepare(
                $conn,
                "UPDATE admins SET name = ?, role = ?, password = ? WHERE id = ?"
            );
            mysqli_stmt_bind_param($updateStmt, "sssi", $name, $role, $hash, $id);
        } else {
            $updateStmt = mysqli_prepare(
                $conn,
                "UPDATE admins SET name = ?, role = ? WHERE id = ?"
            );
            mysqli_stmt_bind_param($updateStmt, "ssi", $name, $role, $id);
        }
        mysqli_stmt_execute($updateStmt);

        /*
        | Kalau super admin mengubah role AKUNNYA SENDIRI, sinkronkan sesi
        | supaya menu langsung menyesuaikan (atau dia ter-lock dari halaman ini).
        */
        if ($isSelf) {
            $_SESSION['admin_role'] = $role;
        }
        header("Location: admins.php?success=updated");
        exit;
    }
}
?>

<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Edit Admin - Bekaert Competency</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet">
    <link rel="stylesheet" href="../assets/css/style.css?v=<?php echo filemtime(__DIR__ . '/../assets/css/style.css'); ?>">
</head>

<body class="admin-page">
    <nav class="admin-navbar">
        <div class="admin-brand">
            <img src="../assets/images/Bekaert_logo_neg_RGB.png" alt="Bekaert" class="brand-logo">
            <span>Competency System</span>
        </div>
        <div class="admin-user">
            <span><?php echo htmlspecialchars($_SESSION['admin_name']); ?></span>
            <a href="logout.php"><i class="bi bi-box-arrow-right"></i> Logout</a>
        </div>
    </nav>
<div class="admin-layout">
    <?php include "../includes/admin_sidebar.php"; ?>
    <main class="admin-content">
    <div class="admin-container">
        <div class="page-header">
            <div>
                <h1>Edit Admin</h1>
                <p><?php echo htmlspecialchars($admin['username']); ?></p>
            </div>
            <a href="admins.php" class="btn btn-outline-secondary">&larr; Back</a>
        </div>

        <?php if ($error !== ''): ?>
            <div class="alert alert-danger"><?php echo htmlspecialchars($error); ?></div>
        <?php endif; ?>
        <?php if ($isSelf): ?>
            <div class="alert alert-info">Ini akun Anda sendiri. Mengubah role akan langsung berlaku.</div>
        <?php endif; ?>

        <div class="form-card">
            <form method="POST">
                <?php echo csrf_input(); ?>
                <input type="hidden" name="id" value="<?php echo (int) $admin['id']; ?>">
                <div class="row g-3">
                    <div class="col-md-6">
                        <label class="form-label">Username</label>
                        <input type="text" class="form-control" value="<?php echo htmlspecialchars($admin['username']); ?>"
                            disabled>
                        <div class="form-text">Username tidak bisa diubah.</div>
                    </div>
                    <div class="col-md-6">
                        <label class="form-label">Nama *</label>
                        <input type="text" name="name" class="form-control"
                            value="<?php echo htmlspecialchars($name); ?>" required>
                    </div>
                    <div class="col-md-6">
                        <label class="form-label">Role *</label>
                        <select name="role" class="form-control">
                            <?php foreach (allowed_admin_roles() as $roleOption): ?>
                                <option value="<?php echo $roleOption; ?>" <?php
                                    echo $role === $roleOption ? 'selected' : '';
                                    ?>><?php echo htmlspecialchars(role_label($roleOption)); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-md-6">
                        <label class="form-label">Password baru</label>
                        <input type="password" name="password" class="form-control"
                            autocomplete="new-password" placeholder="Kosongkan jika tidak diubah">
                        <div class="form-text">Minimal 8 karakter bila diisi.</div>
                    </div>
                </div>
                <hr class="my-4">
                <button type="submit" class="btn btn-primary">Simpan</button>
            </form>
        </div>
    </div>
</main>
</div>
</body>

</html>
