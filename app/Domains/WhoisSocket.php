<?php

namespace App\Domains;

/** Asks a port 43 WHOIS server about a name. Swapped for a fake in tests. */
class WhoisSocket
{
    private const TIMEOUT = 10;

    private const MAX_BYTES = 65536;

    /** @throws RegistrarError when the server can't be reached. */
    public function query(string $server, string $name): string
    {
        $socket = @fsockopen($server, 43, $errorCode, $errorMessage, self::TIMEOUT);

        if ($socket === false) {
            throw new RegistrarError("The WHOIS server {$server} couldn't be reached: {$errorMessage}");
        }

        stream_set_timeout($socket, self::TIMEOUT);
        fwrite($socket, $name."\r\n");
        $reply = '';
        while (! feof($socket) && strlen($reply) < self::MAX_BYTES) {
            $chunk = fread($socket, 8192);
            if ($chunk === false || $chunk === '') {
                break;
            }
            $reply .= $chunk;
        }
        fclose($socket);

        return $reply;
    }
}
