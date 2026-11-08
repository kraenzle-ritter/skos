<?php

declare(strict_types=1);

namespace KraenzleRitter\Skos;

use EasyRdf\Graph;
use EasyRdf\Literal;
use EasyRdf\RdfNamespace;
use EasyRdf\Resource;
use InvalidArgumentException;

/**
 * Reads a SKOS file into concept schemes and concepts.
 *
 * Lenient where thesauri in the wild are careless, and says so in the
 * warnings: a second preferred label in one language becomes an alternative
 * one, a language tag that is not one is dropped, a relation to a blank node
 * is left out. A concept is anything typed `skos:Concept` or placed in a
 * scheme. Labels come from `skos:prefLabel`, else `rdfs:label`.
 */
final class SkosReader
{
    private const Skos = 'http://www.w3.org/2004/02/skos/core#';

    /** @var list<string> */
    private array $warnings = [];

    public function read(string $data, Format $format, ?string $baseUri = null): ReadResult
    {
        RdfNamespace::set('skos', self::Skos);
        RdfNamespace::set('dcterms', 'http://purl.org/dc/terms/');
        RdfNamespace::set('dc11', 'http://purl.org/dc/elements/1.1/');

        $this->warnings = [];
        $graph = new Graph;
        try {
            $graph->parse($data, $format->value, $baseUri);
        } catch (\Throwable $e) {
            throw new InvalidArgumentException('The file could not be read as '.$format->value.': '.$e->getMessage(), 0, $e);
        }

        $schemes = [];
        foreach ($graph->allOfType('skos:ConceptScheme') as $resource) {
            if (! $resource->isBNode()) {
                $schemes[] = $this->scheme($resource);
            }
        }

        $concepts = [];
        foreach ($this->conceptResources($graph) as $resource) {
            $concept = $this->concept($resource);
            if ($concept !== null) {
                $concepts[] = $concept;
            }
        }

        return new ReadResult($schemes, $concepts, $this->warnings);
    }

    /**
     * @return array<string, Resource>
     */
    private function conceptResources(Graph $graph): array
    {
        $found = [];
        foreach ($graph->allOfType('skos:Concept') as $resource) {
            $found[$resource->getUri()] = $resource;
        }
        foreach ($graph->resources() as $resource) {
            if (! isset($found[$resource->getUri()]) && ($resource->hasProperty('skos:inScheme') || $resource->hasProperty('skos:topConceptOf'))) {
                $found[$resource->getUri()] = $resource;
            }
        }

        return array_filter($found, fn (Resource $resource): bool => ! $resource->isBNode());
    }

    private function scheme(Resource $resource): ConceptScheme
    {
        $titles = $this->texts($resource, 'skos:prefLabel') ?: $this->texts($resource, 'dcterms:title') ?: $this->texts($resource, 'dc11:title') ?: $this->texts($resource, 'rdfs:label');
        $descriptions = $this->texts($resource, 'dcterms:description') ?: $this->texts($resource, 'dc11:description');
        $publisher = $this->texts($resource, 'dcterms:publisher') ?: $this->texts($resource, 'dc11:publisher');

        return new ConceptScheme(
            $resource->getUri(),
            titles: $titles,
            descriptions: $descriptions,
            publisher: $publisher === [] ? null : array_values($publisher)[0],
        );
    }

    private function concept(Resource $resource): ?Concept
    {
        $uri = $resource->getUri();

        $preferred = $this->lists($resource, 'skos:prefLabel') ?: $this->lists($resource, 'rdfs:label');
        $prefLabels = [];
        $altLabels = $this->lists($resource, 'skos:altLabel');
        foreach ($preferred as $language => $labels) {
            $prefLabels[$language] = $labels[0];
            $labels = array_slice($labels, 1);
            if ($labels !== []) {
                $this->warnings[] = "{$uri}: more than one preferred label in «{$language}»; the others became alternative labels.";
                $altLabels[$language] = [...($altLabels[$language] ?? []), ...$labels];
            }
        }

        try {
            return new Concept(
                $uri,
                prefLabels: $prefLabels,
                altLabels: $altLabels,
                hiddenLabels: $this->lists($resource, 'skos:hiddenLabel'),
                notations: array_merge([], ...array_values($this->lists($resource, 'skos:notation'))),
                definitions: $this->texts($resource, 'skos:definition'),
                scopeNotes: $this->texts($resource, 'skos:scopeNote'),
                broader: $this->uris($resource, 'skos:broader'),
                narrower: $this->uris($resource, 'skos:narrower'),
                related: $this->uris($resource, 'skos:related'),
                exactMatch: $this->uris($resource, 'skos:exactMatch'),
                closeMatch: $this->uris($resource, 'skos:closeMatch'),
                inSchemes: $this->uris($resource, 'skos:inScheme'),
                topConceptOf: $this->uris($resource, 'skos:topConceptOf'),
            );
        } catch (InvalidArgumentException $e) {
            $this->warnings[] = "{$uri}: left out, ".$e->getMessage();

            return null;
        }
    }

    /**
     * One text per language: the first, and a warning for the rest.
     *
     * @return array<string, string>
     */
    private function texts(Resource $resource, string $property): array
    {
        $texts = [];
        foreach ($this->lists($resource, $property) as $language => $values) {
            $texts[$language] = $values[0];
        }

        return $texts;
    }

    /**
     * @return array<string, list<string>>
     */
    private function lists(Resource $resource, string $property): array
    {
        $lists = [];
        foreach ($resource->allLiterals($property) as $literal) {
            $raw = $literal->getValue();
            $value = trim(is_scalar($raw) ? (string) $raw : ($raw instanceof \DateTimeInterface ? $raw->format(DATE_ATOM) : ''));
            if ($value === '') {
                continue;
            }
            $lists[$this->language($literal, $resource->getUri())][] = $value;
        }

        return $lists;
    }

    /**
     * @return list<string>
     */
    private function uris(Resource $resource, string $property): array
    {
        $uris = [];
        foreach ($resource->allResources($property) as $other) {
            if ($other->isBNode()) {
                $this->warnings[] = "{$resource->getUri()}: {$property} to a blank node left out.";

                continue;
            }
            $uris[] = $other->getUri();
        }

        return $uris;
    }

    private function language(Literal $literal, string $uri): string
    {
        $language = (string) $literal->getLang();
        try {
            return Language::tag($language);
        } catch (InvalidArgumentException) {
            $this->warnings[] = "{$uri}: language tag «{$language}» dropped.";

            return '';
        }
    }
}
