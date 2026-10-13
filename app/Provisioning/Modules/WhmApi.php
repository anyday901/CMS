<?php

namespace App\Provisioning\Modules;

use App\Provisioning\ModuleError;

/**
 * WHM API 1 (/json-api), logged in with an API token, or the password when
 * the server has no token.
 */
final class WhmApi extends ServerApi
{
    private const WHM = 'WHM';

    /**
     * Calls a function. Parameters are posted, so passwords stay out of the
     * server's access log.
     *
     * @param  array<string, mixed>  $params
     * @return array<string, mixed> the reply's data
     */
    public function call(string $function, array $params = []): array
    {
        $request = $this->http()->acceptJson()->asForm();
        $request = filled($this->server->api_token)
            ? $request->withHeaders(['Authorization' => "whm {$this->server->username}:{$this->server->api_token}"])
            : $request->withBasicAuth((string) $this->server->username, (string) $this->server->password);

        $response = $request->post("json-api/{$function}?api.version=1", $params);
        $reason = $response->json('metadata.reason');

        if ($response->successful() && (int) $response->json('metadata.result') === 1) {
            return (array) $response->json('data', []);
        }

        throw is_string($reason) && $reason !== '' ? new ModuleError(self::WHM.": {$reason}") : $this->unexpected(self::WHM, $response, $function);
    }
}
