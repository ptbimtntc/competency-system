<?php
require_once "auth.php";
require_once "../config/database.php";
$id = isset($_GET['id'])
    ? (int) $_GET['id']
    : 0;
if ($id <= 0) {
    header("Location: competencies.php");
    exit;
}
/*
|--------------------------------------------------------------------------
| Ambil competency
|--------------------------------------------------------------------------
*/
$query = "
    SELECT id, name, description, default_trainer, default_training_provider
    FROM competencies
    WHERE id = ?
    LIMIT 1
";
$stmt = mysqli_prepare($conn, $query);
mysqli_stmt_bind_param($stmt, "i", $id);
mysqli_stmt_execute($stmt);
$result = mysqli_stmt_get_result($stmt);
$competency = mysqli_fetch_assoc($result);
if (!$competency) {
    die("Competency tidak ditemukan.");
}
/*
|--------------------------------------------------------------------------
| Search & filter
|--------------------------------------------------------------------------
*/
$search = trim($_GET['search'] ?? '');
$teamFilter = trim($_GET['team'] ?? '');
$allowedTeams = ['A', 'B', 'C', 'D', 'NS'];
if (!in_array($teamFilter, $allowedTeams, true)) {
    $teamFilter = '';
}
$departmentFilter = trim($_GET['department'] ?? '');
$supervisorFilter = trim($_GET['supervisor'] ?? '');
$assignmentFilter = trim($_GET['assignment'] ?? '');
if (!in_array($assignmentFilter, ['assigned', 'not_assigned'], true)) {
    $assignmentFilter = '';
}

$departmentListResult = mysqli_query(
    $conn,
    "SELECT DISTINCT department FROM employees
    WHERE is_deleted = 0 AND department IS NOT NULL AND department != ''
    ORDER BY department ASC"
);
$departmentList = [];
while ($row = mysqli_fetch_assoc($departmentListResult)) {
    $departmentList[] = $row['department'];
}
if ($departmentFilter !== '' && !in_array($departmentFilter, $departmentList, true)) {
    $departmentFilter = '';
}

$supervisorListResult = mysqli_query(
    $conn,
    "SELECT DISTINCT supervisor FROM employees
    WHERE is_deleted = 0 AND supervisor IS NOT NULL AND supervisor != ''
    ORDER BY supervisor ASC"
);
$supervisorList = [];
while ($row = mysqli_fetch_assoc($supervisorListResult)) {
    $supervisorList[] = $row['supervisor'];
}
if ($supervisorFilter !== '' && !in_array($supervisorFilter, $supervisorList, true)) {
    $supervisorFilter = '';
}

$conditions = ["is_deleted = 0"];
$params = [];
$types = "";
if ($search !== '') {
    $conditions[] = "(nik LIKE ? OR name LIKE ? OR department LIKE ? OR position LIKE ? OR supervisor LIKE ?)";
    $keyword = "%" . $search . "%";
    array_push($params, $keyword, $keyword, $keyword, $keyword, $keyword);
    $types .= "sssss";
}
if ($teamFilter !== '') {
    $conditions[] = "team = ?";
    $params[] = $teamFilter;
    $types .= "s";
}
if ($departmentFilter !== '') {
    $conditions[] = "department = ?";
    $params[] = $departmentFilter;
    $types .= "s";
}
if ($supervisorFilter !== '') {
    $conditions[] = "supervisor = ?";
    $params[] = $supervisorFilter;
    $types .= "s";
}
if ($assignmentFilter === 'assigned') {
    $conditions[] = "id IN (SELECT employee_id FROM employee_competencies WHERE competency_id = ? AND is_active = 1)";
    $params[] = $id;
    $types .= "i";
} elseif ($assignmentFilter === 'not_assigned') {
    $conditions[] = "id NOT IN (SELECT employee_id FROM employee_competencies WHERE competency_id = ? AND is_active = 1)";
    $params[] = $id;
    $types .= "i";
}
$employeeQuery = "SELECT id, nik, name, department, position, supervisor, team FROM employees";
if (count($conditions) > 0) {
    $employeeQuery .= " WHERE " . implode(" AND ", $conditions);
}
$employeeQuery .= " ORDER BY name ASC";
$employeeStmt = mysqli_prepare($conn, $employeeQuery);
if (count($params) > 0) {
    mysqli_stmt_bind_param($employeeStmt, $types, ...$params);
}
mysqli_stmt_execute($employeeStmt);
$employeeResult = mysqli_stmt_get_result($employeeStmt);
$totalRows = mysqli_num_rows($employeeResult);
/*
|--------------------------------------------------------------------------
| Ambil employee yang sudah aktif untuk competency ini
|--------------------------------------------------------------------------
*/
$assignedStmt = mysqli_prepare(
    $conn,
    "SELECT employee_id FROM employee_competencies WHERE competency_id = ? AND is_active = 1"
);
mysqli_stmt_bind_param($assignedStmt, "i", $id);
mysqli_stmt_execute($assignedStmt);
$assignedResult = mysqli_stmt_get_result($assignedStmt);
$assignedEmployeeIds = [];
while ($row = mysqli_fetch_assoc($assignedResult)) {
    $assignedEmployeeIds[(int) $row['employee_id']] = true;
}
$success = isset($_GET['success']) && $_GET['success'] === '1';
?>

<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>
        Assign Training - Bekaert Competency
    </title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet">
    <link rel="stylesheet" href="../assets/css/style.css?v=<?php echo filemtime(__DIR__ . '/../assets/css/style.css'); ?>">
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
                <i class="bi bi-box-arrow-right"></i> Logout
            </a>
        </div>
    </nav>
<div class="admin-layout">
    <?php include "../includes/admin_sidebar.php"; ?>
    <main class="admin-content">
    <div class="admin-container">
        <div class="page-header">
            <div>
                <h1>
                    Assign Training
                </h1>
                <p>
                    Pilih karyawan yang akan diberi training untuk kompetensi ini
                </p>
            </div>
            <a href="competencies.php" class="btn btn-outline-secondary">
                &larr; Back
            </a>
        </div>
        <?php if ($success): ?>
            <div class="alert alert-success">
                Assign training berhasil disimpan.
            </div>
        <?php endif; ?>
        <!-- COMPETENCY INFO -->
        <div class="form-card mb-4">
            <h4 class="mb-1">
                <?php echo htmlspecialchars($competency['name']); ?>
            </h4>
            <p class="text-muted mb-0">
                <?php echo htmlspecialchars($competency['description'] ?? '-'); ?>
            </p>
        </div>
        <!-- SEARCH -->
        <div class="employee-search">
            <form method="GET" class="row g-2 align-items-end">
                <input type="hidden" name="id" value="<?php echo $id; ?>">
                <div class="col-md-4">
                    <input type="text" name="search" class="form-control"
                        placeholder="Search NIK, name, department, position, supervisor..."
                        value="<?php echo htmlspecialchars($search); ?>">
                </div>
                <div class="col-md-2">
                    <select name="team" class="form-control">
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
                <div class="col-md-2">
                    <select name="department" class="form-control">
                        <option value="">
                            All Departments
                        </option>
                        <?php foreach ($departmentList as $departmentOption): ?>
                            <option value="<?php echo htmlspecialchars($departmentOption); ?>" <?php
                                echo $departmentFilter === $departmentOption ? 'selected' : '';
                                ?>>
                                <?php echo htmlspecialchars($departmentOption); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-4">
                    <select name="supervisor" class="form-control">
                        <option value="">
                            All Supervisors
                        </option>
                        <?php foreach ($supervisorList as $supervisorOption): ?>
                            <option value="<?php echo htmlspecialchars($supervisorOption); ?>" <?php
                                echo $supervisorFilter === $supervisorOption ? 'selected' : '';
                                ?>>
                                <?php echo htmlspecialchars($supervisorOption); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-2">
                    <select name="assignment" class="form-control">
                        <option value="">
                            All
                        </option>
                        <option value="assigned" <?php echo $assignmentFilter === 'assigned' ? 'selected' : ''; ?>>
                            Already Assigned
                        </option>
                        <option value="not_assigned" <?php echo $assignmentFilter === 'not_assigned' ? 'selected' : ''; ?>>
                            Not Yet Assigned
                        </option>
                    </select>
                </div>
                <div class="col-md-2 d-flex gap-2">
                    <button type="submit" class="btn btn-primary w-100">
                        Filter
                    </button>
                    <?php if ($search !== '' || $teamFilter !== '' || $departmentFilter !== '' || $supervisorFilter !== '' || $assignmentFilter !== ''): ?>
                        <a href="competency_assign.php?id=<?php echo $id; ?>" class="btn btn-outline-secondary w-100">
                            Reset
                        </a>
                    <?php endif; ?>
                </div>
            </form>
        </div>
        <!-- ASSIGN FORM -->
        <div class="employee-table-card">
            <form method="POST" action="competency_assign_save.php" id="assignForm">
                <?php echo csrf_input(); ?>
                <input type="hidden" name="competency_id" value="<?php echo $id; ?>">
                <?php if ($search !== ''): ?>
                    <input type="hidden" name="redirect_search" value="<?php echo htmlspecialchars($search); ?>">
                <?php endif; ?>
                <?php if ($teamFilter !== ''): ?>
                    <input type="hidden" name="redirect_team" value="<?php echo htmlspecialchars($teamFilter); ?>">
                <?php endif; ?>
                <?php if ($departmentFilter !== ''): ?>
                    <input type="hidden" name="redirect_department" value="<?php echo htmlspecialchars($departmentFilter); ?>">
                <?php endif; ?>
                <?php if ($supervisorFilter !== ''): ?>
                    <input type="hidden" name="redirect_supervisor" value="<?php echo htmlspecialchars($supervisorFilter); ?>">
                <?php endif; ?>
                <?php if ($assignmentFilter !== ''): ?>
                    <input type="hidden" name="redirect_assignment" value="<?php echo htmlspecialchars($assignmentFilter); ?>">
                <?php endif; ?>
                <div class="p-4 border-bottom">
                    <h4 class="mb-1">
                        Isi Data Training Massal (Opsional)
                    </h4>
                    <p class="text-muted mb-3">
                        Assign di sini hanya untuk MENJADWALKAN training, bukan mencatat training yang sudah
                        selesai. Isi <strong>Scheduled Training Date</strong> untuk menerapkan jadwal ke SEMUA
                        karyawan yang dicentang &mdash; status kompetensi mereka otomatis jadi <strong>Assigned</strong>,
                        dan kuis baru bisa dikerjakan tepat pada tanggal tersebut setelah kehadiran dikonfirmasi
                        (lewat halaman detail kompetensi employee). Training Date, Score, dan Expiry Date terisi
                        otomatis nanti saat karyawan submit kuis.
                    </p>
                    <div class="row g-3">
                        <div class="col-md-4">
                            <label class="form-label">
                                Scheduled Training Date
                            </label>
                            <input type="date" name="bulk_scheduled_training_date" class="form-control">
                        </div>
                        <div class="col-md-4">
                            <label class="form-label">
                                Trainer
                            </label>
                            <input type="text" name="bulk_trainer" class="form-control" placeholder="Nama trainer"
                                value="<?php echo htmlspecialchars($competency['default_trainer'] ?? ''); ?>">
                        </div>
                        <div class="col-md-4">
                            <label class="form-label">
                                Training Provider
                            </label>
                            <input type="text" name="bulk_training_provider" class="form-control"
                                placeholder="Nama provider"
                                value="<?php echo htmlspecialchars($competency['default_training_provider'] ?? ''); ?>">
                        </div>
                        <div class="col-12">
                            <label class="form-label">
                                Notes
                            </label>
                            <input type="text" name="bulk_notes" class="form-control"
                                placeholder="Catatan tambahan (opsional)">
                        </div>
                        <div class="col-12">
                            <div class="form-check">
                                <input class="form-check-input" type="checkbox" name="bulk_overwrite" value="1"
                                    id="bulkOverwrite">
                                <label class="form-check-label" for="bulkOverwrite">
                                    Terapkan juga ke karyawan yang sudah pernah di-assign sebelumnya
                                    (hanya field di atas yang diisi yang akan menimpa data lama)
                                </label>
                            </div>
                        </div>
                    </div>
                </div>
                <div class="p-4 border-bottom d-flex justify-content-between align-items-center">
                    <div>
                        <h4 class="mb-1">
                            Employee List
                        </h4>
                        <p class="text-muted mb-0">
                            Centang karyawan yang akan di-assign, hilangkan centang untuk menonaktifkan (riwayat tetap aman).
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
                <div class="table-responsive">
                    <table class="table table-hover align-middle mb-0">
                        <thead>
                            <tr>
                                <th width="60">
                                    Select
                                </th>
                                <th>
                                    NIK
                                </th>
                                <th>
                                    Name
                                </th>
                                <th>
                                    Department
                                </th>
                                <th>
                                    Position
                                </th>
                                <th>
                                    Supervisor
                                </th>
                                <th>
                                    Team
                                </th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php
                            if (mysqli_num_rows($employeeResult) > 0):
                                while ($employee = mysqli_fetch_assoc($employeeResult)):
                                    $isAssigned = isset($assignedEmployeeIds[(int) $employee['id']]);
                                    ?>
                                    <tr class="assign-row">
                                        <td>
                                            <input type="hidden" name="visible_employees[]" value="<?php echo $employee['id']; ?>">
                                            <input type="checkbox" class="form-check-input" name="employees[]"
                                                value="<?php echo $employee['id']; ?>" <?php echo $isAssigned ? 'checked' : ''; ?>>
                                        </td>
                                        <td>
                                            <?php echo htmlspecialchars($employee['nik']); ?>
                                        </td>
                                        <td>
                                            <strong>
                                                <?php echo htmlspecialchars($employee['name']); ?>
                                            </strong>
                                        </td>
                                        <td>
                                            <?php echo htmlspecialchars($employee['department'] ?? '-'); ?>
                                        </td>
                                        <td>
                                            <?php echo htmlspecialchars($employee['position'] ?? '-'); ?>
                                        </td>
                                        <td>
                                            <?php echo htmlspecialchars($employee['supervisor'] ?? '-'); ?>
                                        </td>
                                        <td>
                                            <?php if (!empty($employee['team'])): ?>
                                                <span class="badge text-bg-info">
                                                    <?php echo htmlspecialchars($employee['team']); ?>
                                                </span>
                                            <?php else: ?>
                                                -
                                            <?php endif; ?>
                                        </td>
                                    </tr>
                                    <?php
                                endwhile;
                            else:
                                ?>
                                <tr>
                                    <td colspan="7" class="text-center py-5">
                                        Tidak ada data karyawan.
                                    </td>
                                </tr>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
                <?php if ($totalRows > 0): ?>
                    <div class="d-flex flex-wrap justify-content-between align-items-center gap-2 p-3 border-top">
                        <span class="text-muted" style="font-size:13px;" data-paging-summary></span>
                        <nav><ul class="pagination justify-content-center mb-0" data-paging-nav></ul></nav>
                    </div>
                <?php endif; ?>
                <div class="p-4 border-top">
                    <?php if (admin_can_write()): ?>
                        <button type="submit" class="btn btn-primary">
                            Save Assignment
                        </button>
                    <?php else: ?>
                        <span class="text-muted">Akun read-only &mdash; perubahan tidak bisa disimpan.</span>
                    <?php endif; ?>
                </div>
            </form>
        </div>
    </div>
    <script>
        document.getElementById('selectAllBtn').addEventListener('click', function () {
            document.querySelectorAll('#assignForm input[type=checkbox]').forEach(function (cb) {
                cb.checked = true;
            });
        });
        document.getElementById('clearAllBtn').addEventListener('click', function () {
            document.querySelectorAll('#assignForm input[type=checkbox]').forEach(function (cb) {
                cb.checked = false;
            });
        });
        (function () {
            var PAGE_SIZE = 25;
            var rows = Array.prototype.slice.call(document.querySelectorAll('.assign-row'));
            var summary = document.querySelector('[data-paging-summary]');
            var nav = document.querySelector('[data-paging-nav]');
            if (!rows.length || !nav) {
                return;
            }
            var totalPages = Math.max(1, Math.ceil(rows.length / PAGE_SIZE));
            var current = 1;

            function render() {
                var start = (current - 1) * PAGE_SIZE;
                var end = Math.min(start + PAGE_SIZE, rows.length);
                rows.forEach(function (row, i) {
                    row.style.display = (i >= start && i < end) ? '' : 'none';
                });
                if (summary) {
                    summary.textContent = 'Menampilkan ' + (start + 1) + '–' + end + ' dari ' + rows.length + ' karyawan';
                }
                var items = '';
                items += '<li class="page-item' + (current <= 1 ? ' disabled' : '') + '">'
                    + '<a class="page-link" href="#" data-page="' + Math.max(1, current - 1) + '">&laquo;</a></li>';
                for (var p = 1; p <= totalPages; p++) {
                    items += '<li class="page-item' + (p === current ? ' active' : '') + '">'
                        + '<a class="page-link" href="#" data-page="' + p + '">' + p + '</a></li>';
                }
                items += '<li class="page-item' + (current >= totalPages ? ' disabled' : '') + '">'
                    + '<a class="page-link" href="#" data-page="' + Math.min(totalPages, current + 1) + '">&raquo;</a></li>';
                nav.innerHTML = items;
                nav.querySelectorAll('a[data-page]').forEach(function (a) {
                    a.addEventListener('click', function (e) {
                        e.preventDefault();
                        current = parseInt(a.getAttribute('data-page'), 10);
                        render();
                    });
                });
            }
            render();
        })();
    </script>
</main>
</div>
</body>

</html>
