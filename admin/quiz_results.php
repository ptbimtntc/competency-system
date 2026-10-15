<?php
require_once "auth.php";
require_once "../config/database.php";
require_once "pagination.php";
/*
|--------------------------------------------------------------------------
| Daftar hasil quiz seluruh karyawan -- khusus review jawaban benar/salah
|--------------------------------------------------------------------------
|
| Mirip seksi "Nilai Quiz per Karyawan" di dashboard.php, tapi hanya
| menampilkan hasil yang berasal dari quiz (bukan input manual), karena
| hanya hasil quiz yang punya rincian jawaban (employee_quiz_answers).
|
*/
$resultCompetencyOptions = mysqli_query($conn, "SELECT id, name FROM competencies ORDER BY name ASC");
$competencyOptions = [];
while ($row = mysqli_fetch_assoc($resultCompetencyOptions)) {
    $competencyOptions[] = $row;
}
$filterCompetencyId = isset($_GET['competency_id']) ? (int) $_GET['competency_id'] : 0;

$quizStatusFilter = isset($_GET['quiz_status']) ? trim($_GET['quiz_status']) : '';
if (!in_array($quizStatusFilter, ['passed', 'failed'], true)) {
    $quizStatusFilter = '';
}
$quizSearch = isset($_GET['quiz_search']) ? trim($_GET['quiz_search']) : '';

$quizConditions = ["e.is_deleted = 0", "ec.is_active = 1", "ec.quiz_submitted_at IS NOT NULL"];
$quizParams = [];
$quizTypes = "";
if ($filterCompetencyId > 0) {
    $quizConditions[] = "ec.competency_id = ?";
    $quizParams[] = $filterCompetencyId;
    $quizTypes .= "i";
}
if ($quizStatusFilter === 'passed') {
    $quizConditions[] = "ec.status != 'FAILED'";
} elseif ($quizStatusFilter === 'failed') {
    $quizConditions[] = "ec.status = 'FAILED'";
}
if ($quizSearch !== '') {
    $quizConditions[] = "(e.nik LIKE ? OR e.name LIKE ? OR e.department LIKE ?)";
    $quizSearchLike = '%' . $quizSearch . '%';
    $quizParams[] = $quizSearchLike;
    $quizParams[] = $quizSearchLike;
    $quizParams[] = $quizSearchLike;
    $quizTypes .= "sss";
}
$quizWhereSql = implode(' AND ', $quizConditions);

$quizCountStmt = mysqli_prepare($conn, "
    SELECT COUNT(*) AS total
    FROM employee_competencies ec
    INNER JOIN employees e ON e.id = ec.employee_id
    WHERE {$quizWhereSql}
");
if (!empty($quizParams)) {
    mysqli_stmt_bind_param($quizCountStmt, $quizTypes, ...$quizParams);
}
mysqli_stmt_execute($quizCountStmt);
$quizFilteredTotal = (int) mysqli_fetch_assoc(mysqli_stmt_get_result($quizCountStmt))['total'];
$quizPg = paginate($quizFilteredTotal, 15);

$queryQuizScores = "
    SELECT
        ec.id,
        e.nik,
        e.name AS employee_name,
        c.name AS competency_name,
        ec.score,
        c.passing_score,
        ec.status,
        ec.quiz_submitted_at
    FROM employee_competencies ec
    INNER JOIN employees e ON e.id = ec.employee_id
    INNER JOIN competencies c ON c.id = ec.competency_id
    WHERE {$quizWhereSql}
    ORDER BY ec.quiz_submitted_at DESC
    LIMIT ? OFFSET ?
";
$quizDataParams = $quizParams;
$quizDataTypes = $quizTypes . "ii";
$quizDataParams[] = $quizPg['per_page'];
$quizDataParams[] = $quizPg['offset'];
$resultQuizScoresStmt = mysqli_prepare($conn, $queryQuizScores);
mysqli_stmt_bind_param($resultQuizScoresStmt, $quizDataTypes, ...$quizDataParams);
mysqli_stmt_execute($resultQuizScoresStmt);
$quizResult = mysqli_stmt_get_result($resultQuizScoresStmt);
$displayedQuizScores = [];
while ($row = mysqli_fetch_assoc($quizResult)) {
    $displayedQuizScores[] = $row;
}
$quizPaginationBaseParams = array_filter([
    'competency_id' => $filterCompetencyId > 0 ? $filterCompetencyId : null,
    'quiz_status' => $quizStatusFilter,
    'quiz_search' => $quizSearch,
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
    <title>Hasil Quiz - Bekaert Competency</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet">
    <link rel="stylesheet" href="../assets/css/style.css?v=<?php echo filemtime(__DIR__ . '/../assets/css/style.css'); ?>">
</head>
<body class="admin-page">
<nav class="admin-navbar">
    <div class="admin-brand">
        <img src="../assets/images/Bekaert_logo_neg_RGB.png" alt="Bekaert" class="brand-logo">
        <span>Competency System</span>
    </div>
    <div class="admin-user">
        <span>
            <?php echo htmlspecialchars($_SESSION['admin_name']); ?>
            <span class="badge text-bg-light"><?php echo htmlspecialchars(role_label(current_admin_role())); ?></span>
        </span>
        <a href="logout.php"><i class="bi bi-box-arrow-right"></i> Logout</a>
    </div>
</nav>
<div class="admin-layout">
    <?php include "../includes/admin_sidebar.php"; ?>
    <main class="admin-content">
        <div class="admin-container">
            <div class="dashboard-header d-flex flex-wrap justify-content-between align-items-start gap-2">
                <div>
                    <h1>Hasil Quiz</h1>
                    <p>Rincian jawaban benar/salah setiap karyawan yang sudah mengerjakan quiz.</p>
                </div>
                <div>
                    <form method="GET" class="d-flex gap-2">
                        <?php if ($quizStatusFilter !== ''): ?>
                            <input type="hidden" name="quiz_status" value="<?php echo htmlspecialchars($quizStatusFilter); ?>">
                        <?php endif; ?>
                        <?php if ($quizSearch !== ''): ?>
                            <input type="hidden" name="quiz_search" value="<?php echo htmlspecialchars($quizSearch); ?>">
                        <?php endif; ?>
                        <select name="competency_id" class="form-control" onchange="this.form.submit()">
                            <option value="0"<?php echo $filterCompetencyId === 0 ? ' selected' : ''; ?>>
                                Semua Kompetensi
                            </option>
                            <?php foreach ($competencyOptions as $competencyOption): ?>
                                <option value="<?php echo $competencyOption['id']; ?>"
                                    <?php echo $filterCompetencyId === (int) $competencyOption['id'] ? 'selected' : ''; ?>>
                                    <?php echo htmlspecialchars($competencyOption['name']); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                        <noscript>
                            <button type="submit" class="btn btn-outline-primary">Filter</button>
                        </noscript>
                    </form>
                </div>
            </div>
            <div class="dashboard-header mt-3 d-flex flex-wrap justify-content-between align-items-start gap-2">
                <div></div>
                <div class="d-flex gap-2">
                    <form method="GET" class="d-flex gap-2">
                        <?php if ($filterCompetencyId > 0): ?>
                            <input type="hidden" name="competency_id" value="<?php echo $filterCompetencyId; ?>">
                        <?php endif; ?>
                        <input type="text" name="quiz_search" class="form-control" placeholder="NIK, nama, department..."
                            value="<?php echo htmlspecialchars($quizSearch); ?>">
                        <select name="quiz_status" class="form-control" onchange="this.form.submit()">
                            <option value=""<?php echo $quizStatusFilter === '' ? ' selected' : ''; ?>>Semua</option>
                            <option value="passed"<?php echo $quizStatusFilter === 'passed' ? ' selected' : ''; ?>>Lulus</option>
                            <option value="failed"<?php echo $quizStatusFilter === 'failed' ? ' selected' : ''; ?>>Gagal</option>
                        </select>
                        <button type="submit" class="btn btn-outline-primary">Cari</button>
                    </form>
                    <a href="quiz_results_export.php?<?php echo htmlspecialchars(http_build_query($quizPaginationBaseParams)); ?>"
                        class="btn btn-outline-secondary">
                        Export CSV
                    </a>
                </div>
            </div>
            <div class="card">
                <div class="table-responsive">
                    <table class="table table-hover align-middle mb-0">
                        <thead>
                            <tr>
                                <th>NIK</th>
                                <th>Nama</th>
                                <th>Kompetensi</th>
                                <th>Skor</th>
                                <th>KKM</th>
                                <th>Status</th>
                                <th>Tanggal Submit</th>
                                <th>Action</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (count($displayedQuizScores) > 0): ?>
                                <?php foreach ($displayedQuizScores as $quizRow): ?>
                                    <?php $isPassed = $quizRow['status'] !== 'FAILED'; ?>
                                    <tr>
                                        <td><?php echo htmlspecialchars($quizRow['nik']); ?></td>
                                        <td><strong><?php echo htmlspecialchars($quizRow['employee_name']); ?></strong></td>
                                        <td><?php echo htmlspecialchars($quizRow['competency_name']); ?></td>
                                        <td><?php echo htmlspecialchars((string) $quizRow['score']); ?></td>
                                        <td><?php echo htmlspecialchars((string) $quizRow['passing_score']); ?></td>
                                        <td>
                                            <span class="badge <?php echo $isPassed ? 'text-bg-success' : 'text-bg-danger'; ?>">
                                                <?php echo $isPassed ? 'Lulus' : 'Gagal'; ?>
                                            </span>
                                        </td>
                                        <td>
                                            <?php echo !empty($quizRow['quiz_submitted_at']) ? date('d M Y H:i', strtotime($quizRow['quiz_submitted_at'])) : '-'; ?>
                                        </td>
                                        <td>
                                            <a href="quiz_result_detail.php?id=<?php echo $quizRow['id']; ?>" class="btn btn-sm btn-outline-primary">
                                                Lihat Jawaban
                                            </a>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php else: ?>
                                <tr>
                                    <td colspan="8" class="text-center py-4">
                                        Belum ada karyawan yang mengerjakan quiz.
                                    </td>
                                </tr>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
                <?php if ($quizFilteredTotal > 0): ?>
                    <div class="d-flex flex-wrap justify-content-between align-items-center gap-2 p-3 border-top">
                        <span class="text-muted" style="font-size:13px;">
                            Menampilkan
                            <?php echo $quizPg['offset'] + 1; ?>&ndash;<?php echo min($quizPg['offset'] + $quizPg['per_page'], $quizFilteredTotal); ?>
                            dari <?php echo $quizFilteredTotal; ?> data
                        </span>
                        <?php echo render_pagination($quizPg, $quizPaginationBaseParams); ?>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </main>
</div>
</body>
</html>
