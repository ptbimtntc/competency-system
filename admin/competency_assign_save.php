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
    header("Location: competencies.php");
    exit;
}
csrf_validate();
require_writer();
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
$redirectDepartment = trim($_POST['redirect_department'] ?? '');
$redirectSupervisor = trim($_POST['redirect_supervisor'] ?? '');
$redirectAssignment = trim($_POST['redirect_assignment'] ?? '');
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
| Ambil isian training massal (opsional)
|--------------------------------------------------------------------------
|
| Halaman assign hanya untuk MENJADWALKAN training (scheduled_training_date),
| bukan mencatat training yang sudah selesai. Training date, score, dan
| expiry date terisi otomatis nanti saat employee submit kuis (atau diisi
| manual per employee lewat halaman detail untuk kasus training lama tanpa
| kuis). Field yang dikosongkan tidak mengubah apa pun.
|
*/
$bulkScheduledTrainingDate = trim($_POST['bulk_scheduled_training_date'] ?? '');
$bulkScheduledTrainingDate = $bulkScheduledTrainingDate === '' ? null : $bulkScheduledTrainingDate;
$bulkTrainer = trim($_POST['bulk_trainer'] ?? '');
$bulkTrainingProvider = trim($_POST['bulk_training_provider'] ?? '');
$bulkNotes = trim($_POST['bulk_notes'] ?? '');
$bulkOverwrite = isset($_POST['bulk_overwrite']) && $_POST['bulk_overwrite'] === '1';
$hasBulkData = $bulkScheduledTrainingDate !== null
    || $bulkTrainer !== ''
    || $bulkTrainingProvider !== ''
    || $bulkNotes !== '';
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
        "SELECT
            id,
            employee_id,
            training_date,
            scheduled_training_date,
            trainer,
            training_provider,
            expiry_date,
            score,
            notes,
            quiz_submitted_at
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
                $existingByEmployee[$employee_id]['id'],
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
        /*
        |--------------------------------------------------------------------------
        | Employee sudah pernah di-assign sebelumnya
        |--------------------------------------------------------------------------
        */
        if (isset($existingByEmployee[$employee_id])) {
            $existing = $existingByEmployee[$employee_id];
            if ($hasBulkData && $bulkOverwrite) {
                /*
                | Hanya field yang diisi di form massal yang menimpa data lama,
                | field yang dikosongkan tetap memakai nilai lama.
                |
                | Kalau employee ini sebelumnya SUDAH menyelesaikan training
                | (training_date terisi) dan sekarang dijadwalkan ulang ke
                | tanggal yang BERBEDA, ini training cycle baru (refresh /
                | re-training) -- reset training_date/expiry/score/
                | certificate/quiz_submitted_at supaya statusnya balik ke
                | ASSIGNED, bukan tetap nyangkut VALID/EXPIRED/FAILED dari
                | siklus lama dengan tanggal training yang basi.
                */
                $effScheduledTrainingDate = $bulkScheduledTrainingDate ?? $existing['scheduled_training_date'];
                $effTrainer = $bulkTrainer !== '' ? $bulkTrainer : $existing['trainer'];
                $effTrainingProvider = $bulkTrainingProvider !== '' ? $bulkTrainingProvider : $existing['training_provider'];
                $effNotes = $bulkNotes !== '' ? $bulkNotes : $existing['notes'];
                $isNewCycle = !empty($existing['training_date'])
                    && $existing['scheduled_training_date'] !== $effScheduledTrainingDate;

                if ($isNewCycle) {
                    /*
                    | Siklus lama akan ditimpa (training_date/expiry/score/
                    | certificate di-reset). Simpan snapshot-nya dulu ke riwayat
                    | supaya sertifikat siklus sebelumnya tidak hilang.
                    */
                    recordCompetencyHistory($conn, (int) $existing['id'], 'reschedule');
                    $effStatus = calculateCompetencyStatusWithSchedule(null, null, $effScheduledTrainingDate, null);
                    $updateDataStmt = mysqli_prepare(
                        $conn,
                        "UPDATE employee_competencies
                        SET
                            is_active = 1,
                            scheduled_training_date = ?,
                            trainer = ?,
                            training_provider = ?,
                            notes = ?,
                            status = ?,
                            training_date = NULL,
                            expiry_date = NULL,
                            score = NULL,
                            certificate_number = NULL,
                            quiz_submitted_at = NULL
                        WHERE id = ? AND competency_id = ?"
                    );
                } else {
                    $effScore = $existing['score'] !== null ? (int) $existing['score'] : null;
                    $effStatus = calculateCompetencyStatusWithSchedule(
                        $existing['training_date'],
                        $existing['expiry_date'],
                        $effScheduledTrainingDate,
                        $existing['quiz_submitted_at']
                    );
                    $effStatus = applyPassingScoreGate($effStatus, $effScore, $passingScore);
                    $updateDataStmt = mysqli_prepare(
                        $conn,
                        "UPDATE employee_competencies
                        SET
                            is_active = 1,
                            scheduled_training_date = ?,
                            trainer = ?,
                            training_provider = ?,
                            notes = ?,
                            status = ?
                        WHERE id = ? AND competency_id = ?"
                    );
                }
                $types = "";
                $params = [];
                $types .= "s"; $params[] = $effScheduledTrainingDate;
                $types .= "s"; $params[] = $effTrainer;
                $types .= "s"; $params[] = $effTrainingProvider;
                $types .= "s"; $params[] = $effNotes;
                $types .= "s"; $params[] = $effStatus;
                $types .= "i"; $params[] = $existing['id'];
                $types .= "i"; $params[] = $competency_id;
                mysqli_stmt_bind_param($updateDataStmt, $types, ...$params);
                mysqli_stmt_execute($updateDataStmt);
            } else {
                $reactivateStmt = mysqli_prepare(
                    $conn,
                    "UPDATE employee_competencies SET is_active = 1 WHERE id = ? AND competency_id = ?"
                );
                mysqli_stmt_bind_param(
                    $reactivateStmt,
                    "ii",
                    $existing['id'],
                    $competency_id
                );
                mysqli_stmt_execute($reactivateStmt);
            }
            continue;
        }
        /*
        |--------------------------------------------------------------------------
        | Insert competency yang benar-benar baru
        |--------------------------------------------------------------------------
        |
        | Trainer/provider diambil dari isian training massal kalau diisi, kalau
        | tidak jatuh ke default info training milik competency. Hanya
        | scheduled_training_date yang diisi di sini (status jadi ASSIGNED) --
        | training_date/score/expiry_date/certificate_number baru terisi lewat
        | submit kuis, atau diisi manual per employee lewat halaman detail
        | untuk kasus training lama tanpa kuis.
        |
        */
        $insertTrainer = $bulkTrainer !== '' ? $bulkTrainer : $defaultTrainer;
        $insertTrainingProvider = $bulkTrainingProvider !== '' ? $bulkTrainingProvider : $defaultTrainingProvider;
        $insertNotes = $bulkNotes !== '' ? $bulkNotes : null;
        $insertStatus = calculateCompetencyStatusWithSchedule(null, null, $bulkScheduledTrainingDate, null);

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
                notes
            )
            VALUES (?, ?, ?, 1, ?, ?, ?, ?, ?, ?, ?, ?)"
        );
        $types = "";
        $params = [];
        $types .= "i"; $params[] = $employee_id;
        $types .= "i"; $params[] = $competency_id;
        $types .= "s"; $params[] = $insertStatus;
        $types .= "s"; $params[] = $insertTrainer;
        $types .= "s"; $params[] = $insertTrainingProvider;
        $types .= "s"; $params[] = $defaultAuthorizerTitle;
        $types .= "s"; $params[] = $defaultAuthorizerName;
        $types .= "i"; $params[] = $defaultTrainerSignatoryId;
        $types .= "i"; $params[] = $defaultAuthorizerSignatoryId;
        $types .= "s"; $params[] = $bulkScheduledTrainingDate;
        $types .= "s"; $params[] = $insertNotes;
        mysqli_stmt_bind_param($insertStmt, $types, ...$params);
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
    if ($redirectDepartment !== '') {
        $redirect .= "&department=" . urlencode($redirectDepartment);
    }
    if ($redirectSupervisor !== '') {
        $redirect .= "&supervisor=" . urlencode($redirectSupervisor);
    }
    if ($redirectAssignment !== '') {
        $redirect .= "&assignment=" . urlencode($redirectAssignment);
    }
    header("Location: " . $redirect);
    exit;
} catch (Exception $e) {
    mysqli_rollback($conn);
    die("Gagal menyimpan assignment: " . $e->getMessage());
}
