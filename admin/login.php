<?php

session_start();

require_once "../config/database.php";


/*
|--------------------------------------------------------------------------
| Jika sudah login, langsung ke dashboard
|--------------------------------------------------------------------------
*/

if (isset($_SESSION['admin_id'])) {

    header("Location: dashboard.php");

    exit;

}


$error = "";


/*
|--------------------------------------------------------------------------
| Proses Login
|--------------------------------------------------------------------------
*/

if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    $username = trim($_POST['username'] ?? '');
    $password = $_POST['password'] ?? '';


    if ($username === '' || $password === '') {

        $error = "Username dan password wajib diisi.";

    } else {

        $query = "
            SELECT
                id,
                username,
                password,
                name
            FROM admins
            WHERE username = ?
            LIMIT 1
        ";


        $stmt = mysqli_prepare(
            $conn,
            $query
        );


        mysqli_stmt_bind_param(
            $stmt,
            "s",
            $username
        );


        mysqli_stmt_execute($stmt);


        $result = mysqli_stmt_get_result($stmt);


        $admin = mysqli_fetch_assoc($result);


        /*
        |--------------------------------------------------------------------------
        | Verifikasi Password
        |--------------------------------------------------------------------------
        */

        if (
            $admin &&
            password_verify(
                $password,
                $admin['password']
            )
        ) {

            /*
            | Regenerate session ID
            | untuk keamanan
            */

            session_regenerate_id(true);


            $_SESSION['admin_id'] =
                $admin['id'];

            $_SESSION['admin_username'] =
                $admin['username'];

            $_SESSION['admin_name'] =
                $admin['name'];


            header("Location: dashboard.php");

            exit;

        } else {

            $error =
                "Username atau password salah.";

        }

    }

}

?>

<!DOCTYPE html>
<html lang="en">

<head>

    <meta charset="UTF-8">

    <meta
        name="viewport"
        content="width=device-width, initial-scale=1.0"
    >

    <title>
        Admin Login - Bekaert Competency
    </title>


    <link
        href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css"
        rel="stylesheet"
    >


    <link
        rel="stylesheet"
        href="../assets/css/style.css"
    >

</head>


<body class="admin-login-page">


<div class="login-card">


    <div class="login-logo">

        <img src="../assets/images/Bekaert_logo_pos_RGB.png" alt="Bekaert" class="brand-logo">

    </div>


    <div class="login-title">

        COMPETENCY ADMIN

    </div>


    <?php if ($error !== ''): ?>

        <div class="alert alert-danger">

            <?php
            echo htmlspecialchars($error);
            ?>

        </div>

    <?php endif; ?>


    <form
        method="POST"
        action=""
    >


        <div class="mb-3">

            <label class="form-label">

                Username

            </label>


            <input
                type="text"
                name="username"
                class="form-control"
                autocomplete="username"
                required
            >

        </div>


        <div class="mb-4">

            <label class="form-label">

                Password

            </label>


            <input
                type="password"
                name="password"
                class="form-control"
                autocomplete="current-password"
                required
            >

        </div>


        <button
            type="submit"
            class="btn btn-primary w-100"
        >

            LOGIN

        </button>


    </form>


    <div class="login-footer">

        Employee Competency Verification System

    </div>


</div>


</body>

</html>