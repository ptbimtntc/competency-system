<?php
require_once "auth.php";
require_once "../config/database.php";
require_once "../includes/competency_helper.php";
$id = isset($_GET['id'])
    ? (int) $_GET['id']
    : 0;
if ($id <= 0) {
    header(
        "Location: employees.php"
    );
    exit;
}
/*
|--------------------------------------------------------------------------
| Ambil data employee competency
|--------------------------------------------------------------------------
*/
$query = "
    SELECT
        ec.id,
        ec.employee_id,
        ec.competency_id,
        ec.training_date,
        ec.scheduled_training_date,
        ec.attendance_confirmed,
        ec.quiz_submitted_at,
        ec.trainer,
        ec.certificate_number,
        ec.issue_date,
        ec.expiry_date,
        ec.status,
        ec.score,
        ec.training_provider,
        ec.authorizer_title,
        ec.authorizer_name,
        ec.trainer_signatory_id,
        ec.authorizer_signatory_id,
        ec.notes,

        e.nik,
        e.name AS employee_name,
        e.department,
        e.position,

        c.name AS competency_name,
        c.description AS competency_description,
        c.validity_months,
        c.passing_score

    FROM employee_competencies ec
    INNER JOIN employees e
        ON e.id = ec.employee_id
    INNER JOIN competencies c
        ON c.id = ec.competency_id
    WHERE ec.id = ?
    LIMIT 1
";
$stmt = mysqli_prepare(
    $conn,
    $query
);
mysqli_stmt_bind_param(
    $stmt,
    "i",
    $id
);
mysqli_stmt_execute($stmt);
$result = mysqli_stmt_get_result(
    $stmt
);
$data = mysqli_fetch_assoc(
    $result
);
if (!$data) {
    die(
        "Data competency employee tidak ditemukan."
    );
}
/*
|--------------------------------------------------------------------------
| Halaman kembali (Back/Cancel)
|--------------------------------------------------------------------------
|
| Hanya menerima nama file .php lokal (opsional dengan query string) supaya
| tidak bisa dipakai untuk open redirect ke domain lain.
|
*/
$back = trim($_GET['back'] ?? $_POST['back'] ?? '');
$defaultBack = "employee_competencies.php?id=" . $data['employee_id'];
if (!preg_match('/^[a-zA-Z0-9_\-]+\.php(\?[a-zA-Z0-9_\-\.=&%]*)?$/', $back)) {
    $back = $defaultBack;
}
/*
|--------------------------------------------------------------------------
| Ambil daftar signatories (untuk dropdown tanda tangan)
|--------------------------------------------------------------------------
*/
$signatoryResult = mysqli_query(
    $conn,
    "SELECT id, name, title FROM signatories ORDER BY name ASC"
);
$signatories = [];
while ($row = mysqli_fetch_assoc($signatoryResult)) {
    $signatories[] = $row;
}
$error = "";
$success = "";
/*
|--------------------------------------------------------------------------
| UPDATE DATA
|--------------------------------------------------------------------------
*/
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_validate();
    require_writer();
    /*
    |--------------------------------------------------------------------------
    | Field tanggal: konversi string kosong menjadi NULL
    |--------------------------------------------------------------------------
    |
    | Kolom training_date/issue_date/expiry_date bertipe DATE dan tidak
    | menerima string kosong (''). Jika input tanggal dikosongkan di form,
    | $_POST tetap berisi '' (bukan tidak ada), jadi harus dikonversi
    | manual supaya tersimpan sebagai NULL.
    |
    */
    $training_date =
        trim($_POST['training_date'] ?? '');
    $training_date =
        $training_date === '' ? null : $training_date;
    $scheduled_training_date =
        trim($_POST['scheduled_training_date'] ?? '');
    $scheduled_training_date =
        $scheduled_training_date === '' ? null : $scheduled_training_date;
    $attendance_confirmed =
        isset($_POST['attendance_confirmed']) ? 1 : 0;
    $trainer =
        trim(
            $_POST['trainer'] ?? ''
        );
    $certificate_number =
        trim(
            $_POST['certificate_number'] ?? ''
        );
    /*
    |--------------------------------------------------------------------------
    | Generate nomor sertifikat otomatis
    |--------------------------------------------------------------------------
    |
    | Hanya digenerate sekali saat masih kosong dan training_date sudah
    | diisi. Format: PTBI/tahun/bulan romawi/kode competency/nomor urut.
    | Kalau sudah ada (baik hasil generate sebelumnya atau diisi manual),
    | tidak akan ditimpa.
    |
    */
    if ($certificate_number === '' && $training_date !== null) {
        $certificate_number = generateCertificateNumber(
            $conn,
            (int) $data['competency_id'],
            $training_date
        );
    }
    $issue_date =
        trim($_POST['issue_date'] ?? '');
    $issue_date =
        $issue_date === '' ? null : $issue_date;
    $expiry_date =
        trim($_POST['expiry_date'] ?? '');
    $expiry_date =
        $expiry_date === '' ? null : $expiry_date;
    $score =
        ($_POST['score'] ?? '') === '' ? null : (int) $_POST['score'];
    $training_provider =
        trim(
            $_POST['training_provider'] ?? ''
        );
    $authorizer_title =
        trim(
            $_POST['authorizer_title'] ?? ''
        );
    $authorizer_name =
        trim(
            $_POST['authorizer_name'] ?? ''
        );
    $trainer_signatory_id =
        ($_POST['trainer_signatory_id'] ?? '') === '' ? null : (int) $_POST['trainer_signatory_id'];
    $authorizer_signatory_id =
        ($_POST['authorizer_signatory_id'] ?? '') === '' ? null : (int) $_POST['authorizer_signatory_id'];
    $notes =
        trim(
            $_POST['notes'] ?? ''
        );
    $passing_score =
        $data['passing_score'] !== null ? (int) $data['passing_score'] : null;
    $status =
        calculateCompetencyStatusWithSchedule(
            $training_date,
            $expiry_date,
            $scheduled_training_date
        );
    $status =
        applyPassingScoreGate($status, $score, $passing_score);
    /*
    |--------------------------------------------------------------------------
    | Validasi status
    |--------------------------------------------------------------------------
    */
    $allowed_status = ['NOT_TAKEN', 'ASSIGNED', 'VALID', 'EXPIRING_SOON', 'EXPIRED', 'FAILED'];
    if (
        !in_array(
            $status,
            $allowed_status,
            true
        )
    ) {
        $error =
            "Status tidak valid.";
    }
    /*
    |--------------------------------------------------------------------------
    | Validasi tanggal
    |--------------------------------------------------------------------------
    */
    if (
        $error === '' &&
        $issue_date !== null &&
        $expiry_date !== null &&
        $expiry_date < $issue_date
    ) {
        $error =
            "Expiry date tidak boleh lebih awal dari issue date.";
    }
    if (
        $error === '' &&
        $score !== null &&
        ($score < 0 || $score > 100)
    ) {
        $error =
            "Score harus di antara 0 dan 100.";
    }
    /*
    |--------------------------------------------------------------------------
    | UPDATE
    |--------------------------------------------------------------------------
    */
    if ($error === '') {
        $updateQuery = "
            UPDATE employee_competencies
            SET
                training_date = ?,
                scheduled_training_date = ?,
                attendance_confirmed = ?,
                trainer = ?,
                certificate_number = ?,
                issue_date = ?,
                expiry_date = ?,
                status = ?,
                score = ?,
                training_provider = ?,
                authorizer_title = ?,
                authorizer_name = ?,
                trainer_signatory_id = ?,
                authorizer_signatory_id = ?,
                notes = ?
            WHERE id = ?
        ";
        $updateStmt =
            mysqli_prepare(
                $conn,
                $updateQuery
            );
        mysqli_stmt_bind_param(
            $updateStmt,
            "ssisssssisssiisi",
            $training_date,
            $scheduled_training_date,
            $attendance_confirmed,
            $trainer,
            $certificate_number,
            $issue_date,
            $expiry_date,
            $status,
            $score,
            $training_provider,
            $authorizer_title,
            $authorizer_name,
            $trainer_signatory_id,
            $authorizer_signatory_id,
            $notes,
            $id
        );
        if (
            mysqli_stmt_execute(
                $updateStmt
            )
        ) {
            /*
            |--------------------------------------------------------------------------
            | Simpan snapshot ke riwayat (hanya kalau training sudah tercatat)
            |--------------------------------------------------------------------------
            */
            if ($training_date !== null) {
                recordCompetencyHistory(
                    $conn,
                    $id,
                    'manual',
                    $_SESSION['admin_name'] ?? null
                );
            }
            /*
            |--------------------------------------------------------------------------
            | Redirect agar POST tidak dikirim ulang ketika refresh
            |--------------------------------------------------------------------------
            */
            header(
                "Location: employee_competency_edit.php?id="
                . $id
                . "&success=1&back="
                . urlencode($back)
            );
            exit;
        } else {
            $error =
                "Data competency gagal diperbarui.";
        }
    }
}
/*
|--------------------------------------------------------------------------
| Success message
|--------------------------------------------------------------------------
*/
if (
    isset($_GET['success']) &&
    $_GET['success'] === '1'
) {
    $success =
        "Data competency berhasil diperbarui.";
}
?>

<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>
        Competency Detail
    </title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="../assets/css/style.css">
</head>

<body class="admin-page">
    <nav class="admin-navbar">
        <div class="admin-brand">
            <img src="../assets/images/Bekaert_logo_neg_RGB.png" alt="Bekaert" class="brand-logo">
            <span>
                Competency System
            </span>
        </div>
        <div class="admin-user">
            <span>
                <?php
                echo htmlspecialchars(
                    $_SESSION['admin_name']
                );
                ?>
            </span>
            <a href="logout.php">
                Logout
            </a>
        </div>
    </nav>
    <div class="admin-container">
        <div class="page-header">
            <div>
                <h1>
                    Competency Detail
                </h1>
                <p>
                    Update employee competency information
                </p>
            </div>
            <div class="d-flex gap-2">
                <a href="competency_history.php?employee_competency_id=<?php echo (int) $id; ?>&back=<?php
                    echo urlencode('employee_competency_edit.php?id=' . $id . '&back=' . urlencode($back));
                    ?>" class="btn btn-outline-secondary">
                    Riwayat
                </a>
                <a href="<?php echo htmlspecialchars($back); ?>"
                    class="btn btn-outline-secondary">
                    ← Back
                </a>
            </div>
        </div>
        <?php if ($success !== ''): ?>
            <div class="alert alert-success">
                <?php
                echo htmlspecialchars(
                    $success
                );
                ?>
            </div>
        <?php endif; ?>
        <?php if ($error !== ''): ?>
            <div class="alert alert-danger">
                <?php
                echo htmlspecialchars(
                    $error
                );
                ?>
            </div>
        <?php endif; ?>
        <!-- EMPLOYEE INFORMATION -->
        <div class="employee-info-card mb-4">
            <div>
                <small>
                    Employee
                </small>
                <h3>
                    <?php
                    echo htmlspecialchars(
                        $data['employee_name']
                    );
                    ?>
                </h3>
            </div>
            <div>
                <small>
                    NIK
                </small>
                <strong>
                    <?php
                    echo htmlspecialchars(
                        $data['nik']
                    );
                    ?>
                </strong>
            </div>
            <div>
                <small>
                    Department
                </small>
                <strong>
                    <?php
                    echo htmlspecialchars(
                        $data['department']
                    );
                    ?>
                </strong>
            </div>
            <div>
                <small>
                    Position
                </small>
                <strong>
                    <?php
                    echo htmlspecialchars(
                        $data['position']
                    );
                    ?>
                </strong>
            </div>
        </div>
        <!-- COMPETENCY -->
        <div class="form-card">
            <div class="mb-4">
                <small class="text-muted">
                    Competency
                </small>
                <h2 class="mb-2">
                    <?php
                    echo htmlspecialchars(
                        $data['competency_name']
                    );
                    ?>
                </h2>
                <p class="text-muted">
                    <?php
                    echo htmlspecialchars(
                        $data['competency_description']
                        ?? ''
                    );
                    ?>
                </p>
            </div>
            <?php if (!empty($data['quiz_submitted_at'])): ?>
                <div class="alert alert-info d-flex justify-content-between align-items-center">
                    <div>
                        Kuis untuk kompetensi ini sudah dikerjakan pada
                        <strong><?php echo date('d M Y H:i', strtotime($data['quiz_submitted_at'])); ?></strong>.
                        Jawaban terkunci, tidak bisa dikerjakan ulang oleh karyawan.
                    </div>
                    <?php if (admin_can_write()): ?>
                        <form method="POST" action="employee_competency_quiz_reset.php"
                            class="flex-shrink-0 ms-3"
                            onsubmit="return confirm('Reset kuis? Jawaban yang tersimpan akan dihapus dan karyawan bisa mengerjakan ulang setelah dijadwalkan ulang.');">
                            <?php echo csrf_input(); ?>
                            <input type="hidden" name="id" value="<?php echo (int) $id; ?>">
                            <input type="hidden" name="back" value="<?php echo htmlspecialchars($back); ?>">
                            <button type="submit" class="btn btn-sm btn-outline-warning">
                                Reset Quiz
                            </button>
                        </form>
                    <?php endif; ?>
                </div>
            <?php endif; ?>
            <hr>
            <form method="POST">
                <?php echo csrf_input(); ?>
                <input type="hidden" name="back" value="<?php echo htmlspecialchars($back); ?>">
                <div class="row g-3">
                    <!-- SCHEDULED TRAINING DATE -->
                    <div class="col-md-6">
                        <label class="form-label">
                            Scheduled Training Date
                        </label>
                        <input type="date" name="scheduled_training_date" class="form-control" value="<?php echo htmlspecialchars(
                            $data['scheduled_training_date'] ?? ''
                        ); ?>">
                        <div class="form-text">
                            Tanggal training direncanakan. Kalau diisi (dan Training Date di bawah masih kosong),
                            status kompetensi jadi <strong>Assigned</strong> dan tombol kuis akan aktif
                            tepat di tanggal ini untuk karyawan.
                        </div>
                    </div>
                    <!-- ATTENDANCE CONFIRMED -->
                    <div class="col-md-6">
                        <label class="form-label">
                            Attendance
                        </label>
                        <div class="form-check mt-2">
                            <input class="form-check-input" type="checkbox" name="attendance_confirmed" id="attendanceConfirmed"
                                value="1" <?php echo (int) ($data['attendance_confirmed'] ?? 0) === 1 ? 'checked' : ''; ?>>
                            <label class="form-check-label" for="attendanceConfirmed">
                                Karyawan hadir pada training ini
                            </label>
                        </div>
                        <div class="form-text">
                            Wajib dicentang supaya tombol kuis bisa diklik karyawan.
                        </div>
                    </div>
                    <!-- TRAINING DATE -->
                    <div class="col-md-6">
                        <label class="form-label">
                            Training Date
                        </label>
                        <input type="date" name="training_date" id="trainingDateInput" class="form-control" value="<?php echo htmlspecialchars(
                            $data['training_date'] ?? ''
                        ); ?>">
                        <div class="form-text">
                            Tanggal training SUDAH SELESAI dilaksanakan (diisi otomatis saat karyawan submit kuis,
                            atau isi manual untuk entry data lama tanpa kuis).
                        </div>
                    </div>
                    <!-- TRAINER -->
                    <div class="col-md-6">
                        <label class="form-label">
                            Trainer
                        </label>
                        <input type="text" name="trainer" class="form-control" placeholder="Nama trainer" value="<?php echo htmlspecialchars(
                            $data['trainer'] ?? ''
                        ); ?>">
                    </div>
                    <!-- CERTIFICATE NUMBER -->
                    <div class="col-md-6">
                        <label class="form-label">
                            Certificate Number
                        </label>
                        <input type="text" name="certificate_number" class="form-control"
                            placeholder="Otomatis saat disimpan (PTBI/tahun/bulan romawi/kode/nomor urut)"
                            value="<?php echo htmlspecialchars(
                                $data['certificate_number'] ?? ''
                            ); ?>">
                        <div class="form-text">
                            Kosongkan untuk digenerate otomatis (format: PTBI/2026/VIII/ELE/00001) saat Training Date sudah diisi.
                            Sekali digenerate, tidak akan berubah lagi meski disimpan ulang.
                        </div>
                    </div>
                    <!-- SCORE -->
                    <div class="col-md-6">
                        <label class="form-label">
                            Score
                        </label>
                        <input type="number" name="score" class="form-control" min="0" max="100"
                            placeholder="0-100" value="<?php echo htmlspecialchars(
                                (string) ($data['score'] ?? '')
                            ); ?>">
                        <div class="form-text">
                            <?php if ($data['passing_score'] !== null): ?>
                                Nilai kelulusan minimum untuk kompetensi ini:
                                <strong><?php echo (int) $data['passing_score']; ?></strong>.
                                Di bawah itu status otomatis jadi <strong>Failed</strong>, bukan Valid.
                            <?php else: ?>
                                Kompetensi ini belum punya nilai kelulusan minimum, status tidak digate oleh skor.
                            <?php endif; ?>
                        </div>
                    </div>
                    <!-- TRAINING PROVIDER -->
                    <div class="col-md-6">
                        <label class="form-label">
                            Training Provider
                        </label>
                        <input type="text" name="training_provider" class="form-control"
                            placeholder="Nama provider training" value="<?php echo htmlspecialchars(
                                $data['training_provider'] ?? ''
                            ); ?>">
                    </div>
                    <!-- AUTHORIZER TITLE -->
                    <div class="col-md-6">
                        <label class="form-label">
                            Authorizer Title (Certificate)
                        </label>
                        <input type="text" name="authorizer_title" class="form-control" list="authorizerTitleOptions"
                            placeholder="Contoh: Maintenance Manager" value="<?php echo htmlspecialchars(
                                $data['authorizer_title'] ?? 'Maintenance Manager'
                            ); ?>">
                        <datalist id="authorizerTitleOptions">
                            <option value="Maintenance Manager">
                            <option value="Safety Manager">
                            <option value="Quality Manager">
                            <option value="Production Manager">
                            <option value="HR Manager">
                        </datalist>
                        <div class="form-text">
                            Jabatan pengesah yang tampil di sertifikat. Boleh diketik bebas sesuai jenis training.
                        </div>
                    </div>
                    <!-- AUTHORIZER NAME -->
                    <div class="col-md-6">
                        <label class="form-label">
                            Authorizer Name (Certificate)
                        </label>
                        <input type="text" name="authorizer_name" class="form-control"
                            placeholder="Nama pengesah sertifikat" value="<?php echo htmlspecialchars(
                                $data['authorizer_name'] ?? ''
                            ); ?>">
                    </div>
                    <!-- TRAINER SIGNATURE -->
                    <div class="col-md-6">
                        <label class="form-label">
                            Trainer Signature (Certificate)
                        </label>
                        <select name="trainer_signatory_id" class="form-control">
                            <option value="">
                                -- Tanpa tanda tangan --
                            </option>
                            <?php foreach ($signatories as $signatory): ?>
                                <option value="<?php echo $signatory['id']; ?>" <?php
                                    echo (int) ($data['trainer_signatory_id'] ?? 0) === (int) $signatory['id']
                                        ? 'selected'
                                        : '';
                                    ?>>
                                    <?php
                                    echo htmlspecialchars(
                                        $signatory['name']
                                        . (!empty($signatory['title']) ? ' - ' . $signatory['title'] : '')
                                    );
                                    ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                        <div class="form-text">
                            Opsional. Jika tidak dipilih, sistem otomatis mencari signatory dengan nama
                            yang sama persis dengan field Trainer di atas.
                            <a href="signatories.php" target="_blank">Kelola signatories</a>
                        </div>
                    </div>
                    <!-- AUTHORIZER SIGNATURE -->
                    <div class="col-md-6">
                        <label class="form-label">
                            Authorizer Signature (Certificate)
                        </label>
                        <select name="authorizer_signatory_id" class="form-control">
                            <option value="">
                                -- Tanpa tanda tangan --
                            </option>
                            <?php foreach ($signatories as $signatory): ?>
                                <option value="<?php echo $signatory['id']; ?>" <?php
                                    echo (int) ($data['authorizer_signatory_id'] ?? 0) === (int) $signatory['id']
                                        ? 'selected'
                                        : '';
                                    ?>>
                                    <?php
                                    echo htmlspecialchars(
                                        $signatory['name']
                                        . (!empty($signatory['title']) ? ' - ' . $signatory['title'] : '')
                                    );
                                    ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                        <div class="form-text">
                            Opsional. Jika tidak dipilih, sistem otomatis mencari signatory dengan nama
                            yang sama persis dengan field Authorizer Name di atas.
                        </div>
                    </div>
                    <!-- STATUS -->
                    <div class="col-md-6">
                        <label class="form-label">
                            Status
                        </label>
                        <div class="col-md-6">
                            <label class="form-label">
                                Current Status
                            </label>
                            <?php
                            $currentStatus =
                                calculateCompetencyStatusWithSchedule(
                                    $data['training_date'],
                                    $data['expiry_date'],
                                    $data['scheduled_training_date']
                                );
                            $currentStatus =
                                applyPassingScoreGate(
                                    $currentStatus,
                                    $data['score'] !== null ? (int) $data['score'] : null,
                                    $data['passing_score'] !== null ? (int) $data['passing_score'] : null
                                );
                            ?>
                            <?php if ($currentStatus === 'VALID'): ?>
                                <div>
                                    <span class="badge text-bg-success fs-6">
                                        Valid
                                    </span>
                                </div>
                            <?php elseif (
                                $currentStatus === 'EXPIRING_SOON'
                            ): ?>
                                <div>
                                    <span class="badge text-bg-warning fs-6">
                                        Expiring Soon
                                    </span>
                                </div>
                            <?php elseif (
                                $currentStatus === 'EXPIRED'
                            ): ?>
                                <div>
                                    <span class="badge text-bg-danger fs-6">
                                        Expired
                                    </span>
                                </div>
                            <?php elseif (
                                $currentStatus === 'FAILED'
                            ): ?>
                                <div>
                                    <span class="badge text-bg-danger fs-6">
                                        Failed
                                    </span>
                                </div>
                            <?php elseif (
                                $currentStatus === 'ASSIGNED'
                            ): ?>
                                <div>
                                    <span class="badge text-bg-info fs-6">
                                        Assigned
                                    </span>
                                </div>
                            <?php else: ?>
                                <div>
                                    <span class="badge text-bg-secondary fs-6">
                                        Not Taken
                                    </span>
                                </div>
                            <?php endif; ?>
                            <div class="form-text">
                                Status is calculated automatically.
                            </div>
                        </div>
                    </div>
                    <!-- ISSUE DATE -->
                    <div class="col-md-6">
                        <label class="form-label">
                            Issue Date
                        </label>
                        <input type="date" name="issue_date" class="form-control" value="<?php echo htmlspecialchars(
                            $data['issue_date'] ?? ''
                        ); ?>">
                    </div>
                    <!-- EXPIRY DATE -->
                    <div class="col-md-6">
                        <label class="form-label">
                            Expiry Date
                        </label>
                        <input type="date" name="expiry_date" id="expiryDateInput" class="form-control" value="<?php echo htmlspecialchars(
                            $data['expiry_date'] ?? ''
                        ); ?>">
                        <div class="form-text">
                            Otomatis terisi (masa berlaku
                            <?php echo (int) ($data['validity_months'] ?? 12); ?> bulan
                            sejak training date) jika masih kosong. Boleh diubah manual.
                        </div>
                    </div>
                    <!-- NOTES -->
                    <div class="col-12">
                        <label class="form-label">
                            Notes
                        </label>
                        <textarea name="notes" class="form-control" rows="5" placeholder="Additional notes..."><?php
                        echo htmlspecialchars(
                            $data['notes'] ?? ''
                        );
                        ?></textarea>
                    </div>
                </div>
                <hr class="my-4">
                <div class="d-flex gap-2 align-items-center">
                    <?php if (admin_can_write()): ?>
                        <button type="submit" class="btn btn-primary">
                            Save Changes
                        </button>
                    <?php else: ?>
                        <span class="text-muted">Akun read-only &mdash; perubahan tidak bisa disimpan.</span>
                    <?php endif; ?>
                    <a href="<?php echo htmlspecialchars($back); ?>"
                        class="btn btn-outline-secondary">
                        Cancel
                    </a>
                </div>
            </form>
        </div>
    </div>
    <script>
        (function () {
            var validityMonths = <?php echo (int) ($data['validity_months'] ?? 12); ?>;
            var trainingDateInput = document.getElementById('trainingDateInput');
            var expiryDateInput = document.getElementById('expiryDateInput');
            if (!trainingDateInput || !expiryDateInput) {
                return;
            }
            trainingDateInput.addEventListener('change', function () {
                /*
                | Hanya auto-isi jika expiry date masih kosong,
                | supaya tidak menimpa tanggal yang sudah diedit manual.
                */
                if (expiryDateInput.value !== '' || trainingDateInput.value === '') {
                    return;
                }
                var parts = trainingDateInput.value.split('-');
                var date = new Date(
                    parseInt(parts[0], 10),
                    parseInt(parts[1], 10) - 1,
                    parseInt(parts[2], 10)
                );
                date.setMonth(date.getMonth() + validityMonths);
                var yyyy = date.getFullYear();
                var mm = String(date.getMonth() + 1).padStart(2, '0');
                var dd = String(date.getDate()).padStart(2, '0');
                expiryDateInput.value = yyyy + '-' + mm + '-' + dd;
            });
        })();
    </script>
</body>

</html>