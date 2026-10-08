<?php
require_once "auth.php";
require_once "../config/database.php";
require_once "../includes/competency_helper.php";

$allowedStatuses = ['VALID', 'EXPIRING_SOON', 'EXPIRED', 'NOT_TAKEN', 'ASSIGNED', 'FAILED'];
$status = strtoupper(trim($_GET['status'] ?? ''));
if (!in_array($status, $allowedStatuses, true)) {
    $status = 'EXPIRED';
}

$statusTitles = [
    'VALID' => 'Valid Competencies',
    'EXPIRING_SOON' => 'Expiring Soon Competencies',
    'EXPIRED' => 'Expired Competencies',
    'NOT_TAKEN' => 'Not Taken Competencies',
    'ASSIGNED' => 'Assigned Competencies',
    'FAILED' => 'Failed Competencies',
];

$success = isset($_GET['success']) && $_GET['success'] === '1';

/*
|--------------------------------------------------------------------------
| Ambil employee competency berdasarkan status
|--------------------------------------------------------------------------
*/
$query = "
    SELECT
        ec.id,
        ec.training_date,
        ec.scheduled_training_date,
        ec.attendance_confirmed,
        ec.expiry_date,
        ec.trainer,
        ec.certificate_number,
        e.id AS employee_id,
        e.nik,
        e.name AS employee_name,
        e.department,
        e.position,
        c.name AS competency_name
    FROM employee_competencies ec
    INNER JOIN employees e ON ec.employee_id = e.id
    INNER JOIN competencies c ON ec.competency_id = c.id
    WHERE ec.status = ?
        AND ec.is_active = 1
        AND e.is_deleted = 0
    ORDER BY ec.expiry_date ASC, e.name ASC
";
$stmt = mysqli_prepare($conn, $query);
mysqli_stmt_bind_param($stmt, "s", $status);
mysqli_stmt_execute($stmt);
$result = mysqli_stmt_get_result($stmt);
?>

<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>
        <?php echo htmlspecialchars($statusTitles[$status]); ?> - Bekaert Competency
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
                    <?php echo htmlspecialchars($statusTitles[$status]); ?>
                </h1>
                <p>
                    Daftar competency karyawan dengan status
                    <?php echo htmlspecialchars(competencyStatusLabel($status)); ?>
                </p>
            </div>
            <a href="dashboard.php" class="btn btn-outline-secondary">
                &larr; Back to Dashboard
            </a>
        </div>
        <?php if ($success): ?>
            <div class="alert alert-success">
                Attendance berhasil dikonfirmasi.
            </div>
        <?php endif; ?>
        <div class="employee-table-card">
            <?php if ($status === 'ASSIGNED'): ?>
                <form method="POST" action="confirm_attendance_bulk.php" id="bulkAttendanceForm">
                    <?php echo csrf_input(); ?>
            <?php endif; ?>
            <div class="table-responsive">
                <table class="table table-hover align-middle mb-0">
                    <thead>
                        <tr>
                            <?php if ($status === 'ASSIGNED'): ?>
                                <th width="40">
                                    <input type="checkbox" class="form-check-input" id="selectAllAssigned">
                                </th>
                            <?php endif; ?>
                            <th>
                                NIK
                            </th>
                            <th>
                                Employee
                            </th>
                            <th>
                                Department
                            </th>
                            <th>
                                Competency
                            </th>
                            <th>
                                <?php echo $status === 'ASSIGNED' ? 'Scheduled Date' : 'Training Date'; ?>
                            </th>
                            <th>
                                <?php echo $status === 'ASSIGNED' ? 'Attendance' : 'Expiry Date'; ?>
                            </th>
                            <th>
                                Status
                            </th>
                            <th>
                                Action
                            </th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (mysqli_num_rows($result) > 0): ?>
                            <?php while ($row = mysqli_fetch_assoc($result)): ?>
                                <tr>
                                    <?php if ($status === 'ASSIGNED'): ?>
                                        <td>
                                            <?php if ((int) $row['attendance_confirmed'] === 1): ?>
                                                <input type="checkbox" class="form-check-input" checked disabled title="Sudah dikonfirmasi hadir">
                                            <?php else: ?>
                                                <input type="checkbox" class="form-check-input attendance-select" name="confirm_ids[]" value="<?php echo (int) $row['id']; ?>">
                                            <?php endif; ?>
                                        </td>
                                    <?php endif; ?>
                                    <td>
                                        <?php echo htmlspecialchars($row['nik']); ?>
                                    </td>
                                    <td>
                                        <strong>
                                            <?php echo htmlspecialchars($row['employee_name']); ?>
                                        </strong>
                                    </td>
                                    <td>
                                        <?php echo htmlspecialchars($row['department'] ?? '-'); ?>
                                    </td>
                                    <td>
                                        <?php echo htmlspecialchars($row['competency_name']); ?>
                                    </td>
                                    <td>
                                        <?php if ($status === 'ASSIGNED'): ?>
                                            <?php
                                            echo empty($row['scheduled_training_date'])
                                                ? '-'
                                                : date('d M Y', strtotime($row['scheduled_training_date']));
                                            ?>
                                        <?php else: ?>
                                            <?php
                                            echo empty($row['training_date'])
                                                ? '-'
                                                : date('d M Y', strtotime($row['training_date']));
                                            ?>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <?php if ($status === 'ASSIGNED'): ?>
                                            <?php if ((int) $row['attendance_confirmed'] === 1): ?>
                                                <span class="badge text-bg-success">Confirmed</span>
                                            <?php else: ?>
                                                <span class="badge text-bg-secondary">Not confirmed</span>
                                            <?php endif; ?>
                                        <?php else: ?>
                                            <?php
                                            echo empty($row['expiry_date'])
                                                ? 'No Expiry'
                                                : date('d M Y', strtotime($row['expiry_date']));
                                            ?>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <span class="badge <?php
                                            echo match ($status) {
                                                'VALID' => 'text-bg-success',
                                                'EXPIRING_SOON' => 'text-bg-warning',
                                                'EXPIRED' => 'text-bg-danger',
                                                'FAILED' => 'text-bg-danger',
                                                'ASSIGNED' => 'text-bg-info',
                                                default => 'text-bg-secondary',
                                            };
                                        ?>">
                                            <?php echo htmlspecialchars(competencyStatusLabel($status)); ?>
                                        </span>
                                    </td>
                                    <td>
                                        <a href="employee_competency_edit.php?id=<?php echo $row['id']; ?>&back=<?php
                                            echo urlencode('competency_status.php?status=' . $status);
                                            ?>" class="btn btn-sm btn-outline-primary">
                                            Detail
                                        </a>
                                    </td>
                                </tr>
                            <?php endwhile; ?>
                        <?php else: ?>
                            <tr>
                                <td colspan="<?php echo $status === 'ASSIGNED' ? 9 : 8; ?>" class="text-center py-5">
                                    Tidak ada data competency dengan status ini.
                                </td>
                            </tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
            <?php if ($status === 'ASSIGNED'): ?>
                <?php if (mysqli_num_rows($result) > 0 && admin_can_write()): ?>
                    <div class="p-4 border-top">
                        <button type="submit" class="btn btn-primary">
                            Konfirmasi Hadir Terpilih
                        </button>
                    </div>
                <?php endif; ?>
                </form>
            <?php endif; ?>
        </div>
    </div>
    <?php if ($status === 'ASSIGNED'): ?>
        <script>
            (function () {
                var selectAll = document.getElementById('selectAllAssigned');
                var checkboxes = document.querySelectorAll('.attendance-select');
                selectAll?.addEventListener('change', function () {
                    checkboxes.forEach(function (cb) {
                        cb.checked = selectAll.checked;
                    });
                });
            })();
        </script>
    <?php endif; ?>
</body>

</html>
