<?php
require_once "config/database.php";
require_once "includes/competency_helper.php";

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header("Location: quiz.php");
    exit;
}

$id = isset($_POST['id']) ? (int) $_POST['id'] : 0;
if ($id <= 0) {
    header("Location: quiz.php");
    exit;
}

mysqli_begin_transaction($conn);
try {
    /*
    | Kunci HANYA baris employee_competencies milik user ini (bukan JOIN ke competencies),
    | supaya user lain yang mengerjakan kuis untuk kompetensi yang sama TIDAK ikut terkunci.
    */
    $lockStmt = mysqli_prepare(
        $conn,
        "SELECT
            ec.id,
            ec.competency_id,
            ec.certificate_number,
            ec.training_date,
            ec.scheduled_training_date,
            ec.attendance_confirmed,
            ec.quiz_submitted_at,
            ec.quiz_retry_until,
            ec.score,
            ec.status
        FROM employee_competencies ec
        WHERE ec.id = ?
        FOR UPDATE"
    );
    mysqli_stmt_bind_param($lockStmt, "i", $id);
    mysqli_stmt_execute($lockStmt);
    $ec = mysqli_fetch_assoc(mysqli_stmt_get_result($lockStmt));

    if (!$ec) {
        throw new Exception("Data kompetensi tidak ditemukan.");
    }

    /* Ambil metadata kompetensi tanpa lock FOR UPDATE */
    $compStmt = mysqli_prepare(
        $conn,
        "SELECT validity_months, passing_score FROM competencies WHERE id = ? LIMIT 1"
    );
    mysqli_stmt_bind_param($compStmt, "i", $ec['competency_id']);
    mysqli_stmt_execute($compStmt);
    $compData = mysqli_fetch_assoc(mysqli_stmt_get_result($compStmt)) ?: [];
    $ec['validity_months'] = $compData['validity_months'] ?? null;
    $ec['passing_score'] = $compData['passing_score'] ?? null;

    $eligibility = getQuizEligibility($conn, $ec);
    if (!$eligibility['eligible']) {
        mysqli_rollback($conn);
        if ($eligibility['reason'] === 'already_submitted') {
            /*
            | Terjadi kalau tombol submit terklik dua kali (koneksi lambat):
            | request pertama sudah berhasil menyimpan jawaban, jadi arahkan
            | ke halaman sukses yang sama alih-alih menampilkan pesan gagal.
            */
            header(
                "Location: employee.php?id=" . $id . "&quiz_success=1&score=" . (int) $ec['score']
                . "&passed=" . ($ec['status'] === 'FAILED' ? '0' : '1')
            );
            exit;
        }
        header("Location: quiz.php?id=" . $id . "&error=1");
        exit;
    }

    /*
    |--------------------------------------------------------------------------
    | Ambil soal + SEMUA pilihan jawaban sekaligus (Single Query, No N+1)
    |--------------------------------------------------------------------------
    */
    $questionStmt = mysqli_prepare(
        $conn,
        "SELECT id, allow_multiple_answers FROM competency_questions WHERE competency_id = ? ORDER BY id ASC"
    );
    mysqli_stmt_bind_param($questionStmt, "i", $ec['competency_id']);
    mysqli_stmt_execute($questionStmt);
    $questionResult = mysqli_stmt_get_result($questionStmt);
    $questions = [];
    while ($q = mysqli_fetch_assoc($questionResult)) {
        $questions[] = $q;
    }
    if (count($questions) === 0) {
        throw new Exception("Soal kuis tidak ditemukan.");
    }

    $choiceStmt = mysqli_prepare(
        $conn,
        "SELECT c.id, c.question_id, c.is_correct, c.points
        FROM competency_question_choices c
        INNER JOIN competency_questions q ON q.id = c.question_id
        WHERE q.competency_id = ?"
    );
    mysqli_stmt_bind_param($choiceStmt, "i", $ec['competency_id']);
    mysqli_stmt_execute($choiceStmt);
    $choiceResult = mysqli_stmt_get_result($choiceStmt);
    $choicesByQuestion = [];
    while ($choice = mysqli_fetch_assoc($choiceResult)) {
        $choicesByQuestion[(int) $choice['question_id']][] = $choice;
    }

    $submittedAnswers = $_POST['answers'] ?? [];
    $totalEarned = 0;
    $totalPossible = 0;
    $answerInsertRows = [];

    foreach ($questions as $question) {
        $questionId = (int) $question['id'];
        $questionChoices = $choicesByQuestion[$questionId] ?? [];
        $correctChoiceIds = [];
        $questionMaxPoints = 0;
        $validChoiceIds = [];

        foreach ($questionChoices as $choice) {
            $validChoiceIds[] = (int) $choice['id'];
            if ((int) $choice['is_correct'] === 1) {
                $correctChoiceIds[] = (int) $choice['id'];
                $questionMaxPoints += (int) $choice['points'];
            }
        }
        $totalPossible += $questionMaxPoints;

        $rawSelected = $submittedAnswers[$questionId] ?? [];
        if (!is_array($rawSelected)) {
            $rawSelected = [$rawSelected];
        }
        $selectedChoiceIds = [];
        foreach ($rawSelected as $choiceIdRaw) {
            $choiceIdInt = (int) $choiceIdRaw;
            if (in_array($choiceIdInt, $validChoiceIds, true)) {
                $selectedChoiceIds[] = $choiceIdInt;
            }
        }

        sort($selectedChoiceIds);
        $sortedCorrect = $correctChoiceIds;
        sort($sortedCorrect);
        if ($selectedChoiceIds === $sortedCorrect) {
            $totalEarned += $questionMaxPoints;
        }

        foreach ($selectedChoiceIds as $choiceId) {
            $answerInsertRows[] = [$id, $questionId, $choiceId];
        }
    }

    $score = $totalPossible > 0 ? (int) round($totalEarned / $totalPossible * 100) : 0;

    /*
    |--------------------------------------------------------------------------
    | Simpan jawaban (audit trail) - Multi-row bulk insert
    |--------------------------------------------------------------------------
    */
    if (count($answerInsertRows) > 0) {
        $placeholders = implode(', ', array_fill(0, count($answerInsertRows), '(?, ?, ?)'));
        $types = str_repeat('iii', count($answerInsertRows));
        $flatValues = [];
        foreach ($answerInsertRows as $row) {
            $flatValues[] = $row[0];
            $flatValues[] = $row[1];
            $flatValues[] = $row[2];
        }
        $insertAnswerStmt = mysqli_prepare(
            $conn,
            "INSERT INTO employee_quiz_answers (employee_competency_id, question_id, choice_id) VALUES " . $placeholders
        );
        mysqli_stmt_bind_param($insertAnswerStmt, $types, ...$flatValues);
        mysqli_stmt_execute($insertAnswerStmt);
    }

    /*
    |--------------------------------------------------------------------------
    | Hitung training_date, expiry_date, certificate_number, status
    |--------------------------------------------------------------------------
    */
    $trainingDate = $ec['training_date'] ?: $ec['scheduled_training_date'];
    $validityMonths = !empty($ec['validity_months']) ? (int) $ec['validity_months'] : null;
    $expiryDate = null;
    if ($validityMonths !== null) {
        $expiryDateTime = new DateTime($trainingDate);
        $expiryDateTime->modify('+' . $validityMonths . ' months');
        $expiryDate = $expiryDateTime->format('Y-m-d');
    }

    $passingScore = $ec['passing_score'] !== null ? (int) $ec['passing_score'] : null;
    $status = calculateCompetencyStatus($trainingDate, $expiryDate);
    $status = applyPassingScoreGate($status, $score, $passingScore);

    $certificateNumber = $ec['certificate_number'];

    /*
    | Simpan status & nomor sertifikat dengan retry otomatis jika terjadi tabrakan nomor urut
    | (error 1062 uq_certificate_number) saat banyak peserta submit bersamaan.
    */
    $maxSaveRetries = 5;
    $saved = false;

    for ($attempt = 1; $attempt <= $maxSaveRetries; $attempt++) {
        if ($status !== 'FAILED') {
            if (empty($certificateNumber)) {
                $certificateNumber = generateCertificateNumber($conn, (int) $ec['competency_id'], $trainingDate);
            }
        } else {
            $certificateNumber = null;
        }

        $updateStmt = mysqli_prepare(
            $conn,
            "UPDATE employee_competencies
            SET training_date = ?,
                expiry_date = ?,
                certificate_number = ?,
                score = ?,
                status = ?,
                quiz_submitted_at = NOW()
            WHERE id = ?"
        );
        mysqli_stmt_bind_param(
            $updateStmt,
            "sssisi",
            $trainingDate,
            $expiryDate,
            $certificateNumber,
            $score,
            $status,
            $id
        );

        try {
            mysqli_stmt_execute($updateStmt);
            $saved = true;
            break;
        } catch (\mysqli_sql_exception $e) {
            if ($e->getCode() === 1062 && $status !== 'FAILED') {
                $certificateNumber = null; // Reset agar dibuatkan nomor sequence berikutnya
                usleep(50000); // Tunggu 50ms lalu coba lagi
                continue;
            }
            throw $e;
        }
    }

    if (!$saved) {
        throw new Exception("Gagal menyimpan hasil kuis karena antrean nomor sertifikat.");
    }

    /*
    |--------------------------------------------------------------------------
    | Simpan snapshot siklus training ini ke riwayat
    |--------------------------------------------------------------------------
    */
    recordCompetencyHistory($conn, $id, 'quiz');

    mysqli_commit($conn);
    header("Location: employee.php?id=" . $id . "&quiz_success=1&score=" . $score . "&passed=" . ($status === 'FAILED' ? '0' : '1'));
    exit;
} catch (\Throwable $e) {
    /*
    | \Throwable (bukan cuma Exception) supaya TypeError/Error teknis juga
    | tertangkap -- sebelumnya error semacam itu lolos dari catch dan bikin
    | halaman putih/500 alih-alih redirect balik ke quiz.php dengan pesan
    | gagal.
    */
    mysqli_rollback($conn);
    error_log("quiz_submit.php gagal (id={$id}): " . $e->getMessage());
    header("Location: quiz.php?id=" . $id . "&error=1");
    exit;
}
