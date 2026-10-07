<?php

$items=projects();
$cats=array_values(array_unique(array_filter(array_map(fn($p)=>$p['category']??'', $items))));
?>
<?php public_head($site,$route,$item); ?>
<section class="page-hero"><p class="eyebrow">OUR WORK</p><h1>Projects</h1></section>
<main class="projects-section">
<div class="filters"><button class="filter active" data-filter="all">All</button><?php foreach($cats as $cat):?><button class="filter" data-filter="<?=e(slugify($cat))?>"><?=e($cat)?></button><?php endforeach;?></div>
<div class="project-grid"><?php foreach($items as $p):$img=$p['featured_image']??$p['images'][0]??'';?><a class="project-card" data-category="<?=e(slugify($p['category']??''))?>" href="<?=e(site_url('/project/'.urlencode($p['slug'])))?>"><div class="project-image"><?php if($img):?><img loading="lazy" src="<?=e(image_url($img))?>" alt="<?=e($p['name'])?>"><?php endif;?></div><div><small><?=e($p['category']??'PROJECT')?></small><h3><?=e($p['name'])?></h3><p><?=e($p['location']??'')?></p></div></a><?php endforeach;?></div>
<?php if(!$items):?><div class="empty-projects"><h3>No projects published yet.</h3><p>Projects added in the admin dashboard will appear here automatically.</p></div><?php endif;?>
</main>
<?php public_footer($site); ?>