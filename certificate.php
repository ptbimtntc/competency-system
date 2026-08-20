<?php

require_once "config/database.php";


/*
|--------------------------------------------------------------------------
| Ambil ID employee_competencies
|--------------------------------------------------------------------------
*/

$competencyId = isset($_GET['id'])
    ? (int) $_GET['id']
    : 0;


if ($competencyId <= 0) {
    die("Data sertifikat tidak valid.");
}


/*
|--------------------------------------------------------------------------
| Ambil data sertifikat
|--------------------------------------------------------------------------
*/

$query = "
    SELECT

        ec.id AS employee_competency_id,

        ec.score,
        ec.training_date,
        ec.expiry_date,
        ec.trainer,
        ec.training_provider,
        ec.certificate_number,
        ec.authorizer_title,
        ec.authorizer_name,
        ec.status,

        e.id AS employee_id,
        e.nik,
        e.name AS employee_name,
        e.department,
        e.position,
        e.license_id,
        e.photo,

        c.name AS competency_name,
        c.description,
        c.icon,

        ts.signature AS trainer_signature,
        aus.signature AS authorizer_signature

    FROM employee_competencies ec

    INNER JOIN employees e
        ON ec.employee_id = e.id

    INNER JOIN competencies c
        ON ec.competency_id = c.id

    LEFT JOIN signatories ts
        ON ts.id = ec.trainer_signatory_id

    LEFT JOIN signatories aus
        ON aus.id = ec.authorizer_signatory_id

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
    die("Data sertifikat tidak ditemukan.");
}


/*
|--------------------------------------------------------------------------
| Fallback: cocokkan tanda tangan berdasarkan nama
|--------------------------------------------------------------------------
|
| Jika signatory tidak dipilih manual (trainer_signatory_id /
| authorizer_signatory_id kosong), cari signatory dengan nama yang sama
| persis (case-insensitive) dengan field trainer / authorizer_name.
|
*/
if (empty($data['trainer_signature']) && !empty($data['trainer'])) {
    $nameStmt = mysqli_prepare(
        $conn,
        "SELECT signature FROM signatories WHERE LOWER(TRIM(name)) = LOWER(TRIM(?)) AND signature IS NOT NULL LIMIT 1"
    );
    mysqli_stmt_bind_param($nameStmt, "s", $data['trainer']);
    mysqli_stmt_execute($nameStmt);
    $nameMatch = mysqli_fetch_assoc(mysqli_stmt_get_result($nameStmt));
    if ($nameMatch) {
        $data['trainer_signature'] = $nameMatch['signature'];
    }
}
if (empty($data['authorizer_signature']) && !empty($data['authorizer_name'])) {
    $nameStmt = mysqli_prepare(
        $conn,
        "SELECT signature FROM signatories WHERE LOWER(TRIM(name)) = LOWER(TRIM(?)) AND signature IS NOT NULL LIMIT 1"
    );
    mysqli_stmt_bind_param($nameStmt, "s", $data['authorizer_name']);
    mysqli_stmt_execute($nameStmt);
    $nameMatch = mysqli_fetch_assoc(mysqli_stmt_get_result($nameStmt));
    if ($nameMatch) {
        $data['authorizer_signature'] = $nameMatch['signature'];
    }
}


/*
|--------------------------------------------------------------------------
| Format tanggal
|--------------------------------------------------------------------------
*/

$trainingDate = empty($data['training_date'])
    ? '-'
    : date('d M Y', strtotime($data['training_date']));


$expiryDate = empty($data['expiry_date'])
    ? 'No Expiry'
    : date('d M Y', strtotime($data['expiry_date']));

?>

<!DOCTYPE html>
<html lang="en">

<head>

    <meta charset="UTF-8">

    <meta
        name="viewport"
        content="width=device-width, initial-scale=1.0"
    >

    <title>
        Certificate -
        <?php echo htmlspecialchars($data['employee_name']); ?>
    </title>


    <link
        href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css"
        rel="stylesheet"
    >


    <link
        rel="stylesheet"
        href="assets/css/style.css"
    >

</head>


<body class="certificate-page">

<div class="certificate-actions">

    <a
        href="employee.php?id=<?php echo $data['employee_competency_id']; ?>"
        class="btn btn-outline-primary"
    >
        ← Kembali
    </a>


    <button
        onclick="window.print()"
        class="btn btn-primary"
    >
        🖨 Print / Save PDF
    </button>

</div>

<div class="certificate-wrapper">

    <div class="certificate">

        <!-- HEADER -->

        <div class="certificate-top">

            <div class="certificate-logo">
                <img src="assets/images/Bekaert_logo_pos_RGB.png" alt="Bekaert" class="brand-logo">
            </div>


            <div class="certificate-label">
                EMPLOYEE COMPETENCY
            </div>

        </div>

<div class="certificate-content">

    <div class="certificate-title">
        CERTIFICATE
    </div>


    <div class="certificate-subtitle">
        OF COMPETENCY
    </div>


    <p class="certificate-intro">
        This is to certify that
    </p>


    <div class="certificate-name">

        <?php
        echo htmlspecialchars(
            $data['employee_name']
        );
        ?>

    </div>


    <div class="certificate-nik">

        NIK :
        <?php
        echo htmlspecialchars(
            $data['nik']
        );
        ?>

    </div>

<p class="certificate-description">

    has successfully completed the training
    and assessment for

</p>


<div class="certificate-competency">

    <?php
    echo strtoupper(
        htmlspecialchars(
            $data['competency_name']
        )
    );
    ?>

</div>


<div class="certificate-score">

    with a score of

    <strong>
        <?php
        echo htmlspecialchars(
            (string) ($data['score'] ?? '-')
        );
        ?>
        / 100
    </strong>

</div>

<p class="certificate-authorization">

    and is authorized to perform basic work
    in accordance with company procedures
    and safety standards.

</p>

<div class="signature-area">

    <div class="signature">

        <?php if (!empty($data['trainer_signature'])): ?>
            <img class="signature-image"
                src="uploads/signatures/<?php echo htmlspecialchars($data['trainer_signature']); ?>"
                alt="Trainer signature">
        <?php endif; ?>

        <div class="signature-line">
        </div>

        <strong>
            <?php
            echo htmlspecialchars(
                $data['trainer'] ?? ''
            );
            ?>
        </strong>

        <span>
            Trainer
        </span>

    </div>


    <div class="passed-stamp">

        PASSED

    </div>


    <div class="signature">

        <?php if (!empty($data['authorizer_signature'])): ?>
            <img class="signature-image"
                src="uploads/signatures/<?php echo htmlspecialchars($data['authorizer_signature']); ?>"
                alt="Authorizer signature">
        <?php endif; ?>

        <div class="signature-line">
        </div>

        <strong>
            <?php
            echo htmlspecialchars(
                !empty($data['authorizer_name'])
                    ? $data['authorizer_name']
                    : ($data['authorizer_title'] ?? 'Maintenance Manager')
            );
            ?>
        </strong>

        <span>
            <?php
            echo !empty($data['authorizer_name'])
                ? htmlspecialchars($data['authorizer_title'] ?? 'Maintenance Manager')
                : 'PT Bekaert Indonesia';
            ?>
        </span>

    </div>

</div>

<div class="certificate-footer">

    <div>

        <span>
            Certificate No.
        </span>

        <strong>
            <?php
            echo htmlspecialchars(
                $data['certificate_number'] ?? ''
            );
            ?>
        </strong>

    </div>


    <div>

        <span>
            Training Date
        </span>

        <strong>
            <?php echo $trainingDate; ?>
        </strong>

    </div>


    <div>

        <span>
            Valid Until
        </span>

        <strong>
            <?php echo $expiryDate; ?>
        </strong>

    </div>

</div>

</div>

</div>

</div>

</body>

</html>