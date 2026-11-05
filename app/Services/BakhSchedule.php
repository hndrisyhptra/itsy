<?php

namespace App\Services;

use DateTimeImmutable;
use DateTimeZone;

final class BakhSchedule
{
    public static function period(array $project): ?array
    {
        $dates = [];
        $days = 0;
        foreach ($project['schedule'] ?? [] as $row) {
            $hk = $row['days'] ?? null;
            if ((! is_int($hk) && ! is_float($hk)) || ! is_finite((float) $hk) || $hk < 0) {
                return null;
            }
            $days += $hk;
            if ($hk === 0 || $hk === 0.0) {
                continue;
            }
            foreach (['start', 'end'] as $key) {
                $value = $row[$key] ?? '';
                $date = is_string($value) ? DateTimeImmutable::createFromFormat('!Y-m-d', $value, new DateTimeZone('UTC')) : false;
                if (! $date || $date->format('Y-m-d') !== $value) {
                    return null;
                }
                $dates[] = $value;
            }
            if ($row['start'] > $row['end']) {
                return null;
            }
        }
        if (! $dates || $days < 1 || $days > 1000000) {
            return null;
        }
        sort($dates);
        $start = $dates[0];
        $limit = (new DateTimeImmutable($start, new DateTimeZone('UTC')))->modify('+'.((int) floor($days) - 1).' days')->format('Y-m-d');

        // Historical schedules can contain gaps. Keep the period inside both the PP and its HK cap.
        return ['start' => $start, 'end' => strlen($limit) > 10 ? end($dates) : min(end($dates), $limit), 'hk' => $days];
    }
}
