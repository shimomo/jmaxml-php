<?php

declare(strict_types=1);

namespace JmaXml\Feed;

use DateTimeImmutable;
use DOMDocument;
use DOMElement;
use JmaXml\Feed\Exception\FeedException;
use JmaXml\Feed\Internal\W3cDateTime;
use LibXMLError;

/**
 * A JMA Atom feed document.
 *
 * @author shimomo
 */
final readonly class AtomFeed
{
    private const string ATOM_NAMESPACE = 'http://www.w3.org/2005/Atom';

    /**
     * @param list<Entry> $entries in feed order
     */
    public function __construct(
        public string $id,
        public DateTimeImmutable $updated,
        public array $entries,
    ) {
        //
    }

    public static function fromString(string $xml, string $url): self
    {
        $root = self::load($xml, $url);
        $entries = [];

        foreach (self::children($root, 'entry') as $index => $entry) {
            $link = self::children($entry, 'link')[0] ?? null;
            $href = $link?->getAttribute('href') ?? '';

            if ($href === '') {
                throw new FeedException('Entry ' . ($index + 1) . ' has no link', $url);
            }

            $entries[] = new Entry(
                self::requiredText($entry, 'id', $url),
                self::text($entry, 'title'),
                self::dateTime($entry, $url),
                self::text(self::children($entry, 'author')[0] ?? null, 'name'),
                $href,
                self::text($entry, 'content'),
                TelegramFileName::fromUrl($href),
            );
        }

        return new self(self::requiredText($root, 'id', $url), self::dateTime($root, $url), $entries);
    }

    private static function load(string $xml, string $url): DOMElement
    {
        if (trim($xml) === '') {
            throw new FeedException('Empty feed', $url);
        }

        $previous = libxml_use_internal_errors(true);
        libxml_clear_errors();

        try {
            $document = new DOMDocument();
            $loaded = $document->loadXML($xml, LIBXML_NONET);
            $error = self::firstError(libxml_get_errors());
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($previous);
        }

        if (!$loaded || $error !== null) {
            throw new FeedException('Malformed feed: ' . ($error !== null ? trim($error->message) : 'unknown error'), $url);
        }

        if ($document->doctype !== null) {
            throw new FeedException('DOCTYPE is not allowed in a feed', $url);
        }

        $root = $document->documentElement;

        if (!$root instanceof DOMElement || $root->localName !== 'feed' || $root->namespaceURI !== self::ATOM_NAMESPACE) {
            throw new FeedException('Not an Atom feed', $url);
        }

        return $root;
    }

    /**
     * @return list<DOMElement>
     */
    private static function children(DOMElement $parent, string $localName): array
    {
        $children = [];

        foreach ($parent->childNodes as $child) {
            if ($child instanceof DOMElement && $child->localName === $localName && $child->namespaceURI === self::ATOM_NAMESPACE) {
                $children[] = $child;
            }
        }

        return $children;
    }

    private static function text(?DOMElement $parent, string $localName): string
    {
        $element = $parent === null ? null : (self::children($parent, $localName)[0] ?? null);

        return $element === null ? '' : trim($element->textContent);
    }

    private static function requiredText(DOMElement $parent, string $localName, string $url): string
    {
        $text = self::text($parent, $localName);

        if ($text === '') {
            throw new FeedException('Missing <' . $localName . '> in <' . $parent->localName . '>', $url);
        }

        return $text;
    }

    private static function dateTime(DOMElement $parent, string $url): DateTimeImmutable
    {
        $text = self::requiredText($parent, 'updated', $url);

        return W3cDateTime::parse($text) ?? throw new FeedException('Invalid <updated> "' . $text . '"', $url);
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
