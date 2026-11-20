<?php

namespace App\Support;

use Illuminate\Validation\ValidationException;

/** Validate new physical inputs without rounding or rewriting historical ledger precision. */
final class WholeVolume
{
    public const MAX = 1000000000;

    public static function number(mixed $value): float
    {
        if ((! is_int($value) && ! is_float($value)) || ! is_finite((float) $value)
            || $value < 0 || $value > self::MAX || floor((float) $value) !== (float) $value) {
            throw ValidationException::withMessages(['volume' => 'Volume harus berupa bilangan bulat 0–1.000.000.000 tanpa koma atau desimal.']);
        }

        return (float) $value;
    }
}
