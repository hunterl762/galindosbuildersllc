<?php
function site():array {
    static $site;if($site)return $site;
    $brand=schema()['defaultSettings'];foreach(q('SELECT * FROM site_settings') as $row)$brand[$row['setting_key']]=$row['setting_value'];
    foreach(['favicon_url','header_logo_url','meta_image_url'] as $k)$brand[$k]=image_url($brand[$k]);
    $home=array_merge(schema()['defaultHome'],json_decode($brand['home']??'{}',true)??[]);
    $projects=q('SELECT * FROM projects WHERE visible=1 ORDER BY sort_order,created_at DESC');
    foreach($projects as &$p){$p['gallery']=q('SELECT pi.*,m.alt_text AS media_alt FROM project_images pi LEFT JOIN media m ON m.id=pi.media_id WHERE pi.project_id=? ORDER BY pi.sort_order,pi.id',[$p['id']]);$p['images']=array_map(fn($i)=>image_url($i['image_url']),$p['gallery']);$p['featured_image']=image_url($p['featured_image'])?:($p['images'][0]??'');}unset($p);
    $seo=[];foreach(q('SELECT * FROM seo_settings') as $m)$seo[$m['page_key']]=$m;
    return $site=['brand'=>$brand,'home'=>$home,'projects'=>$projects,'services'=>q('SELECT * FROM services WHERE visible=1 ORDER BY sort_order,id'),'tabs'=>q('SELECT * FROM navigation_tabs WHERE visible=1 ORDER BY sort_order,id'),'areas'=>q('SELECT * FROM service_areas WHERE visible=1 ORDER BY sort_order,title'),'seo'=>$seo];
}
function site_origin():string { $url=env('SITE_URL','http://localhost:8000');$parts=parse_url($url);if(!$parts||!in_array($parts['scheme']??'',['http','https'],true)||empty($parts['host'])||isset($parts['user'])||isset($parts['pass'])||!in_array($parts['path']??'',['','/'],true)||isset($parts['query'])||isset($parts['fragment']))fail(500,'SITE_URL must be the website origin without a subdirectory.');return rtrim($url,'/'); }
function absolute_url(string $value):string {return str_starts_with($value,'/')?site_origin().$value:$value;}
function metadata(array $site,string $route,array $item=[]):array {
    $stored=$site['seo'][$route==='/'?'home':($route==='/projects'?'projects':$route)]??[];
    $canonical=absolute_url(image_url($item['canonical_url']??$stored['canonical_url']??'')?:$route);
    $title=$item['seo_title']??'';if(!$title)$title=$stored['title']??'';if(!$title)$title=($item['title']??$item['name']??'Framing & Construction').' | '.$site['brand']['site_name'];
    $description=$item['seo_description']??'';if(!$description)$description=$stored['description']??'';if(!$description)$description=mb_substr(strip_tags($item['hero_text']??$item['description']??$site['home']['hero_text']),0,160);
    return ['title'=>$title,'description'=>$description,'keywords'=>$stored['keywords']??'','canonical'=>$canonical,'image'=>absolute_url(image_url($item['og_image']??$stored['og_image']??$item['featured_image']??$site['brand']['meta_image_url'])),'robots'=>$stored['robots']??$item['robots']??'index,follow'];
}
function site_url(string $url=''):string{return $url?:'/';}function asset_url(string $url):string{return $url;}
function projects():array{return site()['projects'];}function services():array{return site()['services'];}function tabs():array{return site()['tabs'];}
function branding_settings():array{return site()['brand'];}function home_content():array{return site()['home'];}
function seo(string $key='home'):array{return metadata(site(),$key==='home'?'/':'/'.$key);}
function slugify(string $s):string{return trim(strtolower(preg_replace('/[^a-zA-Z0-9]+/','-',$s)),'-');}
function csrf_token():string{return csrf();}function safe_page_html(string $html):string{return clean_html($html);}
function tab_url(array $tab):string{
    if(!empty($tab['page_slug']))return '/page/'.rawurlencode($tab['page_slug']);
    $url=$tab['url']??'/';$url=preg_replace('~/project(?:\.php)?\?job=([^&#]+)~','/project/$1',$url);$url=preg_replace('~/page(?:\.php)?\?slug=([^&#]+)~','/page/$1',$url);return image_url(str_replace(['/projects.php','/index.php'],['/projects','/'],$url));
}
function project_by_slug(string $slug):?array{foreach(projects() as $p)if($p['slug']===$slug)return $p;return null;}
function custom_page_by_slug(string $slug):?array {
    global $pageType;$table=['services'=>'services','service-areas'=>'service_areas','page'=>'pages'][$pageType??'page'];
    $page=q("SELECT * FROM `$table` WHERE slug=? AND visible=1",[$slug])[0]??null;
    if($page){$page['title']=$page['title']??$page['name'];$page['eyebrow']=$page['eyebrow']??site()['brand']['site_name'];$page['hero_text']=$page['hero_text']?:($page['description']??'');}
    return $page;
}
function public_head(array $site,string $route,array $item=[]):void {
    $meta=metadata($site,$route,$item);$brand=$site['brand'];
    $structure=['@context'=>'https://schema.org','@type'=>$route==='/'?'GeneralContractor':(str_starts_with($route,'/services/')?'Service':(str_starts_with($route,'/project/')?'CreativeWork':'WebPage')),'name'=>$item['title']??$item['name']??$brand['site_name'],'url'=>$meta['canonical'],'description'=>$meta['description']];
    ?><!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title><?=e($meta['title'])?></title><meta name="description" content="<?=e($meta['description'])?>"><meta name="robots" content="<?=e($meta['robots'])?>"><link rel="canonical" href="<?=e($meta['canonical'])?>"><meta property="og:site_name" content="<?=e($brand['site_name'])?>"><meta property="og:type" content="website"><meta property="og:title" content="<?=e($meta['title'])?>"><meta property="og:description" content="<?=e($meta['description'])?>"><meta property="og:url" content="<?=e($meta['canonical'])?>"><?php if($meta['image']):?><meta property="og:image" content="<?=e($meta['image'])?>"><meta name="twitter:card" content="summary_large_image"><meta name="twitter:image" content="<?=e($meta['image'])?>"><?php endif;?><?php if($brand['favicon_url']):?><link rel="icon" href="<?=e($brand['favicon_url'])?>"><?php endif;?><script type="application/ld+json"><?=json_encode($structure,JSON_HEX_TAG|JSON_HEX_AMP|JSON_HEX_APOS|JSON_HEX_QUOT|JSON_THROW_ON_ERROR)?></script><link rel="stylesheet" href="/assets/css/site.css"><script defer src="/assets/js/site.js"></script></head><body><header class="site-header"><a class="brand" href="/"><?php brand_logo($brand);?></a><button class="menu" aria-label="Open navigation">☰</button><nav><?php foreach($site['tabs'] as $tab):?><a href="<?=e(tab_url($tab))?>"><?=e($tab['label'])?></a><?php endforeach;?><a class="nav-cta" href="/#contact">Start a Project</a></nav></header><?php
}
function brand_logo(array $brand):void{if($brand['header_logo_url'])echo '<img class="site-brand-logo" src="'.e($brand['header_logo_url']).'" alt="'.e($brand['site_name']).' logo">';else echo '<span class="brand-mark">GB</span>';echo $brand['site_name']==='Galindos Builders LLC'?'<span>GALINDOS <b>BUILDERS LLC</b></span>':'<span>'.e($brand['site_name']).'</span>';}
function public_footer(array $site):void{?><footer><div class="brand"><?php brand_logo($site['brand']);?></div><p><?=e($site['brand']['footer_text'])?></p><p>© <?=date('Y')?> <?=e($site['brand']['site_name'])?>.</p></footer></body></html><?php }
