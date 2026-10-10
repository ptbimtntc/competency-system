<?php
function calculateCompetencyStatus(
    ?string $training_date,
    ?string $expiry_date
): string {
    /*
    |--------------------------------------------------------------------------
    | Belum ada training
    |--------------------------------------------------------------------------
    */
    if (
        empty($training_date)
    ) {
        return 'NOT_TAKEN';
    }
    /*
    |--------------------------------------------------------------------------
    | Belum ada expiry date
    |--------------------------------------------------------------------------
    */
    if (
        empty($expiry_date)
    ) {
        return 'VALID';
    }
    /*
    |--------------------------------------------------------------------------
    | Tanggal hari ini
    |--------------------------------------------------------------------------
    */
    $today =
        new DateTime();
    $expiry =
        new DateTime(
            $expiry_date
        );
    /*
    |--------------------------------------------------------------------------
    | Sudah expired
    |--------------------------------------------------------------------------
    */
    if (
        $expiry < $today
    ) {
        return 'EXPIRED';
    }
    /*
    |--------------------------------------------------------------------------
    | Hitung selisih hari
    |--------------------------------------------------------------------------
    */
    $difference =
        $today->diff(
            $expiry
        );
    $daysRemaining =
        (int) $difference->format('%r%a');
    /*
    |--------------------------------------------------------------------------
    | Expired dalam 30 hari
    |--------------------------------------------------------------------------
    */
    if (
        $daysRemaining <= 30
    ) {
        return 'EXPIRING_SOON';
    }
    /*
    |--------------------------------------------------------------------------
    | Masih valid
    |--------------------------------------------------------------------------
    */
    return 'VALID';
}
/*
|--------------------------------------------------------------------------
| Label & warna status (untuk index.php dan employee.php)
|--------------------------------------------------------------------------
*/
function competencyStatusLabel(string $status): string
{
    return match ($status) {
        'ASSIGNED' => 'Assigned',
        'VALID' => 'Valid',
        'EXPIRING_SOON' => 'Expiring Soon',
        'EXPIRED' => 'Expired',
        'FAILED' => 'Failed',
        default => 'Not Taken',
    };
}

function competencyStatusColorClass(string $status): string
{
    return match ($status) {
        'ASSIGNED' => 'status-pill-info',
        'VALID' => 'status-pill-valid',
        'EXPIRING_SOON' => 'status-pill-warning',
        'EXPIRED' => 'status-pill-danger',
        'FAILED' => 'status-pill-danger',
        default => 'status-pill-secondary',
    };
}

function competencyStatusIcon(string $status): string
{
    return match ($status) {
        'ASSIGNED' => '📅',
        'VALID' => '✓',
        'EXPIRING_SOON' => '⚠',
        'EXPIRED' => '✕',
        'FAILED' => '✕',
        default => '•',
    };
}
/*
|--------------------------------------------------------------------------
| Status dengan mempertimbangkan jadwal training (scheduled_training_date)
|--------------------------------------------------------------------------
|
| Superset dari calculateCompetencyStatus(): kalau sudah ada
| scheduled_training_date tapi kuis belum pernah disubmit (quiz_submitted_at
| masih kosong), kompetensi dianggap ASSIGNED -- baik training_date-nya
| masih kosong (belum hadir) ATAUPUN sudah diisi otomatis saat attendance
| dikonfirmasi (sudah hadir, menunggu hasil kuis). Begitu kuis disubmit,
| status baru dihitung dari training_date/expiry_date seperti biasa.
|
| Kalau scheduled_training_date kosong (entry manual data lama tanpa alur
| quiz), aturan ini tidak berlaku -- status langsung dihitung dari
| training_date/expiry_date.
|
*/
function calculateCompetencyStatusWithSchedule(
    ?string $training_date,
    ?string $expiry_date,
    ?string $scheduled_training_date,
    ?string $quiz_submitted_at
): string {
    if (!empty($scheduled_training_date) && empty($quiz_submitted_at)) {
        return 'ASSIGNED';
    }
    return calculateCompetencyStatus($training_date, $expiry_date);
}
/*
|--------------------------------------------------------------------------
| Terapkan gate skor kelulusan minimum (passing_score) ke status kompetensi
|--------------------------------------------------------------------------
|
| Skor kelulusan minimum berbeda-beda per competency (diisi manual oleh
| admin lewat halaman Add/Edit Competency). Kalau training sudah selesai
| (status VALID/EXPIRING_SOON/EXPIRED) tapi skor karyawan di bawah minimum,
| status diturunkan jadi FAILED alih-alih dianggap lulus. Kalau score atau
| passing_score belum diisi, tidak ada gate yang diterapkan (kompatibel
| dengan data lama yang tidak melalui kuis).
|
*/
function applyPassingScoreGate(string $status, ?int $score, ?int $passing_score): string
{
    if (
        in_array($status, ['VALID', 'EXPIRING_SOON', 'EXPIRED'], true) &&
        $score !== null &&
        $passing_score !== null &&
        $score < $passing_score
    ) {
        return 'FAILED';
    }
    return $status;
}
/*
|--------------------------------------------------------------------------
| Cek apakah kuis kompetensi employee ini boleh dikerjakan sekarang
|--------------------------------------------------------------------------
|
| $ec harus berisi: competency_id, scheduled_training_date,
| attendance_confirmed, quiz_submitted_at, quiz_retry_until.
|
| Dipakai bersama oleh employee.php, quiz.php, dan quiz_submit.php supaya
| aturan gating-nya tidak beda-beda di tiap halaman.
|
| quiz_retry_until (diisi oleh resetEmployeeCompetencyQuiz saat admin/
| supervisor mereset kuis karyawan yang gagal) membuka jendela pengerjaan
| ulang yang tidak terikat tanggal training asli -- tapi dibatasi durasi
| (default 1 jam dari saat direset). Begitu waktunya lewat, kuis terkunci
| lagi sampai direset ulang.
|
*/
function getQuizEligibility(mysqli $conn, array $ec): array
{
    if (!empty($ec['quiz_submitted_at'])) {
        return ['eligible' => false, 'reason' => 'already_submitted'];
    }
    if (empty($ec['scheduled_training_date'])) {
        return ['eligible' => false, 'reason' => 'no_schedule'];
    }
    if ((int) ($ec['attendance_confirmed'] ?? 0) !== 1) {
        return ['eligible' => false, 'reason' => 'not_confirmed'];
    }

    $retryUntil = $ec['quiz_retry_until'] ?? null;
    $retryWindowActive = !empty($retryUntil) && new DateTime($retryUntil) >= new DateTime();

    if (!$retryWindowActive) {
        if (!empty($retryUntil)) {
            return ['eligible' => false, 'reason' => 'retry_expired'];
        }
        $today = (new DateTime())->format('Y-m-d');
        $scheduled = (new DateTime($ec['scheduled_training_date']))->format('Y-m-d');
        if ($today !== $scheduled) {
            return ['eligible' => false, 'reason' => 'wrong_date'];
        }
    }

    $countStmt = mysqli_prepare(
        $conn,
        "SELECT COUNT(*) AS total FROM competency_questions WHERE competency_id = ?"
    );
    mysqli_stmt_bind_param($countStmt, "i", $ec['competency_id']);
    mysqli_stmt_execute($countStmt);
    $total = (int) mysqli_fetch_assoc(mysqli_stmt_get_result($countStmt))['total'];
    if ($total === 0) {
        return ['eligible' => false, 'reason' => 'no_questions'];
    }
    return ['eligible' => true, 'reason' => 'ok', 'retry_until' => $retryWindowActive ? $retryUntil : null];
}
/*
|--------------------------------------------------------------------------
| Teks penjelasan untuk tiap alasan quiz belum bisa dikerjakan
|--------------------------------------------------------------------------
*/
function quizEligibilityMessage(string $reason, ?string $scheduledTrainingDate = null): string
{
    return match ($reason) {
        'already_submitted' => 'Kuis untuk kompetensi ini sudah pernah dikerjakan.',
        'no_schedule' => 'Belum ada jadwal training untuk kompetensi ini.',
        'not_confirmed' => 'Kehadiran Anda pada training ini belum dikonfirmasi oleh admin.',
        'wrong_date' => 'Kuis hanya dapat dikerjakan tepat pada tanggal training: '
            . (!empty($scheduledTrainingDate) ? date('d M Y', strtotime($scheduledTrainingDate)) : '-') . '.',
        'retry_expired' => 'Waktu pengerjaan ulang kuis (1 jam) sudah habis. Minta admin/atasan untuk mereset ulang.',
        'no_questions' => 'Soal kuis untuk kompetensi ini belum tersedia.',
        default => 'Kuis belum dapat dikerjakan saat ini.',
    };
}
/*
|--------------------------------------------------------------------------
| Reset kuis supaya karyawan bisa mengerjakan ulang
|--------------------------------------------------------------------------
|
| Menghapus jawaban & hasil kuis sebelumnya, mengembalikan status ke
| ASSIGNED, dan (kalau $retryWindowHours > 0) membuka jendela waktu
| pengerjaan ulang selama N jam dari sekarang -- tidak terikat tanggal
| training asli, supaya karyawan yang gagal bisa langsung mengerjakan
| ulang kuisnya tanpa menunggu sesi training baru. Dipakai bersama oleh
| admin/employee_competency_quiz_retry.php dan
| portal/employee_competency_quiz_retry.php.
|
*/
function resetEmployeeCompetencyQuiz(mysqli $conn, int $employeeCompetencyId, int $retryWindowHours = 0): bool
{
    $stmt = mysqli_prepare(
        $conn,
        "SELECT scheduled_training_date FROM employee_competencies WHERE id = ? LIMIT 1"
    );
    mysqli_stmt_bind_param($stmt, "i", $employeeCompetencyId);
    mysqli_stmt_execute($stmt);
    $data = mysqli_fetch_assoc(mysqli_stmt_get_result($stmt));
    if (!$data) {
        return false;
    }

    $newStatus = calculateCompetencyStatusWithSchedule(null, null, $data['scheduled_training_date'], null);
    $retryUntil = $retryWindowHours > 0
        ? (new DateTime())->modify("+{$retryWindowHours} hours")->format('Y-m-d H:i:s')
        : null;

    mysqli_begin_transaction($conn);
    try {
        $deleteStmt = mysqli_prepare(
            $conn,
            "DELETE FROM employee_quiz_answers WHERE employee_competency_id = ?"
        );
        mysqli_stmt_bind_param($deleteStmt, "i", $employeeCompetencyId);
        mysqli_stmt_execute($deleteStmt);

        $updateStmt = mysqli_prepare(
            $conn,
            "UPDATE employee_competencies
            SET quiz_submitted_at = NULL,
                training_date = NULL,
                expiry_date = NULL,
                certificate_number = NULL,
                score = NULL,
                status = ?,
                quiz_retry_until = ?
            WHERE id = ?"
        );
        mysqli_stmt_bind_param($updateStmt, "ssi", $newStatus, $retryUntil, $employeeCompetencyId);
        mysqli_stmt_execute($updateStmt);

        mysqli_commit($conn);
        return true;
    } catch (\Throwable $e) {
        mysqli_rollback($conn);
        return false;
    }
}
/*
|--------------------------------------------------------------------------
| Validasi 4 pilihan jawaban (A-D) sebuah soal
|--------------------------------------------------------------------------
|
| $choices harus berbentuk ['A' => ['text' => string, 'is_correct' => bool,
| 'points' => int], 'B' => [...], 'C' => [...], 'D' => [...]].
| Dipakai bersama oleh form Add/Edit Question dan CSV import supaya
| aturannya tidak beda-beda.
|
*/
function validateQuestionChoices(array $choices, bool $allowMultiple): ?string
{
    foreach (['A', 'B', 'C', 'D'] as $label) {
        if (!isset($choices[$label])) {
            return "Pilihan {$label} wajib ada.";
        }
        if (trim((string) $choices[$label]['text']) === '') {
            return "Teks pilihan {$label} wajib diisi.";
        }
        if ((int) $choices[$label]['points'] < 0) {
            return "Poin pilihan {$label} tidak boleh negatif.";
        }
    }
    $correctCount = 0;
    foreach (['A', 'B', 'C', 'D'] as $label) {
        if (!empty($choices[$label]['is_correct'])) {
            $correctCount++;
        }
    }
    if ($correctCount === 0) {
        return "Minimal 1 pilihan harus ditandai sebagai jawaban benar.";
    }
    if (!$allowMultiple && $correctCount > 1) {
        return "Soal single-answer hanya boleh punya 1 jawaban benar.";
    }
    return null;
}
/*
|--------------------------------------------------------------------------
| Nomor sertifikat otomatis
|--------------------------------------------------------------------------
|
| Format: PTBI/{tahun}/{bulan romawi}/{kode competency 3 huruf}/{nomor urut 5 digit}
| Contoh : PTBI/2026/VIII/ELE/00001
|
*/
function monthToRoman(int $month): string
{
    $numerals = [
        1 => 'I', 2 => 'II', 3 => 'III', 4 => 'IV',
        5 => 'V', 6 => 'VI', 7 => 'VII', 8 => 'VIII',
        9 => 'IX', 10 => 'X', 11 => 'XI', 12 => 'XII',
    ];
    return $numerals[$month] ?? 'I';
}

function generateCertificateNumber(mysqli $conn, int $competencyId, string $trainingDate): string
{
    $timestamp = strtotime($trainingDate);
    $year = date('Y', $timestamp !== false ? $timestamp : time());
    $month = (int) date('n', $timestamp !== false ? $timestamp : time());
    $roman = monthToRoman($month);

    $competencyStmt = mysqli_prepare(
        $conn,
        "SELECT code, name FROM competencies WHERE id = ? LIMIT 1"
    );
    mysqli_stmt_bind_param($competencyStmt, "i", $competencyId);
    mysqli_stmt_execute($competencyStmt);
    $competency = mysqli_fetch_assoc(mysqli_stmt_get_result($competencyStmt));

    $code = trim((string) ($competency['code'] ?? ''));
    if ($code === '') {
        $letters = strtoupper(preg_replace('/[^A-Za-z]/', '', $competency['name'] ?? 'XXX'));
        $code = str_pad(substr($letters, 0, 3), 3, 'X');
    }

    $countStmt = mysqli_prepare(
        $conn,
        "SELECT COUNT(*) AS total FROM employee_competencies
        WHERE competency_id = ? AND certificate_number IS NOT NULL AND certificate_number != ''"
    );
    mysqli_stmt_bind_param($countStmt, "i", $competencyId);
    mysqli_stmt_execute($countStmt);
    $total = (int) mysqli_fetch_assoc(mysqli_stmt_get_result($countStmt))['total'];
    $sequence = str_pad((string) ($total + 1), 5, '0', STR_PAD_LEFT);

    return "PTBI/{$year}/{$roman}/{$code}/{$sequence}";
}
/*
|--------------------------------------------------------------------------
| CSV export safety
|--------------------------------------------------------------------------
|
| Cegah CSV/formula injection saat file dibuka di Excel: value yang diawali
| =, +, -, atau @ bisa dieksekusi sebagai formula. Diberi prefix tanda kutip
| supaya dibaca sebagai teks biasa.
|
*/
function csvSafeValue(?string $value): string
{
    $value = (string) $value;
    if ($value !== '' && in_array($value[0], ['=', '+', '-', '@'], true)) {
        return "'" . $value;
    }
    return $value;
}
/*
|--------------------------------------------------------------------------
| Simpan snapshot riwayat siklus training
|--------------------------------------------------------------------------
|
| Membaca kondisi terkini satu baris employee_competencies lalu menyimpannya
| ke tabel competency_history. Dipanggil dari semua jalur yang mengubah
| data training (kuis, edit manual, import, penjadwalan ulang) supaya
| siklus lama tidak hilang saat baris employee_competencies ditimpa.
|
| Dedupe: kalau snapshot terakhir untuk assignment ini sudah identik
| (training_date, expiry_date, score, status, certificate_number sama),
| pemanggilan diabaikan supaya log tidak penuh baris kembar.
|
| Aman dipanggil walau tabel competency_history belum dibuat (migrasi belum
| dijalankan) -- error dari query ditelan diam-diam.
|
*/
function recordCompetencyHistory(
    mysqli $conn,
    int $employeeCompetencyId,
    string $source,
    ?string $recordedBy = null
): void {
    if ($employeeCompetencyId <= 0) {
        return;
    }
    try {
        $stmt = mysqli_prepare(
            $conn,
            "SELECT
                employee_id,
                competency_id,
                training_date,
                scheduled_training_date,
                expiry_date,
                score,
                status,
                certificate_number,
                trainer,
                training_provider,
                notes
            FROM employee_competencies
            WHERE id = ?
            LIMIT 1"
        );
        if ($stmt === false) {
            return;
        }
        mysqli_stmt_bind_param($stmt, "i", $employeeCompetencyId);
        mysqli_stmt_execute($stmt);
        $ec = mysqli_fetch_assoc(mysqli_stmt_get_result($stmt));
        if (!$ec) {
            return;
        }

        $lastStmt = mysqli_prepare(
            $conn,
            "SELECT training_date, expiry_date, score, status, certificate_number
            FROM competency_history
            WHERE employee_competency_id = ?
            ORDER BY id DESC
            LIMIT 1"
        );
        if ($lastStmt === false) {
            return;
        }
        mysqli_stmt_bind_param($lastStmt, "i", $employeeCompetencyId);
        mysqli_stmt_execute($lastStmt);
        $last = mysqli_fetch_assoc(mysqli_stmt_get_result($lastStmt));
        if (
            $last &&
            (string) $last['training_date'] === (string) $ec['training_date'] &&
            (string) $last['expiry_date'] === (string) $ec['expiry_date'] &&
            (string) $last['score'] === (string) $ec['score'] &&
            (string) $last['status'] === (string) $ec['status'] &&
            (string) $last['certificate_number'] === (string) $ec['certificate_number']
        ) {
            return;
        }

        $insertStmt = mysqli_prepare(
            $conn,
            "INSERT INTO competency_history
            (
                employee_competency_id,
                employee_id,
                competency_id,
                training_date,
                scheduled_training_date,
                expiry_date,
                score,
                status,
                certificate_number,
                trainer,
                training_provider,
                notes,
                source,
                recorded_by
            )
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)"
        );
        if ($insertStmt === false) {
            return;
        }
        mysqli_stmt_bind_param(
            $insertStmt,
            "iiisssisssssss",
            $employeeCompetencyId,
            $ec['employee_id'],
            $ec['competency_id'],
            $ec['training_date'],
            $ec['scheduled_training_date'],
            $ec['expiry_date'],
            $ec['score'],
            $ec['status'],
            $ec['certificate_number'],
            $ec['trainer'],
            $ec['training_provider'],
            $ec['notes'],
            $source,
            $recordedBy
        );
        mysqli_stmt_execute($insertStmt);
    } catch (\Throwable $e) {
        // Riwayat bersifat pelengkap -- jangan sampai menggagalkan alur utama.
    }
}