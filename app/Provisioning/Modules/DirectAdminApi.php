<?php

namespace App\Provisioning\Modules;

use App\Provisioning\ModuleError;

/** DirectAdmin's CMD_API commands, logged in with the password or a login key. */
final class DirectAdminApi extends ServerApi
{
    /**
     * Sends a command. DirectAdmin answers form-encoded text: "error=1" with
     * text and details on failure, otherwise the values asked for. Some
     * commands answer success with a redirect to their "location".
     *
     * @param  array<string, string>  $params
     * @return array<string, mixed>
     */
    public function call(string $command, array $params = [], string $method = 'post'): array
    {
        $request = $this->http()
            ->withOptions(['allow_redirects' => false])
            ->withBasicAuth((string) $this->server->username, (string) $this->server->password);
        $response = $method === 'get' ? $request->get($command, $params) : $request->asForm()->post($command, $params);

        if ($response->redirect()) {
            return [];
        }

        if (! $response->successful()) {
            throw $this->unexpected('DirectAdmin', $response, $command);
        }

        // A web page instead of an API answer is DirectAdmin's login form.
        if (str_starts_with(ltrim($response->body()), '<')) {
            throw new ModuleError("DirectAdmin answered {$command} with a web page instead of an API answer. Check the server's username and password.");
        }

        parse_str($response->body(), $answer);

        if (($answer['error'] ?? '0') !== '0') {
            $message = implode(': ', array_filter([trim(strip_tags((string) ($answer['text'] ?? ''))), trim(strip_tags((string) ($answer['details'] ?? '')))]));

            throw new ModuleError('DirectAdmin: '.($message !== '' ? $message : "{$command} failed."));
        }

        return $answer;
    }
}
