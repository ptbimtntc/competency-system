<?php
require_once "../includes/portal_auth.php";
if (!portal_is_superadmin()) {
    http_response_code(403);
    die('Halaman ini hanya bisa diakses oleh superadmin.');
}

$success = isset($_GET['success']) && $_GET['success'] === '1';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_validate();
    $allowedIds = $_POST['allowed_ids'] ?? [];
    if (!is_array($allowedIds)) {
        $allowedIds = [];
    }
    $allowedIds = array_values(array_unique(array_filter(array_map('intval', $allowedIds), fn($v) => $v > 0)));

    mysqli_begin_transaction($conn);
    try {
        mysqli_query($conn, "UPDATE competencies SET portal_reset_allowed = 0");
        if (count($allowedIds) > 0) {
            $placeholders = implode(',', array_fill(0, count($allowedIds), '?'));
            $types = str_repeat('i', count($allowedIds));
            $stmt = mysqli_prepare($conn, "UPDATE competencies SET portal_reset_allowed = 1 WHERE id IN ({$placeholders})");
            mysqli_stmt_bind_param($stmt, $types, ...$allowedIds);
            mysqli_stmt_execute($stmt);
        }
        mysqli_commit($conn);
    } catch (\Throwable $e) {
        mysqli_rollback($conn);
    }
    header("Location: portal_reset_settings.php?success=1");
    exit;
}

$competencyResult = mysqli_query(
    $conn,
    "SELECT id, code, name, portal_reset_allowed FROM competencies ORDER BY name ASC"
);
$competencies = [];
while ($row = mysqli_fetch_assoc($competencyResult)) {
    $competencies[] = $row;
}
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Pengaturan Reset Competency - Bekaert Competency</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet">
    <link rel="stylesheet" href="../assets/css/style.css?v=<?php echo filemtime(__DIR__ . '/../assets/css/style.css'); ?>">
</head>

<body class="admin-page">
    <nav class="admin-navbar">
        <div class="admin-brand">
            <img src="../assets/images/Bekaert_logo_neg_RGB.png" alt="Bekaert" class="brand-logo">
            <span>Employee Portal</span>
        </div>
        <div class="admin-user">
            <span>
                <?php echo htmlspecialchars($_SESSION['portal_name']); ?>
                <span class="badge text-bg-light"><?php echo htmlspecialchars($_SESSION['portal_role_name']); ?></span>
            </span>
            <a href="logout.php"><i class="bi bi-box-arrow-right"></i> Logout</a>
        </div>
    </nav>
    <div class="admin-layout">
        <?php include "../includes/portal_sidebar.php"; ?>
        <main class="admin-content">
            <div class="admin-container">
                <div class="page-header">
                    <div>
                        <h1>Pengaturan Reset Competency</h1>
                        <p>
                            Pilih competency mana saja yang boleh direset dari Failed ke Assigned lewat portal
                            (supervisor/superadmin). Competency yang tidak dicentang akan membuat tombol reset
                            tidak bisa diklik di halaman Failed Competencies portal. Pengaturan ini tidak
                            berlaku untuk reset dari sisi admin.
                        </p>
                    </div>
                    <a href="dashboard.php" class="btn btn-outline-secondary">&larr; Back to Dashboard</a>
                </div>

                <?php if ($success): ?>
                    <div class="alert alert-success">Pengaturan berhasil disimpan.</div>
                <?php endif; ?>

                <div class="employee-table-card">
                    <form method="POST">
                        <?php echo csrf_input(); ?>
                        <div class="table-responsive">
                            <table class="table table-hover align-middle mb-0">
                                <thead>
                                    <tr>
                                        <th width="60">
                                            <input type="checkbox" class="form-check-input" id="selectAllCompetencies">
                                        </th>
                                        <th>Code</th>
                                        <th>Competency</th>
                                        <th>Boleh Direset via Portal</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php if (count($competencies) > 0): ?>
                                        <?php foreach ($competencies as $competency): ?>
                                            <tr>
                                                <td>
                                                    <input type="checkbox" class="form-check-input competency-allow"
                                                        name="allowed_ids[]" value="<?php echo $competency['id']; ?>"
                                                        <?php echo (int) $competency['portal_reset_allowed'] === 1 ? 'checked' : ''; ?>>
                                                </td>
                                                <td><?php echo htmlspecialchars($competency['code'] ?: '-'); ?></td>
                                                <td><?php echo htmlspecialchars($competency['name']); ?></td>
                                                <td>
                                                    <?php if ((int) $competency['portal_reset_allowed'] === 1): ?>
                                                        <span class="badge text-bg-success">Diizinkan</span>
                                                    <?php else: ?>
                                                        <span class="badge text-bg-secondary">Tidak diizinkan</span>
                                                    <?php endif; ?>
                                                </td>
                                            </tr>
                                        <?php endforeach; ?>
                                    <?php else: ?>
                                        <tr><td colspan="4" class="text-center py-5">Belum ada competency.</td></tr>
                                    <?php endif; ?>
                                </tbody>
                            </table>
                        </div>
                        <?php if (count($competencies) > 0): ?>
                            <div class="p-4 border-top">
                                <button type="submit" class="btn btn-primary">Simpan Pengaturan</button>
                            </div>
                        <?php endif; ?>
                    </form>
                </div>
            </div>
        </main>
    </div>
    <script>
        (function () {
            var selectAll = document.getElementById('selectAllCompetencies');
            var checkboxes = document.querySelectorAll('.competency-allow');
            selectAll?.addEventListener('change', function () {
                checkboxes.forEach(function (cb) {
                    cb.checked = selectAll.checked;
                });
            });
        })();
    </script>
</body>

</html>
