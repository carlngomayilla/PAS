<?php

namespace App\Support;

use InvalidArgumentException;

class SafeSql
{
    /**
     * @param  list<string>  $allowed
     */
    public static function identifier(string $value, array $allowed): string
    {
        if (! in_array($value, $allowed, true)) {
            throw new InvalidArgumentException(sprintf('Unsafe SQL identifier rejected: %s', $value));
        }

        return $value;
    }
}
