<?php
// CLI-only recovery for servers without Argon2 support. Requires host shell access.
if(PHP_SAPI!=='cli'){http_response_code(403);exit;}
require dirname(__DIR__).'/includes/app.php';
$username=$argv[1]??'';if(!$username)exit("Usage: php scripts/reset-admin.php username (password is read from stdin)\n");
fwrite(STDERR,"Enter new password (terminal input may be visible): ");$password=rtrim((string)fgets(STDIN),"\r\n");$hash=password_hash_php($password);
$row=q('SELECT id FROM admins WHERE username=?',[$username])[0]??null;if(!$row)fail(404,'Account not found.');
tx(function()use($row,$hash){q('UPDATE admins SET password_hash=? WHERE id=?',[$hash,$row['id']]);q('DELETE FROM php_sessions WHERE user_id=?',[$row['id']]);});echo "Password reset.\n";
