<?php
require_once "auth.php";
require_once "../config/database.php";
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header("Location: competencies.php");
    exit;
}
csrf_validate();
require_writer();
$id = isset($_POST['id'])
    ? (int) $_POST['id']
    : 0;
if ($id <= 0) {
    header("Location: competencies.php");
    exit;
}
$questionStmt = mysqli_prepare(
    $conn,
    "SELECT competency_id FROM competency_questions WHERE id = ? LIMIT 1"
);
mysqli_stmt_bind_param($questionStmt, "i", $id);
mysqli_stmt_execute($questionStmt);
$question = mysqli_fetch_assoc(mysqli_stmt_get_result($questionStmt));
if (!$question) {
    header("Location: competencies.php");
    exit;
}
$competencyId = (int) $question['competency_id'];
/*
|--------------------------------------------------------------------------
| Cek apakah soal sudah pernah dijawab
|--------------------------------------------------------------------------
*/
$checkStmt = mysqli_prepare(
    $conn,
    "SELECT COUNT(*) AS total FROM employee_quiz_answers WHERE question_id = ?"
);
mysqli_stmt_bind_param($checkStmt, "i", $id);
mysqli_stmt_execute($checkStmt);
$usage = mysqli_fetch_assoc(mysqli_stmt_get_result($checkStmt));
if ($usage['total'] > 0) {
    die(
        "Soal ini sudah pernah dijawab oleh karyawan. Tidak dapat dihapus."
    );
}
$deleteStmt = mysqli_prepare(
    $conn,
    "DELETE FROM competency_questions WHERE id = ?"
);
mysqli_stmt_bind_param($deleteStmt, "i", $id);
mysqli_stmt_execute($deleteStmt);
header("Location: competency_questions.php?competency_id=" . $competencyId);
exit;
