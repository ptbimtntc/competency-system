<?php

$host = "localhost";
$user = "root";
$password = "";
$database = "bekaert_competency";

$conn = mysqli_connect(
    $host,
    $user,
    $password,
    $database
);

if (!$conn) {
    die("Koneksi database gagal: " . mysqli_connect_error());
}

mysqli_set_charset($conn, "utf8mb4");

/*
|--------------------------------------------------------------------------
| Kedaluwarsakan jendela retry kuis (FAILED -> ASSIGNED -> FAILED lagi
| kalau tidak dikerjakan dalam 1 jam) sekali di awal tiap request, supaya
| semua halaman melihat status yang konsisten tanpa perlu cron job.
|--------------------------------------------------------------------------
*/
require_once __DIR__ . "/../includes/competency_helper.php";
sweepExpiredQuizRetries($conn);