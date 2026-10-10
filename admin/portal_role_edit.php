<?php
require_once "auth.php";
require_once "../config/database.php";
require_superadmin();

$roleId = isset($_GET['id']) ? (int) $_GET['id'] : 0;
$roleStmt = mysqli_prepare($conn, "SELECT * FROM portal_roles WHERE id = ? LIMIT 1");
mysqli_stmt_bind_param($roleStmt, "i", $roleId);
mysqli_stmt_execute($roleStmt);
$role = mysqli_fetch_assoc(mysqli_stmt_get_result($roleStmt));
if (!$role) {
    die("Role tidak ditemukan.");
}

$isLocked = in_array($role['slug'], ['superadmin', 'disabled'], true);
$canEditNameDescription = !$role['is_protected'];

$error = "";
$success = $_GET['success'] ?? '';

$menusResult = mysqli_query($conn, "SELECT slug, label, icon, has_actions FROM portal_menus ORDER BY sort_order ASC");
$menus = [];
while ($row = mysqli_fetch_assoc($menusResult)) {
    $menus[] = $row;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_validate();

    if ($isLocked) {
        $error = "Role ini tidak bisa diubah.";
    } else {
        $name = $role['name'];
        $description = $role['description'];
        if ($canEditNameDescription) {
            $name = trim($_POST['name'] ?? '');
            $description = trim($_POST['description'] ?? '');
            if ($name === '') {
                $error = "Nama role wajib diisi.";
            }
        }

        if ($error === '') {
            mysqli_begin_transaction($conn);
            try {
                if ($canEditNameDescription) {
                    $updateRoleStmt = mysqli_prepare($conn, "UPDATE portal_roles SET name = ?, description = ? WHERE id = ?");
                    mysqli_stmt_bind_param($updateRoleStmt, "ssi", $name, $description, $roleId);
                    mysqli_stmt_execute($updateRoleStmt);
                }

                foreach ($menus as $menu) {
                    $canView = isset($_POST['view_' . $menu['slug']]) ? 1 : 0;
                    $canExecute = ($menu['has_actions'] && isset($_POST['execute_' . $menu['slug']])) ? 1 : 0;
                    $permStmt = mysqli_prepare(
                        $conn,
                        "UPDATE portal_role_permissions SET can_view = ?, can_execute = ? WHERE role_id = ? AND menu_slug = ?"
                    );
                    mysqli_stmt_bind_param($permStmt, "iiis", $canView, $canExecute, $roleId, $menu['slug']);
                    mysqli_stmt_execute($permStmt);
                }

                mysqli_commit($conn);
                header("Location: portal_role_edit.php?id={$roleId}&success=updated");
                exit;
            } catch (Exception $e) {
                mysqli_rollback($conn);
                $error = "Gagal menyimpan: " . $e->getMessage();
            }
        }
    }

    // reload role row in case of error, biar form tidak nampilin data basi
    mysqli_stmt_execute($roleStmt);
    $role = mysqli_fetch_assoc(mysqli_stmt_get_result($roleStmt)) ?: $role;
}

$permissions = [];
$permResult = mysqli_prepare($conn, "SELECT menu_slug, can_view, can_execute FROM portal_role_permissions WHERE role_id = ?");
mysqli_stmt_bind_param($permResult, "i", $roleId);
mysqli_stmt_execute($permResult);
$permRows = mysqli_stmt_get_result($permResult);
while ($row = mysqli_fetch_assoc($permRows)) {
    $permissions[$row['menu_slug']] = $row;
}
?>

<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Edit Portal Role - Bekaert Competency</title>
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
                        <h1><?php echo htmlspecialchars($role['name']); ?></h1>
                        <p><?php echo htmlspecialchars($role['description'] ?? ''); ?></p>
                    </div>
                    <a href="portal_roles.php" class="btn btn-outline-secondary">&larr; Back</a>
                </div>

                <?php if ($success === 'added'): ?>
                    <div class="alert alert-success">Role berhasil dibuat. Atur permission-nya di bawah.</div>
                <?php elseif ($success === 'updated'): ?>
                    <div class="alert alert-success">Perubahan berhasil disimpan.</div>
                <?php endif; ?>
                <?php if ($error !== ''): ?>
                    <div class="alert alert-danger"><?php echo htmlspecialchars($error); ?></div>
                <?php endif; ?>

                <?php if ($isLocked): ?>
                    <div class="alert alert-warning">
                        <?php if ($role['slug'] === 'superadmin'): ?>
                            Role <strong>Superadmin</strong> selalu memiliki akses penuh ke semua menu &amp; semua
                            karyawan (tidak terbatas hierarki), dan tidak bisa diubah agar tidak sengaja dilemahkan.
                        <?php else: ?>
                            Role <strong>Akses Dinonaktifkan</strong> selalu memblokir login ke portal dan tidak
                            bisa diubah.
                        <?php endif; ?>
                    </div>
                <?php endif; ?>

                <div class="form-card">
                    <form method="POST">
                        <?php echo csrf_input(); ?>
                        <?php if ($canEditNameDescription): ?>
                            <div class="row g-3 mb-3">
                                <div class="col-md-6">
                                    <label class="form-label">Nama Role *</label>
                                    <input type="text" name="name" class="form-control"
                                        value="<?php echo htmlspecialchars($role['name']); ?>" required>
                                </div>
                                <div class="col-md-6">
                                    <label class="form-label">Deskripsi</label>
                                    <input type="text" name="description" class="form-control"
                                        value="<?php echo htmlspecialchars($role['description'] ?? ''); ?>">
                                </div>
                            </div>
                            <hr>
                        <?php endif; ?>

                        <h5 class="mb-3">Permission Menu</h5>
                        <div class="table-responsive">
                            <table class="table align-middle">
                                <thead>
                                    <tr>
                                        <th>Menu</th>
                                        <th class="text-center">Boleh Dilihat</th>
                                        <th class="text-center">Boleh Dijalankan</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($menus as $menu): ?>
                                        <?php $perm = $permissions[$menu['slug']] ?? ['can_view' => 0, 'can_execute' => 0]; ?>
                                        <tr>
                                            <td><i class="bi <?php echo htmlspecialchars($menu['icon']); ?>"></i> <?php echo htmlspecialchars($menu['label']); ?></td>
                                            <td class="text-center">
                                                <input type="checkbox" class="form-check-input" name="view_<?php echo $menu['slug']; ?>"
                                                    <?php echo $perm['can_view'] ? 'checked' : ''; ?>
                                                    <?php echo $isLocked ? 'disabled' : ''; ?>>
                                            </td>
                                            <td class="text-center">
                                                <?php if ($menu['has_actions']): ?>
                                                    <input type="checkbox" class="form-check-input" name="execute_<?php echo $menu['slug']; ?>"
                                                        <?php echo $perm['can_execute'] ? 'checked' : ''; ?>
                                                        <?php echo $isLocked ? 'disabled' : ''; ?>>
                                                <?php else: ?>
                                                    <span class="text-muted">&mdash;</span>
                                                <?php endif; ?>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>

                        <?php if (!$isLocked): ?>
                            <hr class="my-4">
                            <button type="submit" class="btn btn-primary">Simpan</button>
                        <?php endif; ?>
                    </form>
                </div>
            </div>
        </main>
    </div>
</body>

</html>
