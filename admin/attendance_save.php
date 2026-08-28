<?php
require_once "auth.php";
require_once "../config/database.php";
require_once "../includes/competency_helper.php";
/*
|--------------------------------------------------------------------------
| Pastikan request menggunakan POST
|--------------------------------------------------------------------------
*/
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header("Location: attendance.php");
    exit;
}
csrf_validate();
require_writer();
/*
|--------------------------------------------------------------------------
| Ambil competency & tanggal sesi
|--------------------------------------------------------------------------
*/
$competency_id = isset($_POST['competency_id']) ? (int) $_POST['competency_id'] : 0;
$scheduledTrainingDate = trim($_POST['scheduled_training_date'] ?? '');
if ($competency_id <= 0 || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $scheduledTrainingDate)) {
    die("Sesi training tidak valid.");
}
$redirectSearch = trim($_POST['redirect_search'] ?? '');
$redirectTeam = trim($_POST['redirect_team'] ?? '');
/*
|--------------------------------------------------------------------------
| Ambil default info training milik competency ini (untuk assignment baru)
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
        default_authorizer_signatory_id,
        passing_score
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
$passingScore = $defaults['passing_score'] !== null
    ? (int) $defaults['passing_score']
    : null;
/*
|--------------------------------------------------------------------------
| Ambil employee yang ditampilkan (scope) dan yang dicentang hadir
|--------------------------------------------------------------------------
*/
$visibleEmployees = $_POST['visible_employees'] ?? [];
if (!is_array($visibleEmployees)) {
    $visibleEmployees = [];
}
$visibleEmployeeIds = array_unique(array_map('intval', $visibleEmployees));

$attendedEmployees = $_POST['attended_employees'] ?? [];
if (!is_array($attendedEmployees)) {
    $attendedEmployees = [];
}
$attendedEmployeeIds = array_flip(array_unique(array_map('intval', $attendedEmployees)));
/*
|--------------------------------------------------------------------------
| Ambil assignment aktif yang sudah ada untuk competency ini
|--------------------------------------------------------------------------
*/
mysqli_begin_transaction($conn);
try {
    /*
    | Ambil SEMUA row untuk competency ini (aktif maupun tidak). Kolom
    | employee_id+competency_id punya unique constraint, jadi employee yang
    | pernah di-assign lalu dinonaktifkan tetap harus di-UPDATE (reaktivasi),
    | bukan di-INSERT ulang -- kalau tidak, akan bentrok dan gagal.
    */
    $existingStmt = mysqli_prepare(
        $conn,
        "SELECT
            id,
            employee_id,
            training_date,
            scheduled_training_date,
            expiry_date,
            score,
            is_active
        FROM employee_competencies
        WHERE competency_id = ?"
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
                /*
                | Hadir -> pindahkan (atau konfirmasi) karyawan ini ke sesi
                | yang dipilih dan tandai kehadirannya.
                |
                | Kalau employee ini sebelumnya SUDAH pernah menyelesaikan
                | training (training_date terisi) di sesi yang BERBEDA dari
                | sesi yang dipilih sekarang, ini training cycle baru (refresh
                | / re-training) -- reset training_date/expiry/score/
                | certificate/quiz_submitted_at supaya statusnya balik ke
                | ASSIGNED dan dia bisa mengerjakan kuis lagi untuk sesi baru
                | ini, bukan tetap nyangkut VALID/EXPIRED/FAILED dari siklus
                | lama dengan tanggal training yang basi.
                */
                $isNewCycle = !empty($existing['training_date'])
                    && $existing['scheduled_training_date'] !== $scheduledTrainingDate;

                if ($isNewCycle) {
                    $effStatus = calculateCompetencyStatusWithSchedule(null, null, $scheduledTrainingDate);
                    $updateStmt = mysqli_prepare(
                        $conn,
                        "UPDATE employee_competencies
                        SET is_active = 1,
                            scheduled_training_date = ?,
                            attendance_confirmed = 1,
                            status = ?,
                            training_date = NULL,
                            expiry_date = NULL,
                            score = NULL,
                            certificate_number = NULL,
                            quiz_submitted_at = NULL
                        WHERE id = ?"
                    );
                } else {
                    $effScore = $existing['score'] !== null ? (int) $existing['score'] : null;
                    $effStatus = calculateCompetencyStatusWithSchedule(
                        $existing['training_date'],
                        $existing['expiry_date'],
                        $scheduledTrainingDate
                    );
                    $effStatus = applyPassingScoreGate($effStatus, $effScore, $passingScore);
                    $updateStmt = mysqli_prepare(
                        $conn,
                        "UPDATE employee_competencies
                        SET is_active = 1,
                            scheduled_training_date = ?,
                            attendance_confirmed = 1,
                            status = ?
                        WHERE id = ?"
                    );
                }
                mysqli_stmt_bind_param($updateStmt, "ssi", $scheduledTrainingDate, $effStatus, $existing['id']);
                mysqli_stmt_execute($updateStmt);
            } elseif (
                (int) $existing['is_active'] === 1 &&
                $existing['scheduled_training_date'] === $scheduledTrainingDate
            ) {
                /*
                | Tidak hadir, dan memang dijadwalkan di sesi ini -> batalkan
                | konfirmasi kehadiran. Kalau dia dijadwalkan di sesi lain
                | atau row-nya sudah nonaktif, jangan diutak-atik (bukan
                | bagian dari sesi ini).
                */
                $uncheckStmt = mysqli_prepare(
                    $conn,
                    "UPDATE employee_competencies SET attendance_confirmed = 0 WHERE id = ?"
                );
                mysqli_stmt_bind_param($uncheckStmt, "i", $existing['id']);
                mysqli_stmt_execute($uncheckStmt);
            }
            continue;
        }

        if ($isAttended) {
            /*
            | Walk-in: hadir tapi belum pernah di-assign ke competency ini.
            | Buat assignment baru langsung dengan kehadiran terkonfirmasi.
            */
            $insertStatus = calculateCompetencyStatusWithSchedule(null, null, $scheduledTrainingDate);
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
                    authorizer_signatory_id,
                    scheduled_training_date,
                    attendance_confirmed
                )
                VALUES (?, ?, ?, 1, ?, ?, ?, ?, ?, ?, ?, 1)"
            );
            mysqli_stmt_bind_param(
                $insertStmt,
                "iisssssiis",
                $employee_id,
                $competency_id,
                $insertStatus,
                $defaultTrainer,
                $defaultTrainingProvider,
                $defaultAuthorizerTitle,
                $defaultAuthorizerName,
                $defaultTrainerSignatoryId,
                $defaultAuthorizerSignatoryId,
                $scheduledTrainingDate
            );
            mysqli_stmt_execute($insertStmt);
        }
    }
    mysqli_commit($conn);
    $redirect = "attendance.php?competency_id=" . $competency_id
        . "&scheduled_date=" . urlencode($scheduledTrainingDate)
        . "&success=1";
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
    die("Gagal menyimpan attendance: " . $e->getMessage());
}
