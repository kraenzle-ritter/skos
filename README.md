# kraenzle-ritter/skos

[![CI](https://github.com/kraenzle-ritter/skos/actions/workflows/ci.yml/badge.svg)](https://github.com/kraenzle-ritter/skos/actions/workflows/ci.yml)
[![License](https://img.shields.io/github/license/kraenzle-ritter/skos)](LICENSE)
[![PHP Version](https://img.shields.io/badge/PHP-8.3%2B-blue)](https://www.php.net/)

SKOS vocabularies in PHP: concepts and concept schemes as plain value objects, read from and written to Turtle, RDF/XML and N-Triples with [EasyRdf](https://github.com/sweetrdf/easyrdf), and read from thesaurus tables. Framework-agnostic. Built for [Anton](https://git.k-r.ch/kraenzle-ritter/anton), which exports its keywords as a thesaurus (roadmap issue #706); meant to serve kb as well.

## Scope

- **Concepts** with preferred, alternative and hidden labels per language (or without language), notations, definitions and scope notes, `broader`, `narrower`, `related`, `exactMatch` and `closeMatch`.
- **SKOS integrity where it is cheap:** one preferred label per language (S14); a label is preferred, alternative or hidden, never two of them (S13, the stronger kind wins, compared without regard to case); a concept never points at itself; URIs must be absolute.
- **Inverses completed.** Hierarchy and `related` are written in both directions wherever both ends are in the file, so a host that stores an edge once (child → parent) gets `skos:narrower` as well. A link to a concept outside the file is written from this side only.
- **Top concepts** are the concepts without any broader concept.
- **Subtrees:** a concept and everything below it, as a vocabulary of the same scheme; links leaving the subtree stay.
- **Reading SKOS** (`SkosReader`): schemes and concepts, lenient where thesauri are careless (a second preferred label becomes an alternative one, a broken language tag is dropped, blank nodes are left out), with warnings for a report. `ReadResult::vocabulary()` picks a scheme; a file without one takes the caller's.
- **Reading thesaurus tables** (`ThesaurusTable`): one row per term with `term`, `broader`, `use_for`, `use`, `related`, `notation`, `definition`. Non-preferred terms become alternative labels; a compound equivalence («use A, B») becomes one on each and is reported. Several terms in a cell are separated by `;`, and by commas only where the whole cell is no term itself, so names like «Arbeit, Industrie, Wirtschaft» survive. URIs are built from the scheme URI and the term, stable across readings.

Not yet here: collections, SKOS-XL, mapping beyond `exactMatch` and `closeMatch`, compound equivalence as its own relation (ISO 25964).

## Usage

```php
use KraenzleRitter\Skos\Concept;
use KraenzleRitter\Skos\ConceptScheme;
use KraenzleRitter\Skos\Format;
use KraenzleRitter\Skos\SkosWriter;
use KraenzleRitter\Skos\Vocabulary;

$vocabulary = new Vocabulary(
    new ConceptScheme('https://archive.example/keywords', titles: ['de' => 'Schlagwörter']),
    [
        new Concept('https://archive.example/id/writing', prefLabels: ['de' => 'Schrift']),
        new Concept(
            'https://archive.example/id/letter',
            prefLabels: ['de' => 'Brief', 'it' => 'lettera'],
            altLabels: ['de' => ['Schreiben']],
            broader: ['https://archive.example/id/writing'],
            exactMatch: ['http://www.wikidata.org/entity/Q133492'],
        ),
    ],
);

$turtle = (new SkosWriter)->write($vocabulary, Format::Turtle);
$branch = (new SkosWriter)->write($vocabulary->subtree('https://archive.example/id/writing'), Format::RdfXml);
```

Reading:

```php
use KraenzleRitter\Skos\SkosReader;
use KraenzleRitter\Skos\ThesaurusTable;

$result = (new SkosReader)->read(file_get_contents('ariadne.ttl'), Format::Turtle);
$vocabulary = $result->vocabulary();          // the only scheme, or name one
$warnings = $result->warnings;

$table = (new ThesaurusTable(new ConceptScheme('https://archive.example/thesaurus'), 'de'))->read($rows);
$table->vocabulary; $table->compounds; $table->warnings;
```

`Format::fromName('ttl')` finds a format by name or extension; `mimeType()` and `extension()` give what a download needs.

Writing 11 200 concepts as Turtle takes about three seconds and 140 MB, reading them about eight seconds and 160 MB (debug build of PHP).

## Development

```bash
composer install
composer test
composer phpstan
```

## License

MIT, see [LICENSE](LICENSE).
