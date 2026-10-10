<?php
require_once "../includes/portal_auth.php";
require_once "../admin/pagination.php";
portal_require_view('team_competency_gap');
require_once "../includes/competency_helper.php";

$scopeNiks = portal_scope_niks($conn);
$search = trim($_GET['search'] ?? '');
$onlyGaps = ($_GET['only_gaps'] ?? '1') !== '0';

$requirementsMissing = false;
$requirementsByPosition = [];
try {
    $reqStmt = mysqli_prepare($conn, "SELECT position, competency_id FROM position_requirements");
    if ($reqStmt === false) {
        $requirementsMissing = true;
    } else {
        mysqli_stmt_execute($reqStmt);
        $reqResult = mysqli_stmt_get_result($reqStmt);
        while ($row = mysqli_fetch_assoc($reqResult)) {
            $requirementsByPosition[$row['position']][] = (int) $row['competency_id'];
        }
    }
} catch (\Throwable $e) {
    $requirementsMissing = true;
}

$competencyMeta = [];
$metaResult = mysqli_query($conn, "SELECT id, code, name, passing_score FROM competencies");
while ($row = mysqli_fetch_assoc($metaResult)) {
    $competencyMeta[(int) $row['id']] = $row;
}

[$scopeClause, $scopeParams] = portal_scope_where($scopeNiks, 'nik');
$conditions = ["is_deleted = 0"];
$params = [];
$types = "";
if ($search !== '') {
    $conditions[] = "(nik LIKE ? OR name LIKE ?)";
    $keyword = "%" . $search . "%";
    $params[] = $keyword;
    $params[] = $keyword;
    $types .= "ss";
}
// scopeClause is appended at the END of the SQL string, so its params must
// come last in the bound params array to match positional placeholder order.
$params = array_merge($params, $scopeParams);
$types .= str_repeat("s", count($scopeParams));
$employeeQuery = "SELECT id, nik, name, team, department, position FROM employees WHERE "
    . implode(" AND ", $conditions) . $scopeClause . " ORDER BY name ASC";
$employeeStmt = mysqli_prepare($conn, $employeeQuery);
if (!empty($params)) {
    mysqli_stmt_bind_param($employeeStmt, $types, ...$params);
}
mysqli_stmt_execute($employeeStmt);
$employeeResult = mysqli_stmt_get_result($employeeStmt);
$employees = [];
while ($row = mysqli_fetch_assoc($employeeResult)) {
    $employees[] = $row;
}

$statusMap = [];
$assignmentResult = mysqli_query(
    $conn,
    "SELECT ec.employee_id, ec.competency_id, ec.training_date, ec.scheduled_training_date,
        ec.expiry_date, ec.score, ec.quiz_submitted_at, c.passing_score
     FROM employee_competencies ec
     INNER JOIN competencies c ON c.id = ec.competency_id
     WHERE ec.is_active = 1"
);
while ($row = mysqli_fetch_assoc($assignmentResult)) {
    $status = calculateCompetencyStatusWithSchedule(
        $row['training_date'],
        $row['expiry_date'],
        $row['scheduled_training_date'],
        $row['quiz_submitted_at']
    );
    $status = applyPassingScoreGate(
        $status,
        $row['score'] !== null ? (int) $row['score'] : null,
        $row['passing_score'] !== null ? (int) $row['passing_score'] : null
    );
    $statusMap[(int) $row['employee_id']][(int) $row['competency_id']] = $status;
}

$rows = [];
$employeesEvaluated = 0;
$employeesFullyCompliant = 0;
$employeesWithGap = 0;
$totalGapItems = 0;
$employeesNoRequirement = 0;

foreach ($employees as $employee) {
    $requiredIds = $requirementsByPosition[$employee['position']] ?? [];
    if (count($requiredIds) === 0) {
        $employeesNoRequirement++;
        continue;
    }
    $employeesEvaluated++;
    $employeeHasGap = false;
    foreach ($requiredIds as $competencyId) {
        $meta = $competencyMeta[$competencyId] ?? null;
        if ($meta === null) {
            continue;
        }
        $status = $statusMap[(int) $employee['id']][$competencyId] ?? '';
        $isCompliant = ($status === 'VALID' || $status === 'EXPIRING_SOON');
        if (!$isCompliant) {
            $employeeHasGap = true;
            $totalGapItems++;
        }
        if ($onlyGaps && $isCompliant) {
            continue;
        }
        $rows[] = [
            'employee' => $employee,
            'competency_code' => $meta['code'],
            'competency_name' => $meta['name'],
            'status' => $status,
            'is_gap' => !$isCompliant,
        ];
    }
    if ($employeeHasGap) {
        $employeesWithGap++;
    } else {
        $employeesFullyCompliant++;
    }
}

/*
|--------------------------------------------------------------------------
| Pagination
|--------------------------------------------------------------------------
| $rows is built entirely in PHP from business logic (not a single SQL
| listing query), so the full set is computed first (stats above are based
| on the full scoped employee set) and only the final table rows are
| paginated here, in-memory, with the same paginate()/render_pagination()
| helpers used elsewhere.
*/
$totalRows = count($rows);
$pg = paginate($totalRows, 25);
$pagedRows = array_slice($rows, $pg['offset'], $pg['per_page']);

$paginationBaseParams = array_filter([
    'search' => $search,
    'only_gaps' => $onlyGaps ? '1' : '0',
], function ($value) {
    return $value !== '';
});

function portalGapStatusBadge(string $status): array
{
    return match ($status) {
        'VALID' => ['Valid', 'text-bg-success'],
        'EXPIRING_SOON' => ['Expiring Soon', 'text-bg-warning'],
        'EXPIRED' => ['Expired', 'text-bg-danger'],
        'FAILED' => ['Failed', 'text-bg-danger'],
        'ASSIGNED' => ['Assigned', 'text-bg-info'],
        'NOT_TAKEN' => ['Not Taken', 'text-bg-secondary'],
        default => ['Belum ada data', 'text-bg-secondary'],
    };
}
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Competency Gap Tim - Bekaert Competency</title>
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
                        <h1>Competency Gap</h1>
                        <p>Anggota tim vs competency wajib untuk posisinya</p>
                    </div>
                </div>

                <?php if ($requirementsMissing): ?>
                    <div class="alert alert-warning">Daftar required competency belum tersedia.</div>
                <?php elseif (count($requirementsByPosition) === 0): ?>
                    <div class="alert alert-info">Belum ada required competency yang diatur untuk posisi mana pun.</div>
                <?php endif; ?>

                <div class="row g-3 mb-1">
                    <div class="col-6 col-md-3">
                        <div class="stat-card"><div class="stat-label">Dievaluasi</div><div class="stat-number"><?php echo $employeesEvaluated; ?></div></div>
                    </div>
                    <div class="col-6 col-md-3">
                        <div class="stat-card"><div class="stat-label">Lengkap</div><div class="stat-number text-success"><?php echo $employeesFullyCompliant; ?></div></div>
                    </div>
                    <div class="col-6 col-md-3">
                        <div class="stat-card"><div class="stat-label">Punya gap</div><div class="stat-number text-danger"><?php echo $employeesWithGap; ?></div></div>
                    </div>
                    <div class="col-6 col-md-3">
                        <div class="stat-card"><div class="stat-label">Total item gap</div><div class="stat-number"><?php echo $totalGapItems; ?></div></div>
                    </div>
                </div>

                <div class="employee-search">
                    <form method="GET" class="row g-2">
                        <div class="col-md-9">
                            <input type="text" name="search" class="form-control" placeholder="NIK / nama"
                                value="<?php echo htmlspecialchars($search); ?>">
                        </div>
                        <div class="col-md-3">
                            <button type="submit" class="btn btn-primary w-100">Terapkan</button>
                        </div>
                        <div class="col-12">
                            <div class="form-check">
                                <input type="hidden" name="only_gaps" value="0">
                                <input class="form-check-input" type="checkbox" name="only_gaps" value="1"
                                    id="onlyGaps" <?php echo $onlyGaps ? 'checked' : ''; ?>>
                                <label class="form-check-label" for="onlyGaps">Hanya tampilkan yang gap</label>
                            </div>
                        </div>
                    </form>
                </div>

                <div class="employee-table-card">
                    <div class="table-responsive">
                        <table class="table table-hover align-middle mb-0">
                            <thead>
                                <tr>
                                    <th>NIK</th><th>Employee</th><th>Position</th>
                                    <th>Required Competency</th><th>Status</th><th>Action</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php if (count($pagedRows) > 0): ?>
                                    <?php foreach ($pagedRows as $row): ?>
                                        <?php [$label, $badgeClass] = portalGapStatusBadge($row['status']); $employee = $row['employee']; ?>
                                        <tr>
                                            <td><?php echo htmlspecialchars($employee['nik']); ?></td>
                                            <td><strong><?php echo htmlspecialchars($employee['name']); ?></strong></td>
                                            <td><?php echo htmlspecialchars($employee['position']); ?></td>
                                            <td>
                                                <?php echo htmlspecialchars($row['competency_name']); ?>
                                                <?php if ($row['competency_code']): ?>
                                                    <span class="text-muted">(<?php echo htmlspecialchars($row['competency_code']); ?>)</span>
                                                <?php endif; ?>
                                            </td>
                                            <td>
                                                <span class="badge <?php echo $badgeClass; ?>"><?php echo htmlspecialchars($label); ?></span>
                                                <?php if ($row['is_gap']): ?><span class="badge text-bg-dark">GAP</span><?php endif; ?>
                                            </td>
                                            <td>
                                                <a href="../index.php?nik=<?php echo urlencode($employee['nik']); ?>"
                                                    class="btn btn-sm btn-outline-secondary" target="_blank">Lihat</a>
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
