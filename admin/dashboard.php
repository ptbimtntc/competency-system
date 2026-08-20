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
    FROM employee_competencies
    WHERE status = 'VALID'
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
    FROM employee_competencies
    WHERE status = 'EXPIRED'
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
        <div class="stat-card">
            <div class="stat-label">
                Employees
            </div>
            <div class="stat-number">
                <?php
                echo $totalEmployee;
                ?>
            </div>
        </div>
    </div>
    <div class="col-md-3">
        <div class="stat-card">
            <div class="stat-label">
                Competencies
            </div>
            <div class="stat-number">
                <?php
                echo $totalCompetency;
                ?>
            </div>
        </div>
    </div>
    <div class="col-md-3">
        <div class="stat-card">
            <div class="stat-label">
                Valid
            </div>
            <div class="stat-number">
                <?php
                echo $totalValid;
                ?>
            </div>
        </div>
    </div>
    <div class="col-md-3">
        <div class="stat-card">
            <div class="stat-label">
                Expired
            </div>
            <div class="stat-number">
                <?php
                echo $totalExpired;
                ?>
            </div>
        </div>
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
    </div>
</div>
</div>
</body>
</html>