<?php
require_once "../includes/portal_auth.php";
portal_require_view('team_attendance');
require_once "../includes/competency_helper.php";

$scopeNiks = portal_scope_niks($conn);
$canExecute = portal_can_execute('team_attendance');

$competencyResult = mysqli_query($conn, "SELECT id, name FROM competencies ORDER BY name ASC");
$competencies = [];
while ($row = mysqli_fetch_assoc($competencyResult)) {
    $competencies[] = $row;
}

$competencyId = isset($_GET['competency_id']) ? (int) $_GET['competency_id'] : 0;
$scheduledDate = trim($_GET['scheduled_date'] ?? '');
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $scheduledDate)) {
    $scheduledDate = '';
}
$sessionSelected = $competencyId > 0 && $scheduledDate !== '';
$selectedCompetencyName = '';
$assignedRows = [];
$employees = [];

if ($sessionSelected) {
    $competencyNameStmt = mysqli_prepare($conn, "SELECT name FROM competencies WHERE id = ? LIMIT 1");
    mysqli_stmt_bind_param($competencyNameStmt, "i", $competencyId);
    mysqli_stmt_execute($competencyNameStmt);
    $competencyRow = mysqli_fetch_assoc(mysqli_stmt_get_result($competencyNameStmt));
    if (!$competencyRow) {
        die("Competency tidak ditemukan.");
    }
    $selectedCompetencyName = $competencyRow['name'];

    [$scopeClause, $scopeParams] = portal_scope_where($scopeNiks, 'nik');
    $employeeQuery = "SELECT id, nik, name, department, position, team FROM employees WHERE is_deleted = 0 {$scopeClause} ORDER BY name ASC";
    $employeeStmt = mysqli_prepare($conn, $employeeQuery);
    if (!empty($scopeParams)) {
        mysqli_stmt_bind_param($employeeStmt, str_repeat("s", count($scopeParams)), ...$scopeParams);
    }
    mysqli_stmt_execute($employeeStmt);
    $employeeResult = mysqli_stmt_get_result($employeeStmt);
    while ($row = mysqli_fetch_assoc($employeeResult)) {
        $employees[] = $row;
    }

    $assignedStmt = mysqli_prepare(
        $conn,
        "SELECT id, employee_id, scheduled_training_date, attendance_confirmed, status
         FROM employee_competencies WHERE competency_id = ? AND is_active = 1"
    );
    mysqli_stmt_bind_param($assignedStmt, "i", $competencyId);
    mysqli_stmt_execute($assignedStmt);
    $assignedResult = mysqli_stmt_get_result($assignedStmt);
    while ($row = mysqli_fetch_assoc($assignedResult)) {
        $assignedRows[(int) $row['employee_id']] = $row;
    }
}

$success = isset($_GET['success']) && $_GET['success'] === '1';
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Konfirmasi Kehadiran Tim - Bekaert Competency</title>
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
                        <h1>Konfirmasi Kehadiran</h1>
                        <p>Centang anggota tim yang hadir pada sesi training terjadwal</p>
                    </div>
                </div>
                <?php if ($success): ?>
                    <div class="alert alert-success">Attendance berhasil disimpan.</div>
                <?php endif; ?>
                <?php if (!$canExecute): ?>
                    <div class="alert alert-warning">Anda hanya bisa melihat, tidak bisa mengubah kehadiran.</div>
                <?php endif; ?>

                <div class="form-card mb-4">
                    <h5 class="mb-3">Pilih Sesi Training</h5>
                    <form method="GET" class="row g-3">
                        <div class="col-md-5">
                            <label class="form-label">Competency</label>
                            <select name="competency_id" class="form-control" required>
                                <option value="">-- Pilih Competency --</option>
                                <?php foreach ($competencies as $c): ?>
                                    <option value="<?php echo $c['id']; ?>" <?php echo $competencyId === (int) $c['id'] ? 'selected' : ''; ?>>
                                        <?php echo htmlspecialchars($c['name']); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-3">
                            <label class="form-label">Scheduled Training Date</label>
                            <input type="date" name="scheduled_date" class="form-control"
                                value="<?php echo htmlspecialchars($scheduledDate); ?>" required>
                        </div>
                        <div class="col-md-4 d-flex align-items-end">
                            <button type="submit" class="btn btn-primary w-100">Tampilkan Anggota Tim</button>
                        </div>
                    </form>
                </div>

                <?php if ($sessionSelected): ?>
                    <div class="employee-table-card">
                        <form method="POST" action="team_attendance_save.php" id="attendanceForm">
                            <?php echo csrf_input(); ?>
                            <input type="hidden" name="competency_id" value="<?php echo $competencyId; ?>">
                            <input type="hidden" name="scheduled_training_date" value="<?php echo htmlspecialchars($scheduledDate); ?>">
                            <div class="p-4 border-bottom d-flex justify-content-between align-items-center">
                                <div>
                                    <h4 class="mb-1"><?php echo htmlspecialchars($selectedCompetencyName); ?></h4>
                                    <p class="text-muted mb-0">
                                        Training tanggal <strong><?php echo date('d M Y', strtotime($scheduledDate)); ?></strong>.
                                        Centang anggota tim yang HADIR pada tanggal ini.
                                    </p>
                                </div>
                                <?php if ($canExecute): ?>
                                    <div class="d-flex gap-2">
                                        <button type="button" class="btn btn-sm btn-outline-secondary" id="selectAllBtn">Select All</button>
                                        <button type="button" class="btn btn-sm btn-outline-secondary" id="clearAllBtn">Clear All</button>
                                    </div>
                                <?php endif; ?>
                            </div>
                            <div class="table-responsive">
                                <table class="table table-hover align-middle mb-0">
                                    <thead>
                                        <tr>
                                            <th width="60">Hadir</th>
                                            <th>NIK</th>
                                            <th>Name</th>
                                            <th>Department</th>
                                            <th>Team</th>
                                            <th>Info</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php if (count($employees) > 0): ?>
                                            <?php foreach ($employees as $employee): ?>
                                                <?php
                                                $existing = $assignedRows[(int) $employee['id']] ?? null;
                                                $isThisSession = $existing && $existing['scheduled_training_date'] === $scheduledDate;
                                                $isChecked = $isThisSession && (int) $existing['attendance_confirmed'] === 1;
                                                ?>
                                                <tr>
                                                    <td>
                                                        <input type="hidden" name="visible_employees[]" value="<?php echo $employee['id']; ?>">
                                                        <input type="checkbox" class="form-check-input attendance-checkbox"
                                                            name="attended_employees[]" value="<?php echo $employee['id']; ?>"
                                                            <?php echo $isChecked ? 'checked' : ''; ?>
                                                            <?php echo $canExecute ? '' : 'disabled'; ?>>
                                                    </td>
                                                    <td><?php echo htmlspecialchars($employee['nik']); ?></td>
                                                    <td><strong><?php echo htmlspecialchars($employee['name']); ?></strong></td>
                                                    <td><?php echo htmlspecialchars($employee['department'] ?? '-'); ?></td>
                                                    <td>
                                                        <?php if (!empty($employee['team'])): ?>
                                                            <span class="badge text-bg-info"><?php echo htmlspecialchars($employee['team']); ?></span>
                                                        <?php else: ?>-<?php endif; ?>
                                                    </td>
                                                    <td>
                                                        <?php if ($existing && !$isThisSession && !empty($existing['scheduled_training_date'])): ?>
                                                            <span class="badge text-bg-warning">
                                                                Dijadwalkan lain: <?php echo date('d M Y', strtotime($existing['scheduled_training_date'])); ?>
                                                            </span>
                                                        <?php elseif ($existing && !$isThisSession): ?>
                                                            <span class="badge text-bg-secondary"><?php echo htmlspecialchars(competencyStatusLabel($existing['status'])); ?></span>
                                                        <?php endif; ?>
                                                    </td>
                                                </tr>
                                            <?php endforeach; ?>
                                        <?php else: ?>
                                            <tr><td colspan="6" class="text-center py-4">Tidak ada anggota tim.</td></tr>
                                        <?php endif; ?>
                                    </tbody>
                                </table>
                            </div>
                            <?php if ($canExecute): ?>
                                <div class="p-4 border-top">
                                    <button type="submit" class="btn btn-primary">Save Attendance</button>
                                </div>
                            <?php endif; ?>
                        </form>
                    </div>
                <?php endif; ?>
            </div>
            <script>
                document.getElementById('selectAllBtn')?.addEventListener('click', function () {
                    document.querySelectorAll('.attendance-checkbox').forEach(function (cb) { cb.checked = true; });
                });
                document.getElementById('clearAllBtn')?.addEventListener('click', function () {
                    document.querySelectorAll('.attendance-checkbox').forEach(function (cb) { cb.checked = false; });
                });
            </script>
        </main>
    </div>
</body>

</html>
