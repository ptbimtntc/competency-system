<?php
require_once "../includes/portal_auth.php";
portal_require_view('dashboard');
require_once "../includes/competency_helper.php";
require_once "../admin/pagination.php";

$scopeNiks = portal_scope_niks($conn);
$search = trim($_GET['search'] ?? '');
$filterCompetencyId = isset($_GET['competency_id']) ? (int) $_GET['competency_id'] : 0;

$competencyOptionsResult = mysqli_query($conn, "SELECT id, name FROM competencies ORDER BY name ASC");
$competencyOptions = [];
while ($row = mysqli_fetch_assoc($competencyOptionsResult)) {
    $competencyOptions[] = $row;
}

[$scopeClause, $scopeParams] = portal_scope_where($scopeNiks, 'e.nik');
$conditions = ["ec.status = 'ASSIGNED'", "ec.is_active = 1", "e.is_deleted = 0"];
$params = [];
$types = "";
if ($search !== '') {
    $conditions[] = "(e.nik LIKE ? OR e.name LIKE ?)";
    $keyword = "%" . $search . "%";
    $params[] = $keyword;
    $params[] = $keyword;
    $types .= "ss";
}
if ($filterCompetencyId > 0) {
    $conditions[] = "c.id = ?";
    $params[] = $filterCompetencyId;
    $types .= "i";
}
$params = array_merge($params, $scopeParams);
$types .= str_repeat("s", count($scopeParams));

$countQuery = "
    SELECT COUNT(*) AS total
    FROM employee_competencies ec
    INNER JOIN employees e ON e.id = ec.employee_id
    INNER JOIN competencies c ON c.id = ec.competency_id
    WHERE " . implode(" AND ", $conditions) . $scopeClause . "
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
        ec.id, ec.scheduled_training_date, ec.training_date, ec.attendance_confirmed,
        e.nik, e.name AS employee_name, e.department, e.position,
        c.name AS competency_name
    FROM employee_competencies ec
    INNER JOIN employees e ON e.id = ec.employee_id
    INNER JOIN competencies c ON c.id = ec.competency_id
    WHERE " . implode(" AND ", $conditions) . $scopeClause . "
    ORDER BY e.name ASC
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
$rows = [];
while ($row = mysqli_fetch_assoc($result)) {
    $rows[] = $row;
}

$paginationBaseParams = array_filter([
    'search' => $search,
    'competency_id' => $filterCompetencyId > 0 ? $filterCompetencyId : null,
], function ($value) {
    return $value !== null && $value !== '';
});
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <link rel="icon" type="image/x-icon" href="../favicon.ico">
    <link rel="icon" type="image/png" sizes="32x32" href="../assets/images/favicon-32.png">
    <link rel="apple-touch-icon" href="../assets/images/apple-touch-icon.png">
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Assigned Competencies Tim - Bekaert Competency</title>
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
                        <h1>Assigned Competencies</h1>
                        <p>Anggota tim dengan kompetensi berstatus assigned (belum valid/lulus)</p>
                    </div>
                    <a href="dashboard.php" class="btn btn-outline-secondary">&larr; Back to Dashboard</a>
                </div>

                <div class="employee-search">
                    <form method="GET" class="row g-2">
                        <div class="col-md-6">
                            <input type="text" name="search" class="form-control" placeholder="NIK / nama"
                                value="<?php echo htmlspecialchars($search); ?>">
                        </div>
                        <div class="col-md-3">
                            <select name="competency_id" class="form-control">
                                <option value="0">Semua Kompetensi</option>
                                <?php foreach ($competencyOptions as $competencyOption): ?>
                                    <option value="<?php echo $competencyOption['id']; ?>"
                                        <?php echo $filterCompetencyId === (int) $competencyOption['id'] ? 'selected' : ''; ?>>
                                        <?php echo htmlspecialchars($competencyOption['name']); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-3">
                            <button type="submit" class="btn btn-primary w-100">Terapkan</button>
                        </div>
                    </form>
                </div>

                <div class="employee-table-card">
                    <div class="table-responsive">
                        <table class="table table-hover align-middle mb-0">
                            <thead>
                                <tr>
                                    <th>NIK</th><th>Employee</th><th>Department</th>
                                    <th>Competency</th><th>Scheduled Training Date</th><th>Kehadiran</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php if (count($rows) > 0): ?>
                                    <?php foreach ($rows as $row): ?>
                                        <?php
                                        $isAttended = (int) $row['attendance_confirmed'] === 1 || !empty($row['training_date']);
                                        $displayDate = $row['scheduled_training_date'] ?? $row['training_date'];
                                        ?>
                                        <tr>
                                            <td><?php echo htmlspecialchars($row['nik']); ?></td>
                                            <td><strong><?php echo htmlspecialchars($row['employee_name']); ?></strong></td>
                                            <td><?php echo htmlspecialchars($row['department'] ?? '-'); ?></td>
                                            <td><?php echo htmlspecialchars($row['competency_name']); ?></td>
                                            <td>
                                                <?php
                                                echo empty($displayDate)
                                                    ? '-'
                                                    : date('d M Y', strtotime($displayDate));
                                                ?>
                                            </td>
                                            <td>
                                                <?php if ($isAttended): ?>
                                                    <span class="badge text-bg-success">Sudah Hadir</span>
                                                <?php else: ?>
                                                    <span class="badge text-bg-secondary">Belum Hadir</span>
                                                <?php endif; ?>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                <?php else: ?>
                                    <tr><td colspan="6" class="text-center py-5">Tidak ada data untuk filter ini.</td></tr>
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
