<?php
require_once "auth.php";
require_once "../config/database.php";
$id = isset($_GET['id'])
    ? (int) $_GET['id']
    : 0;
if ($id <= 0) {
    header("Location: competencies.php");
    exit;
}
/*
|--------------------------------------------------------------------------
| Ambil competency
|--------------------------------------------------------------------------
*/
$query = "SELECT id, name, description FROM competencies WHERE id = ? LIMIT 1";
$stmt = mysqli_prepare($conn, $query);
mysqli_stmt_bind_param($stmt, "i", $id);
mysqli_stmt_execute($stmt);
$result = mysqli_stmt_get_result($stmt);
$competency = mysqli_fetch_assoc($result);
if (!$competency) {
    die("Competency tidak ditemukan.");
}
/*
|--------------------------------------------------------------------------
| Search & filter Team
|--------------------------------------------------------------------------
*/
$search = trim($_GET['search'] ?? '');
$teamFilter = trim($_GET['team'] ?? '');
$allowedTeams = ['A', 'B', 'C', 'D', 'NS'];
if (!in_array($teamFilter, $allowedTeams, true)) {
    $teamFilter = '';
}
$conditions = [];
$params = [];
$types = "";
if ($search !== '') {
    $conditions[] = "(nik LIKE ? OR name LIKE ? OR department LIKE ? OR position LIKE ? OR supervisor LIKE ?)";
    $keyword = "%" . $search . "%";
    array_push($params, $keyword, $keyword, $keyword, $keyword, $keyword);
    $types .= "sssss";
}
if ($teamFilter !== '') {
    $conditions[] = "team = ?";
    $params[] = $teamFilter;
    $types .= "s";
}
$employeeQuery = "SELECT id, nik, name, department, position, supervisor, team FROM employees";
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
/*
|--------------------------------------------------------------------------
| Ambil employee yang sudah aktif untuk competency ini
|--------------------------------------------------------------------------
*/
$assignedStmt = mysqli_prepare(
    $conn,
    "SELECT employee_id FROM employee_competencies WHERE competency_id = ? AND is_active = 1"
);
mysqli_stmt_bind_param($assignedStmt, "i", $id);
mysqli_stmt_execute($assignedStmt);
$assignedResult = mysqli_stmt_get_result($assignedStmt);
$assignedEmployeeIds = [];
while ($row = mysqli_fetch_assoc($assignedResult)) {
    $assignedEmployeeIds[(int) $row['employee_id']] = true;
}
$success = isset($_GET['success']) && $_GET['success'] === '1';
?>

<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>
        Assign Training - Bekaert Competency
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
                    Assign Training
                </h1>
                <p>
                    Pilih karyawan yang akan diberi training untuk kompetensi ini
                </p>
            </div>
            <a href="competencies.php" class="btn btn-outline-secondary">
                &larr; Back
            </a>
        </div>
        <?php if ($success): ?>
            <div class="alert alert-success">
                Assign training berhasil disimpan.
            </div>
        <?php endif; ?>
        <!-- COMPETENCY INFO -->
        <div class="form-card mb-4">
            <h4 class="mb-1">
                <?php echo htmlspecialchars($competency['name']); ?>
            </h4>
            <p class="text-muted mb-0">
                <?php echo htmlspecialchars($competency['description'] ?? '-'); ?>
            </p>
        </div>
        <!-- SEARCH -->
        <div class="employee-search">
            <form method="GET" class="row g-2">
                <input type="hidden" name="id" value="<?php echo $id; ?>">
                <div class="col-md-6">
                    <input type="text" name="search" class="form-control"
                        placeholder="Search NIK, name, department, position, supervisor..."
                        value="<?php echo htmlspecialchars($search); ?>">
                </div>
                <div class="col-md-4">
                    <select name="team" class="form-control">
                        <option value="">
                            All Teams
                        </option>
                        <?php foreach ($allowedTeams as $teamOption): ?>
                            <option value="<?php echo $teamOption; ?>" <?php
                                echo $teamFilter === $teamOption ? 'selected' : '';
                                ?>>
                                Team <?php echo $teamOption; ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-2">
                    <button type="submit" class="btn btn-primary w-100">
                        Search
                    </button>
                </div>
            </form>
        </div>
        <!-- ASSIGN FORM -->
        <div class="employee-table-card">
            <form method="POST" action="competency_assign_save.php" id="assignForm">
                <input type="hidden" name="competency_id" value="<?php echo $id; ?>">
                <?php if ($search !== ''): ?>
                    <input type="hidden" name="redirect_search" value="<?php echo htmlspecialchars($search); ?>">
                <?php endif; ?>
                <?php if ($teamFilter !== ''): ?>
                    <input type="hidden" name="redirect_team" value="<?php echo htmlspecialchars($teamFilter); ?>">
                <?php endif; ?>
                <div class="p-4 border-bottom d-flex justify-content-between align-items-center">
                    <div>
                        <h4 class="mb-1">
                            Employee List
                        </h4>
                        <p class="text-muted mb-0">
                            Centang karyawan yang akan di-assign, hilangkan centang untuk menonaktifkan (riwayat tetap aman).
                        </p>
                    </div>
                    <div class="d-flex gap-2">
                        <button type="button" class="btn btn-sm btn-outline-secondary" id="selectAllBtn">
                            Select All
                        </button>
                        <button type="button" class="btn btn-sm btn-outline-secondary" id="clearAllBtn">
                            Clear All
                        </button>
                    </div>
                </div>
                <div class="table-responsive">
                    <table class="table table-hover align-middle mb-0">
                        <thead>
                            <tr>
                                <th width="60">
                                    Select
                                </th>
                                <th>
                                    NIK
                                </th>
                                <th>
                                    Name
                                </th>
                                <th>
                                    Department
                                </th>
                                <th>
                                    Position
                                </th>
                                <th>
                                    Supervisor
                                </th>
                                <th>
                                    Team
                                </th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php
                            $number = 1;
                            if (mysqli_num_rows($employeeResult) > 0):
                                while ($employee = mysqli_fetch_assoc($employeeResult)):
                                    $isAssigned = isset($assignedEmployeeIds[(int) $employee['id']]);
                                    ?>
                                    <tr>
                                        <td>
                                            <input type="hidden" name="visible_employees[]" value="<?php echo $employee['id']; ?>">
                                            <input type="checkbox" class="form-check-input" name="employees[]"
                                                value="<?php echo $employee['id']; ?>" <?php echo $isAssigned ? 'checked' : ''; ?>>
                                        </td>
                                        <td>
                                            <?php echo htmlspecialchars($employee['nik']); ?>
                                        </td>
                                        <td>
                                            <strong>
                                                <?php echo htmlspecialchars($employee['name']); ?>
                                            </strong>
                                        </td>
                                        <td>
                                            <?php echo htmlspecialchars($employee['department'] ?? '-'); ?>
                                        </td>
                                        <td>
                                            <?php echo htmlspecialchars($employee['position'] ?? '-'); ?>
                                        </td>
                                        <td>
                                            <?php echo htmlspecialchars($employee['supervisor'] ?? '-'); ?>
                                        </td>
                                        <td>
                                            <?php if (!empty($employee['team'])): ?>
                                                <span class="badge text-bg-info">
                                                    <?php echo htmlspecialchars($employee['team']); ?>
                                                </span>
                                            <?php else: ?>
                                                -
                                            <?php endif; ?>
                                        </td>
                                    </tr>
                                    <?php
                                endwhile;
                            else:
                                ?>
                                <tr>
                                    <td colspan="7" class="text-center py-5">
                                        Tidak ada data karyawan.
                                    </td>
                                </tr>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
                <div class="p-4 border-top">
                    <button type="submit" class="btn btn-primary">
                        Save Assignment
                    </button>
                </div>
            </form>
        </div>
    </div>
    <script>
        document.getElementById('selectAllBtn').addEventListener('click', function () {
            document.querySelectorAll('#assignForm input[type=checkbox]').forEach(function (cb) {
                cb.checked = true;
            });
        });
        document.getElementById('clearAllBtn').addEventListener('click', function () {
            document.querySelectorAll('#assignForm input[type=checkbox]').forEach(function (cb) {
                cb.checked = false;
            });
        });
    </script>
</body>

</html>
