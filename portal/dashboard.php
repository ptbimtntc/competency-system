<?php
require_once "../includes/portal_auth.php";
portal_require_view('dashboard');

$scopeNiks = portal_scope_niks($conn);

/* Total team members */
if ($scopeNiks === null) {
    $totalMembers = mysqli_fetch_assoc(mysqli_query($conn, "SELECT COUNT(*) AS total FROM employees WHERE is_deleted = 0"))['total'];
} else {
    $totalMembers = count($scopeNiks);
}

function portal_count_by_status(mysqli $conn, string $status, ?array $scopeNiks): int
{
    [$scopeClause, $scopeParams] = portal_scope_where($scopeNiks);
    $query = "
        SELECT COUNT(*) AS total
        FROM employee_competencies ec
        INNER JOIN employees e ON e.id = ec.employee_id
        WHERE ec.status = ? AND ec.is_active = 1 AND e.is_deleted = 0 {$scopeClause}
    ";
    $stmt = mysqli_prepare($conn, $query);
    $types = "s" . str_repeat("s", count($scopeParams));
    $params = array_merge([$status], $scopeParams);
    mysqli_stmt_bind_param($stmt, $types, ...$params);
    mysqli_stmt_execute($stmt);
    return (int) mysqli_fetch_assoc(mysqli_stmt_get_result($stmt))['total'];
}

$totalValid = portal_count_by_status($conn, 'VALID', $scopeNiks);
$totalExpired = portal_count_by_status($conn, 'EXPIRED', $scopeNiks);
$totalAssigned = portal_count_by_status($conn, 'ASSIGNED', $scopeNiks);
$totalFailed = portal_count_by_status($conn, 'FAILED', $scopeNiks);

/* Daftar kompetensi untuk filter resume assignment/kehadiran & quiz */
$resultCompetencyOptions = mysqli_query($conn, "SELECT id, name FROM competencies ORDER BY name ASC");
$competencyOptions = [];
while ($row = mysqli_fetch_assoc($resultCompetencyOptions)) {
    $competencyOptions[] = $row;
}
$filterCompetencyId = isset($_GET['competency_id']) ? (int) $_GET['competency_id'] : 0;

/* Resume Assignment & Kehadiran Training (tim) */
[$scopeClause, $scopeParams] = portal_scope_where($scopeNiks);
$competencyClause = $filterCompetencyId > 0 ? " AND ec.competency_id = ?" : "";

$queryAssignSummary = "
    SELECT
        COUNT(*) AS total_assigned,
        SUM(CASE WHEN ec.attendance_confirmed = 1 THEN 1 ELSE 0 END) AS total_attended
    FROM employee_competencies ec
    INNER JOIN employees e ON e.id = ec.employee_id
    WHERE e.is_deleted = 0 AND ec.is_active = 1 AND ec.scheduled_training_date IS NOT NULL {$scopeClause}{$competencyClause}
";
$assignParams = $filterCompetencyId > 0 ? array_merge($scopeParams, [$filterCompetencyId]) : $scopeParams;
$assignTypes = str_repeat('s', count($scopeParams)) . ($filterCompetencyId > 0 ? 'i' : '');
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
$queryQuizScores = "
    SELECT
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
    WHERE e.is_deleted = 0 AND ec.is_active = 1 AND ec.quiz_submitted_at IS NOT NULL {$scopeClause}{$competencyClause}
    ORDER BY ec.quiz_submitted_at DESC
";
$quizParams = $filterCompetencyId > 0 ? array_merge($scopeParams, [$filterCompetencyId]) : $scopeParams;
$quizTypes = str_repeat('s', count($scopeParams)) . ($filterCompetencyId > 0 ? 'i' : '');
$quizStmt = mysqli_prepare($conn, $queryQuizScores);
if (!empty($quizParams)) {
    mysqli_stmt_bind_param($quizStmt, $quizTypes, ...$quizParams);
}
mysqli_stmt_execute($quizStmt);
$quizResult = mysqli_stmt_get_result($quizStmt);
$quizScores = [];
while ($row = mysqli_fetch_assoc($quizResult)) {
    $quizScores[] = $row;
}
$totalQuizTaken = count($quizScores);
$totalQuizPassed = count(array_filter($quizScores, fn($r) => $r['status'] !== 'FAILED'));
$totalQuizFailed = $totalQuizTaken - $totalQuizPassed;
?>
<!DOCTYPE html>
<html lang="en">

<head>
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
                <div class="dashboard-header">
                    <div>
                        <h1>Dashboard Tim</h1>
                        <p>
                            <?php echo portal_is_superadmin() ? 'Seluruh karyawan' : 'Anda &amp; bawahan Anda'; ?>
                        </p>
                    </div>
                </div>
                <div class="row g-3">
                    <div class="col-md-4 col-sm-6">
                        <div class="stat-card stat-card-primary">
                            <div class="stat-icon"><i class="bi bi-people-fill"></i></div>
                            <div>
                                <div class="stat-label">Anggota Tim</div>
                                <div class="stat-number"><?php echo $totalMembers; ?></div>
                            </div>
                        </div>
                    </div>
                    <div class="col-md-4 col-sm-6">
                        <a href="team_members.php" class="stat-card stat-card-link stat-card-success">
                            <div class="stat-icon"><i class="bi bi-check-circle-fill"></i></div>
                            <div>
                                <div class="stat-label">Valid</div>
                                <div class="stat-number"><?php echo $totalValid; ?></div>
                            </div>
                        </a>
                    </div>
                    <div class="col-md-4 col-sm-6">
                        <a href="team_recertification.php" class="stat-card stat-card-link stat-card-danger">
                            <div class="stat-icon"><i class="bi bi-exclamation-triangle-fill"></i></div>
                            <div>
                                <div class="stat-label">Expired</div>
                                <div class="stat-number"><?php echo $totalExpired; ?></div>
                            </div>
                        </a>
                    </div>
                    <div class="col-md-4 col-sm-6">
                        <a href="team_attendance.php" class="stat-card stat-card-link stat-card-warning">
                            <div class="stat-icon"><i class="bi bi-hourglass-split"></i></div>
                            <div>
                                <div class="stat-label">Assigned</div>
                                <div class="stat-number"><?php echo $totalAssigned; ?></div>
                            </div>
                        </a>
                    </div>
                    <div class="col-md-4 col-sm-6">
                        <a href="team_competency_gap.php" class="stat-card stat-card-link stat-card-danger">
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
                        <p>Nilai Quiz per Karyawan di bawah juga ikut mengikuti filter ini.</p>
                    </div>
                    <div>
                        <form method="GET" class="d-flex gap-2">
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
                <div class="row g-3">
                    <div class="col-md-3 col-sm-6">
                        <div class="stat-card stat-card-primary">
                            <div class="stat-icon"><i class="bi bi-clipboard-check-fill"></i></div>
                            <div>
                                <div class="stat-label">Total Di-assign</div>
                                <div class="stat-number"><?php echo $totalAssignedAll; ?></div>
                            </div>
                        </div>
                    </div>
                    <div class="col-md-3 col-sm-6">
                        <div class="stat-card stat-card-success">
                            <div class="stat-icon"><i class="bi bi-person-check-fill"></i></div>
                            <div>
                                <div class="stat-label">Hadir</div>
                                <div class="stat-number"><?php echo $totalAttended; ?></div>
                            </div>
                        </div>
                    </div>
                    <div class="col-md-3 col-sm-6">
                        <div class="stat-card stat-card-danger">
                            <div class="stat-icon"><i class="bi bi-person-x-fill"></i></div>
                            <div>
                                <div class="stat-label">Belum Hadir</div>
                                <div class="stat-number"><?php echo $totalNotAttended; ?></div>
                            </div>
                        </div>
                    </div>
                    <div class="col-md-3 col-sm-6">
                        <div class="stat-card stat-card-warning">
                            <div class="stat-icon"><i class="bi bi-percent"></i></div>
                            <div>
                                <div class="stat-label">Persentase Kehadiran</div>
                                <div class="stat-number"><?php echo $attendancePercentage; ?>%</div>
                            </div>
                        </div>
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

                <div class="dashboard-header mt-4">
                    <div>
                        <h2 class="mb-0">Nilai Quiz per Karyawan</h2>
                        <p>
                            <?php echo $totalQuizTaken; ?> quiz dikerjakan &mdash;
                            <span class="text-success"><?php echo $totalQuizPassed; ?> Lulus</span> /
                            <span class="text-danger"><?php echo $totalQuizFailed; ?> Gagal</span>
                        </p>
                    </div>
                </div>
                <div class="card">
                    <div class="table-responsive" style="max-height: 420px; overflow-y: auto;">
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
                                </tr>
                            </thead>
                            <tbody>
                                <?php if (count($quizScores) > 0): ?>
                                    <?php foreach ($quizScores as $quizRow): ?>
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
                                            <td><?php echo date('d M Y', strtotime($quizRow['quiz_submitted_at'])); ?></td>
                                        </tr>
                                    <?php endforeach; ?>
                                <?php else: ?>
                                    <tr>
                                        <td colspan="7" class="text-center py-4">
                                            Belum ada anggota tim yang mengerjakan quiz.
                                        </td>
                                    </tr>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </main>
    </div>
</body>

</html>
