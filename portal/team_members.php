<?php
require_once "../includes/portal_auth.php";
portal_require_view('team_members');

$scopeNiks = portal_scope_niks($conn);
[$scopeClause, $scopeParams] = portal_scope_where($scopeNiks, 'nik');

$query = "
    SELECT id, nik, name, department, position, team, license_id, photo
    FROM employees
    WHERE is_deleted = 0 {$scopeClause}
    ORDER BY name ASC
";
$stmt = mysqli_prepare($conn, $query);
if (!empty($scopeParams)) {
    mysqli_stmt_bind_param($stmt, str_repeat("s", count($scopeParams)), ...$scopeParams);
}
mysqli_stmt_execute($stmt);
$result = mysqli_stmt_get_result($stmt);
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Anggota Tim - Bekaert Competency</title>
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
                        <h1>Anggota Tim</h1>
                        <p><?php echo portal_is_superadmin() ? 'Seluruh karyawan' : 'Anda &amp; bawahan Anda'; ?></p>
                    </div>
                </div>
                <div class="employee-table-card">
                    <div class="table-responsive">
                        <table class="table table-hover align-middle mb-0">
                            <thead>
                                <tr>
                                    <th>#</th>
                                    <th>Photo</th>
                                    <th>NIK</th>
                                    <th>Name</th>
                                    <th>Department</th>
                                    <th>Position</th>
                                    <th>Team</th>
                                    <th>Action</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php $number = 1; if (mysqli_num_rows($result) > 0): while ($employee = mysqli_fetch_assoc($result)): ?>
                                    <tr>
                                        <td><?php echo $number++; ?></td>
                                        <td>
                                            <?php if (!empty($employee['photo']) && is_file(__DIR__ . "/../uploads/employees/" . $employee['photo'])): ?>
                                                <img src="../uploads/employees/<?php echo htmlspecialchars($employee['photo']); ?>"
                                                    class="employee-photo-small" alt="Employee photo">
                                            <?php else: ?>
                                                <div class="employee-photo-placeholder">
                                                    <?php echo strtoupper(substr($employee['name'], 0, 1)); ?>
                                                </div>
                                            <?php endif; ?>
                                        </td>
                                        <td><?php echo htmlspecialchars($employee['nik']); ?></td>
                                        <td><strong><?php echo htmlspecialchars($employee['name']); ?></strong></td>
                                        <td><?php echo htmlspecialchars($employee['department'] ?? '-'); ?></td>
                                        <td><?php echo htmlspecialchars($employee['position'] ?? '-'); ?></td>
                                        <td>
                                            <?php if (!empty($employee['team'])): ?>
                                                <span class="badge text-bg-info"><?php echo htmlspecialchars($employee['team']); ?></span>
                                            <?php else: ?>
                                                -
                                            <?php endif; ?>
                                        </td>
                                        <td>
                                            <a href="../index.php?nik=<?php echo urlencode($employee['nik']); ?>"
                                                class="btn btn-sm btn-outline-secondary" target="_blank">
                                                Lihat Kompetensi
                                            </a>
                                        </td>
                                    </tr>
                                <?php endwhile; else: ?>
                                    <tr>
                                        <td colspan="8" class="text-center py-5">Tidak ada data.</td>
                                    </tr>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </main>
    </div>
</body>

</html>
