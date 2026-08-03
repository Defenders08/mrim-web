<?php
/**
 * Configuration for MRIM Web Client
 *
 * Mail.Ru Instant Messenger (MRIM) protocol client
 * connecting to mrim.su server.
 */

return [
    // MRIM Dispatcher address (host and port)
    // Connecting here returns the IP:port of a specific MRIM server node
    'mrim_dispatcher_host' => 'mrim.su',
    'mrim_dispatcher_port' => 2042,

    // Web / WebSocket server configuration
    // Port 3000 is required by AI Studio environment
    'server_host' => '0.0.0.0',
    'server_port' => 3000,

    // MRIM client identification string sent during CS_LOGIN2
    'mrim_client_name' => 'client="mrim-web-client 1.0"',

    // Default Ping interval in seconds (will also obey server-provided ping period)
    'ping_interval' => 30,

    // Debug logging
    'debug' => true,
];
