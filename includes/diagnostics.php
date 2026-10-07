<?php
function installation_error(Throwable $error): array {
    if($error instanceof PDOException){
        if(str_contains(strtolower($error->getMessage()),'could not find driver'))
            return [503,'PHP’s PDO MySQL extension is disabled. Enable pdo_mysql for this domain in cPanel.'];
        $code=(int)($error->errorInfo[1]??0);
        $messages=[
            1045=>'MySQL rejected the username or password. Check DB_USER and DB_PASS in the server’s .env file, including the full cPanel username prefix.',
            1044=>'The database user cannot access this database. In cPanel, add the user to the database and grant its privileges.',
            1049=>'The configured database does not exist. Set DB_NAME to the full database name shown in cPanel, including its account prefix.',
            2002=>'PHP cannot reach MySQL. Check DB_HOST and DB_PORT against the hostname supplied by your hosting provider.',
            2003=>'PHP cannot reach MySQL. Check DB_HOST and DB_PORT against the hostname supplied by your hosting provider.',
            1142=>'MySQL denied a required installation operation. Grant this database user All Privileges on this database in cPanel, then retry.',
            1143=>'MySQL denied a required installation operation. Grant this database user All Privileges on this database in cPanel, then retry.',
            1227=>'MySQL denied a required installation operation. Check this database user’s privileges with your hosting provider.',
            1062=>'Existing data contains a duplicate value required to be unique. Keep your data intact and share the Installer error from the PHP log to identify the conflicting migration.',
            1146=>'A required table is missing. Verify that all PHP release files were uploaded, and share the Installer error from the PHP log.',
            1005=>'MySQL could not create a required table. Share the Installer error from the PHP log so its schema compatibility can be checked.',
        ];
        return [$code===1062?409:503,($messages[$code]??'A database operation failed. Share the Installer error from the PHP log to identify the cause.').($code?' (MySQL error '.$code.')':'')];
    }
    $status=(int)$error->getCode();if($status<400||$status>599)$status=500;
    return [$status,$status<500?$error->getMessage():'Installation failed during a PHP operation. Check that PHP 8.2+ and the required extensions are enabled, then share the Installer error from the PHP log.'];
}
