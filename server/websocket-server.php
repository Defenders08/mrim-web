<?php
/**
 * WebSocket & HTTP Combined Server for MRIM Web Client
 *
 * Runs on port 3000 (configurable via PORT environment variable).
 * Serves static HTML/CSS/JS frontend files over HTTP GET
 * and upgrades connections to WebSocket for real-time MRIM communication.
 */

require_once __DIR__ . '/mrim-client.php';

class MRIMWebServer
{
    private $serverSocket;
    private array $clients = [];
    private array $config;
    private string $publicDir;
    private bool $running = true;

    public function __construct(array $config)
    {
        $this->publicDir = realpath(__DIR__ . '/../public') ?: (__DIR__ . '/../public');
        $this->config = $config;
    }

    /**
     * Generate a UUID v4 string for WebSocket session tracking
     */
    private function generateUuid(): string
    {
        $data = random_bytes(16);
        $data[6] = chr(ord($data[6]) & 0x0f | 0x40);
        $data[8] = chr(ord($data[8]) & 0x3f | 0x80);
        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($data), 4));
    }

    /**
     * Start listening and enter the stream_select event loop
     */
    public function start(string $host = '0.0.0.0', int $port = 8080): void
    {
        $errno = 0;
        $errstr = '';
        $context = stream_context_create([
            'socket' => [
                'so_reuseaddr' => true,
                'so_reuseport' => true,
            ],
        ]);
        $this->serverSocket = @stream_socket_server(
            "tcp://$host:$port",
            $errno,
            $errstr,
            STREAM_SERVER_BIND | STREAM_SERVER_LISTEN,
            $context
        );

        if (!$this->serverSocket) {
            die("Fatal: Failed to bind HTTP/WebSocket server on $host:$port - $errstr ($errno)\n");
        }

        stream_set_blocking($this->serverSocket, false);
        echo "=========================================================\n";
        echo "  MRIM Web Client Server Started\n";
        echo "  HTTP/WebSocket listening on: http://$host:$port\n";
        echo "  Serving frontend from: {$this->publicDir}\n";
        echo "=========================================================\n";

        $this->loop();
    }

    /**
     * Main non-blocking stream_select loop
     */
    private function loop(): void
    {
        while ($this->running) {
            $readSockets = [$this->serverSocket];

            // Add all connected browser client sockets
            foreach ($this->clients as $id => $client) {
                if (is_resource($client['socket'])) {
                    $readSockets[] = $client['socket'];
                } else {
                    $this->closeClient($id);
                    continue;
                }

                // Add MRIM TCP socket for each specific WebSocket client if connected
                if (!empty($client['mrim_client'])) {
                    $mrimSock = $client['mrim_client']->getSocket();
                    if ($mrimSock && is_resource($mrimSock)) {
                        $readSockets[] = $mrimSock;
                    }
                }
            }

            $writeSockets = null;
            $exceptSockets = null;

            // Wait up to 200ms for activity
            $numReady = @stream_select($readSockets, $writeSockets, $exceptSockets, 0, 200000);

            // Periodically check ping intervals per client
            foreach ($this->clients as $client) {
                if (!empty($client['mrim_client'])) {
                    $client['mrim_client']->checkPing();
                }
            }

            if ($numReady === false) {
                // Interrupted system call
                continue;
            }

            if ($numReady > 0) {
                // 1. Check for new connections on HTTP/WebSocket server socket
                if (in_array($this->serverSocket, $readSockets, true)) {
                    $this->acceptNewClient();
                    $key = array_search($this->serverSocket, $readSockets, true);
                    unset($readSockets[$key]);
                }

                // 2. Check for incoming bytes from any active MRIM TCP sockets
                foreach ($this->clients as $id => $client) {
                    if (!empty($client['mrim_client'])) {
                        $mrimSock = $client['mrim_client']->getSocket();
                        if ($mrimSock && in_array($mrimSock, $readSockets, true)) {
                            $client['mrim_client']->readLoopStep();
                            $key = array_search($mrimSock, $readSockets, true);
                            if ($key !== false) {
                                unset($readSockets[$key]);
                            }
                        }
                    }
                }

                // 3. Process incoming HTTP requests or WebSocket frames from browsers
                foreach ($readSockets as $sock) {
                    $this->handleClientData($sock);
                }
            }
        }
    }

    /**
     * Accept a new incoming TCP connection from browser
     */
    private function acceptNewClient(): void
    {
        $newSocket = @stream_socket_accept($this->serverSocket, 0);
        if ($newSocket) {
            stream_set_blocking($newSocket, false);
            $id = (int) $newSocket;
            $sessionUuid = $this->generateUuid();

            $this->clients[$id] = [
                'socket'      => $newSocket,
                'is_websocket'=> false,
                'buffer'      => '',
                'session_uuid'=> $sessionUuid,
                'mrim_client' => null,
                'connected_at'=> time(),
            ];
        }
    }

    /**
     * Handle incoming data from browser socket (HTTP or WebSocket frame)
     */
    private function handleClientData($sock): void
    {
        $id = (int) $sock;
        if (!isset($this->clients[$id])) {
            return;
        }

        $data = @fread($sock, 8192);
        if ($data === false || ($data === '' && feof($sock))) {
            $this->closeClient($id);
            return;
        }

        if ($data === '') {
            return;
        }

        if (!$this->clients[$id]['is_websocket']) {
            $this->clients[$id]['buffer'] .= $data;
            if (strpos($this->clients[$id]['buffer'], "\r\n\r\n") !== false) {
                $this->processHttpRequest($id);
            }
        } else {
            $this->processWebSocketFrame($id, $data);
        }
    }

    /**
     * Process an HTTP request (static file or WebSocket upgrade)
     */
    private function processHttpRequest(int $id): void
    {
        $client = $this->clients[$id];
        $request = $client['buffer'];
        $sock = $client['socket'];

        // Check if browser is asking for WebSocket upgrade
        if (preg_match("/Sec-WebSocket-Key: (.*)\r\n/i", $request, $match)) {
            $key = trim($match[1]);
            $acceptKey = base64_encode(sha1($key . '258EAFA5-E914-47DA-95CA-C5AB0DC85B11', true));
            $upgradeResponse = "HTTP/1.1 101 Switching Protocols\r\n"
                             . "Upgrade: websocket\r\n"
                             . "Connection: Upgrade\r\n"
                             . "Sec-WebSocket-Accept: $acceptKey\r\n\r\n";

            @fwrite($sock, $upgradeResponse);
            $this->clients[$id]['is_websocket'] = true;
            $this->clients[$id]['buffer'] = '';
            $this->clients[$id]['ws_buffer'] = '';

            // Instantiate dedicated MRIMClient specifically bound to this WebSocket connection
            $mrimClient = new MRIMClient($this->config);
            
            $mrimClient->setEventCallback(function (string $event, array $data) use ($id) {
                $this->sendJsonToClient($id, ['type' => $event, 'data' => $data]);
            });

            $mrimClient->setLoggerCallback(function (string $msg, string $level) use ($id) {
                $line = "[" . date('H:i:s') . "] [$level] [WS ID: $id] $msg";
                echo $line . PHP_EOL;
                $this->sendJsonToClient($id, ['type' => 'log', 'data' => ['message' => $msg, 'level' => $level]]);
            });

            $this->clients[$id]['mrim_client'] = $mrimClient;

            $wsUuid = $this->clients[$id]['session_uuid'];
            $mrimHash = spl_object_id($mrimClient);
            echo "[DEBUG] Established WebSocket connection [WS ID: $id, Session UUID: $wsUuid] with dedicated MRIMClient [Hash: $mrimHash]\n";

            // Immediately send current MRIM status & contacts for this specific client
            $this->sendJsonToClient($id, [
                'type' => 'init_state',
                'data' => [
                    'mrim_state' => $mrimClient->getState(),
                    'contacts'   => array_values($mrimClient->getContacts()),
                    'my_email'   => $mrimClient->getEmail(),
                ]
            ]);
            return;
        }

        // Standard HTTP GET request for frontend files
        $firstLine = explode("\r\n", $request)[0] ?? '';
        $path = '/index.html';
        if (preg_match('/^GET\s+([^\s?]+)/i', $firstLine, $matches)) {
            $path = $matches[1];
            if ($path === '' || $path === '/') {
                $path = '/index.html';
            }
        }

        // Avatar proxy handler for mrim.su avatars
        if (preg_match('#^/avatar/([^/]+)/([^/]+)#i', $path, $avatarMatches)) {
            $domain = rawurldecode($avatarMatches[1]);
            $username = rawurldecode($avatarMatches[2]);
            $targetUrl = "http://obraz.mrim.su/" . rawurlencode($domain) . "/" . rawurlencode($username) . "/_mrimavatar";

            $ctx = stream_context_create([
                'http' => [
                    'method'  => 'GET',
                    'timeout' => 5,
                    'header'  => "User-Agent: MRIMWebClient/1.0\r\n",
                ],
            ]);

            $imgData = @file_get_contents($targetUrl, false, $ctx);
            if ($imgData !== false && strlen($imgData) > 0) {
                $response = "HTTP/1.1 200 OK\r\n"
                          . "Content-Type: image/jpeg\r\n"
                          . "Cache-Control: public, max-age=86400\r\n"
                          . "Access-Control-Allow-Origin: *\r\n"
                          . "Content-Length: " . strlen($imgData) . "\r\n"
                          . "Connection: close\r\n\r\n"
                          . $imgData;
            } else {
                $response = "HTTP/1.1 404 Not Found\r\n"
                          . "Content-Type: image/jpeg\r\n"
                          . "Content-Length: 0\r\n"
                          . "Connection: close\r\n\r\n";
            }

            @fwrite($sock, $response);
            $this->closeClient($id);
            return;
        }

        $filePath = realpath($this->publicDir . $path);
        if ($filePath && strpos($filePath, $this->publicDir) === 0 && is_file($filePath)) {
            $ext = strtolower(pathinfo($filePath, PATHINFO_EXTENSION));
            $mimeTypes = [
                'html' => 'text/html; charset=utf-8',
                'css'  => 'text/css; charset=utf-8',
                'js'   => 'application/javascript; charset=utf-8',
                'ico'  => 'image/x-icon',
                'png'  => 'image/png',
            ];
            $contentType = $mimeTypes[$ext] ?? 'application/octet-stream';
            $content = file_get_contents($filePath);

            $response = "HTTP/1.1 200 OK\r\n"
                      . "Content-Type: $contentType\r\n"
                      . "Content-Length: " . strlen($content) . "\r\n"
                      . "Connection: close\r\n\r\n"
                      . $content;
        } else {
            $body = "<h1>404 Not Found</h1><p>File not found: " . htmlspecialchars($path) . "</p>";
            $response = "HTTP/1.1 404 Not Found\r\n"
                      . "Content-Type: text/html; charset=utf-8\r\n"
                      . "Content-Length: " . strlen($body) . "\r\n"
                      . "Connection: close\r\n\r\n"
                      . $body;
        }

        @fwrite($sock, $response);
        $this->closeClient($id);
    }

    /**
     * Decode RFC 6455 WebSocket frames from browser buffer
     */
    private function processWebSocketFrame(int $id, string $data): void
    {
        if (!isset($this->clients[$id])) {
            return;
        }

        $this->clients[$id]['ws_buffer'] .= $data;
        $buf = &$this->clients[$id]['ws_buffer'];

        while (strlen($buf) >= 2) {
            $firstByte = ord($buf[0]);
            $secondByte = ord($buf[1]);

            $opcode = $firstByte & 0x0F;
            $isMasked = ($secondByte & 0x80) !== 0;
            $payloadLen = $secondByte & 0x7F;

            $offset = 2;

            if ($payloadLen === 126) {
                if (strlen($buf) < 4) break;
                $payloadLen = unpack('n', substr($buf, 2, 2))[1];
                $offset = 4;
            } elseif ($payloadLen === 127) {
                if (strlen($buf) < 10) break;
                // Read 64-bit integer length
                $p = unpack('N2', substr($buf, 2, 8));
                $payloadLen = ($p[1] << 32) | $p[2];
                $offset = 10;
            }

            $maskLen = $isMasked ? 4 : 0;
            $totalLen = $offset + $maskLen + $payloadLen;

            if (strlen($buf) < $totalLen) {
                // Wait for more bytes
                break;
            }

            $frameData = substr($buf, 0, $totalLen);
            $buf = substr($buf, $totalLen);

            // Handle connection close opcode
            if ($opcode === 0x08) {
                $this->closeClient($id);
                return;
            }

            // Ping control frame -> send Pong
            if ($opcode === 0x09) {
                $pongHeader = pack('CC', 0x8A, $payloadLen);
                $pongMask = $maskLen ? substr($frameData, $offset, 4) : '';
                $pongPayload = substr($frameData, $offset + $maskLen, $payloadLen);
                if ($isMasked) {
                    $unmaskedPong = '';
                    for ($i = 0; $i < $payloadLen; $i++) {
                        $unmaskedPong .= chr(ord($pongPayload[$i]) ^ ord($pongMask[$i % 4]));
                    }
                    $pongPayload = $unmaskedPong;
                }
                @fwrite($this->clients[$id]['socket'], $pongHeader . $pongPayload);
                continue;
            }

            $mask = $isMasked ? substr($frameData, $offset, 4) : '';
            $rawPayload = substr($frameData, $offset + $maskLen, $payloadLen);

            $unmasked = '';
            if ($isMasked) {
                for ($i = 0; $i < $payloadLen; $i++) {
                    $unmasked .= chr(ord($rawPayload[$i]) ^ ord($mask[$i % 4]));
                }
            } else {
                $unmasked = $rawPayload;
            }

            $command = json_decode($unmasked, true);
            if (is_array($command)) {
                $this->handleBrowserCommand($id, $command);
            }
        }
    }

    /**
     * Handle actions sent from JavaScript in browser
     */
    private function handleBrowserCommand(int $clientId, array $command): void
    {
        if (!isset($this->clients[$clientId])) {
            return;
        }

        $clientInfo = $this->clients[$clientId];
        /** @var MRIMClient|null $mrimClient */
        $mrimClient = $clientInfo['mrim_client'] ?? null;
        $wsUuid = $clientInfo['session_uuid'] ?? 'unknown';

        if (!$mrimClient) {
            echo "[SECURITY ERROR] No MRIMClient instance associated with WS ID: $clientId\n";
            return;
        }

        $action = $command['action'] ?? '';
        $mrimHash = spl_object_id($mrimClient);
        $authenticatedEmail = $mrimClient->getEmail();

        switch ($action) {
            case 'login':
                $email = trim($command['email'] ?? '');
                $password = (string) ($command['password'] ?? '');
                $status = (int) ($command['status'] ?? MRIMProtocol::STATUS_ONLINE);

                if ($email === '' || $password === '') {
                    $this->sendJsonToClient($clientId, [
                        'type' => 'login_error',
                        'data' => ['reason' => 'Please enter both email and password']
                    ]);
                    return;
                }

                // Ensure any previous session on this connection is cleanly closed first
                if ($mrimClient->getState() !== 'disconnected') {
                    $mrimClient->disconnect();
                }

                echo "[DEBUG] Login command received [WS ID: $clientId, Session UUID: $wsUuid, Login: $email, MRIMClient Hash: $mrimHash]\n";
                $this->sendJsonToClient($clientId, ['type' => 'status_log', 'data' => ['message' => "Connecting to MRIM as $email..."]]);
                $mrimClient->connect($email, $password, $status);
                break;

            case 'send_message':
                $to = trim($command['to'] ?? '');
                $text = (string) ($command['text'] ?? '');

                // Verify sender authentication and log details
                $mrimState = $mrimClient->getState();
                $ownerMatch = ($mrimState === 'authenticated' && $authenticatedEmail !== '');

                echo "[DEBUG] send_message attempt:\n"
                   . "  - WS ID: $clientId\n"
                   . "  - Session UUID: $wsUuid\n"
                   . "  - Claimed/Auth Login: " . ($authenticatedEmail !== '' ? $authenticatedEmail : 'unauthenticated') . "\n"
                   . "  - MRIMClient Hash (spl_object_id): $mrimHash\n"
                   . "  - Owner Match: " . ($ownerMatch ? 'YES' : 'NO') . "\n"
                   . "  - Recipient: $to\n";

                if ($to !== '' && $text !== '') {
                    if ($mrimState !== 'authenticated') {
                        echo "[SECURITY REJECTION] Cannot send message: WS ID $clientId is not authenticated in MRIM.\n";
                        $this->sendJsonToClient($clientId, [
                            'type' => 'log',
                            'data' => ['message' => 'Error: You must be logged in to send messages.', 'level' => 'error']
                        ]);
                        return;
                    }

                    $sent = $mrimClient->sendMessage($to, $text);
                    if ($sent) {
                        // Echo sent message strictly back to THIS browser WebSocket client only
                        $this->sendJsonToClient($clientId, [
                            'type' => 'message_sent',
                            'data' => [
                                'to'   => $to,
                                'text' => $text,
                                'timestamp' => time(),
                            ]
                        ]);
                    }
                }
                break;

            case 'authorize_contact':
                $email = trim($command['email'] ?? '');
                if ($email !== '') {
                    $mrimClient->authorizeContact($email);
                }
                break;

            case 'add_contact':
                $email = trim($command['email'] ?? '');
                $nickname = trim($command['nickname'] ?? '');
                if ($email !== '') {
                    $mrimClient->addContact($email, $nickname);
                }
                break;

            case 'request_authorization':
                $email = trim($command['email'] ?? '');
                $reason = trim($command['reason'] ?? '');
                if ($email !== '') {
                    $mrimClient->requestAuthorization($email, $reason);
                }
                break;

            case 'reconnect':
                $mrimClient->reconnect();
                break;

            case 'logout':
                $mrimClient->disconnect();
                $this->sendJsonToClient($clientId, ['type' => 'logout', 'data' => ['reason' => 'User requested logout']]);
                break;

            case 'ping':
                $mrimClient->sendPing();
                break;

            default:
                break;
        }
    }

    /**
     * Encode and send RFC 6455 WebSocket JSON frame to one client
     */
    private function sendJsonToClient(int $id, array $payload): void
    {
        if (!isset($this->clients[$id]) || !$this->clients[$id]['is_websocket']) {
            return;
        }

        $cleanPayload = MRIMProtocol::cleanArrayForJson($payload);
        $json = json_encode($cleanPayload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $len = strlen($json);

        if ($len <= 125) {
            $header = pack('CC', 0x81, $len);
        } elseif ($len <= 65535) {
            $header = pack('CCn', 0x81, 126, $len);
        } else {
            $header = pack('CCJ', 0x81, 127, $len);
        }

        @fwrite($this->clients[$id]['socket'], $header . $json);
    }

    /**
     * Close browser socket connection and completely clean up MRIMClient instance
     */
    private function closeClient(int $id): void
    {
        if (isset($this->clients[$id])) {
            $wsUuid = $this->clients[$id]['session_uuid'] ?? 'unknown';
            /** @var MRIMClient|null $mrimClient */
            $mrimClient = $this->clients[$id]['mrim_client'] ?? null;

            if ($mrimClient) {
                $mrimHash = spl_object_id($mrimClient);
                $email = $mrimClient->getEmail();
                echo "[DEBUG] Destroying MRIMClient instance [Hash: $mrimHash, Login: $email] for disconnected WebSocket [WS ID: $id, Session UUID: $wsUuid]\n";
                $mrimClient->disconnect();
                $this->clients[$id]['mrim_client'] = null;
            }

            @fclose($this->clients[$id]['socket']);
            unset($this->clients[$id]);
            echo "[DEBUG] WebSocket connection [WS ID: $id, Session UUID: $wsUuid] closed and purged.\n";
        }
    }
}

// Entry point: Start WebSocket server when executed from command line
if (php_sapi_name() === 'cli' && basename(__FILE__) === basename($_SERVER['SCRIPT_FILENAME'] ?? '')) {
    $config = require __DIR__ . '/../config.php';
    $server = new MRIMWebServer($config);
    $server->start('0.0.0.0', $config['server_port']);
}
