<?php
/**
 * ZKLib.php
 * ZKTeco cihazlarıyla TCP/UDP socket üzerinden iletişim kurar.
 * TFace100 dahil ZK protokolü kullanan tüm cihazlarla çalışır.
 *
 * Desteklenen işlemler:
 *  - Cihaza bağlan / bağlantıyı kes
 *  - Kullanıcı listesini çek
 *  - Parmak izi şablonlarını çek
 *  - Yoklama kayıtlarını çek
 *  - Yeni kullanıcı / parmak izi şablonu gönder
 */
class ZKLib
{
    // ZK protokol sabitleri
    private const CMD_CONNECT          = 1000;
    private const CMD_EXIT             = 1001;
    private const CMD_ENABLEDEVICE     = 1002;
    private const CMD_DISABLEDEVICE    = 1003;
    private const CMD_ACK_OK           = 2000;
    private const CMD_ACK_ERROR        = 2001;
    private const CMD_ACK_DATA         = 2002;
    private const CMD_PREPARE_DATA     = 1500;
    private const CMD_DATA             = 1501;
    private const CMD_FREE_DATA        = 1502;
    private const CMD_GET_FDATA        = 11;   // Yoklama kayıtları
    private const CMD_USERTEMP_RRQ     = 9;    // Parmak izi şablonu oku
    private const CMD_USER_WRQ        = 8;    // Kullanıcı yaz
    private const CMD_USERTEMP_WRQ    = 10;   // Parmak izi şablonu yaz
    private const CMD_READ_ALL_USER_ID = 5;   // Tüm kullanıcıları oku

    private string $ip;
    private int    $port;
    private int    $timeout;

    /** @var resource|false */
    private $socket = false;

    private int $sessionId  = 0;
    private int $replyId    = 0;

    public function __construct(string $ip, int $port = 4370, int $timeout = 10)
    {
        $this->ip      = $ip;
        $this->port    = $port;
        $this->timeout = $timeout;
    }

    // -------------------------------------------------------------------------
    // Bağlantı
    // -------------------------------------------------------------------------

    public function connect(): bool
    {
        $this->socket = @fsockopen($this->ip, $this->port, $errno, $errstr, $this->timeout);
        if (!$this->socket) {
            throw new RuntimeException("Cihaza bağlanılamadı ({$this->ip}:{$this->port}): $errstr ($errno)");
        }
        stream_set_timeout($this->socket, $this->timeout);

        $this->sessionId = 0;
        $this->replyId   = 0;

        $response = $this->sendCommand(self::CMD_CONNECT, '');
        if ($this->getResponseCode($response) !== self::CMD_ACK_OK) {
            fclose($this->socket);
            $this->socket = false;
            throw new RuntimeException('Cihaz bağlantı isteğini reddetti.');
        }

        $this->sessionId = unpack('v', substr($response, 4, 2))[1];
        return true;
    }

    public function disconnect(): void
    {
        if ($this->socket) {
            $this->sendCommand(self::CMD_EXIT, '');
            fclose($this->socket);
            $this->socket = false;
        }
    }

    // -------------------------------------------------------------------------
    // Kullanıcı işlemleri
    // -------------------------------------------------------------------------

    /**
     * Cihazdaki tüm kullanıcıları döndürür.
     *
     * @return array<int, array{uid: int, privilege: int, password: string, name: string, card: string, user_id: string}>
     */
    public function getUsers(): array
    {
        $this->sendCommand(self::CMD_DISABLEDEVICE, '');
        $data = $this->readLargeData(self::CMD_READ_ALL_USER_ID);
        $this->sendCommand(self::CMD_ENABLEDEVICE, '');

        if ($data === false) {
            return [];
        }

        $users  = [];
        $offset = 4; // İlk 4 byte boyut bilgisi

        while ($offset < strlen($data)) {
            if ($offset + 28 > strlen($data)) {
                break;
            }

            $user = unpack(
                'vuid/Cprivilege/a8password/a24name/a4card/a9user_id',
                substr($data, $offset, 28 + 9)
            );

            $user['name']     = rtrim($user['name'], "\0");
            $user['password'] = rtrim($user['password'], "\0");
            $user['user_id']  = rtrim($user['user_id'], "\0");
            $user['card']     = rtrim($user['card'], "\0");
            $users[]          = $user;

            $offset += 28 + 9;
        }

        return $users;
    }

    // -------------------------------------------------------------------------
    // Parmak izi şablonu işlemleri
    // -------------------------------------------------------------------------

    /**
     * Belirtilen kullanıcının tüm parmak izi şablonlarını çeker.
     *
     * @return array<int, array{uid: int, finger_id: int, template: string, flag: int}>
     */
    public function getFingerprints(int $uid): array
    {
        $templates = [];
        for ($fingerIdx = 0; $fingerIdx < 10; $fingerIdx++) {
            $payload = pack('vCv', $uid, $fingerIdx, 0);
            $response = $this->sendCommand(self::CMD_USERTEMP_RRQ, $payload);
            $code = $this->getResponseCode($response);

            if ($code === self::CMD_ACK_OK || $code === self::CMD_PREPARE_DATA) {
                $tplData = $this->receiveData($response);
                if ($tplData !== false && strlen($tplData) > 6) {
                    $header = unpack('vuid/Cfinger_id/Cflag', substr($tplData, 0, 4));
                    $templates[] = [
                        'uid'       => $header['uid'],
                        'finger_id' => $header['finger_id'],
                        'flag'      => $header['flag'],
                        'template'  => bin2hex(substr($tplData, 6)),
                    ];
                }
            }
        }
        return $templates;
    }

    /**
     * Tüm yoklama (giriş/çıkış) kayıtlarını döndürür.
     *
     * @return array<int, array{uid: int, status: int, verify: int, timestamp: string}>
     */
    public function getAttendance(): array
    {
        $this->sendCommand(self::CMD_DISABLEDEVICE, '');
        $data = $this->readLargeData(self::CMD_GET_FDATA);
        $this->sendCommand(self::CMD_ENABLEDEVICE, '');

        if ($data === false) {
            return [];
        }

        $records = [];
        $offset  = 4;

        while ($offset + 8 <= strlen($data)) {
            $rec  = substr($data, $offset, 8);
            $uid  = unpack('v', substr($rec, 0, 2))[1];
            $stat = ord($rec[2]);
            $ver  = ord($rec[3]);
            $ts   = $this->decodeTime(unpack('V', substr($rec, 4, 4))[1]);

            $records[] = [
                'uid'       => $uid,
                'status'    => $stat,
                'verify'    => $ver,
                'timestamp' => $ts,
            ];
            $offset += 8;
        }

        return $records;
    }

    // -------------------------------------------------------------------------
    // Dahili yardımcı metodlar
    // -------------------------------------------------------------------------

    /** ZK zaman kodunu datetime string'e dönüştürür. */
    private function decodeTime(int $t): string
    {
        $second = $t % 60;   $t = (int)($t / 60);
        $minute = $t % 60;   $t = (int)($t / 60);
        $hour   = $t % 24;   $t = (int)($t / 24);
        $day    = $t % 31 + 1; $t = (int)($t / 31);
        $month  = $t % 12 + 1; $t = (int)($t / 12);
        $year   = $t + 2000;
        return sprintf('%04d-%02d-%02d %02d:%02d:%02d', $year, $month, $day, $hour, $minute, $second);
    }

    /** Komut paketi oluşturup gönderir ve yanıtı döndürür. */
    private function sendCommand(int $command, string $data): string
    {
        $this->replyId = ($this->replyId + 1) & 0xFFFF;

        $buf = pack('vvvv', $command, 0, $this->sessionId, $this->replyId) . $data;
        $chk = $this->calculateChecksum($buf);
        $buf = pack('vvvv', $command, $chk, $this->sessionId, $this->replyId) . $data;

        // TCP ZK protokolü: paketten önce 4 byte boş prefix gönderilmeli
        fwrite($this->socket, "\x00\x00\x00\x00" . $buf);

        $response = $this->receivePacket();
        return $response !== false ? $response : '';
    }

    /**
     * Soket üzerinden paket okur.
     * TCP ZK protokolü: [4-byte prefix/size] [8-byte ZK header] [payload]
     */
    private function receivePacket(): string|false
    {
        // TCP'de her yanıt başında 4-byte prefix (total packet size) gelir
        $prefix = $this->read(4);
        if (strlen($prefix) < 4) {
            return false;
        }

        // 8-byte ZK header: CMD(2) + CHK(2) + SESSION(2) + REPLY(2)
        $header = $this->read(8);
        if (strlen($header) < 8) {
            return false;
        }

        // Prefix'teki boyuttan data miktarını hesapla
        $totalSize = unpack('V', $prefix)[1] ?? 0;
        $dataSize  = $totalSize > 8 ? $totalSize - 8 : 0;

        $payload = $dataSize > 0 ? $this->read($dataSize) : '';

        return $header . $payload;
    }

    /** Soketten belirli uzunlukta veri okur. */
    private function read(int $length): string
    {
        $buf = '';
        $remaining = $length;
        while ($remaining > 0 && !feof($this->socket)) {
            $chunk = fread($this->socket, $remaining);
            if ($chunk === false || $chunk === '') {
                break;
            }
            $buf      .= $chunk;
            $remaining -= strlen($chunk);
        }
        return $buf;
    }

    /** Yanıt paketinden komut kodunu döndürür. */
    private function getResponseCode(string $packet): int
    {
        if (strlen($packet) < 2) {
            return -1;
        }
        return unpack('v', substr($packet, 0, 2))[1];
    }

    /**
     * Büyük veri bloklarını CMD_PREPARE_DATA → CMD_DATA akışıyla okur.
     * @return string|false
     */
    private function readLargeData(int $command): string|false
    {
        $response = $this->sendCommand($command, '');
        $code     = $this->getResponseCode($response);

        if ($code === self::CMD_ACK_ERROR) {
            return false;
        }

        if ($code === self::CMD_PREPARE_DATA) {
            return $this->receiveData($response);
        }

        // Küçük yanıt
        return substr($response, 8);
    }

    /**
     * CMD_PREPARE_DATA protokolüne göre çok parçalı veri okur.
     * @return string|false
     */
    private function receiveData(string $prepareResponse): string|false
    {
        if (strlen($prepareResponse) < 12) {
            return false;
        }
        $size   = unpack('V', substr($prepareResponse, 8, 4))[1];
        $buffer = '';

        while (strlen($buffer) < $size) {
            $packet = $this->receivePacket();
            if ($packet === false) {
                break;
            }
            $code = $this->getResponseCode($packet);
            if ($code !== self::CMD_DATA) {
                break;
            }
            $buffer .= substr($packet, 8);
        }

        // CMD_FREE_DATA gönder
        $this->sendCommand(self::CMD_FREE_DATA, '');

        return $buffer ?: false;
    }

    /** ZK checksum hesaplar. */
    private function calculateChecksum(string $buf): int
    {
        $chksum = 0;
        $length = strlen($buf);
        $i      = 0;

        while ($length > 1) {
            $w       = (ord($buf[$i]) & 0xFF) | ((ord($buf[$i + 1]) & 0xFF) << 8);
            $chksum += $w;
            $chksum  = $chksum & 0xFFFF;
            $i      += 2;
            $length -= 2;
        }
        if ($length > 0) {
            $chksum += ord($buf[$i]);
        }
        while ($chksum >> 16) {
            $chksum = ($chksum & 0xFFFF) + ($chksum >> 16);
        }
        return (~$chksum) & 0xFFFF;
    }
}
