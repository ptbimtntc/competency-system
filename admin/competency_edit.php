<?php
require_once "auth.php";
require_once "../config/database.php";
$id = isset($_GET['id'])
    ? (int) $_GET['id']
    : 0;
if ($id <= 0) {
    header(
        "Location: competencies.php"
    );
    exit;
}
/*
|--------------------------------------------------------------------------
| Ambil competency
|--------------------------------------------------------------------------
*/
$query = "
    SELECT
        id,
        name,
        code,
        description,
        scope,
        validity_note,
        validity_months,
        default_trainer,
        default_training_provider,
        default_authorizer_title,
        default_authorizer_name,
        default_trainer_signatory_id,
        default_authorizer_signatory_id
    FROM competencies
    WHERE id = ?
    LIMIT 1
";
$stmt =
    mysqli_prepare(
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
$competency =
    mysqli_fetch_assoc($result);
if (!$competency) {
    die(
        "Competency tidak ditemukan."
    );
}
/*
|--------------------------------------------------------------------------
| Ambil daftar signatories (untuk dropdown default trainer/authorizer)
|--------------------------------------------------------------------------
*/
$signatoryResult = mysqli_query(
    $conn,
    "SELECT id, name, title FROM signatories ORDER BY name ASC"
);
$signatories = [];
while ($row = mysqli_fetch_assoc($signatoryResult)) {
    $signatories[] = $row;
}
$error = "";
/*
|--------------------------------------------------------------------------
| UPDATE
|--------------------------------------------------------------------------
*/
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $name =
        trim($_POST['name'] ?? '');
    $code =
        strtoupper(trim($_POST['code'] ?? ''));
    $description =
        trim($_POST['description'] ?? '');
    $scope =
        trim($_POST['scope'] ?? '');
    $validity_note =
        trim($_POST['validity_note'] ?? '');
    $validity_months =
        ($_POST['validity_months'] ?? '') === '' ? null : (int) $_POST['validity_months'];
    $default_trainer =
        trim($_POST['default_trainer'] ?? '');
    $default_training_provider =
        trim($_POST['default_training_provider'] ?? '');
    $default_authorizer_title =
        trim($_POST['default_authorizer_title'] ?? '');
    $default_authorizer_name =
        trim($_POST['default_authorizer_name'] ?? '');
    $default_trainer_signatory_id =
        ($_POST['default_trainer_signatory_id'] ?? '') === '' ? null : (int) $_POST['default_trainer_signatory_id'];
    $default_authorizer_signatory_id =
        ($_POST['default_authorizer_signatory_id'] ?? '') === '' ? null : (int) $_POST['default_authorizer_signatory_id'];
    if ($name === '') {
        $error =
            "Nama kompetensi wajib diisi.";
    } elseif (!preg_match('/^[A-Z]{3}$/', $code)) {
        $error =
            "Code wajib diisi 3 huruf kapital (A-Z).";
    } elseif (
        $validity_months !== null &&
        ($validity_months < 1 || $validity_months > 120)
    ) {
        $error =
            "Masa berlaku (bulan) harus antara 1 dan 120.";
    } else {
        /*
        |--------------------------------------------------------------------------
        | Cek duplicate nama & code
        |--------------------------------------------------------------------------
        */
        $checkQuery = "
            SELECT id
            FROM competencies
            WHERE (name = ? OR code = ?)
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
            "ssi",
            $name,
            $code,
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
                "Nama atau code kompetensi tersebut sudah digunakan.";
        } else {
            /*
            |--------------------------------------------------------------------------
            | UPDATE
            |--------------------------------------------------------------------------
            */
            $updateQuery = "
                UPDATE competencies
                SET
                    name = ?,
                    code = ?,
                    description = ?,
                    scope = ?,
                    validity_note = ?,
                    validity_months = ?,
                    default_trainer = ?,
                    default_training_provider = ?,
                    default_authorizer_title = ?,
                    default_authorizer_name = ?,
                    default_trainer_signatory_id = ?,
                    default_authorizer_signatory_id = ?
                WHERE id = ?
            ";
            $updateStmt =
                mysqli_prepare(
                    $conn,
                    $updateQuery
                );
            mysqli_stmt_bind_param(
                $updateStmt,
                "sssssissssiii",
                $name,
                $code,
                $description,
                $scope,
                $validity_note,
                $validity_months,
                $default_trainer,
                $default_training_provider,
                $default_authorizer_title,
                $default_authorizer_name,
                $default_trainer_signatory_id,
                $default_authorizer_signatory_id,
                $id
            );
            if (
                mysqli_stmt_execute(
                    $updateStmt
                )
            ) {
                header(
                    "Location: competencies.php"
                );
                exit;
            } else {
                $error =
                    "Kompetensi gagal diperbarui.";
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
        Edit Competency
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
                    Edit Competency
                </h1>
                <p>
                    Update competency information
                </p>
            </div>
            <a href="competencies.php" class="btn btn-outline-secondary">
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
            <form method="POST">
                <div class="row g-3 mb-3">
                    <div class="col-md-9">
                        <label class="form-label">
                            Competency Name *
                        </label>
                        <input type="text" name="name" class="form-control"
                            value="<?php echo htmlspecialchars($competency['name']); ?>" required>
                    </div>
                    <div class="col-md-3">
                        <label class="form-label">
                            Code (3 huruf) *
                        </label>
                        <input type="text" name="code" id="competencyCodeInput" class="form-control"
                            maxlength="3" style="text-transform:uppercase;"
                            value="<?php echo htmlspecialchars($competency['code'] ?? ''); ?>" required>
                        <div class="form-text">
                            Dipakai di nomor sertifikat, harus unik.
                        </div>
                    </div>
                </div>
                <div class="mb-4">
                    <label class="form-label">
                        Description
                    </label>
                    <textarea name="description" class="form-control" rows="5"><?php
                    echo htmlspecialchars(
                        $competency['description'] ?? ''
                    );
                    ?></textarea>
                </div>
                <div class="mb-4">
                    <label class="form-label">
                        Ruang Lingkup Otorisasi
                    </label>
                    <textarea name="scope" class="form-control" rows="4"
                        placeholder="Satu baris untuk satu item, contoh:&#10;Mengoperasikan mesin X&#10;Melakukan perawatan berkala Y"><?php
                        echo htmlspecialchars(
                            $competency['scope'] ?? ''
                        );
                        ?></textarea>
                    <div class="form-text">
                        Satu baris = satu item ruang lingkup, akan tampil sebagai daftar bercentang di halaman detail.
                    </div>
                </div>
                <div class="row g-3 mb-4">
                    <div class="col-md-4">
                        <label class="form-label">
                            Masa Berlaku (bulan)
                        </label>
                        <input type="number" name="validity_months" class="form-control" min="1" max="120"
                            value="<?php echo htmlspecialchars((string) ($competency['validity_months'] ?? 12)); ?>">
                    </div>
                    <div class="col-md-8">
                        <label class="form-label">
                            Catatan Masa Berlaku
                        </label>
                        <input type="text" name="validity_note" class="form-control" value="<?php
                            echo htmlspecialchars(
                                $competency['validity_note']
                                ?? 'Kompetensi ini berlaku selama 1 tahun sejak tanggal training. Perpanjangan wajib dilakukan sebelum masa berlaku berakhir.'
                            );
                            ?>">
                        <div class="form-text">
                            Tampil di halaman detail kompetensi karyawan. Bisa diubah bebas per kompetensi.
                        </div>
                    </div>
                </div>
                <hr class="my-4">
                <h5 class="mb-1">
                    Default Info Training
                </h5>
                <p class="text-muted" style="font-size:13px;">
                    Nilai bawaan yang otomatis terisi saat kompetensi ini di-assign ke karyawan baru,
                    supaya tidak perlu isi ulang satu per satu. Tetap bisa diedit per karyawan
                    karena pelaksanaan training belum tentu sama untuk setiap orang.
                </p>
                <div class="row g-3">
                    <div class="col-md-6">
                        <label class="form-label">
                            Default Trainer
                        </label>
                        <input type="text" name="default_trainer" class="form-control"
                            placeholder="Nama trainer" value="<?php echo htmlspecialchars($competency['default_trainer'] ?? ''); ?>">
                    </div>
                    <div class="col-md-6">
                        <label class="form-label">
                            Default Training Provider
                        </label>
                        <input type="text" name="default_training_provider" class="form-control"
                            placeholder="Nama provider training" value="<?php echo htmlspecialchars($competency['default_training_provider'] ?? ''); ?>">
                    </div>
                    <div class="col-md-6">
                        <label class="form-label">
                            Default Authorizer Title
                        </label>
                        <input type="text" name="default_authorizer_title" class="form-control"
                            list="authorizerTitleOptions" value="<?php
                                echo htmlspecialchars($competency['default_authorizer_title'] ?? 'Maintenance Manager');
                                ?>">
                        <datalist id="authorizerTitleOptions">
                            <option value="Maintenance Manager">
                            <option value="Safety Manager">
                            <option value="Quality Manager">
                            <option value="Production Manager">
                            <option value="HR Manager">
                        </datalist>
                    </div>
                    <div class="col-md-6">
                        <label class="form-label">
                            Default Authorizer Name
                        </label>
                        <input type="text" name="default_authorizer_name" class="form-control"
                            placeholder="Nama pengesah sertifikat" value="<?php echo htmlspecialchars($competency['default_authorizer_name'] ?? ''); ?>">
                    </div>
                    <div class="col-md-6">
                        <label class="form-label">
                            Default Trainer Signature
                        </label>
                        <select name="default_trainer_signatory_id" class="form-control">
                            <option value="">
                                -- Tanpa tanda tangan --
                            </option>
                            <?php foreach ($signatories as $signatory): ?>
                                <option value="<?php echo $signatory['id']; ?>" <?php
                                    echo (int) ($competency['default_trainer_signatory_id'] ?? 0) === (int) $signatory['id']
                                        ? 'selected'
                                        : '';
                                    ?>>
                                    <?php
                                    echo htmlspecialchars(
                                        $signatory['name']
                                        . (!empty($signatory['title']) ? ' - ' . $signatory['title'] : '')
                                    );
                                    ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-md-6">
                        <label class="form-label">
                            Default Authorizer Signature
                        </label>
                        <select name="default_authorizer_signatory_id" class="form-control">
                            <option value="">
                                -- Tanpa tanda tangan --
                            </option>
                            <?php foreach ($signatories as $signatory): ?>
                                <option value="<?php echo $signatory['id']; ?>" <?php
                                    echo (int) ($competency['default_authorizer_signatory_id'] ?? 0) === (int) $signatory['id']
                                        ? 'selected'
                                        : '';
                                    ?>>
                                    <?php
                                    echo htmlspecialchars(
                                        $signatory['name']
                                        . (!empty($signatory['title']) ? ' - ' . $signatory['title'] : '')
                                    );
                                    ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                </div>
                <hr class="my-4">
                <div class="d-flex gap-2">
                    <button type="submit" class="btn btn-primary">
                        Update Competency
                    </button>
                    <a href="competencies.php" class="btn btn-outline-secondary">
                        Cancel
                    </a>
                </div>
            </form>
        </div>
    </div>
    <script>
        document.getElementById('competencyCodeInput').addEventListener('input', function () {
            this.value = this.value.toUpperCase().replace(/[^A-Z]/g, '').slice(0, 3);
        });
    </script>
</body>

</html>
