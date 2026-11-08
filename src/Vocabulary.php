<?php

declare(strict_types=1);

namespace KraenzleRitter\Skos;

use InvalidArgumentException;

/**
 * A concept scheme with its concepts, in the order the host gave them.
 *
 * Hierarchy is read from both directions: a concept is narrower than another
 * when it names that one as broader, or that one names it as narrower. Hosts
 * that store an edge once (as Anton does) need not spell out the inverse.
 */
final class Vocabulary
{
    /** @var array<string, Concept> */
    private array $concepts = [];

    /** @var array<string, list<string>>|null child URIs per concept, inside the vocabulary */
    private ?array $children = null;

    /** @var array<string, list<string>> parent URIs per concept, inside the vocabulary */
    private array $parents = [];

    /**
     * @param  iterable<Concept>  $concepts
     */
    public function __construct(public readonly ConceptScheme $scheme, iterable $concepts = [])
    {
        foreach ($concepts as $concept) {
            if (isset($this->concepts[$concept->uri])) {
                throw new InvalidArgumentException("Concept «{$concept->uri}» appears twice.");
            }
            $this->concepts[$concept->uri] = $concept;
        }
    }

    /**
     * @return array<string, Concept>
     */
    public function concepts(): array
    {
        return $this->concepts;
    }

    public function has(string $uri): bool
    {
        return isset($this->concepts[$uri]);
    }

    public function get(string $uri): ?Concept
    {
        return $this->concepts[$uri] ?? null;
    }

    public function count(): int
    {
        return count($this->concepts);
    }

    /**
     * The URIs of the concepts directly below one, inside the vocabulary.
     *
     * @return list<string>
     */
    public function childrenOf(string $uri): array
    {
        return $this->children()[$uri] ?? [];
    }

    /**
     * The URIs of the concepts directly above one, inside the vocabulary.
     *
     * @return list<string>
     */
    public function parentsOf(string $uri): array
    {
        $this->children();

        return $this->parents[$uri] ?? [];
    }

    /**
     * Concepts with nothing above them inside the vocabulary and no broader
     * concept outside it either: the scheme's top concepts.
     *
     * @return list<string>
     */
    public function topConcepts(): array
    {
        $top = [];
        foreach ($this->concepts as $uri => $concept) {
            if ($concept->broader === [] && $this->parentsOf($uri) === []) {
                $top[] = $uri;
            }
        }

        return $top;
    }

    /**
     * A concept and everything below it, as a vocabulary of the same scheme.
     * Links leaving the subtree stay as they are: they name concepts that
     * exist, only not in this file.
     */
    public function subtree(string $rootUri): self
    {
        if (! $this->has($rootUri)) {
            throw new InvalidArgumentException("Concept «{$rootUri}» is not in the vocabulary.");
        }

        $seen = [$rootUri => true];
        $queue = [$rootUri];
        while ($queue !== []) {
            foreach ($this->childrenOf(array_shift($queue)) as $child) {
                if (! isset($seen[$child])) {
                    $seen[$child] = true;
                    $queue[] = $child;
                }
            }
        }

        return new self($this->scheme, array_filter(
            $this->concepts,
            fn (Concept $concept): bool => isset($seen[$concept->uri]),
        ));
    }

    /**
     * @return array<string, list<string>>
     */
    private function children(): array
    {
        if ($this->children !== null) {
            return $this->children;
        }

        $children = [];
        $this->parents = [];
        $add = function (string $parent, string $child) use (&$children): void {
            if (isset($this->concepts[$parent], $this->concepts[$child]) && ! isset($children[$parent][$child])) {
                $children[$parent][$child] = $child;
                $this->parents[$child][] = $parent;
            }
        };

        foreach ($this->concepts as $uri => $concept) {
            foreach ($concept->broader as $parent) {
                $add($parent, $uri);
            }
            foreach ($concept->narrower as $child) {
                $add($uri, $child);
            }
        }

        return $this->children = array_map(array_values(...), $children);
    }
}
