<section class="contact" id="contact">
<p class="eyebrow">LET'S BUILD</p>
<h2>
<?=e($home['contact_title'])?>
</h2>
<p>
<?=e($home['contact_text'])?>
</p>
<?php if($sent):?>
<div class="quote-success">Thank you. Your quote request has been submitted.</div>
<?php endif;?>
<?php if(quote_turnstile_ready()):?>
<form class="quote-form" action="/quote" method="post">
<input type="hidden" name="csrf" value="<?=e(csrf_token())?>">
<input type="hidden" name="action" value="quote">
<input name="name" required placeholder="Name">
<input type="email" name="email" required placeholder="Email">
<input name="phone" placeholder="Phone">
<input name="project_type" placeholder="Project type">
<input name="location" placeholder="Project location">
<textarea name="message" rows="5" required minlength="10" maxlength="10000" placeholder="Tell us about your project">
</textarea>
<div class="quote-honeypot" aria-hidden="true">
<label for="company-website">Leave this field empty</label>
<input id="company-website" name="company_website" type="text" tabindex="-1" autocomplete="off">
</div>
<div class="cf-turnstile" data-sitekey="<?=e(env('TURNSTILE_SITE_KEY'))?>" data-action="quote">
</div>
<noscript>
<p>Please enable JavaScript to verify and send your quote request.</p>
</noscript>
<button class="btn primary">Request a Quote</button>
</form>
<script src="https://challenges.cloudflare.com/turnstile/v0/api.js" async defer>
</script>
<?php else:?>
<p role="status">Online quote requests are temporarily unavailable. Please use our phone or email contact details to reach us.</p>
<?php endif;?>
</section>
