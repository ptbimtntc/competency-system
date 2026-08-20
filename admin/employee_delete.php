<?php
require_once "auth.php";
require_once "../config/database.php";
$id = isset($_GET['id'])
    ? (int) $_GET['id']
    : 0;
if ($id <= 0) {
    header("Location: employees.php");
    exit;
}
/*
|--------------------------------------------------------------------------
| Ambil foto employee
|--------------------------------------------------------------------------
*/
$query = "
    SELECT photo
    FROM employees
    WHERE id = ?
    LIMIT 1
";
$stmt =
    mysqli_prepare(
        $conn,
        $query
    );
mysqli_stmt_bind_param(
    $stmt,
    "i",
    $id
);
mysqli_stmt_execute($stmt);
$result =
    mysqli_stmt_get_result($stmt);
$employee =
    mysqli_fetch_assoc($result);
if (!$employee) {
    header("Location: employees.php");
    exit;
}
/*
|--------------------------------------------------------------------------
| Hapus employee beserta riwayat competency terkait
|--------------------------------------------------------------------------
*/
mysqli_begin_transaction($conn);

$deleteCompetenciesStmt =
    mysqli_prepare(
        $conn,
        "DELETE FROM employee_competencies WHERE employee_id = ?"
    );
mysqli_stmt_bind_param(
    $deleteCompetenciesStmt,
    "i",
    $id
);
mysqli_stmt_execute($deleteCompetenciesStmt);

$deleteQuery = "
    DELETE FROM employees
    WHERE id = ?
";
$deleteStmt =
    mysqli_prepare(
        $conn,
        $deleteQuery
    );
mysqli_stmt_bind_param(
    $deleteStmt,
    "i",
    $id
);
if (
    mysqli_stmt_execute(
        $deleteStmt
    )
) {
    mysqli_commit($conn);
    /*
    |--------------------------------------------------------------------------
    | Hapus file foto
    |--------------------------------------------------------------------------
    */
    if (
        !empty(
            $employee['photo']
        )
    ) {
        $photoPath =
            "../uploads/employees/"
            . $employee['photo'];
        if (
            file_exists(
                $photoPath
            )
        ) {
            unlink(
                $photoPath
            );
        }
    }
} else {
    mysqli_rollback($conn);
}
header(
    "Location: employees.php"
);
exit;