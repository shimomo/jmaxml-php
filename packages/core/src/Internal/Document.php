<?php

declare(strict_types=1);

namespace JmaXml\Internal;

use DOMDocument;
use DOMElement;
use JmaXml\Exception\ParseException;
use LibXMLError;

/**
 * Loads a telegram into a DOM tree and returns its <Report> root element.
 *
 * @internal
 *
 * @author shimomo
 */
final class Document
{
    public const string REPORT_NAMESPACE = 'http://xml.kishou.go.jp/jmaxml1/';

    public static function fromString(string $xml): DOMElement
    {
        if (trim($xml) === '') {
            throw new ParseException('Empty document', '/');
        }

        // Errors the caller had already collected are left alone: only the ones raised while loading are looked at,
        // and the buffer is cleared afterwards only if the caller was not collecting errors itself.
        $previous = libxml_use_internal_errors(true);
        $alreadyPending = count(libxml_get_errors());

        try {
            $document = new DOMDocument();
            $loaded = $document->loadXML($xml, LIBXML_NONET);
            $error = self::firstError(array_slice(libxml_get_errors(), $alreadyPending));
        } finally {
            if (!$previous) {
                libxml_clear_errors();
            }

            libxml_use_internal_errors($previous);
        }

        if (!$loaded || $error !== null) {
            throw new ParseException('Malformed XML: ' . ($error !== null ? trim($error->message) : 'unknown error'), '/');
        }

        if ($document->doctype !== null) {
            throw new ParseException('DOCTYPE is not allowed', '/');
        }

        $root = $document->documentElement;

        if (!$root instanceof DOMElement || $root->localName !== 'Report' || $root->namespaceURI !== self::REPORT_NAMESPACE) {
            throw new ParseException('Root element must be Report in ' . self::REPORT_NAMESPACE, '/');
        }

        return $root;
    }

    public static function fromFile(string $path): DOMElement
    {
        set_error_handler(static fn(): bool => true);

        try {
            $xml = is_file($path) && is_readable($path) ? file_get_contents($path) : false;
        } finally {
            restore_error_handler();
        }

        if ($xml === false) {
            throw new ParseException('Cannot read file ' . $path, '/');
        }

        return self::fromString($xml);
    }

    /**
     * @param array<LibXMLError> $errors
     */
    private static function firstError(array $errors): ?LibXMLError
    {
        foreach ($errors as $error) {
            if ($error->level >= LIBXML_ERR_ERROR) {
                return $error;
            }
        }

        return null;
    }
}
