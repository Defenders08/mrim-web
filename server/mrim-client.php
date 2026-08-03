<?php
/**
 * MRIMClient
 *
 * Full TCP socket client for Mail.Ru Instant Messenger (MRIM) protocol.
 * Handles TCP connection, authentication, ping/pong, contacts, and messaging.
 */

require_once __DIR__ . '/mrim-protocol.php';

class MRIMClient
{
    private $socket = null;
    private string $dispatcherHost;
    private int $dispatcherPort;
    private string $clientName;

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
        $this->clientName     = $config['mrim_client_name'] ?? 'client="mrim-web-client 1.0"';
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
        $this->log("Sending MRIM_CS_LOGIN2 for {$this->email}...");

        // Payload: LPS(login) + LPS(password) + uint32(status) + LPS(client string)
        $payload = MRIMProtocol::encodeLPS($this->email)
                 . MRIMProtocol::encodeLPS($this->password)
                 . MRIMProtocol::encodeUint32($this->status)
                 . MRIMProtocol::encodeLPS($this->clientName);

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

        $this->log("Sending message to $toEmail: $text");

        // MRIM message flags: MESSAGE_FLAG_OFFLINE (0x1) + MESSAGE_FLAG_UTF16 (0x200000)
        $flags = MRIMProtocol::MESSAGE_FLAG_OFFLINE | MRIMProtocol::MESSAGE_FLAG_UTF16;
        $msgId = 0;

        $payload = MRIMProtocol::encodeUint32($flags) .
                   MRIMProtocol::encodeUint32($msgId) .
                   MRIMProtocol::encodeLPS($toEmail) .
                   MRIMProtocol::encodeLPSUtf16($text) .
                   MRIMProtocol::encodeLPS('');

        $flagsHex = "0x" . dechex($flags);
        $msgIdHex = "0x" . dechex($msgId);
        $rawHex = bin2hex($payload);

        $this->log("SEND MESSAGE PAYLOAD:\nMESSAGE FLAGS HEX: $flagsHex\nMSG_ID HEX: $msgIdHex\nrecipient: $toEmail\ntext: $text\nRAW_HEX: $rawHex", 'info');

        return $this->sendPacket(MRIMProtocol::MRIM_CS_MESSAGE, $payload);
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
     * Send a low-level binary MRIM packet
     */
    private function sendPacket(int $msgId, string $data): bool
    {
        if (!$this->socket || !is_resource($this->socket)) {
            return false;
        }

        $packet = MRIMProtocol::buildPacket($msgId, $this->seq++, $data);
        $written = @fwrite($this->socket, $packet);
        $this->log(
    "SENT PACKET CMD=0x" . dechex($msgId) .
    " BYTES=" . $written,
    'debug'
);

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
                        $reason = MRIMProtocol::cp1251ToUtf8(substr($data, 4, $len));
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
            case MRIMProtocol::MRIM_CS_USER_INFO:
            case 0x1022: // MRIM_CS_STATUS_CHANGED / MRIM_CS_CONTACT_STATUS
                $this->parseUserStatus($data);
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
                    // 0x0000 = MESSAGE_DELIVERED (0 = Success direct)
                    // 0x0001 = MESSAGE_REJECTED (1 = Failed/Rejected)
                    // 0x0002 = MESSAGE_USER_OFFLINE (2 = Success stored offline)
                    // 0x0003 = MESSAGE_NOT_FOUND
                    // Bit flags (e.g. 0x8000) may also be present on status word
                    $rawStatus = $status & 0xFFFF;
                    if ($rawStatus === 1 || $rawStatus === 3) {
                        $this->log("Сообщение НЕ доставлено (код ошибки $status)", 'error');
                        $this->emit('message_delivery_status', ['success' => false, 'code' => $status]);
                    } else {
                        $this->log("Сообщение успешно доставлено (код $status)", 'info');
                        $this->emit('message_delivery_status', ['success' => true, 'code' => $status]);
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

            $flags    = $uVals[0] ?? 0;
            $groupId  = $uVals[1] ?? 0;

            // In MRIM protocol contacts_mask:
            // Field u#0 = flags
            // Field u#1 = group_id
            // Field u#2 = user_status / server_status
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

            // Output structured debug info required by specification:
            $this->log("CONTACT #$contactIndex -> UID: $emailClean | EMAIL: $emailClean | NICK: $nickClean | STATUS: $statusVal (0x" . dechex($statusVal) . ") | FLAGS: $flags (0x" . dechex($flags) . ") | ONLINE: $isOnlineStr", 'info');

            $contacts[$emailClean] = [
                'email'        => $emailClean,
                'nickname'     => $nickClean,
                'status'       => $statusVal,
                'status_title' => '',
                'status_desc'  => '',
                'group_id'     => $groupId,
                'group_name'   => $groups[$groupId] ?? 'General',
                'phones'       => $phonesStr,
                'unread'       => 0,
            ];
        }

        $this->contacts = $contacts;
        $this->log("Loaded contact list with " . count($this->contacts) . " active contacts", 'info');
        $this->emit('contact_list', ['contacts' => array_values($this->contacts)]);
    }

    /**
     * Parse MRIM_CS_USER_STATUS payload
     */
    private function parseUserStatus(string $data): void
    {
        $offset = 0;
        $dataLen = strlen($data);

        if ($dataLen < 8) {
            return;
        }

        $statusVal = MRIMProtocol::decodeUint32($data, $offset);
        $offset += 4;

        $userEmailRes = MRIMProtocol::decodeLPS($data, $offset);
        $offset = $userEmailRes['next_offset'];

        $statusTitleRes = MRIMProtocol::decodeLPS($data, $offset);
        $offset = $statusTitleRes['next_offset'];

        $statusDescRes = MRIMProtocol::decodeLPS($data, $offset);
        $offset = $statusDescRes['next_offset'];

        $emailClean = strtolower(trim($userEmailRes['value']));
        if ($emailClean === '') {
            return;
        }

        if (!isset($this->contacts[$emailClean])) {
            $this->contacts[$emailClean] = [
                'email'        => $emailClean,
                'nickname'     => $emailClean,
                'status'       => $statusVal,
                'status_title' => $statusTitleRes['value'],
                'status_desc'  => $statusDescRes['value'],
                'group_id'     => 0,
                'group_name'   => 'General',
                'phones'       => '',
                'unread'       => 0,
            ];
        } else {
            $this->contacts[$emailClean]['status'] = $statusVal;
            $this->contacts[$emailClean]['status_title'] = $statusTitleRes['value'];
            $this->contacts[$emailClean]['status_desc'] = $statusDescRes['value'];
        }

        $isOnlineStr = ($statusVal > 0) ? "YES" : "NO";
        $this->log("STATUS UPDATE -> EMAIL: $emailClean | STATUS: $statusVal (0x" . dechex($statusVal) . ") | ONLINE: $isOnlineStr", 'info');

        $this->emit('user_status', [
            'email'        => $emailClean,
            'status'       => $statusVal,
            'status_title' => $statusTitleRes['value'],
            'status_desc'  => $statusDescRes['value'],
        ]);
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

        $isUtf16 = ($flags & MRIMProtocol::MESSAGE_FLAG_UTF16) !== 0;

        $textRes = MRIMProtocol::decodeLPS($data, $offset, $isUtf16);
        $offset = $textRes['next_offset'];
        $msgText = trim($textRes['value']);

        if ($msgText === '' && !$isUtf16) {
            $textRes2 = MRIMProtocol::decodeLPS($data, $offset, true);
            $msgText = trim($textRes2['value']);
        }

        if ($msgText === '') {
            return;
        }

        $this->log("PARSED INCOMING MESSAGE_RECV (0x1011) from $fromEmail (id=$msgId): $msgText", 'info');

        if (strpos($fromEmail, 'admin@mrim.su') !== false) {
            $this->log("TEST MESSAGE FROM ADMIN RECEIVED", 'info');
        }

        $this->emit('message', [
            'from'      => $fromEmail,
            'text'      => $msgText,
            'timestamp' => time(),
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
        $offset = 0;
        $dataLen = strlen($data);

        if ($dataLen < 12) {
            $this->log("Invalid MESSAGE_RECV2 length: $dataLen", 'error');
            return;
        }

        // Field 1: msg_id (uint32)
        $msgId = MRIMProtocol::decodeUint32($data, $offset);
        $offset += 4;

        // Field 2: flags (uint32)
        $flags = MRIMProtocol::decodeUint32($data, $offset);
        $offset += 4;

        // Field 3: from (LPS)
        $fromRes = MRIMProtocol::decodeLPS($data, $offset);
        $offset = $fromRes['next_offset'];
        $fromEmail = strtolower(trim($fromRes['value']));

        // Field 4: text (LPS)
        $isUtf16 = ($flags & MRIMProtocol::MESSAGE_FLAG_UTF16) !== 0;
        $startTextOffset = $offset;
        $textRes = MRIMProtocol::decodeLPS($data, $offset, $isUtf16);
        $offset = $textRes['next_offset'];
        $msgText = trim($textRes['value']);

        // Fallback: If text decoding returned empty string, attempt opposite decoding
        if ($msgText === '') {
            $fallbackRes = MRIMProtocol::decodeLPS($data, $startTextOffset, !$isUtf16);
            if (trim($fallbackRes['value']) !== '') {
                $msgText = trim($fallbackRes['value']);
            }
        }

        // Field 5: rtf (LPS)
        $rtfText = '';
        if ($offset < $dataLen) {
            $rtfRes = MRIMProtocol::decodeLPS($data, $offset);
            $offset = $rtfRes['next_offset'];
            $rtfText = $rtfRes['value'];
        }

        // Check for MIME encoded gateway or email body if present
        if (strpos($data, "From:") !== false && strpos($data, "\r\n\r\n") !== false) {
            $parts = explode("\r\n\r\n", $data, 2);
            if (count($parts) === 2) {
                if (preg_match('/From:\s*([^\r\n]+)/i', $parts[0], $match)) {
                    $mimeFrom = strtolower(trim($match[1]));
                    if (strpos($mimeFrom, '@') !== false) {
                        $fromEmail = $mimeFrom;
                    }
                }
                $rawBody = trim($parts[1]);
                $decoded = base64_decode($rawBody);
                if ($decoded !== false && strlen($decoded) > 0) {
                    if (function_exists('mb_convert_encoding')) {
                        $mimeText = mb_convert_encoding($decoded, 'UTF-8', 'UTF-16LE');
                    } elseif (function_exists('iconv')) {
                        $mimeText = iconv('UTF-16LE', 'UTF-8//IGNORE', $decoded) ?: $decoded;
                    } else {
                        $mimeText = $decoded;
                    }
                    $mimeText = trim($mimeText, "\x00 ");
                    if ($mimeText !== '') {
                        $msgText = $mimeText;
                    }
                }
            }
        }

        $flagsHex = "0x" . dechex($flags);
        $rawHex = bin2hex($data);

        $this->log("MESSAGE_RECV2 DEBUG:\nmsg_id: $msgId\nflags: $flagsHex\nfrom: $fromEmail\ntext: $msgText\nrtf: $rtfText\nRAW_HEX: $rawHex", 'info');

        if (strpos($fromEmail, 'admin@mrim.su') !== false) {
            $this->log("TEST MESSAGE FROM ADMIN RECEIVED", 'info');
        }

        if ($fromEmail !== '' && $msgText !== '') {
            $this->emit('message', [
                'from'      => $fromEmail,
                'text'      => $msgText,
                'timestamp' => time(),
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

        $textRaw = substr($data, $offset);
        if (function_exists('mb_convert_encoding')) {
            $msgText = mb_convert_encoding($textRaw, 'UTF-8', 'UTF-16LE');
        } elseif (function_exists('iconv')) {
            $msgText = iconv('UTF-16LE', 'UTF-8//IGNORE', $textRaw) ?: $textRaw;
        } else {
            $msgText = $textRaw;
        }

        $msgText = trim($msgText, "\x00 ");

        if ($fromEmail !== '' && $msgText !== '') {
            $this->log("PARSED INCOMING MESSAGE_RECV3 (0x1063) from $fromEmail: $msgText", 'info');

            if (strpos($fromEmail, 'admin@mrim.su') !== false) {
                $this->log("TEST MESSAGE FROM ADMIN RECEIVED", 'info');
            }

            $this->emit('message', [
                'from'      => $fromEmail,
                'text'      => $msgText,
                'timestamp' => time(),
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
            $isUtf16 = ($flags & MRIMProtocol::MESSAGE_FLAG_UTF16) !== 0;
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

        if ($fromEmail !== '' && $msgText !== '') {
            $this->log("PARSED MESSAGE FROM MESSAGE_ACK (0x1009) from $fromEmail: $msgText", 'info');

            if (strpos($fromEmail, 'admin@mrim.su') !== false) {
                $this->log("TEST MESSAGE FROM ADMIN RECEIVED", 'info');
            }

            $this->emit('message', [
                'from'      => $fromEmail,
                'text'      => $msgText,
                'timestamp' => time(),
            ]);
        }
    }
}
