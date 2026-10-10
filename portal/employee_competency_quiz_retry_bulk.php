<?php
require_once "../includes/portal_auth.php";
require_once "../includes/competency_helper.php";
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header("Location: team_failed.php");
    exit;
}
csrf_validate();
portal_require_execute('dashboard');

$ids = $_POST['reset_ids'] ?? [];
if (!is_array($ids)) {
    $ids = [];
}
$ids = array_values(array_unique(array_filter(array_map('intval', $ids), fn($v) => $v > 0)));

if (count($ids) > 0) {
    /*
    |--------------------------------------------------------------------------
    | Validasi scope: supervisor hanya boleh reset kuis bawahannya sendiri
    | (atau diri sendiri), superadmin tidak dibatasi. Dicek di server supaya
    | tidak bisa ditembus walau form di-tamper (lihat juga reset satuan di
    | employee_competency_quiz_retry.php).
    |--------------------------------------------------------------------------
    */
    $scopeNiks = portal_scope_niks($conn);
    [$scopeClause, $scopeParams] = portal_scope_where($scopeNiks, 'e.nik');
    $placeholders = implode(',', array_fill(0, count($ids), '?'));
    $types = str_repeat('i', count($ids)) . str_repeat('s', count($scopeParams));
    $params = array_merge($ids, $scopeParams);
    $allowedStmt = mysqli_prepare(
        $conn,
        "SELECT ec.id FROM employee_competencies ec
         INNER JOIN employees e ON e.id = ec.employee_id
         WHERE ec.status = 'FAILED' AND e.is_deleted = 0
            AND ec.id IN ({$placeholders}) {$scopeClause}"
    );
    mysqli_stmt_bind_param($allowedStmt, $types, ...$params);
    mysqli_stmt_execute($allowedStmt);
    $allowedResult = mysqli_stmt_get_result($allowedStmt);
    while ($row = mysqli_fetch_assoc($allowedResult)) {
        resetEmployeeCompetencyQuiz($conn, (int) $row['id'], 1);
    }
}

$back = trim($_POST['back'] ?? '');
if (!preg_match('/^[a-zA-Z0-9_\-]+\.php(\?[a-zA-Z0-9_\-\.=&%]*)?$/', $back)) {
    $back = 'team_failed.php';
}
$separator = strpos($back, '?') !== false ? '&' : '?';
header("Location: " . $back . $separator . "quiz_retry=1");
exit;
