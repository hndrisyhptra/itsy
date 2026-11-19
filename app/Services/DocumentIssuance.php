<?php

namespace App\Services;

use DateTimeImmutable;

final class DocumentIssuance
{
    public const POLICY = 'pre-work-issue-v1';

    public static function calendar(mixed $value): string
    {
        if (! is_string($value) || ! preg_match('/^\d{4}-\d{2}-\d{2}$/D', $value) || $value < '0001-01-01') {
            return '';
        }
        $date = DateTimeImmutable::createFromFormat('!Y-m-d', $value);

        return $date && $date->format('Y-m-d') === $value ? $value : '';
    }

    public static function planning(array $project, ?array $previous = null): array
    {
        $project['documentDatePolicy'] = self::POLICY;
        $project['issueDate'] = $project['date'] ?? '';
        $project['workStartDate'] ??= $previous['workStartDate'] ?? ($previous ? (BakhSchedule::period($previous)['start'] ?? '') : '');

        return $project;
    }

    public static function projectError(array $p, bool $formal = false): string
    {
        if (($p['documentDatePolicy'] ?? '') !== self::POLICY) {
            return '';
        }
        foreach (['issueDate' => 'Saat Penggunaan pada Header PP', 'workStartDate' => 'Tanggal Mulai Pekerjaan'] as $key => $label) {
            if (! is_string($p[$key] ?? '') || (($p[$key] ?? '') !== '' && ! self::calendar($p[$key]))) {
                return $label.' tidak valid.';
            }
        }
        if ($formal && (empty($p['issueDate']) || empty($p['workStartDate']))) {
            return 'Lengkapi Saat Penggunaan di Header PP dan Tanggal Mulai Pekerjaan di Jadwal Proyek.';
        }
        if (! empty($p['issueDate']) && ! empty($p['workStartDate']) && $p['issueDate'] > $p['workStartDate']) {
            return 'Tanggal PP (Saat Penggunaan) '.$p['issueDate'].' tidak boleh melewati tanggal mulai pekerjaan '.$p['workStartDate'].'.';
        }

        return '';
    }

    public static function bakhError(array $p, array $d, bool $formal = false): string
    {
        if (($p['documentDatePolicy'] ?? '') !== self::POLICY && ($d['documentDatePolicy'] ?? '') !== self::POLICY) {
            return '';
        }
        if ($error = self::projectError($p, $formal)) {
            return $error;
        }
        if (! self::calendar($d['date'] ?? '')) {
            return $formal || ! empty($d['date']) ? 'Tanggal terbit BA KH tidak valid.' : '';
        }
        $start = self::calendar($p['workStartDate'] ?? '') ?: (BakhSchedule::period($p)['start'] ?? '');
        if ($start && $d['date'] > $start) {
            return 'Tanggal terbit BA KH '.$d['date'].' tidak boleh melewati tanggal mulai pekerjaan '.$start.'.';
        }
        if (self::calendar($p['issueDate'] ?? '') && $d['date'] < $p['issueDate']) {
            return 'Tanggal terbit BA KH '.$d['date'].' tidak boleh sebelum tanggal terbit PP '.$p['issueDate'].'.';
        }

        return '';
    }

    public static function spkError(array $p, array $d, array $v, bool $formal = false): string
    {
        if (($p['documentDatePolicy'] ?? '') !== self::POLICY && ($v['documentDatePolicy'] ?? '') !== self::POLICY) {
            return '';
        }
        if ($error = self::bakhError($p, array_merge($d, ['documentDatePolicy' => self::POLICY]), $formal)) {
            return $error;
        }
        if (! self::calendar($v['date'] ?? '')) {
            return $formal || ! empty($v['date']) ? 'Tanggal terbit SPK tidak valid.' : '';
        }
        $start = self::calendar($p['workStartDate'] ?? '') ?: (BakhSchedule::period($p)['start'] ?? '');
        if ($start && $v['date'] > $start) {
            return 'Tanggal terbit SPK '.$v['date'].' tidak boleh melewati tanggal mulai pekerjaan '.$start.'.';
        }
        if (self::calendar($d['date'] ?? '') && $v['date'] < $d['date']) {
            return 'Tanggal terbit SPK '.$v['date'].' tidak boleh sebelum tanggal terbit BA KH '.$d['date'].'.';
        }

        return '';
    }
}
