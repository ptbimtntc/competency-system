<?php
require_once "auth.php";
require_once "../config/database.php";
/*
|--------------------------------------------------------------------------
| Pastikan request menggunakan POST
|--------------------------------------------------------------------------
*/
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header("Location: competencies.php");
    exit;
}
/*
|--------------------------------------------------------------------------
| Ambil competency ID
|--------------------------------------------------------------------------
*/
$competency_id = isset($_POST['competency_id'])
    ? (int) $_POST['competency_id']
    : 0;
if ($competency_id <= 0) {
    die("Competency tidak valid.");
}
$redirectSearch = trim($_POST['redirect_search'] ?? '');
$redirectTeam = trim($_POST['redirect_team'] ?? '');
/*
|--------------------------------------------------------------------------
| Ambil default info training milik competency ini
|--------------------------------------------------------------------------
*/
$defaultsStmt = mysqli_prepare(
    $conn,
    "SELECT
        default_trainer,
        default_training_provider,
        default_authorizer_title,
        default_authorizer_name,
        default_trainer_signatory_id,
        default_authorizer_signatory_id
    FROM competencies
    WHERE id = ?
    LIMIT 1"
);
mysqli_stmt_bind_param($defaultsStmt, "i", $competency_id);
mysqli_stmt_execute($defaultsStmt);
$defaults = mysqli_fetch_assoc(mysqli_stmt_get_result($defaultsStmt));
if (!$defaults) {
    die("Competency tidak ditemukan.");
}
$defaultTrainer = $defaults['default_trainer'] ?? null;
$defaultTrainingProvider = $defaults['default_training_provider'] ?? null;
$defaultAuthorizerTitle = $defaults['default_authorizer_title'] ?? 'Maintenance Manager';
$defaultAuthorizerName = $defaults['default_authorizer_name'] ?? null;
$defaultTrainerSignatoryId = !empty($defaults['default_trainer_signatory_id'])
    ? (int) $defaults['default_trainer_signatory_id']
    : null;
$defaultAuthorizerSignatoryId = !empty($defaults['default_authorizer_signatory_id'])
    ? (int) $defaults['default_authorizer_signatory_id']
    : null;
/*
|--------------------------------------------------------------------------
| Ambil employee yang ditampilkan (scope) dan yang dipilih
|--------------------------------------------------------------------------
|
| visible_employees[] = seluruh employee yang tampil di halaman
| (dipengaruhi search filter), employees[] = yang dicentang.
| Hanya employee dalam scope "visible" yang boleh dinonaktifkan,
| supaya employee di luar hasil pencarian tidak ikut tersentuh.
|
*/
$visibleEmployees = $_POST['visible_employees'] ?? [];
if (!is_array($visibleEmployees)) {
    $visibleEmployees = [];
}
$visibleEmployeeIds = array_unique(array_map('intval', $visibleEmployees));

$selectedEmployees = $_POST['employees'] ?? [];
if (!is_array($selectedEmployees)) {
    $selectedEmployees = [];
}
$selectedEmployeeIds = array_unique(array_map('intval', $selectedEmployees));
/*
|--------------------------------------------------------------------------
| Ambil assignment yang sudah ada untuk competency ini
|--------------------------------------------------------------------------
*/
mysqli_begin_transaction($conn);
try {
    $existingStmt = mysqli_prepare(
        $conn,
        "SELECT id, employee_id FROM employee_competencies WHERE competency_id = ?"
    );
    mysqli_stmt_bind_param($existingStmt, "i", $competency_id);
    mysqli_stmt_execute($existingStmt);
    $existingResult = mysqli_stmt_get_result($existingStmt);
    $existingByEmployee = [];
    while ($row = mysqli_fetch_assoc($existingResult)) {
        $existingByEmployee[(int) $row['employee_id']] = (int) $row['id'];
    }
    /*
    |--------------------------------------------------------------------------
    | 1. NONAKTIFKAN employee yang tampil tapi tidak dicentang
    |--------------------------------------------------------------------------
    */
    foreach ($visibleEmployeeIds as $employee_id) {
        if (
            isset($existingByEmployee[$employee_id]) &&
            !in_array($employee_id, $selectedEmployeeIds, true)
        ) {
            $deactivateStmt = mysqli_prepare(
                $conn,
                "UPDATE employee_competencies SET is_active = 0 WHERE id = ? AND competency_id = ?"
            );
            mysqli_stmt_bind_param(
                $deactivateStmt,
                "ii",
                $existingByEmployee[$employee_id],
                $competency_id
            );
            mysqli_stmt_execute($deactivateStmt);
        }
    }
    /*
    |--------------------------------------------------------------------------
    | 2. AKTIFKAN KEMBALI atau TAMBAHKAN employee yang dicentang
    |--------------------------------------------------------------------------
    */
    foreach ($selectedEmployeeIds as $employee_id) {
        if ($employee_id <= 0) {
            continue;
        }
        if (isset($existingByEmployee[$employee_id])) {
            $reactivateStmt = mysqli_prepare(
                $conn,
                "UPDATE employee_competencies SET is_active = 1 WHERE id = ? AND competency_id = ?"
            );
            mysqli_stmt_bind_param(
                $reactivateStmt,
                "ii",
                $existingByEmployee[$employee_id],
                $competency_id
            );
            mysqli_stmt_execute($reactivateStmt);
            continue;
        }
        $insertStmt = mysqli_prepare(
            $conn,
            "INSERT INTO employee_competencies
            (
                employee_id,
                competency_id,
                status,
                is_active,
                trainer,
                training_provider,
                authorizer_title,
                authorizer_name,
                trainer_signatory_id,
                authorizer_signatory_id
            )
            VALUES (?, ?, 'NOT_TAKEN', 1, ?, ?, ?, ?, ?, ?)"
        );
        mysqli_stmt_bind_param(
            $insertStmt,
            "iissssii",
            $employee_id,
            $competency_id,
            $defaultTrainer,
            $defaultTrainingProvider,
            $defaultAuthorizerTitle,
            $defaultAuthorizerName,
            $defaultTrainerSignatoryId,
            $defaultAuthorizerSignatoryId
        );
        mysqli_stmt_execute($insertStmt);
    }
    mysqli_commit($conn);
    $redirect = "competency_assign.php?id=" . $competency_id . "&success=1";
    if ($redirectSearch !== '') {
        $redirect .= "&search=" . urlencode($redirectSearch);
    }
    if ($redirectTeam !== '') {
        $redirect .= "&team=" . urlencode($redirectTeam);
    }
    header("Location: " . $redirect);
    exit;
} catch (Exception $e) {
    mysqli_rollback($conn);
    die("Gagal menyimpan assignment: " . $e->getMessage());
}
