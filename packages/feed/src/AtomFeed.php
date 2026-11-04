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
     * @param list<string> $warnings entries that were skipped because they lack an id, an updated time or a link
     */
    public function __construct(
        public string $id,
        public DateTimeImmutable $updated,
        public array $entries,
        public array $warnings = [],
    ) {
        //
    }

    public static function fromString(string $xml, string $url): self
    {
        $root = self::load($xml, $url);
        $entries = [];
        $warnings = [];

        // One broken entry must not hide the others, so entries are checked one by one.
        foreach (self::children($root, 'entry') as $index => $entry) {
            $link = self::children($entry, 'link')[0] ?? null;
            $href = $link?->getAttribute('href') ?? '';
            $id = self::text($entry, 'id');
            $updated = W3cDateTime::parse(self::text($entry, 'updated'));

            if ($href === '' || $id === '' || $updated === null) {
                $warnings[] = 'Skipped entry ' . ($index + 1) . ' of ' . $url . ': it needs an id, a valid updated time and a link';

                continue;
            }

            $entries[] = new Entry(
                $id,
                self::text($entry, 'title'),
                $updated,
                self::text(self::children($entry, 'author')[0] ?? null, 'name'),
                $href,
                self::text($entry, 'content'),
                TelegramFileName::fromUrl($href),
            );
        }

        return new self(self::requiredText($root, 'id', $url), self::dateTime($root, $url), $entries, $warnings);
    }

    private static function load(string $xml, string $url): DOMElement
    {
        if (trim($xml) === '') {
            throw new FeedException('Empty feed', $url);
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
