<?php

namespace App\Domains\Registrars;

use App\Domains\RegistrarError;
use SimpleXMLElement;

/** Reads registrars' XML replies safely: no external entities, default namespaces dropped. */
trait XmlReplies
{
    protected function xml(string $body, string $registrar): SimpleXMLElement
    {
        // A default namespace would hide every element from plain XPath.
        $body = preg_replace('/\sxmlns="[^"]*"/', '', $body, 1);
        $previous = libxml_use_internal_errors(true);
        $xml = simplexml_load_string((string) $body, SimpleXMLElement::class, LIBXML_NONET);
        libxml_clear_errors();
        libxml_use_internal_errors($previous);

        if ($xml === false) {
            throw new RegistrarError("{$registrar} sent a reply that isn't XML.");
        }

        return $xml;
    }

    /** The text of the first element at an XPath, or null. */
    protected function text(SimpleXMLElement $xml, string $path): ?string
    {
        $found = $xml->xpath($path);

        return $found ? trim((string) $found[0]) : null;
    }
}
