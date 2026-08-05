<?php
/**
 * MRIMClient
 *
 * Full TCP socket client for Mail.Ru Instant Messenger (MRIM) protocol.
 * Handles TCP connection, authentication, ping/pong, contacts, and messaging.
 */

require_once __DIR__ . '/mrim-protocol.php';
require_once __DIR__ . '/mrim-wakeup.php';

class MRIMClient
{
    public const CLIENT_NAME_DEFAULT  = 'client="webagent" version="1.0" build="20260805"';
    public const CLIENT_NAME_WEBAGENT = 'client="webagent" version="1.0" build="20260805"';
    public const CLIENT_NAME_MAGENT   = 'client="magent" version="5.10" build="3850"';
    public const CLIENT_NAME_MAILRU   = 'client="Mail.Ru Agent" version="5.10" build="3850"';

    private $socket = null;
    private string $dispatcherHost;
    private int $dispatcherPort;
    private string $clientName;
    private int $featureMask = MRIMWakeUp::FEATURE_FLAG_WAKEUP; // 0x00000010
    private int $userFeatureMask = 0;
    private string $lang = 'ru';

    private string $state = 'disconnected'; // disconnected, connecting, connected, authenticated
    private int $seq = 1;
    private string $email = '';
    private string $password = '';
    private int $status = MRIMProtocol::STATUS_ONLINE;

    private int $pingPeriod = 30;
    private int $lastPingTime = 0;
    private string $readBuffer = '';

    private array $contacts = [];
    private $eventCallback = null;
    private $loggerCallback = null;

    public function __construct(array $config)
    {
        $this->dispatcherHost = $config['mrim_dispatcher_host'] ?? 'mrim.su';
        $this->dispatcherPort = $config['mrim_dispatcher_port'] ?? 2042;
        $this->clientName     = $config['mrim_client_name'] ?? self::CLIENT_NAME_DEFAULT;
        $this->pingPeriod     = $config['ping_interval'] ?? 30;
    }

    /**
     * Set callback for protocol events sent to UI: function(string $event, array $data)
     */
    public function setEventCallback(callable $callback): void
    {
        $this->eventCallback = $callback;
    }

    /**
     * Set logger callback: function(string $message, string $level)
     */
    public function setLoggerCallback(callable $callback): void
    {
        $this->loggerCallback = $callback;
    }

    private function log(string $msg, string $level = 'info'): void
    {
        if ($this->loggerCallback) {
            ($this->loggerCallback)($msg, $level);
        }
    }

    private function emit(string $event, array $data = []): void
    {
        if ($this->eventCallback) {
            ($this->eventCallback)($event, $data);
        }
    }

    /**
     * Get active TCP socket resource for stream_select()
     */
    public function getSocket()
    {
        return $this->socket;
    }

    /**
     * Get current client state
     */
    public function getState(): string
    {
        return $this->state;
    }

    /**
     * Get stored contacts list
     */
    public function getContacts(): array
    {
        return $this->contacts;
    }

    /**
     * Connect to MRIM server and initiate handshake
     */
    public function connect(string $email, string $password, int $status = MRIMProtocol::STATUS_ONLINE): bool
    {
        $this->disconnect();

        $this->email = trim($email);
        $this->password = $password;
        $this->status = $status;
        $this->state = 'connecting';

        $this->log("Connecting to MRIM dispatcher {$this->dispatcherHost}:{$this->dispatcherPort}...");

        $errno = 0;
        $errstr = '';
        $dispatcher = @stream_socket_client(
            "tcp://{$this->dispatcherHost}:{$this->dispatcherPort}",
            $errno,
            $errstr,
            5,
            STREAM_CLIENT_CONNECT
        );

        if (!$dispatcher) {
            $msg = "Failed to connect to dispatcher: $errstr ($errno)";
            $this->log($msg, 'error');
            $this->emit('error', ['message' => $msg]);
            $this->state = 'disconnected';
            return false;
        }

        // Read server node IP:PORT from dispatcher
        $nodeAddr = trim(fgets($dispatcher, 128));
        fclose($dispatcher);

        if (empty($nodeAddr)) {
            $msg = "Dispatcher returned empty server address";
            $this->log($msg, 'error');
            $this->emit('error', ['message' => $msg]);
            $this->state = 'disconnected';
            return false;
        }

        $this->log("Dispatcher redirected to MRIM server node: $nodeAddr");

        $this->socket = @stream_socket_client(
            "tcp://$nodeAddr",
            $errno,
            $errstr,
            10,
            STREAM_CLIENT_CONNECT
        );

        if (!$this->socket) {
            $msg = "Failed to connect to MRIM node $nodeAddr: $errstr ($errno)";
            $this->log($msg, 'error');
            $this->emit('error', ['message' => $msg]);
            $this->state = 'disconnected';
            return false;
        }

        stream_set_blocking($this->socket, false);
        $this->state = 'connected';
        $this->lastPingTime = time();
        $this->readBuffer = '';

        $this->log("Connected to $nodeAddr. Sending MRIM_CS_HELLO...");

        // Send CS_HELLO packet to initiate handshake
        $this->sendPacket(MRIMProtocol::MRIM_CS_HELLO, '');
        return true;
    }

    /**
     * Authenticate with stored credentials after MRIM_CS_HELLO_ACK
     */
    public function login(): void
    {
        // 0x000007FF: Standard Mail.Ru Agent 5.10 capability mask (includes FEATURE_FLAG_WAKEUP = 0x10, SMS = 0x01, MULTS = 0x20, VIDEO = 0x100)
        $featureMask = MRIMWakeUp::FEATURE_FLAG_WAKEUP | 0x000007EF;
        $userFeatureMask = 0;
        $lang = 'ru';

        if (empty($this->clientName) || strpos($this->clientName, 'webagent') !== false) {
            $this->clientName = self::CLIENT_NAME_MAGENT; // client="magent" version="5.10" build="3850"
        }
        $clientName = $this->clientName;

        $this->log(sprintf(
            "LOGIN2 CAPABILITIES:\nuser=%s\nclient=%s\nfeature_mask=0x%08X\nuser_feature_mask=0x%08X\nlang=%s",
            $this->email,
            $clientName,
            $featureMask,
            $userFeatureMask,
            $lang
        ), 'info');

        // Full MRIM_CS_LOGIN2 Payload:
        // 1. LPS(login)
        // 2. LPS(password)
        // 3. uint32(status)
        // 4. LPS(client string)
        // 5. uint32(feature_mask) -> FEATURE_FLAG_WAKEUP = 0x00000010
        // 6. LPS(xstatus_uri)
        // 7. LPS(xstatus_title)
        // 8. LPS(xstatus_desc)
        // 9. uint32(user_feature_mask)
        // 10. LPS(lang)
        $payload = MRIMProtocol::encodeLPS($this->email)
                 . MRIMProtocol::encodeLPS($this->password)
                 . MRIMProtocol::encodeUint32($this->status)
                 . MRIMProtocol::encodeLPS($clientName)
                 . MRIMProtocol::encodeUint32($featureMask)
                 . MRIMProtocol::encodeLPS('')  // xstatus_uri
                 . MRIMProtocol::encodeLPS('')  // xstatus_title
                 . MRIMProtocol::encodeLPS('')  // xstatus_desc
                 . MRIMProtocol::encodeUint32($userFeatureMask) // user_feature_mask
                 . MRIMProtocol::encodeLPS($lang); // lang

        $supportsWakeUpBool = (($featureMask & MRIMWakeUp::FEATURE_FLAG_WAKEUP) !== 0) ? 'true' : 'false';
        $this->log(sprintf(
            "LOGIN2 CAPABILITY DEBUG:\nfeature_mask=0x%08X\nsupports_wakeup=%s\nraw_payload=%s",
            $featureMask,
            $supportsWakeUpBool,
            bin2hex($payload)
        ), 'debug');

        $this->log("LOGIN2 Full HEX Payload: " . bin2hex($payload));

        $this->log(sprintf(
            "LOGIN2 DEBUG:\nemail=%s\npassword_length=%d\nstatus=%d\nclientName=%s\nfeature_mask=0x%08X\nxstatus_uri=%s\nxstatus_title=%s\nxstatus_desc=%s\nuser_feature_mask=0x%08X\nlang=%s",
            $this->email,
            strlen($this->password),
            $this->status,
            $clientName,
            $featureMask,
            '',
            '',
            '',
            $userFeatureMask,
            $lang
        ));

        $fullPacket = MRIMProtocol::buildPacket(MRIMProtocol::MRIM_CS_LOGIN2, $this->seq, $payload);
        $this->log("DEBUG PACKET DUMP:\n" . self::formatPacketDump(MRIMProtocol::MRIM_CS_LOGIN2, $fullPacket), 'debug');

        $this->log(sprintf(
            "LOGIN2 CLIENT DEBUG:\nclientName=%s\nfeature_mask=0x%08X",
            $clientName,
            $featureMask
        ), 'info');

        $this->sendPacket(MRIMProtocol::MRIM_CS_LOGIN2, $payload);
    }

    /**
     * Send an instant message to a contact
     */
    public function sendMessage(string $toEmail, string $text): bool
    {
        if ($this->state !== 'authenticated' || !$this->socket) {
            return false;
        }

        $cleanText = trim($text);
        $this->log("Sending message to $toEmail: $cleanText");

        // MRIM message flags: MESSAGE_FLAG_OFFLINE (0x1)
        $flags = MRIMProtocol::MESSAGE_FLAG_OFFLINE;

        $payload = MRIMProtocol::encodeUint32($flags) .
                   MRIMProtocol::encodeLPS($toEmail) .
                   MRIMProtocol::encodeLPSCp1251($cleanText) .
                   MRIMProtocol::encodeLPS('');

        $flagsHex = "0x" . dechex($flags);
        $rawHex = bin2hex($payload);

        $this->log("SEND MESSAGE PAYLOAD:\nMESSAGE FLAGS HEX: $flagsHex\nrecipient: $toEmail\ntext: $cleanText\nRAW_HEX: $rawHex", 'info');

        return $this->sendPacket(MRIMProtocol::MRIM_CS_MESSAGE, $payload);
    }

    /**
     * Send a WakeUp / Alarm request to a contact
     */
    public function sendWakeUp(string $toEmail): bool
    {
        if ($this->state !== 'authenticated' || !$this->socket) {
            return false;
        }

        $cleanEmail = strtolower(trim($toEmail));

        $this->log(sprintf(
            "CLIENT OBJECT DEBUG:\nobject_id=%d\nfunction=sendWakeUp",
            spl_object_id($this)
        ), 'debug');

        $this->log(sprintf(
            "CONTACT KEYS:\n%s",
            json_encode(array_keys($this->contacts))
        ), 'debug');

        $contactExists = isset($this->contacts[$cleanEmail]) ? "YES" : "NO";
        $contact = $this->contacts[$cleanEmail] ?? null;

        $this->log(sprintf(
            "CONTACT OBJECT BEFORE WAKEUP:\nemail=%s\ndata=%s",
            $cleanEmail,
            json_encode($contact)
        ), 'debug');

        $knownFeatures = $contact['feature_mask'] ?? 0;
        $supportsWakeUp = (($knownFeatures & MRIMWakeUp::FEATURE_FLAG_WAKEUP) !== 0) ? "YES" : "NO";
        $binaryStr = sprintf('%032b', $knownFeatures);

        $this->log(sprintf(
            "WAKEUP CAPABILITY CHECK:\nemail=%s\nstored_feature_mask=0x%08X\nbinary=%s\nsupports_wakeup=%s",
            $cleanEmail,
            $knownFeatures,
            $binaryStr,
            $supportsWakeUp
        ), 'debug');

        $this->log(sprintf(
            "CONTACT STATE TRACE:\npacket=%s\nfunction=%s\nemail=%s\nfeature_mask=0x%08X\nfull_contact=%s",
            'SEND_WAKEUP_CHECK',
            'sendWakeUp',
            $cleanEmail,
            $knownFeatures,
            json_encode($contact ?? [])
        ), 'debug');

        $this->log(sprintf(
            "WAKEUP TARGET DEBUG:\nrecipient=%s\ncontacts_exists=%s\nstored_feature_mask=0x%08X\nsupports_wakeup=%s",
            $cleanEmail,
            $contactExists,
            $knownFeatures,
            $supportsWakeUp
        ), 'info');

        if (($knownFeatures & MRIMWakeUp::FEATURE_FLAG_WAKEUP) === 0) {
            $this->log(sprintf(
                "WAKEUP CAPABILITY UNKNOWN:\nrecipient=%s\nstored_feature_mask=0x%08X\naction=sending_anyway",
                $cleanEmail,
                $knownFeatures
            ), 'warning');
        }

        $payload = MRIMWakeUp::buildWakeUpPayload($cleanEmail);

        $flagsHex = "0x" . sprintf('%08X', MRIMWakeUp::MESSAGE_FLAG_WAKEUP);
        $payloadHex = bin2hex($payload);

        $this->log(sprintf(
            "WAKEUP PAYLOAD DEBUG:\nrecipient=%s\nflags=%s\npayload_hex=%s",
            $cleanEmail,
            $flagsHex,
            $payloadHex
        ), 'debug');

        $fullPacket = MRIMProtocol::buildPacket(MRIMProtocol::MRIM_CS_MESSAGE, $this->seq, $payload);
        $this->log("WAKEUP PACKET DUMP:\n" . self::formatPacketDump(MRIMProtocol::MRIM_CS_MESSAGE, $fullPacket), 'debug');
        return $this->sendPacket(MRIMProtocol::MRIM_CS_MESSAGE, $payload);
    }

    /**
     * Authorize a contact on MRIM server (MRIM_CS_AUTHORIZE = 0x101C)
     */
    public function authorizeContact(string $email): bool
    {
        $cleanEmail = strtolower(trim($email));
        if (empty($cleanEmail) || $this->state !== 'authenticated') {
            return false;
        }

        $this->log("Отправка пакета авторизации MRIM_CS_AUTHORIZE для $cleanEmail...");
        $payload = MRIMProtocol::encodeLPS($cleanEmail);
        $res = $this->sendPacket(MRIMProtocol::MRIM_CS_AUTHORIZE, $payload);
        if ($res) {
            $this->log("Контакт $cleanEmail успешно авторизован на сервере MRIM!", 'info');
            $this->emit('status_log', ['message' => "Контакт $cleanEmail авторизован на сервере MRIM"]);
        }
        return $res;
    }

    /**
     * Request authorization from a contact via instant message with MESSAGE_FLAG_AUTHORIZE
     */
    public function requestAuthorization(string $email, string $reason = ''): bool
    {
        $cleanEmail = strtolower(trim($email));
        if (empty($cleanEmail) || $this->state !== 'authenticated') {
            return false;
        }

        if (empty($reason)) {
            $reason = "Пожалуйста, добавьте меня в список контактов.";
        }

        $this->log("Запрос авторизации у $cleanEmail с текстом: '$reason'");

        $flags = MRIMProtocol::MESSAGE_FLAG_AUTHORIZE;

        $payload = MRIMProtocol::encodeUint32($flags) .
                   MRIMProtocol::encodeLPS($cleanEmail) .
                   MRIMProtocol::encodeLPSCp1251($reason) .
                   MRIMProtocol::encodeLPS('');

        $res = $this->sendPacket(MRIMProtocol::MRIM_CS_MESSAGE, $payload);
        $this->authorizeContact($cleanEmail);
        return $res;
    }

    /**
     * Add contact to server contact list (MRIM_CS_ADD_CONTACT = 0x1019)
     */
    public function addContact(string $email, string $nickname = '', int $groupId = 0): bool
    {
        $cleanEmail = strtolower(trim($email));
        if (empty($cleanEmail) || $this->state !== 'authenticated') {
            return false;
        }

        if (empty($nickname)) {
            $nickname = $cleanEmail;
        }

        $this->log("Добавление контакта $cleanEmail ($nickname) на сервер MRIM...");

        $flags = 0;
        $payload = MRIMProtocol::encodeUint32($flags) .
                   MRIMProtocol::encodeUint32($groupId) .
                   MRIMProtocol::encodeLPS($cleanEmail) .
                   MRIMProtocol::encodeLPSCp1251($nickname) .
                   MRIMProtocol::encodeLPS('') .
                   MRIMProtocol::encodeLPSCp1251('Пожалуйста, авторизуйте меня в Агенте');

        $res = $this->sendPacket(MRIMProtocol::MRIM_CS_ADD_CONTACT, $payload);
        $this->authorizeContact($cleanEmail);
        $this->requestAuthorization($cleanEmail, "Пожалуйста, авторизуйте меня в Агенте");

        return $res;
    }

    /**
     * Send ping packet to keep TCP connection alive
     */
    public function sendPing(): void
    {
        if (!$this->socket || $this->state === 'disconnected') {
            return;
        }
        $this->sendPacket(MRIMProtocol::MRIM_CS_PING, '');
        $this->lastPingTime = time();
        $this->log("Sent MRIM_CS_PING", 'debug');
    }

    /**
     * Check if it is time to send a ping
     */
    public function checkPing(): void
    {
        if ($this->state === 'authenticated' || $this->state === 'connected') {
            if (time() - $this->lastPingTime >= $this->pingPeriod) {
                $this->sendPing();
            }
        }
    }

    /**
     * Reconnect using last known credentials
     */
    public function reconnect(): bool
    {
        $this->log("Attempting reconnect to MRIM...");
        if (empty($this->email) || empty($this->password)) {
            $this->log("No stored credentials for reconnect", 'error');
            return false;
        }
        $this->emit('status_log', ['message' => 'Reconnecting to MRIM server...']);
        return $this->connect($this->email, $this->password, $this->status);
    }

    /**
     * Disconnect from MRIM server
     */
    public function disconnect(): void
    {
        if ($this->socket && is_resource($this->socket)) {
            if ($this->state === 'authenticated') {
                @$this->sendPacket(MRIMProtocol::MRIM_CS_LOGOUT, '');
            }
            @fclose($this->socket);
        }
        $this->socket = null;
        $this->state = 'disconnected';
        $this->readBuffer = '';
    }

    /**
     * Format a packet hex dump and byte breakdown
     */
    public static function formatPacketDump(int $msgId, string $packet): string
    {
        $cmdName = MRIMProtocol::getCommandName($msgId);
        $len = strlen($packet);
        $hex = implode(' ', str_split(bin2hex($packet), 2));
        
        $ascii = '';
        for ($i = 0; $i < $len; $i++) {
            $byte = ord($packet[$i]);
            $ascii .= ($byte >= 32 && $byte <= 126) ? chr($byte) : '.';
        }

        $out = "=== $cmdName ===\n";
        $out .= "Length: $len bytes\n";
        $out .= "HEX:\n$hex\n";
        $out .= "ASCII:\n$ascii\n";
        $out .= "Offsets:\n";

        if ($len >= 44) {
            $magic = unpack('V', substr($packet, 0, 4))[1];
            $proto = unpack('V', substr($packet, 4, 4))[1];
            $seq   = unpack('V', substr($packet, 8, 4))[1];
            $cmd   = unpack('V', substr($packet, 12, 4))[1];
            $dlen  = unpack('V', substr($packet, 16, 4))[1];
            $from  = unpack('V', substr($packet, 20, 4))[1];
            $fport = unpack('V', substr($packet, 24, 4))[1];
            $res   = bin2hex(substr($packet, 28, 16));

            $out .= sprintf("0x00 - 0x03 [4  b] magic:    0x%08X (%s)\n", $magic, bin2hex(substr($packet, 0, 4)));
            $out .= sprintf("0x04 - 0x07 [4  b] proto:    0x%08X (%s)\n", $proto, bin2hex(substr($packet, 4, 4)));
            $out .= sprintf("0x08 - 0x0B [4  b] seq:      %d (0x%08X) (%s)\n", $seq, $seq, bin2hex(substr($packet, 8, 4)));
            $out .= sprintf("0x0C - 0x0F [4  b] msg:      0x%04X (%s)\n", $cmd, bin2hex(substr($packet, 12, 4)));
            $out .= sprintf("0x10 - 0x13 [4  b] dlen:     %d (0x%08X) (%s)\n", $dlen, $dlen, bin2hex(substr($packet, 16, 4)));
            $out .= sprintf("0x14 - 0x17 [4  b] from:     %d (%s)\n", $from, bin2hex(substr($packet, 20, 4)));
            $out .= sprintf("0x18 - 0x1B [4  b] fromport: %d (%s)\n", $fport, bin2hex(substr($packet, 24, 4)));
            $out .= sprintf("0x1C - 0x2B [16 b] reserved: %s\n", $res);
        }

        $payload = substr($packet, 44);
        $pOffset = 44;
        $pLen = strlen($payload);

        if ($msgId === MRIMProtocol::MRIM_CS_LOGIN2) {
            $curr = 0;
            if ($curr + 4 <= $pLen) {
                $lLen = unpack('V', substr($payload, $curr, 4))[1];
                $str = substr($payload, $curr + 4, $lLen);
                $out .= sprintf("0x%02X - 0x%02X [%d b] payload.login: LPS(len=%d, str='%s') HEX=%s\n", 
                    $pOffset + $curr, $pOffset + $curr + 4 + $lLen - 1, 4 + $lLen, $lLen, $str, bin2hex(substr($payload, $curr, 4 + $lLen)));
                $curr += 4 + $lLen;
            }
            if ($curr + 4 <= $pLen) {
                $lLen = unpack('V', substr($payload, $curr, 4))[1];
                $str = substr($payload, $curr + 4, $lLen);
                $out .= sprintf("0x%02X - 0x%02X [%d b] payload.password: LPS(len=%d, str='***') HEX=%s\n", 
                    $pOffset + $curr, $pOffset + $curr + 4 + $lLen - 1, 4 + $lLen, $lLen, bin2hex(substr($payload, $curr, 4 + $lLen)));
                $curr += 4 + $lLen;
            }
            if ($curr + 4 <= $pLen) {
                $st = unpack('V', substr($payload, $curr, 4))[1];
                $out .= sprintf("0x%02X - 0x%02X [4 b] payload.status: uint32(%d / 0x%X) HEX=%s\n", 
                    $pOffset + $curr, $pOffset + $curr + 3, $st, $st, bin2hex(substr($payload, $curr, 4)));
                $curr += 4;
            }
            if ($curr + 4 <= $pLen) {
                $lLen = unpack('V', substr($payload, $curr, 4))[1];
                $str = substr($payload, $curr + 4, $lLen);
                $out .= sprintf("0x%02X - 0x%02X [%d b] payload.client: LPS(len=%d, str='%s') HEX=%s\n", 
                    $pOffset + $curr, $pOffset + $curr + 4 + $lLen - 1, 4 + $lLen, $lLen, $str, bin2hex(substr($payload, $curr, 4 + $lLen)));
                $curr += 4 + $lLen;
            }
            if ($curr + 4 <= $pLen) {
                $fm = unpack('V', substr($payload, $curr, 4))[1];
                $out .= sprintf("0x%02X - 0x%02X [4 b] payload.feature_mask: uint32(0x%08X) HEX=%s\n", 
                    $pOffset + $curr, $pOffset + $curr + 3, $fm, bin2hex(substr($payload, $curr, 4)));
                $curr += 4;
            }
            if ($curr + 4 <= $pLen) {
                $lLen = unpack('V', substr($payload, $curr, 4))[1];
                $str = substr($payload, $curr + 4, $lLen);
                $out .= sprintf("0x%02X - 0x%02X [%d b] payload.xstatus_uri: LPS(len=%d) HEX=%s\n", 
                    $pOffset + $curr, $pOffset + $curr + 4 + $lLen - 1, 4 + $lLen, $lLen, bin2hex(substr($payload, $curr, 4 + $lLen)));
                $curr += 4 + $lLen;
            }
            if ($curr + 4 <= $pLen) {
                $lLen = unpack('V', substr($payload, $curr, 4))[1];
                $str = substr($payload, $curr + 4, $lLen);
                $out .= sprintf("0x%02X - 0x%02X [%d b] payload.xstatus_title: LPS(len=%d) HEX=%s\n", 
                    $pOffset + $curr, $pOffset + $curr + 4 + $lLen - 1, 4 + $lLen, $lLen, bin2hex(substr($payload, $curr, 4 + $lLen)));
                $curr += 4 + $lLen;
            }
            if ($curr + 4 <= $pLen) {
                $lLen = unpack('V', substr($payload, $curr, 4))[1];
                $str = substr($payload, $curr + 4, $lLen);
                $out .= sprintf("0x%02X - 0x%02X [%d b] payload.xstatus_desc: LPS(len=%d) HEX=%s\n", 
                    $pOffset + $curr, $pOffset + $curr + 4 + $lLen - 1, 4 + $lLen, $lLen, bin2hex(substr($payload, $curr, 4 + $lLen)));
                $curr += 4 + $lLen;
            }
            if ($curr + 4 <= $pLen) {
                $ufm = unpack('V', substr($payload, $curr, 4))[1];
                $out .= sprintf("0x%02X - 0x%02X [4 b] payload.user_feature_mask: uint32(0x%08X) HEX=%s\n", 
                    $pOffset + $curr, $pOffset + $curr + 3, $ufm, bin2hex(substr($payload, $curr, 4)));
                $curr += 4;
            }
            if ($curr + 4 <= $pLen) {
                $lLen = unpack('V', substr($payload, $curr, 4))[1];
                $str = substr($payload, $curr + 4, $lLen);
                $out .= sprintf("0x%02X - 0x%02X [%d b] payload.lang: LPS(len=%d, str='%s') HEX=%s\n", 
                    $pOffset + $curr, $pOffset + $curr + 4 + $lLen - 1, 4 + $lLen, $lLen, $str, bin2hex(substr($payload, $curr, 4 + $lLen)));
                $curr += 4 + $lLen;
            }
        } elseif ($msgId === MRIMProtocol::MRIM_CS_MESSAGE) {
            $curr = 0;
            if ($curr + 4 <= $pLen) {
                $flags = unpack('V', substr($payload, $curr, 4))[1];
                $out .= sprintf("0x%02X - 0x%02X [4 b] payload.flags: uint32(0x%08X) HEX=%s\n", 
                    $pOffset + $curr, $pOffset + $curr + 3, $flags, bin2hex(substr($payload, $curr, 4)));
                $curr += 4;
            }
            if ($curr + 4 <= $pLen) {
                $lLen = unpack('V', substr($payload, $curr, 4))[1];
                $str = substr($payload, $curr + 4, $lLen);
                $out .= sprintf("0x%02X - 0x%02X [%d b] payload.to: LPS(len=%d, str='%s') HEX=%s\n", 
                    $pOffset + $curr, $pOffset + $curr + 4 + $lLen - 1, 4 + $lLen, $lLen, $str, bin2hex(substr($payload, $curr, 4 + $lLen)));
                $curr += 4 + $lLen;
            }
            if ($curr + 4 <= $pLen) {
                $lLen = unpack('V', substr($payload, $curr, 4))[1];
                $strRaw = substr($payload, $curr + 4, $lLen);
                $out .= sprintf("0x%02X - 0x%02X [%d b] payload.text: LPS(len=%d, raw_hex=%s) HEX=%s\n", 
                    $pOffset + $curr, $pOffset + $curr + 4 + $lLen - 1, 4 + $lLen, $lLen, bin2hex($strRaw), bin2hex(substr($payload, $curr, 4 + $lLen)));
                $curr += 4 + $lLen;
            }
            if ($curr + 4 <= $pLen) {
                $lLen = unpack('V', substr($payload, $curr, 4))[1];
                $str = substr($payload, $curr + 4, $lLen);
                $out .= sprintf("0x%02X - 0x%02X [%d b] payload.rtf: LPS(len=%d) HEX=%s\n", 
                    $pOffset + $curr, $pOffset + $curr + 4 + $lLen - 1, 4 + $lLen, $lLen, bin2hex(substr($payload, $curr, 4 + $lLen)));
                $curr += 4 + $lLen;
            }
        } elseif ($msgId === MRIMProtocol::MRIM_CS_MESSAGE_ACK) {
            $curr = 0;
            if ($curr + 4 <= $pLen) {
                $mid = unpack('V', substr($payload, $curr, 4))[1];
                $out .= sprintf("0x%02X - 0x%02X [4 b] payload.msg_id: uint32(%d / 0x%X) HEX=%s\n", 
                    $pOffset + $curr, $pOffset + $curr + 3, $mid, $mid, bin2hex(substr($payload, $curr, 4)));
                $curr += 4;
            }
            if ($curr + 4 <= $pLen) {
                $lLen = unpack('V', substr($payload, $curr, 4))[1];
                $str = substr($payload, $curr + 4, $lLen);
                $out .= sprintf("0x%02X - 0x%02X [%d b] payload.from: LPS(len=%d, str='%s') HEX=%s\n", 
                    $pOffset + $curr, $pOffset + $curr + 4 + $lLen - 1, 4 + $lLen, $lLen, $str, bin2hex(substr($payload, $curr, 4 + $lLen)));
                $curr += 4 + $lLen;
            }
        } else {
            $out .= sprintf("0x2C - 0x%02X [%d b] raw payload: %s\n", $pOffset + $pLen - 1, $pLen, bin2hex($payload));
        }

        return $out;
    }

    /**
     * Send a low-level binary MRIM packet
     */
    private function sendPacket(int $msgId, string $data): bool
    {
        if (!$this->socket || !is_resource($this->socket)) {
            return false;
        }

        $packet = MRIMProtocol::buildPacket($msgId, $this->seq++, $data);
        $cmdName = MRIMProtocol::getCommandName($msgId);
        $this->log("Sending packet $cmdName (cmd=0x" . dechex($msgId) . ", bytes=" . strlen($packet) . ")", 'debug');

        $written = @fwrite($this->socket, $packet);

        if ($written === false || $written < strlen($packet)) {
            $this->log("Failed to write full packet to socket", 'error');
            $this->disconnect();
            $this->emit('disconnected', ['reason' => 'Socket write failure']);
            return false;
        }

        return true;
    }

    /**
     * Read available bytes from TCP stream and parse packets
     */
    public function readLoopStep(): void
    {
        if (!$this->socket || !is_resource($this->socket)) {
            return;
        }

        $chunk = @fread($this->socket, 8192);
        if ($chunk === false || ($chunk === '' && feof($this->socket))) {
            $this->log("Server closed connection", 'warning');
            $this->disconnect();
            $this->emit('disconnected', ['reason' => 'Server closed connection']);
            return;
        }

        if ($chunk !== '') {
            $this->readBuffer .= $chunk;
        }

        // Process buffered complete packets
        while (strlen($this->readBuffer) >= 44) {
            $headerBytes = substr($this->readBuffer, 0, 44);
            $header = MRIMProtocol::parseHeader($headerBytes);

            if (!$header['valid']) {
                $this->log("Received invalid packet magic signature. Resynchronizing...", 'error');
                // Skip 1 byte and try to find magic header again
                $this->readBuffer = substr($this->readBuffer, 1);
                continue;
            }

            $dlen = $header['dlen'];
            if (strlen($this->readBuffer) < 44 + $dlen) {
                // Not enough bytes for full data body yet
                break;
            }

            $dataBody = substr($this->readBuffer, 44, $dlen);
            $this->readBuffer = substr($this->readBuffer, 44 + $dlen);

            $this->parsePacket($header, $dataBody);
        }
    }

    /**
     * Handle parsed MRIM command packets
     */
    /**
     * Get authenticated email address
     */
    public function getEmail(): string
    {
        return $this->email;
    }

    /**
     * Handle parsed MRIM command packets
     */
    private function parsePacket(array $header, string $data): void
    {
        $cmd = $header['msg'];
        $cmdName = MRIMProtocol::getCommandName($cmd);
        $dlen = strlen($data);
        $rawHex = bin2hex($data);
        $this->log("Received packet: $cmdName (cmd=0x" . dechex($cmd) . ", dlen=$dlen) RAW_HEX=$rawHex", 'debug');

        switch ($cmd) {
            case MRIMProtocol::MRIM_CS_HELLO_ACK:
                // Server acknowledged handshake, byte 0-3 contains ping period
                if (strlen($data) >= 4) {
                    $this->pingPeriod = max(10, MRIMProtocol::decodeUint32($data, 0));
                    $this->log("Handshake OK. Server ping period: {$this->pingPeriod}s");
                }
                $this->login();
                break;

            case MRIMProtocol::MRIM_CS_LOGIN_ACK:
                // Authentication successful
                $this->state = 'authenticated';
                $this->log("Login successful as {$this->email}!");
                $this->emit('login_success', ['email' => $this->email]);
                break;

            case MRIMProtocol::MRIM_CS_LOGIN_REJ:
                // Authentication failed
                $reason = 'Unknown reason';
                if (strlen($data) >= 4) {
                    $len = MRIMProtocol::decodeUint32($data, 0);
                    if (strlen($data) >= 4 + $len) {
                        $reason = MRIMProtocol::ensureUtf8(substr($data, 4, $len));
                    }
                }
                $this->log("Login rejected: $reason", 'error');
                $this->emit('login_error', ['reason' => $reason]);
                $this->disconnect();
                break;

            case MRIMProtocol::MRIM_CS_CONTACT_LIST2:
                $this->parseContactList($data);
                break;

            case MRIMProtocol::MRIM_CS_USER_STATUS:
            case 0x1022: // MRIM_CS_STATUS_CHANGED / MRIM_CS_CONTACT_STATUS
                $this->parseUserStatus($data, $cmd, $cmdName);
                break;

            case MRIMProtocol::MRIM_CS_USER_INFO:
                $rawNew = '';
                $rawOld = '';
                $dataLen = strlen($data);
                if ($dataLen >= 4) {
                    $tmpOffset = 4;
                    $emailRes = MRIMProtocol::decodeLPS($data, $tmpOffset);
                    $tmpOffset = $emailRes['next_offset'];
                    $uriRes = MRIMProtocol::decodeLPS($data, $tmpOffset);
                    $tmpOffset = $uriRes['next_offset'];
                    $titleRes = MRIMProtocol::decodeLPS($data, $tmpOffset);
                    $tmpOffset = $titleRes['next_offset'];
                    $descRes = MRIMProtocol::decodeLPS($data, $tmpOffset);
                    $tmpOffset = $descRes['next_offset'];
                    if ($tmpOffset + 8 <= $dataLen) {
                        $tmpOffset += 8;
                        if ($tmpOffset < $dataLen) {
                            $rawNewRes = MRIMProtocol::decodeLPS($data, $tmpOffset);
                            $rawNew = $rawNewRes['value'];
                            $tmpOffset = $rawNewRes['next_offset'];
                            if ($tmpOffset < $dataLen) {
                                $rawOldRes = MRIMProtocol::decodeLPS($data, $tmpOffset);
                                $rawOld = $rawOldRes['value'];
                            }
                        }
                    }
                }
                $this->log(sprintf(
                    "USERAGENT DEBUG:\npacket=%s\nraw_new=%s\nraw_old=%s",
                    $cmdName,
                    $rawNew,
                    $rawOld
                ), 'info');
                $this->log(sprintf(
                    "CAPABILITY RECEIVE DEBUG:\npacket=%s\nemail=N/A\noffset=0\nraw_bytes=%s\ndecoded_feature_mask=0x00000000",
                    $cmdName,
                    bin2hex(substr($data, 0, 64))
                ), 'debug');
                $this->log(sprintf(
                    "USER_INFO PACKET DEBUG:\ncmd=0x%04X (%s)\nlength=%d\nignored=true",
                    $cmd,
                    $cmdName,
                    strlen($data)
                ), 'debug');
                $this->log("Received MRIM_CS_USER_INFO (0x1015), skipped status parsing", 'debug');
                break;

            case MRIMProtocol::MRIM_CS_MESSAGE_RECV2:
                $this->handleIncomingMessage($data);
                break;

            case MRIMProtocol::MRIM_CS_MESSAGE_RECV3:
                $this->handleIncomingMessage1063($data);
                break;

            case MRIMProtocol::MRIM_CS_MESSAGE_RECV:
            case MRIMProtocol::MRIM_CS_MESSAGE:
                $this->parseIncomingMessage($data);
                break;

            case MRIMProtocol::MRIM_CS_MESSAGE_ACK:
                $this->parseMessageAck($data);
                break;

            case MRIMProtocol::MRIM_CS_MESSAGE_STATUS:
                if (strlen($data) >= 4) {
                    $status = MRIMProtocol::decodeUint32($data, 0);
                    $this->log("MESSAGE STATUS = " . $status . " (0x" . dechex($status) . ")", 'debug');

                    // In MRIM protocol:
                    // 0x8000 bit mask (e.g. 0x8001 / 32769, 0x8000) or status 0, 2 indicate successful delivery / offline queueing
                    $isSuccess = (($status & 0x8000) !== 0) || $status === 0 || $status === 2;

                    if ($isSuccess) {
                        $this->log("Сообщение успешно доставлено на сервер (код 0x" . dechex($status) . ")", 'info');
                        $this->emit('message_delivery_status', [
                            'success' => true, 
                            'code' => $status, 
                            'text' => 'Доставлено'
                        ]);
                    } else {
                        // Pure 1 (0x0001) or 3 (0x0003) means rejection or not found
                        $this->log("Сообщение НЕ доставлено (код ошибки 0x" . dechex($status) . ")", 'error');
                        $this->emit('message_delivery_status', [
                            'success' => false, 
                            'code' => $status, 
                            'text' => 'Отклонено сервером',
                            'need_authorize' => true
                        ]);
                    }
                }
                break;

            case MRIMProtocol::MRIM_CS_LOGOUT:
                $this->log("Server sent MRIM_CS_LOGOUT");
                $this->emit('logout', ['reason' => 'Logged out by server']);
                $this->disconnect();
                break;

            default:
                $this->log("UNKNOWN PACKET CMD=0x" . dechex($cmd) . " RAW=" . bin2hex(substr($data, 0, 64)), 'debug');
                break;
        }
    }

    /**
     * Parse MRIM_CS_CONTACT_LIST2 payload (0x1037)
     */
    private function parseContactList(string $data): void
    {
        $this->log(sprintf(
            "CLIENT OBJECT DEBUG:\nobject_id=%d\nfunction=parseContactList",
            spl_object_id($this)
        ), 'debug');

        $offset = 0;
        $dataLen = strlen($data);

        if ($dataLen < 8) {
            $this->log("Invalid CONTACT_LIST2 length: $dataLen", 'error');
            return;
        }

        // status (uint32)
        $status = MRIMProtocol::decodeUint32($data, $offset);
        $offset += 4;

        // groups count (uint32)
        $groupsCount = MRIMProtocol::decodeUint32($data, $offset);
        $offset += 4;

        // groups mask (LPS string)
        $groupsMaskRes = MRIMProtocol::decodeLPS($data, $offset);
        $offset = $groupsMaskRes['next_offset'];
        $groupsMask = $groupsMaskRes['value'];

        // contacts mask (LPS string)
        $contactsMaskRes = MRIMProtocol::decodeLPS($data, $offset);
        $offset = $contactsMaskRes['next_offset'];
        $contactsMask = $contactsMaskRes['value'];

        $this->log("CONTACT_LIST2 received -> status=$status, groupsCount=$groupsCount, groupsMask='$groupsMask', contactsMask='$contactsMask'", 'debug');

        $groups = [];

        // Parse group records
        for ($g = 0; $g < $groupsCount && $offset < $dataLen; $g++) {
            $gFlags = 0;
            $gName = 'Group ' . ($g + 1);

            if (!empty($groupsMask)) {
                $maskChars = str_split($groupsMask);
                foreach ($maskChars as $ch) {
                    if ($offset >= $dataLen) break;
                    if ($ch === 'u') {
                        $val = MRIMProtocol::decodeUint32($data, $offset);
                        $offset += 4;
                        if ($gFlags === 0) $gFlags = $val;
                    } elseif ($ch === 's' || $ch === 'S') {
                        $strRes = MRIMProtocol::decodeLPS($data, $offset, ($ch === 'S'));
                        $offset = $strRes['next_offset'];
                        if ($strRes['value'] !== '') $gName = $strRes['value'];
                    }
                }
            } else {
                $gFlags = MRIMProtocol::decodeUint32($data, $offset);
                $offset += 4;
                $gNameRes = MRIMProtocol::decodeLPS($data, $offset);
                $offset = $gNameRes['next_offset'];
                $gName = $gNameRes['value'];
            }

            $groups[$g + 1] = $gName;
        }

        $contacts = [];
        $contactIndex = 0;

        // Parse contact records until data end
        while ($offset + 4 <= $dataLen) {
            $recordStartOffset = $offset;
            $uVals = [];
            $sVals = [];

            if (!empty($contactsMask)) {
                $maskChars = str_split($contactsMask);
                foreach ($maskChars as $ch) {
                    if ($offset >= $dataLen) break;
                    if ($ch === 'u') {
                        $uVals[] = MRIMProtocol::decodeUint32($data, $offset);
                        $offset += 4;
                    } elseif ($ch === 's' || $ch === 'S') {
                        $strRes = MRIMProtocol::decodeLPS($data, $offset, ($ch === 'S'));
                        $offset = $strRes['next_offset'];
                        $sVals[] = $strRes['value'];
                    }
                }
            } else {
                $uVals[] = MRIMProtocol::decodeUint32($data, $offset);
                $offset += 4;
                $uVals[] = MRIMProtocol::decodeUint32($data, $offset);
                $offset += 4;

                $emailRes = MRIMProtocol::decodeLPS($data, $offset);
                $offset = $emailRes['next_offset'];
                $sVals[] = $emailRes['value'];

                $nickRes = MRIMProtocol::decodeLPSUtf16($data, $offset);
                $offset = $nickRes['next_offset'];
                $sVals[] = $nickRes['value'];
            }

            $recordRawBytes = substr($data, $recordStartOffset, $offset - $recordStartOffset);
            $recordRawHex = bin2hex($recordRawBytes);

            $flags    = $uVals[0] ?? 0;
            $groupId  = $uVals[1] ?? 0;

            // In MRIM protocol contacts_mask:
            // Field u#0 = flags
            // Field u#1 = group_id
            // Field u#2 = user_status
            // Field u#3 = xstatus_id / server_status (NOT client feature_mask)
            $statusVal = 0;
            if (count($uVals) >= 3) {
                $statusVal = $uVals[2];
                if ($statusVal === 0 && isset($uVals[3])) {
                    $statusVal = $uVals[3];
                }
            }

            $emailStr  = $sVals[0] ?? '';
            $nickStr   = $sVals[1] ?? '';
            $phonesStr = $sVals[2] ?? '';

            $emailClean = strtolower(trim($emailStr));
            if ($emailClean === '') {
                continue;
            }

            // Skip removed contacts (CONTACT_FLAG_REMOVED = 0x0001) or group markers (CONTACT_FLAG_GROUP = 0x0002)
            if (($flags & 0x0001) !== 0 || ($flags & 0x0002) !== 0) {
                continue;
            }

            $nickClean = trim($nickStr);
            if ($nickClean === '') {
                $nickClean = $emailClean;
            }

            $isOnlineStr = ($statusVal > 0) ? "YES" : "NO";
            $contactIndex++;

            $oldFeatureMask = $this->contacts[$emailClean]['feature_mask'] ?? null;
            $oldValStr = is_int($oldFeatureMask) ? sprintf('0x%08X', $oldFeatureMask) : 'NULL';
            $oldContactObj = $this->contacts[$emailClean] ?? null;

            // CONTACT_LIST2 uVals[4] contains contact's feature_mask capabilities
            $newFeatureMaskFromPacket = $uVals[4] ?? 0;
            $appliedFeatureMask = $this->validateAndApplyFeatureMask($emailClean, $newFeatureMaskFromPacket, 'parseContactList (CONTACT_LIST2)');

            // Preserve old feature_mask if old_feature_mask != null and new_feature_mask == 0
            if ($oldFeatureMask !== null && $appliedFeatureMask === 0) {
                $finalFeatureMask = $oldFeatureMask;
            } else {
                $finalFeatureMask = $appliedFeatureMask;
            }

            $this->log(sprintf(
                "CAPABILITY RECEIVE DEBUG:\npacket=%s\nemail=%s\noffset=N/A\nraw_bytes=%s\ndecoded_feature_mask=0x%08X",
                'MRIM_CS_CONTACT_LIST2',
                $emailClean,
                $recordRawHex,
                $newFeatureMaskFromPacket
            ), 'debug');

            $this->log(sprintf(
                "CONTACT FEATURE UPDATE:\nemail=%s\nold_feature_mask=%s\nreceived_feature_mask=0x%08X\nfinal_feature_mask=0x%08X\nsource_packet=%s",
                $emailClean,
                $oldValStr,
                $newFeatureMaskFromPacket,
                $finalFeatureMask,
                'MRIM_CS_CONTACT_LIST2'
            ), 'debug');

            $this->log(sprintf(
                "CONTACT MASK FLOW:\nemail=%s\nsource_packet=%s\nold_mask=%s\nreceived_mask=0x%08X\nsaved_mask=0x%08X",
                $emailClean,
                'MRIM_CS_CONTACT_LIST2',
                $oldValStr,
                $newFeatureMaskFromPacket,
                $finalFeatureMask
            ), 'debug');

            $this->log(sprintf(
                "CONTACT LIST REPLACE DEBUG:\nemail=%s\nold_feature_mask=%s\nnew_feature_mask=0x%08X\nfinal_feature_mask=0x%08X",
                $emailClean,
                $oldValStr,
                $newFeatureMaskFromPacket,
                $finalFeatureMask
            ), 'debug');

            $newContactObj = [
                'email'        => $emailClean,
                'nickname'     => $nickClean,
                'status'       => $statusVal,
                'status_title' => '',
                'status_desc'  => '',
                'feature_mask' => $finalFeatureMask,
                'group_id'     => $groupId,
                'group_name'   => $groups[$groupId] ?? 'General',
                'phones'       => $phonesStr,
                'unread'       => 0,
            ];

            $this->log(sprintf(
                "CONTACT WRITE TRACE:\nfunction=%s\nemail=%s\nold_feature_mask=%s\nnew_feature_mask=0x%08X\nfull_old_object=%s\nfull_new_object=%s",
                'parseContactList',
                $emailClean,
                $oldValStr,
                $finalFeatureMask,
                json_encode($oldContactObj),
                json_encode($newContactObj)
            ), 'debug');

            $this->log(sprintf(
                "CAPABILITY RAW DEBUG:\npacket=%s\nemail=%s\nraw_hex=%s\ndecoded_fields=%s\npossible_feature_mask=0x%08X",
                'MRIM_CS_CONTACT_LIST2',
                $emailClean,
                $recordRawHex,
                json_encode(['uVals' => $uVals, 'sVals' => $sVals]),
                0
            ), 'debug');

            $this->log(sprintf(
                "CONTACT STATE TRACE:\npacket=%s\nfunction=%s\nemail=%s\nfeature_mask=0x%08X\nfull_contact=%s",
                'MRIM_CS_CONTACT_LIST2',
                'parseContactList',
                $emailClean,
                $finalFeatureMask,
                json_encode($newContactObj)
            ), 'debug');

            $this->log(sprintf(
                "CONTACT_LIST2 RAW RECORD DEBUG (#%d):\nemail=%s\nraw_hex=%s\nuVals=%s\nsVals=%s",
                $contactIndex,
                $emailClean,
                $recordRawHex,
                json_encode($uVals),
                json_encode($sVals)
            ), 'debug');

            $this->log("CONTACT #$contactIndex -> UID: $emailClean | EMAIL: $emailClean | NICK: $nickClean | STATUS: $statusVal (0x" . dechex($statusVal) . ") | FLAGS: $flags (0x" . dechex($flags) . ") | ONLINE: $isOnlineStr", 'info');
            $this->log(sprintf(
                "CONTACT_LIST2 FEATURE:\nemail=%s\nfeature_mask=0x%08X\nraw_hex=%s",
                $emailClean,
                $finalFeatureMask,
                bin2hex(pack('V', $finalFeatureMask))
            ), 'debug');

            $contacts[$emailClean] = $newContactObj;
        }

        $this->contacts = $contacts;
        $this->log("Loaded contact list with " . count($this->contacts) . " active contacts", 'info');
        $this->emit('contact_list', ['contacts' => array_values($this->contacts)]);
    }

    /**
     * Parse MRIM_CS_USER_STATUS payload
     */
    private function parseUserStatus(string $data, int $cmd = 0x100F, string $cmdName = 'MRIM_CS_USER_STATUS'): void
    {
        $this->log(sprintf(
            "CLIENT OBJECT DEBUG:\nobject_id=%d\nfunction=parseUserStatus\ncmd=0x%04X (%s)",
            spl_object_id($this),
            $cmd,
            $cmdName
        ), 'debug');

        $offset = 0;
        $dataLen = strlen($data);

        if ($dataLen < 8) {
            return;
        }

        $statusVal = MRIMProtocol::decodeUint32($data, $offset);
        $offset += 4;

        // 1. user_email
        $userEmailRes = MRIMProtocol::decodeLPS($data, $offset);
        $offset = $userEmailRes['next_offset'];

        // 2. xstatus_uri
        $xstatusUriRes = MRIMProtocol::decodeLPS($data, $offset);
        $offset = $xstatusUriRes['next_offset'];

        // 3. xstatus_title
        $statusTitleRes = MRIMProtocol::decodeLPS($data, $offset);
        $offset = $statusTitleRes['next_offset'];

        // 4. xstatus_desc
        $statusDescRes = MRIMProtocol::decodeLPS($data, $offset);
        $offset = $statusDescRes['next_offset'];

        $offsetBeforeFeatureMask = $offset;
        $rawBytesNext8 = ($offset + 8 <= $dataLen) ? substr($data, $offset, 8) : substr($data, $offset);
        $rawBytesNext8Hex = bin2hex($rawBytesNext8);

        $featureMaskVal = 0;
        if ($offset + 4 <= $dataLen) {
            $featureMaskVal = MRIMProtocol::decodeUint32($data, $offset);
            $offset += 4;
        }

        $userFeatureMaskVal = 0;
        if ($offset + 4 <= $dataLen) {
            $userFeatureMaskVal = MRIMProtocol::decodeUint32($data, $offset);
            $offset += 4;
        }

        $rawNew = '';
        $rawOld = '';
        if ($offset < $dataLen) {
            $rawNewRes = MRIMProtocol::decodeLPS($data, $offset);
            $rawNew = $rawNewRes['value'];
            $offset = $rawNewRes['next_offset'];
            if ($offset < $dataLen) {
                $rawOldRes = MRIMProtocol::decodeLPS($data, $offset);
                $rawOld = $rawOldRes['value'];
            }
        }

        $this->log(sprintf(
            "USERAGENT DEBUG:\npacket=%s\nraw_new=%s\nraw_old=%s",
            $cmdName,
            $rawNew,
            $rawOld
        ), 'info');

        $emailClean = strtolower(trim($userEmailRes['value']));
        if ($emailClean === '') {
            return;
        }

        $this->log(sprintf(
            "USER STATUS CAPABILITY TRACE:\nemail=%s\npacket=%s\noffset_before_feature_mask=%d\nraw_feature_bytes=%s\ndecoded_feature_mask=0x%08X\ndecoded_user_feature_mask=0x%08X",
            $emailClean,
            $cmdName,
            $offsetBeforeFeatureMask,
            $rawBytesNext8Hex,
            $featureMaskVal,
            $userFeatureMaskVal
        ), 'debug');

        $this->log(sprintf(
            "CAPABILITY RECEIVE DEBUG:\npacket=%s\nemail=%s\noffset=%d\nraw_bytes=%s\ndecoded_feature_mask=0x%08X",
            $cmdName,
            $emailClean,
            $offsetBeforeFeatureMask,
            $rawBytesNext8Hex,
            $featureMaskVal
        ), 'debug');

        $oldFeatureMask = $this->contacts[$emailClean]['feature_mask'] ?? null;
        $oldValStr = is_int($oldFeatureMask) ? sprintf('0x%08X', $oldFeatureMask) : 'NULL';
        $oldObj = $this->contacts[$emailClean] ?? null;

        $newFeatureMask = $this->validateAndApplyFeatureMask($emailClean, $featureMaskVal, "parseUserStatus ($cmdName)");

        $this->log(sprintf(
            "CONTACT FEATURE UPDATE:\nemail=%s\nold_feature_mask=%s\nreceived_feature_mask=0x%08X\nfinal_feature_mask=0x%08X\nsource_packet=%s",
            $emailClean,
            $oldValStr,
            $featureMaskVal,
            $newFeatureMask,
            $cmdName
        ), 'debug');

        $this->log(sprintf(
            "CONTACT MASK FLOW:\nemail=%s\nsource_packet=%s\nold_mask=%s\nreceived_mask=0x%08X\nsaved_mask=0x%08X",
            $emailClean,
            $cmdName,
            $oldValStr,
            $featureMaskVal,
            $newFeatureMask
        ), 'debug');

        if (!isset($this->contacts[$emailClean])) {
            $this->contacts[$emailClean] = [
                'email'        => $emailClean,
                'nickname'     => $emailClean,
                'status'       => $statusVal,
                'status_title' => $statusTitleRes['value'],
                'status_desc'  => $statusDescRes['value'],
                'feature_mask' => $newFeatureMask,
                'group_id'     => 0,
                'group_name'   => 'General',
                'phones'       => '',
                'unread'       => 0,
            ];
        } else {
            $this->contacts[$emailClean]['status'] = $statusVal;
            $this->contacts[$emailClean]['status_title'] = $statusTitleRes['value'];
            $this->contacts[$emailClean]['status_desc'] = $statusDescRes['value'];
            $this->contacts[$emailClean]['feature_mask'] = $newFeatureMask;
        }

        $newObj = $this->contacts[$emailClean];

        $this->log(sprintf(
            "CONTACT WRITE TRACE:\nfunction=%s\nemail=%s\nold_feature_mask=%s\nnew_feature_mask=0x%08X\nfull_old_object=%s\nfull_new_object=%s",
            'parseUserStatus',
            $emailClean,
            $oldValStr,
            $newFeatureMask,
            json_encode($oldObj),
            json_encode($newObj)
        ), 'debug');

        $this->log(sprintf(
            "CAPABILITY RAW DEBUG:\npacket=%s\nemail=%s\nraw_hex=%s\ndecoded_fields=%s\npossible_feature_mask=0x%08X",
            $cmdName,
            $emailClean,
            bin2hex($data),
            json_encode([
                'status'            => $statusVal,
                'email'             => $emailClean,
                'xstatus_uri'       => $xstatusUriRes['value'],
                'xstatus_title'     => $statusTitleRes['value'],
                'xstatus_desc'      => $statusDescRes['value'],
                'feature_mask'      => $featureMaskVal,
                'user_feature_mask' => $userFeatureMaskVal,
            ]),
            $featureMaskVal
        ), 'debug');

        $this->log(sprintf(
            "CONTACT STATE TRACE:\npacket=%s\nfunction=%s\nemail=%s\nfeature_mask=0x%08X\nfull_contact=%s",
            $cmdName,
            'parseUserStatus',
            $emailClean,
            $newFeatureMask,
            json_encode($newObj)
        ), 'debug');

        $isOnlineStr = ($statusVal > 0) ? "YES" : "NO";
        $this->log("STATUS UPDATE -> EMAIL: $emailClean | STATUS: $statusVal (0x" . dechex($statusVal) . ") | FEATURE_MASK: 0x" . sprintf('%08X', $newFeatureMask) . " | ONLINE: $isOnlineStr", 'info');
        $this->log(sprintf(
            "USER_STATUS FEATURE:\nemail=%s\nxstatus_uri=%s\nxstatus_title=%s\nxstatus_desc=%s\nfeature_mask=0x%08X\nraw_hex=%s",
            $emailClean,
            $xstatusUriRes['value'],
            $statusTitleRes['value'],
            $statusDescRes['value'],
            $newFeatureMask,
            bin2hex(pack('V', $newFeatureMask))
        ), 'debug');

        $this->emit('user_status', [
            'email'        => $emailClean,
            'status'       => $statusVal,
            'status_title' => $statusTitleRes['value'],
            'status_desc'  => $statusDescRes['value'],
        ]);
    }

    /**
     * Validate and update contact feature_mask safely
     */
    private function validateAndApplyFeatureMask(string $emailClean, int $receivedMask, string $source): int
    {
        $oldMask = $this->contacts[$emailClean]['feature_mask'] ?? 0;

        $action = 'IGNORE';
        $reason = '';
        $finalMask = $oldMask;

        if ($receivedMask === 0) {
            $action = 'IGNORE';
            $reason = 'Received feature_mask is 0';
        } elseif ($receivedMask === 0x00000001) {
            $action = 'IGNORE';
            $reason = 'Received mask 0x00000001 is online status, not feature capabilities';
        } elseif (($receivedMask & MRIMWakeUp::FEATURE_FLAG_WAKEUP) !== 0) {
            $action = 'ACCEPT';
            $reason = 'Contains FEATURE_FLAG_WAKEUP capability (0x00000010)';
            $finalMask = $receivedMask;
        } elseif (($receivedMask & 0xFFFFFFF0) !== 0) {
            $action = 'ACCEPT';
            $reason = 'Contains valid capability bits';
            $finalMask = $receivedMask;
        } else {
            if ($oldMask !== 0) {
                $action = 'IGNORE';
                $reason = 'Received mask lacks valid capability flags; preserving existing mask';
            } else {
                $action = 'ACCEPT';
                $reason = 'Setting initial non-zero feature mask';
                $finalMask = $receivedMask;
            }
        }

        $this->log(sprintf(
            "FEATURE_MASK VALIDATION:\nemail=%s\nold_mask=0x%08X\nreceived_mask=0x%08X\naction=%s\nreason=%s",
            $emailClean,
            $oldMask,
            $receivedMask,
            $action,
            $reason
        ), 'debug');

        $this->log(sprintf(
            "FEATURE MASK TRACE:\nsource=%s\nemail=%s\nold=0x%08X\nnew=0x%08X",
            $source,
            $emailClean,
            $oldMask,
            $finalMask
        ), 'debug');

        return $finalMask;
    }

    /**
     * Parse binary payload of authorization request or system message
     */
    private function parseAuthBinaryPayload(string $data): ?array
    {
        if (strlen($data) < 8) {
            return null;
        }
        $offset = 0;
        $type = MRIMProtocol::decodeUint32($data, $offset);
        if ($type !== 2 && $type !== 1) {
            return null;
        }
        $offset += 4;

        $nickLen = MRIMProtocol::decodeUint32($data, $offset);
        $offset += 4;

        $senderNick = '';
        if ($nickLen > 0 && $offset + $nickLen <= strlen($data)) {
            $senderNickRaw = substr($data, $offset, $nickLen);
            $senderNick = MRIMProtocol::decodeUtf16String($senderNickRaw);
            $offset += $nickLen;
        }

        $text = 'Пожалуйста, добавьте меня в список контактов.';
        if ($offset + 8 <= strlen($data)) {
            $flags = MRIMProtocol::decodeUint32($data, $offset);
            $offset += 4;
            $textLen = MRIMProtocol::decodeUint32($data, $offset);
            $offset += 4;

            if ($textLen > 0 && $offset + $textLen <= strlen($data)) {
                $textRaw = substr($data, $offset, $textLen);
                $decodedText = MRIMProtocol::decodeUtf16String($textRaw);
                if ($decodedText !== '') {
                    $text = $decodedText;
                }
            }
        }

        return [
            'sender_nick' => $senderNick,
            'text'        => $text,
        ];
    }

    /**
     * Helper to decode and handle message body, including authorization payloads
     */
    private function processExtractedMessageText(string $rawText, string $fromEmail, int $flags = 0): array
    {
        $text = MRIMProtocol::ensureUtf8(trim($rawText));
        $isAuthRequest = (($flags & MRIMProtocol::MESSAGE_FLAG_AUTHORIZE) !== 0);
        $senderNick = '';

        // Check if text is base64 encoded binary authorization structure (starts with AgAA... or similar)
        if (strpos($text, 'AgAA') === 0 || preg_match('/^Ag[A-Za-z0-9+\/]{10,}={0,2}$/', $text)) {
            $binary = base64_decode($text);
            if ($binary !== false && strlen($binary) >= 8) {
                $authParsed = $this->parseAuthBinaryPayload($binary);
                if ($authParsed !== null) {
                    $isAuthRequest = true;
                    $senderNick = $authParsed['sender_nick'] ?? '';
                    $text = $authParsed['text'] ?? 'Запрос авторизации';
                }
            }
        }

        // Check if raw text itself is binary auth payload without base64 wrapper
        if (strlen($text) >= 8 && ord($text[0]) === 2 && ord($text[1]) === 0 && ord($text[2]) === 0 && ord($text[3]) === 0) {
            $authParsed = $this->parseAuthBinaryPayload($text);
            if ($authParsed !== null) {
                $isAuthRequest = true;
                $senderNick = $authParsed['sender_nick'] ?? '';
                $text = $authParsed['text'] ?? 'Запрос авторизации';
            }
        }

        return [
            'text'            => $text,
            'is_auth_request' => $isAuthRequest,
            'sender_nick'     => $senderNick,
        ];
    }

    /**
     * Parse incoming MRIM_CS_MESSAGE_RECV packet (0x1011)
     */
    private function parseIncomingMessage(string $data): void
    {
        $offset = 0;

        $msgId = MRIMProtocol::decodeUint32($data, $offset);
        $offset += 4;

        $flags = MRIMProtocol::decodeUint32($data, $offset);
        $offset += 4;

        $fromRes = MRIMProtocol::decodeLPS($data, $offset);
        $offset = $fromRes['next_offset'];
        $fromEmail = strtolower(trim($fromRes['value']));

        $isUtf16 = (($flags & 0x100000) !== 0) || (($flags & MRIMProtocol::MESSAGE_FLAG_UTF16) !== 0);

        $startTextOffset = $offset;
        $textRes = MRIMProtocol::decodeLPS($data, $offset, $isUtf16);
        $offset = $textRes['next_offset'];
        $msgText = trim($textRes['value']);

        $dbg = $textRes['debug'] ?? [];
        $this->log("LPS DEBUG [0x1011 MESSAGE]: len={$dbg['len']} B | enc={$dbg['enc']} | hex={$dbg['hex']} | result='$msgText'", 'debug');

        if ($msgText === '') {
            $textRes2 = MRIMProtocol::decodeLPS($data, $startTextOffset, !$isUtf16);
            $msgText = trim($textRes2['value']);
            if ($msgText !== '') {
                $offset = $textRes2['next_offset'];
            }
        }

        $isWakeUp = MRIMWakeUp::isWakeUpMessage($flags, $msgText);
        $isNotify = (($flags & MRIMProtocol::MESSAGE_FLAG_NOTIFY) !== 0) && ($msgText === '1' || $msgText === '0');

        $this->log(sprintf(
            "MESSAGE RECV FLAGS DEBUG:\nfrom=%s\nflags=0x%08X\nis_wakeup=%s\ntext=%s",
            $fromEmail,
            $flags,
            $isWakeUp ? 'true' : 'false',
            $msgText
        ), 'debug');

        $this->log(sprintf("INCOMING MESSAGE EVALUATION:\nFLAGS = 0x%08X\nTEXT = '%s'\nIS_WAKEUP = %s\nIS_NOTIFY = %s", 
            $flags, $msgText, $isWakeUp ? 'YES' : 'NO', $isNotify ? 'YES' : 'NO'), 'debug');

        if ($isNotify) {
            $this->log("Пользователь $fromEmail набирает сообщение (typing=" . ($msgText === '1' ? 'start' : 'stop') . ")...", 'debug');
            $this->emit('typing_notification', ['from' => $fromEmail, 'typing' => ($msgText === '1')]);
            return;
        }

        if ($msgText === '' && !$isWakeUp) {
            return;
        }

        $proc = $this->processExtractedMessageText($msgText, $fromEmail, $flags);
        $cleanText = $proc['text'];
        $isAuthReq = $proc['is_auth_request'];

        $this->log("PARSED INCOMING MESSAGE_RECV (0x1011) from $fromEmail (id=$msgId): $cleanText", 'info');

        if ($isWakeUp) {
            $this->log("Получен БУДИЛЬНИК (WakeUp) от $fromEmail!", 'warning');
            $wakeUpData = MRIMWakeUp::processIncomingWakeUp($fromEmail, $cleanText, $flags)['data'];
            $this->emit('wakeup', $wakeUpData);
            $this->sendMessageAck($msgId, $fromEmail);
            return;
        }

        if ($isAuthReq) {
            $this->log("Получен запрос авторизации от $fromEmail!", 'info');
            $this->emit('authorize_request', [
                'from' => $fromEmail,
                'text' => $cleanText,
                'nick' => $proc['sender_nick'],
            ]);
        }

        $this->emit('message', [
            'from'            => $fromEmail,
            'text'            => $cleanText,
            'timestamp'       => time(),
            'is_auth_request' => $isAuthReq,
            'sender_nick'     => $proc['sender_nick'],
        ]);

        $this->sendMessageAck($msgId, $fromEmail);
    }

    /**
     * Send message delivery acknowledgment (MRIM_CS_MESSAGE_ACK = 0x1009)
     */
    private function sendMessageAck(int $msgId, string $fromEmail = ''): void
    {
        $payload = MRIMProtocol::encodeUint32($msgId) . MRIMProtocol::encodeLPS($fromEmail);
        $this->sendPacket(MRIMProtocol::MRIM_CS_MESSAGE_ACK, $payload);
        $this->log("Sent delivery ACK for message ID $msgId to $fromEmail", 'debug');
    }

    /**
     * Handle MRIM_CS_MESSAGE_RECV2 (0x101D)
     */
    private function handleIncomingMessage(string $data): void
    {
        // 1. Locate MIME content in $data (find where headers start)
        $mimePos = false;
        foreach (['From:', 'Content-Type:', 'MIME-Version:', 'X-MRIM-'] as $needle) {
            $p = stripos($data, $needle);
            if ($p !== false && ($mimePos === false || $p < $mimePos)) {
                $mimePos = $p;
            }
        }

        $mimeData = ($mimePos !== false) ? substr($data, $mimePos) : $data;

        // Split MIME into headers and body
        $parts = preg_split('/\r?\n\r?\n/', $mimeData, 2);
        $headersRaw = $parts[0] ?? '';
        $bodyRaw = isset($parts[1]) ? trim($parts[1]) : '';

        // 2. Parse MIME headers
        $headers = [];
        $headerLines = preg_split('/\r?\n/', $headersRaw);
        foreach ($headerLines as $line) {
            if (strpos($line, ':') !== false) {
                list($key, $value) = explode(':', $line, 2);
                $headers[strtolower(trim($key))] = trim($value);
            }
        }

        // Extract From email
        $fromEmail = '';
        if (isset($headers['from'])) {
            if (preg_match('/[a-zA-Z0-9._%+-]+@[a-zA-Z0-9.-]+\.[a-zA-Z]{2,}/', $headers['from'], $matches)) {
                $fromEmail = strtolower($matches[0]);
            }
        }
        if ($fromEmail === '') {
            if (preg_match('/[a-zA-Z0-9._%+-]+@[a-zA-Z0-9.-]+\.[a-zA-Z]{2,}/', $data, $matches)) {
                $fromEmail = strtolower($matches[0]);
            }
        }

        // Extract Msg ID
        $msgId = 0;
        if (isset($headers['x-mrim-msg-id'])) {
            $msgId = (int)$headers['x-mrim-msg-id'];
        } elseif (isset($headers['msg-id'])) {
            $msgId = (int)$headers['msg-id'];
        }

        // Extract Flags
        $flags = 0;
        if (isset($headers['x-mrim-flags'])) {
            $flagsRaw = $headers['x-mrim-flags'];
            if (strpos($flagsRaw, '0x') === 0) {
                $flags = (int)hexdec($flagsRaw);
            } else {
                $flags = (int)$flagsRaw;
            }
        }

        // 3. Extract Content-Type, charset, Content-Transfer-Encoding
        $contentType = $headers['content-type'] ?? '';
        $charset = 'UTF-8';
        if (preg_match('/charset=["\']?([^"\';\s]+)["\']?/i', $contentType, $csMatches)) {
            $charset = trim($csMatches[1]);
        }

        $transferEncoding = strtolower($headers['content-transfer-encoding'] ?? '');

        // 4. Base64 decoding if required
        $base64Size = 0;
        if (strpos($transferEncoding, 'base64') !== false) {
            $base64Size = strlen($bodyRaw);
            $decodedRaw = base64_decode($bodyRaw);
            if ($decodedRaw === false) {
                $decodedRaw = $bodyRaw;
            }
        } else {
            $decodedRaw = $bodyRaw;
        }

        // 5-7. Convert charset to UTF-8 safely
        $charsetUpper = strtoupper($charset);
        if ($charsetUpper === 'UTF-16LE' || $charsetUpper === 'UTF16LE' || $charsetUpper === 'UTF-16') {
            $decodedUtf8 = MRIMProtocol::decodeUtf16String($decodedRaw);
        } elseif ($charsetUpper === 'WINDOWS-1251' || $charsetUpper === 'CP1251' || $charsetUpper === 'WINDOWS1251') {
            $decodedUtf8 = MRIMProtocol::cp1251ToUtf8($decodedRaw);
        } else {
            $decodedUtf8 = MRIMProtocol::ensureUtf8($decodedRaw);
        }

        $decodedHex = bin2hex($decodedRaw);

        // Required debug logs
        $headersDebug = '';
        foreach ($headers as $k => $v) {
            $headersDebug .= "  $k: $v\n";
        }

        $this->log("MIME RECV2 DEBUG:\n" .
            "- MIME headers:\n" . rtrim($headersDebug) . "\n" .
            "- charset: $charset\n" .
            "- transfer encoding: $transferEncoding\n" .
            "- base64 size: $base64Size bytes\n" .
            "- decoded hex: $decodedHex\n" .
            "- decoded UTF-8: $decodedUtf8", 'debug');

        $isWakeUp = MRIMWakeUp::isWakeUpMessage($flags, $decodedUtf8);

        $this->log(sprintf(
            "MESSAGE RECV FLAGS DEBUG:\nfrom=%s\nflags=0x%08X\nis_wakeup=%s\ntext=%s",
            $fromEmail,
            $flags,
            $isWakeUp ? 'true' : 'false',
            $decodedUtf8
        ), 'debug');

        if ($fromEmail !== '' && ($decodedUtf8 !== '' || $isWakeUp)) {
            $proc = $this->processExtractedMessageText($decodedUtf8, $fromEmail, $flags);
            $cleanText = $proc['text'];
            $isAuthReq = $proc['is_auth_request'];

            if ($isWakeUp) {
                $this->log("Получен БУДИЛЬНИК (WakeUp) от $fromEmail в RECV2!", 'warning');
                $wakeUpData = MRIMWakeUp::processIncomingWakeUp($fromEmail, $cleanText, $flags)['data'];
                $this->emit('wakeup', $wakeUpData);
                $this->sendMessageAck($msgId, $fromEmail);
                return;
            }

            if ($isAuthReq) {
                $this->log("Получен запрос авторизации от $fromEmail в RECV2!", 'info');
                $this->emit('authorize_request', [
                    'from' => $fromEmail,
                    'text' => $cleanText,
                    'nick' => $proc['sender_nick'],
                ]);
            }

            $this->emit('message', [
                'from'            => $fromEmail,
                'text'            => $cleanText,
                'timestamp'       => time(),
                'is_auth_request' => $isAuthReq,
                'sender_nick'     => $proc['sender_nick'],
            ]);
        }

        $this->sendMessageAck($msgId, $fromEmail);
    }

    /**
     * Handle MRIM_CS_MESSAGE_RECV3 (0x1063)
     */
    private function handleIncomingMessage1063(string $data): void
    {
        $offset = 0;

        $type = MRIMProtocol::decodeUint32($data, $offset);
        $offset += 4;

        $fromRes = MRIMProtocol::decodeLPS($data, $offset);
        $offset = $fromRes['next_offset'];
        $fromEmail = strtolower(trim($fromRes['value']));

        if ($offset + 16 <= strlen($data)) {
            $offset += 16;
        }

        if ($offset < strlen($data)) {
            $textRes = MRIMProtocol::decodeLPS($data, $offset, null);
            $msgText = trim($textRes['value']);
        } else {
            $msgText = '';
        }

        $flags = 0;
        $isWakeUp = MRIMWakeUp::isWakeUpMessage($flags, $msgText);

        $this->log(sprintf(
            "MESSAGE RECV FLAGS DEBUG:\nfrom=%s\nflags=0x%08X\nis_wakeup=%s\ntext=%s",
            $fromEmail,
            $flags,
            $isWakeUp ? 'true' : 'false',
            $msgText
        ), 'debug');

        if ($fromEmail !== '' && ($msgText !== '' || $isWakeUp)) {
            $proc = $this->processExtractedMessageText($msgText, $fromEmail, $flags);
            $cleanText = $proc['text'];
            $isAuthReq = $proc['is_auth_request'];

            $this->log("PARSED INCOMING MESSAGE_RECV3 (0x1063) from $fromEmail: $cleanText", 'info');

            if ($isWakeUp) {
                $this->log("Получен БУДИЛЬНИК (WakeUp) от $fromEmail в RECV3!", 'warning');
                $wakeUpData = MRIMWakeUp::processIncomingWakeUp($fromEmail, $cleanText, $flags)['data'];
                $this->emit('wakeup', $wakeUpData);
                return;
            }

            if ($isAuthReq) {
                $this->log("Получен запрос авторизации от $fromEmail в RECV3!", 'info');
                $this->emit('authorize_request', [
                    'from' => $fromEmail,
                    'text' => $cleanText,
                    'nick' => $proc['sender_nick'],
                ]);
            }

            $this->emit('message', [
                'from'            => $fromEmail,
                'text'            => $cleanText,
                'timestamp'       => time(),
                'is_auth_request' => $isAuthReq,
                'sender_nick'     => $proc['sender_nick'],
            ]);
        }
    }

    /**
     * Parse MRIM_CS_MESSAGE_ACK packet (0x1009)
     */
    private function parseMessageAck(string $data): void
    {
        $offset = 0;
        $dataLen = strlen($data);

        if ($dataLen < 8) {
            $this->log("MESSAGE ACK received from server", 'debug');
            $this->emit('message_ack', ['status' => 'delivered']);
            return;
        }

        // Field 1: msg_id (uint32)
        $msgId = MRIMProtocol::decodeUint32($data, $offset);
        $offset += 4;

        // Field 2: flags (uint32)
        $flags = MRIMProtocol::decodeUint32($data, $offset);
        $offset += 4;

        $fromEmail = '';
        $msgText = '';
        $rtfText = '';

        if ($offset < $dataLen) {
            // Field 3: from (LPS)
            $fromRes = MRIMProtocol::decodeLPS($data, $offset);
            $offset = $fromRes['next_offset'];
            $fromEmail = strtolower(trim($fromRes['value']));
        }

        if ($offset < $dataLen) {
            // Field 4: text (LPS)
            $isUtf16 = (($flags & 0x100000) !== 0) || (($flags & MRIMProtocol::MESSAGE_FLAG_UTF16) !== 0);

            $textLen = MRIMProtocol::decodeUint32($data, $offset);
            $rawTextHex = bin2hex(substr($data, $offset + 4, $textLen));

            $startTextOffset = $offset;
            $textRes = MRIMProtocol::decodeLPS($data, $offset, $isUtf16);
            $offset = $textRes['next_offset'];
            $msgText = trim($textRes['value']);

            if ($msgText === '') {
                $fallbackRes = MRIMProtocol::decodeLPS($data, $startTextOffset, !$isUtf16);
                if (trim($fallbackRes['value']) !== '') {
                    $msgText = trim($fallbackRes['value']);
                }
            }

            $utf16Str = $isUtf16 ? 'YES' : 'NO';
            $flagsHex = sprintf('0x%06X', $flags);
            $this->log("MESSAGE_ACK ENCODING DEBUG:\nflags: $flagsHex\nutf16: $utf16Str\nraw_hex: $rawTextHex\ndecoded: $msgText", 'info');
        }

        if ($offset < $dataLen) {
            // Field 5: rtf (LPS)
            $rtfRes = MRIMProtocol::decodeLPS($data, $offset);
            $offset = $rtfRes['next_offset'];
            $rtfText = $rtfRes['value'];
        }

        $flagsHex = "0x" . dechex($flags);
        $rawHex = bin2hex($data);

        $this->log("MESSAGE_ACK DEBUG:\nmsg_id: $msgId\nflags: $flagsHex\nfrom: $fromEmail\ntext: $msgText\nRAW_HEX: $rawHex", 'info');

        $this->emit('message_ack', [
            'status' => 'delivered',
            'msg_id' => $msgId,
            'flags'  => $flags,
            'from'   => $fromEmail,
            'text'   => $msgText,
        ]);

        if (($flags & MRIMProtocol::MESSAGE_FLAG_NOTIFY) !== 0 && ($msgText === '1' || $msgText === '0')) {
            $this->log("Пользователь $fromEmail набирает сообщение (typing=" . ($msgText === '1' ? 'start' : 'stop') . ")...", 'debug');
            $this->emit('typing_notification', ['from' => $fromEmail, 'typing' => ($msgText === '1')]);
            return;
        }

        if ($fromEmail !== '' && $msgText !== '') {
            $proc = $this->processExtractedMessageText($msgText, $fromEmail, $flags);
            $cleanText = $proc['text'];
            $isAuthReq = $proc['is_auth_request'];
            $isWakeUp = MRIMWakeUp::isWakeUpMessage($flags, $cleanText);

            $this->log("PARSED MESSAGE FROM MESSAGE_ACK (0x1009) from $fromEmail: $cleanText (is_wakeup=" . ($isWakeUp ? 'YES' : 'NO') . ")", 'info');

            // Send delivery acknowledgment back to server if msgId > 0
            if ($msgId > 0) {
                $this->sendMessageAck($msgId, $fromEmail);
            }

            // Process incoming WakeUp alarm event
            if ($isWakeUp) {
                $this->log("Получен БУДИЛЬНИК (WakeUp) от $fromEmail в MESSAGE_ACK (0x1009)!", 'warning');
                $wakeUpData = MRIMWakeUp::processIncomingWakeUp($fromEmail, $cleanText, $flags)['data'];
                $this->emit('wakeup', $wakeUpData);
                return;
            }

            if ($isAuthReq) {
                $this->log("Получен запрос авторизации от $fromEmail!", 'info');
                $this->emit('authorize_request', [
                    'from' => $fromEmail,
                    'text' => $cleanText,
                    'nick' => $proc['sender_nick'],
                ]);
            } else {
                $this->emit('message', [
                    'from'            => $fromEmail,
                    'text'            => $cleanText,
                    'timestamp'       => time(),
                    'is_auth_request' => $isAuthReq,
                    'sender_nick'     => $proc['sender_nick'],
                ]);
            }
        }
    }
}
