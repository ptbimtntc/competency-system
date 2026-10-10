<?php
require_once "../includes/portal_auth.php";
portal_require_view('dashboard');

$scopeNiks = portal_scope_niks($conn);

/* Total team members */
if ($scopeNiks === null) {
    $totalMembers = mysqli_fetch_assoc(mysqli_query($conn, "SELECT COUNT(*) AS total FROM employees WHERE is_deleted = 0"))['total'];
} else {
    $totalMembers = count($scopeNiks);
}

function portal_count_by_status(mysqli $conn, string $status, ?array $scopeNiks): int
{
    [$scopeClause, $scopeParams] = portal_scope_where($scopeNiks);
    $query = "
        SELECT COUNT(*) AS total
        FROM employee_competencies ec
        INNER JOIN employees e ON e.id = ec.employee_id
        WHERE ec.status = ? AND e.is_deleted = 0 {$scopeClause}
    ";
    $stmt = mysqli_prepare($conn, $query);
    $types = "s" . str_repeat("s", count($scopeParams));
    $params = array_merge([$status], $scopeParams);
    mysqli_stmt_bind_param($stmt, $types, ...$params);
    mysqli_stmt_execute($stmt);
    return (int) mysqli_fetch_assoc(mysqli_stmt_get_result($stmt))['total'];
}

$totalValid = portal_count_by_status($conn, 'VALID', $scopeNiks);
$totalExpired = portal_count_by_status($conn, 'EXPIRED', $scopeNiks);
$totalAssigned = portal_count_by_status($conn, 'ASSIGNED', $scopeNiks);
$totalFailed = portal_count_by_status($conn, 'FAILED', $scopeNiks);
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Dashboard Tim - Bekaert Competency</title>
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
                <div class="dashboard-header">
                    <div>
                        <h1>Dashboard Tim</h1>
                        <p>
                            <?php echo portal_is_superadmin() ? 'Seluruh karyawan' : 'Anda &amp; bawahan Anda'; ?>
                        </p>
                    </div>
                </div>
                <div class="row g-3">
                    <div class="col-md-4 col-sm-6">
                        <div class="stat-card stat-card-primary">
                            <div class="stat-icon"><i class="bi bi-people-fill"></i></div>
                            <div>
                                <div class="stat-label">Anggota Tim</div>
                                <div class="stat-number"><?php echo $totalMembers; ?></div>
                            </div>
                        </div>
                    </div>
                    <div class="col-md-4 col-sm-6">
                        <a href="team_members.php" class="stat-card stat-card-link stat-card-success">
                            <div class="stat-icon"><i class="bi bi-check-circle-fill"></i></div>
                            <div>
                                <div class="stat-label">Valid</div>
                                <div class="stat-number"><?php echo $totalValid; ?></div>
                            </div>
                        </a>
                    </div>
                    <div class="col-md-4 col-sm-6">
                        <a href="team_recertification.php" class="stat-card stat-card-link stat-card-danger">
                            <div class="stat-icon"><i class="bi bi-exclamation-triangle-fill"></i></div>
                            <div>
                                <div class="stat-label">Expired</div>
                                <div class="stat-number"><?php echo $totalExpired; ?></div>
                            </div>
                        </a>
                    </div>
                    <div class="col-md-4 col-sm-6">
                        <a href="team_attendance.php" class="stat-card stat-card-link stat-card-warning">
                            <div class="stat-icon"><i class="bi bi-hourglass-split"></i></div>
                            <div>
                                <div class="stat-label">Assigned</div>
                                <div class="stat-number"><?php echo $totalAssigned; ?></div>
                            </div>
                        </a>
                    </div>
                    <div class="col-md-4 col-sm-6">
                        <a href="team_competency_gap.php" class="stat-card stat-card-link stat-card-danger">
                            <div class="stat-icon"><i class="bi bi-x-circle-fill"></i></div>
                            <div>
                                <div class="stat-label">Failed</div>
                                <div class="stat-number"><?php echo $totalFailed; ?></div>
                            </div>
                        </a>
                    </div>
                </div>
            </div>
        </main>
    </div>
</body>

</html>
