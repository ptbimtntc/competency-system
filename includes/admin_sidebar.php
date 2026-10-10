<?php
$__current = basename($_SERVER['SCRIPT_NAME']);
$__menuItems = [
    ['label' => 'Dashboard', 'icon' => 'bi-speedometer2', 'href' => 'dashboard.php', 'match' => ['dashboard.php']],
    ['label' => 'Employees', 'icon' => 'bi-people-fill', 'href' => 'employees.php', 'match' => [
        'employees.php', 'employee_add.php', 'employee_edit.php', 'employees_import.php',
        'employee_competencies.php', 'employee_competency_edit.php', 'employee_competencies_import.php',
    ]],
    ['label' => 'Competencies', 'icon' => 'bi-lightning-charge-fill', 'href' => 'competencies.php', 'match' => [
        'competencies.php', 'competency_add.php', 'competency_edit.php', 'competencies_import.php',
        'competency_questions.php', 'competency_question_add.php', 'competency_question_edit.php',
        'competency_questions_import.php', 'competency_history.php', 'competency_assign.php',
    ]],
    ['label' => 'Attendance', 'icon' => 'bi-clipboard2-check-fill', 'href' => 'attendance.php', 'match' => [
        'attendance.php', 'confirm_attendance_import.php',
    ]],
    ['label' => 'Recertification Due', 'icon' => 'bi-clock-history', 'href' => 'recertification.php', 'match' => ['recertification.php']],
    ['label' => 'Competency Matrix', 'icon' => 'bi-grid-3x3-gap-fill', 'href' => 'competency_matrix.php', 'match' => ['competency_matrix.php']],
    ['label' => 'Competency Gap', 'icon' => 'bi-bullseye', 'href' => 'competency_gap.php', 'match' => ['competency_gap.php']],
    ['label' => 'Required Competency', 'icon' => 'bi-pin-angle-fill', 'href' => 'position_requirements.php', 'match' => ['position_requirements.php']],
    ['label' => 'QR Codes', 'icon' => 'bi-qr-code', 'href' => 'qr_codes.php', 'match' => ['qr_codes.php']],
    ['label' => 'Signatories', 'icon' => 'bi-pen-fill', 'href' => 'signatories.php', 'match' => [
        'signatories.php', 'signatory_add.php', 'signatory_edit.php',
    ]],
];
if (admin_is_superadmin()) {
    $__menuItems[] = ['label' => 'Admin Accounts', 'icon' => 'bi-shield-lock-fill', 'href' => 'admins.php', 'match' => [
        'admins.php', 'admin_add.php', 'admin_edit.php',
    ]];
    $__menuItems[] = ['label' => 'Portal Roles', 'icon' => 'bi-person-badge-fill', 'href' => 'portal_roles.php', 'match' => [
        'portal_roles.php', 'portal_role_add.php', 'portal_role_edit.php',
    ]];
}
?>
<aside class="admin-sidebar">
    <?php foreach ($__menuItems as $__item): ?>
        <a href="<?php echo $__item['href']; ?>"
            class="<?php echo in_array($__current, $__item['match'], true) ? 'active' : ''; ?>">
            <i class="bi <?php echo $__item['icon']; ?>"></i>
            <span><?php echo $__item['label']; ?></span>
        </a>
    <?php endforeach; ?>
</aside>
