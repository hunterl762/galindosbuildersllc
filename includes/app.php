<?php
declare(strict_types=1);
define('ROOT', dirname(__DIR__));
date_default_timezone_set('UTC');
function env(string $key, string $default=''): string {
    static $values=null;
    if ($values===null) {
        $values=[];
        if (is_file(ROOT.'/.env')) foreach(file(ROOT.'/.env', FILE_IGNORE_NEW_LINES) as $line) {
            $line=trim($line); if($line===''||str_starts_with($line,'#')||!str_contains($line,'=')) continue;
            [$k,$v]=explode('=',$line,2); $values[trim($k)]=trim(trim($v),'"\'');
        }
    }
    $v=getenv($key); return $v===false ? (string)($values[$key]??$default) : $v;
}
function e(mixed $v): string {return htmlspecialchars((string)($v??''), ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8');}
function fail(int $status,string $message): never {throw new RuntimeException($message,$status);}
function db(): PDO {
    static $pdo=null; if($pdo) return $pdo;
    $pdo=new PDO('mysql:host='.env('DB_HOST','localhost').';port='.env('DB_PORT','3306').';dbname='.env('DB_NAME','galindosbuilders').';charset=utf8mb4',env('DB_USER','galindos'),env('DB_PASS'),[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC,PDO::ATTR_EMULATE_PREPARES=>false]);
    $pdo->exec("SET time_zone='+00:00'"); return $pdo;
}
function q(string $sql,array $args=[]): array { $s=db()->prepare($sql);$s->execute($args);return $s->columnCount()?$s->fetchAll():[]; }
function tx(callable $fn): mixed {db()->beginTransaction();try{$result=$fn();db()->commit();return $result;}catch(Throwable $ex){if(db()->inTransaction())db()->rollBack();throw $ex;}}
function schema(): array {static $s;return $s??=json_decode(file_get_contents(ROOT.'/includes/cms-fields.json'),true,512,JSON_THROW_ON_ERROR);}
function setting(string $key,string $default=''): string {return (string)(q('SELECT setting_value FROM site_settings WHERE setting_key=?',[$key])[0]['setting_value']??$default);}
function set_setting(string $key,string $value): void {q('INSERT INTO site_settings(setting_key,setting_value) VALUES(?,?) ON DUPLICATE KEY UPDATE setting_value=VALUES(setting_value)',[$key,$value]);}
function safe_url(mixed $value): string {
    $value=trim((string)$value); if($value==='')return '';
    if(preg_match('/[\x00-\x20\\\\]/',$value))fail(422,'Use a valid URL without whitespace or backslashes.');
    if(str_starts_with($value,'/')&&!str_starts_with($value,'//'))return $value;
    $parts=parse_url($value);if($parts&&in_array(strtolower($parts['scheme']??''),['http','https'],true)&&!empty($parts['host'])&&!isset($parts['user'])&&!isset($parts['pass']))return $value;
    fail(422,'Use a relative URL starting with / or an HTTP(S) URL.');
}
function image_url(mixed $url): string {try{return safe_url($url);}catch(Throwable){return '';}}
function clean_html(string $html): string {
    $document=new DOMDocument(); $previous=libxml_use_internal_errors(true);
    $document->loadHTML('<?xml encoding="UTF-8"><div id="cms-root">'.$html.'</div>',LIBXML_HTML_NOIMPLIED|LIBXML_HTML_NODEFDTD|LIBXML_NONET);
    libxml_clear_errors();libxml_use_internal_errors($previous);
    $allowed=['p','br','strong','b','em','i','u','h2','h3','h4','ul','ol','li','blockquote','a','hr'];
    $render=function(DOMNode $node)use(&$render,$allowed):string{
        if($node instanceof DOMText)return e($node->textContent);
        if(!$node instanceof DOMElement)return '';
        $tag=strtolower($node->tagName);if(in_array($tag,['script','style','iframe','object','embed','svg','math'],true))return '';
        $content='';foreach($node->childNodes as $child)$content.=$render($child);
        if(!in_array($tag,$allowed,true))return $content;
        $attrs='';if($tag==='a'){
            $href=$node->getAttribute('href');
            if(preg_match('~^(mailto:|tel:|\#)~i',$href)&&!preg_match('/[\x00-\x20]/',$href))$attrs=' href="'.e($href).'"';
            else try{$safe=safe_url($href);if($safe!=='')$attrs=' href="'.e($safe).'"';}catch(Throwable){}
            if($node->hasAttribute('title'))$attrs.=' title="'.e($node->getAttribute('title')).'"';
        }
        return '<'.$tag.$attrs.'>'.($tag==='br'||$tag==='hr'?'':$content.'</'.$tag.'>');
    };
    $output='';foreach($document->childNodes as $node)$output.=$render($node);return $output;
}
function validate(array $fields,array $body): array {
    $result=[];foreach($fields as $key=>$field){
        $type=$field['type']??'text';$raw=$body[$key]??'';if(is_array($raw))fail(422,'Invalid value for '.$key);
        if($type==='checkbox'){$result[$key]=in_array($raw,['1','on',true],true)?1:0;continue;}
        $v=trim((string)$raw);
        if($type==='number'){
            if($v===''&&$key==='square_footage'){$result[$key]=null;continue;}
            if($v==='')$v='0';$min=$key==='square_footage'?0:-100000;$max=$key==='square_footage'?4294967295:100000;
            if(!preg_match('/^-?\d+$/',$v)||(float)$v<$min||(float)$v>$max)fail(422,'Invalid number for '.$key);$result[$key]=(int)$v;continue;
        }
        if(mb_strlen($v)>($field['max']??255)||(!empty($field['required'])&&$v===''))fail(422,'Check the length of '.$field['label']);
        if($type==='slug'&&!preg_match('/^[a-z0-9]+(?:-[a-z0-9]+)*$/',$v))fail(422,'URL slugs must use lowercase letters, numbers and hyphens.');
        if($type==='select'){$v=$v?:$field['options'][0];if(!in_array($v,$field['options'],true))fail(422,'Invalid option for '.$key);}
        if($type==='email'&&$v!==''&&!filter_var($v,FILTER_VALIDATE_EMAIL))fail(422,'Use a valid email address.');
        if($type==='date') {if($v==='')$v=null;else{$date=DateTimeImmutable::createFromFormat('!Y-m-d',$v);if(!$date||$date->format('Y-m-d')!==$v)fail(422,'Use a valid date.');}}
        if($type==='url')$v=safe_url($v);if($type==='html')$v=clean_html($v);$result[$key]=$v;
    }return $result;
}
function can(?array $user,string $permission):bool{return !empty($user['active'])&&in_array($permission,['owner'=>['content','leads','settings','users'],'editor'=>['content'],'sales'=>['leads']][$user['role']]??[],true);}
function user():?array {static $user=false;if($user!==false)return $user;if(empty($_SESSION['userId'])||empty($_SESSION['loginAt'])||time()-(int)$_SESSION['loginAt']>86400)return $user=null;return $user=q('SELECT id,username,role,active FROM admins WHERE id=? AND active=1',[$_SESSION['userId']])[0]??null;}
function require_permission(string $permission):void {if(!user())redirect('/admin/login');if(!can(user(),$permission))fail(403,'Your account does not have permission for this action.');}
function redirect(string $url,int $status=303):never {header('Location: '.$url,true,$status);exit;}
function csrf():string {return $_SESSION['csrf']??=bin2hex(random_bytes(32));}
function check_csrf():void {$value=$_SERVER['HTTP_X_CSRF_TOKEN']??$_POST['csrf']??'';if(!is_string($value)||!hash_equals(csrf(),$value))fail(403,'Invalid request token. Reload the page and try again.');}
function csrf_input():void {echo '<input type="hidden" name="csrf" value="'.e(csrf()).'">';}
function throttle(string $kind,int $limit,int $seconds):void {
    $key=hash('sha256',$kind.':'.($_SERVER['REMOTE_ADDR']??'cli'));
    $hits=tx(function()use($key,$seconds){q('INSERT IGNORE INTO request_limits(limit_key,hits,reset_at) VALUES(?,0,DATE_ADD(UTC_TIMESTAMP(),INTERVAL ? SECOND))',[$key,$seconds]);$r=q('SELECT hits,reset_at<=UTC_TIMESTAMP() AS expired FROM request_limits WHERE limit_key=? FOR UPDATE',[$key])[0];$hits=$r['expired']?1:(int)$r['hits']+1;q('UPDATE request_limits SET hits=?,reset_at=IF(?,DATE_ADD(UTC_TIMESTAMP(),INTERVAL ? SECOND),reset_at) WHERE limit_key=?',[$hits,$r['expired'],$seconds,$key]);return $hits;});
    if($hits>$limit){header('Retry-After: '.$seconds);fail(429,'Too many attempts. Please try again later.');}
}
function password_hash_php(string $password):string {if(strlen($password)<12||strlen($password)>128)fail(422,'Use a password of 12–128 bytes.');if(defined('PASSWORD_ARGON2ID'))return password_hash($password,PASSWORD_ARGON2ID);if(strlen($password)>72)fail(422,'This PHP server supports passwords up to 72 bytes.');return password_hash($password,PASSWORD_BCRYPT,['cost'=>12]);}
function verify_password(string $password,string $hash):bool {return password_verify($password,str_replace('$2b$','$2y$',$hash));}
function boot():void {
    header('X-Content-Type-Options: nosniff');header('Referrer-Policy: strict-origin-when-cross-origin');header('X-Frame-Options: SAMEORIGIN');
    header("Content-Security-Policy: default-src 'self'; script-src 'self'; style-src 'self' 'unsafe-inline' https://fonts.googleapis.com; font-src 'self' https://fonts.gstatic.com; img-src 'self' https: data:; frame-ancestors 'self'; base-uri 'self'; form-action 'self'");
    $secure=(!empty($_SERVER['HTTPS'])&&$_SERVER['HTTPS']!=='off')||env('COOKIE_SECURE')==='true';
    ini_set('session.use_strict_mode','1');ini_set('session.use_only_cookies','1');ini_set('session.gc_maxlifetime','28800');session_name('GBPHPSESSID');
    session_set_cookie_params(['lifetime'=>28800,'path'=>'/','secure'=>$secure,'httponly'=>true,'samesite'=>'Lax']);
    try{q('SELECT sid FROM php_sessions LIMIT 1');session_set_save_handler(new PhpSessionStore(),true);}catch(PDOException $ex){if(!in_array($ex->errorInfo[1]??0,[1146],true))throw $ex;}
    session_start(); if(!in_array($_SERVER['REQUEST_METHOD']??'GET',['GET','HEAD','OPTIONS'],true))check_csrf();
}
class PhpSessionStore implements SessionHandlerInterface,SessionUpdateTimestampHandlerInterface {
    private ?string $lock=null;
    public function open(string $path,string $name):bool{return true;}
    public function close():bool{if($this->lock!==null){q('SELECT RELEASE_LOCK(?)',[$this->lock]);$this->lock=null;}return true;}
    public function read(string $sid):string{if($this->lock!==null)$this->close();$this->lock='php_session_'.substr(hash('sha256',$sid),0,40);if(!(int)q('SELECT GET_LOCK(?,10) AS ok',[$this->lock])[0]['ok'])fail(503,'Session is busy. Try again.');return q('SELECT data FROM php_sessions WHERE sid=? AND expires_at>UTC_TIMESTAMP()',[$sid])[0]['data']??'';}
    public function write(string $sid,string $data):bool{q('INSERT INTO php_sessions(sid,data,user_id,expires_at) VALUES(?,?,?,DATE_ADD(UTC_TIMESTAMP(),INTERVAL 8 HOUR)) ON DUPLICATE KEY UPDATE data=VALUES(data),user_id=VALUES(user_id),expires_at=VALUES(expires_at)',[$sid,$data,$_SESSION['userId']??null]);return true;}
    public function destroy(string $sid):bool{q('DELETE FROM php_sessions WHERE sid=?',[$sid]);return true;}
    public function gc(int $max_lifetime):int|false{q('DELETE FROM php_sessions WHERE expires_at<UTC_TIMESTAMP()');return 1;}
    public function validateId(string $sid):bool{return (bool)q('SELECT sid FROM php_sessions WHERE sid=? AND expires_at>UTC_TIMESTAMP()',[$sid]);}
    public function updateTimestamp(string $sid,string $data):bool{q('UPDATE php_sessions SET expires_at=DATE_ADD(UTC_TIMESTAMP(),INTERVAL 8 HOUR) WHERE sid=?',[$sid]);return true;}
}
