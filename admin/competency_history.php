<?php
require_once "auth.php";
require_once "../config/database.php";
require_once "../includes/competency_helper.php";

$employeeCompetencyId = isset($_GET['employee_competency_id'])
    ? (int) $_GET['employee_competency_id']
    : 0;
if ($employeeCompetencyId <= 0) {
    header("Location: employees.php");
    exit;
}
/*
|--------------------------------------------------------------------------
| Data assignment + employee + competency
|--------------------------------------------------------------------------
*/
$headerStmt = mysqli_prepare(
    $conn,
    "SELECT
        ec.id,
        ec.employee_id,
        e.nik,
        e.name AS employee_name,
        e.department,
        e.position,
        c.name AS competency_name,
        c.code AS competency_code
    FROM employee_competencies ec
    INNER JOIN employees e ON e.id = ec.employee_id
    INNER JOIN competencies c ON c.id = ec.competency_id
    WHERE ec.id = ?
    LIMIT 1"
);
mysqli_stmt_bind_param($headerStmt, "i", $employeeCompetencyId);
mysqli_stmt_execute($headerStmt);
$info = mysqli_fetch_assoc(mysqli_stmt_get_result($headerStmt));
if (!$info) {
    die("Data competency employee tidak ditemukan.");
}
/*
|--------------------------------------------------------------------------
| Halaman kembali (validasi seperti employee_competency_edit.php)
|--------------------------------------------------------------------------
*/
$back = trim($_GET['back'] ?? '');
if (!preg_match('/^[a-zA-Z0-9_\-]+\.php(\?[a-zA-Z0-9_\-\.=&%]*)?$/', $back)) {
    $back = "employee_competency_edit.php?id=" . $employeeCompetencyId;
}
/*
|--------------------------------------------------------------------------
| Ambil riwayat
|--------------------------------------------------------------------------
*/
$historyRows = [];
$historyMissing = false;
try {
    $historyStmt = mysqli_prepare(
        $conn,
        "SELECT
            training_date,
            scheduled_training_date,
            expiry_date,
            score,
            status,
            certificate_number,
            trainer,
            training_provider,
            notes,
            source,
            recorded_by,
            recorded_at
        FROM competency_history
        WHERE employee_competency_id = ?
        ORDER BY recorded_at DESC, id DESC"
    );
    if ($historyStmt === false) {
        $historyMissing = true;
    } else {
        mysqli_stmt_bind_param($historyStmt, "i", $employeeCompetencyId);
        mysqli_stmt_execute($historyStmt);
        $historyResult = mysqli_stmt_get_result($historyStmt);
        while ($row = mysqli_fetch_assoc($historyResult)) {
            $historyRows[] = $row;
        }
    }
} catch (\Throwable $e) {
    $historyMissing = true;
}
$sourceLabels = [
    'quiz' => 'Kuis',
    'manual' => 'Edit manual',
    'import' => 'Import CSV',
    'reschedule' => 'Dijadwalkan ulang',
];
?>

<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>
        Riwayat Competency - Bekaert Competency
    </title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="../assets/css/style.css">
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
                <?php echo htmlspecialchars($_SESSION['admin_name']); ?>
            </span>
            <a href="logout.php">
                Logout
            </a>
        </div>
    </nav>
    <div class="admin-container">
        <div class="page-header">
            <div>
                <h1>
                    Riwayat Siklus Training
                </h1>
                <p>
                    Jejak setiap siklus training / sertifikat untuk satu competency karyawan
                </p>
            </div>
            <a href="<?php echo htmlspecialchars($back); ?>" class="btn btn-outline-secondary">
                &larr; Back
            </a>
        </div>

        <div class="employee-info-card mb-4">
            <div>
                <small>Employee</small>
                <h3><?php echo htmlspecialchars($info['employee_name']); ?></h3>
            </div>
            <div>
                <small>NIK</small>
                <strong><?php echo htmlspecialchars($info['nik']); ?></strong>
            </div>
            <div>
                <small>Department</small>
                <strong><?php echo htmlspecialchars($info['department'] ?? '-'); ?></strong>
            </div>
            <div>
                <small>Competency</small>
                <strong>
                    <?php echo htmlspecialchars($info['competency_name']); ?>
                    <?php if ($info['competency_code'] !== null && $info['competency_code'] !== ''): ?>
                        (<?php echo htmlspecialchars($info['competency_code']); ?>)
                    <?php endif; ?>
                </strong>
            </div>
        </div>

        <?php if ($historyMissing): ?>
            <div class="alert alert-warning">
                Tabel <code>competency_history</code> belum tersedia. Jalankan migrasi
                <code>database/migrations/2026_08_27_add_competency_history.sql</code> terlebih dahulu.
            </div>
        <?php endif; ?>

        <div class="employee-table-card">
            <div class="table-responsive">
                <table class="table table-hover align-middle mb-0">
                    <thead>
                        <tr>
                            <th>Dicatat</th>
                            <th>Sumber</th>
                            <th>Training Date</th>
                            <th>Expiry Date</th>
                            <th>Score</th>
                            <th>Status</th>
                            <th>Certificate No.</th>
                            <th>Trainer</th>
                            <th>Oleh</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (count($historyRows) > 0): ?>
                            <?php foreach ($historyRows as $row): ?>
                                <tr>
                                    <td>
                                        <?php echo htmlspecialchars(
                                            date('d M Y H:i', strtotime($row['recorded_at']))
                                        ); ?>
                                    </td>
                                    <td>
                                        <span class="badge text-bg-secondary">
                                            <?php echo htmlspecialchars(
                                                $sourceLabels[$row['source']] ?? $row['source']
                                            ); ?>
                                        </span>
                                    </td>
                                    <td>
                                        <?php echo empty($row['training_date'])
                                            ? '-'
                                            : date('d M Y', strtotime($row['training_date'])); ?>
                                    </td>
                                    <td>
                                        <?php echo empty($row['expiry_date'])
                                            ? '-'
                                            : date('d M Y', strtotime($row['expiry_date'])); ?>
                                    </td>
                                    <td>
                                        <?php echo $row['score'] !== null
                                            ? (int) $row['score'] . ' / 100'
                                            : '-'; ?>
                                    </td>
                                    <td>
                                        <span class="badge <?php
                                            echo match ($row['status']) {
                                                'VALID' => 'text-bg-success',
                                                'EXPIRING_SOON' => 'text-bg-warning',
                                                'EXPIRED' => 'text-bg-danger',
                                                'FAILED' => 'text-bg-danger',
                                                'ASSIGNED' => 'text-bg-info',
                                                default => 'text-bg-secondary',
                                            };
                                        ?>">
                                            <?php echo htmlspecialchars(
                                                competencyStatusLabel($row['status'])
                                            ); ?>
                                        </span>
                                    </td>
                                    <td>
                                        <?php echo $row['certificate_number'] !== null && $row['certificate_number'] !== ''
                                            ? htmlspecialchars($row['certificate_number'])
                                            : '-'; ?>
                                    </td>
                                    <td>
                                        <?php echo $row['trainer'] !== null && $row['trainer'] !== ''
                                            ? htmlspecialchars($row['trainer'])
                                            : '-'; ?>
                                    </td>
                                    <td>
                                        <?php echo $row['recorded_by'] !== null && $row['recorded_by'] !== ''
                                            ? htmlspecialchars($row['recorded_by'])
                                            : '<span class="text-muted">system</span>'; ?>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        <?php else: ?>
                            <tr>
                                <td colspan="9" class="text-center py-5">
                                    <?php echo $historyMissing
                                        ? 'Riwayat tidak dapat dimuat.'
                                        : 'Belum ada riwayat tercatat untuk competency ini.'; ?>
                                </td>
                            </tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</body>

</html>
