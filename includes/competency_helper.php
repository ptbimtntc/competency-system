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
        'VALID' => 'Valid',
        'EXPIRING_SOON' => 'Expiring Soon',
        'EXPIRED' => 'Expired',
        default => 'Not Taken',
    };
}

function competencyStatusColorClass(string $status): string
{
    return match ($status) {
        'VALID' => 'status-pill-valid',
        'EXPIRING_SOON' => 'status-pill-warning',
        'EXPIRED' => 'status-pill-danger',
        default => 'status-pill-secondary',
    };
}

function competencyStatusIcon(string $status): string
{
    return match ($status) {
        'VALID' => '✓',
        'EXPIRING_SOON' => '⚠',
        'EXPIRED' => '✕',
        default => '•',
    };
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