<?php
require_once "../includes/portal_auth.php";
require_once "../includes/competency_helper.php";
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header("Location: dashboard.php");
    exit;
}
csrf_validate();
portal_require_execute('dashboard');

$id = isset($_POST['id']) ? (int) $_POST['id'] : 0;
if ($id <= 0) {
    header("Location: dashboard.php");
    exit;
}

/*
|--------------------------------------------------------------------------
| Validasi scope: supervisor hanya boleh reset kuis bawahannya sendiri
| (atau diri sendiri), superadmin tidak dibatasi. Dicek di server supaya
| tidak bisa ditembus walau form di-tamper.
|--------------------------------------------------------------------------
*/
$scopeNiks = portal_scope_niks($conn);
[$scopeClause, $scopeParams] = portal_scope_where($scopeNiks, 'e.nik');
$checkStmt = mysqli_prepare(
    $conn,
    "SELECT ec.id, c.portal_reset_allowed FROM employee_competencies ec
     INNER JOIN employees e ON e.id = ec.employee_id
     INNER JOIN competencies c ON c.id = ec.competency_id
     WHERE ec.id = ? AND e.is_deleted = 0 {$scopeClause}
     LIMIT 1"
);
$types = "i" . str_repeat("s", count($scopeParams));
$params = array_merge([$id], $scopeParams);
mysqli_stmt_bind_param($checkStmt, $types, ...$params);
mysqli_stmt_execute($checkStmt);
$ecCheck = mysqli_fetch_assoc(mysqli_stmt_get_result($checkStmt));
if (!$ecCheck) {
    http_response_code(403);
    die("Karyawan ini bukan bawahan Anda.");
}
if ((int) $ecCheck['portal_reset_allowed'] !== 1) {
    http_response_code(403);
    die("Reset untuk competency ini dinonaktifkan oleh superadmin.");
}

resetEmployeeCompetencyQuiz($conn, $id, 1);

$back = trim($_POST['back'] ?? '');
if (!preg_match('/^[a-zA-Z0-9_\-]+\.php(\?[a-zA-Z0-9_\-\.=&%]*)?$/', $back)) {
    $back = 'dashboard.php';
}
$separator = strpos($back, '?') !== false ? '&' : '?';
header("Location: " . $back . $separator . "quiz_retry=1");
exit;
