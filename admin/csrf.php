<?php
/*
|--------------------------------------------------------------------------
| CSRF protection
|--------------------------------------------------------------------------
|
| Sinkron-token sederhana: satu token per sesi, dibuat sekali dan dipakai
| ulang untuk semua form. Panggil file ini SETELAH session_start().
|
| Pemakaian:
|   - Di dalam setiap <form method="POST">   : <?php echo csrf_input(); ?>
|   - Di awal setiap handler yang menulis data: csrf_validate();
|
*/
if (session_status() === PHP_SESSION_ACTIVE && empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

function csrf_token(): string
{
    return $_SESSION['csrf_token'] ?? '';
}

function csrf_input(): string
{
    return '<input type="hidden" name="csrf_token" value="'
        . htmlspecialchars(csrf_token(), ENT_QUOTES) . '">';
}

/*
| Verifikasi token dari $_POST (atau $_GET untuk aksi berbasis link).
| Kalau tidak cocok, hentikan request -- jangan sampai aksi tulis jalan.
*/
function csrf_validate(): void
{
    $sent = $_POST['csrf_token'] ?? $_GET['csrf_token'] ?? '';
    $known = $_SESSION['csrf_token'] ?? '';
    if (!is_string($sent) || $sent === '' || $known === '' || !hash_equals($known, $sent)) {
        http_response_code(419);
        die('Permintaan ditolak: token keamanan (CSRF) tidak valid atau sesi kedaluwarsa. '
            . 'Muat ulang halaman lalu coba lagi.');
    }
}
