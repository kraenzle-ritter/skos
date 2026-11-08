<?php

declare(strict_types=1);

namespace KraenzleRitter\Skos;

/**
 * A SKOS concept scheme: the vocabulary's own URI, its title and description
 * per language (key `''` for no language).
 */
final readonly class ConceptScheme
{
    /**
     * @param  array<string, string>  $titles
     * @param  array<string, string>  $descriptions
     */
    public function __construct(
        public string $uri,
        public array $titles = [],
        public array $descriptions = [],
        public ?string $publisher = null,
    ) {
        Uri::assertAbsolute($uri);
    }
}
