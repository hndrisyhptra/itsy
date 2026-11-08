<?php

namespace App\Services;

use Illuminate\Validation\ValidationException;

final class BarpTemplate
{
    public static function defaults(array $state, array $term): array
    {
        $spk = collect($state['spks'])->firstWhere('id', $term['spkId']);
        $deal = collect($state['deals'])->firstWhere('id', $spk['dealId']);
        $project = collect($state['projects'])->firstWhere('id', $deal['projectId']);
        $head = ['signer' => '', 'signerNik' => '', 'signerUserId' => ''];
        if (($term['workflowVersion'] ?? 1) >= 3) {
            $source = $project;
            $source['approverUserIds'] = $term['approverUserIds'] ?? [];
            $source['approvals'] = array_merge($term['approvals'] ?? [], $project['approvals'] ?? []);
            $head = BakhSigner::projectIdentity($state, $source);
        } elseif (($spk['signerSource'] ?? '') === 'pp-head-pmo') {
            $head = $spk;
        }

        return ['version' => 1, 'date' => now()->toDateString(), 'place' => $spk['place'], 'start' => $spk['start'], 'end' => $spk['end'], 'location' => $deal['location'] ?? '', 'sto' => '', 'homepass' => null,
            'waspang' => $spk['supervisor'], 'mandor' => $deal['mandor'], 'headName' => $head['signer'], 'headNik' => $head['signerNik'] ?? '', 'headUserId' => $head['signerUserId'] ?? ''];
    }

    public static function save(array $state, array $term, array $input, bool $submit): array
    {
        if (! isset($term['barp']) && ! array_key_exists('barp', $input)) {
            return $term; // Preserve historical clients/documents without silently adopting new requirements.
        }
        $data = array_key_exists('barp', $input) ? $input['barp'] : $term['barp'];
        if (! is_array($data) || array_is_list($data)) {
            self::fail('Informasi BARP harus berupa formulir yang valid.');
        }
        $barp = $term['barp'] ?? self::defaults($state, $term);
        foreach (['date' => 10, 'place' => 150, 'start' => 10, 'end' => 10, 'location' => 500, 'sto' => 150] as $field => $limit) {
            if (array_key_exists($field, $data)) {
                if (! is_string($data[$field]) || mb_strlen($data[$field]) > $limit) {
                    self::fail('Isian BARP '.$field.' tidak valid atau terlalu panjang.');
                }
                $barp[$field] = trim($data[$field]);
            }
        }
        $hp = array_key_exists('homepass', $data) ? $data['homepass'] : $barp['homepass'];
        if ($hp !== null && ((! is_int($hp) && ! is_float($hp)) || (float) $hp < 0 || (float) $hp > 1000000000 || floor((float) $hp) !== (float) $hp)) {
            self::fail('Homepass BARP harus bilangan bulat nonnegatif atau dikosongkan.');
        }
        $barp['homepass'] = $hp === null ? null : (int) $hp;
        $barp['version'] = 1;
        foreach (['date', 'start', 'end'] as $field) {
            $value = $barp[$field];
            if ($value !== '' && (! preg_match('/^\d{4}-\d{2}-\d{2}$/D', $value) || ! checkdate((int) substr($value, 5, 2), (int) substr($value, 8, 2), (int) substr($value, 0, 4)))) {
                self::fail('Tanggal BARP tidak valid.');
            }
        }
        $spk = collect($state['spks'])->firstWhere('id', $term['spkId']);
        if (($barp['start'] && $barp['start'] < $spk['start']) || ($barp['end'] && $barp['end'] > $spk['end']) || ($barp['start'] && $barp['end'] && $barp['start'] > $barp['end'])) {
            self::fail('Periode rekonsiliasi harus berada dalam periode SPK.');
        }
        if ($submit) {
            foreach (['date', 'place', 'start', 'end', 'location'] as $field) {
                if ($barp[$field] === '') {
                    self::fail('Lengkapi tanggal, tempat, periode dan lokasi pada Informasi BARP sebelum mengajukan.');
                }
            }
        }
        $term['barp'] = $barp; // Server-owned captured names/IDs survive client overrides and later account renames.

        return $term;
    }

    private static function fail(string $message): never
    {
        throw ValidationException::withMessages(['prototype' => [$message]]);
    }
}
