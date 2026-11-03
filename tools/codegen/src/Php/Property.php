<?php

declare(strict_types=1);

namespace JmaXml\Codegen\Php;

use JmaXml\Codegen\Dictionary\Description;

/**
 * One promoted constructor property of a generated class, with the expression that fills it in fromElement().
 *
 * @author shimomo
 */
final readonly class Property
{
    /**
     * @param string $type declared PHP type
     * @param string|null $docType docblock type, when it says more than the declared type
     */
    public function __construct(
        public string $name,
        public string $type,
        public ?string $docType,
        public string $expression,
        public ?Description $description,
    ) {
        //
    }
}
