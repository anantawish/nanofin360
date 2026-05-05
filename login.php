<?php
declare(strict_types=1);

require_once __DIR__ . '/bootstrap.php';

$returnTo = trim((string)($_GET['return_to'] ?? app_base_url('index.php')));
if ($returnTo === '' || preg_match('/^https?:\/\//i', $returnTo) === 1) {
    $returnTo = app_base_url('index.php');
}
if (!str_starts_with($returnTo, '/')) {
    $returnTo = '/' . ltrim($returnTo, '/');
}

$app = app_config();
$title = 'Sign In - ' . (string)($app['app_name'] ?? 'Smart Finance 360');
$flashes = consume_flashes();
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?php echo h($title); ?></title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Montserrat:wght@500;700&family=Source+Sans+3:wght@400;600&display=swap" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="<?php echo h(app_base_url('assets/css/theme.css')); ?>" rel="stylesheet">
</head>
<body>
<div class="container py-5">
    <div class="row justify-content-center">
        <div class="col-lg-5 col-md-7">
            <div class="card shadow-sm border-0 module-hero">
                <div class="card-body p-4">
                    <h1 class="h4 mb-1">Smart Finance 360</h1>
                    <p class="text-muted mb-4">Sign in to continue.</p>

                    <?php foreach ($flashes as $flash): ?>
                        <div class="alert alert-<?php echo h((string)$flash['type']); ?> py-2" role="alert">
                            <?php echo h((string)$flash['message']); ?>
                        </div>
                    <?php endforeach; ?>

                    <form method="post" class="validate-form" novalidate>
                        <?php echo csrf_input(); ?>
                        <input type="hidden" name="__action" value="login">
                        <input type="hidden" name="return_to" value="<?php echo h($returnTo); ?>">

                        <div class="mb-3">
                            <label class="form-label">Username</label>
                            <input class="form-control" name="user_name" maxlength="100" autocomplete="username" required>
                        </div>
                        <div class="mb-3">
                            <label class="form-label">Password</label>
                            <input class="form-control" type="password" name="password" autocomplete="current-password" required>
                        </div>
                        <button class="btn btn-brand w-100" type="submit">Sign In</button>
                    </form>
                </div>
            </div>
        </div>
    </div>
</div>
<script src="https://cdn.jsdelivr.net/npm/jquery@3.7.1/dist/jquery.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<?php $appJsVersion = @filemtime(__DIR__ . '/assets/js/app.js'); if ($appJsVersion === false) { $appJsVersion = time(); } ?>
<script src="<?php echo h(app_base_url('assets/js/app.js?v=' . (string)$appJsVersion)); ?>"></script>
</body>
</html>
