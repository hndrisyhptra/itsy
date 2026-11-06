<?php

namespace App\Services;

use DOMDocument;
use DOMXPath;
use Illuminate\Validation\ValidationException;
use ZipArchive;

/** Small bounded OOXML codec for master templates; no formula evaluation. */
class MasterImportWorkbook
{
    public const MAX_ROWS = 1000;

    public const HEADERS = [
        'designator' => ['kode_designator', 'uraian_pekerjaan', 'satuan', 'kode_jenis'],
        'khs' => ['kode_designator', 'kode_paket', 'harga'],
    ];

    private function invalid(string $message): never
    {
        throw ValidationException::withMessages(['file' => $message]);
    }

    private function xml(string $value): DOMXPath
    {
        if (preg_match('/<!DOCTYPE|<!ENTITY/i', $value)) {
            $this->invalid('XML tidak boleh berisi DTD/entity.');
        }
        $previous = libxml_use_internal_errors(true);
        try {
            $doc = new DOMDocument;
            if (! $doc->loadXML($value, LIBXML_NONET | LIBXML_COMPACT)) {
                $this->invalid('XML workbook tidak valid.');
            }

            return new DOMXPath($doc);
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($previous);
        }
    }

    public function read(string $path, string $entity): array
    {
        $zip = new ZipArchive;
        if ($zip->open($path) !== true) {
            $this->invalid('Gunakan file Excel .xlsx sesuai template.');
        }
        try {
            $bytes = 0;
            if ($zip->numFiles > 300) {
                $this->invalid('Terlalu banyak bagian workbook.');
            }
            for ($i = 0; $i < $zip->numFiles; $i++) {
                $stat = $zip->statIndex($i);
                $bytes += $stat['size'];
                if ($bytes > 32 * 1024 * 1024 || $stat['size'] > 16 * 1024 * 1024 || preg_match('~(?:^|/)\.\.(?:/|$)|^/|\\\\~', $stat['name']) || str_contains(strtolower($stat['name']), 'vbaproject')) {
                    $this->invalid('Workbook terlalu besar atau memiliki struktur/macro yang tidak didukung.');
                }
            }
            $entry = function ($name) use ($zip) {
                $value = $zip->getFromName($name);
                if ($value === false) {
                    $this->invalid('Bagian workbook tidak lengkap.');
                }

                return $value;
            };
            $book = $this->xml($entry('xl/workbook.xml'));
            $sheets = $book->query('//*[local-name()="sheet" and @name="Data"]');
            if ($sheets->length !== 1) {
                $this->invalid('Workbook harus memiliki tepat satu sheet Data. Gunakan template yang diunduh.');
            }
            $id = $sheets->item(0)->getAttributeNS('http://schemas.openxmlformats.org/officeDocument/2006/relationships', 'id');
            $relations = $this->xml($entry('xl/_rels/workbook.xml.rels'));
            $target = null;
            foreach ($relations->query('//*[local-name()="Relationship"]') as $rel) {
                if ($rel->getAttribute('Id') === $id && $rel->getAttribute('TargetMode') !== 'External') {
                    $target = $rel->getAttribute('Target');
                }
            }
            if (! $target || ! preg_match('~^(?:/xl/)?worksheets/[a-zA-Z0-9_-]+\.xml$~D', $target)) {
                $this->invalid('Lokasi sheet Data tidak valid.');
            }
            $strings = [];
            if ($zip->locateName('xl/sharedStrings.xml') !== false) {
                $shared = $this->xml($entry('xl/sharedStrings.xml'));
                foreach ($shared->query('//*[local-name()="si"]') as $item) {
                    $v = '';
                    foreach ($shared->query('.//*[local-name()="t"]', $item) as $text) {
                        $v .= $text->textContent;
                    }
                    $strings[] = $v;
                }
            }
            $sheet = $this->xml($entry(str_starts_with($target, '/') ? ltrim($target, '/') : 'xl/'.$target));
            $cells = $sheet->query('//*[local-name()="sheetData"]/*[local-name()="row"]/*[local-name()="c"]');
            if ($cells->length > 20000) {
                $this->invalid('Sheet Data melebihi batas 20.000 sel.');
            }
            $table = [];
            $seen = [];
            foreach ($cells as $cell) {
                $address = $cell->getAttribute('r');
                if (! preg_match('/^([A-Z]{1,3})([1-9][0-9]{0,3})$/D', $address, $m) || (int) $m[2] > 2000 || isset($seen[$address])) {
                    $this->invalid('Alamat sel duplikat atau baris melebihi batas template.');
                }
                $seen[$address] = true;
                $type = $cell->getAttribute('t');
                $value = $sheet->evaluate('string(./*[local-name()="v"])', $cell);
                if ($type === 's') {
                    if (! ctype_digit($value) || ! array_key_exists((int) $value, $strings)) {
                        $this->invalid('Referensi teks Excel tidak valid.');
                    }
                    $value = $strings[(int) $value];
                } elseif ($type === 'inlineStr') {
                    $value = '';
                    foreach ($sheet->query('./*[local-name()="is"]//*[local-name()="t"]', $cell) as $text) {
                        $value .= $text->textContent;
                    }
                }
                $formula = $sheet->query('./*[local-name()="f"]', $cell)->length > 0;
                if ($type === 'e' || ($formula && ! ($entity === 'khs' && $m[1] === 'C' && (int) $m[2] > 1 && in_array($type, ['', 'n'], true) && preg_match('/^\d+(?:\.\d+)?$/D', $value)))) {
                    $this->invalid("Sel {$address}: gunakan teks/angka valid. Rumus harga hanya diterima bila hasil angkanya sudah tersimpan; hitung ulang lalu simpan Excel.");
                }
                $table[(int) $m[2]][$m[1]] = trim($value);
            }
            ksort($table);
            $headers = self::HEADERS[$entity];
            $letters = range('A', chr(64 + count($headers)));
            if (array_map(fn ($c) => $table[1][$c] ?? '', $letters) !== $headers) {
                $this->invalid('Header sheet Data tidak sesuai. Unduh template '.$entity.' dan jangan ubah nama kolom.');
            }
            $rows = [];
            foreach ($table as $number => $columns) {
                if (count(array_filter($columns, fn ($v) => $v !== '')) === 0) {
                    continue;
                }
                if (array_diff(array_keys(array_filter($columns, fn ($v) => $v !== '')), $letters)) {
                    $this->invalid("Baris {$number}: ada data di luar kolom template.");
                }
                if ($number === 1) {
                    continue;
                }
                $rows[] = ['row' => $number, 'data' => array_combine($headers, array_map(fn ($c) => $columns[$c] ?? '', $letters))];
                if (count($rows) > self::MAX_ROWS) {
                    $this->invalid('Maksimal '.self::MAX_ROWS.' baris data per impor.');
                }
            }
            if (! $rows) {
                $this->invalid('Sheet Data masih kosong. Isi mulai baris 2; sheet referensi tidak diimpor.');
            }

            return $rows;
        } finally {
            $zip->close();
        }
    }

    /** Generate in memory: Apache may not access the CLI user's macOS TMPDIR. */
    public function template(string $entity, array $catalog): string
    {
        $sheets = ['Data' => [self::HEADERS[$entity]], 'Panduan' => [['Petunjuk'],
            ['Isi sheet Data mulai baris 2. Jangan ubah header. Maksimal '.self::MAX_ROWS.' baris / 5 MB.'],
            ['Gunakan kode persis dari sheet referensi. ID database dibuat otomatis.'],
            ['Tambah baru menolak kode/pasangan yang sudah ada. Tambah dan perbarui mencocokkan kode designator atau pasangan designator-paket.'],
            ['Data arsip tidak dipulihkan. Identitas yang telah digunakan BOQ tidak dapat diubah.'],
            ['Harga: angka rupiah tanpa Rp/pemisah ribuan, contoh 141810 atau 141810.25. Harga nol valid; kosong tidak valid.'],
            ['Untuk rumus harga, hitung ulang dan simpan hasil angka sebelum upload. Rumus tidak dijalankan aplikasi.'],
            ['Periksa file dahulu. Jika ada baris salah, seluruh batch tidak disimpan.']],
            'Jenis' => [['kode_jenis', 'nama_jenis']], 'Paket' => [['kode_paket', 'nama_paket']],
        ];
        foreach ($catalog['types'] as $t) {
            $sheets['Jenis'][] = [$t->code, $t->name];
        }
        foreach ($catalog['packages'] as $p) {
            $sheets['Paket'][] = [$p->code, $p->name];
        }
        if ($entity === 'khs') {
            $sheets['Designator'] = [['kode_designator', 'uraian_pekerjaan', 'satuan']];
            foreach ($catalog['designators'] as $d) {
                if (in_array((int) $d->designator_type_id, [1, 2], true)) {
                    $sheets['Designator'][] = [$d->code, $d->item_name, $d->unit];
                }
            }
        }
        $entries = [];
        $escape = fn ($v) => htmlspecialchars((string) $v, ENT_XML1 | ENT_QUOTES, 'UTF-8');
        $types = '<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types"><Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/><Default Extension="xml" ContentType="application/xml"/><Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/>';
        $book = '<workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships"><sheets>';
        $rels = '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">';
        $index = 0;
        foreach ($sheets as $name => $rows) {
            $index++;
            $types .= '<Override PartName="/xl/worksheets/sheet'.$index.'.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/>';
            $book .= '<sheet name="'.$name.'" sheetId="'.$index.'" r:id="rId'.$index.'"/>';
            $rels .= '<Relationship Id="rId'.$index.'" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet'.$index.'.xml"/>';
            $xml = '<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"><sheetViews><sheetView workbookViewId="0"><pane ySplit="1" topLeftCell="A2" activePane="bottomLeft" state="frozen"/></sheetView></sheetViews><cols><col min="1" max="1" width="32" customWidth="1"/><col min="2" max="2" width="65" customWidth="1"/><col min="3" max="4" width="24" customWidth="1"/></cols><sheetData>';
            foreach ($rows as $n => $row) {
                $xml .= '<row r="'.($n + 1).'">';
                foreach ($row as $c => $value) {
                    $xml .= '<c r="'.chr(65 + $c).($n + 1).'" s="'.($n === 0 ? 1 : 0).'" t="inlineStr"><is><t xml:space="preserve">'.$escape($value).'</t></is></c>';
                }
                $xml .= '</row>';
            }
            $entries['xl/worksheets/sheet'.$index.'.xml'] = $xml.'</sheetData></worksheet>';
        }
        $types .= '<Override PartName="/xl/styles.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.styles+xml"/></Types>';
        $rels .= '<Relationship Id="styles" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/styles" Target="styles.xml"/></Relationships>';
        $entries['[Content_Types].xml'] = $types;
        $entries['_rels/.rels'] = '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/></Relationships>';
        $entries['xl/workbook.xml'] = $book.'</sheets></workbook>';
        $entries['xl/_rels/workbook.xml.rels'] = $rels;
        $entries['xl/styles.xml'] = '<styleSheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"><fonts count="2"><font><sz val="11"/><name val="Calibri"/></font><font><b/><sz val="11"/><name val="Calibri"/></font></fonts><fills count="3"><fill><patternFill patternType="none"/></fill><fill><patternFill patternType="gray125"/></fill><fill><patternFill patternType="solid"><fgColor rgb="FF9FA1FF"/><bgColor indexed="64"/></patternFill></fill></fills><borders count="1"><border><left style="thin"/><right style="thin"/><top style="thin"/><bottom style="thin"/></border></borders><cellStyleXfs count="1"><xf/></cellStyleXfs><cellXfs count="2"><xf fontId="0" fillId="0" borderId="0"/><xf fontId="1" fillId="2" borderId="0" applyFill="1" applyFont="1"/></cellXfs></styleSheet>';

        return $this->archive($entries);
    }

    /** Minimal standard ZIP (deflate/CRC/central directory); paths are fixed server-owned XML parts. */
    private function archive(array $entries): string
    {
        $body = '';
        $directory = '';
        foreach ($entries as $name => $xml) {
            $compressed = gzdeflate($xml, 6);
            if ($compressed === false) {
                throw new \RuntimeException('Template tidak dapat dikompresi.');
            }
            $offset = strlen($body);
            $crc = crc32($xml);
            $size = strlen($xml);
            $compressedSize = strlen($compressed);
            $nameSize = strlen($name);
            // UTF-8 names, deflate, DOS epoch 1980-01-01; no disk or temporary directory.
            $body .= pack('VvvvvvVVVvv', 0x04034B50, 20, 0x0800, 8, 0, 33, $crc, $compressedSize, $size, $nameSize, 0).$name.$compressed;
            $directory .= pack('VvvvvvvVVVvvvvvVV', 0x02014B50, 20, 20, 0x0800, 8, 0, 33, $crc, $compressedSize, $size, $nameSize, 0, 0, 0, 0, 0, $offset).$name;
        }
        $count = count($entries);

        return $body.$directory.pack('VvvvvVVv', 0x06054B50, 0, 0, $count, $count, strlen($directory), strlen($body), 0);
    }
}
