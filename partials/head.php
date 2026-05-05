<?php
$app = app_config();
$title = $pageTitle ?? ($app['app_name'] ?? 'Smart Finance 360');
$title = ui_strip_english_parenthetical((string)$title);
$authProfile = current_user_profile();
$themeCssVersion = @filemtime(__DIR__ . '/../assets/css/theme.css');
if ($themeCssVersion === false) {
    $themeCssVersion = time();
}
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
    <link href="https://cdn.datatables.net/1.13.8/css/dataTables.bootstrap5.min.css" rel="stylesheet">
    <link href="<?php echo h(app_base_url('assets/css/theme.css?v=' . (string)$themeCssVersion)); ?>" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@400;600;700&display=swap" rel="stylesheet">
    <style>
        .brand-wrapper {
            font-family: 'Poppins', sans-serif;
            margin-bottom: 15px;
        }

        .brand-logo {
            font-size: 36px;
            font-weight: 700;
            letter-spacing: -1px;
            margin: 0;
            line-height: 1.1;
        }

        .g-blue { color: #4285F4; }
        .g-red { color: #DB4437; }
        .g-yellow { color: #F4B400; }
        .g-green { color: #0F9D58; }

        .brand-link {
            display: block;
            font-size: 13px;
            color: #4285F4;
            text-decoration: none;
            font-weight: 600;
            margin-top: 4px;
            transition: color 0.2s ease-in-out;
        }

        .brand-link:hover {
            color: #1a73e8;
            text-decoration: underline;
        }
    </style>
</head>
<body>
<header class="topbar shadow-sm">
    <div class="brand-wrapper">
        <h1 class="brand-logo">
            <span class="g-blue">n</span><span class="g-red">a</span><span class="g-yellow">n</span><span class="g-green">o</span><span class="g-blue">f</span><span class="g-red">i</span><span class="g-yellow">n</span><span class="g-green">3</span><span class="g-blue">6</span><span class="g-red">0</span>
        </h1>
        <a href="register.php" target="_blank" class="brand-link">
            Download Free Finance & Leasing Software
        </a>
    </div>
    <div class="user-switch">
        <div class="small text-muted">System User: <strong><?php echo h(current_user_name()); ?></strong></div>
        <div class="small text-muted">User Role: <strong><?php echo h(thai_role_label(current_role_name())); ?></strong></div>
        <div class="small text-muted">Branch: <strong><?php echo h((string)($authProfile['branch_code'] ?? '-')); ?></strong></div>
        <div class="d-flex flex-wrap gap-2 justify-content-end mt-1">
            <button class="btn btn-sm btn-outline-primary" type="button" data-bs-toggle="modal" data-bs-target="#changePasswordModal">
                Change Password
            </button>
            <form method="post" action="">
                <?php echo csrf_input(); ?>
                <input type="hidden" name="__action" value="logout">
                <button class="btn btn-sm btn-outline-secondary" type="submit">Sign Out</button>
            </form>
        </div>
    </div>
</header>

<div class="modal fade" id="changePasswordModal" tabindex="-1" aria-labelledby="changePasswordModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content border-0 shadow">
            <form method="post" class="validate-form" novalidate>
                <?php echo csrf_input(); ?>
                <input type="hidden" name="__action" value="change_password">
                <div class="modal-header">
                    <h2 class="h6 mb-0" id="changePasswordModalLabel">Change Password</h2>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body">
                    <div class="mb-3">
                        <label class="form-label">Current Password *</label>
                        <input class="form-control" type="password" name="current_password" autocomplete="current-password" required>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">New Password *</label>
                        <input class="form-control" type="password" name="new_password" minlength="3" autocomplete="new-password" required>
                        <div class="form-text">At least 3 characters and must be different from the current password.</div>
                    </div>
                    <div>
                        <label class="form-label">Confirm New Password *</label>
                        <input class="form-control" type="password" name="confirm_password" minlength="3" autocomplete="new-password" required>
                    </div>
                </div>
                <div class="modal-footer">
                    <button class="btn btn-brand" type="submit">Save New Password</button>
                    <button class="btn btn-outline-secondary" type="button" data-bs-dismiss="modal">Close</button>
                </div>
            </form>
        </div>
    </div>
</div>

<div class="layout-wrap">
