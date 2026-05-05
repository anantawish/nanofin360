<?php
$modules = all_modules();
uasort($modules, static function (array $a, array $b): int {
    return ((int)($a['id'] ?? 0)) <=> ((int)($b['id'] ?? 0));
});
$hiddenMenuModuleIds = [5, 8];
$modules = array_filter(
    $modules,
    static function (array $module) use ($hiddenMenuModuleIds): bool {
        return !in_array((int)($module['id'] ?? 0), $hiddenMenuModuleIds, true);
    }
);
$current = $currentModule ?? '';
$canSeeAdminMenu = strtolower(trim(current_role_name())) === 'admin';
?>
<aside class="sidebar">
    <nav>
        <a class="menu-link <?php echo $current === '' ? 'active' : ''; ?>" href="<?php echo h(app_base_url('index.php')); ?>">
            <span class="menu-link-th">Dashboard</span>
        </a>
        <?php $menuNumber = 1; ?>
        <?php foreach ($modules as $key => $mod): ?>
            <?php $localizedModule = ui_localize_module_definition($mod + ['key' => $key]); ?>
            <a class="menu-link <?php echo $current === $key ? 'active' : ''; ?>" href="<?php echo h(app_base_url('modules/' . $mod['file'])); ?>">
                <span class="menu-link-th"><?php echo h($menuNumber . '. ' . (string)$localizedModule['title']); ?></span>
            </a>
            <?php $menuNumber++; ?>
        <?php endforeach; ?>
        <?php if ($canSeeAdminMenu): ?>
            <a class="menu-link <?php echo $current === 'admin' ? 'active' : ''; ?>" href="<?php echo h(app_base_url('admin.php')); ?>">
                <span class="menu-link-th">Admin: Branch & User Management</span>
            </a>
        <?php endif; ?>
    </nav>
</aside>
<main class="main-content">
    <?php include __DIR__ . '/notifications.php'; ?>
