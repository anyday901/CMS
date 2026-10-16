<?php

namespace App\Domains\Registrars;

use App\Domains\DomainContact;
use App\Domains\RegistrarError;
use Carbon\CarbonImmutable;
use DOMDocument;
use DOMElement;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;

/** Calls to the OpenSRS XML API: signed OPS envelopes over HTTPS, and the forms it wants values in. */
final class OpenSrsApi
{
    private const LIVE = 'https://rr-n1-tor.opensrs.net:55443';

    private const TEST = 'https://horizon.opensrs.net:55443';

    /**
     * Sends one command and returns the reply as an array.
     *
     * @param  list<string>  $accept  Response codes that aren't errors although is_success is 0.
     */
    public static function call(string $action, string $object, array $attributes, array $credentials, array $accept = []): array
    {
        $xml = self::encode(['protocol' => 'XCP', 'action' => $action, 'object' => $object, 'attributes' => $attributes]);
        $key = (string) ($credentials['api_key'] ?? '');

        try {
            $response = Http::timeout(30)->withHeaders([
                'X-Username' => (string) ($credentials['username'] ?? ''),
                'X-Signature' => self::signature($xml, $key),
            ])->withBody($xml, 'text/xml')->post(! empty($credentials['test_mode']) ? self::TEST : self::LIVE);
        } catch (ConnectionException $e) {
            throw new RegistrarError("OpenSRS couldn't be reached: {$e->getMessage()}");
        }

        if (! $response->successful()) {
            throw new RegistrarError("OpenSRS answered HTTP {$response->status()} to {$action}.");
        }

        $reply = self::decode($response->body());

        if ((string) ($reply['is_success'] ?? '0') !== '1' && ! in_array((string) ($reply['response_code'] ?? ''), $accept, true)) {
            throw new RegistrarError("OpenSRS refused {$action}: ".($reply['response_text'] ?? 'no reason given'));
        }

        return $reply;
    }

    public static function date(?string $value): ?CarbonImmutable
    {
        return blank($value) ? null : CarbonImmutable::parse(substr((string) $value, 0, 10));
    }

    public static function contact(DomainContact $contact): array
    {
        return [
            'first_name' => $contact->firstName,
            'last_name' => $contact->lastName,
            'org_name' => $contact->company ?: trim("{$contact->firstName} {$contact->lastName}"),
            'address1' => $contact->address1,
            'address2' => $contact->address2,
            'city' => $contact->city,
            'state' => $contact->state,
            'postal_code' => $contact->postcode,
            'country' => $contact->country,
            'phone' => $contact->registrarPhone(),
            'email' => $contact->email,
        ];
    }

    /**
     * The X-Signature header OpenSRS requires: md5(md5(body + key) + key).
     * The algorithm is fixed by the OpenSRS API; it authenticates the request
     * over HTTPS and doesn't store or protect any secret.
     */
    public static function signature(string $body, string $key): string
    {
        return md5(md5($body.$key).$key); // NOSONAR: the OpenSRS API mandates this signature scheme.
    }

    /** The OPS envelope around a data block. */
    public static function encode(array $data): string
    {
        $dom = new DOMDocument('1.0', 'UTF-8');
        $envelope = $dom->appendChild($dom->createElement('OPS_envelope'));
        $header = $envelope->appendChild($dom->createElement('header'));
        $header->appendChild($dom->createElement('version', '0.9'));
        $block = $envelope->appendChild($dom->createElement('body'))->appendChild($dom->createElement('data_block'));
        $block->appendChild(self::encodeValue($dom, $data));

        return "<?xml version='1.0' encoding='UTF-8' standalone='no' ?>\n<!DOCTYPE OPS_envelope SYSTEM 'ops.dtd'>\n".$dom->saveXML($envelope);
    }

    private static function encodeValue(DOMDocument $dom, array $value): DOMElement
    {
        $node = $dom->createElement(array_is_list($value) && $value !== [] ? 'dt_array' : 'dt_assoc');
        foreach ($value as $key => $item) {
            $child = $dom->createElement('item');
            $child->setAttribute('key', (string) $key);
            if (is_array($item)) {
                $child->appendChild(self::encodeValue($dom, $item));
            } else {
                $child->appendChild($dom->createTextNode((string) $item));
            }
            $node->appendChild($child);
        }

        return $node;
    }

    /** The data block of an OPS reply as nested arrays. */
    public static function decode(string $body): array
    {
        $dom = new DOMDocument;
        $previous = libxml_use_internal_errors(true);
        // No network, and no entity expansion from the DOCTYPE.
        $loaded = $body !== '' && $dom->loadXML($body, LIBXML_NONET);
        libxml_clear_errors();
        libxml_use_internal_errors($previous);

        $block = $loaded ? $dom->getElementsByTagName('data_block')->item(0) : null;
        $root = $block ? self::firstElement($block) : null;

        if ($root === null) {
            throw new RegistrarError("OpenSRS sent a reply that isn't an OPS envelope.");
        }

        return (array) self::decodeValue($root);
    }

    private static function decodeValue(DOMElement $node): array|string
    {
        if (! in_array($node->nodeName, ['dt_assoc', 'dt_array'], true)) {
            return trim($node->textContent);
        }

        $value = [];
        foreach ($node->childNodes as $item) {
            if ($item instanceof DOMElement && $item->nodeName === 'item') {
                $inner = self::firstElement($item);
                $value[$item->getAttribute('key')] = $inner ? self::decodeValue($inner) : trim($item->textContent);
            }
        }

        return $value;
    }

    private static function firstElement(DOMElement $node): ?DOMElement
    {
        foreach ($node->childNodes as $child) {
            if ($child instanceof DOMElement) {
                return $child;
            }
        }

        return null;
    }
}
