<?php
require_once "auth.php";
require_once "../config/database.php";
require_once "../includes/competency_helper.php";
$id = isset($_GET['id'])
    ? (int) $_GET['id']
    : 0;
if ($id <= 0) {
    header("Location: employees.php");
    exit;
}
/*
|--------------------------------------------------------------------------
| Ambil data employee
|--------------------------------------------------------------------------
*/
$query = "
    SELECT
        id,
        nik,
        name,
        department,
        position
    FROM employees
    WHERE id = ?
    LIMIT 1
";
$stmt = mysqli_prepare(
    $conn,
    $query
);
mysqli_stmt_bind_param(
    $stmt,
    "i",
    $id
);
mysqli_stmt_execute($stmt);
$result = mysqli_stmt_get_result($stmt);
$employee = mysqli_fetch_assoc($result);
if (!$employee) {
    die("Employee tidak ditemukan.");
}
/*
|--------------------------------------------------------------------------
| Ambil semua competency
|--------------------------------------------------------------------------
*/
$competencyQuery = "
    SELECT
        id,
        name,
        description
    FROM competencies
    ORDER BY name ASC
";
$competencyResult =
    mysqli_query(
        $conn,
        $competencyQuery
    );
/*
|--------------------------------------------------------------------------
| Ambil competency yang sudah dimiliki employee
|--------------------------------------------------------------------------
*/
$assignedQuery = "
    SELECT
        id,
        competency_id,
        training_date,
        trainer,
        certificate_number,
        issue_date,
        expiry_date,
        status,
        is_active,
        notes
    FROM employee_competencies
    WHERE employee_id = ?
";
$assignedStmt =
    mysqli_prepare(
        $conn,
        $assignedQuery
    );
mysqli_stmt_bind_param(
    $assignedStmt,
    "i",
    $id
);
mysqli_stmt_execute(
    $assignedStmt
);
$assignedResult =
    mysqli_stmt_get_result(
        $assignedStmt
    );
/*
|--------------------------------------------------------------------------
| Buat array competency yang sudah dipilih
|--------------------------------------------------------------------------
*/
$assignedCompetencies = [];
while (
    $assigned =
    mysqli_fetch_assoc(
        $assignedResult
    )
) {
    $assignedCompetencies[
        $assigned['competency_id']
    ] = [
        'id' =>
            $assigned['id'],
        'training_date' =>
            $assigned['training_date'],
        'trainer' =>
            $assigned['trainer'],
        'certificate_number' =>
            $assigned['certificate_number'],
        'issue_date' =>
            $assigned['issue_date'],
        'expiry_date' =>
            $assigned['expiry_date'],
        'status' =>
            $assigned['status'],
        'is_active' =>
            (int) $assigned['is_active'],
        'notes' =>
            $assigned['notes']
    ];
}
?>

<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>
        Employee Competencies
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
                    Employee Competencies
                </h1>
                <p>
                    Manage competency records for employee
                </p>
            </div>
            <a href="employees.php" class="btn btn-outline-secondary">
                ← Back to Employees
            </a>
        </div>
        <!-- EMPLOYEE INFORMATION -->
        <div class="employee-info-card mb-4">
            <div>
                <small>
                    Employee
                </small>
                <h3>
                    <?php
                    echo htmlspecialchars(
                        $employee['name']
                    );
                    ?>
                </h3>
            </div>
            <div>
                <small>
                    NIK
                </small>
                <strong>
                    <?php
                    echo htmlspecialchars(
                        $employee['nik']
                    );
                    ?>
                </strong>
            </div>
            <div>
                <small>
                    Department
                </small>
                <strong>
                    <?php
                    echo htmlspecialchars(
                        $employee['department']
                    );
                    ?>
                </strong>
            </div>
            <div>
                <small>
                    Position
                </small>
                <strong>
                    <?php
                    echo htmlspecialchars(
                        $employee['position']
                    );
                    ?>
                </strong>
            </div>
        </div>
        <div class="employee-table-card">
            <form method="POST" action="employee_competencies_save.php" id="competencyForm">
                <input type="hidden" name="employee_id" value="<?php echo $employee['id']; ?>">
                <div class="p-4 border-bottom">
                    <h4 class="mb-1">
                        Competency List
                    </h4>
                    <p class="text-muted mb-0">
                        Select competency that has been completed
                        by this employee.
                    </p>
                </div>
                <div class="table-responsive">
                    <table class="table table-hover align-middle mb-0">
                        <thead>
                            <tr>
                                <th width="80">
                                    Select
                                </th>
                                <th>
                                    Competency
                                </th>
                                <th>
                                    Description
                                </th>
                                <th>
                                    Status
                                </th>
                                <th>
                                    Action
                                </th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php
                            while (
                                $competency =
                                mysqli_fetch_assoc(
                                    $competencyResult
                                )
                            ):
                                /*
                                |--------------------------------------------------------------------------
                                | Cek apakah competency pernah dimiliki employee
                                |--------------------------------------------------------------------------
                                */
                                $hasAssignment =
                                    isset(
                                    $assignedCompetencies[
                                        $competency['id']
                                    ]
                                );
                                /*
                                |--------------------------------------------------------------------------
                                | Cek apakah competency sedang aktif
                                |--------------------------------------------------------------------------
                                */
                                $isActive =
                                    $hasAssignment
                                    &&
                                    $assignedCompetencies[
                                        $competency['id']
                                    ]['is_active'] == 1;
                                /*
                                |--------------------------------------------------------------------------
                                | Ambil data competency employee
                                |--------------------------------------------------------------------------
                                */
                                $assignment =
                                    $hasAssignment
                                    ? $assignedCompetencies[
                                        $competency['id']
                                    ]
                                    : null;
                                /*
                                |--------------------------------------------------------------------------
                                | Hitung status otomatis
                                |--------------------------------------------------------------------------
                                */
                                if ($assignment) {
                                    $status =
                                        calculateCompetencyStatus(
                                            $assignment['training_date'],
                                            $assignment['expiry_date']
                                        );
                                } else {
                                    $status = 'NOT_TAKEN';
                                }
                                /*
                                |--------------------------------------------------------------------------
                                | ID employee_competency
                                |--------------------------------------------------------------------------
                                */
                                $assignmentId =
                                    $assignment
                                    ? $assignment['id']
                                    : 0;
                                ?>
                                <tr>
                                    <!-- SELECT -->
                                    <td>
                                        <input type="checkbox" class="form-check-input competency-checkbox"
                                            name="competencies[]" value="<?php
                                            echo $competency['id'];
                                            ?>" <?php
                                            echo $isActive
                                                ? 'checked'
                                                : '';
                                            ?> data-was-active="<?php
                                             echo $isActive
                                                 ? '1'
                                                 : '0';
                                             ?>" data-has-history="<?php
                                             if ($assignment) {
                                                 echo (
                                                     !empty(
                                                     $assignment['training_date']
                                                 )
                                                     ||
                                                     !empty(
                                                     $assignment['trainer']
                                                 )
                                                     ||
                                                     !empty(
                                                     $assignment['certificate_number']
                                                 )
                                                     ||
                                                     !empty(
                                                     $assignment['issue_date']
                                                 )
                                                     ||
                                                     !empty(
                                                     $assignment['expiry_date']
                                                 )
                                                     ||
                                                     !empty(
                                                     $assignment['notes']
                                                 )
                                                 )
                                                     ? '1'
                                                     : '0';
                                             } else {
                                                 echo '0';
                                             }
                                             ?>" data-competency-name="<?php
                                             echo htmlspecialchars(
                                                 $competency['name'],
                                                 ENT_QUOTES
                                             );
                                             ?>">
                                    </td>
                                    <!-- COMPETENCY -->
                                    <td>
                                        <strong>
                                            <?php
                                            echo htmlspecialchars(
                                                $competency['name']
                                            );
                                            ?>
                                        </strong>
                                    </td>
                                    <!-- DESCRIPTION -->
                                    <td>
                                        <?php
                                        echo htmlspecialchars(
                                            $competency['description']
                                            ?? '-'
                                        );
                                        ?>
                                    </td>
                                    <!-- STATUS -->
                                    <td>
                                        <?php if ($status === 'VALID'): ?>
                                            <span class="badge text-bg-success">
                                                Valid
                                            </span>
                                        <?php elseif (
                                            $status === 'EXPIRING_SOON'
                                        ): ?>
                                            <span class="badge text-bg-warning">
                                                Expiring Soon
                                            </span>
                                        <?php elseif (
                                            $status === 'EXPIRED'
                                        ): ?>
                                            <span class="badge text-bg-danger">
                                                Expired
                                            </span>
                                        <?php else: ?>
                                            <span class="badge text-bg-secondary">
                                                Not Taken
                                            </span>
                                        <?php endif; ?>
                                        <?php if (
                                            $hasAssignment
                                            &&
                                            !$isActive
                                        ): ?>
                                            <br>
                                            <small class="text-muted">
                                                Archived
                                            </small>
                                        <?php endif; ?>
                                    </td>
                                    <!-- ACTION -->
                                    <td>
                                        <?php if ($assignmentId > 0): ?>
                                            <a href="employee_competency_edit.php?id=<?php
                                            echo $assignmentId;
                                            ?>" class="btn btn-sm btn-outline-primary">
                                                Detail
                                            </a>
                                        <?php else: ?>
                                            <span class="text-muted">
                                                Not assigned
                                            </span>
                                        <?php endif; ?>
                                    </td>
                                </tr>
                            <?php endwhile; ?>
                        </tbody>
                    </table>
                </div>
                <div class="p-4 border-top">
                    <button type="submit" class="btn btn-primary">
                        Save Competencies
                    </button>
                </div>
            </form>
        </div>
    </div>
    <script>
        document
            .getElementById('competencyForm')
            .addEventListener(
                'submit',
                function (event) {
                    const checkboxes =
                        document.querySelectorAll(
                            '.competency-checkbox'
                        );
                    for (
                        const checkbox
                        of checkboxes
                    ) {
                        /*
                        |--------------------------------------------------------------------------
                        | Cek apakah competency sebelumnya aktif
                        |--------------------------------------------------------------------------
                        */
                        const wasActive =
                            checkbox.dataset.wasActive
                            === '1';
                        /*
                        |--------------------------------------------------------------------------
                        | Cek apakah sekarang di-uncheck
                        |--------------------------------------------------------------------------
                        */
                        const isNowUnchecked =
                            !checkbox.checked;
                        /*
                        |--------------------------------------------------------------------------
                        | Cek apakah mempunyai riwayat
                        |--------------------------------------------------------------------------
                        */
                        const hasHistory =
                            checkbox.dataset.hasHistory
                            === '1';
                        /*
                        |--------------------------------------------------------------------------
                        | Jika semua kondisi terpenuhi
                        |--------------------------------------------------------------------------
                        */
                        if (
                            wasActive
                            &&
                            isNowUnchecked
                            &&
                            hasHistory
                        ) {
                            const competencyName =
                                checkbox.dataset
                                    .competencyName;
                            const confirmation =
                                confirm(
                                    competencyName
                                    +
                                    " memiliki data training/sertifikat."
                                    +
                                    "\n\n"
                                    +
                                    "Menghilangkan centang TIDAK akan menghapus riwayat."
                                    +
                                    "\n\n"
                                    +
                                    "Competency hanya akan menjadi INACTIVE."
                                    +
                                    "\n\n"
                                    +
                                    "Apakah Anda yakin ingin melanjutkan?"
                                );
                            if (!confirmation) {
                                event.preventDefault();
                                return;
                            }
                        }
                    }
                }
            );
    </script>
</body>

</html>