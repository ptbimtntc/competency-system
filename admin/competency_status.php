<?php
require_once "auth.php";
require_once "../config/database.php";
require_once "../includes/competency_helper.php";
require_once "pagination.php";

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
| Filter
|--------------------------------------------------------------------------
*/
$search = trim($_GET['search'] ?? '');
$competencyFilter = isset($_GET['competency_id']) ? (int) $_GET['competency_id'] : 0;
$teamFilter = trim($_GET['team'] ?? '');
$allowedTeams = ['A', 'B', 'C', 'D', 'NS'];
if (!in_array($teamFilter, $allowedTeams, true)) {
    $teamFilter = '';
}
$attendanceFilter = trim($_GET['attendance'] ?? '');
if ($status !== 'ASSIGNED' || !in_array($attendanceFilter, ['confirmed', 'not_confirmed'], true)) {
    $attendanceFilter = '';
}

$competencyListResult = mysqli_query($conn, "SELECT id, name FROM competencies ORDER BY name ASC");
$competencyList = [];
while ($row = mysqli_fetch_assoc($competencyListResult)) {
    $competencyList[] = $row;
}

/*
|--------------------------------------------------------------------------
| Ambil employee competency berdasarkan status + filter
|--------------------------------------------------------------------------
*/
$conditions = ["ec.status = ?", "ec.is_active = 1", "e.is_deleted = 0"];
$params = [$status];
$types = "s";
if ($search !== '') {
    $conditions[] = "(e.nik LIKE ? OR e.name LIKE ? OR e.department LIKE ?)";
    $keyword = "%" . $search . "%";
    array_push($params, $keyword, $keyword, $keyword);
    $types .= "sss";
}
if ($competencyFilter > 0) {
    $conditions[] = "c.id = ?";
    $params[] = $competencyFilter;
    $types .= "i";
}
if ($teamFilter !== '') {
    $conditions[] = "e.team = ?";
    $params[] = $teamFilter;
    $types .= "s";
}
if ($attendanceFilter === 'confirmed') {
    $conditions[] = "ec.attendance_confirmed = 1";
} elseif ($attendanceFilter === 'not_confirmed') {
    $conditions[] = "ec.attendance_confirmed = 0";
}
$countQuery = "
    SELECT COUNT(*) AS total
    FROM employee_competencies ec
    INNER JOIN employees e ON ec.employee_id = e.id
    INNER JOIN competencies c ON ec.competency_id = c.id
    WHERE " . implode(' AND ', $conditions) . "
";
$countStmt = mysqli_prepare($conn, $countQuery);
mysqli_stmt_bind_param($countStmt, $types, ...$params);
mysqli_stmt_execute($countStmt);
$totalRows = (int) mysqli_fetch_assoc(mysqli_stmt_get_result($countStmt))['total'];
$pg = paginate($totalRows, 25);

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
        e.team,
        ec.score,
        c.name AS competency_name,
        c.passing_score
    FROM employee_competencies ec
    INNER JOIN employees e ON ec.employee_id = e.id
    INNER JOIN competencies c ON ec.competency_id = c.id
    WHERE " . implode(' AND ', $conditions) . "
    ORDER BY ec.expiry_date ASC, e.name ASC
    LIMIT ? OFFSET ?
";
$dataParams = $params;
$dataTypes = $types . "ii";
$dataParams[] = $pg['per_page'];
$dataParams[] = $pg['offset'];
$stmt = mysqli_prepare($conn, $query);
mysqli_stmt_bind_param($stmt, $dataTypes, ...$dataParams);
mysqli_stmt_execute($stmt);
$result = mysqli_stmt_get_result($stmt);

$paginationBaseParams = array_filter([
    'status' => $status,
    'search' => $search,
    'competency_id' => $competencyFilter > 0 ? $competencyFilter : null,
    'team' => $teamFilter,
    'attendance' => $attendanceFilter,
], function ($value) {
    return $value !== null && $value !== '';
});

/*
|--------------------------------------------------------------------------
| Helper buat query string filter yang aktif (dipakai di link reset dll)
|--------------------------------------------------------------------------
*/
function buildStatusFilterQuery(string $status, array $overrides = []): string
{
    $base = [
        'status' => $status,
        'search' => $overrides['search'] ?? ($_GET['search'] ?? ''),
        'competency_id' => $overrides['competency_id'] ?? ($_GET['competency_id'] ?? ''),
        'team' => $overrides['team'] ?? ($_GET['team'] ?? ''),
        'attendance' => $overrides['attendance'] ?? ($_GET['attendance'] ?? ''),
    ];
    $base = array_filter($base, fn($v) => $v !== '');
    /*
    | RFC3986 (%20 untuk spasi) bukan default '+' -- supaya hasilnya lolos
    | validasi regex parameter "back" di employee_competency_edit.php, yang
    | tidak mengizinkan karakter '+'.
    */
    return http_build_query($base, '', '&', PHP_QUERY_RFC3986);
}
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
                    <?php echo htmlspecialchars($statusTitles[$status]); ?>
                </h1>
                <p>
                    Daftar competency karyawan dengan status
                    <?php echo htmlspecialchars(competencyStatusLabel($status)); ?>
                </p>
            </div>
            <div class="d-flex gap-2">
                <?php if ($status === 'ASSIGNED'): ?>
                    <a href="confirm_attendance_import.php" class="btn btn-outline-secondary">
                        Import Attendance CSV
                    </a>
                <?php endif; ?>
                <a href="dashboard.php" class="btn btn-outline-secondary">
                    &larr; Back to Dashboard
                </a>
            </div>
        </div>
        <?php if ($success): ?>
            <div class="alert alert-success">
                Attendance berhasil dikonfirmasi.
            </div>
        <?php endif; ?>
        <div class="form-card mb-4">
            <form method="GET" class="row g-3 align-items-end">
                <input type="hidden" name="status" value="<?php echo htmlspecialchars($status); ?>">
                <div class="col-md-3">
                    <label class="form-label">Search</label>
                    <input type="text" name="search" class="form-control"
                        placeholder="NIK, nama, department..." value="<?php echo htmlspecialchars($search); ?>">
                </div>
                <div class="col-md-3">
                    <label class="form-label">Competency</label>
                    <select name="competency_id" class="form-control">
                        <option value="">All Competencies</option>
                        <?php foreach ($competencyList as $c): ?>
                            <option value="<?php echo $c['id']; ?>" <?php
                                echo $competencyFilter === (int) $c['id'] ? 'selected' : '';
                                ?>>
                                <?php echo htmlspecialchars($c['name']); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-2">
                    <label class="form-label">Team</label>
                    <select name="team" class="form-control">
                        <option value="">All Teams</option>
                        <?php foreach ($allowedTeams as $teamOption): ?>
                            <option value="<?php echo $teamOption; ?>" <?php
                                echo $teamFilter === $teamOption ? 'selected' : '';
                                ?>>
                                Team <?php echo $teamOption; ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <?php if ($status === 'ASSIGNED'): ?>
                    <div class="col-md-2">
                        <label class="form-label">Attendance</label>
                        <select name="attendance" class="form-control">
                            <option value="">All</option>
                            <option value="confirmed" <?php echo $attendanceFilter === 'confirmed' ? 'selected' : ''; ?>>
                                Confirmed
                            </option>
                            <option value="not_confirmed" <?php echo $attendanceFilter === 'not_confirmed' ? 'selected' : ''; ?>>
                                Not confirmed
                            </option>
                        </select>
                    </div>
                <?php endif; ?>
                <div class="col-md-2 d-flex gap-2">
                    <button type="submit" class="btn btn-primary w-100">
                        Filter
                    </button>
                    <?php if ($search !== '' || $competencyFilter > 0 || $teamFilter !== '' || $attendanceFilter !== ''): ?>
                        <a href="competency_status.php?status=<?php echo htmlspecialchars($status); ?>"
                            class="btn btn-outline-secondary w-100">
                            Reset
                        </a>
                    <?php endif; ?>
                </div>
            </form>
        </div>
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
                                Score
                            </th>
                            <th>
                                KKM
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
                                        <?php echo $row['score'] !== null ? htmlspecialchars((string) $row['score']) : '-'; ?>
                                    </td>
                                    <td>
                                        <?php echo $row['passing_score'] !== null ? htmlspecialchars((string) $row['passing_score']) : '-'; ?>
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
                                            echo urlencode('competency_status.php?' . buildStatusFilterQuery($status));
                                            ?>" class="btn btn-sm btn-outline-primary">
                                            Detail
                                        </a>
                                    </td>
                                </tr>
                            <?php endwhile; ?>
                        <?php else: ?>
                            <tr>
                                <td colspan="<?php echo $status === 'ASSIGNED' ? 11 : 10; ?>" class="text-center py-5">
                                    Tidak ada data competency dengan status ini.
                                </td>
                            </tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
            <?php if ($totalRows > 0): ?>
                <div class="d-flex flex-wrap justify-content-between align-items-center gap-2 p-3 border-top">
                    <span class="text-muted" style="font-size:13px;">
                        Menampilkan
                        <?php echo $pg['offset'] + 1; ?>&ndash;<?php echo min($pg['offset'] + $pg['per_page'], $totalRows); ?>
                        dari <?php echo $totalRows; ?> data
                    </span>
                    <?php echo render_pagination($pg, $paginationBaseParams); ?>
                </div>
            <?php endif; ?>
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
</main>
</div>
</body>

</html>
