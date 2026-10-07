<?php
// PHP built-in development server only. Apache uses .htaccess.
$path=rawurldecode(parse_url($_SERVER['REQUEST_URI'],PHP_URL_PATH));
if(preg_match('~^/(?:includes|templates|database|scripts|tests?|src|views|vendor|deploy|node_modules|\.git|\.github|data)(?:/|$)|^/\.|\.(zip|sql|env|json|lock|log)$~i',$path)){http_response_code(403);exit;}
if(str_starts_with($path,'/uploads/')&&!preg_match('/\.(jpg|jpeg|png|gif|webp|ico|svg)$/i',$path)){http_response_code(403);exit;}
$file=__DIR__.$path;if(is_file($file))return false;require __DIR__.'/index.php';
