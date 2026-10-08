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
            c.validity_months,
            c.passing_score
        FROM employee_competencies ec
        INNER JOIN competencies c ON c.id = ec.competency_id
        WHERE ec.id = ?
        FOR UPDATE"
    );
    mysqli_stmt_bind_param($lockStmt, "i", $id);
    mysqli_stmt_execute($lockStmt);
    $ec = mysqli_fetch_assoc(mysqli_stmt_get_result($lockStmt));

    if (!$ec) {
        throw new Exception("Data kompetensi tidak ditemukan.");
    }

    $eligibility = getQuizEligibility($conn, $ec);
    if (!$eligibility['eligible']) {
        mysqli_rollback($conn);
        header("Location: quiz.php?id=" . $id . "&error=1");
        exit;
    }

    /*
    |--------------------------------------------------------------------------
    | Ambil soal + pilihan jawaban benar untuk kompetensi ini
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

    $submittedAnswers = $_POST['answers'] ?? [];
    $totalEarned = 0;
    $totalPossible = 0;
    $answerInsertRows = [];

    foreach ($questions as $question) {
        $questionId = (int) $question['id'];
        $choiceStmt = mysqli_prepare(
            $conn,
            "SELECT id, is_correct, points FROM competency_question_choices WHERE question_id = ?"
        );
        mysqli_stmt_bind_param($choiceStmt, "i", $questionId);
        mysqli_stmt_execute($choiceStmt);
        $choiceResult = mysqli_stmt_get_result($choiceStmt);
        $correctChoiceIds = [];
        $questionMaxPoints = 0;
        $validChoiceIds = [];
        while ($choice = mysqli_fetch_assoc($choiceResult)) {
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
    | Simpan jawaban (audit trail)
    |--------------------------------------------------------------------------
    */
    if (count($answerInsertRows) > 0) {
        $insertAnswerStmt = mysqli_prepare(
            $conn,
            "INSERT INTO employee_quiz_answers (employee_competency_id, question_id, choice_id) VALUES (?, ?, ?)"
        );
        foreach ($answerInsertRows as $row) {
            mysqli_stmt_bind_param($insertAnswerStmt, "iii", $row[0], $row[1], $row[2]);
            mysqli_stmt_execute($insertAnswerStmt);
        }
    }

    /*
    |--------------------------------------------------------------------------
    | Hitung training_date, expiry_date, certificate_number, status
    |--------------------------------------------------------------------------
    */
    /*
    | training_date normalnya sudah diisi otomatis saat attendance
    | dikonfirmasi (tanggal admin mencentang hadir). Fallback ke
    | scheduled_training_date untuk jaga-jaga kalau karena alasan apa pun
    | kolomnya masih kosong saat quiz ini disubmit.
    */
    $trainingDate = $ec['training_date'] ?: $ec['scheduled_training_date'];
    $validityMonths = !empty($ec['validity_months']) ? (int) $ec['validity_months'] : null;
    $expiryDate = null;
    if ($validityMonths !== null) {
        $expiryDateTime = new DateTime($trainingDate);
        $expiryDateTime->modify('+' . $validityMonths . ' months');
        $expiryDate = $expiryDateTime->format('Y-m-d');
    }
    $certificateNumber = $ec['certificate_number'];
    if (empty($certificateNumber)) {
        $certificateNumber = generateCertificateNumber($conn, (int) $ec['competency_id'], $trainingDate);
    }
    $passingScore = $ec['passing_score'] !== null ? (int) $ec['passing_score'] : null;
    $status = calculateCompetencyStatus($trainingDate, $expiryDate);
    $status = applyPassingScoreGate($status, $score, $passingScore);

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
    mysqli_stmt_execute($updateStmt);

    /*
    |--------------------------------------------------------------------------
    | Simpan snapshot siklus training ini ke riwayat
    |--------------------------------------------------------------------------
    */
    recordCompetencyHistory($conn, $id, 'quiz');

    mysqli_commit($conn);
    header("Location: employee.php?id=" . $id . "&quiz_success=1&score=" . $score . "&passed=" . ($status === 'FAILED' ? '0' : '1'));
    exit;
} catch (Exception $e) {
    mysqli_rollback($conn);
    header("Location: quiz.php?id=" . $id . "&error=1");
    exit;
}
