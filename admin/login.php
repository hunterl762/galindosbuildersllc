<?php
require dirname(__DIR__).'/includes/app.php';

if(is_admin()){
    header('Location:'.site_url('/admin/'));
    exit;
}

if(!admins()){
    header('Location:'.site_url('/admin/setup.php'));
    exit;
}

$error='';
if($_SERVER['REQUEST_METHOD']==='POST'){
    verify_csrf();
    $username=trim((string)($_POST['username']??''));
    $password=(string)($_POST['password']??'');

    foreach(admins() as $admin){
        if(hash_equals((string)$admin['username'],$username) && password_verify($password,(string)$admin['password'])){
            session_regenerate_id(true);
            $_SESSION['admin']=$admin['username'];
            $_SESSION['admin_login_at']=time();
            header('Location:'.site_url('/admin/'));
            exit;
        }
    }
    $error='The username or password you entered is incorrect.';
}
?><!doctype html>
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
        <form class="login-card" method="post" autocomplete="on">
            <div class="login-mobile-brand"><div class="admin-logo">GB</div><b>Galindos Builders LLC</b></div>
            <div>
                <p class="login-eyebrow">ADMIN PORTAL</p>
                <h2>Welcome back</h2>
                <p class="login-help">Sign in with your administrator account to continue.</p>
            </div>
            <?php if($error):?><div class="alert" role="alert"><?=e($error)?></div><?php endif;?>
            <input type="hidden" name="csrf" value="<?=e(csrf_token())?>">
            <label>Username<input name="username" required autocomplete="username" autofocus value="<?=e((string)($_POST['username']??''))?>"></label>
            <label>Password<div class="password-field"><input id="admin-password" type="password" name="password" required autocomplete="current-password"><button type="button" class="password-toggle" data-password-toggle aria-label="Show password">Show</button></div></label>
            <button class="login-submit" type="submit">Sign In to Dashboard</button>
            <p class="login-security">Administrator access only. Login credentials are verified against the website's SQL administrator database.</p>
        </form>
    </section>
</div>
<script>
document.querySelector('[data-password-toggle]')?.addEventListener('click',function(){const input=document.getElementById('admin-password');const show=input.type==='password';input.type=show?'text':'password';this.textContent=show?'Hide':'Show';this.setAttribute('aria-label',show?'Hide password':'Show password');});
</script>
</body>
</html>