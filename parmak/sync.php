<?php
/**
 * sync.php
 * ZKTeco TFace100 cihazından kullanıcıları ve parmak izi şablonlarını
 * çekip MSSQL veritabanına kaydeder.
 *
 * Çalıştırmak için tarayıcıdan veya CLI'dan erişin:
 *   http://sunucu/parmak/sync.php
 *   php sync.php
 *
 * Cihaz IP'sini ve portunu aşağıdaki sabitlerde güncelleyin.
 */

define('ZK_IP',      '192.168.1.201'); // Cihazın IP adresi
define('ZK_PORT',    4370);            // Varsayılan ZK portu
define('ZK_TIMEOUT', 10);             // Bağlantı zaman aşımı (sn)

require_once __DIR__ . '/db_config.php';
require_once __DIR__ . '/ZKLib.php';

header('Content-Type: text/html; charset=utf-8');

$messages = [];
$errors   = [];

try {
    $pdo = getDbConnection();

    // -------------------------------------------------------------------------
    // Cihaza bağlan
    // -------------------------------------------------------------------------
    $zk = new ZKLib(ZK_IP, ZK_PORT, ZK_TIMEOUT);
    $zk->connect();
    $messages[] = 'Cihaza başarıyla bağlanıldı (' . ZK_IP . ':' . ZK_PORT . ').';

    // -------------------------------------------------------------------------
    // Kullanıcıları çek ve kaydet
    // -------------------------------------------------------------------------
    $users = $zk->getUsers();
    $messages[] = count($users) . ' kullanıcı bulundu.';

    $upsertUser = $pdo->prepare(
        "MERGE dbo.ZK_Users AS target
         USING (VALUES (:user_id, :uid, :privilege, :name, :card))
               AS source (UserID, UID, Privilege, [Name], CardNum)
               ON target.UserID = source.UserID
         WHEN MATCHED THEN
             UPDATE SET
                 UID       = source.UID,
                 Privilege = source.Privilege,
                 [Name]    = source.[Name],
                 CardNum   = source.CardNum,
                 UpdatedAt = GETDATE()
         WHEN NOT MATCHED THEN
             INSERT (UserID, UID, Privilege, [Name], CardNum, CreatedAt, UpdatedAt)
             VALUES (source.UserID, source.UID, source.Privilege,
                     source.[Name], source.CardNum, GETDATE(), GETDATE());"
    );

    $savedUsers = 0;
    foreach ($users as $user) {
        try {
            $upsertUser->execute([
                ':user_id'   => $user['user_id'],
                ':uid'       => $user['uid'],
                ':privilege' => $user['privilege'],
                ':name'      => $user['name'],
                ':card'      => $user['card'],
            ]);
            $savedUsers++;
        } catch (PDOException $e) {
            $errors[] = "Kullanıcı kaydedilemedi (UID: {$user['uid']}): " . $e->getMessage();
        }
    }
    $messages[] = "$savedUsers kullanıcı veritabanına kaydedildi/güncellendi.";

    // -------------------------------------------------------------------------
    // Parmak izi şablonlarını çek ve kaydet
    // -------------------------------------------------------------------------
    $upsertFp = $pdo->prepare(
        "MERGE dbo.ZK_Fingerprints AS target
         USING (VALUES (:uid, :finger_id, :template, :flag))
               AS source (UID, FingerID, Template, Flag)
               ON target.UID = source.UID AND target.FingerID = source.FingerID
         WHEN MATCHED THEN
             UPDATE SET
                 Template  = source.Template,
                 Flag      = source.Flag,
                 UpdatedAt = GETDATE()
         WHEN NOT MATCHED THEN
             INSERT (UID, FingerID, Template, Flag, CreatedAt, UpdatedAt)
             VALUES (source.UID, source.FingerID, source.Template,
                     source.Flag, GETDATE(), GETDATE());"
    );

    $savedFp = 0;
    foreach ($users as $user) {
        try {
            $fingerprints = $zk->getFingerprints((int)$user['uid']);
            foreach ($fingerprints as $fp) {
                $upsertFp->execute([
                    ':uid'       => $fp['uid'],
                    ':finger_id' => $fp['finger_id'],
                    ':template'  => $fp['template'],
                    ':flag'      => $fp['flag'],
                ]);
                $savedFp++;
            }
        } catch (PDOException $e) {
            $errors[] = "Parmak izi kaydedilemedi (UID: {$user['uid']}): " . $e->getMessage();
        }
    }
    $messages[] = "$savedFp parmak izi şablonu veritabanına kaydedildi/güncellendi.";

    // -------------------------------------------------------------------------
    // Yoklama (giriş/çıkış) kayıtları – opsiyonel
    // -------------------------------------------------------------------------
    $attendance = $zk->getAttendance();
    $messages[] = count($attendance) . ' yoklama kaydı bulundu.';

    $insAtt = $pdo->prepare(
        "IF NOT EXISTS (
             SELECT 1 FROM dbo.ZK_Attendance
             WHERE UID = :uid AND [Timestamp] = :ts AND Status = :status
         )
         INSERT INTO dbo.ZK_Attendance (UID, [Timestamp], Status, VerifyType, CreatedAt)
         VALUES (:uid, :ts, :status, :verify, GETDATE())"
    );

    $savedAtt = 0;
    foreach ($attendance as $att) {
        try {
            $insAtt->execute([
                ':uid'    => $att['uid'],
                ':ts'     => $att['timestamp'],
                ':status' => $att['status'],
                ':verify' => $att['verify'],
            ]);
            $savedAtt++;
        } catch (PDOException $e) {
            $errors[] = "Yoklama kaydedilemedi: " . $e->getMessage();
        }
    }
    $messages[] = "$savedAtt yoklama kaydı veritabanına eklendi.";

    $zk->disconnect();
    $messages[] = 'Cihaz bağlantısı kapatıldı.';

} catch (RuntimeException | PDOException $e) {
    $errors[] = 'HATA: ' . $e->getMessage();
}
?>
<!DOCTYPE html>
<html lang="tr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>ZKTeco Senkronizasyon</title>
    <style>
        body { font-family: Arial, sans-serif; max-width: 800px; margin: 40px auto; padding: 0 20px; background:#f5f5f5; }
        h1   { color: #333; }
        .msg { background:#d4edda; border:1px solid #c3e6cb; padding:8px 12px; margin:5px 0; border-radius:4px; color:#155724; }
        .err { background:#f8d7da; border:1px solid #f5c6cb; padding:8px 12px; margin:5px 0; border-radius:4px; color:#721c24; }
        a    { display:inline-block; margin-top:15px; color:#007bff; }
    </style>
</head>
<body>
<h1>ZKTeco TFace100 – Senkronizasyon Sonucu</h1>
<?php foreach ($messages as $m): ?>
    <div class="msg">✔ <?= htmlspecialchars($m) ?></div>
<?php endforeach; ?>
<?php foreach ($errors as $e): ?>
    <div class="err">✖ <?= htmlspecialchars($e) ?></div>
<?php endforeach; ?>
<a href="index.php">← Dashboard'a dön</a>
</body>
</html>
