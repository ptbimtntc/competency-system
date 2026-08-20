<?php
require_once "auth.php";
require_once "../config/database.php";
$error = "";
$prefillName = trim($_GET['name'] ?? '');
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $name = trim($_POST['name'] ?? '');
    $title = trim($_POST['title'] ?? '');
    /*
    |--------------------------------------------------------------------------
    | Validasi
    |--------------------------------------------------------------------------
    */
    if ($name === '') {
        $error = "Nama signatory wajib diisi.";
    } else {
        /*
        |--------------------------------------------------------------------------
        | Upload tanda tangan
        |--------------------------------------------------------------------------
        */
        $signatureName = null;
        if (
            isset($_FILES['signature']) &&
            $_FILES['signature']['error'] !== UPLOAD_ERR_NO_FILE
        ) {
            $file = $_FILES['signature'];
            if ($file['error'] !== UPLOAD_ERR_OK) {
                $error = "Upload tanda tangan gagal.";
            } else {
                $allowedTypes = ['image/jpeg', 'image/png', 'image/webp'];
                $allowedExtensions = ['jpg', 'jpeg', 'png', 'webp'];
                $maxSize = 1 * 1024 * 1024;
                $extension = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
                if (
                    !in_array($file['type'], $allowedTypes, true) ||
                    !in_array($extension, $allowedExtensions, true) ||
                    @getimagesize($file['tmp_name']) === false
                ) {
                    $error = "Tanda tangan harus JPG, PNG, atau WEBP.";
                } elseif ($file['size'] > $maxSize) {
                    $error = "Ukuran file maksimal 1 MB.";
                } else {
                    $signatureName = uniqid('sig_', true) . '.' . $extension;
                    $uploadDir = "../uploads/signatures/";
                    if (!is_dir($uploadDir)) {
                        mkdir($uploadDir, 0755, true);
                    }
                    $destination = $uploadDir . $signatureName;
                    if (!move_uploaded_file($file['tmp_name'], $destination)) {
                        $error = "Tanda tangan gagal disimpan.";
                    }
                }
            }
        }
        /*
        |--------------------------------------------------------------------------
        | Insert database
        |--------------------------------------------------------------------------
        */
        if ($error === '') {
            $query = "
                INSERT INTO signatories (name, title, signature)
                VALUES (?, ?, ?)
            ";
            $stmt = mysqli_prepare($conn, $query);
            mysqli_stmt_bind_param($stmt, "sss", $name, $title, $signatureName);
            if (mysqli_stmt_execute($stmt)) {
                header("Location: signatories.php");
                exit;
            } else {
                $error = "Data signatory gagal disimpan.";
            }
        }
    }
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>
        Add Signatory
    </title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="../assets/css/style.css">
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
            <?php echo htmlspecialchars($_SESSION['admin_name']); ?>
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
                Add Signatory
            </h1>
            <p>
                Tambah pemateri training atau manager/pengesah sertifikat
            </p>
        </div>
        <a href="signatories.php" class="btn btn-outline-secondary">
            &larr; Back
        </a>
    </div>
    <?php if ($error !== ''): ?>
        <div class="alert alert-danger">
            <?php echo htmlspecialchars($error); ?>
        </div>
    <?php endif; ?>
    <div class="form-card">
        <form method="POST" enctype="multipart/form-data">
            <div class="row g-3">
                <div class="col-md-6">
                    <label class="form-label">
                        Name *
                    </label>
                    <input type="text" name="name" class="form-control"
                        value="<?php echo htmlspecialchars($_POST['name'] ?? $prefillName); ?>" required>
                </div>
                <div class="col-md-6">
                    <label class="form-label">
                        Title
                    </label>
                    <input type="text" name="title" class="form-control" list="signatoryTitleOptions"
                        placeholder="Contoh: Trainer, Maintenance Manager, Safety Manager"
                        value="<?php echo htmlspecialchars($_POST['title'] ?? ''); ?>">
                    <datalist id="signatoryTitleOptions">
                        <option value="Trainer">
                        <option value="Maintenance Manager">
                        <option value="Safety Manager">
                        <option value="Quality Manager">
                        <option value="Production Manager">
                        <option value="HR Manager">
                    </datalist>
                </div>
                <div class="col-md-6">
                    <label class="form-label">
                        Signature Image
                    </label>
                    <input type="file" name="signature" class="form-control" accept=".jpg,.jpeg,.png,.webp">
                    <div class="form-text">
                        JPG, PNG, atau WEBP. Maksimal 1 MB. Disarankan PNG transparan.
                    </div>
                </div>
            </div>
            <hr class="my-4">
            <div class="d-flex gap-2">
                <button type="submit" class="btn btn-primary">
                    Save Signatory
                </button>
                <a href="signatories.php" class="btn btn-outline-secondary">
                    Cancel
                </a>
            </div>
        </form>
    </div>
</div>
</body>
</html>
