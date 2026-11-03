<?php

declare(strict_types=1);

namespace JmaXml;

use DOMElement;
use JmaXml\InformationBasis\Head;
use JmaXml\Internal\Document;
use JmaXml\Internal\Node;

/**
 * The control part (管理部) and header part (ヘッダ部) of a telegram, read without its body.
 *
 * Use it to sort telegrams or to tell cancellations and drills apart cheaply, or when the
 * body does not matter.
 *
 * @author shimomo
 */
final readonly class Envelope
{
    public function __construct(
        public Control $control,
        public Head $head,
    ) {
        //
    }

    public static function fromString(string $xml): self
    {
        return self::fromRoot(Document::fromString($xml));
    }

    public static function fromFile(string $path): self
    {
        return self::fromRoot(Document::fromFile($path));
    }

    private static function fromRoot(DOMElement $root): self
    {
        return new self(
            Control::fromElement(Node::one($root, Control::NS, 'Control', '/Report'), '/Report/Control'),
            Head::fromElement(Node::one($root, Head::NS, 'Head', '/Report'), '/Report/Head'),
        );
    }
}
