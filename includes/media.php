<?php
function upload_root():string {$path=env('UPLOAD_DIR','uploads');return preg_match('~^(?:/|[A-Za-z]:[\\\\/])~',$path)?$path:ROOT.'/'.$path;}
function upload_image(array $file,array $meta=[],?string $settingKey=null,?string $replaceId=null):array {
    if(($file['error']??UPLOAD_ERR_NO_FILE)!==UPLOAD_ERR_OK||empty($file['tmp_name'])||!is_uploaded_file($file['tmp_name']))fail(422,'Choose an image to upload.');
    if(filesize($file['tmp_name'])>10*1024*1024)fail(413,'Image must be under 10 MB.');
    $type=(new finfo(FILEINFO_MIME_TYPE))->file($file['tmp_name']);$types=['image/jpeg'=>'jpg','image/png'=>'png','image/webp'=>'webp','image/gif'=>'gif','image/x-icon'=>'ico','image/vnd.microsoft.icon'=>'ico'];
    if(!isset($types[$type]))fail(422,'Use a valid JPG, PNG, WebP, GIF or ICO image.');
    if($settingKey==='meta_image_url'&&$types[$type]==='ico')fail(422,'Use a JPG, PNG, WebP or GIF social image.');
    $id=bin2hex(random_bytes(16));$url='/uploads/media/'.$id.'.'.$types[$type];
    if($replaceId!==null){$old=q('SELECT * FROM media WHERE id=?',[$replaceId])[0]??null;if(!$old||!preg_match('~^/uploads/media/[a-f0-9]{32}\.(jpg|png|gif|webp|ico)$~',$old['url']))fail(422,'Only media-library uploads can be replaced.');if(($types[$type]??'')!==pathinfo($old['url'],PATHINFO_EXTENSION))fail(422,'Use the same image format as the original.');$id=$old['id'];$url=$old['url'];}
    $directory=upload_root().'/media';if(!is_dir($directory)&&!mkdir($directory,0775,true)&&!is_dir($directory))fail(500,'Unable to create upload directory.');
    $destination=$directory.'/'.basename($url);$temporary=$destination.'.'.bin2hex(random_bytes(8)).'.tmp';
    if(!move_uploaded_file($file['tmp_name'],$temporary))fail(500,'Unable to save upload.');
    $size=filesize($temporary);$renamed=false;
    try{
        if(!rename($temporary,$destination))fail(500,'Unable to apply upload.');$renamed=true;
        tx(function()use($replaceId,$id,$url,$meta,$type,$size,$settingKey){
            if($replaceId!==null)q('UPDATE media SET size_bytes=?,mime_type=? WHERE id=?',[$size,$type,$id]);
            else q('INSERT INTO media(id,url,title,alt_text,folder,mime_type,size_bytes) VALUES(?,?,?,?,?,?,?)',[$id,$url,mb_substr($meta['title']??'Uploaded image',0,255),mb_substr($meta['alt_text']??'',0,255),mb_substr($meta['folder']??'',0,100),$type,$size]);
            if($settingKey!==null)set_setting($settingKey,$url);
        });
    }catch(Throwable $ex){if($renamed&&$replaceId===null)@unlink($destination);throw $ex;}finally{if(is_file($temporary))@unlink($temporary);}
    return ['id'=>$id,'url'=>$url,'key'=>$settingKey,'title'=>$meta['title']??'Image'];
}
function delete_media(string $id):void {
    $m=q('SELECT * FROM media WHERE id=?',[$id])[0]??null;if(!$m)fail(404,'Image not found.');
    foreach([['project_images','image_url'],['projects','featured_image'],['projects','og_image'],['pages','featured_image'],['pages','og_image'],['services','featured_image'],['services','og_image'],['service_areas','featured_image'],['service_areas','og_image'],['seo_settings','og_image'],['site_settings','setting_value']] as [$table,$column])if(q("SELECT 1 FROM `$table` WHERE `$column`=? LIMIT 1",[$m['url']]))fail(409,'This image is in use. Remove its references before deleting it.');
    q('DELETE FROM media WHERE id=?',[$id]);if(preg_match('~^/uploads/media/[a-f0-9]{32}\.(jpg|png|gif|webp|ico)$~',$m['url'])){ $path=upload_root().'/media/'.basename($m['url']);if(is_file($path))unlink($path); }
}
