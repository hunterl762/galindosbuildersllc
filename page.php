<?php
require __DIR__.'/includes/app.php';
// Prefer the rewrite-provided slug, but also resolve it from PATH_INFO/REQUEST_URI.
// This keeps CMS pages working on Apache configurations where PATH_INFO reaches
// page.php and makes direct page.php?slug=... links a reliable fallback.
$rawSlug=trim((string)($_GET['slug']??''));
if($rawSlug===''){
    $path=(string)parse_url((string)($_SERVER['REQUEST_URI']??''),PHP_URL_PATH);
    $base=rtrim(base_path(),'/');
    if($base!==''&&str_starts_with($path,$base.'/'))$path=substr($path,strlen($base));
    if(preg_match('~^/page/([^/]+)/?$~i',$path,$m))$rawSlug=rawurldecode($m[1]);
}
$slug=slugify($rawSlug);
$page=$rawSlug!==''?custom_page_by_slug($slug):null;
if(!$page){http_response_code(404);$page=['title'=>'Page Not Found','eyebrow'=>'404','hero_text'=>'The page you requested could not be found.','content'=>'','cta_title'=>'','cta_text'=>'','cta_label'=>'','cta_url'=>''];}
$title=trim((string)($page['seo_title']??''))?:($page['title'].' | Galindos Builders LLC');
$source=(string)($page['hero_text']??$page['content']??'');$description=trim((string)($page['seo_description']??''))?:substr(strip_tags($source),0,155);
?><!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title><?=e($title)?></title><meta name="description" content="<?=e($description)?>"><link rel="stylesheet" href="<?=e(asset_url('/assets/css/site.css'))?>"><script defer src="<?=e(asset_url('/assets/js/site.js'))?>"></script></head><body>
<header class="site-header"><a class="brand" href="<?=e(site_url('/'))?>"><span class="brand-mark">GB</span><span>GALINDOS <b>BUILDERS LLC</b></span></a><button class="menu" aria-label="Open navigation">☰</button><nav><?php foreach(tabs() as $tab):if(empty($tab['visible']))continue;?><a href="<?=e(tab_url($tab))?>"><?=e($tab['label'])?></a><?php endforeach;?><a class="nav-cta" href="<?=e(site_url('/#contact'))?>">Start a Project</a></nav></header>
<section class="custom-page-hero"><div><p class="eyebrow"><?=e($page['eyebrow']??'GALINDOS BUILDERS LLC')?></p><h1><?=e($page['title'])?></h1><?php if(!empty($page['hero_text'])):?><p><?=e($page['hero_text'])?></p><?php endif;?></div></section>
<main class="custom-page-main"><section class="custom-page-content"><?=safe_page_html((string)($page['content']??''))?></section><?php if(!empty($page['cta_title'])):?><section class="project-cta"><div><p class="eyebrow">LET'S BUILD</p><h2><?=e($page['cta_title'])?></h2><p><?=e($page['cta_text']??'')?></p></div><?php if(!empty($page['cta_label'])):?><a class="btn primary" href="<?=e($page['cta_url']?:site_url('/#contact'))?>"><?=e($page['cta_label'])?></a><?php endif;?></section><?php endif;?></main>
<footer><div class="brand"><span class="brand-mark">GB</span><span>GALINDOS <b>BUILDERS LLC</b></span></div><p>Professional framing & construction.</p><p>© <?=date('Y')?> Galindos Builders LLC.</p></footer></body></html>