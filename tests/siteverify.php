<?php
// Optional network smoke test. Public Cloudflare dummy keys only; no DB or mail.
declare(strict_types=1);
if(PHP_SAPI!=='cli')exit;
require dirname(__DIR__).'/includes/app.php';require ROOT.'/includes/quote.php';
foreach([
    ['1x0000000000000000000000000000000AA',true,null],
    ['2x0000000000000000000000000000000AA',false,'invalid-input-response'],
    ['3x0000000000000000000000000000000AA',false,'timeout-or-duplicate'],
] as [$secret,$success,$error]){
    $result=quote_siteverify(['secret'=>$secret,'response'=>'XXXX.DUMMY.TOKEN.XXXX']);
    if($result['success']!==$success||($error!==null&&!in_array($error,$result['error-codes']??[],true)))throw new RuntimeException('Unexpected dummy Siteverify result.');
    echo 'PASS: live Siteverify '.($error??'success')."\n";
}
echo "Dummy-key transport checks passed. Real browser success/replay still requires staging.\n";
