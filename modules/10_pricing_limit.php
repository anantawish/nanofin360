<?php
declare(strict_types=1);
require_once __DIR__ . '/../bootstrap.php';

$moduleKey = 'pricing_limit';
$context = handle_module_request($moduleKey);
$pageTitle = $context['module']['title'];
$currentModule = $moduleKey;

include __DIR__ . '/../partials/head.php';
include __DIR__ . '/../partials/menu.php';
render_module_page($context);
include __DIR__ . '/../partials/footer.php';
