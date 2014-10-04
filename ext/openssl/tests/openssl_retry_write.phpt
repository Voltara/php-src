--TEST--
SSL_R_BAD_WRITE_RETRY avoidance
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
    /* Accept but don't read. */
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

    stream_set_blocking($sock, 0);

    /* Flood the socket with as much data as possible (up to a limit, just in case)
     * to overflow the TCP send buffer.  The SO_SNDBUF socket option would make this
     * condition easier to set up.
     */
    $buf = str_repeat("0", 65536);
    for ($i = 0; $i < 256; $i++) {
        if (fwrite($sock, $buf) < 1) {
	    break;
	}
    }

    /* Write once more, this time from a different memory location */
    fwrite($sock, "a different location in memory");

    phpt_notify();

    echo "Done writing\n";
CODE;

include 'ServerClientTestCase.inc';
ServerClientTestCase::getInstance()->run($clientCode, $serverCode);
--EXPECT--
Done writing
