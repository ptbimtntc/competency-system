<?php
require_once "auth.php";
require_once "../config/database.php";
/*
|--------------------------------------------------------------------------
| Konfirmasi hadir massal dari daftar Assigned Competencies
|--------------------------------------------------------------------------
|
| Hanya mengubah attendance_confirmed -- tidak menyentuh status/training_date,
| karena status ASSIGNED dihitung dari scheduled_training_date, bukan dari
| attendance_confirmed (lihat calculateCompetencyStatusWithSchedule()).
|
*/
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header("Location: competency_status.php?status=ASSIGNED");
    exit;
}
csrf_validate();
require_writer();

$ids = $_POST['confirm_ids'] ?? [];
if (!is_array($ids)) {
    $ids = [];
}
$ids = array_values(array_unique(array_filter(array_map('intval', $ids), fn($v) => $v > 0)));

if (count($ids) > 0) {
    $placeholders = implode(',', array_fill(0, count($ids), '?'));
    $types = str_repeat('i', count($ids));
    $todayDate = date('Y-m-d');
    /*
    | training_date diisi otomatis dengan tanggal hari ini (tanggal
    | konfirmasi), hanya kalau belum pernah diisi. scheduled_training_date
    | disamakan dengan training_date (hasil akhirnya, bukan hardcode
    | $todayDate) supaya "dijadwalkan" dan "aktual" tetap sinkron. Status
    | TIDAK diubah -- tetap ASSIGNED sampai kuis disubmit (lihat
    | calculateCompetencyStatusWithSchedule()), dan status di tabel ini
    | memang masih ASSIGNED (WHERE status = 'ASSIGNED' di bawah).
    */
    $stmt = mysqli_prepare(
        $conn,
        "UPDATE employee_competencies
        SET attendance_confirmed = 1,
            training_date = COALESCE(training_date, ?),
            scheduled_training_date = training_date
        WHERE status = 'ASSIGNED' AND id IN ({$placeholders})"
    );
    mysqli_stmt_bind_param($stmt, 's' . $types, $todayDate, ...$ids);
    mysqli_stmt_execute($stmt);
}

header("Location: competency_status.php?status=ASSIGNED&success=1");
exit;
