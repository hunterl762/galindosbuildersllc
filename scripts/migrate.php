<?php
if(PHP_SAPI!=='cli'){http_response_code(403);exit;}
require dirname(__DIR__).'/includes/app.php';require ROOT.'/includes/migrate.php';
migrate();if(in_array('--import-legacy',$argv,true)){import_legacy();migrate();}echo "Compatible PHP CMS migrations completed.\n";
