<?php
require_once "auth.php";
require_once "../config/database.php";
require_superadmin();

$error = "";
$username = "";
$name = "";
$role = "admin";

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_validate();
    $username = trim($_POST['username'] ?? '');
    $name = trim($_POST['name'] ?? '');
    $password = $_POST['password'] ?? '';
    $role = trim($_POST['role'] ?? '');

    if ($username === '' || $password === '' || $name === '') {
        $error = "Username, nama, dan password wajib diisi.";
    } elseif (!preg_match('/^[a-zA-Z0-9_.-]{3,50}$/', $username)) {
        $error = "Username 3-50 karakter, hanya huruf/angka/titik/garis bawah/strip.";
    } elseif (strlen($password) < 8) {
        $error = "Password minimal 8 karakter.";
    } elseif (!in_array($role, allowed_admin_roles(), true)) {
        $error = "Role tidak valid.";
    } else {
        $checkStmt = mysqli_prepare($conn, "SELECT id FROM admins WHERE username = ? LIMIT 1");
        mysqli_stmt_bind_param($checkStmt, "s", $username);
        mysqli_stmt_execute($checkStmt);
        if (mysqli_fetch_assoc(mysqli_stmt_get_result($checkStmt))) {
            $error = "Username tersebut sudah dipakai.";
        } else {
            $hash = password_hash($password, PASSWORD_DEFAULT);
            $insertStmt = mysqli_prepare(
                $conn,
                "INSERT INTO admins (username, name, password, role) VALUES (?, ?, ?, ?)"
            );
            mysqli_stmt_bind_param($insertStmt, "ssss", $username, $name, $hash, $role);
            mysqli_stmt_execute($insertStmt);
            header("Location: admins.php?success=added");
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
    <title>Add Admin - Bekaert Competency</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="../assets/css/style.css">
</head>

<body class="admin-page">
    <nav class="admin-navbar">
        <div class="admin-brand">
            <img src="../assets/images/Bekaert_logo_neg_RGB.png" alt="Bekaert" class="brand-logo">
            <span>Competency System</span>
        </div>
        <div class="admin-user">
            <span><?php echo htmlspecialchars($_SESSION['admin_name']); ?></span>
            <a href="logout.php">Logout</a>
        </div>
    </nav>
    <div class="admin-container">
        <div class="page-header">
            <div>
                <h1>Add Admin</h1>
                <p>Buat akun admin baru</p>
            </div>
            <a href="admins.php" class="btn btn-outline-secondary">&larr; Back</a>
        </div>

        <?php if ($error !== ''): ?>
            <div class="alert alert-danger"><?php echo htmlspecialchars($error); ?></div>
        <?php endif; ?>

        <div class="form-card">
            <form method="POST">
                <?php echo csrf_input(); ?>
                <div class="row g-3">
                    <div class="col-md-6">
                        <label class="form-label">Username *</label>
                        <input type="text" name="username" class="form-control"
                            value="<?php echo htmlspecialchars($username); ?>" autocomplete="off" required>
                    </div>
                    <div class="col-md-6">
                        <label class="form-label">Nama *</label>
                        <input type="text" name="name" class="form-control"
                            value="<?php echo htmlspecialchars($name); ?>" required>
                    </div>
                    <div class="col-md-6">
                        <label class="form-label">Password *</label>
                        <input type="password" name="password" class="form-control"
                            autocomplete="new-password" minlength="8" required>
                        <div class="form-text">Minimal 8 karakter.</div>
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
                        <div class="form-text">
                            Super Admin: penuh + kelola akun. Admin: penuh tanpa kelola akun.
                            Viewer: hanya lihat &amp; export.
                        </div>
                    </div>
                </div>
                <hr class="my-4">
                <button type="submit" class="btn btn-primary">Simpan</button>
            </form>
        </div>
    </div>
</body>

</html>
