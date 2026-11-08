# kraenzle-ritter/skos

[![CI](https://github.com/kraenzle-ritter/skos/actions/workflows/ci.yml/badge.svg)](https://github.com/kraenzle-ritter/skos/actions/workflows/ci.yml)
[![License](https://img.shields.io/github/license/kraenzle-ritter/skos)](LICENSE)
[![PHP Version](https://img.shields.io/badge/PHP-8.3%2B-blue)](https://www.php.net/)

SKOS vocabularies in PHP: concepts and concept schemes as plain value objects, written to Turtle, RDF/XML and N-Triples with [EasyRdf](https://github.com/sweetrdf/easyrdf). Framework-agnostic. Built for [Anton](https://git.k-r.ch/kraenzle-ritter/anton), which exports its keywords as a thesaurus (roadmap issue #706); meant to serve kb as well.

## Scope

- **Concepts** with preferred, alternative and hidden labels per language (or without language), notations, definitions and scope notes, `broader`, `narrower`, `related`, `exactMatch` and `closeMatch`.
- **SKOS integrity where it is cheap:** one preferred label per language (S14); a label is preferred, alternative or hidden, never two of them (S13, the stronger kind wins, compared without regard to case); a concept never points at itself; URIs must be absolute.
- **Inverses completed.** Hierarchy and `related` are written in both directions wherever both ends are in the file, so a host that stores an edge once (child → parent) gets `skos:narrower` as well. A link to a concept outside the file is written from this side only.
- **Top concepts** are the concepts without any broader concept.
- **Subtrees:** a concept and everything below it, as a vocabulary of the same scheme; links leaving the subtree stay.

Not yet here: reading SKOS (import), collections, SKOS-XL, mapping beyond `exactMatch` and `closeMatch`.

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

`Format::fromName('ttl')` finds a format by name or extension; `mimeType()` and `extension()` give what a download needs.

Writing 11 200 concepts as Turtle takes about three seconds and 140 MB.

## Development

```bash
composer install
composer test
composer phpstan
```

## License

MIT, see [LICENSE](LICENSE).
