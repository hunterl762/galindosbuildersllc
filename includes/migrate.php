<?php
function migrate():void {
    if(!(int)q("SELECT GET_LOCK('galindos_cms_migrations',30) AS acquired")[0]['acquired'])fail(409,'Another migration is running.');
    try{
        foreach(['database/schema.sql','database/migrations/002-cms-v2.sql'] as $file){
            $sql=preg_replace('/^--.*$/m','',file_get_contents(ROOT.'/'.$file));$sql=preg_replace('/(?:CREATE DATABASE|USE)\b[^;]*;/i','',$sql);
            foreach(explode(';',$sql) as $statement)if(trim($statement)!=='')db()->exec(trim($statement));
        }
        q('CREATE TABLE IF NOT EXISTS schema_migrations(version VARCHAR(100) PRIMARY KEY,applied_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP) ENGINE=InnoDB');
        foreach(json_decode(file_get_contents(ROOT.'/database/migrations/columns.json'),true,512,JSON_THROW_ON_ERROR) as $table=>$columns)foreach($columns as $name=>$definition){
            if(!q('SELECT 1 FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=? AND COLUMN_NAME=?',[$table,$name]))db()->exec("ALTER TABLE `$table` ADD COLUMN `$name` $definition");
        }
        q('CREATE TABLE IF NOT EXISTS php_sessions(sid VARCHAR(128) PRIMARY KEY,data MEDIUMTEXT NOT NULL,user_id BIGINT UNSIGNED NULL,expires_at DATETIME NOT NULL,INDEX(expires_at),INDEX(user_id)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4');
        if(!q("SELECT version FROM schema_migrations WHERE version='002-cms-v2'")){
            if(!q('SELECT id FROM services LIMIT 1')&&!q("SELECT store_key FROM site_store WHERE store_key='services'")&&!is_file(ROOT.'/data/services.json'))foreach(schema()['defaultServices'] as $i=>$s)q('INSERT INTO services(id,slug,name,description,sort_order) VALUES(?,?,?,?,?)',[$s['id'],$s['slug'],$s['name'],$s['description'],$i*10]);
            if(!q('SELECT id FROM navigation_tabs LIMIT 1')&&!q("SELECT store_key FROM site_store WHERE store_key='tabs'")&&!is_file(ROOT.'/data/tabs.json'))foreach(schema()['defaultTabs'] as $i=>$t)q('INSERT INTO navigation_tabs(id,label,url,sort_order) VALUES(?,?,?,?)',[strtolower($t['label']),$t['label'],$t['url'],$i*10]);
        }
        foreach(q("SELECT id,name FROM services WHERE slug IS NULL OR slug='' ") as $s){$slug=strtolower(trim(preg_replace('/[^a-zA-Z0-9]+/','-',$s['name']),'-'));q('UPDATE services SET slug=? WHERE id=?',[substr(($slug?:'service').'-'.$s['id'],0,160),$s['id']]);}
        if(!q("SELECT 1 FROM information_schema.STATISTICS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='services' AND INDEX_NAME='services_slug_unique'"))q('CREATE UNIQUE INDEX services_slug_unique ON services(slug)');
        foreach(['hero','about','values','services','projects','contact'] as $i=>$key)q('INSERT IGNORE INTO homepage_sections(section_key,enabled,sort_order) VALUES(?,1,?)',[$key,$i*10]);
        foreach(q('SELECT DISTINCT image_url FROM project_images') as $image){$id=substr(hash('sha256',$image['image_url']),0,32);q('INSERT IGNORE INTO media(id,url,title,alt_text,folder,mime_type) VALUES(?,?,?,?,?,?)',[$id,$image['image_url'],'Imported project image','','Legacy','legacy']);q('UPDATE project_images SET media_id=? WHERE image_url=? AND media_id IS NULL',[$id,$image['image_url']]);}
        q("INSERT IGNORE INTO schema_migrations(version) VALUES('002-cms-v2'),('003-php-runtime')");
    }finally{q("SELECT RELEASE_LOCK('galindos_cms_migrations')");}
}
function import_legacy():void {
    $data=[];foreach(['admins','home','services','tabs','pages','projects','seo','quotes'] as $key){$file=ROOT.'/data/'.$key.'.json';$payload=is_file($file)?file_get_contents($file):(q('SELECT payload FROM site_store WHERE store_key=?',[$key])[0]['payload']??null);if($payload!==null)$data[$key]=json_decode($payload,true,512,JSON_THROW_ON_ERROR);}
    tx(function()use($data){
        foreach(['services'=>'services','tabs'=>'navigation','pages'=>'pages','projects'=>'projects'] as $legacy=>$key){$resource=schema()['resources'][$key];$table=$resource['table'];if(!isset($data[$legacy])||q("SELECT id FROM `$table` LIMIT 1"))continue;
            foreach($data[$legacy] as $i=>$row){$row['sort_order']=$row['sort_order']??$row['sort']??$i*10;$row['visible']=empty($row['visible'])&&array_key_exists('visible',$row)?'0':'1';$row['featured']=empty($row['featured'])?'0':'1';$row['slug']=$row['slug']??trim(strtolower(preg_replace('/[^a-zA-Z0-9]+/','-',$row['name']??$row['title']??'item')),'-').'-'.substr(bin2hex(random_bytes(4)),0,8);$values=validate($resource['fields'],$row);$values['id']=$row['id']??bin2hex(random_bytes(16));insert_row($table,$values);if($key==='projects')foreach($row['images']??[] as $order=>$url)q('INSERT INTO project_images(project_id,image_url,sort_order) VALUES(?,?,?)',[$values['id'],$url,$order]);}
        }
        if(isset($data['home']))q('INSERT IGNORE INTO site_settings(setting_key,setting_value) VALUES(?,?)',['home',json_encode($data['home'],JSON_THROW_ON_ERROR)]);
        if(isset($data['seo'])&&!q('SELECT page_key FROM seo_settings LIMIT 1'))foreach($data['seo'] as $key=>$row)insert_row('seo_settings',validate(schema()['resources']['seo']['fields'],$row+['page_key'=>$key]));
        if(isset($data['admins'])&&!q('SELECT id FROM admins LIMIT 1'))foreach($data['admins'] as $a){$hash=$a['password']??$a['password_hash']??'';if(!str_starts_with($hash,'$2')&&!str_starts_with($hash,'$argon2'))fail(422,'Unsupported legacy password hash.');q('INSERT INTO admins(username,password_hash,role) VALUES(?,?,?)',[$a['username'],$hash,'owner']);}
        if(isset($data['quotes'])&&!q('SELECT id FROM quote_submissions LIMIT 1'))foreach($data['quotes'] as $r)q('INSERT INTO quote_submissions(name,email,phone,project_type,location,message,status,submitted_at) VALUES(?,?,?,?,?,?,?,?)',[$r['name'],$r['email']??'',$r['phone']??'',$r['project_type']??$r['project']??'',$r['location']??'',$r['message']??'',$r['status']??'New',isset($r['created_at'])?gmdate('Y-m-d H:i:s',strtotime($r['created_at'])):gmdate('Y-m-d H:i:s')]);
    });
}
function insert_row(string $table,array $values):void {$columns=array_keys($values);q("INSERT INTO `$table` (`".implode('`,`',$columns).'`) VALUES ('.implode(',',array_fill(0,count($values),'?')).')',array_values($values));}
