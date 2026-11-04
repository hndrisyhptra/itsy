<?php

namespace App\Services;

use DateTimeImmutable;
use Illuminate\Validation\ValidationException;

final class ProjectPlanTemplate
{
    public static function apply(array $project): array
    {
        $template = json_decode(file_get_contents(resource_path('data/project-plan-template.json')), true, flags: JSON_THROW_ON_ERROR);
        $schedule = $project['schedule'] ?? [];
        $date = $project['date'] ?? '';
        $cursor = is_string($date) && preg_match('/^[0-9]{4}-[0-9]{2}-[0-9]{2}$/D', $date)
            ? DateTimeImmutable::createFromFormat('!Y-m-d', $date) : false;
        if (! $cursor || $cursor->format('Y-m-d') !== $date || ! is_array($schedule) || ! array_is_list($schedule) || count($schedule) !== 5) {
            self::invalid('Isi Saat Penggunaan dan lima HK jadwal pelaksanaan.');
        }
        $total = 0;
        foreach ($template['stages'] as $i => $name) {
            $days = $schedule[$i]['days'] ?? null;
            if ((! is_int($days) && ! is_float($days)) || ! is_finite((float) $days) || $days < 0 || floor($days) !== (float) $days || $days > 1000000) {
                self::invalid('HK jadwal harus berupa bilangan bulat nonnegatif.');
            }
            $days = (int) $days;
            $total += $days;
            $end = $days ? $cursor->modify('+'.($days - 1).' days') : $cursor;
            if ($total > 1000000 || ($days && strlen($end->format('Y-m-d')) !== 10)) {
                self::invalid('Durasi jadwal di luar rentang tanggal yang didukung.');
            }
            $project['schedule'][$i] = ['name' => $name, 'days' => $days, 'start' => $days ? $cursor->format('Y-m-d') : '', 'end' => $days ? $end->format('Y-m-d') : ''];
            if ($days) {
                $cursor = $end->modify('+1 day');
            }
        }
        if ($total <= 0) {
            self::invalid('Total HK jadwal harus lebih dari nol.');
        }
        $project['planTemplateVersion'] = $template['version'];
        $project['intro'] = implode("\n\n", array_map(fn ($section) => $section['title'].":\n".$section['text'], $template['introduction']));
        $project['teamText'] = $template['team'];
        $project['team'] = [];
        $project['durationDays'] = $total;

        return $project;
    }

    private static function invalid(string $message): never
    {
        throw ValidationException::withMessages(['project' => $message]);
    }
}
