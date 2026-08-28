<?php
require_once "auth.php";
require_once "../config/database.php";
require_once "../includes/competency_helper.php";
/*
|--------------------------------------------------------------------------
| Filter
|--------------------------------------------------------------------------
|
| window        : rentang jatuh tempo yang ditampilkan di daftar utama
| team          : filter tim karyawan (A/B/C/D/NS)
| competency_id : batasi ke satu competency
| search        : cari NIK / nama karyawan
|
*/
$allowedWindows = ['30', '60', '90', 'expired', 'all'];
$window = trim($_GET['window'] ?? '60');
if (!in_array($window, $allowedWindows, true)) {
    $window = '60';
}
$windowLabels = [
    '30' => 'Jatuh tempo ≤ 30 hari (termasuk yang sudah lewat)',
    '60' => 'Jatuh tempo ≤ 60 hari (termasuk yang sudah lewat)',
    '90' => 'Jatuh tempo ≤ 90 hari (termasuk yang sudah lewat)',
    'expired' => 'Sudah lewat masa berlaku',
    'all' => 'Semua competency yang punya masa berlaku',
];
$allowedTeams = ['A', 'B', 'C', 'D', 'NS'];
$team = strtoupper(trim($_GET['team'] ?? ''));
if (!in_array($team, $allowedTeams, true)) {
    $team = '';
}
$competencyId = isset($_GET['competency_id']) ? (int) $_GET['competency_id'] : 0;
$search = trim($_GET['search'] ?? '');
/*
|--------------------------------------------------------------------------
| Scope filter (team / competency / search)
|--------------------------------------------------------------------------
|
| Dipakai bersama oleh query daftar utama dan query ringkasan. Filter
| "window" hanya diterapkan di daftar utama.
|
*/
$scopeConditions = ["ec.is_active = 1", "ec.expiry_date IS NOT NULL", "e.is_deleted = 0"];
$scopeParams = [];
$scopeTypes = "";
if ($team !== '') {
    $scopeConditions[] = "e.team = ?";
    $scopeParams[] = $team;
    $scopeTypes .= "s";
}
if ($competencyId > 0) {
    $scopeConditions[] = "ec.competency_id = ?";
    $scopeParams[] = $competencyId;
    $scopeTypes .= "i";
}
if ($search !== '') {
    $scopeConditions[] = "(e.nik LIKE ? OR e.name LIKE ?)";
    $keyword = "%" . $search . "%";
    $scopeParams[] = $keyword;
    $scopeParams[] = $keyword;
    $scopeTypes .= "ss";
}
/*
|--------------------------------------------------------------------------
| Daftar utama (scope + window)
|--------------------------------------------------------------------------
*/
$listConditions = $scopeConditions;
$listParams = $scopeParams;
$listTypes = $scopeTypes;
if ($window === 'expired') {
    $listConditions[] = "ec.expiry_date < CURDATE()";
} elseif (in_array($window, ['30', '60', '90'], true)) {
    $listConditions[] = "ec.expiry_date <= DATE_ADD(CURDATE(), INTERVAL ? DAY)";
    $listParams[] = (int) $window;
    $listTypes .= "i";
}
$listQuery = "
    SELECT
        ec.id,
        ec.competency_id,
        ec.training_date,
        ec.expiry_date,
        ec.score,
        c.passing_score,
        DATEDIFF(ec.expiry_date, CURDATE()) AS days_remaining,
        e.nik,
        e.name AS employee_name,
        e.team,
        e.department,
        c.name AS competency_name
    FROM employee_competencies ec
    INNER JOIN employees e ON e.id = ec.employee_id
    INNER JOIN competencies c ON c.id = ec.competency_id
    WHERE " . implode(" AND ", $listConditions) . "
    ORDER BY ec.expiry_date ASC, e.name ASC
";
$listStmt = mysqli_prepare($conn, $listQuery);
if (count($listParams) > 0) {
    mysqli_stmt_bind_param($listStmt, $listTypes, ...$listParams);
}
mysqli_stmt_execute($listStmt);
$listResult = mysqli_stmt_get_result($listStmt);
/*
|--------------------------------------------------------------------------
| Ringkasan (scope saja, tanpa window)
|--------------------------------------------------------------------------
*/
$summaryQuery = "
    SELECT
        SUM(days_remaining < 0) AS overdue,
        SUM(days_remaining BETWEEN 0 AND 30) AS due_30,
        SUM(days_remaining BETWEEN 0 AND 60) AS due_60,
        SUM(days_remaining BETWEEN 0 AND 90) AS due_90
    FROM (
        SELECT DATEDIFF(ec.expiry_date, CURDATE()) AS days_remaining
        FROM employee_competencies ec
        INNER JOIN employees e ON e.id = ec.employee_id
        WHERE " . implode(" AND ", $scopeConditions) . "
    ) t
";
$summaryStmt = mysqli_prepare($conn, $summaryQuery);
if (count($scopeParams) > 0) {
    mysqli_stmt_bind_param($summaryStmt, $scopeTypes, ...$scopeParams);
}
mysqli_stmt_execute($summaryStmt);
$summary = mysqli_fetch_assoc(mysqli_stmt_get_result($summaryStmt));
$summaryOverdue = (int) ($summary['overdue'] ?? 0);
$summaryDue30 = (int) ($summary['due_30'] ?? 0);
$summaryDue60 = (int) ($summary['due_60'] ?? 0);
$summaryDue90 = (int) ($summary['due_90'] ?? 0);
/*
|--------------------------------------------------------------------------
| Dropdown competency
|--------------------------------------------------------------------------
*/
$competencyOptions = mysqli_query($conn, "SELECT id, name FROM competencies ORDER BY name ASC");
/*
|--------------------------------------------------------------------------
| Query string aktif (untuk link Export & Back)
|--------------------------------------------------------------------------
*/
$filterParams = array_filter([
    'window' => $window,
    'team' => $team,
    'competency_id' => $competencyId > 0 ? $competencyId : null,
    'search' => $search,
], function ($value) {
    return $value !== null && $value !== '';
});
$queryString = http_build_query($filterParams, '', '&', PHP_QUERY_RFC3986);
?>

<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>
        Recertification Due - Bekaert Competency
    </title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="../assets/css/style.css">
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
                Logout
            </a>
        </div>
    </nav>
    <div class="admin-container">
        <div class="page-header">
            <div>
                <h1>
                    Recertification Due
                </h1>
                <p>
                    Competency karyawan yang sudah / akan habis masa berlakunya &mdash; per hari ini
                    <?php echo date('d M Y'); ?>
                </p>
            </div>
            <div class="d-flex gap-2">
                <a href="dashboard.php" class="btn btn-outline-secondary">
                    &larr; Dashboard
                </a>
                <a href="recertification_export.php<?php echo $queryString !== '' ? '?' . htmlspecialchars($queryString) : ''; ?>"
                    class="btn btn-outline-secondary">
                    Export CSV
                </a>
            </div>
        </div>

        <!-- RINGKASAN -->
        <div class="row g-3 mb-1">
            <div class="col-6 col-md-3">
                <div class="stat-card">
                    <div class="stat-label">Sudah lewat</div>
                    <div class="stat-number text-danger"><?php echo $summaryOverdue; ?></div>
                </div>
            </div>
            <div class="col-6 col-md-3">
                <div class="stat-card">
                    <div class="stat-label">Jatuh tempo ≤ 30 hari</div>
                    <div class="stat-number"><?php echo $summaryDue30; ?></div>
                </div>
            </div>
            <div class="col-6 col-md-3">
                <div class="stat-card">
                    <div class="stat-label">Jatuh tempo ≤ 60 hari</div>
                    <div class="stat-number"><?php echo $summaryDue60; ?></div>
                </div>
            </div>
            <div class="col-6 col-md-3">
                <div class="stat-card">
                    <div class="stat-label">Jatuh tempo ≤ 90 hari</div>
                    <div class="stat-number"><?php echo $summaryDue90; ?></div>
                </div>
            </div>
        </div>
        <p class="text-muted mb-3">
            Angka ringkasan mengikuti filter tim / competency / pencarian, tapi tidak terpengaruh pilihan
            rentang di bawah. Nilai "≤ 60 hari" dan "≤ 90 hari" bersifat kumulatif (termasuk yang lebih dekat).
        </p>

        <!-- FILTER -->
        <div class="employee-search">
            <form method="GET" class="row g-2">
                <div class="col-md-3">
                    <select name="window" class="form-control">
                        <?php foreach ($windowLabels as $windowValue => $windowLabel): ?>
                            <option value="<?php echo $windowValue; ?>" <?php
                                echo $window === $windowValue ? 'selected' : '';
                                ?>>
                                <?php echo htmlspecialchars($windowLabel); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-3">
                    <select name="competency_id" class="form-control">
                        <option value="">Semua competency</option>
                        <?php while ($competencyOption = mysqli_fetch_assoc($competencyOptions)): ?>
                            <option value="<?php echo (int) $competencyOption['id']; ?>" <?php
                                echo $competencyId === (int) $competencyOption['id'] ? 'selected' : '';
                                ?>>
                                <?php echo htmlspecialchars($competencyOption['name']); ?>
                            </option>
                        <?php endwhile; ?>
                    </select>
                </div>
                <div class="col-md-2">
                    <select name="team" class="form-control">
                        <option value="">Semua tim</option>
                        <?php foreach ($allowedTeams as $teamOption): ?>
                            <option value="<?php echo $teamOption; ?>" <?php
                                echo $team === $teamOption ? 'selected' : '';
                                ?>>
                                Team <?php echo $teamOption; ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-2">
                    <input type="text" name="search" class="form-control" placeholder="NIK / nama"
                        value="<?php echo htmlspecialchars($search); ?>">
                </div>
                <div class="col-md-2">
                    <button type="submit" class="btn btn-primary w-100">
                        Terapkan
                    </button>
                </div>
            </form>
        </div>

        <!-- TABEL -->
        <div class="employee-table-card">
            <div class="p-3 border-bottom text-muted">
                Menampilkan: <strong><?php echo htmlspecialchars($windowLabels[$window]); ?></strong>
            </div>
            <div class="table-responsive">
                <table class="table table-hover align-middle mb-0">
                    <thead>
                        <tr>
                            <th>NIK</th>
                            <th>Employee</th>
                            <th>Team</th>
                            <th>Department</th>
                            <th>Competency</th>
                            <th>Training Date</th>
                            <th>Expiry Date</th>
                            <th>Sisa</th>
                            <th>Status</th>
                            <th>Action</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (mysqli_num_rows($listResult) > 0): ?>
                            <?php while ($row = mysqli_fetch_assoc($listResult)): ?>
                                <?php
                                $status = calculateCompetencyStatus(
                                    $row['training_date'],
                                    $row['expiry_date']
                                );
                                $status = applyPassingScoreGate(
                                    $status,
                                    $row['score'] !== null ? (int) $row['score'] : null,
                                    $row['passing_score'] !== null ? (int) $row['passing_score'] : null
                                );
                                $days = (int) $row['days_remaining'];
                                $backParam = urlencode('recertification.php' . ($queryString !== '' ? '?' . $queryString : ''));
                                ?>
                                <tr>
                                    <td><?php echo htmlspecialchars($row['nik']); ?></td>
                                    <td>
                                        <strong><?php echo htmlspecialchars($row['employee_name']); ?></strong>
                                    </td>
                                    <td>
                                        <?php echo $row['team'] !== null && $row['team'] !== ''
                                            ? htmlspecialchars($row['team'])
                                            : '-'; ?>
                                    </td>
                                    <td><?php echo htmlspecialchars($row['department'] ?? '-'); ?></td>
                                    <td><?php echo htmlspecialchars($row['competency_name']); ?></td>
                                    <td>
                                        <?php echo empty($row['training_date'])
                                            ? '-'
                                            : date('d M Y', strtotime($row['training_date'])); ?>
                                    </td>
                                    <td>
                                        <?php echo date('d M Y', strtotime($row['expiry_date'])); ?>
                                    </td>
                                    <td>
                                        <?php if ($days < 0): ?>
                                            <span class="badge text-bg-danger">
                                                Lewat <?php echo abs($days); ?> hari
                                            </span>
                                        <?php elseif ($days <= 30): ?>
                                            <span class="badge text-bg-warning">
                                                <?php echo $days; ?> hari lagi
                                            </span>
                                        <?php else: ?>
                                            <span class="badge text-bg-secondary">
                                                <?php echo $days; ?> hari lagi
                                            </span>
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
                                        <div class="employee-actions">
                                            <a href="employee_competency_edit.php?id=<?php echo (int) $row['id']; ?>&back=<?php echo $backParam; ?>"
                                                class="btn btn-sm btn-outline-primary">
                                                Detail
                                            </a>
                                            <a href="competency_assign.php?id=<?php echo (int) $row['competency_id']; ?><?php
                                                echo $team !== '' ? '&team=' . urlencode($team) : ''; ?>"
                                                class="btn btn-sm btn-outline-success">
                                                Jadwalkan ulang
                                            </a>
                                        </div>
                                    </td>
                                </tr>
                            <?php endwhile; ?>
                        <?php else: ?>
                            <tr>
                                <td colspan="10" class="text-center py-5">
                                    Tidak ada competency yang cocok dengan filter ini.
                                </td>
                            </tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</body>

</html>
