<?php
/**
 * push_receiver.php
 * ZKTeco cihazının ADMS (PUSH) protokolüyle gönderdiği anlık
 * giriş/çıkış ve kullanıcı verilerini alır ve MSSQL'e kaydeder.
 *
 * Cihaz ayarları (Cihaz menüsü → İletişim → PUSH Sunucusu):
 *   Sunucu Adresi : http://sunucu-ip/parmak/push_receiver.php
 *   Port          : 80 (veya 443 HTTPS için)
 *
 * Güvenlik için PUSH_SECRET ile basit token doğrulaması yapılır.
 * Cihazın gönderdiği isteğe aynı secret parametresini ekleyin.
 */

define('PUSH_SECRET', 'degistirin_gizli_anahtar'); // Cihaz ile eşleşmeli

require_once __DIR__ . '/db_config.php';

// -------------------------------------------------------------------------
// Yetki doğrulama
// -------------------------------------------------------------------------
$secret = $_GET['secret'] ?? $_SERVER['HTTP_X_PUSH_SECRET'] ?? '';
if ($secret !== PUSH_SECRET) {
    http_response_code(403);
    exit('Yetkisiz erişim.');
}

// -------------------------------------------------------------------------
// Ham veriyi oku
// -------------------------------------------------------------------------
$rawBody  = file_get_contents('php://input');
$postData = $_POST;

// ZKTeco cihazları genellikle şu formatlardan birini kullanır:
//   1. URL-encoded POST (table=Attendance&Stamp=...)
//   2. JSON POST body
//   3. XML (daha eski modeller)

$table = $postData['table'] ?? '';
if (empty($table) && !empty($rawBody)) {
    $json = json_decode($rawBody, true);
    if (is_array($json)) {
        $table    = $json['table'] ?? '';
        $postData = array_merge($postData, $json);
    }
}

// -------------------------------------------------------------------------
// Veritabanı bağlantısı
// -------------------------------------------------------------------------
try {
    $pdo = getDbConnection();
} catch (PDOException $e) {
    http_response_code(500);
    error_log('DB bağlantı hatası: ' . $e->getMessage());
    exit('Veritabanı bağlantısı başarısız.');
}

// -------------------------------------------------------------------------
// Tablo türüne göre işlem
// -------------------------------------------------------------------------
$handled = false;

switch (strtolower($table)) {

    // -----------------------------------------------------------------------
    // Giriş / Çıkış (Yoklama) kaydı
    // -----------------------------------------------------------------------
    case 'attendance':
    case 'checkinout':
        $uid       = intval($postData['uid']       ?? $postData['UserID']    ?? 0);
        $timestamp = $postData['timestamp']        ?? $postData['CheckTime'] ?? date('Y-m-d H:i:s');
        $status    = intval($postData['status']    ?? $postData['Status']    ?? 0);
        $verify    = intval($postData['verify']    ?? $postData['VerifyType']?? 0);

        if ($uid > 0) {
            try {
                $stmt = $pdo->prepare(
                    "IF NOT EXISTS (
                         SELECT 1 FROM dbo.ZK_Attendance
                         WHERE UID = :uid AND [Timestamp] = :ts AND Status = :status
                     )
                     INSERT INTO dbo.ZK_Attendance (UID, [Timestamp], Status, VerifyType, CreatedAt)
                     VALUES (:uid, :ts, :status, :verify, GETDATE())"
                );
                $stmt->execute([
                    ':uid'    => $uid,
                    ':ts'     => $timestamp,
                    ':status' => $status,
                    ':verify' => $verify,
                ]);
                $handled = true;
            } catch (PDOException $e) {
                error_log('Yoklama kaydı hatası: ' . $e->getMessage());
            }
        }
        break;

    // -----------------------------------------------------------------------
    // Yeni / Güncellenen kullanıcı
    // -----------------------------------------------------------------------
    case 'user':
    case 'users':
        $userId    = $postData['user_id']   ?? $postData['UserID']   ?? '';
        $uid       = intval($postData['uid']?? $postData['UID']      ?? 0);
        $name      = $postData['name']      ?? $postData['Name']     ?? '';
        $privilege = intval($postData['privilege'] ?? 0);
        $card      = $postData['card']      ?? $postData['CardNum']  ?? '';

        if (!empty($userId)) {
            try {
                $stmt = $pdo->prepare(
                    "MERGE dbo.ZK_Users AS target
                     USING (VALUES (:user_id, :uid, :privilege, :name, :card))
                           AS source (UserID, UID, Privilege, [Name], CardNum)
                           ON target.UserID = source.UserID
                     WHEN MATCHED THEN
                         UPDATE SET UID = source.UID, Privilege = source.Privilege,
                                    [Name] = source.[Name], CardNum = source.CardNum,
                                    UpdatedAt = GETDATE()
                     WHEN NOT MATCHED THEN
                         INSERT (UserID, UID, Privilege, [Name], CardNum, CreatedAt, UpdatedAt)
                         VALUES (source.UserID, source.UID, source.Privilege,
                                 source.[Name], source.CardNum, GETDATE(), GETDATE());"
                );
                $stmt->execute([
                    ':user_id'  => $userId,
                    ':uid'      => $uid,
                    ':privilege'=> $privilege,
                    ':name'     => $name,
                    ':card'     => $card,
                ]);
                $handled = true;
            } catch (PDOException $e) {
                error_log('Kullanıcı kayıt hatası: ' . $e->getMessage());
            }
        }
        break;

    // -----------------------------------------------------------------------
    // Parmak izi şablonu
    // -----------------------------------------------------------------------
    case 'fingertmp':
    case 'fingerprint':
        $uid      = intval($postData['uid']      ?? $postData['UID']      ?? 0);
        $fingerId = intval($postData['finger_id'] ?? $postData['FingerID'] ?? 0);
        $template = $postData['template']        ?? $postData['Template']  ?? '';
        $flag     = intval($postData['flag']     ?? $postData['Flag']     ?? 0);

        if ($uid > 0 && !empty($template)) {
            try {
                $stmt = $pdo->prepare(
                    "MERGE dbo.ZK_Fingerprints AS target
                     USING (VALUES (:uid, :finger_id, :template, :flag))
                           AS source (UID, FingerID, Template, Flag)
                           ON target.UID = source.UID AND target.FingerID = source.FingerID
                     WHEN MATCHED THEN
                         UPDATE SET Template = source.Template, Flag = source.Flag,
                                    UpdatedAt = GETDATE()
                     WHEN NOT MATCHED THEN
                         INSERT (UID, FingerID, Template, Flag, CreatedAt, UpdatedAt)
                         VALUES (source.UID, source.FingerID, source.Template,
                                 source.Flag, GETDATE(), GETDATE());"
                );
                $stmt->execute([
                    ':uid'       => $uid,
                    ':finger_id' => $fingerId,
                    ':template'  => $template,
                    ':flag'      => $flag,
                ]);
                $handled = true;
            } catch (PDOException $e) {
                error_log('Parmak izi kayıt hatası: ' . $e->getMessage());
            }
        }
        break;

    default:
        // Bilinmeyen tablo – kaydı logla
        error_log('Bilinmeyen PUSH tablosu: ' . $table . ' | Veri: ' . $rawBody);
        $handled = true; // Cihaza 200 dön ki tekrar göndermesin
        break;
}

// ZKTeco cihazı başarılı yanıt olarak "OK" veya "ok" bekler.
http_response_code($handled ? 200 : 400);
echo $handled ? 'OK' : 'Veri işlenemedi.';
