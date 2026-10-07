<?php
require __DIR__.'/includes/app.php';require ROOT.'/includes/migrate.php';require ROOT.'/includes/diagnostics.php';require ROOT.'/includes/installer.php';
$notice='';$error='';$values=[];
try{
    boot(false);header('Cache-Control: no-store');header('X-Robots-Tag: noindex, nofollow');
    if(($_SERVER['REQUEST_METHOD']??'GET')==='POST'){
        $action=$_POST['action']??'';
        if($action==='unlock'){
            $token=$_POST['setup_token']??'';if(!is_string($token)||env('SETUP_TOKEN')===''||!hash_equals(env('SETUP_TOKEN'),$token))fail(403,'Invalid setup token.');
            session_regenerate_id(true);$_SESSION['installer_auth']=hash('sha256',env('SETUP_TOKEN'));$_SESSION['installer_at']=time();redirect('/installer.php');
        }
        if(!installer_authorized())fail(403,'Unlock the installer with your setup token first.');
        if($action==='lock'){unset($_SESSION['installer_auth'],$_SESSION['installer_at']);redirect('/installer.php');}
        $config=installer_configuration($_POST,$action!=='test_database');$values=$config;
        if($action==='test_database'){installer_database($config);$notice='Database connection successful. Configuration has not been saved.';}
        elseif($action==='test_smtp'){installer_smtp($config);$notice='SMTP connection and authentication succeeded. No email was sent; configuration has not been saved.';}
        elseif($action==='install'){
            installer_database($config);
            foreach($config as $key=>$value){$system=getenv($key);if($system!==false&&(string)$system!==(string)$value)fail(422,'The hosting environment overrides '.$key.'. Update or remove that cPanel environment variable before saving.');}
            save_installer_configuration($config,ROOT.'/.env');$GLOBALS['installer_environment']=$config;
            migrate();import_legacy();migrate();unset($_SESSION['installer_auth'],$_SESSION['installer_at']);redirect('/admin/login');
        }else fail(422,'Choose a valid installer action.');
    }
}catch(Throwable $ex){[$status,$error]=installation_error($ex);http_response_code($status);error_log('Installer: '.$ex->getMessage());}
$unlocked=installer_authorized();
$defaults=['SITE_URL'=>env('SITE_URL','http://localhost:8000'),'DB_HOST'=>env('DB_HOST','localhost'),'DB_PORT'=>env('DB_PORT','3306'),'DB_NAME'=>env('DB_NAME'),'DB_USER'=>env('DB_USER'),'MAIL_TRANSPORT'=>env('MAIL_TRANSPORT','disabled'),'SMTP_HOST'=>env('SMTP_HOST'),'SMTP_PORT'=>env('SMTP_PORT','587'),'SMTP_USER'=>env('SMTP_USER'),'SMTP_SECURE'=>env('SMTP_SECURE','false'),'MAIL_FROM'=>env('MAIL_FROM'),'QUOTE_NOTIFY_EMAIL'=>env('QUOTE_NOTIFY_EMAIL')];
$values=array_merge($defaults,$values);unset($values['DB_PASS'],$values['SMTP_PASS']);
?>
<!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><meta name="robots" content="noindex,nofollow"><title>Website Installer | Galindos Builders</title><link rel="stylesheet" href="/admin/admin.css"></head><body><main class="admin-main solo"><section class="panel"><h1>Website Installer</h1><p>Configure the database and email delivery, then install or update the CMS. Back up existing SQL data and uploads before updating.</p>
<?php if($error):?><p class="alert" role="alert"><?=e($error)?></p><?php endif;?><?php if($notice):?><p class="success" role="status"><?=e($notice)?></p><?php endif;?>
<?php if(!$unlocked):?><form method="post"><?php csrf_input();?><input type="hidden" name="action" value="unlock"><label>Setup token<input type="password" name="setup_token" required autocomplete="off"></label><button>Unlock Installer</button></form><p>Use the SETUP_TOKEN configured privately on your server.</p>
<?php else:?><form method="post" class="form-grid" autocomplete="off"><?php csrf_input();?><h2 class="full">Website &amp; Database</h2>
<?php foreach(['SITE_URL'=>'Website URL','DB_HOST'=>'Database hostname','DB_PORT'=>'Database port','DB_NAME'=>'Full database name','DB_USER'=>'Full database username'] as $key=>$label):?><label><?=e($label)?><input name="<?=e($key)?>" value="<?=e($values[$key])?>" type="<?=$key==='DB_PORT'?'number':'text'?>" required></label><?php endforeach;?><label>Database password<input name="DB_PASS" type="password" autocomplete="new-password"><small>Leave blank to keep the saved password.</small></label><p class="full">Use the full cPanel database and user names, including their account prefixes. Create the database/user in cPanel first and grant installation privileges.</p>
<h2 class="full">Email / SMTP Setup</h2><label>Email transport<select name="MAIL_TRANSPORT"><?php foreach(['disabled'=>'Disabled','smtp'=>'SMTP','mail'=>'Hosting PHP mail'] as $key=>$label):?><option value="<?=e($key)?>" <?=$values['MAIL_TRANSPORT']===$key?'selected':''?>><?=e($label)?></option><?php endforeach;?></select></label>
<?php foreach(['SMTP_HOST'=>'SMTP hostname','SMTP_PORT'=>'SMTP port','SMTP_USER'=>'SMTP username','MAIL_FROM'=>'Sender email address','QUOTE_NOTIFY_EMAIL'=>'Company notification email'] as $key=>$label):?><label><?=e($label)?><input name="<?=e($key)?>" value="<?=e($values[$key])?>" type="<?=str_contains($key,'EMAIL')||$key==='MAIL_FROM'?'email':($key==='SMTP_PORT'?'number':'text')?>"></label><?php endforeach;?><label>SMTP password<input type="password" name="SMTP_PASS" autocomplete="new-password"><small>Leave blank to keep the saved password.</small></label><label>SMTP encryption<select name="SMTP_SECURE"><option value="false" <?=$values['SMTP_SECURE']==='false'?'selected':''?>>STARTTLS (usually port 587)</option><option value="true" <?=$values['SMTP_SECURE']==='true'?'selected':''?>>Implicit TLS (usually port 465)</option></select></label>
<div class="full"><button name="action" value="test_database">Test Database</button> <button name="action" value="test_smtp">Test SMTP Connection</button> <button name="action" value="install">Save &amp; Install / Update</button></div><p class="full">Tests do not save settings or send email. After testing, re-enter any passwords that have not been saved before clicking Save &amp; Install. Configuration is saved privately to .env; SMTP delivery still requires the cron job described in the README.</p></form><form method="post"><?php csrf_input();?><button name="action" value="lock">Lock Installer</button></form><?php endif;?></section></main></body></html>
