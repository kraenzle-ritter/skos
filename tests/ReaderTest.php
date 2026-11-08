<?php

declare(strict_types=1);

namespace KraenzleRitter\Skos\Tests;

use InvalidArgumentException;
use KraenzleRitter\Skos\Concept;
use KraenzleRitter\Skos\ConceptScheme;
use KraenzleRitter\Skos\Format;
use KraenzleRitter\Skos\SkosReader;
use KraenzleRitter\Skos\SkosWriter;
use KraenzleRitter\Skos\ThesaurusTable;
use KraenzleRitter\Skos\Vocabulary;
use PHPUnit\Framework\TestCase;

final class ReaderTest extends TestCase
{
    private const Turtle = <<<'TTL'
        @prefix skos: <http://www.w3.org/2004/02/skos/core#> .
        @prefix dcterms: <http://purl.org/dc/terms/> .
        @prefix ex: <https://example.org/thes/> .

        ex:scheme a skos:ConceptScheme ;
            dcterms:title "Bildthesaurus"@de ;
            dcterms:publisher "Bibliothek" ;
            skos:hasTopConcept ex:art .

        ex:art a skos:Concept ;
            skos:prefLabel "Kunst"@de, "art"@en ;
            skos:topConceptOf ex:scheme ;
            skos:notation "1" .

        ex:painting a skos:Concept ;
            skos:prefLabel "Malerei"@de, "Gemälde"@de ;
            skos:altLabel "Malkunst"@de ;
            skos:hiddenLabel "Mahlerei"@de ;
            skos:definition "Kunst mit Farbe."@de ;
            skos:broader ex:art, [ skos:prefLabel "anonym" ] ;
            skos:related ex:drawing ;
            skos:exactMatch <http://www.wikidata.org/entity/Q11629> ;
            skos:inScheme ex:scheme .

        ex:drawing skos:prefLabel "Zeichnung"@de-CH, "dessin"@x ;
            skos:inScheme ex:scheme .
        TTL;

    public function test_a_turtle_file_reads_into_schemes_and_concepts(): void
    {
        $result = (new SkosReader)->read(self::Turtle, Format::Turtle);

        $this->assertCount(1, $result->schemes);
        $this->assertSame('https://example.org/thes/scheme', $result->schemes[0]->uri);
        $this->assertSame(['de' => 'Bildthesaurus'], $result->schemes[0]->titles);
        $this->assertSame('Bibliothek', $result->schemes[0]->publisher);

        $vocabulary = $result->vocabulary();
        $this->assertSame(['https://example.org/thes/art', 'https://example.org/thes/painting', 'https://example.org/thes/drawing'], array_keys($vocabulary->concepts()));

        $painting = $vocabulary->get('https://example.org/thes/painting');
        $this->assertInstanceOf(Concept::class, $painting);
        $this->assertSame(['de' => 'Malerei'], $painting->prefLabels);
        $this->assertSame(['de' => ['Malkunst', 'Gemälde']], $painting->altLabels);
        $this->assertSame(['de' => ['Mahlerei']], $painting->hiddenLabels);
        $this->assertSame(['de' => 'Kunst mit Farbe.'], $painting->definitions);
        $this->assertSame(['https://example.org/thes/art'], $painting->broader);
        $this->assertSame(['https://example.org/thes/drawing'], $painting->related);
        $this->assertSame(['http://www.wikidata.org/entity/Q11629'], $painting->exactMatch);
        $this->assertSame(['1'], $vocabulary->get('https://example.org/thes/art')?->notations);
        $this->assertSame(['de-ch' => 'Zeichnung', '' => 'dessin'], $vocabulary->get('https://example.org/thes/drawing')?->prefLabels);
        $this->assertSame(['https://example.org/thes/art', 'https://example.org/thes/drawing'], $vocabulary->topConcepts());

        $this->assertNotEmpty(array_filter($result->warnings, fn (string $w): bool => str_contains($w, 'more than one preferred label')));
        $this->assertNotEmpty(array_filter($result->warnings, fn (string $w): bool => str_contains($w, 'blank node')));
        $this->assertNotEmpty(array_filter($result->warnings, fn (string $w): bool => str_contains($w, 'language tag «x»')));
    }

    public function test_what_the_writer_writes_the_reader_reads(): void
    {
        $scheme = new ConceptScheme('https://example.org/s', ['de' => 'Test']);
        $written = (new SkosWriter)->write(new Vocabulary($scheme, [
            new Concept('https://example.org/a', prefLabels: ['de' => 'A'], narrower: ['https://example.org/b']),
            new Concept('https://example.org/b', prefLabels: ['de' => 'B'], altLabels: ['' => ['Be']]),
        ]), Format::RdfXml);

        $vocabulary = (new SkosReader)->read($written, Format::RdfXml)->vocabulary();

        $this->assertSame(['https://example.org/a'], $vocabulary->get('https://example.org/b')?->broader);
        $this->assertSame(['' => ['Be']], $vocabulary->get('https://example.org/b')?->altLabels);
    }

    public function test_a_file_with_several_schemes_wants_one_named(): void
    {
        $result = (new SkosReader)->read(<<<'TTL'
            @prefix skos: <http://www.w3.org/2004/02/skos/core#> .
            <https://e.org/s1> a skos:ConceptScheme .
            <https://e.org/s2> a skos:ConceptScheme .
            <https://e.org/a> a skos:Concept ; skos:prefLabel "A"@de ; skos:inScheme <https://e.org/s1> .
            <https://e.org/b> a skos:Concept ; skos:prefLabel "B"@de ; skos:inScheme <https://e.org/s2> .
            TTL, Format::Turtle);

        $this->assertSame(['https://e.org/b'], array_keys($result->vocabulary('https://e.org/s2')->concepts()));

        $this->expectException(InvalidArgumentException::class);
        $result->vocabulary();
    }

    public function test_a_file_without_a_scheme_takes_the_callers(): void
    {
        $result = (new SkosReader)->read("<https://e.org/a> <http://www.w3.org/1999/02/22-rdf-syntax-ns#type> <http://www.w3.org/2004/02/skos/core#Concept> .\n", Format::NTriples);

        $vocabulary = $result->vocabulary(fallback: new ConceptScheme('https://archive.example/scheme'));

        $this->assertSame(['https://e.org/a'], array_keys($vocabulary->concepts()));
    }

    public function test_an_unreadable_file_is_refused(): void
    {
        $this->expectException(InvalidArgumentException::class);
        (new SkosReader)->read('this is not turtle {', Format::Turtle);
    }

    public function test_a_table_reads_with_compounds_and_commas_in_names(): void
    {
        $scheme = new ConceptScheme('https://archive.example/thes');
        $result = (new ThesaurusTable($scheme, 'de'))->read([
            ['term' => 'Arbeit, Industrie, Wirtschaft'],
            ['term' => 'Fabrik', 'broader' => 'Arbeit, Industrie, Wirtschaft', 'use_for' => 'Werk; Manufaktur', 'notation' => '2.1'],
            ['term' => 'Streik', 'broader' => 'Arbeit, Industrie, Wirtschaft; Protest', 'related' => 'Fabrik; Unbekannt'],
            ['term' => 'Arbeitskampf', 'use' => 'Streik'],
            ['term' => 'Fabrikstreik', 'use' => 'Fabrik, Streik'],
            ['term' => 'Leer', 'use' => 'Gibt es nicht'],
            ['term' => ''],
        ]);

        $vocabulary = $result->vocabulary;
        $table = new ThesaurusTable($scheme, 'de');
        $industry = $vocabulary->get($table->uriOf('Arbeit, Industrie, Wirtschaft'));
        $factory = $vocabulary->get($table->uriOf('Fabrik'));
        $strike = $vocabulary->get($table->uriOf('Streik'));

        $this->assertNotNull($industry, 'a name with commas stays one term');
        $this->assertSame([$industry->uri], $factory?->broader);
        $this->assertSame(['de' => ['Werk', 'Manufaktur', 'Fabrikstreik']], $factory->altLabels);
        $this->assertSame(['2.1'], $factory->notations);
        $this->assertSame([$industry->uri, $table->uriOf('Protest')], $strike?->broader);
        $this->assertSame([$factory->uri], $strike->related);
        $this->assertSame(['de' => ['Arbeitskampf', 'Fabrikstreik']], $strike->altLabels);
        $this->assertNotNull($vocabulary->get($table->uriOf('Protest')), 'a broader term without a row is created');
        $this->assertNull($vocabulary->get($table->uriOf('Arbeitskampf')), 'a non-preferred term is no concept');

        $this->assertSame([['term' => 'Fabrikstreik', 'use' => ['Fabrik', 'Streik']]], $result->compounds);
        $this->assertCount(3, $result->warnings, implode("\n", $result->warnings));
        $this->assertSame($table->uriOf('Fabrik'), (new ThesaurusTable($scheme, 'de'))->uriOf(' fabrik '), 'the same term yields the same URI');
    }
}
