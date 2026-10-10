<?php
/**
 * One-off backfill: populate employees.supervisor_nik from the free-text
 * employees.supervisor name, where the name matches exactly one active
 * employee. Run once via: php database/migrations/backfill_supervisor_nik.php
 */
require_once __DIR__ . "/../../config/database.php";

$query = "
    SELECT id, nik, name, supervisor
    FROM employees
    WHERE is_deleted = 0
      AND supervisor IS NOT NULL AND supervisor <> ''
      AND supervisor_nik IS NULL
";
$result = mysqli_query($conn, $query);

$updated = 0;
$unresolved = [];

while ($row = mysqli_fetch_assoc($result)) {
    $nameQuery = "
        SELECT nik FROM employees
        WHERE is_deleted = 0 AND LOWER(TRIM(name)) = LOWER(TRIM(?))
    ";
    $stmt = mysqli_prepare($conn, $nameQuery);
    mysqli_stmt_bind_param($stmt, "s", $row['supervisor']);
    mysqli_stmt_execute($stmt);
    $matches = mysqli_fetch_all(mysqli_stmt_get_result($stmt), MYSQLI_ASSOC);

    if (count($matches) === 1) {
        $supervisorNik = $matches[0]['nik'];
        $updateStmt = mysqli_prepare($conn, "UPDATE employees SET supervisor_nik = ? WHERE id = ?");
        mysqli_stmt_bind_param($updateStmt, "si", $supervisorNik, $row['id']);
        mysqli_stmt_execute($updateStmt);
        $updated++;
    } else {
        $unresolved[] = [
            'nik' => $row['nik'],
            'name' => $row['name'],
            'supervisor_text' => $row['supervisor'],
            'match_count' => count($matches),
        ];
    }
}

echo "Backfill selesai.\n";
echo "Berhasil diisi otomatis: {$updated}\n";
echo "Perlu diisi manual (" . count($unresolved) . "):\n";
foreach ($unresolved as $u) {
    echo "  - NIK {$u['nik']} ({$u['name']}): supervisor \"{$u['supervisor_text']}\" -> {$u['match_count']} match\n";
}
