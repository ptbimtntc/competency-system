<?php
require_once "../includes/portal_auth.php";
portal_require_view('dashboard');
require_once "../includes/competency_helper.php";
require_once "../admin/pagination.php";

$scopeNiks = portal_scope_niks($conn);
$search = trim($_GET['search'] ?? '');

[$scopeClause, $scopeParams] = portal_scope_where($scopeNiks, 'e.nik');
$conditions = ["ec.status = 'FAILED'", "ec.is_active = 1", "e.is_deleted = 0"];
$params = [];
$types = "";
if ($search !== '') {
    $conditions[] = "(e.nik LIKE ? OR e.name LIKE ?)";
    $keyword = "%" . $search . "%";
    $params[] = $keyword;
    $params[] = $keyword;
    $types .= "ss";
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
        ec.id, ec.score, ec.training_date, ec.quiz_submitted_at,
        e.nik, e.name AS employee_name, e.department, e.position,
        c.name AS competency_name, c.passing_score, c.portal_reset_allowed
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
], function ($value) {
    return $value !== null && $value !== '';
});
$backUrl = 'team_failed.php' . (!empty($paginationBaseParams)
    ? '?' . http_build_query($paginationBaseParams, '', '&', PHP_QUERY_RFC3986)
    : '');
$quizRetrySuccess = isset($_GET['quiz_retry']) && $_GET['quiz_retry'] === '1';
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Failed Competencies Tim - Bekaert Competency</title>
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
                        <h1>Failed Competencies</h1>
                        <p>Anggota tim dengan nilai quiz di bawah KKM (passing score)</p>
                    </div>
                    <a href="dashboard.php" class="btn btn-outline-secondary">&larr; Back to Dashboard</a>
                </div>

                <?php if ($quizRetrySuccess): ?>
                    <div class="alert alert-success">
                        Status berhasil direset ke Assigned. Karyawan bisa mengerjakan ulang kuis dalam 1 jam ke
                        depan sebagai training kedua, setelah itu terkunci lagi.
                    </div>
                <?php endif; ?>

                <div class="employee-search">
                    <form method="GET" class="row g-2">
                        <div class="col-md-9">
                            <input type="text" name="search" class="form-control" placeholder="NIK / nama"
                                value="<?php echo htmlspecialchars($search); ?>">
                        </div>
                        <div class="col-md-3">
                            <button type="submit" class="btn btn-primary w-100">Terapkan</button>
                        </div>
                    </form>
                </div>

                <div class="employee-table-card">
                    <?php $canReset = portal_can_execute('dashboard'); ?>
                    <?php if ($canReset): ?>
                        <form method="POST" action="employee_competency_quiz_retry_bulk.php" id="bulkResetForm"
                            onsubmit="return confirm('Reset status karyawan terpilih dari Failed ke Assigned? Mereka akan dianggap hadir training kembali hari ini dan bisa mengerjakan kuis ulang sebagai training kedua dalam 1 jam ke depan.');">
                            <?php echo csrf_input(); ?>
                            <input type="hidden" name="back" value="<?php echo htmlspecialchars($backUrl); ?>">
                    <?php endif; ?>
                    <div class="table-responsive">
                        <table class="table table-hover align-middle mb-0">
                            <thead>
                                <tr>
                                    <?php if ($canReset): ?>
                                        <th width="40">
                                            <input type="checkbox" class="form-check-input" id="selectAllFailed">
                                        </th>
                                    <?php endif; ?>
                                    <th>NIK</th><th>Employee</th><th>Department</th>
                                    <th>Competency</th><th>Score</th><th>KKM</th><th>Training Date</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php if (count($rows) > 0): ?>
                                    <?php foreach ($rows as $row): ?>
                                        <?php $competencyResetAllowed = (int) $row['portal_reset_allowed'] === 1; ?>
                                        <tr>
                                            <?php if ($canReset): ?>
                                                <td>
                                                    <?php if ($competencyResetAllowed): ?>
                                                        <input type="checkbox" class="form-check-input reset-select" name="reset_ids[]" value="<?php echo $row['id']; ?>">
                                                    <?php else: ?>
                                                        <input type="checkbox" class="form-check-input" disabled title="Reset untuk competency ini dinonaktifkan oleh superadmin">
                                                    <?php endif; ?>
                                                </td>
                                            <?php endif; ?>
                                            <td><?php echo htmlspecialchars($row['nik']); ?></td>
                                            <td><strong><?php echo htmlspecialchars($row['employee_name']); ?></strong></td>
                                            <td><?php echo htmlspecialchars($row['department'] ?? '-'); ?></td>
                                            <td>
                                                <?php echo htmlspecialchars($row['competency_name']); ?>
                                                <?php if (!$competencyResetAllowed): ?>
                                                    <span class="badge text-bg-secondary" title="Reset untuk competency ini dinonaktifkan oleh superadmin">Reset dikunci</span>
                                                <?php endif; ?>
                                            </td>
                                            <td><?php echo htmlspecialchars((string) $row['score']); ?></td>
                                            <td><?php echo htmlspecialchars((string) $row['passing_score']); ?></td>
                                            <td>
                                                <?php
                                                echo empty($row['training_date'])
                                                    ? '-'
                                                    : date('d M Y', strtotime($row['training_date']));
                                                ?>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                <?php else: ?>
                                    <tr><td colspan="<?php echo $canReset ? 8 : 7; ?>" class="text-center py-5">Tidak ada data untuk filter ini.</td></tr>
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
                    <?php if ($canReset): ?>
                        <?php if (count($rows) > 0): ?>
                            <div class="p-4 border-top">
                                <button type="submit" class="btn btn-warning">
                                    Reset ke Assigned (Terpilih)
                                </button>
                            </div>
                        <?php endif; ?>
                        </form>
                    <?php endif; ?>
                </div>
            </div>
        </main>
    </div>
    <?php if ($canReset): ?>
        <script>
            (function () {
                var selectAll = document.getElementById('selectAllFailed');
                var checkboxes = document.querySelectorAll('.reset-select');
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
