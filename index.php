<?php
require __DIR__.'/includes/app.php';
try{
    boot();require ROOT.'/includes/content.php';require ROOT.'/includes/media.php';require ROOT.'/includes/migrate.php';
    $route=rtrim(rawurldecode(parse_url($_SERVER['REQUEST_URI']??'/',PHP_URL_PATH)??'/'),'/')?:'/';
    if(str_starts_with($route,'/admin')){require ROOT.'/includes/admin.php';admin_dispatch($route);exit;}
    if(!q("SELECT version FROM schema_migrations WHERE version='003-php-runtime'"))redirect('/install.php',302);
    if($route==='/quote'&&$_SERVER['REQUEST_METHOD']==='POST'){
        throttle('quotes',5,3600);$lead=validate(['name'=>['label'=>'Name','required'=>true,'max'=>255],'email'=>['label'=>'Email','type'=>'email','required'=>true,'max'=>255],'phone'=>['max'=>80],'project_type'=>['max'=>160],'location'=>['max'=>255],'message'=>['label'=>'Message','required'=>true,'max'=>10000]],$_POST);
        if(mb_strlen($lead['message'])<10)fail(422,'Please include at least ten characters about your project.');
        require ROOT.'/includes/mail.php';tx(function()use($lead){q("INSERT INTO quote_submissions(name,email,phone,project_type,location,message,status) VALUES(?,?,?,?,?,?,'New')",array_values($lead));queue_quote_mail($lead);});redirect('/?sent=1#contact');
    }
    if($_SERVER['REQUEST_METHOD']!=='GET'&&$_SERVER['REQUEST_METHOD']!=='HEAD')fail(405,'Method not allowed.');
    if($route==='/index.php')redirect('/',301);
    if($route==='/projects.php')redirect('/projects',301);
    if(in_array($route,['/project.php','/project'],true)&&isset($_GET['job']))redirect('/project/'.rawurlencode((string)$_GET['job']),301);
    if(in_array($route,['/page.php','/page'],true)&&isset($_GET['slug']))redirect('/page/'.rawurlencode((string)$_GET['slug']),301);
    $site=site();$item=[];
    if($route==='/robots.txt'){header('Content-Type: text/plain');echo "User-agent: *\nDisallow: /admin/\nDisallow: /install.php\nSitemap: ".site_origin()."/sitemap.xml\n";exit;}
    if($route==='/sitemap.xml'){
        header('Content-Type: application/xml; charset=UTF-8');$entries=[['/',[]],['/projects',[]]];
        foreach(['project'=>$site['projects'],'page'=>q('SELECT * FROM pages WHERE visible=1'),'services'=>$site['services'],'service-areas'=>$site['areas']] as $type=>$rows)foreach($rows as $row)$entries[]=['/'.$type.'/'.rawurlencode($row['slug']),$row];
        echo '<?xml version="1.0" encoding="UTF-8"?><urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">';foreach($entries as [$path,$row]){$m=metadata($site,$path,$row);if(!str_contains($m['robots'],'noindex')&&$m['canonical']===site_origin().$path)echo '<url><loc>'.e($m['canonical']).'</loc></url>';}echo '</urlset>';exit;
    }
    if($route==='/'){
        $home=$site['home'];$all=$site['projects'];$featured=array_values(array_filter($all,fn($p)=>!empty($p['featured'])));if(!$featured)$featured=array_slice($all,0,6);$sent=($_GET['sent']??'')==='1';
        public_head($site,'/');echo '<main>';foreach(q('SELECT section_key FROM homepage_sections WHERE enabled=1 ORDER BY sort_order,section_key') as $section){$key=$section['section_key'];if(in_array($key,['hero','about','values','services','projects','contact'],true))require ROOT.'/templates/home/'.$key.'.php';}echo '</main>';public_footer($site);exit;
    }
    if($route==='/projects'){ $item=['title'=>'Projects'];require ROOT.'/templates/projects.php';exit;}
    if(preg_match('~^/project/([a-z0-9-]+)$~',$route,$m)){$_GET['job']=$m[1];require ROOT.'/templates/project.php';exit;}
    if(preg_match('~^/(page|services|service-areas)/([a-z0-9-]+)$~',$route,$m)){$pageType=$m[1];$_GET['slug']=$m[2];require ROOT.'/templates/page.php';exit;}
    fail(404,'The page you requested could not be found.');
}catch(Throwable $ex){
    $duplicate=$ex instanceof PDOException&&($ex->errorInfo[1]??0)===1062;
    $status=$duplicate?409:(($ex instanceof PDOException)?503:((int)$ex->getCode()?:500));if($status<400||$status>599)$status=500;http_response_code($status);
    $message=$duplicate?'That username, slug or key already exists.':($status>=500?'Unable to connect or complete this request. Check database configuration and the PHP error log.':$ex->getMessage());error_log('PHP CMS: '.$ex->getMessage());
    ?><!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="robots" content="noindex"><title><?=e($status)?> | Galindos Builders</title><link rel="stylesheet" href="/admin/admin.css"></head><body><main class="admin-main solo"><section class="panel"><h1><?=e($status)?></h1><p role="alert"><?=e($message)?></p><a href="/">Return to website</a> · <a href="/install.php">Install / Update</a></section></main></body></html><?php
}
