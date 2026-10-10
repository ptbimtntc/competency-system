<?php
require_once "auth.php";
require_once "../config/database.php";
require_superadmin();

$success = $_GET['success'] ?? '';
$error = $_GET['error'] ?? '';

$result = mysqli_query(
    $conn,
    "SELECT r.id, r.slug, r.name, r.description, r.is_protected,
        (SELECT COUNT(*) FROM employees e WHERE e.portal_role_id = r.id AND e.is_deleted = 0) AS assigned_count
     FROM portal_roles r
     ORDER BY r.is_protected DESC, r.name ASC"
);

$autoSupervisorCount = mysqli_fetch_assoc(mysqli_query(
    $conn,
    "SELECT COUNT(*) AS total FROM employees e
     WHERE e.is_deleted = 0 AND e.portal_role_id IS NULL
       AND EXISTS (SELECT 1 FROM employees sub WHERE sub.supervisor_nik = e.nik AND sub.is_deleted = 0)"
))['total'];
?>

<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Portal Roles - Bekaert Competency</title>
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
                        <h1>Portal Roles</h1>
                        <p>Atur role &amp; hak akses menu untuk Employee Portal (login NIK)</p>
                    </div>
                    <div class="d-flex gap-2">
                        <a href="portal_role_add.php" class="btn btn-primary">+ Tambah Role</a>
                    </div>
                </div>

                <?php if ($success === 'added'): ?>
                    <div class="alert alert-success">Role berhasil ditambahkan.</div>
                <?php elseif ($success === 'updated'): ?>
                    <div class="alert alert-success">Role &amp; permission berhasil diperbarui.</div>
                <?php elseif ($success === 'deleted'): ?>
                    <div class="alert alert-success">Role berhasil dihapus.</div>
                <?php endif; ?>
                <?php if ($error === 'in_use'): ?>
                    <div class="alert alert-danger">Tidak bisa menghapus: role ini masih dipakai oleh karyawan.</div>
                <?php elseif ($error === 'protected'): ?>
                    <div class="alert alert-danger">Role ini adalah role bawaan sistem dan tidak bisa dihapus.</div>
                <?php endif; ?>

                <div class="alert alert-info">
                    <strong><?php echo $autoSupervisorCount; ?></strong> karyawan otomatis mendapat role
                    <strong>Supervisor</strong> karena memiliki bawahan (tanpa perlu di-assign manual). Role manual
                    (di bawah) akan selalu menang jika diisi pada data karyawan.
                </div>

                <div class="employee-table-card">
                    <div class="table-responsive">
                        <table class="table table-hover align-middle mb-0">
                            <thead>
                                <tr>
                                    <th>Role</th>
                                    <th>Deskripsi</th>
                                    <th>Karyawan (assign manual)</th>
                                    <th>Action</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php while ($role = mysqli_fetch_assoc($result)): ?>
                                    <tr>
                                        <td>
                                            <strong><?php echo htmlspecialchars($role['name']); ?></strong>
                                            <?php if ($role['is_protected']): ?>
                                                <span class="badge text-bg-secondary">Bawaan Sistem</span>
                                            <?php endif; ?>
                                        </td>
                                        <td><?php echo htmlspecialchars($role['description'] ?? '-'); ?></td>
                                        <td><?php echo (int) $role['assigned_count']; ?></td>
                                        <td>
                                            <div class="employee-actions">
                                                <a href="portal_role_edit.php?id=<?php echo (int) $role['id']; ?>"
                                                    class="btn btn-sm btn-outline-primary">
                                                    <?php echo in_array($role['slug'], ['superadmin', 'disabled'], true) ? 'Lihat' : 'Edit Permission'; ?>
                                                </a>
                                                <?php if (!$role['is_protected']): ?>
                                                    <form method="POST" action="portal_role_delete.php" class="d-inline"
                                                        onsubmit="return confirm('Hapus role ini?');">
                                                        <?php echo csrf_input(); ?>
                                                        <input type="hidden" name="id" value="<?php echo (int) $role['id']; ?>">
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
