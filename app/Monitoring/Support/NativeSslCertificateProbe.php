<?php

namespace App\Monitoring\Support;

use App\Monitoring\Contracts\SslCertificateProbe;
use RuntimeException;

class NativeSslCertificateProbe implements SslCertificateProbe
{
    public function inspect(string $hostname, int $port, int $timeoutSeconds): array
    {
        $context = stream_context_create(['ssl' => [
            'verify_peer' => true,
            'verify_peer_name' => true,
            'peer_name' => $hostname,
            'capture_peer_cert' => true,
        ]]);
        $errno = 0;
        $error = '';
        $socket = @stream_socket_client("ssl://{$hostname}:{$port}", $errno, $error, $timeoutSeconds, STREAM_CLIENT_CONNECT, $context);

        if ($socket === false) {
            throw new RuntimeException('TLS connection could not be established.');
        }

        $parameters = stream_context_get_params($socket);
        fclose($socket);
        $certificate = openssl_x509_parse($parameters['options']['ssl']['peer_certificate'] ?? null);

        if ($certificate === false) {
            throw new RuntimeException('The TLS certificate could not be read.');
        }

        return $certificate;
    }
}
