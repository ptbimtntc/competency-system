<?php
require_once "auth.php";
require_once "../config/database.php";
require_once "../includes/competency_helper.php";
/*
|--------------------------------------------------------------------------
| Daftar competency (untuk dropdown pemilihan sesi training)
|--------------------------------------------------------------------------
*/
$competencyResult = mysqli_query(
    $conn,
    "SELECT id, name FROM competencies ORDER BY name ASC"
);
$competencies = [];
while ($row = mysqli_fetch_assoc($competencyResult)) {
    $competencies[] = $row;
}
/*
|--------------------------------------------------------------------------
| Daftar sesi training terjadwal (shortcut, biar admin tidak perlu ingat
| tanggal persis)
|--------------------------------------------------------------------------
*/
$sessionResult = mysqli_query(
    $conn,
    "SELECT
        ec.competency_id,
        ec.scheduled_training_date,
        c.name AS competency_name,
        COUNT(*) AS total_scheduled,
        SUM(ec.attendance_confirmed = 1) AS total_confirmed
    FROM employee_competencies ec
    INNER JOIN competencies c ON c.id = ec.competency_id
    WHERE ec.scheduled_training_date IS NOT NULL
        AND ec.is_active = 1
    GROUP BY ec.competency_id, ec.scheduled_training_date
    ORDER BY ec.scheduled_training_date DESC
    LIMIT 30"
);
$sessions = [];
while ($row = mysqli_fetch_assoc($sessionResult)) {
    $sessions[] = $row;
}
/*
|--------------------------------------------------------------------------
| Sesi yang dipilih
|--------------------------------------------------------------------------
*/
$competencyId = isset($_GET['competency_id']) ? (int) $_GET['competency_id'] : 0;
$scheduledDate = trim($_GET['scheduled_date'] ?? '');
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $scheduledDate)) {
    $scheduledDate = '';
}
$search = trim($_GET['search'] ?? '');
$teamFilter = trim($_GET['team'] ?? '');
$allowedTeams = ['A', 'B', 'C', 'D', 'NS'];
if (!in_array($teamFilter, $allowedTeams, true)) {
    $teamFilter = '';
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
    /*
    |--------------------------------------------------------------------------
    | Ambil SEMUA employee (search/team difilter di client-side, lihat JS
    | di bawah)
    |--------------------------------------------------------------------------
    |
    | Sengaja tidak difilter lewat query + reload GET seperti sebelumnya --
    | search yang reload halaman menghapus centangan attendance yang belum
    | disimpan (checkbox yang sudah dicentang tapi belum di-submit hilang
    | begitu form GET search disubmit), sehingga kehadiran yang sudah
    | dicentang admin bisa batal tersimpan tanpa disadari.
    |
    */
    $employeeQuery = "SELECT id, nik, name, department, position, supervisor, team FROM employees WHERE is_deleted = 0 ORDER BY name ASC";
    $employeeResult = mysqli_query($conn, $employeeQuery);
    while ($row = mysqli_fetch_assoc($employeeResult)) {
        $employees[] = $row;
    }
    /*
    |--------------------------------------------------------------------------
    | Ambil employee_competencies aktif untuk competency ini (semua tanggal)
    |--------------------------------------------------------------------------
    |
    | Dipakai untuk mengelompokkan employee: yang sudah di-assign ke
    | competency ini (apapun tanggalnya) vs yang belum sama sekali.
    |
    */
    $assignedStmt = mysqli_prepare(
        $conn,
        "SELECT
            id,
            employee_id,
            scheduled_training_date,
            attendance_confirmed,
            status
        FROM employee_competencies
        WHERE competency_id = ? AND is_active = 1"
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
    <title>
        Attendance - Bekaert Competency
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
                    Attendance
                </h1>
                <p>
                    Centang karyawan yang hadir pada sesi training terjadwal
                </p>
            </div>
            <a href="dashboard.php" class="btn btn-outline-secondary">
                &larr; Dashboard
            </a>
        </div>
        <?php if ($success): ?>
            <div class="alert alert-success">
                Attendance berhasil disimpan.
            </div>
        <?php endif; ?>
        <!-- PILIH SESI -->
        <div class="form-card mb-4">
            <h5 class="mb-3">
                Pilih Sesi Training
            </h5>
            <form method="GET" class="row g-3">
                <div class="col-md-5">
                    <label class="form-label">
                        Competency
                    </label>
                    <select name="competency_id" class="form-control" required>
                        <option value="">
                            -- Pilih Competency --
                        </option>
                        <?php foreach ($competencies as $c): ?>
                            <option value="<?php echo $c['id']; ?>" <?php
                                echo $competencyId === (int) $c['id'] ? 'selected' : '';
                                ?>>
                                <?php echo htmlspecialchars($c['name']); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-3">
                    <label class="form-label">
                        Scheduled Training Date
                    </label>
                    <input type="date" name="scheduled_date" class="form-control"
                        value="<?php echo htmlspecialchars($scheduledDate); ?>" required>
                </div>
                <div class="col-md-4 d-flex align-items-end">
                    <button type="submit" class="btn btn-primary w-100">
                        Tampilkan Karyawan
                    </button>
                </div>
            </form>
            <?php if (count($sessions) > 0): ?>
                <hr>
                <div class="mb-2 text-muted" style="font-size:13px;">
                    Sesi terjadwal terbaru:
                </div>
                <div class="d-flex flex-wrap gap-2">
                    <?php foreach ($sessions as $s): ?>
                        <?php
                        $isCurrent = $sessionSelected
                            && (int) $s['competency_id'] === $competencyId
                            && $s['scheduled_training_date'] === $scheduledDate;
                        ?>
                        <a href="attendance.php?competency_id=<?php echo $s['competency_id']; ?>&scheduled_date=<?php
                            echo $s['scheduled_training_date'];
                            ?>" class="btn btn-sm <?php echo $isCurrent ? 'btn-primary' : 'btn-outline-secondary'; ?>">
                            <?php echo htmlspecialchars($s['competency_name']); ?>
                            &mdash;
                            <?php echo date('d M Y', strtotime($s['scheduled_training_date'])); ?>
                            <span class="badge text-bg-light text-dark ms-1">
                                <?php echo (int) $s['total_confirmed']; ?>/<?php echo (int) $s['total_scheduled']; ?> hadir
                            </span>
                        </a>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </div>
        <?php if ($sessionSelected): ?>
            <!-- SEARCH (client-side, tanpa reload -- supaya centangan attendance yang
                 belum disimpan tidak hilang saat admin mencari/memfilter nama lain) -->
            <div class="employee-search">
                <div class="row g-2">
                    <div class="col-md-6">
                        <input type="text" id="employeeSearchInput" class="form-control"
                            placeholder="Search NIK, name, department, position, supervisor..."
                            value="<?php echo htmlspecialchars($search); ?>">
                    </div>
                    <div class="col-md-4">
                        <select id="employeeTeamFilter" class="form-control">
                            <option value="">
                                All Teams
                            </option>
                            <?php foreach ($allowedTeams as $teamOption): ?>
                                <option value="<?php echo $teamOption; ?>" <?php
                                    echo $teamFilter === $teamOption ? 'selected' : '';
                                    ?>>
                                    Team <?php echo $teamOption; ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-md-2 d-flex align-items-center text-muted" style="font-size:13px;">
                        Filter langsung, tidak reload
                    </div>
                </div>
            </div>
            <!-- ATTENDANCE FORM -->
            <div class="employee-table-card">
                <form method="POST" action="attendance_save.php" id="attendanceForm">
                    <?php echo csrf_input(); ?>
                    <input type="hidden" name="competency_id" value="<?php echo $competencyId; ?>">
                    <input type="hidden" name="scheduled_training_date" value="<?php echo htmlspecialchars($scheduledDate); ?>">
                    <?php if ($search !== ''): ?>
                        <input type="hidden" name="redirect_search" value="<?php echo htmlspecialchars($search); ?>">
                    <?php endif; ?>
                    <?php if ($teamFilter !== ''): ?>
                        <input type="hidden" name="redirect_team" value="<?php echo htmlspecialchars($teamFilter); ?>">
                    <?php endif; ?>
                    <div class="p-4 border-bottom d-flex justify-content-between align-items-center">
                        <div>
                            <h4 class="mb-1">
                                <?php echo htmlspecialchars($selectedCompetencyName); ?>
                            </h4>
                            <p class="text-muted mb-0">
                                Training tanggal
                                <strong><?php echo date('d M Y', strtotime($scheduledDate)); ?></strong>.
                                Centang karyawan yang HADIR pada tanggal ini. Karyawan yang dicentang tapi belum
                                pernah di-assign akan otomatis ditambahkan ke kompetensi ini (status Assigned).
                            </p>
                        </div>
                        <div class="d-flex gap-2">
                            <button type="button" class="btn btn-sm btn-outline-secondary" id="selectAllBtn">
                                Select All
                            </button>
                            <button type="button" class="btn btn-sm btn-outline-secondary" id="clearAllBtn">
                                Clear All
                            </button>
                        </div>
                    </div>
                    <?php
                    $assignedGroup = [];
                    $notAssignedGroup = [];
                    foreach ($employees as $employee) {
                        if (isset($assignedRows[(int) $employee['id']])) {
                            $assignedGroup[] = $employee;
                        } else {
                            $notAssignedGroup[] = $employee;
                        }
                    }
                    ?>
                    <div class="p-4 border-bottom">
                        <h5 class="mb-0">
                            Sudah Di-assign ke Kompetensi Ini
                            <span class="badge text-bg-info"><?php echo count($assignedGroup); ?></span>
                        </h5>
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
                                    <th>Info Jadwal</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php if (count($assignedGroup) > 0): ?>
                                    <?php foreach ($assignedGroup as $employee): ?>
                                        <?php
                                        $row = $assignedRows[(int) $employee['id']];
                                        $isThisSession = $row['scheduled_training_date'] === $scheduledDate;
                                        $isChecked = $isThisSession && (int) $row['attendance_confirmed'] === 1;
                                        ?>
                                        <tr class="employee-row"
                                            data-search="<?php echo htmlspecialchars(strtolower(
                                                $employee['nik'] . ' ' . $employee['name'] . ' ' .
                                                ($employee['department'] ?? '') . ' ' . ($employee['position'] ?? '') . ' ' .
                                                ($employee['supervisor'] ?? '')
                                            )); ?>"
                                            data-team="<?php echo htmlspecialchars($employee['team'] ?? ''); ?>">
                                            <td>
                                                <input type="hidden" name="visible_employees[]" value="<?php echo $employee['id']; ?>">
                                                <input type="checkbox" class="form-check-input attendance-checkbox"
                                                    name="attended_employees[]" value="<?php echo $employee['id']; ?>"
                                                    <?php echo $isChecked ? 'checked' : ''; ?>>
                                            </td>
                                            <td><?php echo htmlspecialchars($employee['nik']); ?></td>
                                            <td><strong><?php echo htmlspecialchars($employee['name']); ?></strong></td>
                                            <td><?php echo htmlspecialchars($employee['department'] ?? '-'); ?></td>
                                            <td>
                                                <?php if (!empty($employee['team'])): ?>
                                                    <span class="badge text-bg-info"><?php echo htmlspecialchars($employee['team']); ?></span>
                                                <?php else: ?>
                                                    -
                                                <?php endif; ?>
                                            </td>
                                            <td>
                                                <?php if ($isThisSession): ?>
                                                    <span class="text-muted">Dijadwalkan sesi ini</span>
                                                <?php elseif (!empty($row['scheduled_training_date'])): ?>
                                                    <span class="badge text-bg-warning">
                                                        Dijadwalkan lain: <?php echo date('d M Y', strtotime($row['scheduled_training_date'])); ?>
                                                    </span>
                                                <?php else: ?>
                                                    <span class="badge <?php
                                                        echo match ($row['status']) {
                                                            'VALID' => 'text-bg-success',
                                                            'EXPIRING_SOON' => 'text-bg-warning',
                                                            'EXPIRED' => 'text-bg-danger',
                                                            'FAILED' => 'text-bg-danger',
                                                            default => 'text-bg-secondary',
                                                        };
                                                        ?>">
                                                        <?php echo htmlspecialchars(competencyStatusLabel($row['status'])); ?>
                                                    </span>
                                                <?php endif; ?>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                <?php else: ?>
                                    <tr>
                                        <td colspan="6" class="text-center py-4">
                                            Tidak ada karyawan yang sudah di-assign ke kompetensi ini.
                                        </td>
                                    </tr>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                    <div class="p-4 border-bottom border-top">
                        <h5 class="mb-0">
                            Belum Di-assign ke Kompetensi Ini
                            <span class="badge text-bg-secondary"><?php echo count($notAssignedGroup); ?></span>
                        </h5>
                        <p class="text-muted mb-0 mt-1" style="font-size:13px;">
                            Centang kalau ada yang hadir walau belum di-assign sebelumnya (walk-in) &mdash;
                            akan otomatis dibuatkan assignment baru untuk kompetensi ini.
                        </p>
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
                                </tr>
                            </thead>
                            <tbody>
                                <?php if (count($notAssignedGroup) > 0): ?>
                                    <?php foreach ($notAssignedGroup as $employee): ?>
                                        <tr class="employee-row"
                                            data-search="<?php echo htmlspecialchars(strtolower(
                                                $employee['nik'] . ' ' . $employee['name'] . ' ' .
                                                ($employee['department'] ?? '') . ' ' . ($employee['position'] ?? '') . ' ' .
                                                ($employee['supervisor'] ?? '')
                                            )); ?>"
                                            data-team="<?php echo htmlspecialchars($employee['team'] ?? ''); ?>">
                                            <td>
                                                <input type="hidden" name="visible_employees[]" value="<?php echo $employee['id']; ?>">
                                                <input type="checkbox" class="form-check-input attendance-checkbox"
                                                    name="attended_employees[]" value="<?php echo $employee['id']; ?>">
                                            </td>
                                            <td><?php echo htmlspecialchars($employee['nik']); ?></td>
                                            <td><strong><?php echo htmlspecialchars($employee['name']); ?></strong></td>
                                            <td><?php echo htmlspecialchars($employee['department'] ?? '-'); ?></td>
                                            <td>
                                                <?php if (!empty($employee['team'])): ?>
                                                    <span class="badge text-bg-info"><?php echo htmlspecialchars($employee['team']); ?></span>
                                                <?php else: ?>
                                                    -
                                                <?php endif; ?>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                <?php else: ?>
                                    <tr>
                                        <td colspan="5" class="text-center py-4">
                                            Semua karyawan (sesuai filter) sudah di-assign ke kompetensi ini.
                                        </td>
                                    </tr>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                    <div class="p-4 border-top">
                        <?php if (admin_can_write()): ?>
                            <button type="submit" class="btn btn-primary">
                                Save Attendance
                            </button>
                        <?php else: ?>
                            <span class="text-muted">Akun read-only &mdash; perubahan tidak bisa disimpan.</span>
                        <?php endif; ?>
                    </div>
                </form>
            </div>
        <?php endif; ?>
    </div>
    <script>
        (function () {
            function visibleCheckboxes() {
                return Array.prototype.filter.call(
                    document.querySelectorAll('.attendance-checkbox'),
                    function (cb) {
                        var row = cb.closest('tr');
                        return row && row.style.display !== 'none';
                    }
                );
            }

            document.getElementById('selectAllBtn')?.addEventListener('click', function () {
                visibleCheckboxes().forEach(function (cb) {
                    cb.checked = true;
                });
            });
            document.getElementById('clearAllBtn')?.addEventListener('click', function () {
                visibleCheckboxes().forEach(function (cb) {
                    cb.checked = false;
                });
            });

            /*
            | Filter search/team dilakukan di sini (client-side, tanpa reload)
            | supaya checkbox yang sudah dicentang tapi belum disimpan tidak
            | hilang saat admin mencari nama lain -- sebelumnya search pakai
            | form GET yang reload halaman dan menghapus semua centangan yang
            | belum di-submit.
            */
            var searchInput = document.getElementById('employeeSearchInput');
            var teamSelect = document.getElementById('employeeTeamFilter');
            var rows = document.querySelectorAll('.employee-row');

            function applyFilter() {
                var keyword = (searchInput ? searchInput.value : '').trim().toLowerCase();
                var team = teamSelect ? teamSelect.value : '';
                rows.forEach(function (row) {
                    var matchesSearch = keyword === '' || row.dataset.search.indexOf(keyword) !== -1;
                    var matchesTeam = team === '' || row.dataset.team === team;
                    row.style.display = (matchesSearch && matchesTeam) ? '' : 'none';
                });
            }

            searchInput?.addEventListener('input', applyFilter);
            teamSelect?.addEventListener('change', applyFilter);
            applyFilter();
        })();
    </script>
</body>

</html>
