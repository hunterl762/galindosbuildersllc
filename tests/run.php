<?php
declare(strict_types=1);
if(PHP_SAPI!=='cli')exit;
require dirname(__DIR__).'/includes/app.php';require ROOT.'/includes/migrate.php';require ROOT.'/includes/content.php';require ROOT.'/includes/mail.php';
require ROOT.'/includes/diagnostics.php';
require ROOT.'/includes/installer.php';
function ok(bool $condition,string $message):void {if(!$condition)throw new RuntimeException('FAILED: '.$message);echo "PASS: $message\n";}
ok(!str_contains(clean_html('<h2>Safe</h2><script>attack()</script><a href="javascript:alert(1)" onclick="bad()">Link</a>'),'attack'),'HTML sanitization');
foreach(['javascript:alert(1)','//evil.example','/\\evil.example'] as $url){try{safe_url($url);throw new LogicException('Unsafe URL accepted');}catch(RuntimeException $ex){ok($ex->getCode()===422,'Reject unsafe URL');}}
ok(verify_password('long-password-123',str_replace('$2y$','$2b$',password_hash('long-password-123',PASSWORD_BCRYPT))),'Node bcrypt compatibility');
if(defined('PASSWORD_ARGON2ID'))ok(verify_password('long-password-123',password_hash('long-password-123',PASSWORD_ARGON2ID)),'Argon2 account compatibility');
ok(!can(['role'=>'editor','active'=>1],'settings')&&can(['role'=>'owner','active'=>1],'settings'),'Role permissions');
$configurationFile=tempnam(sys_get_temp_dir(),'gb-settings-');file_put_contents($configurationFile,"SETUP_TOKEN=keep-this-token\nUPLOAD_DIR=uploads\nDB_PASS=old\n");
try{$secret='quotes" and apostrophe\' and backslash\\ and $dollar';save_installer_configuration(['DB_PASS'=>$secret,'SMTP_PASS'=>$secret],$configurationFile);$saved=file_get_contents($configurationFile);ok(str_contains($saved,'SETUP_TOKEN=keep-this-token')&&str_contains($saved,'UPLOAD_DIR=uploads'),'Installer preserves unrelated configuration');preg_match('/^DB_PASS=(.*)$/m',$saved,$secretMatch);ok(json_decode($secretMatch[1],true)===$secret,'Installer safely quotes password punctuation');}finally{unlink($configurationFile);}
$sample=['DB_HOST'=>'localhost','DB_PORT'=>'3306','DB_NAME'=>'account_galindos','DB_USER'=>'account_user','SITE_URL'=>'https://example.com','MAIL_TRANSPORT'=>'disabled','SMTP_HOST'=>'','SMTP_PORT'=>'587','SMTP_USER'=>'','SMTP_SECURE'=>'false','MAIL_FROM'=>'','QUOTE_NOTIFY_EMAIL'=>''];
ok(installer_configuration($sample)['COOKIE_SECURE']==='true','Installer configures secure HTTPS cookies');
try{installer_configuration(array_merge($sample,['DB_HOST'=>'localhost;dbname=other']));throw new LogicException('DSN injection accepted');}catch(RuntimeException $ex){ok($ex->getCode()===422,'Installer rejects DSN injection');}
foreach([1045=>'username or password',1049=>'does not exist',2002=>'cannot reach',1142=>'Privileges',1062=>'duplicate'] as $code=>$phrase){$error=new PDOException('Private credential details must not appear');$error->errorInfo=['HY000',$code,'Private credential details'];[$status,$message]=installation_error($error);ok(str_contains($message,$phrase)&&!str_contains($message,'Private credential'),'Safe installer diagnostic '.$code);}
if(!getenv('TEST_DB_PORT')){echo "Database integration skipped; set TEST_DB_PORT to a disposable server.\n";exit;}
$name='galindos_test_php_'.bin2hex(random_bytes(6));$port=getenv('TEST_DB_PORT');$databaseUser=getenv('TEST_DB_USER')?:'root';$databasePassword=getenv('TEST_DB_PASS')?:'';
$admin=new PDO('mysql:host=127.0.0.1;port='.$port,$databaseUser,$databasePassword,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);$admin->exec("CREATE DATABASE `$name` CHARACTER SET utf8mb4");
$uploadDir=sys_get_temp_dir().'/galindos-php-uploads-'.bin2hex(random_bytes(4));mkdir($uploadDir);
foreach(['DB_HOST'=>'127.0.0.1','DB_PORT'=>$port,'DB_NAME'=>$name,'DB_USER'=>$databaseUser,'DB_PASS'=>$databasePassword,'UPLOAD_DIR'=>$uploadDir,'SITE_URL'=>'http://127.0.0.1:33080','SETUP_TOKEN'=>'test-setup-token','COOKIE_SECURE'=>'false','SMTP_HOST'=>'test.invalid','MAIL_FROM'=>'company@example.com','QUOTE_NOTIFY_EMAIL'=>'owner@example.com'] as $k=>$v)putenv($k.'='.$v);
$server=null;$cookies=[];
function http(string $path,array $body=[],?string $upload=null,bool $includeCsrf=true,string $method=''):array {
    global $cookies;
    $method=$method?:($body||$upload!==null?'POST':'GET');$headers=['Cookie: '.implode('; ',array_map(fn($k,$v)=>$k.'='.$v,array_keys($cookies),array_values($cookies)))];
    if($upload!==null){$boundary='GB'.bin2hex(random_bytes(8));$headers[]='Content-Type: multipart/form-data; boundary='.$boundary;if($includeCsrf&&isset($body['csrf']))$headers[]='X-CSRF-Token: '.$body['csrf'];$content='';foreach($body as $k=>$v)$content.="--$boundary\r\nContent-Disposition: form-data; name=\"$k\"\r\n\r\n$v\r\n";$content.="--$boundary\r\nContent-Disposition: form-data; name=\"image\"; filename=\"image.png\"\r\nContent-Type: image/png\r\n\r\n$upload\r\n--$boundary--\r\n";}
    else{$headers[]='Content-Type: application/x-www-form-urlencoded';$content=http_build_query($body);}
    $context=stream_context_create(['http'=>['method'=>$method,'header'=>implode("\r\n",$headers),'content'=>$content,'ignore_errors'=>true,'follow_location'=>0,'timeout'=>20]]);
    $text=file_get_contents('http://127.0.0.1:33080'.$path,false,$context);$responseHeaders=$http_response_header??[];$status=(int)explode(' ',$responseHeaders[0]??' 0 ')[1];
    foreach($responseHeaders as $h)if(preg_match('/^Set-Cookie: ([^=]+)=([^;]*)/i',$h,$m))$cookies[$m[1]]=$m[2];return ['status'=>$status,'text'=>$text,'headers'=>$responseHeaders];
}
function token(array $response):string {if(!preg_match('/name="csrf" value="([a-f0-9]+)"/',$response['text'],$m))throw new RuntimeException('No CSRF token: '.$response['text']);return $m[1];}
try{
    $baseline=preg_replace('/(?:CREATE DATABASE|USE)\b[^;]*;/i','',preg_replace('/^--.*$/m','',file_get_contents(ROOT.'/database/schema.sql')));
    foreach(explode(';',$baseline) as $sql)if(trim($sql)!=='')db()->exec($sql);
    q("INSERT INTO projects(id,slug,name,location,featured) VALUES('legacy','legacy-project','Legacy Project','Maryland',1)");q("INSERT INTO project_images(project_id,image_url) VALUES('legacy','/uploads/projects/old.jpg')");
    q("INSERT INTO pages(id,slug,title,content) VALUES('old-page','about','About','<h2>Approved content</h2>')");
    migrate();migrate();ok(q("SELECT name FROM projects WHERE id='legacy'")[0]['name']==='Legacy Project','Additive PHP migration preserves data on rerun');
    q('INSERT INTO admins(username,password_hash,role) VALUES(?,?,?)',['owner',password_hash_php('owner-password-123'),'owner']);
    $command=[PHP_BINARY];
    if(PHP_OS_FAMILY==='Windows')array_push($command,'-n','-d','extension_dir='.ini_get('extension_dir'),'-d','extension=pdo_mysql','-d','extension=fileinfo','-d','extension=mbstring','-d','extension=openssl');
    array_push($command,'-d','display_errors=0','-S','127.0.0.1:33080',ROOT.'/router.php');
    $server=proc_open($command,[0=>['pipe','r'],1=>['file',$uploadDir.'/server.log','a'],2=>['file',$uploadDir.'/server.log','a']],$pipes,ROOT);
    for($i=0;$i<50;$i++){if(@fsockopen('127.0.0.1',33080,$errno,$errstr,0.1))break;usleep(100000);}
    $installer=http('/installer.php');$installerCsrf=token($installer);ok($installer['status']===200&&!str_contains($installer['text'],'name="DB_USER"'),'Installer hides configuration until unlocked');
    ok(http('/installer.php',['csrf'=>$installerCsrf,'action'=>'unlock','setup_token'=>'wrong'])['status']===403,'Installer rejects invalid setup token');
    ok(http('/installer.php',['csrf'=>$installerCsrf,'action'=>'unlock','setup_token'=>'test-setup-token'])['status']===303,'Installer token unlock');
    $installer=http('/installer.php');$installerCsrf=token($installer);ok(str_contains($installer['text'],'name="DB_USER"')&&str_contains($installer['text'],'name="SMTP_HOST"'),'Installer database and SMTP controls');
    $candidate=['csrf'=>$installerCsrf,'action'=>'test_database','DB_HOST'=>'127.0.0.1','DB_PORT'=>$port,'DB_NAME'=>$name,'DB_USER'=>$databaseUser,'DB_PASS'=>$databasePassword,'SITE_URL'=>'http://127.0.0.1:33080','MAIL_TRANSPORT'=>'disabled','SMTP_HOST'=>'','SMTP_PORT'=>'587','SMTP_USER'=>'','SMTP_SECURE'=>'false','MAIL_FROM'=>'','QUOTE_NOTIFY_EMAIL'=>''];
    $tested=http('/installer.php',$candidate);ok($tested['status']===200&&str_contains($tested['text'],'Database connection successful'),'Installer tests database without saving');
    $tested=http('/installer.php',array_merge($candidate,['DB_PASS'=>'never-render-this-secret']));ok($tested['status']===503&&!str_contains($tested['text'],'never-render-this-secret'),'Installer hides rejected passwords');
    http('/installer.php',['csrf'=>$installerCsrf,'action'=>'lock']);
    $login=http('/admin/login');$csrf=token($login);ok(http('/admin/login',['csrf'=>$csrf,'username'=>'owner','password'=>'owner-password-123'])['status']===303,'Admin login');$csrf=token(http('/admin'));
    foreach(['/','/projects','/project/legacy-project','/page/about','/services/wood-framing','/sitemap.xml','/robots.txt','/admin/projects','/admin/projects/new','/admin/pages','/admin/services','/admin/service-areas','/admin/navigation','/admin/seo','/admin/settings','/admin/media','/admin/home','/admin/sections','/admin/leads','/admin/users','/admin/mail'] as $path){$r=http($path);ok($r['status']===200,'Page '.$path.' ('.$r['status'].')');}
    ok(http('/admin/home',['hero_title'=>'Missing token'])['status']===403,'CSRF enforcement');
    $png=base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAusB9Wl2nXsAAAAASUVORK5CYII=');
    foreach(['favicon_url','meta_image_url'] as $key){$response=http('/admin/settings/images/'.$key,['csrf'=>$csrf],$png);ok($response['status']===201,'Direct branding upload '.$key);$data=json_decode($response['text'],true);ok(setting($key)===$data['url'],'Branding image applied');}
    ok(http('/admin/settings/images/favicon_url',['csrf'=>$csrf],'fake png')['status']===422,'Reject spoofed image');
    ok(http('/admin/projects/save',['csrf'=>$csrf,'slug'=>'case-study','name'=>'Case Study','visible'=>'1','client'=>'Client','completion_date'=>'2026-10-01','square_footage'=>'10000'])['status']===303,'Project metadata save');
    $p=q("SELECT * FROM projects WHERE slug='case-study'")[0];$media=q('SELECT * FROM media WHERE folder=?',['Branding'])[0];
    ok(http('/admin/projects/'.$p['id'].'/gallery',['csrf'=>$csrf,'media_id'=>$media['id'],'sort_order'=>'0','image_kind'=>'before','caption'=>'Before photo'])['status']===303,'Reusable gallery');
    ok(http('/admin/media/'.$media['id'].'/delete',['csrf'=>$csrf])['status']===409,'Protect in-use media');
    ok(str_contains(http('/project/case-study')['text'],'Before photo'),'Case-study gallery rendering');
    ok(http('/admin/service-areas/save',['csrf'=>$csrf,'slug'=>'maryland','title'=>'Maryland','visible'=>'1','content'=>'<h2>Area</h2><script>bad()</script>'])['status']===303,'Service area save');ok(!str_contains(http('/service-areas/maryland')['text'],'bad()'),'Rich content safely rendered');
    ok(http('/admin/sections/values',['csrf'=>$csrf,'sort_order'=>'50'])['status']===303&&!str_contains(http('/')['text'],'class="values"'),'Homepage section visibility');
    ok(http('/quote',['csrf'=>$csrf,'name'=>'Customer','email'=>'customer@example.com','message'=>'Please quote this project.'])['status']===303,'Quote capture');$lead=q('SELECT * FROM quote_submissions')[0];
    ok(http('/admin/leads/'.$lead['id'],['csrf'=>$csrf,'status'=>'Estimate Scheduled','assigned_to'=>'1','note'=>'Call Tuesday'])['status']===303,'Lead assignment and notes');ok(q('SELECT note FROM lead_notes')[0]['note']==='Call Tuesday','Note preserved');
    $sent=[];deliver_mail(function($message)use(&$sent){$sent[]=$message;});ok(count($sent)===2,'Queued customer/company emails');
    ok(http('/admin/users',['csrf'=>$csrf,'username'=>'editor','password'=>'editor-password-123','role'=>'editor'])['status']===303,'Create editor');
    http('/admin/logout',['csrf'=>$csrf]);$csrf=token(http('/admin/login'));http('/admin/login',['csrf'=>$csrf,'username'=>'editor','password'=>'editor-password-123']);$csrf=token(http('/admin'));
    ok(http('/admin/settings')['status']===403&&http('/admin/leads')['status']===403,'Editor permissions');ok(http('/admin/settings/images/favicon_url',['csrf'=>$csrf],$png)['status']===403,'Branding upload permission');
    foreach(['/.env','/includes/app.php','/data/admins.json'] as $path)ok(http($path)['status']===403,'Private path '.$path);
    ok(http('/project.php?job=legacy-project')['status']===301,'Legacy PHP bookmark redirect');
    echo "All PHP checks passed.\n";
}finally{
    if(is_resource($server)){proc_terminate($server);proc_close($server);}
    $admin->exec("DROP DATABASE `$name`");
    // Cleanup only this uniquely-created test upload directory.
    $files=new RecursiveIteratorIterator(new RecursiveDirectoryIterator($uploadDir,FilesystemIterator::SKIP_DOTS),RecursiveIteratorIterator::CHILD_FIRST);
    foreach($files as $file){if($file->isDir())rmdir($file->getPathname());else unlink($file->getPathname());}rmdir($uploadDir);
}
