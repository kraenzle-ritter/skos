<?php

declare(strict_types=1);

namespace KraenzleRitter\Skos;

/**
 * The serialisations the writer offers, with what HTTP and file names need.
 */
enum Format: string
{
    case Turtle = 'turtle';
    case RdfXml = 'rdfxml';
    case NTriples = 'ntriples';

    /**
     * By name or file extension: `turtle`/`ttl`, `rdfxml`/`rdf`/`xml`,
     * `ntriples`/`nt`.
     */
    public static function fromName(string $name): ?self
    {
        return match (strtolower(trim($name))) {
            'turtle', 'ttl' => self::Turtle,
            'rdfxml', 'rdf', 'xml' => self::RdfXml,
            'ntriples', 'nt' => self::NTriples,
            default => null,
        };
    }

    public function mimeType(): string
    {
        return match ($this) {
            self::Turtle => 'text/turtle',
            self::RdfXml => 'application/rdf+xml',
            self::NTriples => 'application/n-triples',
        };
    }

    public function extension(): string
    {
        return match ($this) {
            self::Turtle => 'ttl',
            self::RdfXml => 'rdf',
            self::NTriples => 'nt',
        };
    }
}
