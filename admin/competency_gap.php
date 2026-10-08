<?php
require_once "auth.php";
require_once "../config/database.php";
require_once "../includes/competency_helper.php";
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
$position = trim($_GET['position'] ?? '');
$search = trim($_GET['search'] ?? '');
$onlyGaps = ($_GET['only_gaps'] ?? '1') !== '0';
/*
|--------------------------------------------------------------------------
| Dropdown (department / supervisor / position)
|--------------------------------------------------------------------------
*/
function distinctColumn(mysqli $conn, string $column): array
{
    $values = [];
    $result = mysqli_query(
        $conn,
        "SELECT DISTINCT {$column} AS v FROM employees
         WHERE is_deleted = 0 AND {$column} IS NOT NULL AND {$column} <> ''
         ORDER BY {$column} ASC"
    );
    while ($row = mysqli_fetch_assoc($result)) {
        $values[] = $row['v'];
    }
    return $values;
}
$departments = distinctColumn($conn, 'department');
$supervisors = distinctColumn($conn, 'supervisor');
$positions = distinctColumn($conn, 'position');
/*
|--------------------------------------------------------------------------
| Daftar required competency per posisi
|--------------------------------------------------------------------------
*/
$requirementsMissing = false;
$requirementsByPosition = [];
try {
    $reqStmt = mysqli_prepare(
        $conn,
        "SELECT position, competency_id FROM position_requirements"
    );
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
/*
|--------------------------------------------------------------------------
| Metadata competency
|--------------------------------------------------------------------------
*/
$competencyMeta = [];
$metaResult = mysqli_query($conn, "SELECT id, code, name, passing_score FROM competencies");
while ($row = mysqli_fetch_assoc($metaResult)) {
    $competencyMeta[(int) $row['id']] = $row;
}
/*
|--------------------------------------------------------------------------
| Employee (difilter)
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
if ($position !== '') {
    $conditions[] = "position = ?";
    $params[] = $position;
    $types .= "s";
}
if ($search !== '') {
    $conditions[] = "(nik LIKE ? OR name LIKE ?)";
    $keyword = "%" . $search . "%";
    $params[] = $keyword;
    $params[] = $keyword;
    $types .= "ss";
}
$employeeQuery = "SELECT id, nik, name, team, department, position, supervisor FROM employees";
if (count($conditions) > 0) {
    $employeeQuery .= " WHERE " . implode(" AND ", $conditions);
}
$employeeQuery .= " ORDER BY name ASC";
$employeeStmt = mysqli_prepare($conn, $employeeQuery);
if (count($params) > 0) {
    mysqli_stmt_bind_param($employeeStmt, $types, ...$params);
}
mysqli_stmt_execute($employeeStmt);
$employeeResult = mysqli_stmt_get_result($employeeStmt);
$employees = [];
while ($row = mysqli_fetch_assoc($employeeResult)) {
    $employees[] = $row;
}
/*
|--------------------------------------------------------------------------
| Peta status competency [employee_id][competency_id] => status
|--------------------------------------------------------------------------
*/
$statusMap = [];
$assignmentResult = mysqli_query(
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
/*
|--------------------------------------------------------------------------
| Bangun baris laporan
|--------------------------------------------------------------------------
|
| Untuk tiap karyawan yang posisinya punya daftar required competency,
| bandingkan tiap competency wajib dengan status terkini. "Compliant" =
| VALID atau EXPIRING_SOON; selain itu dianggap gap.
|
*/
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

$filterParams = array_filter([
    'team' => $team,
    'department' => $department,
    'supervisor' => $supervisor,
    'position' => $position,
    'search' => $search,
    'only_gaps' => $onlyGaps ? null : '0',
], function ($value) {
    return $value !== null && $value !== '';
});
$queryString = http_build_query($filterParams, '', '&', PHP_QUERY_RFC3986);

function gapStatusBadge(string $status): array
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
    <title>
        Competency Gap - Bekaert Competency
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
                    Competency Gap
                </h1>
                <p>
                    Karyawan vs competency wajib untuk posisinya
                </p>
            </div>
            <div class="d-flex gap-2">
                <a href="dashboard.php" class="btn btn-outline-secondary">
                    &larr; Dashboard
                </a>
                <a href="position_requirements.php" class="btn btn-outline-secondary">
                    Atur Required Competency
                </a>
                <a href="competency_gap_export.php<?php echo $queryString !== '' ? '?' . htmlspecialchars($queryString) : ''; ?>"
                    class="btn btn-outline-secondary">
                    Export CSV
                </a>
            </div>
        </div>

        <?php if ($requirementsMissing): ?>
            <div class="alert alert-warning">
                Tabel <code>position_requirements</code> belum tersedia. Jalankan migrasi
                <code>database/migrations/2026_08_27_add_position_requirements.sql</code> terlebih dahulu.
            </div>
        <?php elseif (count($requirementsByPosition) === 0): ?>
            <div class="alert alert-info">
                Belum ada required competency yang diatur untuk posisi mana pun.
                <a href="position_requirements.php">Atur sekarang</a>.
            </div>
        <?php endif; ?>

        <!-- RINGKASAN -->
        <div class="row g-3 mb-1">
            <div class="col-6 col-md-3">
                <div class="stat-card">
                    <div class="stat-label">Karyawan dievaluasi</div>
                    <div class="stat-number"><?php echo $employeesEvaluated; ?></div>
                </div>
            </div>
            <div class="col-6 col-md-3">
                <div class="stat-card">
                    <div class="stat-label">Lengkap (tanpa gap)</div>
                    <div class="stat-number text-success"><?php echo $employeesFullyCompliant; ?></div>
                </div>
            </div>
            <div class="col-6 col-md-3">
                <div class="stat-card">
                    <div class="stat-label">Punya gap</div>
                    <div class="stat-number text-danger"><?php echo $employeesWithGap; ?></div>
                </div>
            </div>
            <div class="col-6 col-md-3">
                <div class="stat-card">
                    <div class="stat-label">Total item gap</div>
                    <div class="stat-number"><?php echo $totalGapItems; ?></div>
                </div>
            </div>
        </div>
        <p class="text-muted mb-3">
            <?php echo $employeesNoRequirement; ?> karyawan (dalam filter ini) posisinya belum punya daftar
            required competency &mdash; tidak ikut dihitung. Compliant = status Valid atau Expiring Soon.
        </p>

        <!-- FILTER -->
        <div class="employee-search">
            <form method="GET" class="row g-2">
                <div class="col-md-2">
                    <select name="team" class="form-control">
                        <option value="">Semua tim</option>
                        <?php foreach ($allowedTeams as $teamOption): ?>
                            <option value="<?php echo $teamOption; ?>" <?php
                                echo $team === $teamOption ? 'selected' : '';
                                ?>>Team <?php echo $teamOption; ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-3">
                    <select name="position" class="form-control">
                        <option value="">Semua posisi</option>
                        <?php foreach ($positions as $positionOption): ?>
                            <option value="<?php echo htmlspecialchars($positionOption); ?>" <?php
                                echo $position === $positionOption ? 'selected' : '';
                                ?>><?php echo htmlspecialchars($positionOption); ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-2">
                    <select name="department" class="form-control">
                        <option value="">Semua department</option>
                        <?php foreach ($departments as $departmentOption): ?>
                            <option value="<?php echo htmlspecialchars($departmentOption); ?>" <?php
                                echo $department === $departmentOption ? 'selected' : '';
                                ?>><?php echo htmlspecialchars($departmentOption); ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-2">
                    <select name="supervisor" class="form-control">
                        <option value="">Semua supervisor</option>
                        <?php foreach ($supervisors as $supervisorOption): ?>
                            <option value="<?php echo htmlspecialchars($supervisorOption); ?>" <?php
                                echo $supervisor === $supervisorOption ? 'selected' : '';
                                ?>><?php echo htmlspecialchars($supervisorOption); ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-2">
                    <input type="text" name="search" class="form-control" placeholder="NIK / nama"
                        value="<?php echo htmlspecialchars($search); ?>">
                </div>
                <div class="col-md-1">
                    <button type="submit" class="btn btn-primary w-100">Ok</button>
                </div>
                <div class="col-12">
                    <div class="form-check">
                        <input type="hidden" name="only_gaps" value="0">
                        <input class="form-check-input" type="checkbox" name="only_gaps" value="1"
                            id="onlyGaps" <?php echo $onlyGaps ? 'checked' : ''; ?>>
                        <label class="form-check-label" for="onlyGaps">
                            Hanya tampilkan baris yang gap (hilangkan centang untuk melihat semua competency wajib)
                        </label>
                    </div>
                </div>
            </form>
        </div>

        <!-- TABEL -->
        <div class="employee-table-card">
            <div class="table-responsive">
                <table class="table table-hover align-middle mb-0">
                    <thead>
                        <tr>
                            <th>NIK</th>
                            <th>Employee</th>
                            <th>Team</th>
                            <th>Position</th>
                            <th>Required Competency</th>
                            <th>Status Sekarang</th>
                            <th>Action</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (count($rows) > 0): ?>
                            <?php foreach ($rows as $row): ?>
                                <?php
                                [$label, $badgeClass] = gapStatusBadge($row['status']);
                                $employee = $row['employee'];
                                ?>
                                <tr>
                                    <td><?php echo htmlspecialchars($employee['nik']); ?></td>
                                    <td><strong><?php echo htmlspecialchars($employee['name']); ?></strong></td>
                                    <td>
                                        <?php echo $employee['team'] !== null && $employee['team'] !== ''
                                            ? htmlspecialchars($employee['team'])
                                            : '-'; ?>
                                    </td>
                                    <td><?php echo htmlspecialchars($employee['position']); ?></td>
                                    <td>
                                        <?php echo htmlspecialchars($row['competency_name']); ?>
                                        <?php if ($row['competency_code'] !== null && $row['competency_code'] !== ''): ?>
                                            <span class="text-muted">(<?php echo htmlspecialchars($row['competency_code']); ?>)</span>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <span class="badge <?php echo $badgeClass; ?>">
                                            <?php echo htmlspecialchars($label); ?>
                                        </span>
                                        <?php if ($row['is_gap']): ?>
                                            <span class="badge text-bg-dark">GAP</span>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <a href="employee_competencies.php?id=<?php echo (int) $employee['id']; ?>"
                                            class="btn btn-sm btn-outline-primary">
                                            Kelola
                                        </a>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        <?php else: ?>
                            <tr>
                                <td colspan="7" class="text-center py-5">
                                    <?php echo $onlyGaps
                                        ? 'Tidak ada gap untuk filter ini. 🎉'
                                        : 'Tidak ada data untuk filter ini.'; ?>
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
