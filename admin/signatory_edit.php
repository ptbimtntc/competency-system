<?php
require_once "auth.php";
require_once "../config/database.php";
$id = isset($_GET['id']) ? (int) $_GET['id'] : 0;
if ($id <= 0) {
    header("Location: signatories.php");
    exit;
}
/*
|--------------------------------------------------------------------------
| Ambil data signatory
|--------------------------------------------------------------------------
*/
$query = "SELECT id, name, title, signature FROM signatories WHERE id = ? LIMIT 1";
$stmt = mysqli_prepare($conn, $query);
mysqli_stmt_bind_param($stmt, "i", $id);
mysqli_stmt_execute($stmt);
$result = mysqli_stmt_get_result($stmt);
$signatory = mysqli_fetch_assoc($result);
if (!$signatory) {
    die("Signatory tidak ditemukan.");
}
$error = "";
/*
|--------------------------------------------------------------------------
| UPDATE
|--------------------------------------------------------------------------
*/
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $name = trim($_POST['name'] ?? '');
    $title = trim($_POST['title'] ?? '');
    if ($name === '') {
        $error = "Nama signatory wajib diisi.";
    } else {
        $signatureName = $signatory['signature'];
        if (
            isset($_FILES['signature']) &&
            $_FILES['signature']['error'] !== UPLOAD_ERR_NO_FILE
        ) {
            $file = $_FILES['signature'];
            $allowedTypes = ['image/jpeg', 'image/png', 'image/webp'];
            $allowedExtensions = ['jpg', 'jpeg', 'png', 'webp'];
            $maxSize = 1 * 1024 * 1024;
            $extension = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
            if ($file['error'] !== UPLOAD_ERR_OK) {
                $error = "Upload tanda tangan gagal.";
            } elseif (
                !in_array($file['type'], $allowedTypes, true) ||
                !in_array($extension, $allowedExtensions, true) ||
                @getimagesize($file['tmp_name']) === false
            ) {
                $error = "Tanda tangan harus JPG, PNG, atau WEBP.";
            } elseif ($file['size'] > $maxSize) {
                $error = "Ukuran file maksimal 1 MB.";
            } else {
                $newSignatureName = uniqid('sig_', true) . '.' . $extension;
                $uploadDir = "../uploads/signatures/";
                if (!is_dir($uploadDir)) {
                    mkdir($uploadDir, 0755, true);
                }
                $destination = $uploadDir . $newSignatureName;
                if (move_uploaded_file($file['tmp_name'], $destination)) {
                    if (!empty($signatory['signature'])) {
                        $oldSignature = $uploadDir . $signatory['signature'];
                        if (file_exists($oldSignature)) {
                            unlink($oldSignature);
                        }
                    }
                    $signatureName = $newSignatureName;
                } else {
                    $error = "Tanda tangan gagal disimpan.";
                }
            }
        }
        if ($error === '') {
            $updateQuery = "
                UPDATE signatories
                SET name = ?, title = ?, signature = ?
                WHERE id = ?
            ";
            $updateStmt = mysqli_prepare($conn, $updateQuery);
            mysqli_stmt_bind_param($updateStmt, "sssi", $name, $title, $signatureName, $id);
            if (mysqli_stmt_execute($updateStmt)) {
                header("Location: signatories.php");
                exit;
            } else {
                $error = "Data signatory gagal diperbarui.";
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
        Edit Signatory
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
                Edit Signatory
            </h1>
            <p>
                Update data pemateri training atau manager/pengesah sertifikat
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
                        value="<?php echo htmlspecialchars($signatory['name']); ?>" required>
                </div>
                <div class="col-md-6">
                    <label class="form-label">
                        Title
                    </label>
                    <input type="text" name="title" class="form-control" list="signatoryTitleOptions"
                        value="<?php echo htmlspecialchars($signatory['title'] ?? ''); ?>">
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
                        New Signature Image
                    </label>
                    <input type="file" name="signature" class="form-control" accept=".jpg,.jpeg,.png,.webp">
                    <div class="form-text">
                        Kosongkan jika tidak ingin mengganti tanda tangan. Maksimal 1 MB.
                    </div>
                </div>
                <?php if (!empty($signatory['signature'])): ?>
                    <div class="col-12">
                        <label class="form-label d-block">
                            Current Signature
                        </label>
                        <img src="../uploads/signatures/<?php echo htmlspecialchars($signatory['signature']); ?>"
                            style="height:70px;max-width:220px;object-fit:contain;border:1px solid #dce2e8;border-radius:8px;padding:6px;"
                            alt="Current signature">
                    </div>
                <?php endif; ?>
            </div>
            <hr class="my-4">
            <div class="d-flex gap-2">
                <button type="submit" class="btn btn-primary">
                    Update Signatory
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
