<?php
function queue_quote_mail(array $lead):void {
    if(env('MAIL_TRANSPORT')==='disabled')return;
    if(env('MAIL_FROM')===''||env('QUOTE_NOTIFY_EMAIL')===''||(env('SMTP_HOST')===''&&env('MAIL_TRANSPORT')!=='mail'))return;
    $messages=[
        [env('QUOTE_NOTIFY_EMAIL'),'New quote request: '.$lead['name'],"Name: {$lead['name']}\nEmail: {$lead['email']}\nPhone: {$lead['phone']}\nProject: {$lead['project_type']}\nLocation: {$lead['location']}\n\n{$lead['message']}\n\nManage: ".env('SITE_URL').'/admin/leads'],
        [$lead['email'],'We received your project request',"Hi {$lead['name']},\n\nThank you for contacting Galindos Builders LLC. We received your request and will contact you to discuss your project.\n\nGalindos Builders LLC\n".env('SITE_URL')],
    ];foreach($messages as [$to,$subject,$body])q('INSERT INTO mail_outbox(recipient,subject,body,available_at) VALUES(?,?,?,UTC_TIMESTAMP())',[$to,$subject,$body]);
}
function deliver_mail(?callable $send=null):int {
    if($send===null){
        if(env('MAIL_TRANSPORT')==='disabled')fail(422,'Email delivery is disabled.');
        if(!is_file(ROOT.'/vendor/autoload.php'))fail(500,'Install Composer dependencies for email delivery.');require_once ROOT.'/vendor/autoload.php';
        if(env('MAIL_FROM')===''||(env('SMTP_HOST')===''&&env('MAIL_TRANSPORT')!=='mail'))fail(500,'Configure email first.');
        $send=function(array $row):void {
            $mailer=new PHPMailer\PHPMailer\PHPMailer(true);$mailer->CharSet='UTF-8';$mailer->Timeout=30;
            if(env('MAIL_TRANSPORT')==='mail')$mailer->isMail();else{$mailer->isSMTP();$mailer->Host=env('SMTP_HOST');$mailer->Port=(int)env('SMTP_PORT','587');$mailer->SMTPAuth=env('SMTP_USER')!=='';$mailer->Username=env('SMTP_USER');$mailer->Password=env('SMTP_PASS');$mailer->SMTPSecure=env('SMTP_SECURE')==='true'?PHPMailer\PHPMailer\PHPMailer::ENCRYPTION_SMTPS:PHPMailer\PHPMailer\PHPMailer::ENCRYPTION_STARTTLS;}
            $mailer->setFrom(env('MAIL_FROM'),'Galindos Builders LLC');$mailer->addAddress($row['recipient']);$mailer->Subject=$row['subject'];$mailer->Body=$row['body'];$mailer->send();
        };
    }
    $rows=tx(function(){ $rows=q('SELECT * FROM mail_outbox WHERE sent_at IS NULL AND attempts<8 AND available_at<=UTC_TIMESTAMP() AND (locked_until IS NULL OR locked_until<UTC_TIMESTAMP()) ORDER BY id LIMIT 10 FOR UPDATE');foreach($rows as $row)q('UPDATE mail_outbox SET locked_until=DATE_ADD(UTC_TIMESTAMP(),INTERVAL 10 MINUTE),attempts=attempts+1 WHERE id=?',[$row['id']]);return $rows; });
    foreach($rows as $row)try{$send($row);q('UPDATE mail_outbox SET sent_at=UTC_TIMESTAMP(),locked_until=NULL,last_error=NULL WHERE id=?',[$row['id']]);}catch(Throwable $ex){q('UPDATE mail_outbox SET locked_until=NULL,available_at=DATE_ADD(UTC_TIMESTAMP(),INTERVAL ? SECOND),last_error=? WHERE id=?',[min(3600,30*(2**(int)$row['attempts'])),'Delivery failed; check SMTP configuration.',$row['id']]);error_log('Mail delivery failed: '.$ex->getMessage());}
    return count($rows);
}
