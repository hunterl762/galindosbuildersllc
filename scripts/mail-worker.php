<?php
if(PHP_SAPI!=='cli'){http_response_code(403);exit;}
require dirname(__DIR__).'/includes/app.php';require ROOT.'/includes/mail.php';
echo 'Processed '.deliver_mail()." email jobs.\n";
q('DELETE FROM php_sessions WHERE expires_at<UTC_TIMESTAMP()');q('DELETE FROM sessions WHERE expires_at<UTC_TIMESTAMP()');q('DELETE FROM request_limits WHERE reset_at<UTC_TIMESTAMP()');
