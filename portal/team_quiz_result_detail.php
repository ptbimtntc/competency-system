<?php
require_once "../includes/portal_auth.php";
portal_require_view('team_quiz_results');
require_once "../includes/competency_helper.php";

$employeeCompetencyId = isset($_GET['id']) ? (int) $_GET['id'] : 0;
if ($employeeCompetencyId <= 0) {
    header("Location: team_quiz_results.php");
    exit;
}

$headerStmt = mysqli_prepare(
    $conn,
    "SELECT
        ec.id,
        e.nik,
        e.name AS employee_name,
        e.department,
        c.name AS competency_name,
        ec.score,
        c.passing_score,
        ec.status,
        ec.quiz_submitted_at
    FROM employee_competencies ec
    INNER JOIN employees e ON e.id = ec.employee_id
    INNER JOIN competencies c ON c.id = ec.competency_id
    WHERE ec.id = ? AND e.is_deleted = 0
    LIMIT 1"
);
mysqli_stmt_bind_param($headerStmt, "i", $employeeCompetencyId);
mysqli_stmt_execute($headerStmt);
$info = mysqli_fetch_assoc(mysqli_stmt_get_result($headerStmt));
if (!$info) {
    die("Data hasil quiz tidak ditemukan.");
}

/*
|--------------------------------------------------------------------------
| Pastikan karyawan ini memang bawahan (atau diri sendiri) -- menu-level
| portal_can_view('team_quiz_results') tidak cukup, karena ID di URL bisa
| diganti ke employee_competency_id milik karyawan lain di luar tim.
|--------------------------------------------------------------------------
*/
$scopeNiks = portal_scope_niks($conn);
if ($scopeNiks !== null && !in_array($info['nik'], $scopeNiks, true)) {
    http_response_code(403);
    die('Anda tidak memiliki akses untuk melihat hasil quiz karyawan ini.');
}

$isPassed = $info['status'] !== 'FAILED';
$questions = getQuizAnswerBreakdown($conn, $employeeCompetencyId);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <link rel="icon" type="image/x-icon" href="../favicon.ico">
    <link rel="icon" type="image/png" sizes="32x32" href="../assets/images/favicon-32.png">
    <link rel="apple-touch-icon" href="../assets/images/apple-touch-icon.png">
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Hasil Quiz - <?php echo htmlspecialchars($info['employee_name']); ?></title>
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
            <div class="mb-3">
                <a href="team_quiz_results.php">&larr; Kembali ke Hasil Quiz Tim</a>
            </div>
            <div class="dashboard-header d-flex flex-wrap justify-content-between align-items-start gap-2">
                <div>
                    <h1><?php echo htmlspecialchars($info['employee_name']); ?></h1>
                    <p>
                        NIK <?php echo htmlspecialchars($info['nik']); ?>
                        &middot; <?php echo htmlspecialchars($info['department']); ?>
                        &middot; <?php echo htmlspecialchars($info['competency_name']); ?>
                    </p>
                </div>
            </div>
            <div class="row g-3 mb-3">
                <div class="col-6 col-md-3">
                    <div class="stat-card stat-card-primary">
                        <div class="stat-icon"><i class="bi bi-percent"></i></div>
                        <div>
                            <div class="stat-label">Skor</div>
                            <div class="stat-number"><?php echo htmlspecialchars((string) $info['score']); ?></div>
                        </div>
                    </div>
                </div>
                <div class="col-6 col-md-3">
                    <div class="stat-card <?php echo $isPassed ? 'stat-card-success' : 'stat-card-danger'; ?>">
                        <div class="stat-icon"><i class="bi <?php echo $isPassed ? 'bi-check-circle-fill' : 'bi-x-circle-fill'; ?>"></i></div>
                        <div>
                            <div class="stat-label">Status</div>
                            <div class="stat-number"><?php echo $isPassed ? 'Lulus' : 'Gagal'; ?></div>
                        </div>
                    </div>
                </div>
                <div class="col-6 col-md-3">
                    <div class="stat-card stat-card-info">
                        <div class="stat-icon"><i class="bi bi-bullseye"></i></div>
                        <div>
                            <div class="stat-label">KKM</div>
                            <div class="stat-number"><?php echo htmlspecialchars((string) $info['passing_score']); ?></div>
                        </div>
                    </div>
                </div>
                <div class="col-6 col-md-3">
                    <div class="stat-card stat-card-warning">
                        <div class="stat-icon"><i class="bi bi-calendar-check"></i></div>
                        <div>
                            <div class="stat-label">Tanggal Submit</div>
                            <div class="stat-number" style="font-size:16px;">
                                <?php echo !empty($info['quiz_submitted_at']) ? date('d M Y H:i', strtotime($info['quiz_submitted_at'])) : '-'; ?>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <?php if (empty($questions)): ?>
                <div class="alert alert-warning">
                    Rincian jawaban tidak ditemukan. Kemungkinan soal sudah dihapus/diubah, atau quiz direset setelah
                    pengerjaan ini.
                </div>
            <?php endif; ?>

            <?php foreach ($questions as $index => $question): ?>
                <div class="card mb-3 <?php echo $question['is_correct'] ? 'border-success' : 'border-danger'; ?>">
                    <div class="card-body">
                        <div class="d-flex justify-content-between align-items-start gap-2 mb-3">
                            <div class="quiz-question-text">
                                <strong><?php echo ($index + 1) . ". "; ?></strong>
                                <?php echo nl2br(htmlspecialchars($question['question_text'])); ?>
                            </div>
                            <span class="badge <?php echo $question['is_correct'] ? 'text-bg-success' : 'text-bg-danger'; ?>">
                                <?php echo $question['is_correct'] ? 'Benar' : 'Salah'; ?>
                            </span>
                        </div>
                        <?php foreach ($question['choices'] as $choice): ?>
                            <?php
                            $rowClass = '';
                            if ($choice['is_correct'] === 1 && $choice['selected']) {
                                $rowClass = 'text-bg-success';
                            } elseif ($choice['is_correct'] === 1) {
                                $rowClass = 'text-bg-success-subtle border border-success';
                            } elseif ($choice['selected']) {
                                $rowClass = 'text-bg-danger';
                            }
                            ?>
                            <div class="d-flex align-items-center gap-2 mb-2 p-2 rounded <?php echo $rowClass; ?>">
                                <?php if ($choice['selected']): ?>
                                    <i class="bi bi-check2-square"></i>
                                <?php else: ?>
                                    <i class="bi bi-square"></i>
                                <?php endif; ?>
                                <span>
                                    <strong><?php echo htmlspecialchars($choice['option_label']); ?>.</strong>
                                    <?php echo htmlspecialchars($choice['choice_text']); ?>
                                </span>
                                <?php if ($choice['is_correct'] === 1): ?>
                                    <span class="badge text-bg-light ms-auto">Jawaban Benar</span>
                                <?php endif; ?>
                            </div>
                        <?php endforeach; ?>
                        <?php if (!$question['answered']): ?>
                            <p class="text-muted mb-0" style="font-size: 13px;">
                                Karyawan tidak memilih jawaban apa pun untuk soal ini.
                            </p>
                        <?php endif; ?>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>
    </main>
</div>
</body>
</html>
