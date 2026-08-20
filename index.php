<?php
require_once "config/database.php";
require_once "includes/competency_helper.php";
// Ambil data karyawan berdasarkan NIK
$nik = trim($_GET['nik'] ?? '');
if ($nik === '') {
    die("NIK tidak ditemukan.");
}
$query = "SELECT * FROM employees WHERE nik = ?";
$stmt = mysqli_prepare($conn, $query);
mysqli_stmt_bind_param($stmt, "s", $nik);
mysqli_stmt_execute($stmt);
$result = mysqli_stmt_get_result($stmt);
$employee = mysqli_fetch_assoc($result);
if (!$employee) {
    die("Data karyawan tidak ditemukan.");
}
// Ambil kompetensi milik karyawan
$queryCompetency = "
    SELECT
        ec.id,
        ec.training_date,
        ec.expiry_date,
        ec.trainer,
        ec.certificate_number,
        ec.status,
        c.name AS competency_name,
        c.description,
        c.scope,
        c.icon
    FROM employee_competencies ec
    INNER JOIN competencies c
        ON ec.competency_id = c.id
    WHERE ec.employee_id = ?
        AND ec.status != 'NOT_TAKEN'
    ORDER BY ec.training_date DESC
";
$stmtCompetency = mysqli_prepare($conn, $queryCompetency);
mysqli_stmt_bind_param(
    $stmtCompetency,
    "i",
    $employee['id']
);
mysqli_stmt_execute($stmtCompetency);
$competencies = mysqli_stmt_get_result($stmtCompetency);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>
        Employee Competency Verification
    </title>
    <!-- Bootstrap -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <!-- CSS kita sendiri -->
    <link rel="stylesheet" href="assets/css/style.css">
</head>
<body>
    <div class="container py-4">
        <div class="verification-card">
            <!-- Header -->
            <div class="verification-header">
                <div class="logo">
                    <img src="assets/images/Bekaert_logo_neg_RGB.png" alt="Bekaert" class="brand-logo">
                </div>
                <div class="verification-title">
                    Employee Competency<br>
                    Verification
                </div>
            </div>
            <div class="employee-profile">
                <div class="employee-photo">
                    <?php if (!empty($employee['photo'])): ?>
                        <img src="uploads/employees/<?php echo htmlspecialchars($employee['photo']); ?>" alt="Employee Photo">
                    <?php else: ?>
                        <div class="photo-placeholder">
                            <?php echo strtoupper(substr($employee['name'], 0, 1)); ?>
                        </div>
                    <?php endif; ?>
                </div>
                <h2>
                    <?php echo htmlspecialchars($employee['name']); ?>
                </h2>
                <div class="employee-nik">
                    NIK : <?php echo htmlspecialchars($employee['nik']); ?>
                </div>
                <div class="employee-position">
                    <?php echo htmlspecialchars($employee['department']); ?>
                    -
                    <?php echo htmlspecialchars($employee['position']); ?>
                </div>
                <div class="status-valid">
                    <span class="status-icon">
                        ✓
                    </span>
                    STATUS: VALID
                </div>
            </div>
            <div class="section-title">
                KOMPETENSI
            </div>
            <div class="competency-list">
                <?php while ($competency = mysqli_fetch_assoc($competencies)): ?>
                    <a href="employee.php?id=<?php echo $competency['id']; ?>" class="competency-item">
                        <div class="competency-icon">
                            <?php
                            echo htmlspecialchars(
                                $competency['icon'] ?? '★'
                            );
                            ?>
                        </div>
                        <div class="competency-content">
                            <div class="competency-name">
                                <?php
                                echo htmlspecialchars(
                                    $competency['competency_name']
                                );
                                ?>
                            </div>
                            <div class="competency-info">
                                <div>
                                    Training Date
                                    <strong>
                                        <?php
                                        echo empty($competency['training_date'])
                                            ? '-'
                                            : date('d M Y', strtotime($competency['training_date']));
                                        ?>
                                    </strong>
                                </div>
                                <div>
                                    Expiry Date
                                    <strong>
                                        <?php
                                        echo empty($competency['expiry_date'])
                                            ? 'No Expiry'
                                            : date('d M Y', strtotime($competency['expiry_date']));
                                        ?>
                                    </strong>
                                </div>
                                <div>
                                    Trainer
                                    <strong>
                                        <?php
                                        echo htmlspecialchars(
                                            $competency['trainer'] ?? '-'
                                        );
                                        ?>
                                    </strong>
                                </div>
                                <div>
                                    Certificate No.
                                    <strong>
                                        <?php
                                        echo htmlspecialchars(
                                            $competency['certificate_number'] ?? '-'
                                        );
                                        ?>
                                    </strong>
                                </div>
                            </div>
                        </div>
                        <div class="competency-status <?php echo competencyStatusColorClass($competency['status']); ?>">
                            <?php
                            echo htmlspecialchars(
                                competencyStatusLabel($competency['status'])
                            );
                            ?>
                        </div>
                        <div class="competency-arrow">
                            ›
                        </div>
                    </a>
                <?php endwhile; ?>
            </div>
        </div>
</body>
</html>