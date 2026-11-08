<?php

declare(strict_types=1);

namespace KraenzleRitter\Skos\Tests;

use EasyRdf\Graph;
use InvalidArgumentException;
use KraenzleRitter\Skos\Concept;
use KraenzleRitter\Skos\ConceptScheme;
use KraenzleRitter\Skos\Format;
use KraenzleRitter\Skos\SkosWriter;
use KraenzleRitter\Skos\Vocabulary;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class SkosWriterTest extends TestCase
{
    private const Base = 'https://example.org/id/';

    private const Skos = 'http://www.w3.org/2004/02/skos/core#';

    public function test_a_concept_carries_its_labels_notes_and_notation(): void
    {
        $graph = $this->graph(new Concept(
            self::Base.'letter',
            prefLabels: ['de' => 'Brief', 'it' => 'lettera'],
            altLabels: ['de' => ['Schreiben']],
            hiddenLabels: ['' => ['Breif']],
            notations: ['3.1'],
            definitions: ['de' => 'Eine schriftliche Mitteilung.'],
            scopeNotes: ['de' => 'Auch Postkarten.'],
            exactMatch: ['http://www.wikidata.org/entity/Q133492'],
        ));

        $letter = $graph->resource(self::Base.'letter');
        $this->assertTrue($letter->isA('skos:Concept'));
        $this->assertSame(self::Base.'scheme', $letter->getResource('skos:inScheme')?->getUri());
        $this->assertSame('Brief', (string) $letter->getLiteral('skos:prefLabel', 'de'));
        $this->assertSame('lettera', (string) $letter->getLiteral('skos:prefLabel', 'it'));
        $this->assertSame('Schreiben', (string) $letter->getLiteral('skos:altLabel', 'de'));
        $this->assertSame('Breif', (string) $letter->getLiteral('skos:hiddenLabel'));
        $this->assertSame('3.1', (string) $letter->getLiteral('skos:notation'));
        $this->assertSame('Eine schriftliche Mitteilung.', (string) $letter->getLiteral('skos:definition', 'de'));
        $this->assertSame('Auch Postkarten.', (string) $letter->getLiteral('skos:scopeNote', 'de'));
        $this->assertSame('http://www.wikidata.org/entity/Q133492', $letter->getResource('skos:exactMatch')?->getUri());
    }

    public function test_a_label_is_preferred_alternative_or_hidden_never_two_of_them(): void
    {
        $concept = new Concept(
            self::Base.'scf',
            prefLabels: ['it' => 'SCF'],
            altLabels: ['' => ['SCF', 'Servizio', 'servizio']],
            hiddenLabels: ['' => ['scf', 'Servizio', 'Svc']],
        );

        $this->assertSame(['' => ['Servizio']], $concept->altLabels);
        $this->assertSame(['' => ['Svc']], $concept->hiddenLabels);
    }

    public function test_hierarchy_and_related_are_written_both_ways_inside_the_file(): void
    {
        $graph = $this->graph(
            new Concept(self::Base.'writing', prefLabels: ['de' => 'Schrift']),
            new Concept(self::Base.'letter', prefLabels: ['de' => 'Brief'], broader: [self::Base.'writing', 'https://elsewhere.org/doc'], related: [self::Base.'card']),
            new Concept(self::Base.'card', prefLabels: ['de' => 'Karte'], narrower: [self::Base.'postcard']),
            new Concept(self::Base.'postcard', prefLabels: ['de' => 'Postkarte']),
        );

        $this->assertLinks($graph, 'letter', 'broader', [self::Base.'writing', 'https://elsewhere.org/doc']);
        $this->assertLinks($graph, 'writing', 'narrower', [self::Base.'letter']);
        $this->assertLinks($graph, 'card', 'related', [self::Base.'letter']);
        $this->assertLinks($graph, 'postcard', 'broader', [self::Base.'card']);
        $this->assertNull($graph->resource('https://elsewhere.org/doc')->getResource('skos:narrower'), 'an outside concept gets no triples');
    }

    public function test_concepts_without_a_broader_one_are_top_concepts(): void
    {
        $graph = $this->graph(
            new Concept(self::Base.'writing', prefLabels: ['de' => 'Schrift'], narrower: [self::Base.'letter']),
            new Concept(self::Base.'letter', prefLabels: ['de' => 'Brief']),
            new Concept(self::Base.'image', prefLabels: ['de' => 'Bild']),
            new Concept(self::Base.'photo', prefLabels: ['de' => 'Foto'], broader: ['https://elsewhere.org/image']),
        );

        $this->assertLinks($graph, 'scheme', 'hasTopConcept', [self::Base.'writing', self::Base.'image']);
        $this->assertSame(self::Base.'scheme', $graph->resource(self::Base.'image')->getResource('skos:topConceptOf')?->getUri());
    }

    public function test_a_subtree_holds_the_concept_and_everything_below_it(): void
    {
        $vocabulary = $this->vocabulary(
            new Concept(self::Base.'writing', prefLabels: ['de' => 'Schrift']),
            new Concept(self::Base.'letter', prefLabels: ['de' => 'Brief'], broader: [self::Base.'writing']),
            new Concept(self::Base.'postcard', prefLabels: ['de' => 'Postkarte'], broader: [self::Base.'letter', self::Base.'image']),
            new Concept(self::Base.'image', prefLabels: ['de' => 'Bild']),
        );

        $subtree = $vocabulary->subtree(self::Base.'letter');

        $this->assertSame([self::Base.'letter', self::Base.'postcard'], array_keys($subtree->concepts()));
        $this->assertSame([], $subtree->topConcepts(), 'the root of a subtree still has its broader concept');

        $graph = (new SkosWriter)->graph($subtree);
        $this->assertLinks($graph, 'letter', 'broader', [self::Base.'writing']);
        $this->assertLinks($graph, 'postcard', 'broader', [self::Base.'letter', self::Base.'image']);
    }

    public function test_a_cycle_in_the_data_does_not_hang_the_subtree(): void
    {
        $vocabulary = $this->vocabulary(
            new Concept(self::Base.'a', broader: [self::Base.'b']),
            new Concept(self::Base.'b', broader: [self::Base.'a']),
        );

        $this->assertCount(2, $vocabulary->subtree(self::Base.'a')->concepts());
    }

    #[DataProvider('formats')]
    public function test_every_format_reads_back_to_the_same_graph(Format $format): void
    {
        $vocabulary = $this->vocabulary(
            new Concept(self::Base.'writing', prefLabels: ['de' => 'Schrift', 'fr' => 'écriture']),
            new Concept(self::Base.'letter', prefLabels: ['de' => 'Brief'], altLabels: ['' => ['Schreiben']], broader: [self::Base.'writing']),
        );
        $written = (new SkosWriter)->write($vocabulary, $format);

        $read = new Graph;
        $read->parse($written, $format->value, self::Base);

        $this->assertSame((new SkosWriter)->graph($vocabulary)->countTriples(), $read->countTriples());
        $this->assertSame('écriture', (string) $read->resource(self::Base.'writing')->getLiteral('skos:prefLabel', 'fr'));
    }

    /**
     * @return iterable<string, array{Format}>
     */
    public static function formats(): iterable
    {
        foreach (Format::cases() as $format) {
            yield $format->value => [$format];
        }
    }

    public function test_formats_are_found_by_name_and_extension(): void
    {
        $this->assertSame(Format::Turtle, Format::fromName('ttl'));
        $this->assertSame(Format::RdfXml, Format::fromName('RDF'));
        $this->assertSame(Format::NTriples, Format::fromName('ntriples'));
        $this->assertNull(Format::fromName('jsonld'));
        $this->assertSame('text/turtle', Format::Turtle->mimeType());
    }

    public function test_a_relative_uri_is_refused(): void
    {
        $this->expectException(InvalidArgumentException::class);
        new Concept('keyword/12');
    }

    public function test_a_bad_language_tag_is_refused(): void
    {
        $this->expectException(InvalidArgumentException::class);
        new Concept(self::Base.'x', prefLabels: ['"de' => 'x']);
    }

    public function test_a_concept_may_appear_only_once(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->vocabulary(new Concept(self::Base.'a'), new Concept(self::Base.'a'));
    }

    public function test_a_concept_never_points_at_itself(): void
    {
        $concept = new Concept(self::Base.'a', broader: [self::Base.'a', self::Base.'b']);

        $this->assertSame([self::Base.'b'], $concept->broader);
    }

    public function test_the_scheme_carries_title_and_description(): void
    {
        $vocabulary = new Vocabulary(new ConceptScheme(self::Base.'scheme', ['de' => 'Schlagwörter'], ['de' => 'Vom Archiv gepflegt.'], 'Archiv'));
        $scheme = (new SkosWriter)->graph($vocabulary)->resource(self::Base.'scheme');

        $this->assertTrue($scheme->isA('skos:ConceptScheme'));
        $this->assertSame('Schlagwörter', (string) $scheme->getLiteral('skos:prefLabel', 'de'));
        $this->assertSame('Schlagwörter', (string) $scheme->getLiteral('dcterms:title', 'de'));
        $this->assertSame('Vom Archiv gepflegt.', (string) $scheme->getLiteral('dcterms:description', 'de'));
        $this->assertSame('Archiv', (string) $scheme->getLiteral('dcterms:publisher'));
    }

    private function vocabulary(Concept ...$concepts): Vocabulary
    {
        return new Vocabulary(new ConceptScheme(self::Base.'scheme', ['de' => 'Test']), $concepts);
    }

    private function graph(Concept ...$concepts): Graph
    {
        return (new SkosWriter)->graph($this->vocabulary(...$concepts));
    }

    /**
     * @param  list<string>  $expected
     */
    private function assertLinks(Graph $graph, string $local, string $property, array $expected): void
    {
        $actual = array_map(
            fn ($resource): string => $resource->getUri(),
            $graph->allResources(self::Base.$local, '<'.self::Skos.$property.'>'),
        );
        sort($actual);
        sort($expected);

        $this->assertSame($expected, $actual, "skos:{$property} of {$local}");
    }
}
