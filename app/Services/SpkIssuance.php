<?php

namespace App\Services;

use DateTimeImmutable;

final class SpkIssuance
{
    public const PLACE = 'Surabaya';

    private static function date(mixed $value): string
    {
        if (! is_string($value) || ! preg_match('/^\d{4}-\d{2}-\d{2}$/D', $value) || $value < '0001-01-01') {
            return '';
        }
        $date = DateTimeImmutable::createFromFormat('!Y-m-d', $value);

        return $date && $date->format('Y-m-d') === $value ? $value : '';
    }

    public static function sync(array $spk): array
    {
        $date = self::date($spk['date'] ?? null);
        $start = self::date($spk['start'] ?? null);
        $spk['place'] = self::PLACE;
        $spk['date'] = $date ?: $start;

        return $spk;
    }
}
