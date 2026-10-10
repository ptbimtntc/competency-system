<?php
require_once "auth.php";
require_once "../config/database.php";
require_superadmin();

$success = $_GET['success'] ?? '';
$error = $_GET['error'] ?? '';

$result = mysqli_query(
    $conn,
    "SELECT id, username, name, role, created_at FROM admins ORDER BY username ASC"
);
?>

<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Admin Accounts - Bekaert Competency</title>
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
                <h1>Admin Accounts</h1>
                <p>Kelola akun & hak akses admin</p>
            </div>
            <div class="d-flex gap-2">
                <a href="admin_add.php" class="btn btn-primary">+ Add Admin</a>
            </div>
        </div>

        <?php if ($success === 'added'): ?>
            <div class="alert alert-success">Akun admin berhasil ditambahkan.</div>
        <?php elseif ($success === 'updated'): ?>
            <div class="alert alert-success">Akun admin berhasil diperbarui.</div>
        <?php elseif ($success === 'deleted'): ?>
            <div class="alert alert-success">Akun admin berhasil dihapus.</div>
        <?php endif; ?>
        <?php if ($error === 'last_superadmin'): ?>
            <div class="alert alert-danger">Tidak bisa: harus ada minimal satu Super Admin.</div>
        <?php elseif ($error === 'self'): ?>
            <div class="alert alert-danger">Anda tidak bisa menghapus akun Anda sendiri.</div>
        <?php endif; ?>

        <div class="employee-table-card">
            <div class="table-responsive">
                <table class="table table-hover align-middle mb-0">
                    <thead>
                        <tr>
                            <th>Username</th>
                            <th>Nama</th>
                            <th>Role</th>
                            <th>Dibuat</th>
                            <th>Action</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php while ($admin = mysqli_fetch_assoc($result)): ?>
                            <tr>
                                <td><strong><?php echo htmlspecialchars($admin['username']); ?></strong></td>
                                <td><?php echo htmlspecialchars($admin['name'] ?? '-'); ?></td>
                                <td>
                                    <span class="badge <?php
                                        echo match ($admin['role']) {
                                            'superadmin' => 'text-bg-primary',
                                            'admin' => 'text-bg-info',
                                            default => 'text-bg-secondary',
                                        };
                                    ?>">
                                        <?php echo htmlspecialchars(role_label($admin['role'])); ?>
                                    </span>
                                    <?php if ((int) $admin['id'] === (int) $_SESSION['admin_id']): ?>
                                        <span class="text-muted">(Anda)</span>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <?php echo $admin['created_at']
                                        ? date('d M Y', strtotime($admin['created_at']))
                                        : '-'; ?>
                                </td>
                                <td>
                                    <div class="employee-actions">
                                        <a href="admin_edit.php?id=<?php echo (int) $admin['id']; ?>"
                                            class="btn btn-sm btn-outline-primary">Edit</a>
                                        <?php if ((int) $admin['id'] !== (int) $_SESSION['admin_id']): ?>
                                            <form method="POST" action="admin_delete.php" class="d-inline"
                                                onsubmit="return confirm('Hapus akun admin ini?');">
                                                <?php echo csrf_input(); ?>
                                                <input type="hidden" name="id" value="<?php echo (int) $admin['id']; ?>">
                                                <button type="submit" class="btn btn-sm btn-outline-danger">Delete</button>
                                            </form>
                                        <?php endif; ?>
                                    </div>
                                </td>
                            </tr>
                        <?php endwhile; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</main>
</div>
</body>

</html>
