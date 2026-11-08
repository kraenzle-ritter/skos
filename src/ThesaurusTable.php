<?php

declare(strict_types=1);

namespace KraenzleRitter\Skos;

/**
 * A thesaurus kept as a table, read into a vocabulary: one row per term,
 * preferred terms and non-preferred ones («use …») alike.
 *
 * Columns (keys of each row, already mapped by the caller):
 * - `term`       the term
 * - `broader`    its broader terms
 * - `use_for`    names it is used for (non-preferred terms, as on the term)
 * - `use`        on a non-preferred term: the preferred term(s) to use
 * - `related`    related terms
 * - `notation`   its notation
 * - `definition` a definition, in the table's language
 *
 * Several terms in one cell are separated by `;`. A comma separates them
 * only where the whole cell is not a term itself and every part is: names
 * like «Arbeit, Industrie, Wirtschaft» stay one term. A non-preferred term
 * becomes an alternative label of the term it points to; one that points to
 * two at once («use A, B», a compound equivalence) becomes one on each and is
 * reported. A broader term without a row of its own is created and reported.
 *
 * Concept URIs are built from the scheme URI and the term, so the same table
 * read again yields the same URIs.
 */
final class ThesaurusTable
{
    /** @var array<string, array{label: string, alt: list<string>, broader: list<string>, related: list<string>, notations: list<string>, definition: ?string}> keyed by folded label */
    private array $terms = [];

    /** @var list<string> */
    private array $warnings = [];

    /** @var list<array{term: string, use: list<string>}> */
    private array $compounds = [];

    public function __construct(
        private readonly ConceptScheme $scheme,
        private readonly string $language = 'de',
    ) {}

    /**
     * @param  iterable<array<string, mixed>>  $rows
     */
    public function read(iterable $rows): TableResult
    {
        $this->terms = [];
        $this->warnings = [];
        $this->compounds = [];

        $preferred = [];
        $nonPreferred = [];
        foreach ($rows as $number => $row) {
            $term = $this->cell($row, 'term');
            if ($term === '') {
                continue;
            }
            if ($this->cell($row, 'use') !== '') {
                $nonPreferred[] = [$term, $row, $number];
            } else {
                $preferred[] = [$term, $row];
                $this->ensure($term);
            }
        }

        foreach ($preferred as [$term, $row]) {
            $key = $this->fold($term);
            foreach ($this->split($this->cell($row, 'broader')) as $parent) {
                if (! isset($this->terms[$this->fold($parent)])) {
                    $this->warnings[] = "«{$parent}», broader term of «{$term}», has no row of its own and was created.";
                    $this->ensure($parent);
                }
                $this->terms[$key]['broader'][] = $this->fold($parent);
            }
            foreach ($this->split($this->cell($row, 'related')) as $other) {
                if (isset($this->terms[$this->fold($other)])) {
                    $this->terms[$key]['related'][] = $this->fold($other);
                } else {
                    $this->warnings[] = "«{$other}», related to «{$term}», is not a term of the table; left out.";
                }
            }
            foreach ($this->split($this->cell($row, 'use_for')) as $name) {
                $this->terms[$key]['alt'][] = $name;
            }
            $notation = $this->cell($row, 'notation');
            if ($notation !== '') {
                $this->terms[$key]['notations'][] = $notation;
            }
            $definition = $this->cell($row, 'definition');
            if ($definition !== '') {
                $this->terms[$key]['definition'] = $definition;
            }
        }

        foreach ($nonPreferred as [$term, $row, $number]) {
            $targets = array_values(array_filter(
                $this->split($this->cell($row, 'use')),
                function (string $target) use ($term): bool {
                    if (isset($this->terms[$this->fold($target)])) {
                        return true;
                    }
                    $this->warnings[] = "«{$term}» points to «{$target}», which is not a term of the table; left out.";

                    return false;
                },
            ));
            foreach ($targets as $target) {
                $this->terms[$this->fold($target)]['alt'][] = $term;
            }
            if (count($targets) > 1) {
                $this->compounds[] = ['term' => $term, 'use' => $targets];
            }
        }

        $concepts = [];
        foreach ($this->terms as $entry) {
            $concepts[] = new Concept(
                $this->uriOf($entry['label']),
                prefLabels: [$this->language => $entry['label']],
                altLabels: [$this->language => $entry['alt']],
                notations: $entry['notations'],
                definitions: $entry['definition'] === null ? [] : [$this->language => $entry['definition']],
                broader: array_map(fn (string $key): string => $this->uriOf($this->terms[$key]['label']), $entry['broader']),
                related: array_map(fn (string $key): string => $this->uriOf($this->terms[$key]['label']), $entry['related']),
                inSchemes: [$this->scheme->uri],
            );
        }

        return new TableResult(new Vocabulary($this->scheme, $concepts), $this->compounds, $this->warnings);
    }

    /**
     * The URI a term of this table carries: stable across readings.
     */
    public function uriOf(string $term): string
    {
        $base = $this->scheme->uri;
        $separator = str_ends_with($base, '/') || str_ends_with($base, '#') ? '' : '#';

        return $base.$separator.rawurlencode($this->fold($term));
    }

    private function ensure(string $term): void
    {
        $key = $this->fold($term);
        $this->terms[$key] ??= ['label' => $term, 'alt' => [], 'broader' => [], 'related' => [], 'notations' => [], 'definition' => null];
    }

    /**
     * Terms in a cell: split at `;`, and a part at commas only where it is no
     * term itself and every comma-separated piece is one.
     *
     * @return list<string>
     */
    private function split(string $cell): array
    {
        $terms = [];
        foreach (explode(';', $cell) as $part) {
            $part = trim($part);
            if ($part === '') {
                continue;
            }
            $pieces = array_values(array_filter(array_map('trim', explode(',', $part)), fn (string $p): bool => $p !== ''));
            $allKnown = count($pieces) > 1 && array_reduce($pieces, fn (bool $known, string $p): bool => $known && isset($this->terms[$this->fold($p)]), true);
            if (! isset($this->terms[$this->fold($part)]) && $allKnown) {
                array_push($terms, ...$pieces);
            } else {
                $terms[] = $part;
            }
        }

        return $terms;
    }

    /**
     * @param  array<string, mixed>  $row
     */
    private function cell(array $row, string $column): string
    {
        $value = $row[$column] ?? '';

        return is_scalar($value) ? trim((string) $value) : '';
    }

    private function fold(string $term): string
    {
        return mb_strtolower(trim(preg_replace('/\s+/u', ' ', $term) ?? $term));
    }
}
