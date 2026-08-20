<?php
require_once "auth.php";
require_once "../config/database.php";
$id = isset($_GET['id'])
    ? (int) $_GET['id']
    : 0;
if ($id <= 0) {
    header(
        "Location: competencies.php"
    );
    exit;
}
/*
|--------------------------------------------------------------------------
| Cek apakah competency sedang digunakan
|--------------------------------------------------------------------------
*/
$checkQuery = "
    SELECT COUNT(*) AS total
    FROM employee_competencies
    WHERE competency_id = ?
";
$checkStmt =
    mysqli_prepare(
        $conn,
        $checkQuery
    );
mysqli_stmt_bind_param(
    $checkStmt,
    "i",
    $id
);
mysqli_stmt_execute(
    $checkStmt
);
$checkResult =
    mysqli_stmt_get_result(
        $checkStmt
    );
$usage =
    mysqli_fetch_assoc(
        $checkResult
    );
if ($usage['total'] > 0) {
    die(
        "Kompetensi ini sedang digunakan oleh employee. " .
        "Tidak dapat dihapus."
    );
}
/*
|--------------------------------------------------------------------------
| Delete
|--------------------------------------------------------------------------
*/
$query = "
    DELETE FROM competencies
    WHERE id = ?
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
header(
    "Location: competencies.php"
);
exit;