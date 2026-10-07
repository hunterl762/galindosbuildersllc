<?php
function installer_authorized():bool {
    return env('SETUP_TOKEN')!==''&&isset($_SESSION['installer_auth'],$_SESSION['installer_at'])&&hash_equals(hash('sha256',env('SETUP_TOKEN')),$_SESSION['installer_auth'])&&time()-(int)$_SESSION['installer_at']<900;
}
function installer_configuration(array $body,bool $validateEmail=true):array {
    $fields=['DB_HOST'=>['label'=>'Database hostname','required'=>true,'max'=>255],'DB_PORT'=>['type'=>'number'],'DB_NAME'=>['label'=>'Database name','required'=>true,'max'=>64],'DB_USER'=>['label'=>'Database user','required'=>true,'max'=>100],'SITE_URL'=>['label'=>'Website URL','type'=>'url','required'=>true,'max'=>700],
        'MAIL_TRANSPORT'=>['type'=>'select','max'=>10,'options'=>['disabled','smtp','mail']],'SMTP_HOST'=>['max'=>255],'SMTP_PORT'=>['type'=>'number'],'SMTP_USER'=>['max'=>255],'SMTP_SECURE'=>['type'=>'select','max'=>5,'options'=>['false','true']],'MAIL_FROM'=>['type'=>'email','max'=>255],'QUOTE_NOTIFY_EMAIL'=>['type'=>'email','max'=>255]];
    $config=validate($fields,$body);
    foreach(['DB_HOST','DB_NAME','DB_USER','SMTP_HOST'] as $key)if(preg_match('/[;\x00-\x20]/',$config[$key]))fail(422,'Use a valid value for '.$key.'.');
    foreach(['DB_PORT','SMTP_PORT'] as $key)if($config[$key]<1||$config[$key]>65535)fail(422,'Ports must be between 1 and 65535.');
    $parts=parse_url($config['SITE_URL']);if(!$parts||!isset($parts['scheme'],$parts['host'])||!in_array($parts['path']??'',['','/'],true)||isset($parts['query'])||isset($parts['fragment']))fail(422,'Use the website origin, such as https://example.com, without a subdirectory.');
    $config['SITE_URL']=rtrim($config['SITE_URL'],'/');$config['COOKIE_SECURE']=$parts['scheme']==='https'?'true':'false';
    foreach(['DB_PASS','SMTP_PASS'] as $key){$password=$body[$key]??'';if(!is_string($password)||strlen($password)>1024||preg_match('/[\x00-\x1f\x7f]/',$password))fail(422,'Use a valid password for '.$key.'.');$config[$key]=$password===''?env($key):$password;}
    if($validateEmail&&$config['MAIL_TRANSPORT']!=='disabled'){
        if($config['MAIL_FROM']===''||$config['QUOTE_NOTIFY_EMAIL']==='')fail(422,'Enter the sender and company notification email addresses.');
        if($config['MAIL_TRANSPORT']==='smtp'&&$config['SMTP_HOST']==='')fail(422,'Enter the SMTP hostname.');
    }
    return $config;
}
function installer_database(array $config):PDO {
    $connection=new PDO('mysql:host='.$config['DB_HOST'].';port='.$config['DB_PORT'].';dbname='.$config['DB_NAME'].';charset=utf8mb4',$config['DB_USER'],$config['DB_PASS'],[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_EMULATE_PREPARES=>false]);
    $connection->query('SELECT 1');return $connection;
}
function installer_smtp(array $config):void {
    if($config['MAIL_TRANSPORT']!=='smtp')fail(422,'Choose SMTP to test an SMTP connection.');
    if(!is_file(ROOT.'/vendor/autoload.php'))fail(422,'The email library is missing. Upload the full PHP deployment ZIP.');require_once ROOT.'/vendor/autoload.php';
    $mailer=new PHPMailer\PHPMailer\PHPMailer(true);$mailer->isSMTP();$mailer->Host=$config['SMTP_HOST'];$mailer->Port=(int)$config['SMTP_PORT'];$mailer->SMTPAuth=$config['SMTP_USER']!=='';$mailer->Username=$config['SMTP_USER'];$mailer->Password=$config['SMTP_PASS'];$mailer->Timeout=15;$mailer->SMTPSecure=$config['SMTP_SECURE']==='true'?PHPMailer\PHPMailer\PHPMailer::ENCRYPTION_SMTPS:PHPMailer\PHPMailer\PHPMailer::ENCRYPTION_STARTTLS;
    try{if(!$mailer->smtpConnect())fail(422,'SMTP connection failed. Check hostname, port, encryption and credentials.');}
    catch(Throwable $ex){error_log('Installer SMTP connection failed.');fail(422,'SMTP connection failed. Check hostname, port, encryption and credentials.');}finally{$mailer->smtpClose();}
}
function save_installer_configuration(array $config,string $path):void {
    // Preserve unrelated settings and tokens, with JSON quoting for password punctuation.
    $text=is_file($path)?file_get_contents($path):file_get_contents(ROOT.'/.env.example');if($text===false)fail(500,'Unable to read configuration.');
    foreach($config as $key=>$value){$line=$key.'='.json_encode((string)$value,JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR);$pattern='/^'.preg_quote($key,'/').'=.*$/m';
        if(preg_match($pattern,$text))$text=preg_replace_callback($pattern,fn()=>$line,$text);else $text=rtrim($text)."\n".$line."\n";
    }
    $temp=$path.'.'.bin2hex(random_bytes(8)).'.tmp';$handle=@fopen($temp,'x');if(!$handle)fail(500,'PHP cannot write configuration. Make the website folder writable by your hosting account.');
    try{if(!chmod($temp,0600))fail(500,'Unable to protect the configuration file.');$written=fwrite($handle,$text);if($written!==strlen($text))fail(500,'Unable to save the full configuration.');fflush($handle);fclose($handle);$handle=null;if(!rename($temp,$path))fail(500,'Unable to replace the configuration file. Check its permissions.');}
    finally{if(is_resource($handle))fclose($handle);if(is_file($temp))unlink($temp);}
}
