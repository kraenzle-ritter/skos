<?php

declare(strict_types=1);

namespace KraenzleRitter\Skos;

/**
 * A SKOS concept as plain data.
 *
 * Labels and notes are keyed by language tag; the key `''` stands for a
 * literal without language, which is what a host has when a name carries no
 * language of its own. Relations hold the URIs of other concepts, inside the
 * vocabulary or outside it.
 */
final readonly class Concept
{
    /** @var array<string, string> */
    public array $prefLabels;

    /** @var array<string, list<string>> */
    public array $altLabels;

    /** @var array<string, list<string>> */
    public array $hiddenLabels;

    /** @var list<string> */
    public array $notations;

    /** @var array<string, string> */
    public array $definitions;

    /** @var array<string, string> */
    public array $scopeNotes;

    /** @var list<string> */
    public array $broader;

    /** @var list<string> */
    public array $narrower;

    /** @var list<string> */
    public array $related;

    /** @var list<string> */
    public array $exactMatch;

    /** @var list<string> */
    public array $closeMatch;

    /**
     * @param  array<string, string>  $prefLabels  one per language (SKOS S14)
     * @param  array<string, list<string>>  $altLabels
     * @param  array<string, list<string>>  $hiddenLabels
     * @param  list<string>  $notations
     * @param  array<string, string>  $definitions
     * @param  array<string, string>  $scopeNotes
     * @param  list<string>  $broader
     * @param  list<string>  $narrower
     * @param  list<string>  $related
     * @param  list<string>  $exactMatch
     * @param  list<string>  $closeMatch
     */
    public function __construct(
        public string $uri,
        array $prefLabels = [],
        array $altLabels = [],
        array $hiddenLabels = [],
        array $notations = [],
        array $definitions = [],
        array $scopeNotes = [],
        array $broader = [],
        array $narrower = [],
        array $related = [],
        array $exactMatch = [],
        array $closeMatch = [],
    ) {
        Uri::assertAbsolute($uri);

        $this->prefLabels = self::texts($prefLabels);
        $taken = array_map(self::fold(...), array_values($this->prefLabels));

        // SKOS S13: a label is preferred, alternative or hidden, never two of
        // them. Hosts often keep the same name in several lists; the stronger
        // kind wins, compared without regard to case or language.
        $this->altLabels = self::lists($altLabels, $taken);
        foreach ($this->altLabels as $values) {
            array_push($taken, ...array_map(self::fold(...), $values));
        }
        $this->hiddenLabels = self::lists($hiddenLabels, $taken);

        $this->notations = self::unique($notations);
        $this->definitions = self::texts($definitions);
        $this->scopeNotes = self::texts($scopeNotes);
        $this->broader = self::uris($broader, $uri);
        $this->narrower = self::uris($narrower, $uri);
        $this->related = self::uris($related, $uri);
        $this->exactMatch = self::uris($exactMatch, $uri);
        $this->closeMatch = self::uris($closeMatch, $uri);
    }

    /**
     * The label to show in the given language, else the first one there is.
     */
    public function label(string $language = ''): string
    {
        $first = array_key_first($this->prefLabels);

        return $this->prefLabels[$language] ?? ($first === null ? $this->uri : $this->prefLabels[$first]);
    }

    /**
     * @param  array<string, string>  $texts
     * @return array<string, string>
     */
    private static function texts(array $texts): array
    {
        $clean = [];
        foreach ($texts as $language => $text) {
            $text = trim((string) $text);
            if ($text !== '') {
                $clean[Language::tag((string) $language)] = $text;
            }
        }

        return $clean;
    }

    /**
     * @param  array<string, list<string>>  $lists
     * @param  list<string>  $taken
     * @return array<string, list<string>>
     */
    private static function lists(array $lists, array $taken): array
    {
        $clean = [];
        foreach ($lists as $language => $values) {
            $kept = [];
            foreach (self::unique($values) as $value) {
                if (! in_array(self::fold($value), $taken, true)) {
                    $kept[] = $value;
                    $taken[] = self::fold($value);
                }
            }
            if ($kept !== []) {
                $clean[Language::tag((string) $language)] = $kept;
            }
        }

        return $clean;
    }

    /**
     * @param  list<string>  $values
     * @return list<string>
     */
    private static function unique(array $values): array
    {
        $clean = [];
        foreach ($values as $value) {
            $value = trim((string) $value);
            if ($value !== '' && ! in_array($value, $clean, true)) {
                $clean[] = $value;
            }
        }

        return $clean;
    }

    /**
     * @param  list<string>  $uris
     * @return list<string>
     */
    private static function uris(array $uris, string $self): array
    {
        $clean = [];
        foreach (self::unique($uris) as $uri) {
            Uri::assertAbsolute($uri);
            if ($uri !== $self) {
                $clean[] = $uri;
            }
        }

        return $clean;
    }

    private static function fold(string $value): string
    {
        return mb_strtolower(trim($value));
    }
}
