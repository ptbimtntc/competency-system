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
| Ambil data competency
|--------------------------------------------------------------------------
*/
if ($search !== '') {
    $query = "
        SELECT
            id,
            name,
            code,
            description
        FROM competencies
        WHERE
            name LIKE ?
            OR description LIKE ?
        ORDER BY name ASC
    ";
    $stmt = mysqli_prepare(
        $conn,
        $query
    );
    $keyword = "%" . $search . "%";
    mysqli_stmt_bind_param(
        $stmt,
        "ss",
        $keyword,
        $keyword
    );
    mysqli_stmt_execute($stmt);
    $result =
        mysqli_stmt_get_result($stmt);
} else {
    $query = "
        SELECT
            id,
            name,
            code,
            description
        FROM competencies
        ORDER BY name ASC
    ";
    $result =
        mysqli_query(
            $conn,
            $query
        );
}
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
                    Competency Management
                </h1>
                <p>
                    Manage employee competency items
                </p>
            </div>
            <div class="d-flex gap-2">
                <a href="dashboard.php" class="btn btn-outline-secondary">
                    &larr; Dashboard
                </a>
                <a href="competency_add.php" class="btn btn-primary">
                    + Add Competency
                </a>
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
                        $number = 1;
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
                                            <a href="competency_edit.php?id=<?php echo $competency['id']; ?>"
                                                class="btn btn-sm btn-outline-primary">
                                                Edit
                                            </a>
                                            <a href="competency_delete.php?id=<?php echo $competency['id']; ?>"
                                                class="btn btn-sm btn-outline-danger"
                                                onclick="return confirm('Apakah Anda yakin ingin menghapus kompetensi ini?');">
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
                                    Tidak ada data kompetensi.
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