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
    private MRIMClient $mrimClient;
    private string $publicDir;
    private bool $running = true;

    public function __construct(array $config)
    {
        $this->publicDir = realpath(__DIR__ . '/../public') ?: (__DIR__ . '/../public');
        $this->mrimClient = new MRIMClient($config);

        // Bind callback events from MRIMClient to broadcast to connected WebSockets
        $this->mrimClient->setEventCallback(function (string $event, array $data) {
            $this->broadcastJson(['type' => $event, 'data' => $data]);
        });

        $this->mrimClient->setLoggerCallback(function (string $msg, string $level) {
            $line = "[" . date('H:i:s') . "] [$level] $msg";
            echo $line . PHP_EOL;
            $this->broadcastJson(['type' => 'log', 'data' => ['message' => $msg, 'level' => $level]]);
        });
    }

    /**
     * Start listening and enter the stream_select event loop
     */
    public function start(string $host = '0.0.0.0', int $port = 3000): void
    {
        $errno = 0;
        $errstr = '';
        $this->serverSocket = @stream_socket_server(
            "tcp://$host:$port",
            $errno,
            $errstr,
            STREAM_SERVER_BIND | STREAM_SERVER_LISTEN
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
                    unset($this->clients[$id]);
                }
            }

            // Add MRIM TCP socket if connected
            $mrimSock = $this->mrimClient->getSocket();
            if ($mrimSock && is_resource($mrimSock)) {
                $readSockets[] = $mrimSock;
            }

            $writeSockets = null;
            $exceptSockets = null;

            // Wait up to 200ms for activity
            $numReady = @stream_select($readSockets, $writeSockets, $exceptSockets, 0, 200000);

            // Periodically check ping intervals
            $this->mrimClient->checkPing();

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

                // 2. Check for incoming bytes from MRIM TCP server
                if ($mrimSock && in_array($mrimSock, $readSockets, true)) {
                    $this->mrimClient->readLoopStep();
                    $key = array_search($mrimSock, $readSockets, true);
                    unset($readSockets[$key]);
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
            $this->clients[$id] = [
                'socket'      => $newSocket,
                'is_websocket'=> false,
                'buffer'      => '',
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

            // Immediately send current MRIM status & contacts to newly connected tab
            $this->sendJsonToClient($id, [
                'type' => 'init_state',
                'data' => [
                    'mrim_state' => $this->mrimClient->getState(),
                    'contacts'   => array_values($this->mrimClient->getContacts()),
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
     * Decode RFC 6455 WebSocket frame from browser
     */
    private function processWebSocketFrame(int $id, string $data): void
    {
        $bytes = array_values(unpack('C*', $data));
        if (count($bytes) < 6) {
            return;
        }

        $firstByte = $bytes[0];
        $opcode = $firstByte & 0x0F;

        // Close control frame
        if ($opcode === 0x08) {
            $this->closeClient($id);
            return;
        }

        // Ping control frame -> send Pong
        if ($opcode === 0x09) {
            return;
        }

        $secondByte = $bytes[1];
        $isMasked = ($secondByte & 0x80) !== 0;
        $payloadLen = $secondByte & 0x7F;

        $offset = 2;
        if ($payloadLen === 126) {
            $payloadLen = ($bytes[2] << 8) + $bytes[3];
            $offset = 4;
        } elseif ($payloadLen === 127) {
            $offset = 10; // Skip 64-bit int calculation for short JSON messages
        }

        if (!$isMasked || count($bytes) < $offset + 4) {
            return;
        }

        $mask = array_slice($bytes, $offset, 4);
        $offset += 4;

        $payloadBytes = array_slice($bytes, $offset, $payloadLen);
        $unmasked = '';
        foreach ($payloadBytes as $i => $b) {
            $unmasked .= chr($b ^ $mask[$i % 4]);
        }

        $command = json_decode($unmasked, true);
        if (is_array($command)) {
            $this->handleBrowserCommand($id, $command);
        }
    }

    /**
     * Handle actions sent from JavaScript in browser
     */
    private function handleBrowserCommand(int $clientId, array $command): void
    {
        $action = $command['action'] ?? '';

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

                $this->broadcastJson(['type' => 'status_log', 'data' => ['message' => "Connecting to MRIM as $email..."]]);
                $this->mrimClient->connect($email, $password, $status);
                break;

            case 'send_message':
                $to = trim($command['to'] ?? '');
                $text = (string) ($command['text'] ?? '');
                if ($to !== '' && $text !== '') {
                    $sent = $this->mrimClient->sendMessage($to, $text);
                    if ($sent) {
                        // Echo sent message back to browser UI
                        $this->broadcastJson([
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

            case 'reconnect':
                $this->mrimClient->reconnect();
                break;

            case 'logout':
                $this->mrimClient->disconnect();
                $this->broadcastJson(['type' => 'logout', 'data' => ['reason' => 'User requested logout']]);
                break;

            case 'ping':
                $this->mrimClient->sendPing();
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

        $json = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
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
     * Broadcast a JSON event to all connected WebSocket clients
     */
    private function broadcastJson(array $payload): void
    {
        foreach (array_keys($this->clients) as $id) {
            $this->sendJsonToClient($id, $payload);
        }
    }

    /**
     * Close browser socket connection
     */
    private function closeClient(int $id): void
    {
        if (isset($this->clients[$id])) {
            @fclose($this->clients[$id]['socket']);
            unset($this->clients[$id]);
        }
    }
}

// Entry point: Start WebSocket server when executed from command line
if (php_sapi_name() === 'cli' && basename(__FILE__) === basename($_SERVER['SCRIPT_FILENAME'] ?? '')) {
    $config = require __DIR__ . '/../config.php';
    $server = new MRIMWebServer($config);
    $server->start('0.0.0.0', $config['server_port']);
}
