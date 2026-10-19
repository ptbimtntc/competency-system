<?php
require_once "auth.php";
require_once "../config/database.php";
require_once "pagination.php";
/*
|--------------------------------------------------------------------------
| Ambil statistik
|--------------------------------------------------------------------------
*/
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
    $_SESSION['admin_dashboard_competency_id'] = $filterCompetencyId;
} else {
    $filterCompetencyId = (int) ($_SESSION['admin_dashboard_competency_id'] ?? 0);
}

/* Total Employee */
if ($filterCompetencyId > 0) {
    $queryEmployee = "
        SELECT COUNT(DISTINCT e.id) AS total
        FROM employees e
        INNER JOIN employee_competencies ec ON ec.employee_id = e.id AND ec.is_active = 1 AND ec.competency_id = ?
        WHERE e.is_deleted = 0
    ";
    $stmtEmployee = mysqli_prepare($conn, $queryEmployee);
    mysqli_stmt_bind_param($stmtEmployee, "i", $filterCompetencyId);
    mysqli_stmt_execute($stmtEmployee);
    $totalEmployee = mysqli_fetch_assoc(mysqli_stmt_get_result($stmtEmployee))['total'];
} else {
    $queryEmployee = "
        SELECT COUNT(*) AS total
        FROM employees
        WHERE is_deleted = 0
    ";
    $resultEmployee = mysqli_query($conn, $queryEmployee);
    $totalEmployee = mysqli_fetch_assoc($resultEmployee)['total'];
}
/* Total Competency (tidak ikut filter -- ini jumlah master kompetensi) */
$queryCompetency = "
    SELECT COUNT(*) AS total
    FROM competencies
";
$resultCompetency =
    mysqli_query(
        $conn,
        $queryCompetency
    );
$totalCompetency =
    mysqli_fetch_assoc(
        $resultCompetency
    )['total'];

function admin_count_by_status(mysqli $conn, string $status, int $competencyId = 0): int
{
    $competencyClause = $competencyId > 0 ? " AND ec.competency_id = ?" : "";
    $query = "
        SELECT COUNT(*) AS total
        FROM employee_competencies ec
        INNER JOIN employees e ON e.id = ec.employee_id
        WHERE ec.status = ? AND ec.is_active = 1 AND e.is_deleted = 0{$competencyClause}
    ";
    $stmt = mysqli_prepare($conn, $query);
    if ($competencyId > 0) {
        mysqli_stmt_bind_param($stmt, "si", $status, $competencyId);
    } else {
        mysqli_stmt_bind_param($stmt, "s", $status);
    }
    mysqli_stmt_execute($stmt);
    return (int) mysqli_fetch_assoc(mysqli_stmt_get_result($stmt))['total'];
}

/* Total Valid */
$totalValid = admin_count_by_status($conn, 'VALID', $filterCompetencyId);
/* Total Expired */
$totalExpired = admin_count_by_status($conn, 'EXPIRED', $filterCompetencyId);
/* Total Assigned */
$totalAssigned = admin_count_by_status($conn, 'ASSIGNED', $filterCompetencyId);
/* Total Failed */
$totalFailed = admin_count_by_status($conn, 'FAILED', $filterCompetencyId);
/* Total Menunggu Kuis -- subset dari Assigned yang sudah konfirmasi hadir
   tapi belum submit kuis, supaya terlihat terpisah dari yang belum hadir
   sama sekali (butuh tindak lanjut berbeda). */
$queryWaitingQuiz = "
    SELECT COUNT(*) AS total
    FROM employee_competencies ec
    INNER JOIN employees e ON e.id = ec.employee_id
    WHERE ec.status = 'ASSIGNED' AND ec.attendance_confirmed = 1
        AND ec.is_active = 1 AND e.is_deleted = 0
" . ($filterCompetencyId > 0 ? " AND ec.competency_id = ?" : "");
$stmtWaitingQuiz = mysqli_prepare($conn, $queryWaitingQuiz);
if ($filterCompetencyId > 0) {
    mysqli_stmt_bind_param($stmtWaitingQuiz, "i", $filterCompetencyId);
}
mysqli_stmt_execute($stmtWaitingQuiz);
$totalWaitingQuiz = (int) mysqli_fetch_assoc(mysqli_stmt_get_result($stmtWaitingQuiz))['total'];

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

/* Resume Assignment & Kehadiran Training
   "Di-assign" mencakup baik yang melalui alur quiz (scheduled_training_date
   terisi) MAUPUN yang nilainya diinput/diimport manual tanpa quiz
   (training_date langsung terisi, scheduled_training_date tetap kosong).
   Begitu juga "Hadir" -- training_date terisi artinya training sudah
   benar-benar terjadi (lewat quiz ATAU input manual), jadi dihitung hadir
   walau attendance_confirmed belum/tidak pernah dicentang lewat fitur
   konfirmasi kehadiran. */
$queryAssignSummary = "
    SELECT
        COUNT(*) AS total_assigned,
        SUM(CASE WHEN ec.attendance_confirmed = 1 OR ec.training_date IS NOT NULL THEN 1 ELSE 0 END) AS total_attended
    FROM employee_competencies ec
    INNER JOIN employees e ON e.id = ec.employee_id
    WHERE e.is_deleted = 0 AND ec.is_active = 1
        AND (ec.scheduled_training_date IS NOT NULL OR ec.training_date IS NOT NULL)
" . ($filterCompetencyId > 0 ? " AND ec.competency_id = ?" : "")
  /* Training bisa tercatat lewat scheduled_training_date (alur quiz) ATAU
     training_date (realisasi/input manual) -- cocokkan salah satu agar
     training yang sudah terjadi di tanggal tersebut tidak terlewat. */
  . ($filterTrainingDate !== '' ? " AND (ec.scheduled_training_date = ? OR ec.training_date = ?)" : "");
$assignSummaryTypes = ($filterCompetencyId > 0 ? "i" : "") . ($filterTrainingDate !== '' ? "ss" : "");
$assignSummaryParams = [];
if ($filterCompetencyId > 0) {
    $assignSummaryParams[] = $filterCompetencyId;
}
if ($filterTrainingDate !== '') {
    $assignSummaryParams[] = $filterTrainingDate;
    $assignSummaryParams[] = $filterTrainingDate;
}
$resultAssignSummaryStmt = mysqli_prepare($conn, $queryAssignSummary);
if (!empty($assignSummaryParams)) {
    mysqli_stmt_bind_param($resultAssignSummaryStmt, $assignSummaryTypes, ...$assignSummaryParams);
}
mysqli_stmt_execute($resultAssignSummaryStmt);
$assignSummary = mysqli_fetch_assoc(mysqli_stmt_get_result($resultAssignSummaryStmt));
$totalAssignedAll = (int) $assignSummary['total_assigned'];
$totalAttended = (int) $assignSummary['total_attended'];
$totalNotAttended = $totalAssignedAll - $totalAttended;
$attendancePercentage = $totalAssignedAll > 0
    ? round($totalAttended / $totalAssignedAll * 100, 1)
    : 0;
/* Nilai Quiz per Karyawan */
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
    WHERE e.is_deleted = 0 AND ec.is_active = 1 AND ec.score IS NOT NULL
" . ($filterCompetencyId > 0 ? " AND ec.competency_id = ?" : "");
$quizSummaryStmt = mysqli_prepare($conn, $queryQuizSummary);
if ($filterCompetencyId > 0) {
    mysqli_stmt_bind_param($quizSummaryStmt, "i", $filterCompetencyId);
}
mysqli_stmt_execute($quizSummaryStmt);
$quizSummary = mysqli_fetch_assoc(mysqli_stmt_get_result($quizSummaryStmt));
$totalQuizTaken = (int) $quizSummary['total'];
$totalQuizPassed = (int) $quizSummary['total_passed'];
$totalQuizFailed = $totalQuizTaken - $totalQuizPassed;

$quizSearch = isset($_GET['quiz_search']) ? trim($_GET['quiz_search']) : '';

$quizConditions = ["e.is_deleted = 0", "ec.is_active = 1", "ec.score IS NOT NULL"];
$quizParams = [];
$quizTypes = "";
if ($filterCompetencyId > 0) {
    $quizConditions[] = "ec.competency_id = ?";
    $quizParams[] = $filterCompetencyId;
    $quizTypes .= "i";
}
if ($filterTrainingDate !== '') {
    $quizConditions[] = "(ec.scheduled_training_date = ? OR ec.training_date = ?)";
    $quizParams[] = $filterTrainingDate;
    $quizParams[] = $filterTrainingDate;
    $quizTypes .= "ss";
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
    SELECT COUNT(*) AS total, AVG(ec.score) AS avg_score
    FROM employee_competencies ec
    INNER JOIN employees e ON e.id = ec.employee_id
    WHERE {$quizWhereSql}
");
if (!empty($quizParams)) {
    mysqli_stmt_bind_param($quizCountStmt, $quizTypes, ...$quizParams);
}
mysqli_stmt_execute($quizCountStmt);
$quizCountRow = mysqli_fetch_assoc(mysqli_stmt_get_result($quizCountStmt));
$quizFilteredTotal = (int) $quizCountRow['total'];
$quizAverageScore = $quizCountRow['avg_score'] !== null ? round((float) $quizCountRow['avg_score'], 1) : null;
$quizPg = paginate($quizFilteredTotal, 15);

/* Urutan: nilai tertinggi lebih dulu (berlaku lintas quiz & input manual --
   nilai 100 input manual tetap di atas nilai 90 quiz). Hanya ketika nilainya
   sama, baris dari quiz didahulukan dari input manual, dan di antara quiz
   yang submit lebih cepat (quiz_submitted_at lebih awal) didahulukan. */
$queryQuizScores = "
    SELECT
        ec.id,
        e.nik,
        e.name AS employee_name,
        c.name AS competency_name,
        ec.score,
        c.passing_score,
        ec.status,
        ec.quiz_submitted_at,
        ec.quiz_retry_until,
        COALESCE(ec.quiz_submitted_at, ec.training_date, ec.updated_at) AS score_date
    FROM employee_competencies ec
    INNER JOIN employees e ON e.id = ec.employee_id
    INNER JOIN competencies c ON c.id = ec.competency_id
    WHERE {$quizWhereSql}
    ORDER BY ec.score DESC, (ec.quiz_submitted_at IS NULL) ASC, ec.quiz_submitted_at ASC
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
    <meta
        name="viewport"
        content="width=device-width, initial-scale=1.0"
    >
    <title>
        Dashboard - Bekaert Competency
    </title>
    <link
        href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css"
        rel="stylesheet"
    >
    <link
        href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css"
        rel="stylesheet"
    >
    <link
        rel="stylesheet"
        href="../assets/css/style.css?v=<?php echo filemtime(__DIR__ . '/../assets/css/style.css'); ?>"
    >
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
            <?php
            echo htmlspecialchars(
                $_SESSION['admin_name']
            );
            ?>
            <span class="badge text-bg-light">
                <?php echo htmlspecialchars(role_label(current_admin_role())); ?>
            </span>
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
    <div class="dashboard-header d-flex flex-wrap justify-content-between align-items-start gap-2">
        <div>
            <h1>
                Dashboard
            </h1>
            <p>
                Employee Competency Management
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
| Link kartu status -> competency_status.php, ikut filter kompetensi
|--------------------------------------------------------------------------
*/
$statusLink = function (string $status, array $extra = []) use ($filterCompetencyId): string {
    $params = array_merge(['status' => $status], $extra);
    if ($filterCompetencyId > 0) {
        $params['competency_id'] = $filterCompetencyId;
    }
    return 'competency_status.php?' . http_build_query($params);
};
$statusExportLink = function (string $status, array $extra = []) use ($filterCompetencyId): string {
    $params = array_merge(['status' => $status], $extra);
    if ($filterCompetencyId > 0) {
        $params['competency_id'] = $filterCompetencyId;
    }
    return 'competency_status_export.php?' . http_build_query($params);
};
?>
<div class="row g-3">
    <div class="col-6 col-sm-4 col-md-3 col-lg-2">
        <a href="employees.php" class="stat-card stat-card-link stat-card-primary">
            <div class="stat-icon"><i class="bi bi-people-fill"></i></div>
            <div>
                <div class="stat-label">Employees</div>
                <div class="stat-number"><?php echo $totalEmployee; ?></div>
            </div>
        </a>
    </div>
    <div class="col-6 col-sm-4 col-md-3 col-lg-2">
        <a href="competencies.php" class="stat-card stat-card-link stat-card-primary">
            <div class="stat-icon"><i class="bi bi-lightning-charge-fill"></i></div>
            <div>
                <div class="stat-label">Competencies</div>
                <div class="stat-number"><?php echo $totalCompetency; ?></div>
            </div>
        </a>
    </div>
    <div class="col-6 col-sm-4 col-md-3 col-lg-2 stat-card-wrap">
        <a href="<?php echo htmlspecialchars($statusLink('VALID')); ?>" class="stat-card stat-card-link stat-card-success">
            <div class="stat-icon"><i class="bi bi-check-circle-fill"></i></div>
            <div>
                <div class="stat-label">Valid</div>
                <div class="stat-number"><?php echo $totalValid; ?></div>
            </div>
        </a>
        <a href="<?php echo htmlspecialchars($statusExportLink('VALID')); ?>" class="stat-card-export" title="Export CSV">
            <i class="bi bi-download"></i>
        </a>
    </div>
    <div class="col-6 col-sm-4 col-md-3 col-lg-2 stat-card-wrap">
        <a href="<?php echo htmlspecialchars($statusLink('EXPIRED')); ?>" class="stat-card stat-card-link stat-card-danger">
            <div class="stat-icon"><i class="bi bi-exclamation-triangle-fill"></i></div>
            <div>
                <div class="stat-label">Expired</div>
                <div class="stat-number"><?php echo $totalExpired; ?></div>
            </div>
        </a>
        <a href="<?php echo htmlspecialchars($statusExportLink('EXPIRED')); ?>" class="stat-card-export" title="Export CSV">
            <i class="bi bi-download"></i>
        </a>
    </div>
    <div class="col-6 col-sm-4 col-md-3 col-lg-2 stat-card-wrap">
        <a href="<?php echo htmlspecialchars($statusLink('ASSIGNED')); ?>" class="stat-card stat-card-link stat-card-warning">
            <div class="stat-icon"><i class="bi bi-hourglass-split"></i></div>
            <div>
                <div class="stat-label">Assigned</div>
                <div class="stat-number"><?php echo $totalAssigned; ?></div>
            </div>
        </a>
        <a href="<?php echo htmlspecialchars($statusExportLink('ASSIGNED')); ?>" class="stat-card-export" title="Export CSV">
            <i class="bi bi-download"></i>
        </a>
    </div>
    <div class="col-6 col-sm-4 col-md-3 col-lg-2 stat-card-wrap">
        <a href="<?php echo htmlspecialchars($statusLink('ASSIGNED', ['attendance' => 'confirmed'])); ?>" class="stat-card stat-card-link stat-card-info">
            <div class="stat-icon"><i class="bi bi-pencil-square"></i></div>
            <div>
                <div class="stat-label">Menunggu Kuis</div>
                <div class="stat-number"><?php echo $totalWaitingQuiz; ?></div>
            </div>
        </a>
        <a href="<?php echo htmlspecialchars($statusExportLink('ASSIGNED', ['attendance' => 'confirmed'])); ?>" class="stat-card-export" title="Export CSV">
            <i class="bi bi-download"></i>
        </a>
    </div>
    <div class="col-6 col-sm-4 col-md-3 col-lg-2 stat-card-wrap">
        <a href="<?php echo htmlspecialchars($statusLink('FAILED')); ?>" class="stat-card stat-card-link stat-card-danger">
            <div class="stat-icon"><i class="bi bi-x-circle-fill"></i></div>
            <div>
                <div class="stat-label">Failed</div>
                <div class="stat-number"><?php echo $totalFailed; ?></div>
            </div>
        </a>
        <a href="<?php echo htmlspecialchars($statusExportLink('FAILED')); ?>" class="stat-card-export" title="Export CSV">
            <i class="bi bi-download"></i>
        </a>
    </div>
</div>

    <div class="dashboard-header mt-4 d-flex flex-wrap justify-content-between align-items-start gap-2">
        <div>
            <h2 class="mb-0">
                Resume Assignment &amp; Kehadiran Training
            </h2>
            <p>
                Nilai Quiz per Karyawan di bawah juga ikut mengikuti filter kompetensi di atas.
            </p>
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
        return 'training_resume.php' . (empty($params) ? '' : ('?' . http_build_query($params)));
    };
    $resumeExportLink = function (string $attendance = '') use ($resumeBaseParams): string {
        $params = $resumeBaseParams;
        if ($attendance !== '') {
            $params['attendance'] = $attendance;
        }
        return 'training_resume_export.php' . (empty($params) ? '' : ('?' . http_build_query($params)));
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
                <?php echo $totalAttended; ?> dari <?php echo $totalAssignedAll; ?> karyawan yang di-assign training
                sudah hadir (<?php echo $attendancePercentage; ?>%).
            </p>
        </div>
    </div>

    <div class="dashboard-header mt-4 d-flex flex-wrap justify-content-between align-items-start gap-2">
        <div>
            <h2 class="mb-0">
                Nilai Quiz per Karyawan
            </h2>
            <p>
                <?php echo $totalQuizTaken; ?> quiz dikerjakan &mdash;
                <span class="text-success"><?php echo $totalQuizPassed; ?> Lulus</span> /
                <span class="text-danger"><?php echo $totalQuizFailed; ?> Gagal</span>
                &mdash; Rata-rata nilai:
                <strong><?php echo $quizAverageScore !== null ? $quizAverageScore : '-'; ?></strong>
            </p>
        </div>
        <div class="d-flex gap-2 align-items-end flex-wrap">
            <a href="dashboard_quiz_export.php?<?php echo htmlspecialchars(http_build_query($quizPaginationBaseParams)); ?>"
                class="btn btn-outline-secondary">
                <i class="bi bi-download"></i> Export CSV
            </a>
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
            Kuis berhasil direset. Karyawan bisa mengerjakan ulang dalam 1 jam ke depan, setelah itu terkunci lagi.
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
                                    <?php if (!$isPassed && admin_can_write()): ?>
                                        <?php if ($retryActive): ?>
                                            <span class="badge text-bg-warning">
                                                Bisa dikerjakan ulang s.d. <?php echo date('H:i', strtotime($retryUntil)); ?>
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
                                Belum ada nilai competency untuk karyawan.
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