<?php
require_once "auth.php";
require_once "../config/database.php";

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header("Location: position_requirements.php");
    exit;
}
csrf_validate();
require_writer();
$position = trim($_POST['position'] ?? '');
if ($position === '') {
    die("Posisi wajib diisi.");
}
$competencies = $_POST['competencies'] ?? [];
if (!is_array($competencies)) {
    $competencies = [];
}
$selectedIds = array_values(array_unique(array_filter(
    array_map('intval', $competencies),
    function ($value) {
        return $value > 0;
    }
)));

mysqli_begin_transaction($conn);
try {
    $existingStmt = mysqli_prepare(
        $conn,
        "SELECT competency_id FROM position_requirements WHERE position = ?"
    );
    mysqli_stmt_bind_param($existingStmt, "s", $position);
    mysqli_stmt_execute($existingStmt);
    $existingResult = mysqli_stmt_get_result($existingStmt);
    $existingIds = [];
    while ($row = mysqli_fetch_assoc($existingResult)) {
        $existingIds[] = (int) $row['competency_id'];
    }

    foreach (array_diff($existingIds, $selectedIds) as $competencyId) {
        $deleteStmt = mysqli_prepare(
            $conn,
            "DELETE FROM position_requirements WHERE position = ? AND competency_id = ?"
        );
        mysqli_stmt_bind_param($deleteStmt, "si", $position, $competencyId);
        mysqli_stmt_execute($deleteStmt);
    }
    foreach (array_diff($selectedIds, $existingIds) as $competencyId) {
        $insertStmt = mysqli_prepare(
            $conn,
            "INSERT INTO position_requirements (position, competency_id) VALUES (?, ?)"
        );
        mysqli_stmt_bind_param($insertStmt, "si", $position, $competencyId);
        mysqli_stmt_execute($insertStmt);
    }

    mysqli_commit($conn);
    header("Location: position_requirements.php?position=" . urlencode($position) . "&success=1");
    exit;
} catch (Exception $e) {
    mysqli_rollback($conn);
    die("Gagal menyimpan required competency: " . $e->getMessage());
}
