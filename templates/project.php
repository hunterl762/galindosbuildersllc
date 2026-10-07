<?php

$p=project_by_slug((string)($_GET['job']??''));
$found=(bool)$p;
if(!$p){
    http_response_code(404);
    $p=['name'=>'Project Not Found','description'=>'The requested project could not be found.','images'=>[],'category'=>'PROJECT','location'=>'','status'=>''];
}
$images=$p['images']??[];
?>
<?php public_head($site,$route,$p); ?>

<section class="project-hero">
<div class="project-hero-bg"<?php if(!empty($images[0])):?> style="background-image:linear-gradient(90deg,rgba(10,16,21,.88),rgba(10,16,21,.3)),url('<?=e($images[0])?>')"<?php endif;?>></div>
<div class="project-hero-content">
<a class="project-back" href="<?=e(site_url('/projects'))?>">← ALL PROJECTS</a>
<p class="eyebrow"><?=e($p['category']??'PROJECT')?></p>
<h1><?=e($p['name'])?></h1>
<?php if(!empty($p['location'])):?><p class="project-location"><?=e($p['location'])?></p><?php endif;?>
</div>
</section>

<main class="project-detail">
<?php if(!$found):?>
<section class="project-empty"><h2>We couldn't find that project.</h2><p>The project may have been renamed or removed.</p><a class="btn primary" href="<?=e(site_url('/projects.php'))?>">Browse Projects</a></section>
<?php else:?>
<section class="project-overview">
<div class="project-overview-title"><p class="eyebrow dark">PROJECT OVERVIEW</p><h2><?=e($p['name'])?></h2></div>
<div class="project-description"><p><?=nl2br(e($p['description']??''))?></p><?php foreach(['scope'=>'Scope of work','stats'=>'Project stats'] as $key=>$label):if(!empty($p[$key])):?><h3><?=e($label)?></h3><p><?=nl2br(e($p[$key]))?></p><?php endif;endforeach;?></div>
<aside class="project-facts">
<div><small>LOCATION</small><strong><?=e($p['location']?:'—')?></strong></div>
<div><small>TYPE</small><strong><?=e($p['category']?:'Construction')?></strong></div>
<div><small>STATUS</small><strong><?=e($p['status']?:'—')?></strong></div>
<?php foreach(['completion_date'=>'COMPLETED','client'=>'CLIENT','contractor'=>'GENERAL CONTRACTOR','square_footage'=>'SQUARE FOOTAGE'] as $key=>$label):if(!empty($p[$key])):?><div><small><?=e($label)?></small><strong><?=e($p[$key])?></strong></div><?php endif;endforeach;?><div><small>PHOTOS</small><strong><?=count($images)?></strong></div>
</aside>
</section>

<?php if($images):?>
<section class="project-gallery-section">
<div class="section-heading row"><div><p class="eyebrow dark">PROJECT GALLERY</p><h2>Built with attention to detail.</h2></div><span class="gallery-count"><?=count($images)?> <?=count($images)===1?'PHOTO':'PHOTOS'?></span></div>
<div class="project-gallery">
<?php foreach($images as $i=>$img):?>
<figure class="<?=($i===0?'gallery-feature ':'')?><?=($i>0&&$i%5===0?'gallery-wide':'')?>"><img loading="<?=($i===0?'eager':'lazy')?>" src="<?=e($img)?>" alt="<?=e($p['gallery'][$i]['alt_text']??$p['name'])?>"><figcaption><?=e(($p['gallery'][$i]['image_kind']??'gallery')==='gallery'?'':$p['gallery'][$i]['image_kind'].' · ')?><?=e($p['gallery'][$i]['caption']??$p['name'])?></figcaption></figure>
<?php endforeach;?>
</div>
</section>
<?php else:?>
<section class="project-no-photos"><p class="eyebrow dark">PROJECT GALLERY</p><h2>Photos coming soon.</h2><p>Project images can be added from the website administrator dashboard.</p></section>
<?php endif;?>

<section class="project-cta"><div><p class="eyebrow">START A CONVERSATION</p><h2>Planning a project?</h2><p>Tell us about your scope, schedule and location.</p></div><a class="btn primary" href="<?=e(site_url('/#contact'))?>">Request a Quote</a></section>
<?php endif;?>
</main>

<?php public_footer($site); ?>