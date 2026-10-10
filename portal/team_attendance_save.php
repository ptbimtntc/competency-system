<?php
require_once "../includes/portal_auth.php";
require_once "../includes/competency_helper.php";

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header("Location: team_attendance.php");
    exit;
}
csrf_validate();
portal_require_execute('team_attendance');

$competency_id = isset($_POST['competency_id']) ? (int) $_POST['competency_id'] : 0;
$scheduledTrainingDate = trim($_POST['scheduled_training_date'] ?? '');
if ($competency_id <= 0 || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $scheduledTrainingDate)) {
    die("Sesi training tidak valid.");
}

/*
|--------------------------------------------------------------------------
| Validasi scope: hanya employee di dalam scope (diri sendiri + bawahan,
| atau semua jika superadmin) yang boleh diproses, walau form di-tamper.
|--------------------------------------------------------------------------
*/
$scopeNiks = portal_scope_niks($conn);
[$scopeClause, $scopeParams] = portal_scope_where($scopeNiks, 'nik');
$allowedIdsStmt = mysqli_prepare($conn, "SELECT id FROM employees WHERE is_deleted = 0 {$scopeClause}");
if (!empty($scopeParams)) {
    mysqli_stmt_bind_param($allowedIdsStmt, str_repeat("s", count($scopeParams)), ...$scopeParams);
}
mysqli_stmt_execute($allowedIdsStmt);
$allowedIdsResult = mysqli_stmt_get_result($allowedIdsStmt);
$allowedIds = [];
while ($row = mysqli_fetch_assoc($allowedIdsResult)) {
    $allowedIds[(int) $row['id']] = true;
}

$defaultsStmt = mysqli_prepare(
    $conn,
    "SELECT default_trainer, default_training_provider, default_authorizer_title, default_authorizer_name,
        default_trainer_signatory_id, default_authorizer_signatory_id, passing_score
     FROM competencies WHERE id = ? LIMIT 1"
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
$defaultTrainerSignatoryId = !empty($defaults['default_trainer_signatory_id']) ? (int) $defaults['default_trainer_signatory_id'] : null;
$defaultAuthorizerSignatoryId = !empty($defaults['default_authorizer_signatory_id']) ? (int) $defaults['default_authorizer_signatory_id'] : null;
$passingScore = $defaults['passing_score'] !== null ? (int) $defaults['passing_score'] : null;

$visibleEmployees = $_POST['visible_employees'] ?? [];
if (!is_array($visibleEmployees)) {
    $visibleEmployees = [];
}
$visibleEmployeeIds = array_unique(array_map('intval', $visibleEmployees));
// Buang employee ID apa pun yang di luar scope (anti-tamper).
$visibleEmployeeIds = array_filter($visibleEmployeeIds, function ($id) use ($allowedIds) {
    return isset($allowedIds[$id]);
});

$attendedEmployees = $_POST['attended_employees'] ?? [];
if (!is_array($attendedEmployees)) {
    $attendedEmployees = [];
}
$attendedEmployeeIds = array_flip(array_unique(array_map('intval', $attendedEmployees)));

mysqli_begin_transaction($conn);
try {
    $todayDate = date('Y-m-d');
    $existingStmt = mysqli_prepare(
        $conn,
        "SELECT id, employee_id, training_date, scheduled_training_date, expiry_date, score, is_active, quiz_submitted_at
         FROM employee_competencies WHERE competency_id = ?"
    );
    mysqli_stmt_bind_param($existingStmt, "i", $competency_id);
    mysqli_stmt_execute($existingStmt);
    $existingResult = mysqli_stmt_get_result($existingStmt);
    $existingByEmployee = [];
    while ($row = mysqli_fetch_assoc($existingResult)) {
        $existingByEmployee[(int) $row['employee_id']] = $row;
    }

    foreach ($visibleEmployeeIds as $employee_id) {
        if ($employee_id <= 0) {
            continue;
        }
        $isAttended = isset($attendedEmployeeIds[$employee_id]);

        if (isset($existingByEmployee[$employee_id])) {
            $existing = $existingByEmployee[$employee_id];
            if ($isAttended) {
                $isNewCycle = !empty($existing['training_date'])
                    && $existing['scheduled_training_date'] !== $scheduledTrainingDate;

                if ($isNewCycle) {
                    $effStatus = calculateCompetencyStatusWithSchedule($todayDate, null, $scheduledTrainingDate, null);
                    $updateStmt = mysqli_prepare(
                        $conn,
                        "UPDATE employee_competencies
                        SET is_active = 1, scheduled_training_date = ?, attendance_confirmed = 1,
                            status = ?, training_date = ?, expiry_date = NULL, score = NULL,
                            certificate_number = NULL, quiz_submitted_at = NULL
                        WHERE id = ?"
                    );
                    mysqli_stmt_bind_param($updateStmt, "sssi", $scheduledTrainingDate, $effStatus, $todayDate, $existing['id']);
                } else {
                    $effTrainingDate = $existing['training_date'] ?: $todayDate;
                    $effScore = $existing['score'] !== null ? (int) $existing['score'] : null;
                    $effStatus = calculateCompetencyStatusWithSchedule(
                        $effTrainingDate,
                        $existing['expiry_date'],
                        $scheduledTrainingDate,
                        $existing['quiz_submitted_at']
                    );
                    $effStatus = applyPassingScoreGate($effStatus, $effScore, $passingScore);
                    $updateStmt = mysqli_prepare(
                        $conn,
                        "UPDATE employee_competencies
                        SET is_active = 1, scheduled_training_date = ?, attendance_confirmed = 1,
                            status = ?, training_date = ?
                        WHERE id = ?"
                    );
                    mysqli_stmt_bind_param($updateStmt, "sssi", $scheduledTrainingDate, $effStatus, $effTrainingDate, $existing['id']);
                }
                mysqli_stmt_execute($updateStmt);
            } elseif (
                (int) $existing['is_active'] === 1 &&
                $existing['scheduled_training_date'] === $scheduledTrainingDate
            ) {
                $uncheckStmt = mysqli_prepare($conn, "UPDATE employee_competencies SET attendance_confirmed = 0 WHERE id = ?");
                mysqli_stmt_bind_param($uncheckStmt, "i", $existing['id']);
                mysqli_stmt_execute($uncheckStmt);
            }
            continue;
        }

        if ($isAttended) {
            $insertStatus = calculateCompetencyStatusWithSchedule($todayDate, null, $scheduledTrainingDate, null);
            $insertStmt = mysqli_prepare(
                $conn,
                "INSERT INTO employee_competencies
                (employee_id, competency_id, status, is_active, trainer, training_provider, authorizer_title,
                 authorizer_name, trainer_signatory_id, authorizer_signatory_id, scheduled_training_date,
                 training_date, attendance_confirmed)
                VALUES (?, ?, ?, 1, ?, ?, ?, ?, ?, ?, ?, ?, 1)"
            );
            mysqli_stmt_bind_param(
                $insertStmt,
                "iisssssiiss",
                $employee_id,
                $competency_id,
                $insertStatus,
                $defaultTrainer,
                $defaultTrainingProvider,
                $defaultAuthorizerTitle,
                $defaultAuthorizerName,
                $defaultTrainerSignatoryId,
                $defaultAuthorizerSignatoryId,
                $scheduledTrainingDate,
                $todayDate
            );
            mysqli_stmt_execute($insertStmt);
        }
    }
    mysqli_commit($conn);
    header("Location: team_attendance.php?competency_id={$competency_id}&scheduled_date=" . urlencode($scheduledTrainingDate) . "&success=1");
    exit;
} catch (Exception $e) {
    mysqli_rollback($conn);
    die("Gagal menyimpan attendance: " . $e->getMessage());
}
