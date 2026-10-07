<?php
function admin_render(string $title,string $mode='dashboard',array $data=[]):void {
    $resources=schema()['resources'];$leadStatuses=schema()['leadStatuses'];$user=user();$fields=[];$record=[];$items=[];extract($data,EXTR_OVERWRITE);require ROOT.'/templates/admin.php';
}
function account_data(array $body,bool $passwordRequired=true):array {
    $username=trim((string)($body['username']??''));if(!preg_match('/^[a-zA-Z0-9_.-]{3,100}$/',$username))fail(422,'Use a username of 3–100 letters, numbers, dots, hyphens or underscores.');
    $email=trim((string)($body['email']??''));if($email!==''&&!filter_var($email,FILTER_VALIDATE_EMAIL))fail(422,'Use a valid email.');$role=$body['role']??'owner';if(!in_array($role,['owner','editor','sales'],true))fail(422,'Choose a valid role.');
    return ['username'=>$username,'email'=>$email,'role'=>$role,'password_hash'=>password_hash_php((string)($body['password']??''))];
}
function json_response(array $data,int $status=200):never {http_response_code($status);header('Content-Type: application/json');echo json_encode($data,JSON_THROW_ON_ERROR);exit;}
function admin_dispatch(string $route):void {
    header('Cache-Control: no-store');header('X-Robots-Tag: noindex, nofollow');
    $path=substr($route,strlen('/admin'));$path=$path?:'/';$method=$_SERVER['REQUEST_METHOD'];$post=$method==='POST';$resources=schema()['resources'];
    if(str_ends_with($path,'.php')){if($post)fail(405,'Use the current admin page.');redirect('/admin/'.(['index'=>'','branding'=>'settings','content'=>'home','pages'=>'pages','edit-project'=>'projects/'.rawurlencode((string)($_GET['id']??''))][basename($path,'.php')]??basename($path,'.php')),301);}
    if($path==='/setup'&&!$post)redirect('/admin/login',302);
    if($path==='/login'&&!$post){if(user())redirect('/admin');$setup=!q('SELECT id FROM admins LIMIT 1');$error='';require ROOT.'/templates/login.php';return;}
    if($path==='/setup'&&$post){
        throttle('setup',5,900);if(env('SETUP_TOKEN')===''||!is_string($_POST['setup_token']??null)||!hash_equals(env('SETUP_TOKEN'),$_POST['setup_token']))fail(403,'Invalid setup token.');
        $data=account_data($_POST+['role'=>'owner']);$data['role']='owner';if(!(int)q("SELECT GET_LOCK('galindos_first_admin',10) AS ok")[0]['ok'])fail(409,'Setup is busy.');
        try{if(q('SELECT id FROM admins LIMIT 1'))fail(409,'Setup is already complete.');insert_row('admins',$data);}finally{q("SELECT RELEASE_LOCK('galindos_first_admin')");}redirect('/admin/login');
    }
    if($path==='/login'&&$post){
        throttle('login',10,900);$username=trim((string)($_POST['username']??''));$password=(string)($_POST['password']??'');if(strlen($password)>128||strlen($username)>100)fail(422,'Invalid login input.');
        $account=q('SELECT * FROM admins WHERE username=? AND active=1',[$username])[0]??null;
        $valid=verify_password($password,$account['password_hash']??'$2y$12$A6Y3fzRjoPTMUbfFmWiMVeSMhWI.hJrcjrPGCvJP1ioTLDHmYB7Eu');
        if(!$account||!$valid){http_response_code(401);$setup=false;$error='The username or password is incorrect.';require ROOT.'/templates/login.php';return;}
        session_regenerate_id(true);$_SESSION=['userId'=>$account['id'],'loginAt'=>time(),'csrf'=>bin2hex(random_bytes(32))];redirect('/admin');
    }
    if(!user())redirect('/admin/login',302);
    if($path==='/logout'&&$post){$_SESSION=[];session_destroy();setcookie(session_name(),'', ['expires'=>1,'path'=>'/','httponly'=>true,'samesite'=>'Lax','secure'=>(!empty($_SERVER['HTTPS'])&&$_SERVER['HTTPS']!=='off')||env('COOKIE_SECURE')==='true']);redirect('/admin/login');}
    if($path==='/'&&!$post){$stats=[['Projects',q('SELECT COUNT(*) AS n FROM projects')[0]['n']],['Quote requests',can(user(),'leads')?q('SELECT COUNT(*) AS n FROM quote_submissions')[0]['n']:'—'],['Pending emails',can(user(),'leads')?q('SELECT COUNT(*) AS n FROM mail_outbox WHERE sent_at IS NULL')[0]['n']:'—']];admin_render('Content Dashboard','dashboard',compact('stats'));return;}
    foreach($resources as $key=>$resource){
        if($path!=='/'.$key&&!str_starts_with($path,'/'.$key.'/'))continue;
        require_permission($resource['permission']);$table=$resource['table'];$pk=$resource['id']??'id';$fields=$resource['fields'];
        if($path==='/'.$key&&!$post){$items=q("SELECT * FROM `$table` ORDER BY `$pk`");admin_render($resource['label'],'list',compact('key','resource','items'));return;}
        if($path==='/'.$key.'/save'&&$post){
            $values=validate($fields,$_POST);$existing=(string)($_POST['record_id']??'');
            if($key==='projects'&&!empty($values['service_id'])&&!q('SELECT id FROM services WHERE id=?',[$values['service_id']]))fail(422,'Choose an existing service.');
            tx(function()use($table,$pk,$key,$existing,$values){
                if($existing!==''){$old=q("SELECT * FROM `$table` WHERE `$pk`=? FOR UPDATE",[$existing])[0]??null;if(!$old)fail(404,'Record not found.');q("UPDATE `$table` SET ".implode(',',array_map(fn($k)=>"`$k`=?",array_keys($values)))." WHERE `$pk`=?",[...array_values($values),$existing]);if($key==='pages'&&$old['slug']!==$values['slug'])q('UPDATE navigation_tabs SET page_slug=? WHERE page_slug=?',[$values['slug'],$old['slug']]);}
                else {if($pk==='id')$values['id']=bin2hex(random_bytes(16));insert_row($table,$values);}
            });redirect('/admin/'.$key);
        }
        if(preg_match('~^/'.preg_quote($key,'~').'/([^/]+)/delete$~',$path,$m)&&$post){tx(function()use($table,$pk,$key,$m){if($key==='pages'){$p=q('SELECT slug FROM pages WHERE id=?',[$m[1]])[0]??null;if($p)q('UPDATE navigation_tabs SET visible=0 WHERE page_slug=?',[$p['slug']]);}if($key==='services')q('UPDATE projects SET service_id=NULL WHERE service_id=?',[$m[1]]);q("DELETE FROM `$table` WHERE `$pk`=?",[$m[1]]);});redirect('/admin/'.$key);}
        if($key==='projects'&&preg_match('~^/projects/([^/]+)/gallery(?:/([^/]+))?$~',$path,$m)&&$post){
            if(isset($m[2])){
                if(($_POST['action']??'')==='remove')q('DELETE FROM project_images WHERE id=? AND project_id=?',[$m[2],$m[1]]);
                else{$v=validate(gallery_fields(),$_POST);q('UPDATE project_images SET sort_order=?,alt_text=?,caption=?,image_kind=? WHERE id=? AND project_id=?',[$v['sort_order'],$v['alt_text'],$v['caption'],$v['image_kind'],$m[2],$m[1]]);}
            }else{$v=validate(gallery_fields(),$_POST);$media=q('SELECT * FROM media WHERE id=?',[(string)($_POST['media_id']??'')])[0]??null;if(!$media)fail(422,'Choose an existing library image.');q('INSERT INTO project_images(project_id,media_id,image_url,alt_text,caption,image_kind,sort_order) VALUES(?,?,?,?,?,?,?)',[$m[1],$media['id'],$media['url'],$v['alt_text']?:$media['alt_text'],$v['caption'],$v['image_kind'],$v['sort_order']]);}redirect('/admin/projects/'.$m[1]);
        }
        if(preg_match('~^/'.preg_quote($key,'~').'/([^/]+)$~',$path,$m)&&!$post){$record=$m[1]==='new'?['visible'=>1,'robots'=>'index,follow']:q("SELECT * FROM `$table` WHERE `$pk`=?",[$m[1]])[0]??null;if(!$record)fail(404,'Record not found.');$services=q('SELECT id,name FROM services');$media=q('SELECT * FROM media ORDER BY created_at DESC');$gallery=$key==='projects'&&!empty($record['id'])?q('SELECT * FROM project_images WHERE project_id=? ORDER BY sort_order,id',[$record['id']]):[];admin_render('Edit '.$resource['label'],'edit',compact('key','resource','fields','record','services','media','gallery'));return;}
        fail(404,'Admin page not found.');
    }
    if(preg_match('~^/settings/images/(favicon_url|meta_image_url|header_logo_url)$~',$path,$m)&&$post){require_permission('settings');$title=['favicon_url'=>'Website favicon','meta_image_url'=>'Social media image','header_logo_url'=>'Header logo'][$m[1]];json_response(upload_image($_FILES['image']??[],['title'=>$title,'alt_text'=>$title,'folder'=>'Branding'],$m[1]),201);}
    if($path==='/media'&&!$post){require_permission('content');admin_render('Media Library','media',['items'=>q('SELECT * FROM media ORDER BY created_at DESC')]);return;}
    if($path==='/media/upload'&&$post){require_permission('content');upload_image($_FILES['image']??[],validate(['title'=>['max'=>255],'alt_text'=>['max'=>255],'folder'=>['max'=>100]],$_POST));json_response(['redirect'=>'/admin/media'],201);}
    if(preg_match('~^/media/([^/]+)/(save|delete|replace)$~',$path,$m)&&$post){require_permission('content');
        if($m[2]==='delete')delete_media($m[1]);elseif($m[2]==='replace'){upload_image($_FILES['image']??[],[],null,$m[1]);json_response(['redirect'=>'/admin/media']);}else{$v=validate(['title'=>['max'=>255],'alt_text'=>['max'=>255],'folder'=>['max'=>100]],$_POST);q('UPDATE media SET title=?,alt_text=?,folder=? WHERE id=?',[...array_values($v),$m[1]]);}redirect('/admin/media');
    }
    if(in_array($path,['/home','/settings'],true)){$key=substr($path,1);require_permission($key==='settings'?'settings':'content');$fields=schema()[$key==='settings'?'settingsFields':'homeFields'];
        if($post){$v=validate($fields,$_POST);tx(function()use($key,$v){if($key==='home')set_setting('home',json_encode($v,JSON_THROW_ON_ERROR));else foreach($v as $k=>$value)set_setting($k,(string)$value);});redirect('/admin/'.$key);}
        $stored=[];foreach(q('SELECT * FROM site_settings') as $s)$stored[$s['setting_key']]=$s['setting_value'];$record=$key==='home'?array_merge(schema()['defaultHome'],json_decode($stored['home']??'{}',true)??[]):array_merge(schema()['defaultSettings'],$stored);$media=q('SELECT * FROM media ORDER BY created_at DESC');admin_render($key==='home'?'Homepage Content':'Settings & Branding','settings',compact('key','fields','record','media'));return;
    }
    if($path==='/sections'&&!$post){require_permission('content');admin_render('Homepage Sections','sections',['items'=>q('SELECT * FROM homepage_sections ORDER BY sort_order,section_key')]);return;}
    if(preg_match('~^/sections/([a-z]+)$~',$path,$m)&&$post){require_permission('content');$v=validate(['enabled'=>['type'=>'checkbox'],'sort_order'=>['type'=>'number']],$_POST);q('UPDATE homepage_sections SET enabled=?,sort_order=? WHERE section_key=?',[$v['enabled'],$v['sort_order'],$m[1]]);redirect('/admin/sections');}
    if($path==='/leads'&&!$post){require_permission('leads');$status=(string)($_GET['status']??'');if($status!==''&&!in_array($status,schema()['leadStatuses'],true))fail(422,'Invalid status.');$items=q('SELECT q.*,a.username AS assignee FROM quote_submissions q LEFT JOIN admins a ON a.id=q.assigned_to '.($status!==''?'WHERE q.status=? ':'').'ORDER BY submitted_at DESC LIMIT 500',$status!==''?[$status]:[]);admin_render('Quote Requests','leads',compact('items','status'));return;}
    if(preg_match('~^/leads/(\d+)$~',$path,$m)){require_permission('leads');$record=q('SELECT * FROM quote_submissions WHERE id=?',[$m[1]])[0]??null;if(!$record)fail(404,'Lead not found.');
        if($post){$v=validate(['status'=>['type'=>'select','options'=>schema()['leadStatuses'],'max'=>40],'assigned_to'=>['max'=>20],'note'=>['max'=>10000]],$_POST);if($v['assigned_to']!==''&&!q('SELECT id FROM admins WHERE id=? AND active=1',[$v['assigned_to']]))fail(422,'Choose an active assignee.');tx(function()use($v,$m){q('UPDATE quote_submissions SET status=?,assigned_to=? WHERE id=?',[$v['status'],$v['assigned_to']?:null,$m[1]]);if($v['note']!=='')q('INSERT INTO lead_notes(lead_id,author_id,note) VALUES(?,?,?)',[$m[1],user()['id'],$v['note']]);});redirect('/admin/leads/'.$m[1]);}
        $users=q('SELECT id,username FROM admins WHERE active=1');$notes=q('SELECT n.*,a.username FROM lead_notes n JOIN admins a ON a.id=n.author_id WHERE lead_id=? ORDER BY n.created_at DESC',[$m[1]]);admin_render('Request from '.$record['name'],'lead',compact('record','users','notes'));return;
    }
    if($path==='/users'){require_permission('users');if($post){insert_row('admins',account_data($_POST));redirect('/admin/users');}admin_render('Administrator Accounts','users',['items'=>q('SELECT id,username,email,role,active FROM admins ORDER BY id')]);return;}
    if(preg_match('~^/users/(\d+)$~',$path,$m)&&$post){require_permission('users');$v=validate(['role'=>['type'=>'select','options'=>['owner','editor','sales'],'max'=>20],'active'=>['type'=>'checkbox']],$_POST);$hash=empty($_POST['password'])?null:password_hash_php((string)$_POST['password']);
        tx(function()use($v,$m,$hash){$owners=q("SELECT id FROM admins WHERE role='owner' AND active=1 ORDER BY id FOR UPDATE");if((string)user()['id']===$m[1]&&(!$v['active']||$v['role']!=='owner'))fail(422,'You cannot remove your own owner access.');if(count($owners)===1&&(string)$owners[0]['id']===$m[1]&&(!$v['active']||$v['role']!=='owner'))fail(422,'At least one active owner is required.');q('UPDATE admins SET role=?,active=?,password_hash=COALESCE(?,password_hash) WHERE id=?',[$v['role'],$v['active'],$hash,$m[1]]);if($hash!==null)q('DELETE FROM php_sessions WHERE user_id=?',[$m[1]]);});if($hash!==null&&(string)user()['id']===$m[1]){$_SESSION=[];session_destroy();redirect('/admin/login');}redirect('/admin/users');
    }
    if($path==='/mail'&&!$post){require_permission('leads');admin_render('Email Delivery','mail',['items'=>q('SELECT id,recipient,subject,attempts,sent_at,last_error FROM mail_outbox ORDER BY id DESC LIMIT 200')]);return;}
    if(preg_match('~^/mail/(\d+)/retry$~',$path,$m)&&$post){require_permission('leads');q('UPDATE mail_outbox SET attempts=0,available_at=UTC_TIMESTAMP(),locked_until=NULL WHERE id=? AND sent_at IS NULL AND (locked_until IS NULL OR locked_until<UTC_TIMESTAMP())',[$m[1]]);redirect('/admin/mail');}
    fail(404,'Admin page not found.');
}
function gallery_fields():array{return ['sort_order'=>['type'=>'number'],'alt_text'=>['max'=>255],'caption'=>['max'=>255],'image_kind'=>['type'=>'select','max'=>20,'options'=>['gallery','before','after']]];}
