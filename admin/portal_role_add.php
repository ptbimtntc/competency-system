<?php
require_once "auth.php";
require_once "../config/database.php";
require_superadmin();

$error = "";
$name = "";
$description = "";

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_validate();
    $name = trim($_POST['name'] ?? '');
    $description = trim($_POST['description'] ?? '');

    if ($name === '') {
        $error = "Nama role wajib diisi.";
    } else {
        $slug = strtolower(preg_replace('/[^a-z0-9]+/', '_', $name));
        $slug = trim($slug, '_');
        if ($slug === '') {
            $slug = 'role_' . time();
        }

        $checkStmt = mysqli_prepare($conn, "SELECT id FROM portal_roles WHERE slug = ? LIMIT 1");
        mysqli_stmt_bind_param($checkStmt, "s", $slug);
        mysqli_stmt_execute($checkStmt);
        if (mysqli_fetch_assoc(mysqli_stmt_get_result($checkStmt))) {
            $slug .= '_' . substr(md5((string) microtime(true)), 0, 5);
        }

        mysqli_begin_transaction($conn);
        try {
            $insertStmt = mysqli_prepare(
                $conn,
                "INSERT INTO portal_roles (slug, name, description, is_protected) VALUES (?, ?, ?, 0)"
            );
            mysqli_stmt_bind_param($insertStmt, "sss", $slug, $name, $description);
            mysqli_stmt_execute($insertStmt);
            $roleId = mysqli_insert_id($conn);

            // Role baru: default semua menu tidak terlihat (admin atur lewat Edit Permission).
            $permInsertStmt = mysqli_prepare(
                $conn,
                "INSERT INTO portal_role_permissions (role_id, menu_slug, can_view, can_execute)
                 SELECT ?, slug, 0, 0 FROM portal_menus"
            );
            mysqli_stmt_bind_param($permInsertStmt, "i", $roleId);
            mysqli_stmt_execute($permInsertStmt);

            mysqli_commit($conn);
            header("Location: portal_role_edit.php?id={$roleId}&success=added");
            exit;
        } catch (Exception $e) {
            mysqli_rollback($conn);
            $error = "Gagal menyimpan role: " . $e->getMessage();
        }
    }
}
?>

<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Tambah Portal Role - Bekaert Competency</title>
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
                        <h1>Tambah Portal Role</h1>
                        <p>Buat role baru untuk Employee Portal</p>
                    </div>
                    <a href="portal_roles.php" class="btn btn-outline-secondary">&larr; Back</a>
                </div>

                <?php if ($error !== ''): ?>
                    <div class="alert alert-danger"><?php echo htmlspecialchars($error); ?></div>
                <?php endif; ?>

                <div class="form-card">
                    <form method="POST">
                        <?php echo csrf_input(); ?>
                        <div class="row g-3">
                            <div class="col-md-6">
                                <label class="form-label">Nama Role *</label>
                                <input type="text" name="name" class="form-control"
                                    value="<?php echo htmlspecialchars($name); ?>" required>
                            </div>
                            <div class="col-md-6">
                                <label class="form-label">Deskripsi</label>
                                <input type="text" name="description" class="form-control"
                                    value="<?php echo htmlspecialchars($description); ?>">
                            </div>
                        </div>
                        <div class="form-text mb-3">
                            Permission menu diatur di halaman berikutnya setelah role dibuat.
                        </div>
                        <hr class="my-4">
                        <button type="submit" class="btn btn-primary">Simpan &amp; Atur Permission</button>
                    </form>
                </div>
            </div>
        </main>
    </div>
</body>

</html>
