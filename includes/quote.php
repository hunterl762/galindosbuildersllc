<?php
declare(strict_types=1);

// Only trust CF-Connecting-IP when the direct peer belongs to an explicitly
// configured proxy network. Arbitrary forwarded headers never identify visitors.
function quote_ip_in_cidr(string $ip,string $cidr):bool {
    $parts=explode('/',trim($cidr));$address=@inet_pton($ip);$network=@inet_pton($parts[0]);
    if($address===false||$network===false||strlen($address)!==strlen($network)||count($parts)>2)return false;
    $bits=$parts[1]??(string)(strlen($network)*8);
    if(!ctype_digit($bits)||(int)$bits>strlen($network)*8)return false;
    $bytes=intdiv((int)$bits,8);$remainder=(int)$bits%8;
    return substr($address,0,$bytes)===substr($network,0,$bytes)&&($remainder===0||((ord($address[$bytes])^ord($network[$bytes]))&(0xff<<(8-$remainder)))===0);
}
function quote_client_ip():string {
    $peer=$_SERVER['REMOTE_ADDR']??'';
    if(!filter_var($peer,FILTER_VALIDATE_IP))fail(503,'Unable to verify this request. Please try again later.');
    foreach(explode(',',env('TURNSTILE_TRUSTED_PROXY_CIDRS')) as $cidr){
        if($cidr!==''&&quote_ip_in_cidr($peer,$cidr)){
            $visitor=$_SERVER['HTTP_CF_CONNECTING_IP']??'';
            if(is_string($visitor)&&filter_var($visitor,FILTER_VALIDATE_IP))return inet_ntop(inet_pton($visitor));
        }
    }
    return inet_ntop(inet_pton($peer));
}
function quote_turnstile_ready():bool {
    return trim(env('TURNSTILE_SITE_KEY'))!==''&&trim(env('TURNSTILE_SECRET_KEY'))!==''&&quote_turnstile_hostnames()!==[];
}
function quote_turnstile_hostnames():array {
    // Explicit deployment allowlist; never derive trust from an HTTP Host header.
    return array_values(array_filter(array_map('trim',explode(',',env('TURNSTILE_HOSTNAMES'))),fn($h)=>$h!==''));
}
function quote_siteverify(array $payload):array {
    if(!function_exists('curl_init'))fail(503,'Quote verification is temporarily unavailable. Please try again later.');
    $handle=curl_init('https://challenges.cloudflare.com/turnstile/v0/siteverify');
    curl_setopt_array($handle,[CURLOPT_POST=>true,CURLOPT_POSTFIELDS=>http_build_query($payload),CURLOPT_HTTPHEADER=>['Content-Type: application/x-www-form-urlencoded'],CURLOPT_RETURNTRANSFER=>true,CURLOPT_CONNECTTIMEOUT=>3,CURLOPT_TIMEOUT=>10,CURLOPT_FOLLOWLOCATION=>false,CURLOPT_SSL_VERIFYPEER=>true,CURLOPT_SSL_VERIFYHOST=>2]);
    $response=curl_exec($handle);$status=curl_getinfo($handle,CURLINFO_RESPONSE_CODE);curl_close($handle);
    if($response===false||$status!==200)fail(503,'Quote verification is temporarily unavailable. Please try again later.');
    $result=json_decode($response,true);
    if(!is_array($result)||!isset($result['success'])||!is_bool($result['success']))fail(503,'Quote verification is temporarily unavailable. Please try again later.');
    return $result;
}
function quote_verify(array $body,string $ip,?callable $verify=null):void {
    $honeypot=$body['company_website']??'';
    if(!is_string($honeypot)||$honeypot!=='')fail(422,'Unable to submit this request. Please reload the form and try again.');
    if(!quote_turnstile_ready())fail(503,'Quote requests are temporarily unavailable. Please contact us directly.');
    $token=$body['cf-turnstile-response']??'';
    if(!is_string($token)||trim($token)===''||strlen($token)>2048)fail(422,'Please complete the verification and submit the form again.');
    // Callable injection is for isolated tests, never controlled by request/config.
    $result=($verify??'quote_siteverify')(['secret'=>env('TURNSTILE_SECRET_KEY'),'response'=>$token,'remoteip'=>$ip]);
    if(($result['success']??null)!==true||($result['action']??null)!=='quote'||!in_array($result['hostname']??null,quote_turnstile_hostnames(),true))fail(422,'Verification failed or expired. Please reload the form and try again.');
}
function submit_quote(array $body,?callable $verify=null):void {
    $ip=quote_client_ip();throttle('quotes',5,3600,$ip);
    $lead=validate(['name'=>['label'=>'Name','required'=>true,'max'=>255],'email'=>['label'=>'Email','type'=>'email','required'=>true,'max'=>255],'phone'=>['max'=>80],'project_type'=>['max'=>160],'location'=>['max'=>255],'message'=>['label'=>'Message','required'=>true,'max'=>10000]],$body);
    if(mb_strlen($lead['message'])<10)fail(422,'Please include at least ten characters about your project.');
    quote_verify($body,$ip,$verify);
    require_once ROOT.'/includes/mail.php';
    tx(function()use($lead){q("INSERT INTO quote_submissions(name,email,phone,project_type,location,message,status) VALUES(?,?,?,?,?,?,'New')",array_values($lead));queue_quote_mail($lead);});
}
