<?php
require_once "../includes/portal_auth.php";
portal_require_view('dashboard');
require_once "../admin/pagination.php";

$scopeNiks = portal_scope_niks($conn);

/* Daftar kompetensi untuk filter dashboard. Filter ini berlaku untuk seluruh
   halaman (kartu ringkasan, Resume Assignment & Kehadiran Training, dan
   Nilai Quiz per Karyawan), bukan hanya sebagian seksi. */
$resultCompetencyOptions = mysqli_query($conn, "SELECT id, name FROM competencies ORDER BY name ASC");
$competencyOptions = [];
while ($row = mysqli_fetch_assoc($resultCompetencyOptions)) {
    $competencyOptions[] = $row;
}
if (isset($_GET['competency_id'])) {
    $filterCompetencyId = (int) $_GET['competency_id'];
    $_SESSION['portal_dashboard_competency_id'] = $filterCompetencyId;
} else {
    $filterCompetencyId = (int) ($_SESSION['portal_dashboard_competency_id'] ?? 0);
}

function portal_count_members(mysqli $conn, ?array $scopeNiks, int $competencyId): int
{
    [$scopeClause, $scopeParams] = portal_scope_where($scopeNiks);
    if ($competencyId > 0) {
        $query = "
            SELECT COUNT(DISTINCT e.id) AS total
            FROM employees e
            INNER JOIN employee_competencies ec ON ec.employee_id = e.id AND ec.is_active = 1 AND ec.competency_id = ?
            WHERE e.is_deleted = 0 {$scopeClause}
        ";
        $types = "i" . str_repeat("s", count($scopeParams));
        $params = array_merge([$competencyId], $scopeParams);
    } else {
        $query = "SELECT COUNT(*) AS total FROM employees e WHERE e.is_deleted = 0 {$scopeClause}";
        $types = str_repeat("s", count($scopeParams));
        $params = $scopeParams;
    }
    $stmt = mysqli_prepare($conn, $query);
    if (!empty($params)) {
        mysqli_stmt_bind_param($stmt, $types, ...$params);
    }
    mysqli_stmt_execute($stmt);
    return (int) mysqli_fetch_assoc(mysqli_stmt_get_result($stmt))['total'];
}

function portal_count_by_status(mysqli $conn, string $status, ?array $scopeNiks, int $competencyId = 0): int
{
    [$scopeClause, $scopeParams] = portal_scope_where($scopeNiks);
    $competencyClause = $competencyId > 0 ? " AND ec.competency_id = ?" : "";
    $query = "
        SELECT COUNT(*) AS total
        FROM employee_competencies ec
        INNER JOIN employees e ON e.id = ec.employee_id
        WHERE ec.status = ? AND ec.is_active = 1 AND e.is_deleted = 0 {$scopeClause}{$competencyClause}
    ";
    $stmt = mysqli_prepare($conn, $query);
    $types = "s" . str_repeat("s", count($scopeParams)) . ($competencyId > 0 ? "i" : "");
    $params = array_merge([$status], $scopeParams);
    if ($competencyId > 0) {
        $params[] = $competencyId;
    }
    mysqli_stmt_bind_param($stmt, $types, ...$params);
    mysqli_stmt_execute($stmt);
    return (int) mysqli_fetch_assoc(mysqli_stmt_get_result($stmt))['total'];
}

$totalMembers = portal_count_members($conn, $scopeNiks, $filterCompetencyId);
$totalValid = portal_count_by_status($conn, 'VALID', $scopeNiks, $filterCompetencyId);
$totalExpired = portal_count_by_status($conn, 'EXPIRED', $scopeNiks, $filterCompetencyId);
$totalAssigned = portal_count_by_status($conn, 'ASSIGNED', $scopeNiks, $filterCompetencyId);
$totalFailed = portal_count_by_status($conn, 'FAILED', $scopeNiks, $filterCompetencyId);

/* Total Menunggu Kuis -- subset dari Assigned yang sudah konfirmasi hadir
   tapi belum submit kuis, supaya terlihat terpisah dari yang belum hadir
   sama sekali (butuh tindak lanjut berbeda). */
[$waitingQuizScopeClause, $waitingQuizScopeParams] = portal_scope_where($scopeNiks);
$queryWaitingQuiz = "
    SELECT COUNT(*) AS total
    FROM employee_competencies ec
    INNER JOIN employees e ON e.id = ec.employee_id
    WHERE ec.status = 'ASSIGNED' AND ec.attendance_confirmed = 1
        AND ec.is_active = 1 AND e.is_deleted = 0
        AND (ec.scheduled_training_date IS NOT NULL OR ec.training_date IS NOT NULL)
        {$waitingQuizScopeClause}
" . ($filterCompetencyId > 0 ? " AND ec.competency_id = ?" : "");
$waitingQuizParams = $waitingQuizScopeParams;
$waitingQuizTypes = str_repeat('s', count($waitingQuizScopeParams));
if ($filterCompetencyId > 0) {
    $waitingQuizParams[] = $filterCompetencyId;
    $waitingQuizTypes .= 'i';
}
$stmtWaitingQuiz = mysqli_prepare($conn, $queryWaitingQuiz);
if (!empty($waitingQuizParams)) {
    mysqli_stmt_bind_param($stmtWaitingQuiz, $waitingQuizTypes, ...$waitingQuizParams);
}
mysqli_stmt_execute($stmtWaitingQuiz);
$totalWaitingQuiz = (int) mysqli_fetch_assoc(mysqli_stmt_get_result($stmtWaitingQuiz))['total'];

/* Resume Assignment & Kehadiran Training (tim) */
[$scopeClause, $scopeParams] = portal_scope_where($scopeNiks);
$competencyClause = $filterCompetencyId > 0 ? " AND ec.competency_id = ?" : "";

/* Filter tanggal training (scheduled_training_date) hanya tersedia & berlaku
   ketika filter kompetensi aktif. Default kosong = semua tanggal. */
$filterTrainingDate = '';
if ($filterCompetencyId > 0 && isset($_GET['training_date']) && $_GET['training_date'] !== '') {
    $candidateDate = $_GET['training_date'];
    $dateParts = explode('-', $candidateDate);
    if (count($dateParts) === 3 && checkdate((int) $dateParts[1], (int) $dateParts[2], (int) $dateParts[0])) {
        $filterTrainingDate = $candidateDate;
    }
}
/* Training bisa tercatat lewat scheduled_training_date (alur quiz) ATAU
   training_date (realisasi/input manual) -- cocokkan salah satu agar
   training yang sudah terjadi di tanggal tersebut tidak terlewat. */
$trainingDateClause = $filterTrainingDate !== '' ? " AND (ec.scheduled_training_date = ? OR ec.training_date = ?)" : "";

/* "Di-assign" mencakup baik yang melalui alur quiz (scheduled_training_date
   terisi) MAUPUN yang nilainya diinput/diimport manual tanpa quiz
   (training_date langsung terisi). "Hadir" juga mengikuti training_date --
   training sudah benar-benar terjadi (quiz ATAU input manual), terhitung
   hadir walau attendance_confirmed tidak pernah dicentang. */
$queryAssignSummary = "
    SELECT
        COUNT(*) AS total_assigned,
        SUM(CASE WHEN ec.attendance_confirmed = 1 OR ec.training_date IS NOT NULL THEN 1 ELSE 0 END) AS total_attended
    FROM employee_competencies ec
    INNER JOIN employees e ON e.id = ec.employee_id
    WHERE e.is_deleted = 0 AND ec.is_active = 1
        AND (ec.scheduled_training_date IS NOT NULL OR ec.training_date IS NOT NULL)
        {$scopeClause}{$competencyClause}{$trainingDateClause}
";
$assignParams = $filterCompetencyId > 0 ? array_merge($scopeParams, [$filterCompetencyId]) : $scopeParams;
$assignTypes = str_repeat('s', count($scopeParams)) . ($filterCompetencyId > 0 ? 'i' : '');
if ($filterTrainingDate !== '') {
    $assignParams[] = $filterTrainingDate;
    $assignParams[] = $filterTrainingDate;
    $assignTypes .= 'ss';
}
$assignStmt = mysqli_prepare($conn, $queryAssignSummary);
if (!empty($assignParams)) {
    mysqli_stmt_bind_param($assignStmt, $assignTypes, ...$assignParams);
}
mysqli_stmt_execute($assignStmt);
$assignSummary = mysqli_fetch_assoc(mysqli_stmt_get_result($assignStmt));
$totalAssignedAll = (int) $assignSummary['total_assigned'];
$totalAttended = (int) $assignSummary['total_attended'];
$totalNotAttended = $totalAssignedAll - $totalAttended;
$attendancePercentage = $totalAssignedAll > 0
    ? round($totalAttended / $totalAssignedAll * 100, 1)
    : 0;

/* Nilai Quiz per Karyawan (tim) */
$quizStatusFilter = isset($_GET['quiz_status']) ? trim($_GET['quiz_status']) : '';
if (!in_array($quizStatusFilter, ['passed', 'failed'], true)) {
    $quizStatusFilter = '';
}

/* Ringkasan lulus/gagal selalu dihitung dari SELURUH data yang cocok
   dengan filter kompetensi, terlepas dari filter status yang sedang aktif,
   supaya angka di atas tabel tidak ikut berubah saat tabelnya difilter. */
$queryQuizSummary = "
    SELECT
        COUNT(*) AS total,
        SUM(CASE WHEN ec.status != 'FAILED' THEN 1 ELSE 0 END) AS total_passed
    FROM employee_competencies ec
    INNER JOIN employees e ON e.id = ec.employee_id
    WHERE e.is_deleted = 0 AND ec.is_active = 1 AND ec.score IS NOT NULL {$scopeClause}{$competencyClause}
";
$quizSummaryParams = $filterCompetencyId > 0 ? array_merge($scopeParams, [$filterCompetencyId]) : $scopeParams;
$quizSummaryTypes = str_repeat('s', count($scopeParams)) . ($filterCompetencyId > 0 ? 'i' : '');
$quizSummaryStmt = mysqli_prepare($conn, $queryQuizSummary);
if (!empty($quizSummaryParams)) {
    mysqli_stmt_bind_param($quizSummaryStmt, $quizSummaryTypes, ...$quizSummaryParams);
}
mysqli_stmt_execute($quizSummaryStmt);
$quizSummary = mysqli_fetch_assoc(mysqli_stmt_get_result($quizSummaryStmt));
$totalQuizTaken = (int) $quizSummary['total'];
$totalQuizPassed = (int) $quizSummary['total_passed'];
$totalQuizFailed = $totalQuizTaken - $totalQuizPassed;

$quizStatusClause = '';
if ($quizStatusFilter === 'passed') {
    $quizStatusClause = " AND ec.status != 'FAILED'";
} elseif ($quizStatusFilter === 'failed') {
    $quizStatusClause = " AND ec.status = 'FAILED'";
}

$quizSearch = isset($_GET['quiz_search']) ? trim($_GET['quiz_search']) : '';
$quizSearchClause = '';
$quizSearchParams = [];
$quizSearchTypes = '';
if ($quizSearch !== '') {
    $quizSearchClause = " AND (e.nik LIKE ? OR e.name LIKE ? OR e.department LIKE ?)";
    $quizSearchLike = '%' . $quizSearch . '%';
    $quizSearchParams = [$quizSearchLike, $quizSearchLike, $quizSearchLike];
    $quizSearchTypes = 'sss';
}

$quizCountStmt = mysqli_prepare($conn, "
    SELECT COUNT(*) AS total, AVG(ec.score) AS avg_score
    FROM employee_competencies ec
    INNER JOIN employees e ON e.id = ec.employee_id
    WHERE e.is_deleted = 0 AND ec.is_active = 1 AND ec.score IS NOT NULL {$scopeClause}{$competencyClause}{$trainingDateClause}{$quizStatusClause}{$quizSearchClause}
");
$quizCountParams = $filterCompetencyId > 0 ? array_merge($scopeParams, [$filterCompetencyId]) : $scopeParams;
if ($filterTrainingDate !== '') {
    $quizCountParams[] = $filterTrainingDate;
    $quizCountParams[] = $filterTrainingDate;
}
$quizCountParams = array_merge($quizCountParams, $quizSearchParams);
$quizCountTypes = str_repeat('s', count($scopeParams)) . ($filterCompetencyId > 0 ? 'i' : '') . ($filterTrainingDate !== '' ? 'ss' : '') . $quizSearchTypes;
if (!empty($quizCountParams)) {
    mysqli_stmt_bind_param($quizCountStmt, $quizCountTypes, ...$quizCountParams);
}
mysqli_stmt_execute($quizCountStmt);
$quizCountRow = mysqli_fetch_assoc(mysqli_stmt_get_result($quizCountStmt));
$quizFilteredTotal = (int) $quizCountRow['total'];
$quizAverageScore = $quizCountRow['avg_score'] !== null ? round((float) $quizCountRow['avg_score'], 1) : null;
$quizPg = paginate($quizFilteredTotal, 15);

/* Urutan: nilai tertinggi lebih dulu (berlaku lintas quiz & input manual --
   nilai 100 input manual tetap di atas nilai 90 quiz). Hanya ketika nilainya
   sama, baris dengan quiz_submitted_at paling lama (tanggal/jam submit lebih
   awal) didahulukan dari yang lebih baru atau input manual (quiz_submitted_at
   NULL dianggap paling baru). Jika masih sama, diurutkan berdasarkan nama
   karyawan secara abjad. */
$queryQuizScores = "
    SELECT
        ec.id,
        e.nik,
        e.name AS employee_name,
        c.name AS competency_name,
        ec.score,
        c.passing_score,
        c.portal_reset_allowed,
        ec.status,
        ec.quiz_submitted_at,
        ec.quiz_retry_until,
        COALESCE(ec.quiz_submitted_at, ec.training_date, ec.updated_at) AS score_date
    FROM employee_competencies ec
    INNER JOIN employees e ON e.id = ec.employee_id
    INNER JOIN competencies c ON c.id = ec.competency_id
    WHERE e.is_deleted = 0 AND ec.is_active = 1 AND ec.score IS NOT NULL {$scopeClause}{$competencyClause}{$trainingDateClause}{$quizStatusClause}{$quizSearchClause}
    ORDER BY ec.score DESC, (ec.quiz_submitted_at IS NULL) ASC, ec.quiz_submitted_at ASC, e.name ASC
    LIMIT ? OFFSET ?
";
$quizDataParams = $filterCompetencyId > 0 ? array_merge($scopeParams, [$filterCompetencyId]) : $scopeParams;
if ($filterTrainingDate !== '') {
    $quizDataParams[] = $filterTrainingDate;
    $quizDataParams[] = $filterTrainingDate;
}
$quizDataParams = array_merge($quizDataParams, $quizSearchParams);
$quizDataTypes = str_repeat('s', count($scopeParams)) . ($filterCompetencyId > 0 ? 'i' : '') . ($filterTrainingDate !== '' ? 'ss' : '') . $quizSearchTypes . "ii";
$quizDataParams[] = $quizPg['per_page'];
$quizDataParams[] = $quizPg['offset'];
$quizStmt = mysqli_prepare($conn, $queryQuizScores);
mysqli_stmt_bind_param($quizStmt, $quizDataTypes, ...$quizDataParams);
mysqli_stmt_execute($quizStmt);
$quizResult = mysqli_stmt_get_result($quizStmt);
$displayedQuizScores = [];
while ($row = mysqli_fetch_assoc($quizResult)) {
    $displayedQuizScores[] = $row;
}
$quizPaginationBaseParams = array_filter([
    'competency_id' => $filterCompetencyId > 0 ? $filterCompetencyId : null,
    'quiz_status' => $quizStatusFilter,
    'quiz_search' => $quizSearch,
    'training_date' => $filterTrainingDate,
], function ($value) {
    return $value !== null && $value !== '';
});
$quizRetrySuccess = isset($_GET['quiz_retry']) && $_GET['quiz_retry'] === '1';
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <link rel="icon" type="image/x-icon" href="../favicon.ico">
    <link rel="icon" type="image/png" sizes="32x32" href="../assets/images/favicon-32.png">
    <link rel="apple-touch-icon" href="../assets/images/apple-touch-icon.png">
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Dashboard Tim - Bekaert Competency</title>
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
                <div class="dashboard-header d-flex flex-wrap justify-content-between align-items-start gap-2">
                    <div>
                        <h1>Dashboard Tim</h1>
                        <p>
                            <?php echo portal_is_superadmin() ? 'Seluruh karyawan' : 'Anda &amp; bawahan Anda'; ?>
                        </p>
                    </div>
                    <div>
                        <form method="GET" class="d-flex gap-2">
                            <?php if ($quizStatusFilter !== ''): ?>
                                <input type="hidden" name="quiz_status" value="<?php echo htmlspecialchars($quizStatusFilter); ?>">
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
                <?php
                /*
                |--------------------------------------------------------------------------
                | Link kartu status, ikut filter kompetensi dashboard kalau halaman
                | tujuannya mendukung filter competency_id
                |--------------------------------------------------------------------------
                */
                $competencyQuery = $filterCompetencyId > 0 ? ['competency_id' => $filterCompetencyId] : [];
                $withCompetency = function (string $target, array $extra = []) use ($competencyQuery): string {
                    $params = array_merge($extra, $competencyQuery);
                    return $target . (empty($params) ? '' : ('?' . http_build_query($params)));
                };
                ?>
                <?php
                /*
                |--------------------------------------------------------------------------
                | Kartu yang menuju halaman dengan slug permission berbeda dari
                | 'dashboard' harus dicek izinnya dulu -- kalau tidak, kartu bisa
                | menampilkan angka tapi klik-nya ditolak ("Anda tidak memiliki akses").
                | Kalau tidak punya akses, kartu ditampilkan non-link (statis).
                |--------------------------------------------------------------------------
                */
                $canViewTeamMembers = portal_can_view('team_members');
                $canViewTeamRecertification = portal_can_view('team_recertification');
                $statCard = function (string $slug, bool $canView, string $href, string $icon, string $colorClass, string $label, int $number) {
                    if ($canView) {
                        echo '<a href="' . htmlspecialchars($href) . '" class="stat-card stat-card-link ' . $colorClass . '">';
                    } else {
                        echo '<div class="stat-card ' . $colorClass . '">';
                    }
                    echo '<div class="stat-icon"><i class="bi ' . $icon . '"></i></div>';
                    echo '<div><div class="stat-label">' . htmlspecialchars($label) . '</div>';
                    echo '<div class="stat-number">' . $number . '</div></div>';
                    echo $canView ? '</a>' : '</div>';
                };
                ?>
                <div class="row g-3">
                    <div class="col-6 col-sm-4 col-md-3 col-lg-2">
                        <?php $statCard('team_members', $canViewTeamMembers, 'team_members.php', 'bi-people-fill', 'stat-card-primary', 'Anggota Tim', $totalMembers); ?>
                    </div>
                    <div class="col-6 col-sm-4 col-md-3 col-lg-2">
                        <a href="<?php echo htmlspecialchars($withCompetency('team_valid.php')); ?>" class="stat-card stat-card-link stat-card-success">
                            <div class="stat-icon"><i class="bi bi-check-circle-fill"></i></div>
                            <div>
                                <div class="stat-label">Valid</div>
                                <div class="stat-number"><?php echo $totalValid; ?></div>
                            </div>
                        </a>
                    </div>
                    <div class="col-6 col-sm-4 col-md-3 col-lg-2">
                        <?php $statCard('team_recertification', $canViewTeamRecertification, 'team_recertification.php', 'bi-exclamation-triangle-fill', 'stat-card-danger', 'Expired', $totalExpired); ?>
                    </div>
                    <div class="col-6 col-sm-4 col-md-3 col-lg-2">
                        <a href="<?php echo htmlspecialchars($withCompetency('team_assigned.php')); ?>" class="stat-card stat-card-link stat-card-warning">
                            <div class="stat-icon"><i class="bi bi-hourglass-split"></i></div>
                            <div>
                                <div class="stat-label">Assigned</div>
                                <div class="stat-number"><?php echo $totalAssigned; ?></div>
                            </div>
                        </a>
                    </div>
                    <div class="col-6 col-sm-4 col-md-3 col-lg-2">
                        <a href="<?php echo htmlspecialchars($withCompetency('team_training_resume.php', ['attendance' => 'waiting_quiz'])); ?>" class="stat-card stat-card-link stat-card-info">
                            <div class="stat-icon"><i class="bi bi-pencil-square"></i></div>
                            <div>
                                <div class="stat-label">Menunggu Kuis</div>
                                <div class="stat-number"><?php echo $totalWaitingQuiz; ?></div>
                            </div>
                        </a>
                    </div>
                    <div class="col-6 col-sm-4 col-md-3 col-lg-2">
                        <a href="<?php echo htmlspecialchars($withCompetency('team_failed.php')); ?>" class="stat-card stat-card-link stat-card-danger">
                            <div class="stat-icon"><i class="bi bi-x-circle-fill"></i></div>
                            <div>
                                <div class="stat-label">Failed</div>
                                <div class="stat-number"><?php echo $totalFailed; ?></div>
                            </div>
                        </a>
                    </div>
                </div>

                <div class="dashboard-header mt-4 d-flex flex-wrap justify-content-between align-items-start gap-2">
                    <div>
                        <h2 class="mb-0">Resume Assignment &amp; Kehadiran Training</h2>
                        <p>Nilai Quiz per Karyawan di bawah juga ikut mengikuti filter kompetensi di atas.</p>
                    </div>
                    <?php if ($filterCompetencyId > 0): ?>
                        <div>
                            <form method="GET" class="d-flex gap-2">
                                <input type="hidden" name="competency_id" value="<?php echo $filterCompetencyId; ?>">
                                <?php if ($quizStatusFilter !== ''): ?>
                                    <input type="hidden" name="quiz_status" value="<?php echo htmlspecialchars($quizStatusFilter); ?>">
                                <?php endif; ?>
                                <?php if ($quizSearch !== ''): ?>
                                    <input type="hidden" name="quiz_search" value="<?php echo htmlspecialchars($quizSearch); ?>">
                                <?php endif; ?>
                                <input type="date" name="training_date" class="form-control"
                                    value="<?php echo htmlspecialchars($filterTrainingDate); ?>"
                                    onchange="this.form.submit()">
                                <?php if ($filterTrainingDate !== ''): ?>
                                    <?php
                                    $resetDateParams = array_filter([
                                        'competency_id' => $filterCompetencyId,
                                        'quiz_status' => $quizStatusFilter,
                                        'quiz_search' => $quizSearch,
                                    ], function ($value) {
                                        return $value !== null && $value !== '';
                                    });
                                    ?>
                                    <a href="dashboard.php?<?php echo htmlspecialchars(http_build_query($resetDateParams)); ?>" class="btn btn-outline-secondary">
                                        Semua Tanggal
                                    </a>
                                <?php endif; ?>
                            </form>
                        </div>
                    <?php endif; ?>
                </div>
                <?php
                $resumeBaseParams = $filterCompetencyId > 0 ? ['competency_id' => $filterCompetencyId] : [];
                $resumeLink = function (string $attendance = '') use ($resumeBaseParams): string {
                    $params = $resumeBaseParams;
                    if ($attendance !== '') {
                        $params['attendance'] = $attendance;
                    }
                    return 'team_training_resume.php' . (empty($params) ? '' : ('?' . http_build_query($params)));
                };
                $resumeExportLink = function (string $attendance = '') use ($resumeBaseParams): string {
                    $params = $resumeBaseParams;
                    if ($attendance !== '') {
                        $params['attendance'] = $attendance;
                    }
                    return 'team_training_resume_export.php' . (empty($params) ? '' : ('?' . http_build_query($params)));
                };
                ?>
                <div class="row g-3">
                    <div class="col-6 col-md-3 stat-card-wrap">
                        <a href="<?php echo htmlspecialchars($resumeLink()); ?>" class="stat-card stat-card-link stat-card-primary">
                            <div class="stat-icon"><i class="bi bi-clipboard-check-fill"></i></div>
                            <div>
                                <div class="stat-label">Total Di-assign</div>
                                <div class="stat-number"><?php echo $totalAssignedAll; ?></div>
                            </div>
                        </a>
                        <a href="<?php echo htmlspecialchars($resumeExportLink()); ?>" class="stat-card-export" title="Export CSV">
                            <i class="bi bi-download"></i>
                        </a>
                    </div>
                    <div class="col-6 col-md-3 stat-card-wrap">
                        <a href="<?php echo htmlspecialchars($resumeLink('confirmed')); ?>" class="stat-card stat-card-link stat-card-success">
                            <div class="stat-icon"><i class="bi bi-person-check-fill"></i></div>
                            <div>
                                <div class="stat-label">Hadir</div>
                                <div class="stat-number"><?php echo $totalAttended; ?></div>
                            </div>
                        </a>
                        <a href="<?php echo htmlspecialchars($resumeExportLink('confirmed')); ?>" class="stat-card-export" title="Export CSV">
                            <i class="bi bi-download"></i>
                        </a>
                    </div>
                    <div class="col-6 col-md-3 stat-card-wrap">
                        <a href="<?php echo htmlspecialchars($resumeLink('not_confirmed')); ?>" class="stat-card stat-card-link stat-card-danger">
                            <div class="stat-icon"><i class="bi bi-person-x-fill"></i></div>
                            <div>
                                <div class="stat-label">Belum Hadir</div>
                                <div class="stat-number"><?php echo $totalNotAttended; ?></div>
                            </div>
                        </a>
                        <a href="<?php echo htmlspecialchars($resumeExportLink('not_confirmed')); ?>" class="stat-card-export" title="Export CSV">
                            <i class="bi bi-download"></i>
                        </a>
                    </div>
                    <div class="col-6 col-md-3 stat-card-wrap">
                        <a href="<?php echo htmlspecialchars($resumeLink()); ?>" class="stat-card stat-card-link stat-card-warning">
                            <div class="stat-icon"><i class="bi bi-percent"></i></div>
                            <div>
                                <div class="stat-label">Persentase Kehadiran</div>
                                <div class="stat-number"><?php echo $attendancePercentage; ?>%</div>
                            </div>
                        </a>
                        <a href="<?php echo htmlspecialchars($resumeExportLink()); ?>" class="stat-card-export" title="Export CSV">
                            <i class="bi bi-download"></i>
                        </a>
                    </div>
                </div>
                <div class="card mt-3">
                    <div class="card-body">
                        <div class="progress" style="height: 10px;">
                            <div class="progress-bar bg-success" role="progressbar"
                                style="width: <?php echo $attendancePercentage; ?>%;"
                                aria-valuenow="<?php echo $attendancePercentage; ?>" aria-valuemin="0" aria-valuemax="100">
                            </div>
                        </div>
                        <p class="text-muted mb-0 mt-2" style="font-size: 13px;">
                            <?php echo $totalAttended; ?> dari <?php echo $totalAssignedAll; ?> karyawan yang
                            di-assign training sudah hadir (<?php echo $attendancePercentage; ?>%).
                        </p>
                    </div>
                </div>

                <div class="dashboard-header mt-4 d-flex flex-wrap justify-content-between align-items-start gap-2">
                    <div>
                        <h2 class="mb-0">Nilai Quiz per Karyawan</h2>
                        <p>
                            <?php echo $totalQuizTaken; ?> quiz dikerjakan &mdash;
                            <span class="text-success"><?php echo $totalQuizPassed; ?> Lulus</span> /
                            <span class="text-danger"><?php echo $totalQuizFailed; ?> Gagal</span>
                            &mdash; Rata-rata nilai:
                            <strong><?php echo $quizAverageScore !== null ? $quizAverageScore : '-'; ?></strong>
                        </p>
                    </div>
                    <div>
                        <form method="GET" class="d-flex gap-2">
                            <?php if ($filterCompetencyId > 0): ?>
                                <input type="hidden" name="competency_id" value="<?php echo $filterCompetencyId; ?>">
                            <?php endif; ?>
                            <?php if ($filterTrainingDate !== ''): ?>
                                <input type="hidden" name="training_date" value="<?php echo htmlspecialchars($filterTrainingDate); ?>">
                            <?php endif; ?>
                            <input type="text" name="quiz_search" class="form-control" placeholder="NIK, nama, department..."
                                value="<?php echo htmlspecialchars($quizSearch); ?>">
                            <select name="quiz_status" class="form-control" onchange="this.form.submit()">
                                <option value=""<?php echo $quizStatusFilter === '' ? ' selected' : ''; ?>>Semua</option>
                                <option value="passed"<?php echo $quizStatusFilter === 'passed' ? ' selected' : ''; ?>>
                                    Lulus
                                </option>
                                <option value="failed"<?php echo $quizStatusFilter === 'failed' ? ' selected' : ''; ?>>
                                    Gagal
                                </option>
                            </select>
                            <button type="submit" class="btn btn-outline-primary">Cari</button>
                        </form>
                    </div>
                </div>
                <?php if ($quizRetrySuccess): ?>
                    <div class="alert alert-success">
                        Kuis berhasil direset. Karyawan bisa mengerjakan ulang dalam 1 jam ke depan, setelah itu
                        terkunci lagi.
                    </div>
                <?php endif; ?>
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
                                    <th>Sumber</th>
                                    <th>Tanggal</th>
                                    <th>Action</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php if (count($displayedQuizScores) > 0): ?>
                                    <?php foreach ($displayedQuizScores as $quizRow): ?>
                                        <?php
                                        $isPassed = $quizRow['status'] !== 'FAILED';
                                        $retryUntil = $quizRow['quiz_retry_until'] ?? null;
                                        $retryActive = !empty($retryUntil) && strtotime($retryUntil) >= time();
                                        $fromQuiz = !empty($quizRow['quiz_submitted_at']);
                                        ?>
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
                                                <span class="badge <?php echo $fromQuiz ? 'text-bg-info' : 'text-bg-secondary'; ?>">
                                                    <?php echo $fromQuiz ? 'Quiz' : 'Input Manual'; ?>
                                                </span>
                                            </td>
                                            <td>
                                                <?php echo !empty($quizRow['score_date']) ? date('d M Y', strtotime($quizRow['score_date'])) : '-'; ?>
                                            </td>
                                            <td>
                                                <?php if (!$isPassed && portal_can_execute('dashboard')): ?>
                                                    <?php if ($retryActive): ?>
                                                        <span class="badge text-bg-warning">
                                                            Bisa dikerjakan ulang s.d. <?php echo date('H:i', strtotime($retryUntil)); ?>
                                                        </span>
                                                    <?php elseif ((int) $quizRow['portal_reset_allowed'] !== 1): ?>
                                                        <span class="badge text-bg-secondary" title="Reset untuk competency ini dinonaktifkan oleh superadmin">
                                                            Reset dikunci
                                                        </span>
                                                    <?php else: ?>
                                                        <form method="POST" action="employee_competency_quiz_retry.php" class="d-inline"
                                                            onsubmit="return confirm('Reset kuis supaya <?php echo htmlspecialchars(addslashes($quizRow['employee_name']), ENT_QUOTES); ?> bisa mengerjakan ulang dalam 1 jam ke depan?');">
                                                            <?php echo csrf_input(); ?>
                                                            <input type="hidden" name="id" value="<?php echo $quizRow['id']; ?>">
                                                            <button type="submit" class="btn btn-sm btn-outline-warning">
                                                                Reset Quiz
                                                            </button>
                                                        </form>
                                                    <?php endif; ?>
                                                <?php else: ?>
                                                    &mdash;
                                                <?php endif; ?>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                <?php else: ?>
                                    <tr>
                                        <td colspan="9" class="text-center py-4">
                                            Belum ada nilai competency untuk anggota tim.
                                        </td>
                                    </tr>
                                <?php endif; ?>
                            </tbody>
                            <?php if ($quizAverageScore !== null): ?>
                                <tfoot>
                                    <tr class="table-light fw-bold">
                                        <td colspan="3" class="text-end">Rata-rata</td>
                                        <td><?php echo $quizAverageScore; ?></td>
                                        <td colspan="5"></td>
                                    </tr>
                                </tfoot>
                            <?php endif; ?>
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
