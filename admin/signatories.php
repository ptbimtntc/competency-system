<?php
require_once "auth.php";
require_once "../config/database.php";
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
if ($search !== '') {
    $query = "
        SELECT id, name, title, signature
        FROM signatories
        WHERE name LIKE ? OR title LIKE ?
        ORDER BY name ASC
    ";
    $stmt = mysqli_prepare($conn, $query);
    $keyword = "%" . $search . "%";
    mysqli_stmt_bind_param($stmt, "ss", $keyword, $keyword);
    mysqli_stmt_execute($stmt);
    $result = mysqli_stmt_get_result($stmt);
} else {
    $result = mysqli_query(
        $conn,
        "SELECT id, name, title, signature FROM signatories ORDER BY name ASC"
    );
}
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
                    Signatories
                </h1>
                <p>
                    Kelola tanda tangan digital pemateri training dan manager/pengesah sertifikat
                </p>
            </div>
            <div class="d-flex gap-2">
                <a href="dashboard.php" class="btn btn-outline-secondary">
                    &larr; Dashboard
                </a>
                <a href="signatory_add.php" class="btn btn-primary">
                    + Add Signatory
                </a>
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
                        $number = 1;
                        if (mysqli_num_rows($result) > 0):
                            while ($signatory = mysqli_fetch_assoc($result)):
                                ?>
                                <tr>
                                    <td>
                                        <?php echo $number++; ?>
                                    </td>
                                    <td>
                                        <?php if (!empty($signatory['signature'])): ?>
                                            <img src="../uploads/signatures/<?php echo htmlspecialchars($signatory['signature']); ?>"
                                                alt="Signature" style="height:36px;max-width:110px;object-fit:contain;">
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
                                                Edit
                                            </a>
                                            <a href="signatory_delete.php?id=<?php echo $signatory['id']; ?>"
                                                class="btn btn-sm btn-outline-danger"
                                                onclick="return confirm('Apakah Anda yakin ingin menghapus signatory ini?');">
                                                Delete
                                            </a>
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
        </div>
    </div>
</body>

</html>
