<?php
require_once "auth.php";
require_once "../config/database.php";
require_once "../includes/competency_helper.php";
require_writer();
/*
|--------------------------------------------------------------------------
| Perbaikan sekali jalan: status "Assigned" yang nyangkut
|--------------------------------------------------------------------------
|
| Dulu, data training yang diinput manual (menu Employees) atau lewat
| Import Training CSV untuk employee_competencies yang masih punya
| scheduled_training_date (dari alur assign/quiz) tapi quiz_submitted_at
| kosong, selalu dihitung ulang jadi ASSIGNED oleh
| calculateCompetencyStatusWithSchedule() di semua halaman list -- walaupun
| training_date & score sudah diisi dan lulus KKM. Kode penyebabnya sudah
| diperbaiki di employee_competencies_import.php dan
| employee_competency_edit.php. Script ini membersihkan baris yang SUDAH
| kena masalah itu sebelum perbaikan kode di atas ada.
|
| Baris yang disentuh: training_date & score sudah terisi, quiz_submitted_at
| masih kosong (bukan hasil kuis), scheduled_training_date masih terisi
| (pemicu bug). scheduled_training_date dikosongkan, lalu status dihitung
| ulang dari training_date/expiry_date + passing_score memakai fungsi yang
| sama persis dipakai halaman lain, supaya hasilnya konsisten.
|
| Jalankan sekali lewat browser, lalu boleh dihapus.
|
*/
$preview = !isset($_POST['confirm']);
$query = "
    SELECT
        ec.id, ec.employee_id, ec.competency_id, ec.training_date,
        ec.expiry_date, ec.score, ec.status AS old_status,
        c.passing_score,
        e.nik, e.name AS employee_name,
        c.name AS competency_name
    FROM employee_competencies ec
    INNER JOIN employees e ON e.id = ec.employee_id
    INNER JOIN competencies c ON c.id = ec.competency_id
    WHERE ec.training_date IS NOT NULL
        AND ec.score IS NOT NULL
        AND ec.quiz_submitted_at IS NULL
        AND ec.scheduled_training_date IS NOT NULL
";
$rows = [];
$result = mysqli_query($conn, $query);
while ($row = mysqli_fetch_assoc($result)) {
    $passingScore = $row['passing_score'] !== null ? (int) $row['passing_score'] : null;
    $newStatus = calculateCompetencyStatus($row['training_date'], $row['expiry_date']);
    $newStatus = applyPassingScoreGate($newStatus, (int) $row['score'], $passingScore);
    $row['new_status'] = $newStatus;
    $rows[] = $row;
}

$updated = 0;
if (!$preview && count($rows) > 0) {
    csrf_validate();
    mysqli_begin_transaction($conn);
    try {
        foreach ($rows as $row) {
            $stmt = mysqli_prepare(
                $conn,
                "UPDATE employee_competencies
                SET scheduled_training_date = NULL, status = ?
                WHERE id = ?"
            );
            mysqli_stmt_bind_param($stmt, "si", $row['new_status'], $row['id']);
            mysqli_stmt_execute($stmt);
            recordCompetencyHistory($conn, (int) $row['id'], 'manual_fix', $_SESSION['admin_name'] ?? null);
            $updated++;
        }
        mysqli_commit($conn);
    } catch (\Throwable $e) {
        mysqli_rollback($conn);
        $updated = 0;
        $error = "Gagal memperbaiki data: " . $e->getMessage();
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Fix Stuck Assigned Status</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
</head>
<body class="p-4">
<div class="container">
    <h1>Perbaikan Status "Assigned" yang Nyangkut</h1>
    <?php if (!$preview): ?>
        <?php if (!empty($error)): ?>
            <div class="alert alert-danger"><?php echo htmlspecialchars($error); ?></div>
        <?php else: ?>
            <div class="alert alert-success">Selesai. <?php echo $updated; ?> baris diperbaiki.</div>
        <?php endif; ?>
    <?php else: ?>
        <p><?php echo count($rows); ?> baris ditemukan yang akan diperbaiki:</p>
        <table class="table table-bordered table-sm">
            <thead>
                <tr>
                    <th>NIK</th><th>Nama</th><th>Competency</th><th>Score</th>
                    <th>Status Lama</th><th>Status Baru</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($rows as $row): ?>
                    <tr>
                        <td><?php echo htmlspecialchars($row['nik']); ?></td>
                        <td><?php echo htmlspecialchars($row['employee_name']); ?></td>
                        <td><?php echo htmlspecialchars($row['competency_name']); ?></td>
                        <td><?php echo htmlspecialchars((string) $row['score']); ?></td>
                        <td><?php echo htmlspecialchars($row['old_status']); ?></td>
                        <td><strong><?php echo htmlspecialchars($row['new_status']); ?></strong></td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
        <?php if (count($rows) > 0): ?>
            <form method="POST">
                <?php echo csrf_input(); ?>
                <input type="hidden" name="confirm" value="1">
                <button type="submit" class="btn btn-primary">Jalankan Perbaikan</button>
            </form>
        <?php endif; ?>
    <?php endif; ?>
    <a href="employees.php" class="btn btn-outline-secondary mt-3">&larr; Back to Employees</a>
</div>
</body>
</html>
