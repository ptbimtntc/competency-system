<?php
require_once "auth.php";
require_once "../config/database.php";
require_once "../includes/competency_helper.php";
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header("Location: dashboard.php");
    exit;
}
csrf_validate();
require_writer();
$id = isset($_POST['id']) ? (int) $_POST['id'] : 0;
if ($id <= 0) {
    header("Location: dashboard.php");
    exit;
}

resetEmployeeCompetencyQuiz($conn, $id, 1);

$back = trim($_POST['back'] ?? '');
if (!preg_match('/^[a-zA-Z0-9_\-]+\.php(\?[a-zA-Z0-9_\-\.=&%]*)?$/', $back)) {
    $back = 'dashboard.php';
}
$separator = strpos($back, '?') !== false ? '&' : '?';
header("Location: " . $back . $separator . "quiz_retry=1");
exit;
