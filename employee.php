<?php

require_once "config/database.php";
require_once "includes/competency_helper.php";

/*
|--------------------------------------------------------------------------
| Ambil ID kompetensi karyawan
|--------------------------------------------------------------------------
|
| URL contoh:
|
| employee.php?id=1
|
*/

$competencyId = isset($_GET['id'])
    ? (int) $_GET['id']
    : 0;

if ($competencyId <= 0) {
    die("Data kompetensi tidak valid.");
}

/*
|--------------------------------------------------------------------------
| Ambil data karyawan + kompetensi
|--------------------------------------------------------------------------
*/

$query = "
    SELECT

        ec.id AS employee_competency_id,
        ec.training_date,
        ec.expiry_date,
        ec.score,
        ec.trainer,
        ec.certificate_number,
        ec.training_provider,
        ec.status,

        e.id AS employee_id,
        e.nik,
        e.name AS employee_name,
        e.department,
        e.position,
        e.license_id,
        e.photo,

        c.id AS competency_id,
        c.name AS competency_name,
        c.description,
        c.scope,
        c.validity_note,
        c.icon

    FROM employee_competencies ec

    INNER JOIN employees e
        ON ec.employee_id = e.id

    INNER JOIN competencies c
        ON ec.competency_id = c.id

    WHERE ec.id = ?
";


$stmt = mysqli_prepare($conn, $query);


if (!$stmt) {
    die("Query gagal dipersiapkan.");
}


mysqli_stmt_bind_param(
    $stmt,
    "i",
    $competencyId
);


mysqli_stmt_execute($stmt);

$result = mysqli_stmt_get_result($stmt);
$data = mysqli_fetch_assoc($result);


if (!$data) {
    die("Data kompetensi tidak ditemukan.");
}
?>

<!DOCTYPE html>
<html lang="en">

<head>

    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>
        Competency Detail -
        <?php echo htmlspecialchars($data['employee_name']); ?>
    </title>

    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">

    <link rel="stylesheet" href="assets/css/style.css">
</head>

<body>
    <div class="container py-3">
        <div class="detail-card">
            <div class="detail-header">
                <div class="logo">
                    <img src="assets/images/Bekaert_logo_neg_RGB.png" alt="Bekaert" class="brand-logo">
                </div>
            </div>
            <div class="back-link">
                <a href="index.php?nik=<?php echo urlencode($data['nik']); ?>">
                    ← Kembali ke Ringkasan
                </a>
            </div>
            <div class="detail-profile">
                <div class="detail-photo">
                    <?php if (!empty($data['photo'])): ?>
                        <img src="uploads/employees/<?php echo htmlspecialchars($data['photo']); ?>" alt="Employee Photo">
                    <?php else: ?>
                        <div class="photo-placeholder">
                            <?php
                            echo strtoupper(
                                substr($data['employee_name'], 0, 1)
                            );
                            ?>
                        </div>
                    <?php endif; ?>
                </div>

                <div class="detail-employee-info">
                    <h2>
                        <?php
                        echo htmlspecialchars(
                            $data['employee_name']
                        );
                        ?>
                    </h2>

                    <div>
                        NIK :
                        <?php
                        echo htmlspecialchars(
                            $data['nik']
                        );
                        ?>
                    </div>

                    <div>
                        <?php
                        echo htmlspecialchars(
                            $data['department']
                        );
                        ?>
                        -
                        <?php
                        echo htmlspecialchars(
                            $data['position']
                        );
                        ?>
                    </div>

                    <div class="detail-status <?php echo competencyStatusColorClass($data['status']); ?>">
                        <?php echo competencyStatusIcon($data['status']); ?> STATUS:
                        <?php
                        echo htmlspecialchars(
                            strtoupper(competencyStatusLabel($data['status']))
                        );
                        ?>

                    </div>
                </div>
            </div>
            <div class="detail-tabs">
                <div class="tab active">
                    DETAIL
                </div>
                <a class="tab" href="certificate.php?id=<?php echo $data['employee_competency_id']; ?>">
                    SERTIFIKAT
                </a>
            </div>
            <div class="information-section">
                <h3>
                    INFORMASI UMUM
                </h3>
                <div class="information-row">
                    <span>License ID</span>
                    <strong>
                        :
                        <?php
                        echo htmlspecialchars(
                            $data['license_id']
                        );
                        ?>
                    </strong>
                </div>

                <div class="information-row">
                    <span>Issue Date</span>
                    <strong>
                        :
                        <?php
                        echo empty($data['training_date'])
                            ? '-'
                            : date('d M Y', strtotime($data['training_date']));
                        ?>
                    </strong>
                </div>

                <div class="information-row">
                    <span>Expiry Date</span>
                    <strong>
                        :
                        <?php
                        echo empty($data['expiry_date'])
                            ? 'No Expiry'
                            : date('d M Y', strtotime($data['expiry_date']));
                        ?>
                    </strong>
                </div>

                <div class="information-row">
                    <span>Department</span>
                    <strong>
                        :
                        <?php
                        echo htmlspecialchars(
                            $data['department']
                        );
                        ?>
                    </strong>
                </div>

                <div class="information-row">
                    <span>Position</span>
                    <strong>
                        :
                        <?php
                        echo htmlspecialchars(
                            $data['position']
                        );
                        ?>
                    </strong>
                </div>
            </div>
            <div class="competency-detail-section">
                <div class="competency-detail-title">
                    <div class="detail-icon">
                        <?php
                        echo htmlspecialchars(
                            $data['icon'] ?? '★'
                        );
                        ?>
                    </div>

                    <div>
                        KOMPETENSI:
                        <?php
                        echo strtoupper(
                            htmlspecialchars(
                                $data['competency_name']
                            )
                        );
                        ?>
                    </div>

                    <span class="detail-valid <?php echo competencyStatusColorClass($data['status']); ?>">
                        <?php
                        echo htmlspecialchars(
                            competencyStatusLabel($data['status'])
                        );
                        ?>
                    </span>
                </div>
                <div class="training-information">
                    <div class="training-row">
                        <span>Status</span>
                        <strong>
                            :
                            <?php
                            echo $data['status'] === 'VALID'
                                ? 'Passed'
                                : htmlspecialchars(competencyStatusLabel($data['status']));
                            ?>
                        </strong>
                    </div>

                    <div class="training-row">
                        <span>Score</span>

                        <strong>
                            :
                            <?php
                            echo htmlspecialchars(
                                (string) ($data['score'] ?? '-')
                            );
                            ?>
                            / 100
                        </strong>

                    </div>


                    <div class="training-row">

                        <span>Training Date</span>

                        <strong>
                            :
                            <?php
                            echo empty($data['training_date'])
                                ? '-'
                                : date('d M Y', strtotime($data['training_date']));
                            ?>
                        </strong>

                    </div>


                    <div class="training-row">

                        <span>Expired Date</span>

                        <strong>
                            :
                            <?php
                            echo empty($data['expiry_date'])
                                ? 'No Expiry'
                                : date('d M Y', strtotime($data['expiry_date']));
                            ?>
                        </strong>

                    </div>


                    <div class="training-row">

                        <span>Trainer</span>

                        <strong>
                            :
                            <?php
                            echo htmlspecialchars(
                                $data['trainer'] ?? ''
                            );
                            ?>
                        </strong>

                    </div>


                    <div class="training-row">

                        <span>Certificate No.</span>

                        <strong>
                            :
                            <?php
                            echo htmlspecialchars(
                                $data['certificate_number'] ?? ''
                            );
                            ?>
                        </strong>

                    </div>


                    <div class="training-row">

                        <span>Training Provider</span>

                        <strong>
                            :
                            <?php
                            echo htmlspecialchars(
                                $data['training_provider'] ?? ''
                            );
                            ?>
                        </strong>

                    </div>

                </div>

            </div>

            <div class="description-section">

                <h3>
                    DESKRIPSI KOMPETENSI
                </h3>


                <p>
                    <?php
                    echo nl2br(
                        htmlspecialchars(
                            $data['description'] ?? '-'
                        )
                    );
                    ?>
                </p>

            </div>

            <div class="scope-section">

                <h3>
                    RUANG LINGKUP OTORISASI
                </h3>


                <?php

                $scopeItems = preg_split(
                    "/\r\n|\n|\r/",
                    $data['scope'] ?? ''
                );

                foreach ($scopeItems as $item):

                    $item = trim($item);

                    if ($item === '') {
                        continue;
                    }

                    ?>

                    <div class="scope-item">

                        <span class="scope-check">
                            ✓
                        </span>

                        <span>
                            <?php
                            echo htmlspecialchars($item);
                            ?>
                        </span>

                    </div>

                <?php endforeach; ?>

            </div>

            <div class="note-box">

                <div class="note-icon">
                    ⓘ
                </div>

                <div>

                    <strong>
                        CATATAN
                    </strong>

                    <p>
                        <?php
                        echo nl2br(
                            htmlspecialchars(
                                !empty($data['validity_note'])
                                    ? $data['validity_note']
                                    : 'Kompetensi ini berlaku selama 1 tahun sejak tanggal training. Perpanjangan wajib dilakukan sebelum masa berlaku berakhir.'
                            )
                        );
                        ?>
                    </p>

                </div>

            </div>

        </div>

    </div>

</body>

</html>