<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>Admin Login | Galindos Builders LLC</title>
<meta name="robots" content="noindex,nofollow">
<link rel="stylesheet" href="<?=e(asset_url('/admin/admin.css'))?>">
</head>
<body class="login-body">
<div class="login-shell">
    <section class="login-brand-panel">
        <a class="login-site-link" href="<?=e(site_url('/'))?>">← Return to website</a>
        <div class="login-brand-content">
            <div class="admin-logo login-logo">GB</div>
            <p class="login-eyebrow">GALINDOS BUILDERS LLC</p>
            <h1>Website Administration</h1>
            <p>Manage projects, galleries, custom pages, services, navigation, SEO settings and quote requests from one secure dashboard.</p>
        </div>
    </section>
    <section class="login-form-panel">
        <form class="login-card" method="post" action="/admin/<?= $setup?'setup':'login' ?>" autocomplete="on">
            <div class="login-mobile-brand"><div class="admin-logo">GB</div><b>Galindos Builders LLC</b></div>
            <div>
                <p class="login-eyebrow">ADMIN PORTAL</p>
                <h2><?= $setup?'Create Administrator':'Welcome back' ?></h2>
                <p class="login-help">Sign in with your administrator account to continue.</p>
            </div>
            <?php if($error):?><div class="alert" role="alert"><?=e($error)?></div><?php endif;?>
            <input type="hidden" name="csrf" value="<?=e(csrf_token())?>">
            <?php if($setup):?><label>Setup token<input type="password" name="setup_token" required autocomplete="off"></label><?php endif;?><label>Username<input name="username" required autocomplete="username" autofocus value="<?=e((string)($_POST['username']??''))?>"></label>
            <label>Password<div class="password-field"><input id="admin-password" type="password" name="password" required autocomplete="current-password"><button type="button" class="password-toggle" data-password-toggle aria-label="Show password">Show</button></div></label>
            <button class="login-submit" type="submit">Sign In to Dashboard</button>
            <p class="login-security">Administrator access only. Login credentials are verified against the website's SQL administrator database.</p>
        </form>
    </section>
</div>
<script defer src="/assets/js/admin.js"></script>
</body>
</html>