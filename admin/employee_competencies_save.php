<?php
require_once "auth.php";
require_once "../config/database.php";
/*
|--------------------------------------------------------------------------
| Pastikan request menggunakan POST
|--------------------------------------------------------------------------
*/
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header("Location: employees.php");
    exit;
}
/*
|--------------------------------------------------------------------------
| Ambil employee ID
|--------------------------------------------------------------------------
*/
$employee_id = isset($_POST['employee_id'])
    ? (int) $_POST['employee_id']
    : 0;
if ($employee_id <= 0) {
    die("Employee tidak valid.");
}
/*
|--------------------------------------------------------------------------
| Ambil competency dari checkbox
|--------------------------------------------------------------------------
*/
$competencies =
    $_POST['competencies'] ?? [];
if (!is_array($competencies)) {
    $competencies = [];
}
/*
|--------------------------------------------------------------------------
| Bersihkan competency ID
|--------------------------------------------------------------------------
*/
$selectedCompetencyIds = [];
foreach ($competencies as $competency_id) {
    $competency_id =
        (int) $competency_id;
    if ($competency_id > 0) {
        $selectedCompetencyIds[] =
            $competency_id;
    }
}
/*
|--------------------------------------------------------------------------
| Hilangkan kemungkinan ID duplikat
|--------------------------------------------------------------------------
*/
$selectedCompetencyIds =
    array_unique(
        $selectedCompetencyIds
    );
/*
|--------------------------------------------------------------------------
| Ambil default info training per competency
|--------------------------------------------------------------------------
|
| Dipakai untuk mengisi otomatis trainer/provider/authorizer saat
| competency baru pertama kali di-assign ke employee. Tetap bisa
| diedit per employee lewat halaman detail training.
|
*/
$competencyDefaults = [];
$defaultsResult = mysqli_query(
    $conn,
    "SELECT
        id,
        default_trainer,
        default_training_provider,
        default_authorizer_title,
        default_authorizer_name,
        default_trainer_signatory_id,
        default_authorizer_signatory_id
    FROM competencies"
);
while ($row = mysqli_fetch_assoc($defaultsResult)) {
    $competencyDefaults[(int) $row['id']] = $row;
}
/*
|--------------------------------------------------------------------------
| Mulai Transaction
|--------------------------------------------------------------------------
*/
mysqli_begin_transaction($conn);
try {
    /*
    |--------------------------------------------------------------------------
    | Ambil competency yang sudah dimiliki employee
    |--------------------------------------------------------------------------
    */
    $existingQuery = "
        SELECT
            id,
            competency_id
        FROM employee_competencies
        WHERE employee_id = ?
    ";
    $existingStmt =
        mysqli_prepare(
            $conn,
            $existingQuery
        );
    mysqli_stmt_bind_param(
        $existingStmt,
        "i",
        $employee_id
    );
    mysqli_stmt_execute(
        $existingStmt
    );
    $existingResult =
        mysqli_stmt_get_result(
            $existingStmt
        );
    /*
    |--------------------------------------------------------------------------
    | Buat array competency existing
    |--------------------------------------------------------------------------
    |
    | Contoh:
    |
    | [
    |     1 => 10,
    |     2 => 11
    | ]
    |
    | Artinya:
    |
    | competency_id 1 memiliki employee_competency ID 10
    | competency_id 2 memiliki employee_competency ID 11
    |
    |--------------------------------------------------------------------------
    */
    $existingCompetencies = [];
    while (
        $row =
        mysqli_fetch_assoc(
            $existingResult
        )
    ) {
        $existingCompetencies[
            (int) $row['competency_id']
        ] =
            (int) $row['id'];
    }
    /*
    |--------------------------------------------------------------------------
    | 1. NONAKTIFKAN competency yang sudah tidak dipilih
    |--------------------------------------------------------------------------
    |
    | Tidak menghapus baris (riwayat training/sertifikat tetap aman),
    | hanya menandai is_active = 0. Sesuai dengan pesan konfirmasi
    | di halaman employee_competencies.php.
    |
    */
    foreach (
        $existingCompetencies
        as $competency_id => $assignment_id
    ) {
        if (
            !in_array(
                $competency_id,
                $selectedCompetencyIds
            )
        ) {
            $deactivateQuery = "
                UPDATE employee_competencies
                SET is_active = 0
                WHERE id = ?
                AND employee_id = ?
            ";
            $deactivateStmt =
                mysqli_prepare(
                    $conn,
                    $deactivateQuery
                );
            mysqli_stmt_bind_param(
                $deactivateStmt,
                "ii",
                $assignment_id,
                $employee_id
            );
            mysqli_stmt_execute(
                $deactivateStmt
            );
        }
    }
    /*
    |--------------------------------------------------------------------------
    | 2. AKTIFKAN KEMBALI atau TAMBAHKAN competency yang dipilih
    |--------------------------------------------------------------------------
    */
    foreach (
        $selectedCompetencyIds
        as $competency_id
    ) {
        /*
        | Jika competency sudah pernah ada (aktif atau sebelumnya
        | dinonaktifkan), aktifkan kembali. Detail competency
        | (training_date, sertifikat, dll) akan tetap aman.
        */
        if (
            isset(
            $existingCompetencies[
                $competency_id
            ]
        )
        ) {
            $reactivateQuery = "
                UPDATE employee_competencies
                SET is_active = 1
                WHERE id = ?
                AND employee_id = ?
            ";
            $reactivateStmt =
                mysqli_prepare(
                    $conn,
                    $reactivateQuery
                );
            mysqli_stmt_bind_param(
                $reactivateStmt,
                "ii",
                $existingCompetencies[$competency_id],
                $employee_id
            );
            mysqli_stmt_execute(
                $reactivateStmt
            );
            continue;
        }
        /*
        |--------------------------------------------------------------------------
        | Insert hanya competency yang benar-benar baru
        |--------------------------------------------------------------------------
        |
        | Isi trainer/provider/authorizer diambil dari default info training
        | milik competency, supaya tidak perlu diketik ulang satu per satu.
        | Nilai ini tetap bisa diedit per employee.
        |
        */
        $defaults = $competencyDefaults[$competency_id] ?? [];
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
        $insertQuery = "
            INSERT INTO employee_competencies
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
            VALUES
            (
                ?,
                ?,
                'NOT_TAKEN',
                1,
                ?,
                ?,
                ?,
                ?,
                ?,
                ?
            )
        ";
        $insertStmt =
            mysqli_prepare(
                $conn,
                $insertQuery
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
        mysqli_stmt_execute(
            $insertStmt
        );
    }
    /*
    |--------------------------------------------------------------------------
    | Commit
    |--------------------------------------------------------------------------
    */
    mysqli_commit($conn);
    /*
    |--------------------------------------------------------------------------
    | Redirect
    |--------------------------------------------------------------------------
    */
    header(
        "Location: employee_competencies.php?id="
        . $employee_id
        . "&success=1"
    );
    exit;
} catch (Exception $e) {
    /*
    |--------------------------------------------------------------------------
    | Jika error → batalkan semua perubahan
    |--------------------------------------------------------------------------
    */
    mysqli_rollback($conn);
    die(

        "Gagal menyimpan competency: "
        . $e->getMessage()
    );
}