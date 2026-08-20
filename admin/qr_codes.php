<?php
require_once "auth.php";
require_once "../config/database.php";
/*
|--------------------------------------------------------------------------
| Pastikan tabel settings tersedia
|--------------------------------------------------------------------------
*/
mysqli_query($conn, "
    CREATE TABLE IF NOT EXISTS settings (
        id INT PRIMARY KEY,
        base_url VARCHAR(255) NOT NULL DEFAULT ''
    )
");
mysqli_query($conn, "INSERT IGNORE INTO settings (id, base_url) VALUES (1, '')");
$error = "";
$success = "";
/*
|--------------------------------------------------------------------------
| Simpan Base URL
|--------------------------------------------------------------------------
*/
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $base_url = rtrim(trim($_POST['base_url'] ?? ''), '/');
    if (
        $base_url === '' ||
        !preg_match('#^https?://[^\s]+$#i', $base_url)
    ) {
        $error = "Base URL tidak valid. Contoh: https://competency.namadomain.com";
    } else {
        $stmt = mysqli_prepare($conn, "UPDATE settings SET base_url = ? WHERE id = 1");
        mysqli_stmt_bind_param($stmt, "s", $base_url);
        mysqli_stmt_execute($stmt);
        $redirectSearch = isset($_GET['search']) ? '&search=' . urlencode($_GET['search']) : '';
        header("Location: qr_codes.php?saved=1" . $redirectSearch);
        exit;
    }
}
$settingRow = mysqli_fetch_assoc(
    mysqli_query($conn, "SELECT base_url FROM settings WHERE id = 1")
);
$baseUrl = $settingRow['base_url'] ?? '';
if (isset($_GET['saved']) && $_GET['saved'] === '1') {
    $success = "Base URL berhasil disimpan.";
}
/*
|--------------------------------------------------------------------------
| Ambil data employee
|--------------------------------------------------------------------------
*/
$search = trim($_GET['search'] ?? '');
if ($search !== '') {
    $query = "
        SELECT id, nik, name, department, position
        FROM employees
        WHERE nik LIKE ? OR name LIKE ? OR department LIKE ?
        ORDER BY name ASC
    ";
    $stmt = mysqli_prepare($conn, $query);
    $keyword = "%" . $search . "%";
    mysqli_stmt_bind_param($stmt, "sss", $keyword, $keyword, $keyword);
    mysqli_stmt_execute($stmt);
    $result = mysqli_stmt_get_result($stmt);
} else {
    $result = mysqli_query(
        $conn,
        "SELECT id, nik, name, department, position FROM employees ORDER BY name ASC"
    );
}
$qrItems = [];
while ($employee = mysqli_fetch_assoc($result)) {
    $qrItems[] = [
        'id' => (int) $employee['id'],
        'nik' => $employee['nik'],
        'name' => $employee['name'],
        'department' => $employee['department'],
        'position' => $employee['position'],
        'url' => $baseUrl !== '' ? $baseUrl . '/?nik=' . rawurlencode($employee['nik']) : '',
    ];
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>
        QR Code Generator - Bekaert Competency
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
                QR Code Generator
            </h1>
            <p>
                Generate QR code verifikasi kompetensi per karyawan
            </p>
        </div>
        <a href="employees.php" class="btn btn-outline-secondary">
            &larr; Back
        </a>
    </div>

    <?php if ($success !== ''): ?>
        <div class="alert alert-success">
            <?php echo htmlspecialchars($success); ?>
        </div>
    <?php endif; ?>
    <?php if ($error !== ''): ?>
        <div class="alert alert-danger">
            <?php echo htmlspecialchars($error); ?>
        </div>
    <?php endif; ?>

    <!-- BASE URL SETTINGS -->
    <div class="form-card mb-4">
        <label class="form-label">
            Base URL Situs
        </label>
        <p class="text-muted" style="font-size:12px;">
            Alamat domain tempat aplikasi ini nanti diakses publik (dipakai untuk membentuk link di QR code).
            Contoh: <code>https://competency.namadomain.com</code>. Jangan pakai <code>localhost</code> jika QR akan discan dari HP orang lain.
        </p>
        <form method="POST" class="row g-2">
            <div class="col-md-9">
                <input type="text" name="base_url" class="form-control"
                    placeholder="https://competency.namadomain.com"
                    value="<?php echo htmlspecialchars($baseUrl); ?>">
            </div>
            <div class="col-md-3">
                <button type="submit" class="btn btn-primary w-100">
                    Save Base URL
                </button>
            </div>
        </form>
    </div>

    <?php if ($baseUrl === ''): ?>
        <div class="alert alert-warning">
            Set Base URL di atas terlebih dahulu sebelum QR code bisa digenerate.
        </div>
    <?php else: ?>
        <!-- SEARCH -->
        <div class="employee-search">
            <form method="GET" class="row g-2">
                <div class="col-md-10">
                    <input type="text" name="search" class="form-control"
                        placeholder="Search NIK, name, department..."
                        value="<?php echo htmlspecialchars($search); ?>">
                </div>
                <div class="col-md-2">
                    <button type="submit" class="btn btn-primary w-100">
                        Search
                    </button>
                </div>
            </form>
        </div>

        <!-- QR GRID -->
        <div class="row g-3">
            <?php if (count($qrItems) === 0): ?>
                <div class="col-12">
                    <div class="employee-table-card p-5 text-center">
                        Tidak ada data karyawan.
                    </div>
                </div>
            <?php else: ?>
                <?php foreach ($qrItems as $item): ?>
                    <div class="col-md-3 col-sm-6">
                        <div class="qr-card">
                            <h3>
                                <?php echo htmlspecialchars($item['name']); ?>
                            </h3>
                            <div class="qr-nik">
                                NIK: <?php echo htmlspecialchars($item['nik']); ?>
                            </div>
                            <div class="qr-canvas-wrap" id="qr-<?php echo $item['id']; ?>"></div>
                            <div class="qr-url">
                                <?php echo htmlspecialchars($item['url']); ?>
                            </div>
                            <button type="button" class="btn btn-sm btn-outline-primary w-100"
                                onclick="downloadQR(<?php echo $item['id']; ?>, '<?php echo htmlspecialchars(addslashes($item['nik']), ENT_QUOTES); ?>')">
                                Download PNG
                            </button>
                        </div>
                    </div>
                <?php endforeach; ?>
            <?php endif; ?>
        </div>
    <?php endif; ?>
</div>

<script src="https://cdn.jsdelivr.net/npm/qrcodejs@1.0.0/qrcode.min.js"></script>
<script>
    const qrItems = <?php echo json_encode(
        $qrItems,
        JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_SLASHES
    ); ?>;

    document.addEventListener('DOMContentLoaded', function () {
        qrItems.forEach(function (item) {
            if (!item.url) {
                return;
            }
            new QRCode(document.getElementById('qr-' + item.id), {
                text: item.url,
                width: 160,
                height: 160,
                correctLevel: QRCode.CorrectLevel.M
            });
        });
    });

    function downloadQR(id, filename) {
        const container = document.getElementById('qr-' + id);
        const canvas = container ? container.querySelector('canvas') : null;
        if (!canvas) {
            return;
        }
        const link = document.createElement('a');
        link.download = filename + '.png';
        link.href = canvas.toDataURL('image/png');
        link.click();
    }
</script>
</body>
</html>
