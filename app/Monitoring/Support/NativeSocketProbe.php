<?php

namespace App\Monitoring\Support;

use App\Monitoring\Contracts\SocketProbe;

class NativeSocketProbe implements SocketProbe
{
    public function connect(string $hostname, int $port, int $timeoutSeconds): array
    {
        $errno = 0;
        $error = '';
        $socket = @stream_socket_client("tcp://{$hostname}:{$port}", $errno, $error, $timeoutSeconds);

        if ($socket !== false) {
            fclose($socket);

            return ['success' => true, 'error_type' => null, 'error_message' => null];
        }

        $message = strtolower($error);
        $type = str_contains($message, 'refused') ? 'connection_refused' : (str_contains($message, 'timed out') ? 'timeout' : 'connection_error');

        return ['success' => false, 'error_type' => $type, 'error_message' => 'TCP connection could not be established.'];
    }
}
