<?php

declare(strict_types=1);

namespace KraenzleRitter\Skos;

use EasyRdf\Graph;
use EasyRdf\RdfNamespace;

/**
 * Writes a vocabulary as SKOS.
 *
 * Every concept is `skos:inScheme` of the scheme. Hierarchy and `related` are
 * written in both directions wherever both ends are in the file (SKOS leaves
 * the inverse to inference, and most consumers do not infer); a link to a
 * concept outside the file is written from this side only. Concepts without
 * any broader concept are the scheme's top concepts.
 */
final class SkosWriter
{
    private const Skos = 'http://www.w3.org/2004/02/skos/core#';

    private const Terms = 'http://purl.org/dc/terms/';

    public function write(Vocabulary $vocabulary, Format $format): string
    {
        $output = $this->graph($vocabulary)->serialise($format->value);

        return is_string($output) ? $output : '';
    }

    public function graph(Vocabulary $vocabulary): Graph
    {
        RdfNamespace::set('skos', self::Skos);
        RdfNamespace::set('dcterms', self::Terms);

        $graph = new Graph;
        $scheme = $vocabulary->scheme;

        $graph->addType($scheme->uri, 'skos:ConceptScheme');
        foreach ($scheme->titles as $language => $title) {
            $graph->addLiteral($scheme->uri, 'skos:prefLabel', $title, $this->language($language));
            $graph->addLiteral($scheme->uri, 'dcterms:title', $title, $this->language($language));
        }
        foreach ($scheme->descriptions as $language => $description) {
            $graph->addLiteral($scheme->uri, 'dcterms:description', $description, $this->language($language));
        }
        if ($scheme->publisher !== null && $scheme->publisher !== '') {
            $graph->addLiteral($scheme->uri, 'dcterms:publisher', $scheme->publisher);
        }

        foreach ($vocabulary->topConcepts() as $top) {
            $graph->addResource($scheme->uri, 'skos:hasTopConcept', $top);
            $graph->addResource($top, 'skos:topConceptOf', $scheme->uri);
        }

        foreach ($vocabulary->concepts() as $concept) {
            $this->concept($graph, $vocabulary, $concept);
        }

        return $graph;
    }

    private function concept(Graph $graph, Vocabulary $vocabulary, Concept $concept): void
    {
        $uri = $concept->uri;

        $graph->addType($uri, 'skos:Concept');
        $graph->addResource($uri, 'skos:inScheme', $vocabulary->scheme->uri);

        foreach ($concept->prefLabels as $language => $label) {
            $graph->addLiteral($uri, 'skos:prefLabel', $label, $this->language($language));
        }
        foreach (['skos:altLabel' => $concept->altLabels, 'skos:hiddenLabel' => $concept->hiddenLabels] as $property => $lists) {
            foreach ($lists as $language => $labels) {
                foreach ($labels as $label) {
                    $graph->addLiteral($uri, $property, $label, $this->language($language));
                }
            }
        }
        foreach ($concept->notations as $notation) {
            $graph->addLiteral($uri, 'skos:notation', $notation);
        }
        foreach (['skos:definition' => $concept->definitions, 'skos:scopeNote' => $concept->scopeNotes] as $property => $notes) {
            foreach ($notes as $language => $note) {
                $graph->addLiteral($uri, $property, $note, $this->language($language));
            }
        }

        foreach ($concept->broader as $parent) {
            $this->link($graph, $vocabulary, $uri, 'skos:broader', $parent, 'skos:narrower');
        }
        foreach ($concept->narrower as $child) {
            $this->link($graph, $vocabulary, $uri, 'skos:narrower', $child, 'skos:broader');
        }
        foreach ($concept->related as $other) {
            $this->link($graph, $vocabulary, $uri, 'skos:related', $other, 'skos:related');
        }
        foreach ($concept->exactMatch as $match) {
            $graph->addResource($uri, 'skos:exactMatch', $match);
        }
        foreach ($concept->closeMatch as $match) {
            $graph->addResource($uri, 'skos:closeMatch', $match);
        }
    }

    /**
     * A link, and its inverse where the other end is in the file. The graph
     * holds each triple once, however often it is added.
     */
    private function link(Graph $graph, Vocabulary $vocabulary, string $from, string $property, string $to, string $inverse): void
    {
        $graph->addResource($from, $property, $to);
        if ($vocabulary->has($to)) {
            $graph->addResource($to, $inverse, $from);
        }
    }

    private function language(string $language): ?string
    {
        $language = Language::tag($language);

        return $language === '' ? null : $language;
    }
}
