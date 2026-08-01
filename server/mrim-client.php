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


    $flags = 0;


    $payload =
        MRIMProtocol::encodeUint32($flags) .
        MRIMProtocol::encodeLPS($toEmail) .
        MRIMProtocol::encodeLPSUtf16($text) .
        MRIMProtocol::encodeLPS('') .
        MRIMProtocol::encodeUint32(0);


    $this->log(
        "MESSAGE PAYLOAD HEX=" . bin2hex($payload),
        'debug'
    );


    return $this->sendPacket(
        MRIMProtocol::MRIM_CS_MESSAGE,
        $payload
    );
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
    private function parsePacket(array $header, string $data): void
    {
        $cmd = $header['msg'];
        $cmdName = MRIMProtocol::getCommandName($cmd);
        $this->log("Received packet: $cmdName (dlen=" . strlen($data) . ")", 'debug');

        $this->log(
    "DEBUG: cmd=0x" . dechex($cmd) .
    " MESSAGE_RECV=0x" . dechex(MRIMProtocol::MRIM_CS_MESSAGE_RECV) .
    " MESSAGE_RECV2=0x" . dechex(MRIMProtocol::MRIM_CS_MESSAGE_RECV2) .
    " MESSAGE_ACK=0x" . dechex(MRIMProtocol::MRIM_CS_MESSAGE_ACK)
);

    $this->log("Received packet: $cmdName (dlen=" . strlen($data) . ")", 'debug');

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
                $this->parseUserStatus($data);
                break;

                case MRIMProtocol::MRIM_CS_USER_INFO:

    $this->log("USER INFO RECEIVED", 'debug');
    $this->log("USER INFO RAW=" . bin2hex($data), 'debug');

    break;


case MRIMProtocol::MRIM_CS_MESSAGE_RECV2:

    $this->log("!!! MESSAGE_RECV2 HANDLER !!!", 'debug');

    $this->handleIncomingMessage($data);

    $this->sendMessageAck($data);

    break;
    case MRIMProtocol::MRIM_CS_MESSAGE_RECV3:

    $this->log("!!! MESSAGE_RECV3 HANDLER !!!", 'debug');

    $this->handleIncomingMessage1063($data);

    break;

            case MRIMProtocol::MRIM_CS_MESSAGE_RECV:
    $this->parseIncomingMessage($data);
    $this->sendMessageAck($data);
    break;

case MRIMProtocol::MRIM_CS_MESSAGE_ACK:

    $this->log("MESSAGE ACK received", 'debug');

    $this->emit('message_sent', [
        'status' => 'delivered'
    ]);

    break;

            case MRIMProtocol::MRIM_CS_MESSAGE_STATUS:

    $this->log(
        "MESSAGE STATUS RAW=" . bin2hex($data),
        'debug'
    );

    if (strlen($data) >= 4) {
        $status = MRIMProtocol::decodeUint32($data, 0);

        $this->log(
            "MESSAGE STATUS = " . $status,
            'debug'
        );
    }

    break;

    $this->log(
        "MESSAGE STATUS = ".$status,
        'debug'
    );

    switch ($status) {

        case 0:
            $this->log("MESSAGE SEND FAILED", 'error');
            break;

        case 1:
            $this->log("MESSAGE SENT", 'debug');
            break;

        default:
            $this->log("UNKNOWN MESSAGE STATUS ".$status, 'debug');
    }

    break;

            case MRIMProtocol::MRIM_CS_LOGOUT:
                $this->log("Server sent MRIM_CS_LOGOUT");
                $this->emit('logout', ['reason' => 'Logged out by server']);
                $this->disconnect();
                break;

            default:

    if ($cmd == 0x1063) {
        $this->log("!!! FOUND 1063 !!!");

        $this->log(
            "RAW=".bin2hex($data)
        );

        break;
    }


    $this->log(
        "UNKNOWN PACKET CMD=0x" . dechex($cmd),
        'debug'
    );

    $this->log(
        "UNKNOWN RAW=" . bin2hex($data),
        'debug'
    );

    break;

    $this->log(
        "UNKNOWN RAW=" . bin2hex($data),
        'debug'
    );

    break;
        }
    }

    /**
     * Parse MRIM_CS_CONTACT_LIST2 payload
     */
    private function parseContactList(string $data): void
{
    $offset = 0;

    // status
    $status = MRIMProtocol::decodeUint32($data, $offset);
    $offset += 4;

    // groups count
    $groupsCount = 0;

    $this->log(
        "GROUPS COUNT: " . $groupsCount,
        'debug'
    );

    // groups mask
    $maskLen = MRIMProtocol::decodeUint32($data, $offset);
$offset += 4;

$groupsMask = substr($data, $offset, $maskLen);
$offset += $maskLen;
// пропускаем служебные данные перед первым контактом
$offset += 4;

// В MRIM_CS_CONTACT_LIST2 нет количества контактов
$contactsCount = 1000;

$this->log(
    "CONTACT COUNT MODE: AUTO",
    'debug'
);

$this->log(
    "REAL CONTACT OFFSET=".$offset,
    'debug'
);

$this->log(
    "GROUP MASK LEN: ".$maskLen,
    'debug'
);

$this->log(
    "GROUP MASK HEX: ".bin2hex($groupsMask),
    'debug'
);

$groups = [];

// Ищем начало первого контакта.
// После mask могут идти служебные байты.




$this->log(
    "CONTACTS REAL OFFSET AFTER SEARCH=".$offset,
    'debug'
);

$this->log(
    "NEXT BYTES: " . bin2hex(substr($data, $offset, 100)),
    'debug'
);



$this->log(
    "NEXT BYTES: " . bin2hex(substr($data, $offset, 100)),
    'debug'
);






        $this->log("CONTACT LIST OFFSET AFTER HEADER: " . $offset, 'debug');
$this->log("NEXT BYTES: " . bin2hex(substr($data, $offset, 100)), 'debug');

        $this->log("Parsing contact list, groups: $groupsCount");
        $this->log("CONTACT LIST RECEIVED", 'debug');

        $contacts = [];
        $i = 0;

while ($offset + 4 <= strlen($data)) {

    $this->log("CONTACT OFFSET=".$offset, 'debug');


    // group id
    $groupId = MRIMProtocol::decodeUint32($data, $offset);
    $offset += 4;


    // email
    $email = MRIMProtocol::decodeLPS($data, $offset);
    $offset = $email['next_offset'];


    if ($email['value'] === '') {
        break;
    }


    // nickname UTF-16
    $nickname = MRIMProtocol::decodeLPSUtf16($data, $offset);
    $offset = $nickname['next_offset'];


    $emailStr = trim($email['value']);
    $nick = trim($nickname['value']);


    $this->log(
        "CONTACT: ".$emailStr." / ".$nick,
        'debug'
    );


    $contacts[$emailStr] = [
        'email' => $emailStr,
        'nickname' => $nick ?: $emailStr,
        'status' => 0,
        'status_title' => '',
        'status_desc' => '',
        'group_id' => $groupId,
        'unread' => 0,
    ];

    $i++;
}

        $this->contacts = $contacts;
        $this->emit('contact_list', ['contacts' => array_values($this->contacts)]);
    }

    /**
     * Parse MRIM_CS_USER_STATUS payload
     */
    private function parseUserStatus(string $data): void
{
    $offset = 0;

    $statusVal = MRIMProtocol::decodeUint32($data, $offset);
    $offset += 4;

    $userEmail = MRIMProtocol::decodeLPS($data, $offset);
    $offset = $userEmail['next_offset'];

    $statusTitle = MRIMProtocol::decodeLPS($data, $offset);
    $offset = $statusTitle['next_offset'];

    $statusDesc = MRIMProtocol::decodeLPS($data, $offset);
    $offset = $statusDesc['next_offset'];


    $email = trim($userEmail['value']);


    $this->log(
        "Status update: $email status=$statusVal title=".$statusTitle['value'],
        'debug'
    );


    if (isset($this->contacts[$email])) {

        $this->contacts[$email]['status'] = $statusVal;
        $this->contacts[$email]['status_title'] = $statusTitle['value'];
        $this->contacts[$email]['status_desc'] = $statusDesc['value'];

    }


    $this->emit('user_status', [
        'email' => $email,
        'status' => $statusVal,
        'status_title' => $statusTitle['value'],
        'status_desc' => $statusDesc['value'],
    ]);
}

    /**
     * Parse incoming message packet and send delivery ACK
     */
    private function parseIncomingMessage(string $data): void
{
    $this->log("PARSER VERSION TEST", 'debug');
    $this->log("=== NEW PARSER ===", 'debug');
    $this->log("MESSAGE RAW: " . bin2hex($data), 'debug');

    $offset = 0;

    $msgId = MRIMProtocol::decodeUint32($data, $offset);
    $offset += 4;

    $flags = MRIMProtocol::decodeUint32($data, $offset);
    $offset += 4;


    // Отправитель
    $from = MRIMProtocol::decodeLPS($data, $offset);
    $offset = $from['next_offset'];


    // Длина UTF-16 текста
$textLen = MRIMProtocol::decodeUint32($data, $offset);
$offset += 4;

$this->log("TEXT LEN = ".$textLen, 'debug');

$rawText = substr($data, $offset, $textLen);

$this->log("TEXT HEX = ".bin2hex($rawText), 'debug');


// UTF-16LE -> UTF-8
if (function_exists('mb_convert_encoding')) {

    $msgText = mb_convert_encoding(
        $rawText,
        'UTF-8',
        'UTF-16LE'
    );

} elseif (function_exists('iconv')) {

    $msgText = iconv(
        'UTF-16LE',
        'UTF-8//IGNORE',
        $rawText
    );

} else {

    $msgText = $rawText;
}


    $msgText = trim($msgText);

if ($msgText === '') {
    $this->log("Ignoring empty message", 'debug');
    return;
}

$this->log("DEBUG TEXT=[" . $msgText . "] LEN=" . strlen($msgText), 'debug');

$fromEmail = trim($from['value']);

$msgText = trim($msgText);

if ($msgText === '') {
    $this->log("Ignoring empty message", 'debug');
    return;
}


    $this->log(
        "New message from $fromEmail (id=$msgId): " . $msgText
    );


    $this->emit('message', [
        'from' => $fromEmail,
        'text' => $msgText,
        'timestamp' => time(),
    ]);
}
private function sendMessageAck(string $data): void
{

    $offset = 0;

    $msgId = MRIMProtocol::decodeUint32($data, $offset);

    $payload =
        MRIMProtocol::encodeUint32($msgId);

    $this->sendPacket(
        MRIMProtocol::MRIM_CS_MESSAGE_ACK,
        $payload
    );

    $this->log(
        "MESSAGE ACK sent for ID ".$msgId,
        'debug'
    );

        $this->log(
    "SEND HEX=" . bin2hex($payload),
    'debug'
);
}
private function handleIncomingMessage(string $data): void
{
    $this->log("!!! MESSAGE_RECV2 HANDLER !!!");

    // первые 12 байт служебные
    $body = substr($data, 12);

    // переводим в строку
    $text = $body;

    // ищем заголовки
    $parts = explode("\r\n\r\n", $text, 2);

    if (count($parts) !== 2) {
        $this->log("Invalid message format", 'error');
        return;
    }

    $headers = $parts[0];
    $payload = trim($parts[1]);


    // получаем отправителя
    preg_match(
        '/From:\s*(.+)/',
        $headers,
        $fromMatch
    );

    $from = $fromMatch[1] ?? 'unknown';


    // декодируем base64
    $decoded = base64_decode($payload);


    if ($decoded === false) {
        $this->log("BASE64 decode failed", 'error');
        return;
    }


    // UTF-16LE -> UTF-8
    if (function_exists('mb_convert_encoding')) {

        $message = mb_convert_encoding(
            $decoded,
            'UTF-8',
            'UTF-16LE'
        );

    } else {

        $message = iconv(
            'UTF-16LE',
            'UTF-8//IGNORE',
            $decoded
        );

    }


    $this->log(
        "FROM: ".$from
    );

    $this->log(
        "TEXT: ".$message
    );


    // отправляем в websocket
    $this->emit(
        'message',
        [
            'from'=>$from,
            'text'=>$message
        ]
    );
}
private function handleIncomingMessage1063(string $data): void
{
    $this->log("MESSAGE 1063 RAW=".bin2hex($data),'debug');

    $offset = 0;


    // type
    $type = MRIMProtocol::decodeUint32($data,$offset);
    $offset +=4;


    // sender
    $from = MRIMProtocol::decodeLPS($data,$offset);
    $offset = $from['next_offset'];


    // пропускаем служебные поля
    if ($offset + 20 > strlen($data)) {
        return;
    }


    $offset += 16;


    $textRaw = substr($data,$offset);


    // UTF16LE
    $text = mb_convert_encoding(
        $textRaw,
        'UTF-8',
        'UTF-16LE'
    );


    $text = trim($text);


    $this->log(
        "MESSAGE1063 FROM=".$from['value']
    );

    $this->log(
        "MESSAGE1063 TEXT=".$text
    );


    if ($text !== '') {

        $this->emit(
            'message',
            [
                'from'=>$from['value'],
                'text'=>$text,
                'timestamp'=>time()
            ]
        );

    }
}
}
