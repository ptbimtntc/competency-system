<?php
require_once "auth.php";
require_once "../config/database.php";
$id = isset($_GET['id'])
    ? (int) $_GET['id']
    : 0;
if ($id <= 0) {
    header("Location: employees.php");
    exit;
}
/*
|--------------------------------------------------------------------------
| Ambil data employee
|--------------------------------------------------------------------------
*/
$query = "
    SELECT
        id,
        nik,
        name,
        department,
        position,
        supervisor,
        team,
        license_id,
        photo
    FROM employees
    WHERE id = ?
    LIMIT 1
";
$stmt = mysqli_prepare(
    $conn,
    $query
);
mysqli_stmt_bind_param(
    $stmt,
    "i",
    $id
);
mysqli_stmt_execute($stmt);
$result =
    mysqli_stmt_get_result($stmt);
$employee =
    mysqli_fetch_assoc($result);
if (!$employee) {
    die("Employee tidak ditemukan.");
}
$error = "";
/*
|--------------------------------------------------------------------------
| UPDATE
|--------------------------------------------------------------------------
*/
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $nik =
        trim($_POST['nik'] ?? '');
    $name =
        trim($_POST['name'] ?? '');
    $department =
        trim($_POST['department'] ?? '');
    $position =
        trim($_POST['position'] ?? '');
    $supervisor =
        trim($_POST['supervisor'] ?? '');
    $team =
        trim($_POST['team'] ?? '');
    $allowedTeams = ['A', 'B', 'C', 'D', 'NS'];
    /*
    |--------------------------------------------------------------------------
    | License ID standar: BKT-<NIK>, otomatis mengikuti NIK terbaru
    |--------------------------------------------------------------------------
    */
    $license_id = $nik !== '' ? 'BKT-' . $nik : '';
    if (
        $nik === '' ||
        $name === '' ||
        $department === '' ||
        $position === ''
    ) {
        $error =
            "NIK, nama, department, dan position wajib diisi.";
    } elseif (
        $team !== '' &&
        !in_array($team, $allowedTeams, true)
    ) {
        $error =
            "Team tidak valid.";
    } else {
        /*
        |--------------------------------------------------------------------------
        | Cek NIK duplikat
        |--------------------------------------------------------------------------
        */
        $checkQuery = "
            SELECT id
            FROM employees
            WHERE nik = ?
            AND id != ?
            LIMIT 1
        ";
        $checkStmt =
            mysqli_prepare(
                $conn,
                $checkQuery
            );
        mysqli_stmt_bind_param(
            $checkStmt,
            "si",
            $nik,
            $id
        );
        mysqli_stmt_execute(
            $checkStmt
        );
        $checkResult =
            mysqli_stmt_get_result(
                $checkStmt
            );
        if (
            mysqli_num_rows(
                $checkResult
            ) > 0
        ) {
            $error =
                "NIK tersebut sudah digunakan.";
        } else {
            /*
            |--------------------------------------------------------------------------
            | Pertahankan foto lama
            |--------------------------------------------------------------------------
            */
            $photoName =
                $employee['photo'];
            /*
            |--------------------------------------------------------------------------
            | Upload foto baru
            |--------------------------------------------------------------------------
            */
            if (
                isset($_FILES['photo']) &&
                $_FILES['photo']['error']
                    !== UPLOAD_ERR_NO_FILE
            ) {
                $file =
                    $_FILES['photo'];
                $allowedTypes = [
                    'image/jpeg',
                    'image/png',
                    'image/webp'
                ];
                $allowedExtensions = ['jpg', 'jpeg', 'png', 'webp'];
                $maxSize =
                    2 * 1024 * 1024;
                $extension =
                    strtolower(
                        pathinfo(
                            $file['name'],
                            PATHINFO_EXTENSION
                        )
                    );
                if (
                    $file['error']
                    !== UPLOAD_ERR_OK
                ) {
                    $error =
                        "Upload foto gagal.";
                } elseif (
                    !in_array(
                        $file['type'],
                        $allowedTypes,
                        true
                    ) ||
                    !in_array(
                        $extension,
                        $allowedExtensions,
                        true
                    ) ||
                    @getimagesize($file['tmp_name']) === false
                ) {
                    $error =
                        "Foto harus JPG, PNG, atau WEBP.";
                } elseif (
                    $file['size'] > $maxSize
                ) {
                    $error =
                        "Ukuran foto maksimal 2 MB.";
                } else {
                    $newPhotoName =
                        uniqid(
                            'emp_',
                            true
                        )
                        . '.'
                        . $extension;
                    $uploadDir =
                        "../uploads/employees/";
                    if (
                        !is_dir($uploadDir)
                    ) {
                        mkdir(
                            $uploadDir,
                            0755,
                            true
                        );
                    }
                    $destination =
                        $uploadDir
                        . $newPhotoName;
                    if (
                        move_uploaded_file(
                            $file['tmp_name'],
                            $destination
                        )
                    ) {
                        /*
                        |--------------------------------------------------------------------------
                        | Hapus foto lama
                        |--------------------------------------------------------------------------
                        */
                        if (
                            !empty(
                                $employee['photo']
                            )
                        ) {
                            $oldPhoto =
                                $uploadDir
                                . $employee['photo'];
                            if (
                                file_exists(
                                    $oldPhoto
                                )
                            ) {
                                unlink(
                                    $oldPhoto
                                );
                            }
                        }
                        $photoName =
                            $newPhotoName;
                    } else {
                        $error =
                            "Foto gagal disimpan.";
                    }
                }
            }
            /*
            |--------------------------------------------------------------------------
            | UPDATE DATABASE
            |--------------------------------------------------------------------------
            */
            if ($error === '') {
                $teamValue = $team !== '' ? $team : null;
                $supervisorValue = $supervisor !== '' ? $supervisor : null;
                $updateQuery = "
                    UPDATE employees
                    SET
                        nik = ?,
                        name = ?,
                        department = ?,
                        position = ?,
                        supervisor = ?,
                        team = ?,
                        license_id = ?,
                        photo = ?
                    WHERE id = ?
                ";
                $updateStmt =
                    mysqli_prepare(
                        $conn,
                        $updateQuery
                    );
                mysqli_stmt_bind_param(
                    $updateStmt,
                    "ssssssssi",
                    $nik,
                    $name,
                    $department,
                    $position,
                    $supervisorValue,
                    $teamValue,
                    $license_id,
                    $photoName,
                    $id
                );
                if (
                    mysqli_stmt_execute(
                        $updateStmt
                    )
                ) {
                    header(
                        "Location: employees.php"
                    );
                    exit;
                } else {
                    $error =
                        "Data gagal diperbarui.";
                }
            }
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
        Edit Employee
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
<body class="admin-page">
<nav class="admin-navbar">
    <div class="admin-brand">
            <img src="../assets/images/Bekaert_logo_neg_RGB.png" alt="Bekaert" class="brand-logo">
            <span>
                Competency System
            </span>
        </div>
    <div class="admin-user">
        <span>
            <?php
            echo htmlspecialchars(
                $_SESSION['admin_name']
            );
            ?>
        </span>
        <a href="logout.php">
            Logout
        </a>
    </div>
</nav>
<div class="admin-container">
    <div class="page-header">
        <div>
            <h1>
                Edit Employee
            </h1>
            <p>
                Update employee information
            </p>
        </div>
        <a
            href="employees.php"
            class="btn btn-outline-secondary"
        >
            ← Back
        </a>
    </div>
    <?php if ($error !== ''): ?>
        <div class="alert alert-danger">
            <?php
            echo htmlspecialchars($error);
            ?>
        </div>
    <?php endif; ?>
    <div class="form-card">
        <form
            method="POST"
            enctype="multipart/form-data"
        >
            <div class="row g-3">
                <div class="col-md-6">
                    <label class="form-label">
                        NIK *
                    </label>
                    <input
                        type="text"
                        name="nik"
                        id="nikInput"
                        class="form-control"
                        value="<?php echo htmlspecialchars($employee['nik']); ?>"
                        required
                    >
                </div>
                <div class="col-md-6">
                    <label class="form-label">
                        Name *
                    </label>
                    <input
                        type="text"
                        name="name"
                        class="form-control"
                        value="<?php echo htmlspecialchars($employee['name']); ?>"
                        required
                    >
                </div>
                <div class="col-md-6">
                    <label class="form-label">
                        Department *
                    </label>
                    <input
                        type="text"
                        name="department"
                        class="form-control"
                        value="<?php echo htmlspecialchars($employee['department']); ?>"
                        required
                    >
                </div>
                <div class="col-md-6">
                    <label class="form-label">
                        Position *
                    </label>
                    <input
                        type="text"
                        name="position"
                        class="form-control"
                        value="<?php echo htmlspecialchars($employee['position']); ?>"
                        required
                    >
                </div>
                <div class="col-md-6">
                    <label class="form-label">
                        Supervisor
                    </label>
                    <input
                        type="text"
                        name="supervisor"
                        class="form-control"
                        placeholder="Nama supervisor"
                        value="<?php echo htmlspecialchars($employee['supervisor'] ?? ''); ?>"
                    >
                </div>
                <div class="col-md-6">
                    <label class="form-label">
                        Team
                    </label>
                    <select name="team" class="form-control">
                        <option value="">
                            -- Pilih Team --
                        </option>
                        <?php foreach (['A', 'B', 'C', 'D', 'NS'] as $teamOption): ?>
                            <option value="<?php echo $teamOption; ?>" <?php
                                echo ($employee['team'] ?? '') === $teamOption ? 'selected' : '';
                                ?>>
                                <?php echo $teamOption; ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-6">
                    <label class="form-label">
                        License ID
                    </label>
                    <input
                        type="text"
                        id="licenseIdPreview"
                        class="form-control"
                        value="<?php echo htmlspecialchars($employee['license_id'] ?? ''); ?>"
                        readonly
                        disabled
                    >
                    <div class="form-text">
                        Standar otomatis: BKT-&lt;NIK&gt;, mengikuti NIK terbaru saat disimpan.
                    </div>
                </div>
                <div class="col-md-6">
                    <label class="form-label">
                        New Photo
                    </label>
                    <input
                        type="file"
                        name="photo"
                        class="form-control"
                        accept=".jpg,.jpeg,.png,.webp"
                    >
                    <div class="form-text">
                        Kosongkan jika tidak ingin mengganti foto.
                    </div>
                </div>
                <?php if (!empty($employee['photo'])): ?>
                    <div class="col-12">
                        <label class="form-label d-block">
                            Current Photo
                        </label>
                        <img
                            src="../uploads/employees/<?php echo htmlspecialchars($employee['photo']); ?>"
                            class="employee-photo-preview"
                            alt="Current employee photo"
                        >
                    </div>
                <?php endif; ?>
            </div>
            <hr class="my-4">
            <div class="d-flex gap-2">
                <button
                    type="submit"
                    class="btn btn-primary"
                >
                    Update Employee
                </button>
                <a
                    href="employees.php"
                    class="btn btn-outline-secondary"
                >
                    Cancel
                </a>
            </div>
        </form>
    </div>
</div>
<script>
    document.getElementById('nikInput').addEventListener('input', function () {
        var preview = document.getElementById('licenseIdPreview');
        preview.value = this.value !== '' ? 'BKT-' + this.value : '';
    });
</script>
</body>
</html>