<?php
require_once "auth.php";
require_once "../config/database.php";
/*
|--------------------------------------------------------------------------
| Ambil statistik
|--------------------------------------------------------------------------
*/
/* Total Employee */
$queryEmployee = "
    SELECT COUNT(*) AS total
    FROM employees
    WHERE is_deleted = 0
";
$resultEmployee =
    mysqli_query(
        $conn,
        $queryEmployee
    );
$totalEmployee =
    mysqli_fetch_assoc(
        $resultEmployee
    )['total'];
/* Total Competency */
$queryCompetency = "
    SELECT COUNT(*) AS total
    FROM competencies
";
$resultCompetency =
    mysqli_query(
        $conn,
        $queryCompetency
    );
$totalCompetency =
    mysqli_fetch_assoc(
        $resultCompetency
    )['total'];
/* Total Valid */
$queryValid = "
    SELECT COUNT(*) AS total
    FROM employee_competencies ec
    INNER JOIN employees e ON e.id = ec.employee_id
    WHERE ec.status = 'VALID' AND e.is_deleted = 0
";
$resultValid =
    mysqli_query(
        $conn,
        $queryValid
    );
$totalValid =
    mysqli_fetch_assoc(
        $resultValid
    )['total'];
/* Total Expired */
$queryExpired = "
    SELECT COUNT(*) AS total
    FROM employee_competencies ec
    INNER JOIN employees e ON e.id = ec.employee_id
    WHERE ec.status = 'EXPIRED' AND e.is_deleted = 0
";
$resultExpired =
    mysqli_query(
        $conn,
        $queryExpired
    );
$totalExpired =
    mysqli_fetch_assoc(
        $resultExpired
    )['total'];
/* Total Assigned */
$queryAssigned = "
    SELECT COUNT(*) AS total
    FROM employee_competencies ec
    INNER JOIN employees e ON e.id = ec.employee_id
    WHERE ec.status = 'ASSIGNED' AND e.is_deleted = 0
";
$resultAssigned =
    mysqli_query(
        $conn,
        $queryAssigned
    );
$totalAssigned =
    mysqli_fetch_assoc(
        $resultAssigned
    )['total'];
/* Total Failed */
$queryFailed = "
    SELECT COUNT(*) AS total
    FROM employee_competencies ec
    INNER JOIN employees e ON e.id = ec.employee_id
    WHERE ec.status = 'FAILED' AND e.is_deleted = 0
";
$resultFailed =
    mysqli_query(
        $conn,
        $queryFailed
    );
$totalFailed =
    mysqli_fetch_assoc(
        $resultFailed
    )['total'];
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta
        name="viewport"
        content="width=device-width, initial-scale=1.0"
    >
    <title>
        Dashboard - Bekaert Competency
    </title>
    <link
        href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css"
        rel="stylesheet"
    >
    <link
        href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css"
        rel="stylesheet"
    >
    <link
        rel="stylesheet"
        href="../assets/css/style.css?v=<?php echo filemtime(__DIR__ . '/../assets/css/style.css'); ?>"
    >
</head>
<body class="admin-page">
<nav class="admin-navbar">
    <div class="admin-brand">
            <img src="../assets/images/Bekaert_logo_neg_RGB.png" alt="Bekaert" class="brand-logo">
            <span>
                Competency System
            </span>
        </div>
    <div class="admin-user">
        <span>
            <?php
            echo htmlspecialchars(
                $_SESSION['admin_name']
            );
            ?>
            <span class="badge text-bg-light">
                <?php echo htmlspecialchars(role_label(current_admin_role())); ?>
            </span>
        </span>
        <a href="logout.php">
            <i class="bi bi-box-arrow-right"></i> Logout
        </a>
    </div>
</nav>
<div class="admin-layout">
    <?php include "../includes/admin_sidebar.php"; ?>
    <main class="admin-content">
<div class="admin-container">
    <div class="dashboard-header">
        <div>
            <h1>
                Dashboard
            </h1>
            <p>
                Employee Competency Management
            </p>
        </div>
    </div>
<div class="row g-3">
    <div class="col-md-4 col-sm-6">
        <a href="employees.php" class="stat-card stat-card-link stat-card-primary">
            <div class="stat-icon"><i class="bi bi-people-fill"></i></div>
            <div>
                <div class="stat-label">Employees</div>
                <div class="stat-number"><?php echo $totalEmployee; ?></div>
            </div>
        </a>
    </div>
    <div class="col-md-4 col-sm-6">
        <a href="competencies.php" class="stat-card stat-card-link stat-card-primary">
            <div class="stat-icon"><i class="bi bi-lightning-charge-fill"></i></div>
            <div>
                <div class="stat-label">Competencies</div>
                <div class="stat-number"><?php echo $totalCompetency; ?></div>
            </div>
        </a>
    </div>
    <div class="col-md-4 col-sm-6">
        <a href="competency_status.php?status=VALID" class="stat-card stat-card-link stat-card-success">
            <div class="stat-icon"><i class="bi bi-check-circle-fill"></i></div>
            <div>
                <div class="stat-label">Valid</div>
                <div class="stat-number"><?php echo $totalValid; ?></div>
            </div>
        </a>
    </div>
    <div class="col-md-4 col-sm-6">
        <a href="competency_status.php?status=EXPIRED" class="stat-card stat-card-link stat-card-danger">
            <div class="stat-icon"><i class="bi bi-exclamation-triangle-fill"></i></div>
            <div>
                <div class="stat-label">Expired</div>
                <div class="stat-number"><?php echo $totalExpired; ?></div>
            </div>
        </a>
    </div>
    <div class="col-md-4 col-sm-6">
        <a href="competency_status.php?status=ASSIGNED" class="stat-card stat-card-link stat-card-warning">
            <div class="stat-icon"><i class="bi bi-hourglass-split"></i></div>
            <div>
                <div class="stat-label">Assigned</div>
                <div class="stat-number"><?php echo $totalAssigned; ?></div>
            </div>
        </a>
    </div>
    <div class="col-md-4 col-sm-6">
        <a href="competency_status.php?status=FAILED" class="stat-card stat-card-link stat-card-danger">
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