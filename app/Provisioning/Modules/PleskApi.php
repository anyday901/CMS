<?php

namespace App\Provisioning\Modules;

use App\Provisioning\ModuleError;
use DOMDocument;
use DOMNode;
use SimpleXMLElement;

/**
 * Plesk's XML API (/enterprise/control/agent.php), logged in with a secret
 * key, or the login's password when the server has no key.
 */
final class PleskApi extends ServerApi
{
    private const PLESK = 'Plesk';

    /** The subscription field naming its customer by id. */
    public const OWNER_ID = 'owner-id';

    /** Plesk's error code for an object that doesn't exist. */
    private const NOT_FOUND = '1013';

    /**
     * Sends one packet. Operations are nested arrays: a key is an element, a
     * list repeats it, and an empty array is an empty element.
     *
     * @param  array<string, mixed>  $operations
     */
    public function send(array $operations): SimpleXMLElement
    {
        $request = $this->http()->withBody($this->xml($operations), 'text/xml');
        $request = filled($this->server->api_token)
            ? $request->withHeaders(['KEY' => $this->server->api_token])
            : $request->withHeaders(['HTTP_AUTH_LOGIN' => (string) $this->server->username, 'HTTP_AUTH_PASSWD' => (string) $this->server->password]);

        $response = $request->post('enterprise/control/agent.php');
        $operation = (string) array_key_first($operations);

        if (! $response->successful()) {
            throw $this->unexpected(self::PLESK, $response, $operation);
        }

        $packet = self::parse($response->body()) ?? throw $this->unexpected(self::PLESK, $response, $operation);

        if ((string) $packet->system->status === 'error') {
            throw new ModuleError('Plesk: '.trim((string) $packet->system->errtext));
        }

        return $packet;
    }

    /** An operation's result, or an error with Plesk's message. */
    public static function result(?SimpleXMLElement $operation): SimpleXMLElement
    {
        return self::find($operation) ?? throw new ModuleError('Plesk: the object was not found.');
    }

    /** An operation's result, or null when Plesk has no such object. */
    public static function find(?SimpleXMLElement $operation): ?SimpleXMLElement
    {
        $result = $operation?->result ?? throw new ModuleError('Plesk answered without a result.');

        if ((string) $result->status === 'ok') {
            return $result;
        }

        if ((string) $result->errcode === self::NOT_FOUND) {
            return null;
        }

        throw new ModuleError('Plesk: '.(trim((string) $result->errtext) ?: 'the request failed.'));
    }

    /** Whether a subscription's details name the customer as its owner (Plesk may leave the owner out). */
    public static function ownedBy(?SimpleXMLElement $info, int $customerId, string $login): bool
    {
        return match (true) {
            $info === null => true,
            isset($info->{'owner-login'}) => (string) $info->{'owner-login'} === $login,
            isset($info->{self::OWNER_ID}) => (int) $info->{self::OWNER_ID} === $customerId,
            default => true,
        };
    }

    private static function parse(string $xml): ?SimpleXMLElement
    {
        $errors = libxml_use_internal_errors(true);
        $packet = simplexml_load_string($xml, options: LIBXML_NONET);
        libxml_clear_errors();
        libxml_use_internal_errors($errors);

        return $packet === false ? null : $packet;
    }

    /** @param  array<string, mixed>  $operations */
    private function xml(array $operations): string
    {
        $document = new DOMDocument('1.0', 'UTF-8');
        $this->append($document, $document->appendChild($document->createElement('packet')), $operations);

        return (string) $document->saveXML();
    }

    /** @param  array<string, mixed>  $children */
    private function append(DOMDocument $document, DOMNode $parent, array $children): void
    {
        foreach ($children as $name => $value) {
            foreach (is_array($value) && $value !== [] && array_is_list($value) ? $value : [$value] as $item) {
                $node = $parent->appendChild($document->createElement($name));

                if (is_array($item)) {
                    $this->append($document, $node, $item);
                } else {
                    $node->appendChild($document->createTextNode((string) $item));
                }
            }
        }
    }
}
