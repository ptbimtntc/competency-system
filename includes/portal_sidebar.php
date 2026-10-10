<?php
$__current = basename($_SERVER['SCRIPT_NAME']);
$__menuFileMap = [
    'dashboard' => 'dashboard.php',
    'team_members' => 'team_members.php',
    'team_competency_matrix' => 'team_competency_matrix.php',
    'team_competency_gap' => 'team_competency_gap.php',
    'team_recertification' => 'team_recertification.php',
    'team_attendance' => 'team_attendance.php',
];
$__menuResult = mysqli_query($conn, "SELECT slug, label, icon FROM portal_menus ORDER BY sort_order ASC");
?>
<aside class="admin-sidebar">
    <?php while ($__menu = mysqli_fetch_assoc($__menuResult)): ?>
        <?php if (!portal_can_view($__menu['slug']) || !isset($__menuFileMap[$__menu['slug']])) continue; ?>
        <a href="<?php echo $__menuFileMap[$__menu['slug']]; ?>"
            class="<?php echo $__current === $__menuFileMap[$__menu['slug']] ? 'active' : ''; ?>">
            <i class="bi <?php echo htmlspecialchars($__menu['icon']); ?>"></i>
            <span><?php echo htmlspecialchars($__menu['label']); ?></span>
        </a>
    <?php endwhile; ?>
</aside>
