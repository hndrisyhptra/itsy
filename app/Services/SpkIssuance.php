<?php

namespace App\Services;

use DateTimeImmutable;

final class SpkIssuance
{
    private static function date(mixed $value): string
    {
        if (! is_string($value) || ! preg_match('/^\d{4}-\d{2}-\d{2}$/D', $value) || $value < '0001-01-01') {
            return '';
        }
        $date = DateTimeImmutable::createFromFormat('!Y-m-d', $value);

        return $date && $date->format('Y-m-d') === $value ? $value : '';
    }

    public static function sync(array $spk, array $project): array
    {
        $date = self::date($spk['date'] ?? null);
        $start = self::date($spk['start'] ?? null);
        $spk['place'] = DocumentSigningPlace::fromProject($project);
        $spk['date'] = $date ?: $start;

        return $spk;
    }

    public static function periodError(array $spk, array $deal): string
    {
        if ($spk['start'] <= $spk['end'] && $spk['start'] >= $deal['start'] && $spk['end'] <= $deal['end']) {
            return '';
        }
        $range = static function (array $period): string {
            $label = static fn (string $value): string => self::date($value) ? implode('/', array_reverse(explode('-', $value))) : 'belum valid';

            return $label($period['start']).'–'.$label($period['end']);
        };
        $hint = $deal['status'] === 'Draft'
            ? 'Buka BA KH dan simpan draf untuk menyelaraskan periode dengan jadwal PP, lalu periksa kembali SPK.'
            : 'Periksa referensi BA KH dan sesuaikan periode SPK dengan dokumen tersimpan.';

        return 'Periode SPK harus berada dalam periode BA KH. SPK: '.$range($spk).'. BA KH '.$deal['no'].' tersimpan: '.$range($deal).'. '.$hint;
    }
}
