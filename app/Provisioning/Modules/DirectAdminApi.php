<?php

namespace App\Provisioning\Modules;

use App\Provisioning\ModuleError;

/** DirectAdmin's CMD_API commands, logged in with the password or a login key. */
final class DirectAdminApi extends ServerApi
{
    /** The user to act as, logged in as the server's admin or reseller ("admin|user"). */
    private ?string $user = null;

    /** The same login, acting as one of its users. */
    public function as(string $user): self
    {
        $api = clone $this;
        $api->user = $user;

        return $api;
    }

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
            ->withBasicAuth($this->server->username.($this->user !== null ? "|{$this->user}" : ''), (string) $this->server->password);
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
