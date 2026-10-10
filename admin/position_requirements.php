<?php
require_once "auth.php";
require_once "../config/database.php";

$success = isset($_GET['success']) && $_GET['success'] === '1';
$selectedPosition = trim($_GET['position'] ?? '');
/*
|--------------------------------------------------------------------------
| Cek tabel position_requirements
|--------------------------------------------------------------------------
*/
$tableMissing = false;
$requirementsByPosition = [];
try {
    $reqResult = mysqli_query(
        $conn,
        "SELECT position, competency_id FROM position_requirements ORDER BY position ASC"
    );
    if ($reqResult === false) {
        $tableMissing = true;
    } else {
        while ($row = mysqli_fetch_assoc($reqResult)) {
            $requirementsByPosition[$row['position']][] = (int) $row['competency_id'];
        }
    }
} catch (\Throwable $e) {
    $tableMissing = true;
}
/*
|--------------------------------------------------------------------------
| Daftar posisi (dari data karyawan + yang sudah punya requirement)
|--------------------------------------------------------------------------
*/
$positions = array_keys($requirementsByPosition);
$positionResult = mysqli_query(
    $conn,
    "SELECT DISTINCT position FROM employees
     WHERE is_deleted = 0 AND position IS NOT NULL AND position <> ''
     ORDER BY position ASC"
);
while ($row = mysqli_fetch_assoc($positionResult)) {
    $positions[] = $row['position'];
}
$positions = array_values(array_unique($positions));
sort($positions);
/*
|--------------------------------------------------------------------------
| Semua competency
|--------------------------------------------------------------------------
*/
$competencies = [];
$competencyResult = mysqli_query($conn, "SELECT id, code, name FROM competencies ORDER BY name ASC");
while ($row = mysqli_fetch_assoc($competencyResult)) {
    $competencies[] = $row;
}
/*
|--------------------------------------------------------------------------
| Jumlah karyawan per posisi (info)
|--------------------------------------------------------------------------
*/
$headcount = [];
$headcountResult = mysqli_query(
    $conn,
    "SELECT position, COUNT(*) AS total FROM employees
     WHERE is_deleted = 0 AND position IS NOT NULL AND position <> ''
     GROUP BY position"
);
while ($row = mysqli_fetch_assoc($headcountResult)) {
    $headcount[$row['position']] = (int) $row['total'];
}

$selectedRequirementIds = $selectedPosition !== ''
    ? ($requirementsByPosition[$selectedPosition] ?? [])
    : [];
?>

<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>
        Required Competency per Posisi - Bekaert Competency
    </title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet">
    <link rel="stylesheet" href="../assets/css/style.css?v=<?php echo filemtime(__DIR__ . '/../assets/css/style.css'); ?>">
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
                <i class="bi bi-box-arrow-right"></i> Logout
            </a>
        </div>
    </nav>
<div class="admin-layout">
    <?php include "../includes/admin_sidebar.php"; ?>
    <main class="admin-content">
    <div class="admin-container">
        <div class="page-header">
            <div>
                <h1>
                    Required Competency per Posisi
                </h1>
                <p>
                    Tentukan competency wajib tiap posisi &mdash; dipakai halaman Competency Gap
                </p>
            </div>
            <div class="d-flex gap-2">
                <a href="competency_gap.php" class="btn btn-outline-secondary">
                    Lihat Laporan Gap
                </a>
            </div>
        </div>

        <?php if ($tableMissing): ?>
            <div class="alert alert-warning">
                Tabel <code>position_requirements</code> belum tersedia. Jalankan migrasi
                <code>database/migrations/2026_08_27_add_position_requirements.sql</code> terlebih dahulu.
            </div>
        <?php endif; ?>
        <?php if ($success): ?>
            <div class="alert alert-success">
                Required competency untuk posisi
                <strong><?php echo htmlspecialchars($selectedPosition); ?></strong> berhasil disimpan.
            </div>
        <?php endif; ?>

        <div class="row g-4">
            <!-- DAFTAR POSISI -->
            <div class="col-md-4">
                <div class="employee-table-card">
                    <div class="p-3 border-bottom">
                        <strong>Posisi</strong>
                    </div>
                    <div class="list-group list-group-flush">
                        <?php foreach ($positions as $positionOption): ?>
                            <?php
                            $requiredCount = count($requirementsByPosition[$positionOption] ?? []);
                            $isActive = $positionOption === $selectedPosition;
                            ?>
                            <a href="position_requirements.php?position=<?php echo urlencode($positionOption); ?>"
                                class="list-group-item list-group-item-action d-flex justify-content-between align-items-center <?php
                                    echo $isActive ? 'active' : ''; ?>">
                                <span>
                                    <?php echo htmlspecialchars($positionOption); ?>
                                    <br>
                                    <small class="<?php echo $isActive ? '' : 'text-muted'; ?>">
                                        <?php echo (int) ($headcount[$positionOption] ?? 0); ?> karyawan
                                    </small>
                                </span>
                                <span class="badge <?php echo $requiredCount > 0 ? 'text-bg-primary' : 'text-bg-secondary'; ?>">
                                    <?php echo $requiredCount; ?> wajib
                                </span>
                            </a>
                        <?php endforeach; ?>
                        <?php if (count($positions) === 0): ?>
                            <div class="p-3 text-muted">
                                Belum ada posisi pada data karyawan.
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>

            <!-- FORM REQUIREMENT -->
            <div class="col-md-8">
                <div class="form-card">
                    <form method="GET" class="row g-2 mb-4">
                        <div class="col-9">
                            <input type="text" name="position" class="form-control" list="positionList"
                                placeholder="Pilih / ketik nama posisi..."
                                value="<?php echo htmlspecialchars($selectedPosition); ?>">
                            <datalist id="positionList">
                                <?php foreach ($positions as $positionOption): ?>
                                    <option value="<?php echo htmlspecialchars($positionOption); ?>">
                                <?php endforeach; ?>
                            </datalist>
                        </div>
                        <div class="col-3">
                            <button type="submit" class="btn btn-outline-primary w-100">
                                Buka
                            </button>
                        </div>
                    </form>

                    <?php if ($selectedPosition === ''): ?>
                        <p class="text-muted mb-0">
                            Pilih posisi di kiri, atau ketik nama posisi di atas untuk mengatur competency wajibnya.
                        </p>
                    <?php else: ?>
                        <h4 class="mb-1">
                            <?php echo htmlspecialchars($selectedPosition); ?>
                        </h4>
                        <p class="text-muted">
                            Centang competency yang wajib dimiliki karyawan dengan posisi ini.
                        </p>
                        <form method="POST" action="position_requirements_save.php">
                            <?php echo csrf_input(); ?>
                            <input type="hidden" name="position" value="<?php echo htmlspecialchars($selectedPosition); ?>">
                            <div class="table-responsive">
                                <table class="table align-middle">
                                    <thead>
                                        <tr>
                                            <th width="80">Wajib</th>
                                            <th>Competency</th>
                                            <th>Code</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php foreach ($competencies as $competency): ?>
                                            <tr>
                                                <td>
                                                    <input type="checkbox" class="form-check-input"
                                                        name="competencies[]"
                                                        value="<?php echo (int) $competency['id']; ?>"
                                                        <?php echo in_array((int) $competency['id'], $selectedRequirementIds, true)
                                                            ? 'checked' : ''; ?>>
                                                </td>
                                                <td><?php echo htmlspecialchars($competency['name']); ?></td>
                                                <td>
                                                    <span class="text-muted">
                                                        <?php echo htmlspecialchars($competency['code'] ?? '-'); ?>
                                                    </span>
                                                </td>
                                            </tr>
                                        <?php endforeach; ?>
                                        <?php if (count($competencies) === 0): ?>
                                            <tr>
                                                <td colspan="3" class="text-muted">Belum ada competency.</td>
                                            </tr>
                                        <?php endif; ?>
                                    </tbody>
                                </table>
                            </div>
                            <?php if (admin_can_write()): ?>
                                <button type="submit" class="btn btn-primary" <?php echo $tableMissing ? 'disabled' : ''; ?>>
                                    Simpan
                                </button>
                            <?php else: ?>
                                <span class="text-muted">Akun read-only &mdash; perubahan tidak bisa disimpan.</span>
                            <?php endif; ?>
                        </form>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>
</main>
</div>
</body>

</html>
