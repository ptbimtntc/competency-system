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
        rel="stylesheet"
        href="../assets/css/style.css"
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
            Logout
        </a>
    </div>
</nav>
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
    <div class="col-md-3">
        <a href="employees.php" class="stat-card stat-card-link">
            <div class="stat-label">
                Employees
            </div>
            <div class="stat-number">
                <?php
                echo $totalEmployee;
                ?>
            </div>
        </a>
    </div>
    <div class="col-md-3">
        <a href="competencies.php" class="stat-card stat-card-link">
            <div class="stat-label">
                Competencies
            </div>
            <div class="stat-number">
                <?php
                echo $totalCompetency;
                ?>
            </div>
        </a>
    </div>
    <div class="col-md-3">
        <a href="competency_status.php?status=VALID" class="stat-card stat-card-link">
            <div class="stat-label">
                Valid
            </div>
            <div class="stat-number">
                <?php
                echo $totalValid;
                ?>
            </div>
        </a>
    </div>
    <div class="col-md-3">
        <a href="competency_status.php?status=EXPIRED" class="stat-card stat-card-link">
            <div class="stat-label">
                Expired
            </div>
            <div class="stat-number">
                <?php
                echo $totalExpired;
                ?>
            </div>
        </a>
    </div>
    <div class="col-md-3">
        <a href="competency_status.php?status=ASSIGNED" class="stat-card stat-card-link">
            <div class="stat-label">
                Assigned
            </div>
            <div class="stat-number">
                <?php
                echo $totalAssigned;
                ?>
            </div>
        </a>
    </div>
    <div class="col-md-3">
        <a href="competency_status.php?status=FAILED" class="stat-card stat-card-link">
            <div class="stat-label">
                Failed
            </div>
            <div class="stat-number">
                <?php
                echo $totalFailed;
                ?>
            </div>
        </a>
    </div>
</div>
<div class="admin-menu-section">
    <h2>
        Management
    </h2>
    <div class="row g-3">
        <div class="col-md-6">
            <a
                href="employees.php"
                class="admin-menu-card"
            >
                <div class="admin-menu-icon">
                    👤
                </div>
                <div>
                    <strong>
                        Employees
                    </strong>
                    <span>
                        Manage employee data
                    </span>
                </div>
            </a>
        </div>
        <div class="col-md-6">
            <a
                href="competencies.php"
                class="admin-menu-card"
            >
                <div class="admin-menu-icon">
                    ⚡
                </div>
                <div>
                    <strong>
                        Competencies
                    </strong>
                    <span>
                        Manage competency items
                    </span>
                </div>
            </a>
        </div>
        <div class="col-md-6">
            <a
                href="attendance.php"
                class="admin-menu-card"
            >
                <div class="admin-menu-icon">
                    📋
                </div>
                <div>
                    <strong>
                        Attendance
                    </strong>
                    <span>
                        Konfirmasi kehadiran karyawan pada scheduled training
                    </span>
                </div>
            </a>
        </div>
        <div class="col-md-6">
            <a
                href="recertification.php"
                class="admin-menu-card"
            >
                <div class="admin-menu-icon">
                    ⏰
                </div>
                <div>
                    <strong>
                        Recertification Due
                    </strong>
                    <span>
                        Competency yang sudah / akan habis masa berlakunya
                    </span>
                </div>
            </a>
        </div>
        <div class="col-md-6">
            <a
                href="competency_matrix.php"
                class="admin-menu-card"
            >
                <div class="admin-menu-icon">
                    🗂️
                </div>
                <div>
                    <strong>
                        Competency Matrix
                    </strong>
                    <span>
                        Grid status competency seluruh karyawan
                    </span>
                </div>
            </a>
        </div>
        <div class="col-md-6">
            <a
                href="competency_gap.php"
                class="admin-menu-card"
            >
                <div class="admin-menu-icon">
                    🎯
                </div>
                <div>
                    <strong>
                        Competency Gap
                    </strong>
                    <span>
                        Karyawan vs competency wajib untuk posisinya
                    </span>
                </div>
            </a>
        </div>
        <div class="col-md-6">
            <a
                href="position_requirements.php"
                class="admin-menu-card"
            >
                <div class="admin-menu-icon">
                    📌
                </div>
                <div>
                    <strong>
                        Required Competency
                    </strong>
                    <span>
                        Atur competency wajib per posisi
                    </span>
                </div>
            </a>
        </div>
        <div class="col-md-6">
            <a
                href="qr_codes.php"
                class="admin-menu-card"
            >
                <div class="admin-menu-icon">
                    🔗
                </div>
                <div>
                    <strong>
                        QR Codes
                    </strong>
                    <span>
                        Generate verification QR codes
                    </span>
                </div>
            </a>
        </div>
        <div class="col-md-6">
            <a
                href="signatories.php"
                class="admin-menu-card"
            >
                <div class="admin-menu-icon">
                    ✍️
                </div>
                <div>
                    <strong>
                        Signatories
                    </strong>
                    <span>
                        Manage trainer &amp; manager digital signatures
                    </span>
                </div>
            </a>
        </div>
        <?php if (admin_is_superadmin()): ?>
            <div class="col-md-6">
                <a
                    href="admins.php"
                    class="admin-menu-card"
                >
                    <div class="admin-menu-icon">
                        🔐
                    </div>
                    <div>
                        <strong>
                            Admin Accounts
                        </strong>
                        <span>
                            Kelola akun &amp; role admin
                        </span>
                    </div>
                </a>
            </div>
        <?php endif; ?>
    </div>
</div>
</div>
</body>
</html>