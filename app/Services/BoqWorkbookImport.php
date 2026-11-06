<?php

namespace App\Services;

use DOMDocument;
use DOMXPath;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use ZipArchive;

/** Bounded, read-only OOXML reader for the supplied OSP FO BOQ layout. Never evaluates formulas. */
class BoqWorkbookImport
{
    private function invalid(string $message): never
    {
        throw ValidationException::withMessages(['file' => $message]);
    }

    private function xml(string $bytes): DOMXPath
    {
        if (preg_match('/<!DOCTYPE|<!ENTITY/i', $bytes)) {
            $this->invalid('XML BOQ tidak boleh berisi DTD/entity.');
        }
        $previous = libxml_use_internal_errors(true);
        try {
            $document = new DOMDocument;
            if (! $document->loadXML($bytes, LIBXML_NONET | LIBXML_COMPACT)) {
                $this->invalid('Struktur XML file Excel tidak valid.');
            }

            return new DOMXPath($document);
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($previous);
        }
    }

    private function entry(ZipArchive $zip, string $name): string
    {
        $bytes = $zip->getFromName($name);
        if ($bytes === false) {
            $this->invalid('Bagian workbook Excel tidak lengkap: '.$name.'.');
        }

        return $bytes;
    }

    private function blank(array $cell): bool
    {
        if (trim($cell['value'] ?? '') !== '') {
            return false;
        }

        // A formula without a saved result is unknown, not a zero. Excel stores
        // an explicitly empty string result as t="str" with an empty <v>.
        if ($cell['formula'] ?? false) {
            return ($cell['type'] ?? '') === 'str' && ($cell['hasValue'] ?? false);
        }

        return in_array($cell['type'] ?? '', ['', 'n', 's', 'inlineStr', 'str'], true);
    }

    private function decimal(array $cell, int $row, string $column, int $scale, bool $allowBlank = false): float
    {
        if ($allowBlank && $this->blank($cell)) {
            return 0.0;
        }
        $raw = trim($cell['value'] ?? '');
        // Read only the saved numeric value. Never evaluate the formula, fetch
        // external links, or use Excel's calculated row/footer totals.
        if (strlen($raw) > 24 || ! in_array($cell['type'] ?? '', ['', 'n'], true) || ! preg_match('/^\d+(?:\.\d+)?$/D', $raw)) {
            $this->invalid(($cell['formula'] ?? false)
                ? "Hasil rumus sel {$column}{$row} belum tersimpan sebagai angka valid. Hitung ulang lalu simpan file di Excel/LibreOffice dan upload kembali."
                : "Sel {$column}{$row} harus angka tidak negatif, bukan teks atau error Excel.");
        }
        $fraction = str_contains($raw, '.') ? rtrim(explode('.', $raw, 2)[1], '0') : '';
        if (bccomp($raw, '999999999999', max($scale, strlen($fraction))) > 0 || strlen($fraction) > $scale) {
            $this->invalid("Sel {$column}{$row} melebihi batas angka atau ketelitian {$scale} desimal.");
        }

        return (float) $raw;
    }

    public function parse(string $path, array $catalog): array
    {
        $zip = new ZipArchive;
        if ($zip->open($path) !== true) {
            $this->invalid('File harus workbook .xlsx yang valid, bukan .xls atau file yang diganti namanya.');
        }
        try {
            if ($zip->numFiles > 300) {
                $this->invalid('Workbook memiliki terlalu banyak bagian.');
            }
            $total = 0;
            for ($i = 0; $i < $zip->numFiles; $i++) {
                $stat = $zip->statIndex($i);
                $total += $stat['size'];
                if ($total > 32 * 1024 * 1024 || $stat['size'] > 16 * 1024 * 1024 || preg_match('~(?:^|/)\.\.(?:/|$)|^/|\\\\~', $stat['name']) || str_contains(strtolower($stat['name']), 'vbaproject')) {
                    $this->invalid('Ukuran/struktur workbook tidak didukung. Gunakan template .xlsx tanpa macro.');
                }
            }
            $book = $this->xml($this->entry($zip, 'xl/workbook.xml'));
            $sheet = $book->query('//*[local-name()="sheet" and translate(@name,"BOQ","boq")="boq"]');
            if ($sheet->length !== 1) {
                $this->invalid('Template harus memiliki tepat satu sheet bernama BoQ.');
            }
            $sheet = $sheet->item(0);
            $relId = $sheet->getAttributeNS('http://schemas.openxmlformats.org/officeDocument/2006/relationships', 'id');
            $rels = $this->xml($this->entry($zip, 'xl/_rels/workbook.xml.rels'));
            $target = null;
            foreach ($rels->query('//*[local-name()="Relationship"]') as $rel) {
                if ($rel->getAttribute('Id') === $relId && $rel->getAttribute('TargetMode') !== 'External') {
                    $target = $rel->getAttribute('Target');
                }
            }
            if (! $target || ! preg_match('~^(?:/xl/)?(?:worksheets/)[a-zA-Z0-9_-]+\.xml$~D', $target)) {
                $this->invalid('Lokasi sheet BoQ tidak valid.');
            }
            $strings = [];
            if ($zip->locateName('xl/sharedStrings.xml') !== false) {
                $shared = $this->xml($this->entry($zip, 'xl/sharedStrings.xml'));
                foreach ($shared->query('//*[local-name()="si"]') as $item) {
                    $value = '';
                    foreach ($shared->query('.//*[local-name()="t"]', $item) as $text) {
                        $value .= $text->textContent;
                    }
                    $strings[] = $value;
                }
            }
            $sheetXml = $this->xml($this->entry($zip, str_starts_with($target, '/') ? ltrim($target, '/') : 'xl/'.$target));
            $table = [];
            $cells = $sheetXml->query('//*[local-name()="sheetData"]/*[local-name()="row"]/*[local-name()="c"]');
            if ($cells->length > 100000) {
                $this->invalid('Workbook melebihi 100.000 sel.');
            }
            foreach ($cells as $cell) {
                if (! preg_match('/^([A-Z]{1,3})([1-9][0-9]{0,4})$/D', $cell->getAttribute('r'), $match) || (int) $match[2] > 10000) {
                    $this->invalid('Alamat sel atau jumlah baris melebihi batas template.');
                }
                [$address, $column, $row] = $match;
                if (! in_array($column, ['A', 'B', 'D', 'E', 'F', 'G'], true)) {
                    continue;
                }
                $type = $cell->getAttribute('t');
                $value = $sheetXml->evaluate('string(./*[local-name()="v"])', $cell);
                if ($type === 's') {
                    if (! ctype_digit($value) || ! array_key_exists((int) $value, $strings)) {
                        $this->invalid('Referensi teks Excel tidak valid.');
                    }
                    $value = $strings[(int) $value];
                } elseif ($type === 'inlineStr') {
                    $value = '';
                    foreach ($sheetXml->query('./*[local-name()="is"]//*[local-name()="t"]', $cell) as $text) {
                        $value .= $text->textContent;
                    }
                }
                if (isset($table[(int) $row][$column])) {
                    $this->invalid('Alamat sel duplikat: '.$address.'.');
                }
                $table[(int) $row][$column] = ['value' => trim($value), 'type' => $type, 'formula' => $sheetXml->query('./*[local-name()="f"]', $cell)->length > 0, 'hasValue' => $sheetXml->query('./*[local-name()="v"]', $cell)->length > 0];
            }
            $text = fn ($r, $c) => mb_strtoupper(preg_replace('/\s+/', ' ', $table[$r][$c]['value'] ?? ''));
            if ($text(6, 'B') !== 'DESIGNATOR' || $text(6, 'D') !== 'SATUAN' || $text(6, 'G') !== 'VOL' || $text(7, 'E') !== 'MATERIAL' || $text(7, 'F') !== 'JASA' || ! preg_match('/PAKET[ -]?(\d+)/', $text(6, 'E'), $package)) {
                $this->invalid('Header template tidak sesuai. Gunakan sheet BoQ: B6 DESIGNATOR, D6 SATUAN, E6 Paket, G6 VOL, E7 MATERIAL, F7 JASA.');
            }
            $packageKey = 'PAKET'.(int) $package[1];
            $items = array_values(array_filter($catalog, fn ($k) => $k['active'] && preg_replace('/[ -]/', '', mb_strtoupper($k['package'])) === $packageKey));
            if (! $items) {
                $this->invalid('Paket '.$package[1].' belum tersedia/aktif pada Master KHS.');
            }
            $byCode = collect($items)->groupBy(fn ($k) => mb_strtoupper(trim($k['code'])));
            $rows = [];
            $seen = [];
            $sourceRows = 0;
            $sourceTotal = '0.00';
            ksort($table);
            foreach ($table as $row => $columns) {
                if ($row < 8) {
                    continue;
                }
                if ($this->blank($columns['G'] ?? [])) {
                    continue;
                }
                $code = $text($row, 'B');
                if ($code === '' && (in_array($text($row, 'A'), ['MATERIAL', 'JASA', 'TOTAL'], true) || $text($row, 'G') === 'TOTAL')) {
                    continue; // Footer totals are never quantities or source prices.
                }
                $qty = $this->decimal($columns['G'] ?? [], $row, 'G', 3);
                if ($qty > 1000000000) {
                    $this->invalid("Volume G{$row} tidak boleh melebihi satu miliar.");
                }
                if ($qty === 0.0) {
                    continue;
                }
                if ($code === '' || ($columns['B']['formula'] ?? false) || ($columns['D']['formula'] ?? false)) {
                    $this->invalid("Designator/satuan baris {$row} tidak valid.");
                }
                $prices = ['m' => $this->decimal($columns['E'] ?? [], $row, 'E', 2, true), 's' => $this->decimal($columns['F'] ?? [], $row, 'F', 2, true)];
                $prefixed = preg_match('/^([MJ])-/', $code, $component);
                $matched = false;
                foreach (['m' => 'M-', 's' => 'J-'] as $c => $prefix) {
                    if ($prefixed && $prefix[0] !== $component[1]) {
                        if ($prices[$c] !== 0.0) {
                            $this->invalid("Komponen harga tidak sesuai prefix designator baris {$row}.");
                        }

                        continue;
                    }
                    $matches = $byCode->get($prefixed ? $code : $prefix.$code, collect())->filter(fn ($k) => $k[$c] !== null)->values();
                    if ($matches->isEmpty() && ! $prefixed && $prices[$c] === 0.0) {
                        continue; // Missing component is N/A; never fabricate a zero-priced row.
                    }
                    if ($matches->isEmpty()) {
                        $this->invalid("Baris {$row}: designator ".($prefixed ? $code : $prefix.$code).' belum memiliki harga KHS aktif pada Paket '.$package[1].'. Lengkapi melalui Master Designator > KHS.');
                    }
                    if ($matches->count() !== 1) {
                        $this->invalid("Baris {$row}: designator ".($prefixed ? $code : $prefix.$code).' memiliki lebih dari satu harga KHS pada Paket '.$package[1].'. Periksa Master Designator > KHS.');
                    }
                    $k = $matches->first();
                    if (mb_strtolower(trim($k['unit'])) !== mb_strtolower(trim($columns['D']['value'] ?? ''))) {
                        $this->invalid("Satuan baris {$row} tidak sama dengan Master KHS untuk {$k['code']}.");
                    }
                    if (isset($seen[$k['id']])) {
                        $this->invalid("Designator {$k['code']} memiliki volume positif duplikat (baris {$row}). Gabungkan volume pada satu baris sumber.");
                    }
                    $seen[$k['id']] = true;
                    $matched = true;
                    $excelM = $c === 'm' ? $prices['m'] : null;
                    $excelS = $c === 's' ? $prices['s'] : null;
                    $rows[] = ['id' => 'row-'.Str::uuid(), 'khsId' => $k['id'], 'qty' => $qty, 'm' => $k['m'], 's' => $k['s'], 'excelM' => $excelM, 'excelS' => $excelS, 'version' => $k['version'], 'reviewed' => $excelM === $k['m'] && $excelS === $k['s'], 'note' => '', 'sourceRow' => $row, 'sourceCode' => $code, 'sourceQty' => $qty];
                }
                if (! $matched) {
                    $this->invalid("Designator baris {$row} tidak ditemukan pada master.");
                }
                $sourceRows++;
                $sourceTotal = bcadd($sourceTotal, bcmul((string) $qty, bcadd((string) $prices['m'], (string) $prices['s'], 2), 2), 2);
            }
            if (! $rows || count($rows) > 500) {
                $this->invalid('File harus memiliki 1–500 komponen BOQ dengan volume positif. Baris volume nol diabaikan.');
            }

            return ['rows' => $rows, 'source' => ['sheet' => $sheet->getAttribute('name'), 'package' => 'Paket '.(int) $package[1], 'sourceRows' => $sourceRows, 'sourceTotal' => (float) $sourceTotal]];
        } finally {
            $zip->close();
        }
    }
}
