<?php
declare(strict_types=1);

if (session_status() !== PHP_SESSION_ACTIVE) {
    session_set_cookie_params(['httponly'=>true,'samesite'=>'Lax','secure'=>!empty($_SERVER['HTTPS'])]);
    session_start();
}

define('ROOT_PATH', dirname(__DIR__));
define('DATA_PATH', ROOT_PATH . '/data');
define('UPLOAD_PATH', ROOT_PATH . '/uploads/projects');

foreach ([DATA_PATH, UPLOAD_PATH] as $dir) {
    if (!is_dir($dir)) { @mkdir($dir, 0775, true); }
}

function read_json(string $file, array $fallback=[]): array {
    $path = DATA_PATH . '/' . $file;
    if (!is_file($path)) return $fallback;
    $data = json_decode((string)file_get_contents($path), true);
    return is_array($data) ? $data : $fallback;
}
function write_json(string $file, array $data): bool {
    $path = DATA_PATH . '/' . $file;
    $tmp = $path . '.tmp';
    $json = json_encode($data, JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES);
    return file_put_contents($tmp, $json, LOCK_EX) !== false && rename($tmp, $path);
}
function e(?string $value): string { return htmlspecialchars($value ?? '', ENT_QUOTES, 'UTF-8'); }
function slugify(string $text): string {
    $text = strtolower(trim(preg_replace('/[^a-zA-Z0-9]+/', '-', $text), '-'));
    return $text ?: 'item-' . time();
}
function csrf_token(): string {
    if (empty($_SESSION['csrf'])) $_SESSION['csrf'] = bin2hex(random_bytes(32));
    return $_SESSION['csrf'];
}
function verify_csrf(): void {
    if (!isset($_POST['csrf']) || !hash_equals($_SESSION['csrf'] ?? '', (string)$_POST['csrf'])) {
        http_response_code(419); exit('Invalid request token. Please go back and try again.');
    }
}
function is_admin(): bool { return !empty($_SESSION['admin']); }
function require_admin(): void { if (!is_admin()) { header('Location: /admin/'); exit; } }
function tabs(): array {
    $default = [
        ['id'=>'projects','label'=>'Projects','url'=>'/projects.php','visible'=>true,'sort'=>10],
        ['id'=>'services','label'=>'Services','url'=>'/#services','visible'=>true,'sort'=>20],
        ['id'=>'about','label'=>'About','url'=>'/#about','visible'=>true,'sort'=>30],
        ['id'=>'contact','label'=>'Contact','url'=>'/#contact','visible'=>true,'sort'=>40],
    ];
    $items = read_json('tabs.json', $default);
    usort($items, fn($a,$b)=>($a['sort']??0)<=>($b['sort']??0));
    return $items;
}
function projects(): array { return array_reverse(read_json('projects.json', [])); }
function project_by_slug(string $slug): ?array {
    foreach (projects() as $p) if (($p['slug']??'') === $slug) return $p;
    return null;
}
function save_uploaded_images(array $files): array {
    $saved=[]; $allowed=['image/jpeg'=>'jpg','image/png'=>'png','image/webp'=>'webp','image/gif'=>'gif'];
    if (!isset($files['tmp_name'])) return $saved;
    $names=(array)$files['name']; $tmps=(array)$files['tmp_name']; $errs=(array)$files['error'];
    foreach ($tmps as $i=>$tmp) {
        if (($errs[$i]??UPLOAD_ERR_NO_FILE)!==UPLOAD_ERR_OK || !is_uploaded_file($tmp)) continue;
        if (filesize($tmp)>10*1024*1024) continue;
        $mime=(new finfo(FILEINFO_MIME_TYPE))->file($tmp);
        if (!isset($allowed[$mime])) continue;
        $name=bin2hex(random_bytes(12)).'.'.$allowed[$mime];
        if (move_uploaded_file($tmp, UPLOAD_PATH.'/'.$name)) $saved[]='/uploads/projects/'.$name;
    }
    return $saved;
}
