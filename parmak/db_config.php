<?php
// MSSQL Veritabanı Bağlantı Ayarları
// Lütfen aşağıdaki değerleri kendi ortamınıza göre güncelleyiniz.

define('DB_SERVER',   'localhost');       // SQL Server adresi veya instance adı (örn: 192.168.1.10\SQLEXPRESS)
define('DB_NAME',     'ZKTecoDb');        // Veritabanı adı
define('DB_USER',     'sa');              // Kullanıcı adı
define('DB_PASSWORD', 'SifreGiriniz');   // Şifre

/**
 * PDO MSSQL bağlantısı döndürür.
 * Gereklilik: php_pdo_sqlsrv veya php_pdo_odbc eklentisi aktif olmalıdır.
 */
function getDbConnection(): PDO
{
    $dsn = sprintf(
        'sqlsrv:Server=%s;Database=%s;TrustServerCertificate=1',
        DB_SERVER,
        DB_NAME
    );

    $options = [
        PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ];

    return new PDO($dsn, DB_USER, DB_PASSWORD, $options);
}
