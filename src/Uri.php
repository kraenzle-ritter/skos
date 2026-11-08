<?php

declare(strict_types=1);

namespace KraenzleRitter\Skos;

use InvalidArgumentException;

/**
 * Concepts and schemes are named by absolute URIs; a relative one would be
 * resolved against wherever the file happens to be read and name something
 * else there.
 */
final class Uri
{
    public static function assertAbsolute(string $uri): void
    {
        if (preg_match('/^[a-z][a-z0-9+.-]*:\S+$/i', $uri) !== 1) {
            throw new InvalidArgumentException("Not an absolute URI: «{$uri}».");
        }
    }
}
