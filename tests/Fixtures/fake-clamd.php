<?php

declare(strict_types=1);

/*
 * A clamd stand-in, just enough of it.
 *
 * Speaks the part of INSTREAM the scanner uses: accept a connection, swallow the
 * command and the length-prefixed chunks, then answer one line. Which line is
 * decided by whether the bytes contained the EICAR marker -- the same string
 * every real scanner is required to recognise.
 *
 * Run as its own process, because the scanner blocks on connect and read and a
 * server in the same process would deadlock against it.
 *
 * Usage: php fake-clamd.php <socket-path> [infected|clean|garbage]
 */
$socketPath = $argv[1] ?? null;
$mode = $argv[2] ?? 'clean';

if ($socketPath === null) {
    exit(1);
}

@unlink($socketPath);

$server = @stream_socket_server('unix://'.$socketPath, $code, $message);

if ($server === false) {
    fwrite(STDERR, "cannot listen: $message\n");
    exit(1);
}

// Tells the test the socket is ready; without it the test races the server.
echo "ready\n";
flush();

$client = @stream_socket_accept($server, 10);

if ($client === false) {
    exit(1);
}

stream_set_timeout($client, 2);
$received = '';

// Read until the four zero bytes that end an INSTREAM, or until the peer
// stops talking.
while (! feof($client)) {
    $chunk = fread($client, 8192);

    if ($chunk === false || $chunk === '') {
        break;
    }

    $received .= $chunk;

    if (str_ends_with($received, "\0\0\0\0")) {
        break;
    }
}

$response = match (true) {
    $mode === 'garbage' => "something went sideways\0",
    str_contains($received, 'EICAR-STANDARD-ANTIVIRUS-TEST-FILE') => "stream: Eicar-Test-Signature FOUND\0",
    default => "stream: OK\0",
};

fwrite($client, $response);
fclose($client);
fclose($server);
@unlink($socketPath);
