<?php

namespace App\Services;

use DOMDocument;
use DOMXPath;
use Illuminate\Validation\ValidationException;
use XMLReader;
use ZipArchive;

/** Bounded, row-wise OOXML reader. Never evaluates formulas, links, or macros. */
class BudgetPidWorkbook
{
    public const MAX_PIDS = 10000;

    public const CATEGORIES = ['Material', 'Non Material', 'Tenaga Kerja', 'Depresiasi', 'Operasional'];

    private function invalid(string $message): never
    {
        throw ValidationException::withMessages(['file' => $message]);
    }

    private function xml(string $bytes): DOMXPath
    {
        if ($bytes === '' || preg_match('/<!DOCTYPE|<!ENTITY/i', $bytes)) {
            $this->invalid('XML workbook tidak boleh mengandung DTD/entity.');
        }
        $doc = new DOMDocument;
        if (! $doc->loadXML($bytes, LIBXML_NONET | LIBXML_COMPACT)) {
            $this->invalid('XML workbook tidak valid.');
        }

        return new DOMXPath($doc);
    }

    private function money(string $v, int $row, string $column): int
    {
        if (! preg_match('/\A-?\d+(?:\.\d+)?(?:[Ee][+-]?\d+)?\z/', $v)
            || ! is_finite($n = (float) $v) || abs($n) > 1000000000000 || floor($n) !== $n) {
            $this->invalid("Baris {$row}, {$column}: isi angka rupiah bulat maksimal 1 triliun; jangan gunakan pemisah ribuan/pecahan atau hasil rumus kosong.");
        }

        return (int) $n;
    }

    public function read(string $path): array
    {
        $prior = libxml_use_internal_errors(true);
        $zip = new ZipArchive;
        if ($zip->open($path) !== true) {
            libxml_use_internal_errors($prior);
            $this->invalid('Gunakan workbook .xlsx yang valid.');
        }
        try {
            $size = 0;
            $names = [];
            if ($zip->numFiles > 300) {
                $this->invalid('Terlalu banyak bagian workbook.');
            }
            for ($i = 0; $i < $zip->numFiles; $i++) {
                $stat = $zip->statIndex($i);
                $size += $stat['size'];
                if ($size > 96 * 1024 * 1024 || $stat['size'] > 64 * 1024 * 1024 || isset($names[$stat['name']])
                    || preg_match('~(?:^|/)\.\.(?:/|$)|^/|\\\\~', $stat['name']) || preg_match('/vbaproject|externallinks/i', $stat['name'])) {
                    $this->invalid('Workbook terlalu besar atau struktur/macro/tautan luar tidak didukung.');
                }
                $names[$stat['name']] = true;
            }
            $entry = function (string $name) use ($zip): string {
                $bytes = $zip->getFromName($name);
                if ($bytes === false || preg_match('/<!DOCTYPE|<!ENTITY/i', $bytes)) {
                    $this->invalid('Bagian workbook hilang atau XML tidak aman.');
                }

                return $bytes;
            };
            $book = $this->xml($entry('xl/workbook.xml'));
            $sheets = $book->query('//*[local-name()="sheet"]');
            if ($sheets->length !== 1) {
                $this->invalid('Gunakan satu sheet berisi data budgeting sesuai contoh master PID.');
            }
            $id = $sheets->item(0)->getAttributeNS('http://schemas.openxmlformats.org/officeDocument/2006/relationships', 'id');
            $rels = $this->xml($entry('xl/_rels/workbook.xml.rels'));
            $target = null;
            foreach ($rels->query('//*[local-name()="Relationship"]') as $rel) {
                if ($rel->getAttribute('Id') === $id && $rel->getAttribute('TargetMode') !== 'External') {
                    $target = $rel->getAttribute('Target');
                }
            }
            if (! $target || ! preg_match('~^(?:/xl/)?worksheets/[a-zA-Z0-9_-]+\.xml$~D', $target)) {
                $this->invalid('Relasi sheet workbook tidak valid.');
            }
            $strings = [];
            if (isset($names['xl/sharedStrings.xml'])) {
                $shared = $this->xml($entry('xl/sharedStrings.xml'));
                foreach ($shared->query('//*[local-name()="si"]') as $item) {
                    $v = '';
                    foreach ($shared->query('.//*[local-name()="t"]', $item) as $text) {
                        $v .= $text->textContent;
                    }
                    $strings[] = $v;
                    if (count($strings) > 200000 || strlen($v) > 4000) {
                        $this->invalid('Teks workbook melebihi batas.');
                    }
                }
                unset($shared);
            }
            $xml = $entry(str_starts_with($target, '/') ? ltrim($target, '/') : 'xl/'.$target);
            $reader = new XMLReader;
            if (! $reader->XML($xml, null, LIBXML_NONET | LIBXML_COMPACT)) {
                $this->invalid('Sheet tidak dapat dibaca.');
            }
            $reader->setParserProperty(XMLReader::SUBST_ENTITIES, false);
            $table = [];
            $header = null;
            $seenRows = [];
            $dataRows = 0;
            try {
                while ($reader->read()) {
                    if ($reader->nodeType !== XMLReader::ELEMENT || $reader->localName !== 'row' || $reader->depth !== 2) {
                        continue;
                    }
                    $number = $reader->getAttribute('r');
                    if (! ctype_digit($number ?? '') || (int) $number < 1 || (int) $number > 60001 || isset($seenRows[$number])) {
                        $this->invalid('Baris workbook duplikat/tidak valid atau melebihi 60.000 baris data.');
                    }
                    $seenRows[$number] = true;
                    $number = (int) $number;
                    $row = $this->xml($reader->readOuterXml());
                    $cells = [];
                    foreach ($row->query('/*/*[local-name()="c"]') as $cell) {
                        $addr = $cell->getAttribute('r');
                        if (! preg_match('/\A([A-T])'.$number.'\z/', $addr, $m) || isset($cells[$m[1]])) {
                            $this->invalid("Baris {$number}: alamat sel duplikat/di luar kolom A:T.");
                        }
                        $type = $cell->getAttribute('t');
                        $v = $row->evaluate('string(./*[local-name()="v"])', $cell);
                        if ($type === 's') {
                            if (! ctype_digit($v) || ! isset($strings[(int) $v])) {
                                $this->invalid("Sel {$addr}: referensi teks tidak valid.");
                            }
                            $v = $strings[(int) $v];
                        } elseif ($type === 'inlineStr') {
                            $v = '';
                            foreach ($row->query('./*[local-name()="is"]//*[local-name()="t"]', $cell) as $text) {
                                $v .= $text->textContent;
                            }
                        }
                        $formula = $row->query('./*[local-name()="f"]', $cell)->length > 0;
                        if ($type === 'e' || ($formula && (! in_array($m[1], ['I', 'K', 'M'], true) || ! in_array($type, ['', 'n'], true) || $v === '')) || strlen($v) > 4000) {
                            $this->invalid("Sel {$addr}: rumus hanya boleh pada Release/Tot.cst.cm/Act.costs dengan hasil angka tersimpan. Hitung ulang dan simpan Excel dahulu.");
                        }
                        $cells[$m[1]] = trim($v);
                    }
                    if (! array_filter($cells, fn ($v) => $v !== '')) {
                        continue;
                    }
                    if ($number === 1) {
                        $header = $cells;
                        foreach (['B' => 'Level', 'C' => 'Description', 'D' => 'Title', 'I' => 'Release', 'K' => 'Tot.cst.cm', 'M' => 'Act.costs'] as $col => $label) {
                            if (($cells[$col] ?? '') !== $label) {
                                $this->invalid("Header {$col}1 harus {$label}, sesuai contoh master PID.");
                            }
                        }

                        continue;
                    }
                    if (! $header || ++$dataRows > 60000) {
                        $this->invalid('Header harus di baris 1; maksimal 60.000 baris data / 10.000 PID.');
                    }
                    $level = str_pad($cells['B'] ?? '', 2, '0', STR_PAD_LEFT);
                    $title = strtoupper($cells['D'] ?? '');
                    $description = $cells['C'] ?? '';
                    if (! in_array($level, ['02', '04'], true) || ! preg_match('/\A[A-Z0-9][A-Z0-9_.-]{0,119}\z/', $title) || $description === '' || mb_strlen($description) > 500 || preg_match('/\p{Cc}/u', $description)) {
                        $this->invalid("Baris {$number}: Level, Title atau Description tidak valid.");
                    }
                    $pid = $title;
                    $category = 'TOTAL';
                    if ($level === '04') {
                        if (! in_array($description, self::CATEGORIES, true) || ! preg_match('/\A(.+)-\d{2}-\d{2}\z/', $title, $match)) {
                            $this->invalid("Baris {$number}: kategori/Title Level 04 tidak sesuai contoh.");
                        }
                        $pid = $match[1];
                        $category = $description;
                    }
                    if (strlen($pid) > 100 || isset($table[$pid]['items'][$category])) {
                        $this->invalid("Baris {$number}: PID/kategori duplikat atau PID terlalu panjang.");
                    }
                    foreach (['J', 'L', 'N'] as $col) {
                        if (($cells[$col] ?? '') !== 'IDR') {
                            $this->invalid("Baris {$number}: mata uang harus IDR pada kolom {$col}.");
                        }
                    }
                    $release = $this->money($cells['I'] ?? '', $number, 'Release');
                    $commit = $this->money($cells['K'] ?? '', $number, 'Tot.cst.cm');
                    $actual = $this->money($cells['M'] ?? '', $number, 'Act.costs');
                    $table[$pid]['items'][$category] = ['source_title' => $title, 'source_row' => $number, 'release' => $release, 'commitment' => $commit, 'actual' => $actual, 'available' => $release - $commit - $actual];
                    if ($level === '02') {
                        $table[$pid]['name'] = $description;
                    }
                    if (count($table) > self::MAX_PIDS) {
                        $this->invalid('Maksimal 10.000 PID per impor.');
                    }
                }
                if (libxml_get_errors()) {
                    $this->invalid('Sheet XML rusak atau terpotong.');
                }
            } finally {
                $reader->close();
            }
            if (! $table) {
                $this->invalid('Workbook belum berisi PID.');
            }
            foreach ($table as $pid => &$record) {
                if (! isset($record['name']) || count($record['items']) !== 6) {
                    $this->invalid("PID {$pid}: wajib satu Level 02 dan lima kategori Level 04 lengkap.");
                }
                $ordered = [];
                foreach (['TOTAL', ...self::CATEGORIES] as $category) {
                    $ordered[$category] = $record['items'][$category];
                }
                $record['items'] = $ordered;
            }
            unset($record);
            ksort($table);

            return $table;
        } finally {
            $zip->close();
            libxml_clear_errors();
            libxml_use_internal_errors($prior);
        }
    }
}
