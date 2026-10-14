<?php

namespace App\Monitoring;

/** Opens a TCP connection to see whether a port answers. Swapped for a fake in tests. */
class PortProbe
{
    /** Milliseconds the connection took, or null when it failed within $timeout seconds. */
    public function open(string $host, int $port, float $timeout = 5.0): ?int
    {
        $target = filter_var($host, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6) !== false ? "[{$host}]" : $host;
        $started = hrtime(true);
        $socket = @fsockopen("tcp://{$target}", $port, $code, $message, $timeout);

        if ($socket === false) {
            return null;
        }

        fclose($socket);

        return (int) round((hrtime(true) - $started) / 1_000_000);
    }
}
