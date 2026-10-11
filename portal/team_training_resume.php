<?php
require_once "../includes/portal_auth.php";
portal_require_view('dashboard');
require_once "../includes/competency_helper.php";
require_once "../admin/pagination.php";

/*
|--------------------------------------------------------------------------
| Resume Assignment & Kehadiran Training (tim) -- drill-down dari dashboard
|--------------------------------------------------------------------------
*/

$scopeNiks = portal_scope_niks($conn);
[$scopeClause, $scopeParams] = portal_scope_where($scopeNiks);

$competencyListResult = mysqli_query($conn, "SELECT id, name FROM competencies ORDER BY name ASC");
$competencyList = [];
while ($row = mysqli_fetch_assoc($competencyListResult)) {
    $competencyList[] = $row;
}

$competencyFilter = isset($_GET['competency_id']) ? (int) $_GET['competency_id'] : 0;
$attendanceFilter = trim($_GET['attendance'] ?? '');
if (!in_array($attendanceFilter, ['confirmed', 'not_confirmed'], true)) {
    $attendanceFilter = '';
}
$search = trim($_GET['search'] ?? '');

$conditions = ["e.is_deleted = 0", "ec.is_active = 1", "ec.scheduled_training_date IS NOT NULL"];
$params = $scopeParams;
$types = str_repeat('s', count($scopeParams));
if ($competencyFilter > 0) {
    $conditions[] = "ec.competency_id = ?";
    $params[] = $competencyFilter;
    $types .= "i";
}
if ($attendanceFilter === 'confirmed') {
    $conditions[] = "ec.attendance_confirmed = 1";
} elseif ($attendanceFilter === 'not_confirmed') {
    $conditions[] = "ec.attendance_confirmed = 0";
}
if ($search !== '') {
    $conditions[] = "(e.nik LIKE ? OR e.name LIKE ? OR e.department LIKE ?)";
    $keyword = "%" . $search . "%";
    array_push($params, $keyword, $keyword, $keyword);
    $types .= "sss";
}

$whereSql = implode(' AND ', $conditions) . $scopeClause;

$countQuery = "
    SELECT COUNT(*) AS total
    FROM employee_competencies ec
    INNER JOIN employees e ON e.id = ec.employee_id
    INNER JOIN competencies c ON c.id = ec.competency_id
    WHERE " . $whereSql . "
";
$countStmt = mysqli_prepare($conn, $countQuery);
if (!empty($params)) {
    mysqli_stmt_bind_param($countStmt, $types, ...$params);
}
mysqli_stmt_execute($countStmt);
$totalRows = (int) mysqli_fetch_assoc(mysqli_stmt_get_result($countStmt))['total'];
$pg = paginate($totalRows, 25);

$query = "
    SELECT
        ec.id,
        ec.scheduled_training_date,
        ec.attendance_confirmed,
        ec.status,
        ec.score,
        e.nik,
        e.name AS employee_name,
        e.department,
        e.team,
        c.name AS competency_name,
        c.passing_score
    FROM employee_competencies ec
    INNER JOIN employees e ON e.id = ec.employee_id
    INNER JOIN competencies c ON c.id = ec.competency_id
    WHERE " . $whereSql . "
    ORDER BY ec.scheduled_training_date DESC, e.name ASC
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
    'competency_id' => $competencyFilter > 0 ? $competencyFilter : null,
    'attendance' => $attendanceFilter,
    'search' => $search,
], function ($value) {
    return $value !== null && $value !== '';
});

$pageTitles = [
    '' => 'Resume Assignment & Kehadiran Training',
    'confirmed' => 'Anggota Tim yang Sudah Hadir Training',
    'not_confirmed' => 'Anggota Tim yang Belum Hadir Training',
];
$pageTitle = $pageTitles[$attendanceFilter];
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <link rel="icon" type="image/x-icon" href="../favicon.ico">
    <link rel="icon" type="image/png" sizes="32x32" href="../assets/images/favicon-32.png">
    <link rel="apple-touch-icon" href="../assets/images/apple-touch-icon.png">
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo htmlspecialchars($pageTitle); ?> - Bekaert Competency</title>
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
                        <h1><?php echo htmlspecialchars($pageTitle); ?></h1>
                        <p>Daftar anggota tim yang di-assign jadwal training, beserta status kehadirannya</p>
                    </div>
                    <div class="d-flex gap-2">
                        <a href="dashboard.php" class="btn btn-outline-secondary">&larr; Back to Dashboard</a>
                    </div>
                </div>
                <div class="form-card mb-4">
                    <form method="GET" class="row g-3 align-items-end">
                        <?php if ($attendanceFilter !== ''): ?>
                            <input type="hidden" name="attendance" value="<?php echo htmlspecialchars($attendanceFilter); ?>">
                        <?php endif; ?>
                        <div class="col-md-4">
                            <label class="form-label">Search</label>
                            <input type="text" name="search" class="form-control"
                                placeholder="NIK, nama, department..." value="<?php echo htmlspecialchars($search); ?>">
                        </div>
                        <div class="col-md-4">
                            <label class="form-label">Competency</label>
                            <select name="competency_id" class="form-control">
                                <option value="0">Semua Kompetensi</option>
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
                            <label class="form-label">Kehadiran</label>
                            <select name="attendance" class="form-control">
                                <option value="" <?php echo $attendanceFilter === '' ? 'selected' : ''; ?>>Semua</option>
                                <option value="confirmed" <?php echo $attendanceFilter === 'confirmed' ? 'selected' : ''; ?>>
                                    Sudah Hadir
                                </option>
                                <option value="not_confirmed" <?php echo $attendanceFilter === 'not_confirmed' ? 'selected' : ''; ?>>
                                    Belum Hadir
                                </option>
                            </select>
                        </div>
                        <div class="col-md-2">
                            <button type="submit" class="btn btn-primary w-100">Filter</button>
                        </div>
                    </form>
                </div>
                <div class="employee-table-card">
                    <div class="table-responsive">
                        <table class="table table-hover align-middle mb-0">
                            <thead>
                                <tr>
                                    <th>NIK</th>
                                    <th>Employee</th>
                                    <th>Department</th>
                                    <th>Competency</th>
                                    <th>Scheduled Date</th>
                                    <th>Attendance</th>
                                    <th>Status</th>
                                    <th>Score</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php if (mysqli_num_rows($result) > 0): ?>
                                    <?php while ($row = mysqli_fetch_assoc($result)): ?>
                                        <tr>
                                            <td><?php echo htmlspecialchars($row['nik']); ?></td>
                                            <td><strong><?php echo htmlspecialchars($row['employee_name']); ?></strong></td>
                                            <td><?php echo htmlspecialchars($row['department'] ?? '-'); ?></td>
                                            <td><?php echo htmlspecialchars($row['competency_name']); ?></td>
                                            <td>
                                                <?php
                                                echo empty($row['scheduled_training_date'])
                                                    ? '-'
                                                    : date('d M Y', strtotime($row['scheduled_training_date']));
                                                ?>
                                            </td>
                                            <td>
                                                <?php if ((int) $row['attendance_confirmed'] === 1): ?>
                                                    <span class="badge text-bg-success">Confirmed</span>
                                                <?php else: ?>
                                                    <span class="badge text-bg-secondary">Not confirmed</span>
                                                <?php endif; ?>
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
                                                    <?php echo htmlspecialchars(competencyStatusLabel($row['status'])); ?>
                                                </span>
                                            </td>
                                            <td>
                                                <?php echo $row['score'] !== null ? htmlspecialchars((string) $row['score']) : '-'; ?>
                                            </td>
                                        </tr>
                                    <?php endwhile; ?>
                                <?php else: ?>
                                    <tr>
                                        <td colspan="8" class="text-center py-5">
                                            Tidak ada data.
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
                </div>
            </div>
        </main>
    </div>
</body>

</html>
