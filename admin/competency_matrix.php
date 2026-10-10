<?php
require_once "auth.php";
require_once "../config/database.php";
require_once "../includes/competency_helper.php";
require_once "pagination.php";
/*
|--------------------------------------------------------------------------
| Filter
|--------------------------------------------------------------------------
*/
$allowedTeams = ['A', 'B', 'C', 'D', 'NS'];
$team = strtoupper(trim($_GET['team'] ?? ''));
if (!in_array($team, $allowedTeams, true)) {
    $team = '';
}
$department = trim($_GET['department'] ?? '');
$supervisor = trim($_GET['supervisor'] ?? '');
$search = trim($_GET['search'] ?? '');
/*
|--------------------------------------------------------------------------
| Kolom matrix: semua competency
|--------------------------------------------------------------------------
*/
$competencyResult = mysqli_query(
    $conn,
    "SELECT id, code, name FROM competencies ORDER BY name ASC"
);
$competencies = [];
while ($row = mysqli_fetch_assoc($competencyResult)) {
    $competencies[] = $row;
}
/*
|--------------------------------------------------------------------------
| Dropdown department
|--------------------------------------------------------------------------
*/
$departmentResult = mysqli_query(
    $conn,
    "SELECT DISTINCT department FROM employees
     WHERE is_deleted = 0 AND department IS NOT NULL AND department <> ''
     ORDER BY department ASC"
);
$departments = [];
while ($row = mysqli_fetch_assoc($departmentResult)) {
    $departments[] = $row['department'];
}
/*
|--------------------------------------------------------------------------
| Dropdown supervisor
|--------------------------------------------------------------------------
*/
$supervisorResult = mysqli_query(
    $conn,
    "SELECT DISTINCT supervisor FROM employees
     WHERE is_deleted = 0 AND supervisor IS NOT NULL AND supervisor <> ''
     ORDER BY supervisor ASC"
);
$supervisors = [];
while ($row = mysqli_fetch_assoc($supervisorResult)) {
    $supervisors[] = $row['supervisor'];
}
/*
|--------------------------------------------------------------------------
| Baris matrix: employee (difilter)
|--------------------------------------------------------------------------
*/
$conditions = ["is_deleted = 0"];
$params = [];
$types = "";
if ($team !== '') {
    $conditions[] = "team = ?";
    $params[] = $team;
    $types .= "s";
}
if ($department !== '') {
    $conditions[] = "department = ?";
    $params[] = $department;
    $types .= "s";
}
if ($supervisor !== '') {
    $conditions[] = "supervisor = ?";
    $params[] = $supervisor;
    $types .= "s";
}
if ($search !== '') {
    $conditions[] = "(nik LIKE ? OR name LIKE ?)";
    $keyword = "%" . $search . "%";
    $params[] = $keyword;
    $params[] = $keyword;
    $types .= "ss";
}
$whereClause = count($conditions) > 0
    ? " WHERE " . implode(" AND ", $conditions)
    : "";
/*
|--------------------------------------------------------------------------
| Hitung total + pagination (hanya baris employee, bukan kolom competency)
|--------------------------------------------------------------------------
*/
$countStmt = mysqli_prepare($conn, "SELECT COUNT(*) AS total FROM employees" . $whereClause);
if (count($params) > 0) {
    mysqli_stmt_bind_param($countStmt, $types, ...$params);
}
mysqli_stmt_execute($countStmt);
$totalRows = (int) mysqli_fetch_assoc(mysqli_stmt_get_result($countStmt))['total'];
$pg = paginate($totalRows, 25);
$employeeQuery = "SELECT id, nik, name, team, department, position FROM employees" . $whereClause . " ORDER BY name ASC LIMIT ? OFFSET ?";
$employeeParams = $params;
$employeeTypes = $types . "ii";
$employeeParams[] = $pg['per_page'];
$employeeParams[] = $pg['offset'];
$employeeStmt = mysqli_prepare($conn, $employeeQuery);
mysqli_stmt_bind_param($employeeStmt, $employeeTypes, ...$employeeParams);
mysqli_stmt_execute($employeeStmt);
$employeeResult = mysqli_stmt_get_result($employeeStmt);
$employees = [];
while ($row = mysqli_fetch_assoc($employeeResult)) {
    $employees[] = $row;
}
/*
|--------------------------------------------------------------------------
| Peta status: [employee_id][competency_id] => status (dihitung ulang)
| Hanya untuk employee pada halaman ini
|--------------------------------------------------------------------------
*/
$statusMap = [];
$employeeIds = array_map(static fn($employee) => (int) $employee['id'], $employees);
if (count($employeeIds) > 0) {
    $idPlaceholders = implode(",", array_fill(0, count($employeeIds), "?"));
    $assignmentStmt = mysqli_prepare(
        $conn,
        "SELECT
            ec.employee_id,
            ec.competency_id,
            ec.training_date,
            ec.scheduled_training_date,
            ec.expiry_date,
            ec.score,
            ec.quiz_submitted_at,
            c.passing_score
        FROM employee_competencies ec
        INNER JOIN competencies c ON c.id = ec.competency_id
        WHERE ec.is_active = 1 AND ec.employee_id IN ($idPlaceholders)"
    );
    mysqli_stmt_bind_param($assignmentStmt, str_repeat("i", count($employeeIds)), ...$employeeIds);
    mysqli_stmt_execute($assignmentStmt);
    $assignmentResult = mysqli_stmt_get_result($assignmentStmt);
} else {
    $assignmentResult = false;
}
if ($assignmentResult !== false) {
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
}
/*
|--------------------------------------------------------------------------
| Tampilan sel: [singkatan, warna latar, warna teks]
|--------------------------------------------------------------------------
*/
function matrixCell(string $status): array
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
/*
|--------------------------------------------------------------------------
| Coverage per competency (jumlah karyawan yang masih tersertifikasi)
|--------------------------------------------------------------------------
*/
$coverage = [];
foreach ($competencies as $competency) {
    $competencyId = (int) $competency['id'];
    $certified = 0;
    foreach ($employees as $employee) {
        $cellStatus = $statusMap[(int) $employee['id']][$competencyId] ?? '';
        if ($cellStatus === 'VALID' || $cellStatus === 'EXPIRING_SOON') {
            $certified++;
        }
    }
    $coverage[$competencyId] = $certified;
}
$totalEmployees = count($employees);

$filterParams = array_filter([
    'team' => $team,
    'department' => $department,
    'supervisor' => $supervisor,
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
        Competency Matrix - Bekaert Competency
    </title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet">
    <link rel="stylesheet" href="../assets/css/style.css?v=<?php echo filemtime(__DIR__ . '/../assets/css/style.css'); ?>">
    <style>
        .matrix-wrap {
            overflow: auto;
            max-height: 75vh;
            border: 1px solid #e4e7eb;
            border-radius: 8px;
        }

        .matrix-table {
            border-collapse: separate;
            border-spacing: 0;
            margin-bottom: 0;
        }

        .matrix-table th,
        .matrix-table td {
            border: 1px solid #e4e7eb;
            white-space: nowrap;
        }

        .matrix-table thead th {
            position: sticky;
            top: 0;
            background: #f8f9fa;
            z-index: 2;
        }

        .matrix-table .col-emp {
            position: sticky;
            left: 0;
            background: #fff;
            z-index: 3;
            text-align: left;
            padding: 8px 12px;
            min-width: 220px;
        }

        .matrix-table thead .col-emp,
        .matrix-table tfoot .col-emp {
            z-index: 4;
            background: #f8f9fa;
        }

        .matrix-table tfoot td {
            position: sticky;
            bottom: 0;
            background: #f8f9fa;
            font-size: 11px;
            z-index: 2;
        }

        .matrix-colhead {
            writing-mode: vertical-rl;
            transform: rotate(180deg);
            font-size: 11px;
            font-weight: 600;
            height: 140px;
            vertical-align: bottom;
            padding: 6px 3px;
            text-align: left;
        }

        .matrix-cell {
            text-align: center;
            font-size: 11px;
            font-weight: 700;
            width: 46px;
            min-width: 46px;
        }

        .matrix-legend {
            display: flex;
            flex-wrap: wrap;
            gap: 14px;
            font-size: 12px;
            margin-bottom: 14px;
        }

        .matrix-legend span {
            display: inline-flex;
            align-items: center;
            gap: 6px;
        }

        .matrix-legend i {
            width: 18px;
            height: 18px;
            border-radius: 4px;
            border: 1px solid rgba(0, 0, 0, .1);
            font-style: normal;
            font-size: 10px;
            font-weight: 700;
            display: inline-flex;
            align-items: center;
            justify-content: center;
        }
    </style>
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
                <i class="bi bi-box-arrow-right"></i> Logout
            </a>
        </div>
    </nav>
<div class="admin-layout">
    <?php include "../includes/admin_sidebar.php"; ?>
    <main class="admin-content">
    <div class="admin-container">
        <div class="page-header">
            <div>
                <h1>
                    Competency Matrix
                </h1>
                <p>
                    Status competency seluruh karyawan dalam satu grid &mdash;
                    <?php echo $totalRows; ?> karyawan &times; <?php echo count($competencies); ?> competency
                </p>
            </div>
            <div class="d-flex gap-2">
                <a href="competency_matrix_export.php<?php echo $queryString !== '' ? '?' . htmlspecialchars($queryString) : ''; ?>"
                    class="btn btn-outline-secondary">
                    Export CSV
                </a>
            </div>
        </div>

        <div class="matrix-legend">
            <span><i style="background:#d1e7dd;color:#0f5132">V</i> Valid</span>
            <span><i style="background:#fff3cd;color:#664d03">ES</i> Expiring Soon</span>
            <span><i style="background:#f8d7da;color:#842029">EX</i> Expired</span>
            <span><i style="background:#f5c2c7;color:#842029">F</i> Failed</span>
            <span><i style="background:#cfe2ff;color:#084298">A</i> Assigned</span>
            <span><i style="background:#e9ecef;color:#6c757d">·</i> Not Taken</span>
            <span><i style="background:#fff">&nbsp;</i> Belum ada data</span>
        </div>

        <!-- FILTER -->
        <div class="employee-search">
            <form method="GET" class="row g-2">
                <div class="col-md-3">
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
                <div class="col-md-3">
                    <select name="department" class="form-control">
                        <option value="">Semua department</option>
                        <?php foreach ($departments as $departmentOption): ?>
                            <option value="<?php echo htmlspecialchars($departmentOption); ?>" <?php
                                echo $department === $departmentOption ? 'selected' : '';
                                ?>>
                                <?php echo htmlspecialchars($departmentOption); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-3">
                    <select name="supervisor" class="form-control">
                        <option value="">Semua supervisor</option>
                        <?php foreach ($supervisors as $supervisorOption): ?>
                            <option value="<?php echo htmlspecialchars($supervisorOption); ?>" <?php
                                echo $supervisor === $supervisorOption ? 'selected' : '';
                                ?>>
                                <?php echo htmlspecialchars($supervisorOption); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-3">
                    <input type="text" name="search" class="form-control" placeholder="NIK / nama karyawan"
                        value="<?php echo htmlspecialchars($search); ?>">
                </div>
                <div class="col-md-3">
                    <button type="submit" class="btn btn-primary w-100">
                        Terapkan
                    </button>
                </div>
            </form>
        </div>

        <?php if ($totalRows === 0 || count($competencies) === 0): ?>
            <div class="alert alert-info">
                <?php
                echo count($competencies) === 0
                    ? 'Belum ada competency yang terdaftar.'
                    : 'Tidak ada karyawan yang cocok dengan filter ini.';
                ?>
            </div>
        <?php else: ?>
            <p class="text-muted" style="font-size:12px;">
                Arahkan kursor ke header kolom atau ke sel untuk melihat nama lengkap &amp; keterangan status.
            </p>
            <div class="matrix-wrap">
                <table class="table table-sm matrix-table">
                    <thead>
                        <tr>
                            <th class="col-emp">Employee</th>
                            <?php foreach ($competencies as $competency): ?>
                                <th class="matrix-colhead" title="<?php echo htmlspecialchars($competency['name']); ?>">
                                    <?php
                                    echo htmlspecialchars(
                                        $competency['code'] !== null && $competency['code'] !== ''
                                            ? $competency['code']
                                            : $competency['name']
                                    );
                                    ?>
                                </th>
                            <?php endforeach; ?>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($employees as $employee): ?>
                            <tr>
                                <td class="col-emp">
                                    <strong><?php echo htmlspecialchars($employee['name']); ?></strong><br>
                                    <small class="text-muted">
                                        <?php echo htmlspecialchars($employee['nik']); ?>
                                        &middot;
                                        <?php echo htmlspecialchars(
                                            $employee['team'] !== null && $employee['team'] !== ''
                                                ? 'Team ' . $employee['team']
                                                : '-'
                                        ); ?>
                                    </small>
                                </td>
                                <?php foreach ($competencies as $competency):
                                    $cellStatus = $statusMap[(int) $employee['id']][(int) $competency['id']] ?? '';
                                    [$abbr, $bg, $fg] = matrixCell($cellStatus);
                                    ?>
                                    <td class="matrix-cell"
                                        style="<?php echo $bg !== '' ? 'background:' . $bg . ';color:' . $fg : ''; ?>"
                                        title="<?php echo htmlspecialchars(
                                            $employee['name'] . ' — ' . $competency['name'] . ': '
                                            . ($cellStatus !== '' ? competencyStatusLabel($cellStatus) : 'Belum ada data')
                                        ); ?>">
                                        <?php echo htmlspecialchars($abbr); ?>
                                    </td>
                                <?php endforeach; ?>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                    <tfoot>
                        <tr>
                            <td class="col-emp">
                                <strong>Coverage</strong>
                                <small class="text-muted d-block">valid / total</small>
                            </td>
                            <?php foreach ($competencies as $competency):
                                $certified = $coverage[(int) $competency['id']];
                                ?>
                                <td class="matrix-cell"
                                    title="<?php echo $certified; ?> dari <?php echo $totalEmployees; ?> karyawan tersertifikasi (valid / expiring soon)">
                                    <?php echo $certified; ?>/<?php echo $totalEmployees; ?>
                                </td>
                            <?php endforeach; ?>
                        </tr>
                    </tfoot>
                </table>
            </div>
            <?php if ($totalRows > 0): ?>
                <div class="d-flex flex-wrap justify-content-between align-items-center gap-2 p-3 border-top">
                    <span class="text-muted" style="font-size:13px;">
                        Menampilkan
                        <?php echo $pg['offset'] + 1; ?>&ndash;<?php echo min($pg['offset'] + $pg['per_page'], $totalRows); ?>
                        dari <?php echo $totalRows; ?> karyawan
                    </span>
                    <?php echo render_pagination($pg, $filterParams); ?>
                </div>
            <?php endif; ?>
        <?php endif; ?>
    </div>
</main>
</div>
</body>

</html>
