<?php

declare(strict_types=1);

use Connectrpc\Conformance\V1\ServerCompatRequest;
use Connectrpc\Conformance\V1\ServerCompatResponse;

require __DIR__ . '/vendor/autoload.php';

function readExactly(int $length): string
{
    $data = '';
    while (\strlen($data) < $length) {
        $chunk = fread(STDIN, $length - \strlen($data));
        if ($chunk === false || $chunk === '') {
            throw new RuntimeException('stdin closed before the request was read');
        }
        $data .= $chunk;
    }

    return $data;
}

$length = unpack('N', readExactly(4));
$request = new ServerCompatRequest();
$request->mergeFromString(readExactly($length[1]));

// php -S workers outlive their master, so the whole group is stopped on SIGTERM.
posix_setpgid(0, 0);

$probe = stream_socket_server('tcp://127.0.0.1:0');
if ($probe === false) {
    throw new RuntimeException('cannot bind a free port');
}
$port = (int) substr((string) strrchr((string) stream_socket_get_name($probe, false), ':'), 1);
fclose($probe);

$child = proc_open(
    [PHP_BINARY, '-q', '-d', 'expose_php=0', '-d', 'default_mimetype=', '-d', 'post_max_size=0', '-S', '127.0.0.1:' . $port, '-t', __DIR__, __DIR__ . '/router.php'],
    [0 => ['file', '/dev/null', 'r'], 1 => STDERR, 2 => STDERR],
    $pipes,
    null,
    [...getenv(), 'PHP_CLI_SERVER_WORKERS' => '4', 'CONFORMANCE_MESSAGE_RECEIVE_LIMIT' => (string) $request->getMessageReceiveLimit()],
);
if ($child === false) {
    throw new RuntimeException('cannot start php -S');
}

// The runner closes stdin after the request and stops the server with SIGTERM.
pcntl_async_signals(true);
pcntl_signal(SIGTERM, static function () use ($child): never {
    pcntl_signal(SIGTERM, SIG_IGN);
    posix_kill(0, SIGTERM);
    proc_close($child);
    exit(0);
});

$deadline = microtime(true) + 10;
while (($socket = @fsockopen('127.0.0.1', $port, $errno, $errstr, 0.1)) === false) {
    if (microtime(true) > $deadline) {
        proc_terminate($child);
        throw new RuntimeException('php -S did not start listening');
    }
    usleep(50_000);
}
fclose($socket);

$response = new ServerCompatResponse(['host' => '127.0.0.1', 'port' => $port])->serializeToString();
fwrite(STDOUT, pack('N', \strlen($response)) . $response);
fflush(STDOUT);

while (proc_get_status($child)['running']) {
    sleep(1);
}
exit(1);
