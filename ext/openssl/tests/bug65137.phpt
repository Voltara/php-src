--TEST--
#65137, openssl stream_select buffering, blocking, infinite loop
--SKIPIF--
<?php
if (!extension_loaded("openssl")) die("skip openssl not loaded");
if (!function_exists("proc_open")) die("skip no proc_open");
--FILE--
<?php
$serverCode = <<<'CODE'
    $serverUri = "ssl://127.0.0.1:64321";
    $serverFlags = STREAM_SERVER_BIND | STREAM_SERVER_LISTEN;
    $serverCtx = stream_context_create(['ssl' => [
        'local_cert' => __DIR__ . '/bug46127.pem',
    ]]);

    $sock = stream_socket_server($serverUri, $errno, $errstr, $serverFlags, $serverCtx);
    phpt_notify();
    $link = stream_socket_accept($sock, 2);
    fwrite($link, "Chunk#1\nChunk#2\nChunk#3\n");

    /* Need to wait here to avoid closing the socket, which creates
     * additional unwanted activity.
     */
    phpt_wait();
CODE;

$clientCode = <<<'CODE'
    $serverUri = "ssl://127.0.0.1:64321";
    $clientFlags = STREAM_CLIENT_CONNECT;

    $clientCtx = stream_context_create(['ssl' => [
        'verify_peer' => false,
        'verify_peer_name' => false,
    ]]);

    phpt_wait();
    $sock = stream_socket_client($serverUri, $errno, $errstr, 2, $clientFlags, $clientCtx);

    /* Set a small chunk size, then read one complete chunk.
     * OpenSSL will buffer the entire string sent to us by the server;
     * the PHP stream layer will consume 8 bytes of it; we then
     * consume all 8 of those bytes.  The result is PHP's buffer
     * is empty, but OpenSSL's is not.
     */
    stream_set_chunk_size($sock, 8);
    echo fread($sock, 8);

    /* PHP 5.5.17 gets stuck in an infinite loop if there is more than
     * one full chunk of data pending; this time limit breaks out of the
     * loop and forces test failure.
     */
    set_time_limit(1);

    echo "stream_select()\n";
    $r = array($sock);
    $w = $e = null;
    $n = stream_select($r, $w, $e, 0);

    /* Because there's data available to read, stream_select should
     * have indicated activity on the socket
     */
    if ($n === 1 && $r[0] === $sock) {
        echo fread($sock, 16);
    } else {
        die("no data available for immediate read");
    }

    phpt_notify();
CODE;

include 'ServerClientTestCase.inc';
ServerClientTestCase::getInstance()->run($clientCode, $serverCode);
--EXPECT--
Chunk#1
stream_select()
Chunk#2
Chunk#3
