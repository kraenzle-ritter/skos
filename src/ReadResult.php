<?php

declare(strict_types=1);

namespace KraenzleRitter\Skos;

use InvalidArgumentException;

/**
 * What a SKOS file holds: its concept schemes, its concepts, and what the
 * reader had to leave out or bend, for a report.
 */
final readonly class ReadResult
{
    /**
     * @param  list<ConceptScheme>  $schemes
     * @param  list<Concept>  $concepts
     * @param  list<string>  $warnings
     */
    public function __construct(
        public array $schemes,
        public array $concepts,
        public array $warnings = [],
    ) {}

    /**
     * The concepts of one scheme as a vocabulary.
     *
     * Without a URI the file must hold at most one scheme. A file without
     * any scheme needs the scheme from the caller: SKOS allows concepts that
     * belong to none, and a thesaurus exported carelessly often has them.
     * Concepts that name no scheme at all belong to the only one there is.
     */
    public function vocabulary(?string $schemeUri = null, ?ConceptScheme $fallback = null): Vocabulary
    {
        $scheme = $this->scheme($schemeUri) ?? $fallback;
        if ($scheme === null) {
            throw new InvalidArgumentException(match (true) {
                $schemeUri !== null => "The file holds no concept scheme «{$schemeUri}».",
                $this->schemes === [] => 'The file holds no concept scheme; name one.',
                default => 'The file holds several concept schemes; choose one: '.implode(', ', array_map(fn (ConceptScheme $s): string => $s->uri, $this->schemes)),
            });
        }

        $alone = count($this->schemes) <= 1;
        $concepts = array_filter(
            $this->concepts,
            fn (Concept $concept): bool => in_array($scheme->uri, [...$concept->inSchemes, ...$concept->topConceptOf], true)
                || ($alone && $concept->inSchemes === [] && $concept->topConceptOf === []),
        );

        return new Vocabulary($scheme, $concepts);
    }

    private function scheme(?string $uri): ?ConceptScheme
    {
        foreach ($this->schemes as $scheme) {
            if ($uri === null ? count($this->schemes) === 1 : $scheme->uri === $uri) {
                return $scheme;
            }
        }

        return null;
    }
}
