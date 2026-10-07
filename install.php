<?php
require __DIR__.'/includes/app.php';
require __DIR__.'/includes/migrate.php';
try{
    boot();header('Cache-Control: no-store');header('X-Robots-Tag: noindex, nofollow');
    if(($_SERVER['REQUEST_METHOD']??'GET')==='POST'){
        $token=$_POST['setup_token']??'';if(!is_string($token)||env('SETUP_TOKEN')===''||!hash_equals(env('SETUP_TOKEN'),$token))fail(403,'Invalid setup token.');
        migrate();import_legacy();migrate();redirect('/admin/login');
    }
    ?><!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Install Galindos Builders</title><link rel="stylesheet" href="/admin/admin.css"></head><body><main class="admin-main solo"><section class="panel"><h1>Install / Update PHP CMS</h1><p>Back up your database and uploads first. This applies additive migrations and imports legacy content only into empty tables.</p><form method="post"><?php csrf_input(); ?><label>Setup token<input type="password" name="setup_token" required autocomplete="off"></label><button>Install / Update</button></form></section></main></body></html><?php
}catch(Throwable $ex){$status=$ex instanceof PDOException?503:(int)$ex->getCode();if($status<400||$status>599)$status=500;http_response_code($status);error_log('Installer: '.$ex->getMessage());echo '<p role="alert">'.e($status>=500?'Unable to install. Check database credentials and the PHP error log.':$ex->getMessage()).'</p>';}
