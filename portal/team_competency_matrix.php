<?php
require_once "../includes/portal_auth.php";
portal_require_view('team_competency_matrix');
require_once "../includes/competency_helper.php";

$scopeNiks = portal_scope_niks($conn);
$search = trim($_GET['search'] ?? '');

$competencyResult = mysqli_query($conn, "SELECT id, code, name FROM competencies ORDER BY name ASC");
$competencies = [];
while ($row = mysqli_fetch_assoc($competencyResult)) {
    $competencies[] = $row;
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

function portalMatrixCell(string $status): array
{
    return match ($status) {
        'VALID' => ['V', '#d1e7dd', '#0f5132'],
        'EXPIRING_SOON' => ['ES', '#fff3cd', '#664d03'],
        'EXPIRED' => ['EX', '#f8d7da', '#842029'],
        'FAILED' => ['F', '#f5c2c7', '#842029'],
        'ASSIGNED' => ['A', '#cfe2ff', '#084298'],
        'NOT_TAKEN' => ['·', '#e9ecef', '#6c757d'],
        default => ['', '', '#6c757d'],
    };
}

$totalEmployees = count($employees);
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Competency Matrix Tim - Bekaert Competency</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet">
    <link rel="stylesheet" href="../assets/css/style.css?v=<?php echo filemtime(__DIR__ . '/../assets/css/style.css'); ?>">
    <style>
        .matrix-wrap { overflow: auto; max-height: 75vh; border: 1px solid #e4e7eb; border-radius: 8px; }
        .matrix-table { border-collapse: separate; border-spacing: 0; margin-bottom: 0; }
        .matrix-table th, .matrix-table td { border: 1px solid #e4e7eb; white-space: nowrap; }
        .matrix-table thead th { position: sticky; top: 0; background: #f8f9fa; z-index: 2; }
        .matrix-table .col-emp { position: sticky; left: 0; background: #fff; z-index: 3; text-align: left; padding: 8px 12px; min-width: 220px; }
        .matrix-table thead .col-emp { z-index: 4; background: #f8f9fa; }
        .matrix-colhead { writing-mode: vertical-rl; transform: rotate(180deg); font-size: 11px; font-weight: 600; height: 140px; vertical-align: bottom; padding: 6px 3px; text-align: left; }
        .matrix-cell { text-align: center; font-size: 11px; font-weight: 700; width: 46px; min-width: 46px; }
        .matrix-legend { display: flex; flex-wrap: wrap; gap: 14px; font-size: 12px; margin-bottom: 14px; }
        .matrix-legend span { display: inline-flex; align-items: center; gap: 6px; }
        .matrix-legend i { width: 18px; height: 18px; border-radius: 4px; border: 1px solid rgba(0,0,0,.1); font-style: normal; font-size: 10px; font-weight: 700; display: inline-flex; align-items: center; justify-content: center; }
    </style>
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
                        <h1>Competency Matrix</h1>
                        <p><?php echo $totalEmployees; ?> karyawan &times; <?php echo count($competencies); ?> competency</p>
                    </div>
                </div>
                <div class="matrix-legend">
                    <span><i style="background:#d1e7dd;color:#0f5132">V</i> Valid</span>
                    <span><i style="background:#fff3cd;color:#664d03">ES</i> Expiring Soon</span>
                    <span><i style="background:#f8d7da;color:#842029">EX</i> Expired</span>
                    <span><i style="background:#f5c2c7;color:#842029">F</i> Failed</span>
                    <span><i style="background:#cfe2ff;color:#084298">A</i> Assigned</span>
                    <span><i style="background:#e9ecef;color:#6c757d">&middot;</i> Not Taken</span>
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
                    </form>
                </div>
                <?php if ($totalEmployees === 0 || count($competencies) === 0): ?>
                    <div class="alert alert-info">
                        <?php echo count($competencies) === 0 ? 'Belum ada competency yang terdaftar.' : 'Tidak ada karyawan yang cocok.'; ?>
                    </div>
                <?php else: ?>
                    <div class="matrix-wrap">
                        <table class="table table-sm matrix-table">
                            <thead>
                                <tr>
                                    <th class="col-emp">Employee</th>
                                    <?php foreach ($competencies as $competency): ?>
                                        <th class="matrix-colhead" title="<?php echo htmlspecialchars($competency['name']); ?>">
                                            <?php echo htmlspecialchars($competency['code'] ?: $competency['name']); ?>
                                        </th>
                                    <?php endforeach; ?>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($employees as $employee): ?>
                                    <tr>
                                        <td class="col-emp">
                                            <strong><?php echo htmlspecialchars($employee['name']); ?></strong><br>
                                            <small class="text-muted"><?php echo htmlspecialchars($employee['nik']); ?></small>
                                        </td>
                                        <?php foreach ($competencies as $competency):
                                            $cellStatus = $statusMap[(int) $employee['id']][(int) $competency['id']] ?? '';
                                            [$abbr, $bg, $fg] = portalMatrixCell($cellStatus);
                                            ?>
                                            <td class="matrix-cell" style="<?php echo $bg !== '' ? 'background:' . $bg . ';color:' . $fg : ''; ?>"
                                                title="<?php echo htmlspecialchars($employee['name'] . ' - ' . $competency['name'] . ': ' . ($cellStatus !== '' ? competencyStatusLabel($cellStatus) : 'Belum ada data')); ?>">
                                                <?php echo htmlspecialchars($abbr); ?>
                                            </td>
                                        <?php endforeach; ?>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php endif; ?>
            </div>
        </main>
    </div>
</body>

</html>
