<?php

namespace App\Services;

use Illuminate\Validation\ValidationException;

final class ContractNumber
{
    /** Derive from the selected PP, never a BA KH number, clock or new sequence. */
    public static function fromProject(string $ppNo, string $code): string
    {
        $ppNo = trim($ppNo);
        if ($ppNo === '' || ! in_array($code, ['01', '02'], true)) {
            throw ValidationException::withMessages(['prototype' => 'Nomor PP tersimpan dan kode dokumen 01/02 diperlukan.']);
        }
        $parts = explode('/', $ppNo, 2);
        $number = $parts[0].'-'.$code.(isset($parts[1]) ? '/'.$parts[1] : '');
        if (mb_strlen($number) > 150) {
            throw ValidationException::withMessages(['prototype' => 'Nomor BA KH/SPK turunan melebihi batas 150 karakter.']);
        }

        return $number;
    }
}
