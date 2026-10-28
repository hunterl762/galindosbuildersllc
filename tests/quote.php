<?php
declare(strict_types=1);
require_once ROOT.'/includes/quote.php';
$original=[];foreach(['TURNSTILE_SITE_KEY','TURNSTILE_SECRET_KEY','TURNSTILE_HOSTNAMES','TURNSTILE_TRUSTED_PROXY_CIDRS'] as $key)$original[$key]=getenv($key);
$server=$_SERVER;
function quote_reject(callable $fn,int $status,string $label):void {
    try{$fn();throw new LogicException('Accepted: '.$label);}catch(RuntimeException $ex){ok($ex->getCode()===$status,$label);}
}
try{
    putenv('TURNSTILE_SITE_KEY=unit-test-site-key');putenv('TURNSTILE_SECRET_KEY=unit-test-secret');putenv('TURNSTILE_HOSTNAMES=example.com,www.example.com');putenv('TURNSTILE_TRUSTED_PROXY_CIDRS=');
    $valid=['company_website'=>'','cf-turnstile-response'=>'unit-test-token'];$calls=0;
    $pass=function(array $payload)use(&$calls):array{$calls++;ok($payload===['secret'=>'unit-test-secret','response'=>'unit-test-token','remoteip'=>'192.0.2.10'],'Siteverify receives server secret, token and IP');return ['success'=>true,'hostname'=>'example.com','action'=>'quote'];};
    quote_verify($valid,'192.0.2.10',$pass);ok($calls===1,'Valid verification passes once');
    foreach([['company_website'=>'https://spam.example'],['company_website'=>['bad']],['cf-turnstile-response'=>''],['cf-turnstile-response'=>['bad']],['cf-turnstile-response'=>str_repeat('a',2049)]] as $override)quote_reject(fn()=>quote_verify(array_replace($valid,$override),'192.0.2.10',$pass),422,'Reject malformed token or honeypot before Siteverify');
    ok($calls===1,'Invalid inputs never reach Siteverify');
    foreach([['success'=>false],['success'=>'true','hostname'=>'example.com','action'=>'quote'],['success'=>true,'hostname'=>'evil.example','action'=>'quote'],['success'=>true,'hostname'=>'localhost','action'=>'quote'],['success'=>true,'hostname'=>'example.com','action'=>'login'],['success'=>true],['success'=>false,'error-codes'=>['timeout-or-duplicate']]] as $result)quote_reject(fn()=>quote_verify($valid,'192.0.2.10',fn()=>$result),422,'Reject failed, replayed, wrong-host or wrong-action verification');
    quote_reject(fn()=>quote_verify($valid,'192.0.2.10',function(){fail(503,'Unavailable');}),503,'Verification outage fails closed');
    putenv('TURNSTILE_SECRET_KEY=');quote_reject(fn()=>quote_verify($valid,'192.0.2.10',$pass),503,'Missing secret fails closed');ok(!quote_turnstile_ready(),'Missing configuration hides quote form');putenv('TURNSTILE_SECRET_KEY=unit-test-secret');
    putenv('TURNSTILE_HOSTNAMES=');quote_reject(fn()=>quote_verify($valid,'192.0.2.10',$pass),503,'Missing hostname allowlist fails closed');putenv('TURNSTILE_HOSTNAMES=example.com,www.example.com');
    foreach([['192.0.2.10','192.0.2.0/24',true],['192.0.3.1','192.0.2.0/24',false],['192.0.2.10','192.0.2.10/32',true],['192.0.2.11','192.0.2.10/32',false],['2001:db8::1','2001:db8::/32',true],['2001:db9::1','2001:db8::/32',false],['192.0.2.10','::/0',false],['192.0.2.10','192.0.2.0/33',false],['192.0.2.10','garbage',false]] as [$ip,$cidr,$expected])ok(quote_ip_in_cidr($ip,$cidr)===$expected,'Proxy CIDR '.$ip.' / '.$cidr);
    $_SERVER['REMOTE_ADDR']='192.0.2.10';$_SERVER['HTTP_CF_CONNECTING_IP']='198.51.100.5';$_SERVER['HTTP_X_FORWARDED_FOR']='203.0.113.1';
    ok(quote_client_ip()==='192.0.2.10','Untrusted forwarded IP cannot bypass rate limit');putenv('TURNSTILE_TRUSTED_PROXY_CIDRS=192.0.2.0/24');ok(quote_client_ip()==='198.51.100.5','Trusted Cloudflare proxy supplies visitor IP');
    $_SERVER['HTTP_CF_CONNECTING_IP']='bad';ok(quote_client_ip()==='192.0.2.10','Malformed visitor IP falls back to peer');
    $home=['contact_title'=>'Contact','contact_text'=>'Tell us'];$sent=false;$_SESSION['csrf']='test-csrf';ob_start();require ROOT.'/templates/home/contact.php';$html=ob_get_clean();
    ok(str_contains($html,'data-action="quote"')&&str_contains($html,'name="company_website"')&&str_contains($html,'/turnstile/v0/api.js')&&!str_contains($html,'unit-test-secret'),'Form renders widget and honeypot without secret');
}finally{foreach($original as $key=>$value)putenv($value===false?$key:$key.'='.$value);$_SERVER=$server;}
