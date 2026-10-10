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
$whereClause = "";
$params = [];
$types = "";
if ($search !== '') {
    $whereClause = " WHERE name LIKE ? OR description LIKE ?";
    $keyword = "%" . $search . "%";
    $params = [$keyword, $keyword];
    $types = "ss";
}
/*
|--------------------------------------------------------------------------
| Hitung total + pagination
|--------------------------------------------------------------------------
*/
$countStmt = mysqli_prepare($conn, "SELECT COUNT(*) AS total FROM competencies" . $whereClause);
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
$query = "SELECT id, name, code, description FROM competencies"
    . $whereClause . " ORDER BY name ASC LIMIT ? OFFSET ?";
$dataParams = $params;
$dataParams[] = $pg['per_page'];
$dataParams[] = $pg['offset'];
$stmt = mysqli_prepare($conn, $query);
mysqli_stmt_bind_param($stmt, $types . "ii", ...$dataParams);
mysqli_stmt_execute($stmt);
$result = mysqli_stmt_get_result($stmt);

$paginationBaseParams = array_filter(['search' => $search], function ($value) {
    return $value !== null && $value !== '';
});
?>

<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>
        Competencies - Bekaert Competency
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
                <?php
                echo htmlspecialchars(
                    $_SESSION['admin_name']
                );
                ?>
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
                    Competency Management
                </h1>
                <p>
                    Manage employee competency items
                </p>
            </div>
            <div class="d-flex gap-2">
                <a href="competencies_export.php" class="btn btn-outline-secondary">
                    Export CSV
                </a>
                <?php if (admin_can_write()): ?>
                    <a href="competencies_import.php" class="btn btn-outline-secondary">
                        Import CSV
                    </a>
                    <a href="competency_add.php" class="btn btn-primary">
                        + Add Competency
                    </a>
                <?php endif; ?>
            </div>
        </div>
        <!-- SEARCH -->
        <div class="employee-search">
            <form method="GET" class="row g-2">
                <div class="col-md-10">
                    <input type="text" name="search" class="form-control" placeholder="Search competency..."
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
                            <th>
                                #
                            </th>
                            <th>
                                Code
                            </th>
                            <th>
                                Competency
                            </th>
                            <th>
                                Description
                            </th>
                            <th>
                                Action
                            </th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php
                        $number = $pg['offset'] + 1;
                        if (
                            mysqli_num_rows($result) > 0
                        ):
                            while (
                                $competency =
                                mysqli_fetch_assoc($result)
                            ):
                                ?>
                                <tr>
                                    <td>
                                        <?php
                                        echo $number++;
                                        ?>
                                    </td>
                                    <td>
                                        <span class="badge text-bg-secondary">
                                            <?php echo htmlspecialchars($competency['code'] ?? '-'); ?>
                                        </span>
                                    </td>
                                    <td>
                                        <strong>
                                            <?php
                                            echo htmlspecialchars(
                                                $competency['name']
                                            );
                                            ?>
                                        </strong>
                                    </td>
                                    <td>
                                        <span class="competency-description">
                                            <?php
                                            echo htmlspecialchars(
                                                $competency['description'] ?? '-'
                                            );
                                            ?>
                                        </span>
                                    </td>
                                    <td>
                                        <div class="employee-actions">
                                            <a href="competency_assign.php?id=<?php echo $competency['id']; ?>"
                                                class="btn btn-sm btn-outline-success">
                                                Assign Training
                                            </a>
                                            <a href="competency_questions.php?competency_id=<?php echo $competency['id']; ?>"
                                                class="btn btn-sm btn-outline-info">
                                                Questions
                                            </a>
                                            <a href="competency_edit.php?id=<?php echo $competency['id']; ?>"
                                                class="btn btn-sm btn-outline-primary">
                                                <?php echo admin_can_write() ? 'Edit' : 'Detail'; ?>
                                            </a>
                                            <?php if (admin_can_write()): ?>
                                                <form method="POST" action="competency_delete.php" class="d-inline"
                                                    onsubmit="return confirm('Apakah Anda yakin ingin menghapus kompetensi ini?');">
                                                    <?php echo csrf_input(); ?>
                                                    <input type="hidden" name="id" value="<?php echo $competency['id']; ?>">
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
                                    Tidak ada data kompetensi.
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
                        dari <?php echo $totalRows; ?> kompetensi
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