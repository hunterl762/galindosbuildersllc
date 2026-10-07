<?php
$page=custom_page_by_slug((string)($_GET['slug']??''));if(!$page)fail(404,'Page not found.');
public_head($site,$route,$page);
?>
<section class="custom-page-hero"><div><p class="eyebrow"><?=e($page['eyebrow']??$site['brand']['site_name'])?></p><h1><?=e($page['title'])?></h1><p><?=e($page['hero_text']??'')?></p></div></section>
<main class="custom-page-main"><section class="custom-page-content">
<?php if(image_url($page['featured_image']??'')):?><img class="page-image" src="<?=e(image_url($page['featured_image']))?>" alt="<?=e($page['title'])?>"><?php endif;?>
<?=clean_html((string)($page['content']??''))?>
<?php if(($pageType??'')==='services'):$related=array_filter($site['projects'],fn($p)=>$p['service_id']===$page['id']);if($related):?><h2>Related Projects</h2><div class="project-grid"><?php foreach($related as $p):?><a class="project-card" href="/project/<?=e(rawurlencode($p['slug']))?>"><div class="project-image"><?php if($p['featured_image']):?><img loading="lazy" src="<?=e($p['featured_image'])?>" alt="<?=e($p['name'])?>"><?php endif;?></div><div><h3><?=e($p['name'])?></h3><p><?=e($p['location'])?></p></div></a><?php endforeach;?></div><?php endif;endif;?>
</section><section class="project-cta"><div><p class="eyebrow">LET'S BUILD</p><h2><?=e(($page['cta_title']??'')?:'Ready to get started?')?></h2><p><?=e(($page['cta_text']??'')?:'Tell us about your project.')?></p></div><a class="btn primary" href="<?=e(image_url($page['cta_url']??'')?:'/#contact')?>"><?=e(($page['cta_label']??'')?:'Request a Quote')?></a></section></main>
<?php public_footer($site); ?>
