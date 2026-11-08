<?php

declare(strict_types=1);

namespace KraenzleRitter\Skos;

use InvalidArgumentException;

/**
 * Language tags as RDF writes them: BCP 47, lower case; `''` for a literal
 * without language.
 */
final class Language
{
    public static function tag(string $language): string
    {
        $language = strtolower(trim(str_replace('_', '-', $language)));

        if ($language !== '' && preg_match('/^[a-z]{2,3}(-[a-z0-9]{1,8})*$/', $language) !== 1) {
            throw new InvalidArgumentException("Not a language tag: «{$language}».");
        }

        return $language;
    }
}
