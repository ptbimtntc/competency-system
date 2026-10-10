<?php
require_once "auth.php";
require_once "../config/database.php";
require_once "pagination.php";
/*
|--------------------------------------------------------------------------
| Search
|--------------------------------------------------------------------------
*/
$search = trim($_GET['search'] ?? '');
/*
|--------------------------------------------------------------------------
| Cari nama trainer/authorizer yang belum punya tanda tangan
|--------------------------------------------------------------------------
|
| Dikumpulkan dari nama yang benar-benar pernah dipakai di training
| record (kolom trainer & authorizer_name), lalu dicocokkan dengan
| signatories yang sudah punya file signature (case-insensitive).
|
*/
$namesResult = mysqli_query($conn, "
    SELECT DISTINCT TRIM(trainer) AS name
    FROM employee_competencies
    WHERE trainer IS NOT NULL AND TRIM(trainer) != ''
    UNION
    SELECT DISTINCT TRIM(authorizer_name) AS name
    FROM employee_competencies
    WHERE authorizer_name IS NOT NULL AND TRIM(authorizer_name) != ''
");
$usedNames = [];
while ($row = mysqli_fetch_assoc($namesResult)) {
    $usedNames[$row['name']] = true;
}
$signedNamesResult = mysqli_query($conn, "
    SELECT LOWER(TRIM(name)) AS name
    FROM signatories
    WHERE signature IS NOT NULL AND signature != ''
");
$signedNames = [];
while ($row = mysqli_fetch_assoc($signedNamesResult)) {
    $signedNames[$row['name']] = true;
}
$missingSignatures = [];
foreach (array_keys($usedNames) as $name) {
    if (!isset($signedNames[mb_strtolower($name)])) {
        $missingSignatures[] = $name;
    }
}
sort($missingSignatures);
$conditions = [];
$params = [];
$types = "";
if ($search !== '') {
    $conditions[] = "(name LIKE ? OR title LIKE ?)";
    $keyword = "%" . $search . "%";
    array_push($params, $keyword, $keyword);
    $types .= "ss";
}
$whereClause = count($conditions) > 0
    ? " WHERE " . implode(" AND ", $conditions)
    : "";
/*
|--------------------------------------------------------------------------
| Hitung total + pagination
|--------------------------------------------------------------------------
*/
$countStmt = mysqli_prepare($conn, "SELECT COUNT(*) AS total FROM signatories" . $whereClause);
if (count($params) > 0) {
    mysqli_stmt_bind_param($countStmt, $types, ...$params);
}
mysqli_stmt_execute($countStmt);
$totalRows = (int) mysqli_fetch_assoc(mysqli_stmt_get_result($countStmt))['total'];
$pg = paginate($totalRows, 25);
/*
|--------------------------------------------------------------------------
| Ambil data halaman ini
|--------------------------------------------------------------------------
*/
$query = "
    SELECT id, name, title, signature
    FROM signatories
" . $whereClause . " ORDER BY name ASC LIMIT ? OFFSET ?";
$dataParams = $params;
$dataTypes = $types . "ii";
$dataParams[] = $pg['per_page'];
$dataParams[] = $pg['offset'];
$stmt = mysqli_prepare($conn, $query);
mysqli_stmt_bind_param($stmt, $dataTypes, ...$dataParams);
mysqli_stmt_execute($stmt);
$result = mysqli_stmt_get_result($stmt);

$paginationBaseParams = array_filter([
    'search' => $search,
], function ($value) {
    return $value !== null && $value !== '';
});
?>

<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>
        Signatories - Bekaert Competency
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
                    Signatories
                </h1>
                <p>
                    Kelola tanda tangan digital pemateri training dan manager/pengesah sertifikat
                </p>
            </div>
            <div class="d-flex gap-2">
                <?php if (admin_can_write()): ?>
                    <a href="signatory_add.php" class="btn btn-primary">
                        + Add Signatory
                    </a>
                <?php endif; ?>
            </div>
        </div>
        <!-- MISSING SIGNATURES -->
        <?php if (count($missingSignatures) > 0): ?>
            <div class="alert alert-warning">
                <strong>
                    Belum ada tanda tangan (<?php echo count($missingSignatures); ?>)
                </strong>
                <p class="mb-2" style="font-size:13px;">
                    Nama-nama ini dipakai sebagai trainer atau authorizer di data training,
                    tapi belum ada tanda tangan yang diupload untuk mereka.
                </p>
                <div class="d-flex flex-wrap gap-2">
                    <?php foreach ($missingSignatures as $name): ?>
                        <a href="signatory_add.php?name=<?php echo urlencode($name); ?>"
                            class="btn btn-sm btn-outline-warning">
                            + <?php echo htmlspecialchars($name); ?>
                        </a>
                    <?php endforeach; ?>
                </div>
            </div>
        <?php endif; ?>
        <!-- SEARCH -->
        <div class="employee-search">
            <form method="GET" class="row g-2">
                <div class="col-md-10">
                    <input type="text" name="search" class="form-control" placeholder="Search name, title..."
                        value="<?php echo htmlspecialchars($search); ?>">
                </div>
                <div class="col-md-2">
                    <button type="submit" class="btn btn-primary w-100">
                        Search
                    </button>
                </div>
            </form>
        </div>
        <!-- TABLE -->
        <div class="employee-table-card">
            <div class="table-responsive">
                <table class="table table-hover align-middle mb-0">
                    <thead>
                        <tr>
                            <th>#</th>
                            <th>Signature</th>
                            <th>Name</th>
                            <th>Title</th>
                            <th>Action</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php
                        $number = $pg['offset'] + 1;
                        if (mysqli_num_rows($result) > 0):
                            while ($signatory = mysqli_fetch_assoc($result)):
                                ?>
                                <tr>
                                    <td>
                                        <?php echo $number++; ?>
                                    </td>
                                    <td>
                                        <?php if (!empty($signatory['signature']) && is_file(__DIR__ . "/../uploads/signatures/" . $signatory['signature'])): ?>
                                            <img src="../uploads/signatures/<?php echo htmlspecialchars($signatory['signature']); ?>"
                                                alt="Signature" style="height:36px;max-width:110px;object-fit:contain;">
                                        <?php elseif (!empty($signatory['signature'])): ?>
                                            <span class="text-muted" title="File tidak ditemukan di server">-</span>
                                        <?php else: ?>
                                            <span class="text-muted">-</span>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <strong>
                                            <?php echo htmlspecialchars($signatory['name']); ?>
                                        </strong>
                                    </td>
                                    <td>
                                        <?php echo htmlspecialchars($signatory['title'] ?? '-'); ?>
                                    </td>
                                    <td>
                                        <div class="employee-actions">
                                            <a href="signatory_edit.php?id=<?php echo $signatory['id']; ?>"
                                                class="btn btn-sm btn-outline-primary">
                                                <?php echo admin_can_write() ? 'Edit' : 'Detail'; ?>
                                            </a>
                                            <?php if (admin_can_write()): ?>
                                                <form method="POST" action="signatory_delete.php" class="d-inline"
                                                    onsubmit="return confirm('Apakah Anda yakin ingin menghapus signatory ini?');">
                                                    <?php echo csrf_input(); ?>
                                                    <input type="hidden" name="id" value="<?php echo $signatory['id']; ?>">
                                                    <button type="submit" class="btn btn-sm btn-outline-danger">
                                                        Delete
                                                    </button>
                                                </form>
                                            <?php endif; ?>
                                        </div>
                                    </td>
                                </tr>
                                <?php
                            endwhile;
                        else:
                            ?>
                            <tr>
                                <td colspan="5" class="text-center py-5">
                                    Tidak ada data signatory.
                                </td>
                            </tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
            <?php if ($totalRows > 0): ?>
                <div class="d-flex flex-wrap justify-content-between align-items-center gap-2 p-3 border-top">
                    <span class="text-muted" style="font-size:13px;">
                        Menampilkan
                        <?php echo $pg['offset'] + 1; ?>&ndash;<?php echo min($pg['offset'] + $pg['per_page'], $totalRows); ?>
                        dari <?php echo $totalRows; ?> signatory
                    </span>
                    <?php echo render_pagination($pg, $paginationBaseParams); ?>
                </div>
            <?php endif; ?>
        </div>
    </div>
</main>
</div>
</body>

</html>
