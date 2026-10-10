<?php

session_start();

/*
|--------------------------------------------------------------------------
| Hapus hanya session portal (biarkan session admin, jika ada, tetap aktif)
|--------------------------------------------------------------------------
*/
foreach ($_SESSION as $key => $value) {
    if (strpos($key, 'portal_') === 0) {
        unset($_SESSION[$key]);
    }
}

header("Location: login.php");
exit;
