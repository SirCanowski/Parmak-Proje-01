<?php
/**
 * index.php
 * ZKTeco TFace100 – Dashboard
 * Veritabanındaki kullanıcıları, parmak izlerini ve yoklama kayıtlarını listeler.
 */
require_once __DIR__ . '/db_config.php';

$error = null;
$users = $fingerprints = $attendance = [];

try {
    $pdo = getDbConnection();

    $users       = $pdo->query("SELECT * FROM dbo.ZK_Users       ORDER BY CreatedAt DESC")->fetchAll();
    $fingerprints= $pdo->query("SELECT * FROM dbo.ZK_Fingerprints ORDER BY CreatedAt DESC")->fetchAll();
    $attendance  = $pdo->query(
        "SELECT TOP 200 * FROM dbo.ZK_Attendance ORDER BY [Timestamp] DESC"
    )->fetchAll();

} catch (PDOException $e) {
    $error = $e->getMessage();
}
?>
<!DOCTYPE html>
<html lang="tr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>ZKTeco TFace100 – Dashboard</title>
    <style>
        * { box-sizing: border-box; }
        body { font-family: Arial, sans-serif; background: #f0f2f5; margin: 0; padding: 20px; color: #333; }
        h1   { text-align: center; color: #2c3e50; margin-bottom: 30px; }
        h2   { color: #2c3e50; border-bottom: 2px solid #3498db; padding-bottom: 6px; }
        .container { max-width: 1200px; margin: 0 auto; }
        .actions   { text-align: right; margin-bottom: 20px; display:flex; gap:10px; flex-wrap:wrap; justify-content:flex-end; }
        .btn {
            display: inline-block; padding: 10px 22px;
            color: #fff; text-decoration: none; border-radius: 5px; font-size: 14px; white-space:nowrap;
        }
        .btn-green  { background: #3498db; }
        .btn-green:hover  { background: #2980b9; }
        .btn-orange { background: #e67e22; }
        .btn-orange:hover { background: #ca6f1e; }
        .btn-gray   { background: #7f8c8d; }
        .btn-gray:hover   { background: #636e72; }
        .btn-red    { background: #e74c3c; }
        .btn-red:hover    { background: #c0392b; }
        .card { background: #fff; border-radius: 8px; box-shadow: 0 2px 6px rgba(0,0,0,.1);
                padding: 20px; margin-bottom: 30px; overflow-x: auto; }
        table { width: 100%; border-collapse: collapse; font-size: 13px; }
        th    { background: #3498db; color: #fff; padding: 10px 12px; text-align: left; }
        td    { padding: 9px 12px; border-bottom: 1px solid #eee; }
        tr:last-child td { border-bottom: none; }
        tr:hover td { background: #f9f9f9; }
        .badge-fp  { background: #27ae60; color:#fff; padding: 2px 8px; border-radius: 12px; font-size:11px; }
        .badge-att { background: #e67e22; color:#fff; padding: 2px 8px; border-radius: 12px; font-size:11px; }
        .err { background:#f8d7da; border:1px solid #f5c6cb; padding:12px 16px; border-radius:4px; color:#721c24; }
        .stats { display:flex; gap:16px; margin-bottom:24px; flex-wrap:wrap; }
        .stat-box { flex:1; min-width:160px; background:#fff; border-radius:8px;
                    box-shadow:0 2px 6px rgba(0,0,0,.1); padding:20px; text-align:center; }
        .stat-box .num { font-size:36px; font-weight:bold; color:#3498db; }
        .stat-box .lbl { font-size:13px; color:#666; margin-top:4px; }
    </style>
</head>
<body>
<div class="container">
    <h1>🖐 ZKTeco TFace100 – Parmak İzi Yönetim Sistemi</h1>

    <?php if ($error): ?>
        <div class="err">⚠ Veritabanı Hatası: <?= htmlspecialchars($error) ?></div>
    <?php else: ?>

    <div class="actions">
        <a class="btn btn-green"  href="sync.php">🔄 Cihazdan Senkronize Et</a>
        <a class="btn btn-orange" href="push_receiver.php?secret=degistirin_gizli_anahtar&table=attendance&uid=0&timestamp=<?= urlencode(date('Y-m-d H:i:s')) ?>&status=0&verify=0" target="_blank">📡 Push Alıcısını Test Et</a>
        <a class="btn btn-gray"   href="database_schema.txt" target="_blank">🗄️ Veritabanı Şeması</a>
        <a class="btn btn-red"    href="?action=refresh">↺ Sayfayı Yenile</a>
    </div>

    <div class="stats">
        <div class="stat-box">
            <div class="num"><?= count($users) ?></div>
            <div class="lbl">Kayıtlı Kullanıcı</div>
        </div>
        <div class="stat-box">
            <div class="num"><?= count($fingerprints) ?></div>
            <div class="lbl">Parmak İzi Şablonu</div>
        </div>
        <div class="stat-box">
            <div class="num"><?= count($attendance) ?></div>
            <div class="lbl">Son Yoklama Kaydı</div>
        </div>
    </div>

    <!-- KULLANICILAR -->
    <div class="card">
        <h2>👥 Kullanıcılar</h2>
        <?php if (empty($users)): ?>
            <p>Henüz kullanıcı kaydı bulunmuyor.</p>
        <?php else: ?>
        <table>
            <thead>
                <tr>
                    <th>UID</th>
                    <th>Kullanıcı ID</th>
                    <th>Ad Soyad</th>
                    <th>Yetki</th>
                    <th>Kart No</th>
                    <th>Eklenme Tarihi</th>
                </tr>
            </thead>
            <tbody>
            <?php foreach ($users as $u): ?>
                <tr>
                    <td><?= htmlspecialchars((string)$u['UID']) ?></td>
                    <td><?= htmlspecialchars((string)$u['UserID']) ?></td>
                    <td><?= htmlspecialchars((string)$u['Name']) ?></td>
                    <td><?= htmlspecialchars((string)$u['Privilege']) ?></td>
                    <td><?= htmlspecialchars((string)$u['CardNum']) ?></td>
                    <td><?= htmlspecialchars((string)$u['CreatedAt']) ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
        <?php endif; ?>
    </div>

    <!-- PARMAK İZLERİ -->
    <div class="card">
        <h2>🔑 Parmak İzi Şablonları</h2>
        <?php if (empty($fingerprints)): ?>
            <p>Henüz parmak izi kaydı bulunmuyor.</p>
        <?php else: ?>
        <table>
            <thead>
                <tr>
                    <th>UID</th>
                    <th>Parmak ID</th>
                    <th>Bayrak</th>
                    <th>Şablon (ilk 32 karakter)</th>
                    <th>Son Güncelleme</th>
                </tr>
            </thead>
            <tbody>
            <?php foreach ($fingerprints as $fp): ?>
                <tr>
                    <td><?= htmlspecialchars((string)$fp['UID']) ?></td>
                    <td><span class="badge-fp">Parmak <?= htmlspecialchars((string)$fp['FingerID']) ?></span></td>
                    <td><?= htmlspecialchars((string)$fp['Flag']) ?></td>
                    <td><code><?= htmlspecialchars(substr((string)$fp['Template'], 0, 32)) ?>…</code></td>
                    <td><?= htmlspecialchars((string)$fp['UpdatedAt']) ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
        <?php endif; ?>
    </div>

    <!-- YOKLAMA KAYITLARI -->
    <div class="card">
        <h2>📋 Son 200 Yoklama Kaydı</h2>
        <?php if (empty($attendance)): ?>
            <p>Henüz yoklama kaydı bulunmuyor.</p>
        <?php else: ?>
        <table>
            <thead>
                <tr>
                    <th>UID</th>
                    <th>Zaman</th>
                    <th>Durum</th>
                    <th>Doğrulama</th>
                    <th>Eklenme</th>
                </tr>
            </thead>
            <tbody>
            <?php foreach ($attendance as $att): ?>
                <tr>
                    <td><?= htmlspecialchars((string)$att['UID']) ?></td>
                    <td><?= htmlspecialchars((string)$att['Timestamp']) ?></td>
                    <td><span class="badge-att"><?= htmlspecialchars((string)$att['Status']) ?></span></td>
                    <td><?= htmlspecialchars((string)$att['VerifyType']) ?></td>
                    <td><?= htmlspecialchars((string)$att['CreatedAt']) ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
        <?php endif; ?>
    </div>

    <?php endif; ?>
</div>
</body>
</html>
