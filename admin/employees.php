<?php
require_once "auth.php";
require_once "../config/database.php";
/*
|--------------------------------------------------------------------------
| Search
|--------------------------------------------------------------------------
*/
$search = trim($_GET['search'] ?? '');
$teamFilter = trim($_GET['team'] ?? '');
$allowedTeams = ['A', 'B', 'C', 'D', 'NS'];
if (!in_array($teamFilter, $allowedTeams, true)) {
    $teamFilter = '';
}
/*
|--------------------------------------------------------------------------
| Ambil data employees
|--------------------------------------------------------------------------
*/
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
$query = "
    SELECT
        id,
        nik,
        name,
        department,
        position,
        supervisor,
        team,
        license_id,
        photo
    FROM employees
";
if (count($conditions) > 0) {
    $query .= " WHERE " . implode(" AND ", $conditions);
}
$query .= " ORDER BY name ASC";
$stmt = mysqli_prepare($conn, $query);
if (count($params) > 0) {
    mysqli_stmt_bind_param($stmt, $types, ...$params);
}
mysqli_stmt_execute($stmt);
$result = mysqli_stmt_get_result($stmt);
?>

<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>
        Employees - Bekaert Competency
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
                <?php
                echo htmlspecialchars(
                    $_SESSION['admin_name']
                );
                ?>
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
                    Employee Management
                </h1>
                <p>
                    Manage employee information
                </p>
            </div>
            <div class="d-flex gap-2">
                <a href="dashboard.php" class="btn btn-outline-secondary">
                    &larr; Dashboard
                </a>
                <a href="qr_codes.php" class="btn btn-outline-primary">
                    QR Codes
                </a>
                <a href="employee_add.php" class="btn btn-primary">
                    + Add Employee
                </a>
            </div>
        </div>
        <!-- SEARCH -->
        <div class="employee-search">
            <form method="GET" class="row g-2">
                <div class="col-md-7">
                    <input type="text" name="search" class="form-control"
                        placeholder="Search NIK, name, department, position, supervisor..."
                        value="<?php echo htmlspecialchars($search); ?>">
                </div>
                <div class="col-md-3">
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
        <!-- TABLE -->
        <div class="employee-table-card">
            <div class="table-responsive">
                <table class="table table-hover align-middle mb-0">
                    <thead>
                        <tr>
                            <th>
                                #
                            </th>
                            <th>
                                Photo
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
                            <th>
                                License ID
                            </th>
                            <th>
                                Action
                            </th>
                        </tr>
                    </thead>
                    <tbody>

                        <?php
                        $number = 1;
                        if (mysqli_num_rows($result) > 0):
                            while (
                                $employee =
                                mysqli_fetch_assoc($result)
                            ):
                                ?>
                                <tr>
                                    <td>
                                        <?php echo $number++; ?>
                                    </td>
                                    <td>
                                        <?php if (!empty($employee['photo'])): ?>
                                            <img src="../uploads/employees/<?php echo htmlspecialchars($employee['photo']); ?>"
                                                class="employee-photo-small" alt="Employee photo">
                                        <?php else: ?>
                                            <div class="employee-photo-placeholder">
                                                <?php
                                                echo strtoupper(
                                                    substr(
                                                        $employee['name'],
                                                        0,
                                                        1
                                                    )
                                                );
                                                ?>
                                            </div>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <?php
                                        echo htmlspecialchars(
                                            $employee['nik']
                                        );
                                        ?>
                                    </td>
                                    <td>
                                        <strong>
                                            <?php
                                            echo htmlspecialchars(
                                                $employee['name']
                                            );
                                            ?>
                                        </strong>
                                    </td>
                                    <td>
                                        <?php
                                        echo htmlspecialchars(
                                            $employee['department']
                                        );
                                        ?>
                                    </td>
                                    <td>
                                        <?php
                                        echo htmlspecialchars(
                                            $employee['position']
                                        );
                                        ?>
                                    </td>
                                    <td>
                                        <?php
                                        echo htmlspecialchars(
                                            $employee['supervisor'] ?? '-'
                                        );
                                        ?>
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
                                    <td>
                                        <?php
                                        echo htmlspecialchars(
                                            $employee['license_id'] ?? '-'
                                        );
                                        ?>
                                    </td>
                                    <td>
                                        <div class="employee-actions">
                                            <a href="../index.php?nik=<?php echo urlencode($employee['nik']); ?>"
                                                class="btn btn-sm btn-outline-secondary" target="_blank">
                                                View
                                            </a>
                                            <a href="employee_edit.php?id=<?php echo $employee['id']; ?>"
                                                class="btn btn-sm btn-outline-primary">
                                                Edit
                                            </a>
                                            <a href="employee_competencies.php?id=<?php echo $employee['id']; ?>"
                                                class="btn btn-sm btn-outline-success">
                                                Competencies
                                            </a>
                                            <a href="employee_delete.php?id=<?php echo $employee['id']; ?>"
                                                class="btn btn-sm btn-outline-danger"
                                                onclick="return confirm('Apakah Anda yakin ingin menghapus karyawan ini?');">
                                                Delete
                                            </a>
                                        </div>
                                    </td>
                                </tr>
                                <?php
                            endwhile;
                        else:
                            ?>
                            <tr>
                                <td colspan="10" class="text-center py-5">
                                    Tidak ada data karyawan.
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