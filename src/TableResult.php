<?php

declare(strict_types=1);

namespace KraenzleRitter\Skos;

/**
 * A thesaurus table read: the vocabulary, the compound equivalences it held
 * («use A, B»), and what was left out or created on the way.
 */
final readonly class TableResult
{
    /**
     * @param  list<array{term: string, use: list<string>}>  $compounds
     * @param  list<string>  $warnings
     */
    public function __construct(
        public Vocabulary $vocabulary,
        public array $compounds = [],
        public array $warnings = [],
    ) {}
}
