<?php

namespace App\Services;

use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/** Server authority for the demo workflow. The React calculations are UX previews. */
class PrototypeDomain
{
    use PrototypeWorkflowV2;

    public const ROLES = ['Admin Konstruksi', 'PM Konstruksi', 'Manager Konstruksi', 'Admin Area', 'Mgr Deployment', 'Mgr Bussiness Support', 'Head of PMO', 'Finance', 'Superadmin'];

    private array $state;

    public function execute(array $state, string $command, array $args, ?string $resultId = null): array
    {
        $this->state = $state;
        $result = match ($command) {
            'saveProject' => ($state['schema'] ?? 1) >= 2 ? $this->saveProjectV2($this->object($args[0] ?? null), (bool) ($args[1] ?? false)) : $this->saveProject($this->object($args[0] ?? null), (bool) ($args[1] ?? false)),
            'decideProject' => $this->decideProject($args[0] ?? '', $args[1] ?? '', $args[2] ?? ''),
            'assignProjectApprovers' => $this->assignProjectApprovers($args[0] ?? '', $args[1] ?? '', $args[2] ?? ''),
            'assignPaymentApprovers' => $this->assignPaymentApprovers($args[0] ?? '', $this->object($args[1] ?? null)),
            'setNumberFormat' => $this->setNumberFormat($args[0] ?? ''),
            'selectActor' => $this->selectActor($args[0] ?? ''),
            '_attachTermScan' => $this->attachTermScan($args[0] ?? '', $this->object($args[1] ?? null)),
            'verifyTermScan' => $this->verifyTermScan($args[0] ?? '', $args[1] ?? '', $args[2] ?? ''),
            'importDemo' => $this->importDemo($args[0] ?? ''),
            'reviewBoq' => $this->reviewBoq($args[0] ?? '', $args[1] ?? '', $args[2] ?? ''),
            'finalizeBoq' => $this->finalizeBoq($args[0] ?? ''),
            'saveDeal' => $this->saveDeal($this->object($args[0] ?? null), $args[1] ?? 'save'),
            'saveSpk' => $this->saveSpk($this->object($args[0] ?? null), $args[1] ?? 'save'),
            'createTerm' => $this->createTerm($args[0] ?? '', $resultId),
            'saveTerm' => $this->saveTerm($this->object($args[0] ?? null), (bool) ($args[1] ?? false)),
            'decide' => $this->decide($args[0] ?? '', $args[1] ?? '', $args[2] ?? ''),
            'recordApm' => $this->recordApm($args[0] ?? '', $args[1] ?? '', (bool) ($args[2] ?? true)),
            'recordPayment' => $this->recordPayment($args[0] ?? '', $args[1] ?? 0, $args[2] ?? ''),
            'attestBapp' => $this->attestBapp($args[0] ?? '', $args[1] ?? '', $args[2] ?? ''),
            'releaseVolume' => $this->releaseVolume($args[0] ?? '', $this->object($args[1] ?? null)),
            'createContinuation' => $this->createContinuation($args[0] ?? '', $args[1] ?? '', $this->object($args[2] ?? null), $args[3] ?? '', $resultId),
            'updateKhs' => $this->updateKhs($this->object($args[0] ?? null), (bool) ($args[1] ?? false)),
            'saveUser' => $this->saveUser($this->object($args[0] ?? null), (bool) ($args[1] ?? false)),
            'saveProfile' => $this->saveProfile($args[0] ?? '', $args[1] ?? ''),
            'role' => $this->changeRole($args[0] ?? ''),
            default => $this->fail('Perintah prototipe tidak dikenal.'),
        };
        $this->assertLedger();
        $this->state['lastSaved'] = now()->toISOString();

        return ['state' => $this->state, 'result' => $result];
    }

    private function fail(string $message): never
    {
        throw ValidationException::withMessages(['prototype' => [$message]]);
    }

    private function object(mixed $value): array
    {
        if (! is_array($value)) {
            $this->fail('Format data tidak valid.');
        }

        return $value;
    }

    private function admin(): void
    {
        abort_unless(in_array($this->state['role'], ['Admin Konstruksi', 'Superadmin'], true), 403, 'Tindakan hanya tersedia bagi Admin Konstruksi/Superadmin.');
    }

    private function finance(): void
    {
        abort_unless($this->state['role'] === 'Finance', 403, 'Tindakan hanya tersedia bagi Finance.');
    }

    private function index(string $collection, string $id): int
    {
        foreach ($this->state[$collection] as $i => $row) {
            if ($row['id'] === $id) {
                return $i;
            }
        }
        $this->fail('Rekaman tidak ditemukan dalam workspace demo ini.');
    }

    public function record(string $collection, string $id): array
    {
        return $this->state[$collection][$this->index($collection, $id)];
    }

    private function existing(string $collection, string $id): ?array
    {
        foreach ($this->state[$collection] as $row) {
            if ($row['id'] === $id) {
                return $row;
            }
        }

        return null;
    }

    private function put(string $collection, array $row): void
    {
        $this->id($row['id'] ?? '');
        foreach ($this->state[$collection] as $i => $v) {
            if ($v['id'] === $row['id']) {
                $this->state[$collection][$i] = $row;

                return;
            }
        }
        $this->state[$collection][] = $row;
    }

    private function id(string $id): string
    {
        if (! preg_match('/^[a-zA-Z0-9-]{3,100}$/D', $id)) {
            $this->fail('ID rekaman tidak valid.');
        }

        return $id;
    }

    private function text(mixed $v, int $max = 50000): string
    {
        if (! is_string($v) || mb_strlen($v) > $max) {
            $this->fail('Teks tidak valid atau terlalu panjang.');
        }

        return trim($v);
    }

    private function required(array $input, array $keys): void
    {
        foreach ($keys as $key) {
            if ($this->text($input[$key] ?? '') === '') {
                $this->fail('Lengkapi kolom wajib: '.$key.'.');
            }
        }
    }

    private function number(mixed $v, bool $volume = false): float
    {
        if ((! is_int($v) && ! is_float($v)) || ! is_finite((float) $v) || $v < 0 || $v > 1000000000000) {
            $this->fail('Angka harus nonnegatif, terbatas dan valid.');
        }
        if ($volume && (abs($v * 1000 - round($v * 1000)) > 0.000001 || $v > 1000000000)) {
            $this->fail('Volume maksimal tiga angka desimal dan tidak melebihi batas.');
        }

        return (float) $v;
    }

    private function date(mixed $value): string
    {
        $value = $this->text($value, 10);
        $date = \DateTimeImmutable::createFromFormat('!Y-m-d', $value);
        if (! $date || $date->format('Y-m-d') !== $value) {
            $this->fail('Tanggal harus valid dalam format YYYY-MM-DD.');
        }

        return $value;
    }

    private function enum(mixed $value, array $values): string
    {
        if (! in_array($value, $values, true)) {
            $this->fail('Pilihan status/tindakan tidak valid.');
        }

        return $value;
    }

    private function log(string $title, string $detail, string $route): void
    {
        array_unshift($this->state['events'], ['id' => 'evt-'.Str::uuid(), 'title' => $title, 'detail' => $detail, 'route' => $route, 'actor' => $this->state['role'], 'at' => now()->toISOString()]);
    }

    private function roundAmount(string $value): float
    {
        return (float) bcdiv(bcadd($value, '0.005', 6), '1', 2);
    }

    private function product(float|int $qty, float|int $price): string
    {
        return bcmul(sprintf('%.6F', $qty), sprintf('%.6F', $price), 6);
    }

    public function dealTotal(array $deal): float
    {
        $sum = '0';
        foreach ($deal['rows'] as $r) {
            $line = bcadd($this->product($r['m'] ? $r['qm'] : 0, $r['pm']), $this->product($r['s'] ? $r['qs'] : 0, $r['ps']), 6);
            $sum = bcadd($sum, (string) $this->roundAmount($line), 2);
        }

        return (float) $sum;
    }

    public function termTotal(array $term): float
    {
        $deal = $this->record('deals', $this->record('spks', $term['spkId'])['dealId']);
        $sum = '0';
        foreach ($deal['rows'] as $r) {
            $actual = $term['actuals'][$r['rowId']] ?? ['m' => 0, 's' => 0];
            $line = bcadd($this->product($r['m'] ? $actual['m'] : 0, $r['pm']), $this->product($r['s'] ? $actual['s'] : 0, $r['ps']), 6);
            $sum = bcadd($sum, (string) $this->roundAmount($line), 2);
        }

        return (float) $sum;
    }

    public function boqTotal(array $p): float
    {
        $sum = '0';
        foreach ($p['boq'] as $r) {
            $line = bcadd($this->product($r['qty'], $r['m'] ?? 0), $this->product($r['qty'], $r['s'] ?? 0), 6);
            $sum = bcadd($sum, (string) $this->roundAmount($line), 2);
        }

        return (float) $sum;
    }

    private function accepted(string $spkId, string $rowId, string $c): float
    {
        $total = 0;
        foreach ($this->state['terms'] as $t) {
            if ($t['spkId'] === $spkId && $t['status'] === 'Disetujui') {
                $total += $t['actuals'][$rowId][$c] ?? 0;
            }
        }

        return round($total, 3);
    }

    private function released(string $spkId, string $rowId, string $c): float
    {
        $total = 0;
        foreach ($this->state['bapps'] as $b) {
            if ($this->record('terms', $b['termId'])['spkId'] === $spkId) {
                $total += $b['released'][$rowId][$c] ?? 0;
            }
        }

        return round($total, 3);
    }

    private function remaining(string $spkId, array $r, string $c): float
    {
        $qty = $r[$c] ? $r[$c === 'm' ? 'qm' : 'qs'] : 0;

        return round($qty - $this->accepted($spkId, $r['rowId'], $c) - $this->released($spkId, $r['rowId'], $c), 3);
    }

    public function ledger(string $rootId, string $rowId, string $c, ?string $excludeDeal = null): array
    {
        $root = $this->record('projects', $rootId);
        $row = collect($root['boq'])->firstWhere('id', $rowId);
        if (! $row) {
            $this->fail('Baris BOQ tidak ditemukan.');
        }
        $reserved = $committed = $accepted = 0;
        foreach ($this->state['projects'] as $p) {
            if ($p['rootId'] !== $rootId) {
                continue;
            }
            $assigned = 0;
            foreach ($this->state['deals'] as $d) {
                if ($d['projectId'] !== $p['id'] || $d['id'] === $excludeDeal) {
                    continue;
                }
                $r = collect($d['rows'])->firstWhere('rowId', $rowId);
                if (! $r) {
                    continue;
                }
                $q = $r[$c] ? $r[$c === 'm' ? 'qm' : 'qs'] : 0;
                $assigned += $q;
                $spk = collect($this->state['spks'])->first(fn ($v) => $v['dealId'] === $d['id'] && $v['status'] === 'Sah');
                if (! $spk) {
                    $reserved += $q;
                } else {
                    $a = $this->accepted($spk['id'], $rowId, $c);
                    $accepted += $a;
                    $committed += $q - $a - $this->released($spk['id'], $rowId, $c);
                }
            }
            if (! empty($p['parentId'])) {
                $reserved += max(0, ($p['allocations'][$rowId][$c] ?? 0) - $assigned);
            }
        }
        $total = $row[$c] !== null ? $row['qty'] : 0;

        return ['total' => $total, 'available' => round($total - $reserved - $committed - $accepted, 3), 'reserved' => round($reserved, 3), 'committed' => round($committed, 3), 'accepted' => round($accepted, 3)];
    }

    private function capacity(array $p, string $rowId, string $c, ?string $excludeDeal): float
    {
        if (empty($p['parentId'])) {
            return $this->ledger($p['rootId'], $rowId, $c, $excludeDeal)['available'];
        }
        $used = 0;
        foreach ($this->state['deals'] as $d) {
            if ($d['projectId'] !== $p['id'] || $d['id'] === $excludeDeal) {
                continue;
            }
            $r = collect($d['rows'])->firstWhere('rowId', $rowId);
            if ($r && $r[$c]) {
                $used += $r[$c === 'm' ? 'qm' : 'qs'];
            }
        }

        return round(($p['allocations'][$rowId][$c] ?? 0) - $used, 3);
    }

    private function assertLedger(): void
    {
        foreach ($this->state['projects'] as $p) {
            if (! empty($p['parentId']) || $p['boqStatus'] !== 'Final') {
                continue;
            }
            foreach ($p['boq'] as $r) {
                foreach (['m', 's'] as $c) {
                    $l = $this->ledger($p['id'], $r['id'], $c);
                    foreach ($l as $number) {
                        if ($number < -0.00001) {
                            $this->fail('Transaksi melanggar ketersediaan ledger volume.');
                        }
                    }
                }
            }
        }
    }

    private function saveProject(array $p, bool $attest): string
    {
        $this->admin();
        $old = $this->existing('projects', $p['id'] ?? '');
        if ($old && $old['status'] === 'Sah') {
            $this->fail('PP sah terkunci.');
        }
        $this->required($p, ['no', 'name', 'unit', 'branch', 'projectCode', 'date']);
        $p['no'] = $this->text($p['no'], 150);
        foreach ($this->state['projects'] as $v) {
            if ($v['id'] !== $p['id'] && mb_strtolower($v['no']) === mb_strtolower($p['no'])) {
                $this->fail('No PP sudah digunakan.');
            }
        }
        if ($this->number($p['budget'] ?? 0) <= 0) {
            $this->fail('Anggaran harus lebih dari nol.');
        }
        $this->date($p['date']);
        if (! $old && (! empty($p['parentId']) || ($p['rootId'] ?? '') !== $p['id'])) {
            $this->fail('PP lanjutan harus dibuat dari pelepasan BA PP.');
        }
        if ($old) {
            foreach (['rootId', 'parentId', 'sourceBapp', 'allocations', 'boq', 'boqStatus', 'continuationNotes'] as $key) {
                unset($p[$key]);
                if (array_key_exists($key, $old)) {
                    $p[$key] = $old[$key];
                }
            }
            if (! empty($old['parentId'])) {
                $p['budget'] = $old['budget'];
            }
        } else {
            $p['boq'] = [];
            $p['boqStatus'] = 'Belum diimpor';
            $p['allocations'] = [];
        }
        foreach (['intro', 'risk', 'communication', 'quality'] as $key) {
            $p[$key] = $this->text($p[$key] ?? '');
        }
        if (count($p['schedule'] ?? []) !== 5) {
            $this->fail('Jadwal PP harus memiliki lima tahap.');
        }
        $templated = ($this->state['schema'] ?? 1) >= 3 && ($p['planTemplateVersion'] ?? null) === 1;
        foreach ($p['schedule'] as $schedule) {
            $this->number($schedule['days'] ?? -1);
            if (($attest && (! $templated || $schedule['days'] > 0)) || ! empty($schedule['start'])) {
                $this->date($schedule['start'] ?? '');
            }
            if (($attest && (! $templated || $schedule['days'] > 0)) || ! empty($schedule['end'])) {
                $this->date($schedule['end'] ?? '');
            }
            if (! empty($schedule['start']) && ! empty($schedule['end']) && $schedule['start'] > $schedule['end']) {
                $this->fail('Jadwal proyek terbalik.');
            }
        }
        foreach (['mandor', 'material', 'bop', 'permit'] as $key) {
            $this->number($p['costs'][$key] ?? -1);
        }
        if (count($p['team'] ?? []) > 100) {
            $this->fail('Maksimal 100 anggota tim.');
        }
        foreach ($p['team'] ?? [] as $member) {
            if ($attest) {
                $this->required($member, ['name', 'job']);
            }
        }
        if ($attest && (! $p['intro'] || (! $templated && ! $p['team']) || (! isset($p['planBudget']) && ! $p['risk']) || ! $p['communication'] || ! $p['quality'])) {
            $this->fail('Lengkapi pendahuluan, tim, risiko, komunikasi dan kualitas.');
        }
        $p['status'] = $attest ? 'Sah' : 'Draft';
        $this->put('projects', $p);
        $this->log($attest ? 'PP disahkan' : 'Draft PP disimpan', $p['no'], '/project-plan/'.$p['id']);

        return $p['id'];
    }

    private function importDemo(string $id): null
    {
        $this->admin();
        $p = $this->record('projects', $id);
        if ($p['boqStatus'] === 'Final' || ! empty($p['parentId'])) {
            $this->fail('BOQ final/lanjutan tidak dapat diimpor ulang.');
        }
        $p['boq'] = [];
        foreach (array_slice(array_values(array_filter($this->state['khs'], fn ($k) => $k['active'])), 0, 6) as $i => $k) {
            $p['boq'][] = ['id' => $id.'-row-'.$i, 'khsId' => $k['id'], 'qty' => [1200, 800, 12, 4, 8, 6][$i], 'excelM' => $k['m'] === null ? null : $k['m'] + ($i === 1 ? 500 : 0), 'excelS' => $k['s'], 'reviewed' => $i !== 1, 'note' => '', 'm' => $k['m'], 's' => $k['s'], 'version' => $k['version']];
        }
        $p['boqStatus'] = 'Review';
        $this->put('projects', $p);
        $this->log('Simulasi BOQ dimuat', $p['no'], '/boq/'.$id);

        return null;
    }

    private function reviewBoq(string $id, string $rowId, string $note): null
    {
        $this->admin();
        $p = $this->record('projects', $id);
        if ($p['boqStatus'] !== 'Review') {
            $this->fail('BOQ bukan draft review.');
        }
        if ($this->text($note) === '') {
            $this->fail('Catatan review wajib diisi.');
        }
        $found = false;
        foreach ($p['boq'] as &$r) {
            if ($r['id'] === $rowId) {
                $k = $this->record('khs', $r['khsId']);
                if (! $k['active']) {
                    $this->fail('Master KHS tidak aktif.');
                }
                $r = array_merge($r, ['m' => $k['m'], 's' => $k['s'], 'version' => $k['version'], 'reviewed' => true, 'note' => trim($note)]);
                $found = true;
            }
        }
        unset($r);
        if (! $found) {
            $this->fail('Baris review tidak ditemukan.');
        }
        $this->put('projects', $p);
        $this->log('Selisih BOQ direview', $p['no'], '/boq/'.$id);

        return null;
    }

    private function finalizeBoq(string $id): null
    {
        $this->admin();
        $p = $this->record('projects', $id);
        if ($p['boqStatus'] !== 'Review' || ! $p['boq']) {
            $this->fail('BOQ harus diimpor dan direview.');
        }
        foreach ($p['boq'] as $r) {
            $k = $this->record('khs', $r['khsId']);
            if (! $k['active'] || ! $r['reviewed'] || $r['version'] !== $k['version'] || $this->number($r['qty'], true) <= 0) {
                $this->fail('Selesaikan seluruh review dan revalidasi Master KHS.');
            }
        }
        $p['boqStatus'] = 'Final';
        $this->put('projects', $p);
        $this->log('BOQ difinalisasi', $p['no'], '/boq/'.$id);

        return null;
    }

    private function validateDeal(array $d, bool $formal): void
    {
        $p = $this->record('projects', $d['projectId'] ?? '');
        $root = $this->record('projects', $p['rootId']);
        if ($p['status'] !== 'Sah' || $p['boqStatus'] !== 'Final' || (($p['workflowVersion'] ?? 1) >= 2 && count($p['approvals'] ?? []) !== count($this->approvalRoles($p, true)))) {
            $this->fail('PP harus sah dan BOQ final.');
        }
        $this->required($d, ['no', 'mandor']);
        foreach ($this->state['deals'] as $v) {
            if ($v['id'] !== $d['id'] && $v['no'] === trim($d['no'])) {
                $this->fail('Nomor BA KH sudah digunakan.');
            }
        }
        $selected = false;
        $seen = [];
        foreach ($d['rows'] ?? [] as $r) {
            $source = collect($root['boq'])->firstWhere('id', $r['rowId'] ?? '');
            if (! $source || isset($seen[$r['rowId']])) {
                $this->fail('Baris kesepakatan tidak dikenal/duplikat.');
            }
            $seen[$r['rowId']] = true;
            foreach (['m', 's'] as $c) {
                if (! is_bool($r[$c] ?? null)) {
                    $this->fail('Lingkup komponen tidak valid.');
                }
                $capKey = $c === 'm' ? 'capM' : 'capS';
                if (($r[$capKey] ?? null) !== $source[$c] && (string) ($r[$capKey] ?? '') !== (string) ($source[$c] ?? '')) {
                    $this->fail('Harga referensi KHS tidak boleh diubah client.');
                }
                if (! $r[$c]) {
                    continue;
                }
                $selected = true;
                $q = $this->number($r[$c === 'm' ? 'qm' : 'qs'] ?? -1, true);
                if ($source[$c] === null || $q <= 0 || $q > $this->capacity($p, $r['rowId'], $c, $d['id'])) {
                    $this->fail('Volume komponen melebihi ketersediaan.');
                }
                $price = $this->number($r[$c === 'm' ? 'pm' : 'ps'] ?? -1);
                if ($price > $source[$c]) {
                    $this->fail('Harga '.($c === 'm' ? 'Material' : 'Jasa').' melebihi KHS komponen.');
                }
                $this->number($r[$c === 'm' ? 'sphM' : 'sphS'] ?? -1);
                $this->number($r[$c === 'm' ? 'offerM' : 'offerS'] ?? -1);
            }
        }
        if (! $selected) {
            $this->fail('Pilih minimal satu komponen pekerjaan.');
        }
        if ($formal) {
            $this->required($d, ['place', 'location', 'witel', 'date', 'start', 'end', 'signer', 'fo', 'jointer']);
            $this->date($d['date']);
            $this->date($d['start']);
            $this->date($d['end']);
            $dates = [];
            $hk = 0;
            foreach ($p['schedule'] as $schedule) {
                $hk += $schedule['days'];
                foreach (['start', 'end'] as $key) {
                    if (! empty($schedule[$key])) {
                        $dates[] = $schedule[$key];
                    }
                }
            }
            sort($dates);
            $duration = (strtotime($d['end']) - strtotime($d['start'])) / 86400 + 1;
            if (! $dates || $d['start'] > $d['end'] || $d['start'] < $dates[0] || $d['end'] > end($dates) || $duration > $hk) {
                $this->fail('Periode mandor harus di dalam jadwal PP dan tidak melebihi total HK.');
            }
        }
    }

    private function saveDeal(array $d, string $action): string
    {
        $this->admin();
        $this->enum($action, ['save', 'generate', 'attest']);
        $old = $this->existing('deals', $d['id'] ?? '');
        if ($old && $old['status'] === 'Sah') {
            $this->fail('BA KH sah terkunci.');
        }
        if ($old && $old['status'] === 'Terbit') {
            if ($action !== 'attest') {
                $this->fail('BA KH terbit terkunci; hanya bukti pengesahan dapat dicatat.');
            }
            $d = array_merge($old, ['evidence' => $d['evidence'] ?? '']);
        }
        $this->validateDeal($d, $action !== 'save');
        if ($action === 'attest') {
            $this->required($d, ['evidence']);
        }
        $d['status'] = $action === 'attest' ? 'Sah' : ($action === 'generate' ? 'Terbit' : 'Draft');
        $this->put('deals', $d);
        $this->log($action === 'attest' ? 'BA KH disahkan' : 'BA KH disimpan', $d['no'], '/ba-kh/'.$d['id']);

        return $d['id'];
    }

    private function saveSpk(array $v, string $action): string
    {
        $this->admin();
        $this->enum($action, ['save', 'generate', 'attest']);
        $old = $this->existing('spks', $v['id'] ?? '');
        if ($old && $old['status'] === 'Sah') {
            $this->fail('SPK sah terkunci.');
        }
        if ($old && $old['status'] === 'Terbit') {
            if ($action !== 'attest') {
                $this->fail('SPK terbit terkunci; hanya bukti pengesahan dapat dicatat.');
            }
            $v = array_merge($old, ['evidence' => $v['evidence'] ?? '']);
        }
        $d = $this->record('deals', $v['dealId'] ?? '');
        if ($d['status'] !== 'Sah') {
            $this->fail('SPK hanya dapat dibuat dari BA KH sah.');
        }
        $this->required($v, ['no']);
        foreach ($this->state['spks'] as $x) {
            if ($x['id'] !== $v['id'] && ($x['dealId'] === $v['dealId'] || $x['no'] === trim($v['no']))) {
                $this->fail('BA KH sudah memiliki SPK atau nomor SPK duplikat.');
            }
        }
        if ($action !== 'save') {
            $this->required($v, ['place', 'date', 'start', 'end', 'address', 'supervisor', 'phone', 'signer', 'position']);
            $this->date($v['date']);
        }
        $this->date($v['start']);
        $this->date($v['end']);
        if ($v['start'] > $v['end'] || $v['start'] < $d['start'] || $v['end'] > $d['end']) {
            $this->fail('Periode SPK harus dalam periode BA KH.');
        }
        if ($action === 'attest') {
            $this->required($v, ['evidence']);
        }
        $this->validateDeal($d, true);
        $v['status'] = $action === 'attest' ? 'Sah' : ($action === 'generate' ? 'Terbit' : 'Draft');
        $this->put('spks', $v);
        $this->log($action === 'attest' ? 'SPK disahkan · volume committed' : 'SPK disimpan', $v['no'], '/spk/'.$v['id']);

        return $v['id'];
    }

    private function createTerm(string $spkId, ?string $id): string
    {
        $this->admin();
        $spk = $this->record('spks', $spkId);
        if ($spk['status'] !== 'Sah') {
            $this->fail('SPK harus sah.');
        }
        $terms = array_values(array_filter($this->state['terms'], fn ($t) => $t['spkId'] === $spkId));
        usort($terms, fn ($a, $b) => $a['number'] <=> $b['number']);
        if (count($terms) >= 3 || collect($terms)->contains('type', 'Final')) {
            $this->fail('Maksimal tiga termin dan tidak ada termin setelah Final.');
        }
        $previous = end($terms);
        if ($previous && ($previous['status'] !== 'Disetujui' || count($previous['approvals']) !== $this->approvalCount($previous) || $previous['apmStatus'] !== 'Valid')) {
            $this->fail('Termin sebelumnya membutuhkan approval lengkap dan APM valid.');
        }
        $id ??= 'termin-'.Str::uuid();
        if ($this->existing('terms', $id)) {
            $this->fail('ID termin sudah digunakan.');
        }
        $number = count($terms) + 1;
        $this->put('terms', ['id' => $id, 'spkId' => $spkId, 'number' => $number, 'type' => $number === 3 ? 'Final' : 'Parsial', 'status' => 'Draft', 'actuals' => [], 'approvals' => [], 'history' => [], 'apm' => '', 'apmStatus' => 'Belum Terbit', 'paid' => 0, 'paymentHistory' => []]);
        if (($this->state['schema'] ?? 1) >= 2) {
            $p = $this->record('projects', $this->record('deals', $spk['dealId'])['projectId']);
            if ($p['status'] !== 'Sah') {
                $this->fail('PP harus disetujui sebelum pembayaran dibuat.');
            }
            $t = $this->record('terms', $id);
            $version = ($this->state['schema'] ?? 1) >= 3 ? 3 : 2;
            $source = $version >= 3 && ($p['workflowVersion'] ?? 1) < 3 ? ['approverUserIds' => $p['paymentApproverUserIds'] ?? []] : $p;
            $chain = $this->validateAssignments($source, $version, $p['branch']);
            $t['workflowVersion'] = $version;
            $t['pmUserId'] = $chain['PM Konstruksi'];
            if ($version >= 3) {
                $t['approverUserIds'] = $chain;
                $t['financeHistory'] = [];
            } else {
                $t['managerUserId'] = $chain['Manager Konstruksi'];
            }
            $this->put('terms', $t);
        }
        $this->log('Draft termin dibuat', $spk['no'].' · Termin '.$number, '/termin/'.$id);

        return $id;
    }

    private function saveTerm(array $input, bool $submit): null
    {
        $this->admin();
        $t = $this->record('terms', $input['id'] ?? '');
        if (! in_array($t['status'], ['Draft', 'Dikembalikan'], true)) {
            $this->fail('Termin terkunci setelah diajukan/ditolak.');
        }
        $t['type'] = $this->enum($input['type'] ?? '', ['Parsial', 'Final']);
        if ($t['number'] === 3 && $t['type'] !== 'Final') {
            $this->fail('Termin ketiga wajib Final.');
        }
        $t['actuals'] = $this->object($input['actuals'] ?? null);
        $deal = $this->record('deals', $this->record('spks', $t['spkId'])['dealId']);
        foreach ($t['actuals'] as $rowId => $q) {
            if (! collect($deal['rows'])->contains('rowId', $rowId)) {
                $this->fail('Item aktual tidak dikenal.');
            }
        }
        foreach ($deal['rows'] as $r) {
            foreach (['m', 's'] as $c) {
                $q = $this->number($t['actuals'][$r['rowId']][$c] ?? 0, true);
                if ((! $r[$c] && $q != 0) || $q > $this->remaining($t['spkId'], $r, $c)) {
                    $this->fail('Volume aktual melebihi sisa committed.');
                }
            }
        }
        if ($this->termTotal($t) <= 0) {
            $this->fail('Masukkan volume aktual dengan nilai tagihan positif.');
        }
        if ($submit) {
            $t['status'] = 'Diajukan';
            $t['approvals'] = [];
            $t['submitted'] = now()->toISOString();
        }
        $this->put('terms', $t);
        $this->log($submit ? 'Termin diajukan ke PM Konstruksi' : 'Draft termin disimpan', 'Termin '.$t['number'], '/termin/'.$t['id']);

        return null;
    }

    private function decide(string $id, string $decision, string $note): null
    {
        $t = $this->record('terms', $id);
        $roles = $this->approvalRoles($t);
        abort_unless($t['status'] === 'Diajukan' && ($roles[count($t['approvals'])] ?? null) === $this->state['role'], 403, 'Approval harus berurutan oleh role yang ditugaskan.');
        if (($t['workflowVersion'] ?? 1) >= 2) {
            $this->assigned($t, $this->state['role']);
        }
        $this->enum($decision, ['Setujui', 'Kembalikan', 'Tolak']);
        $note = $this->text($note);
        if ($decision !== 'Setujui' && $note === '') {
            $this->fail('Alasan pengembalian/penolakan wajib.');
        }
        $a = ['role' => $this->state['role'], 'decision' => $decision, 'note' => $note, 'at' => now()->toISOString()];
        if (($t['workflowVersion'] ?? 1) >= 2) {
            $a['userId'] = $this->state['actorUserId'];
            $a['actorName'] = $this->record('users', $a['userId'])['name'];
        }
        $t['history'][] = $a;
        if ($decision === 'Setujui') {
            $t['approvals'][] = $a;
            if (count($t['approvals']) === $this->approvalCount($t)) {
                $t['status'] = 'Disetujui';
                if ($t['type'] === 'Final') {
                    $this->put('bapps', ['id' => 'bapp-'.Str::uuid(), 'termId' => $t['id'], 'no' => 'BAPP/'.$this->record('spks', $t['spkId'])['no'], 'status' => 'Draft', 'evidence' => '', 'released' => [], 'releaseHistory' => []]);
                }
            }
        } else {
            $t['status'] = $decision === 'Kembalikan' ? 'Dikembalikan' : 'Ditolak';
            $t['approvals'] = [];
        }
        $this->put('terms', $t);
        $this->log($decision === 'Setujui' ? 'Approval '.$this->state['role'].' selesai' : 'Termin '.$decision, 'Termin '.$t['number'], '/termin/'.$id);

        return null;
    }

    private function recordApm(string $id, string $apm, bool $valid): null
    {
        $this->finance();
        $t = $this->record('terms', $id);
        $apm = $this->text($apm, 60);
        if (($t['workflowVersion'] ?? 1) >= 2) {
            $apm = strtoupper($apm);
        }
        if ($t['status'] !== 'Disetujui' || count($t['approvals']) !== $this->approvalCount($t)) {
            $this->fail('APM membutuhkan approval lengkap.');
        }
        if (($t['workflowVersion'] ?? 1) >= 2 && (($t['scan']['status'] ?? '') !== 'Valid' || $t['apmStatus'] === 'Valid')) {
            $this->fail('APM memerlukan scan yang diverifikasi Finance dan belum dibayar.');
        }
        if (($t['workflowVersion'] ?? 1) >= 3 && (($t['financeApproval']['decision'] ?? '') !== 'Setujui' || ($t['financeApproval']['scanId'] ?? '') !== ($t['scan']['id'] ?? '') || ($t['financeApproval']['userId'] ?? '') !== ($t['scan']['verifiedBy'] ?? ''))) {
            $this->fail('Approval Finance harus sesuai scan terbaru sebelum APM dicatat.');
        }
        if (($t['workflowVersion'] ?? 1) >= 2 && ! $valid) {
            $this->fail('APM alur baru hanya dicatat setelah verifikasi; koreksi pembayaran memerlukan proses terpisah.');
        }
        if (! preg_match('/^[A-Za-z0-9][A-Za-z0-9\/._-]{4,59}$/D', $apm)) {
            $this->fail('Nomor APM 5–60 karakter, huruf/angka/._-/ saja.');
        }
        foreach ($this->state['terms'] as $x) {
            if ($valid && $x['id'] !== $id && $x['apmStatus'] === 'Valid' && strtoupper($x['apm']) === strtoupper($apm)) {
                $this->fail('Nomor APM sudah digunakan.');
            }
            if (! $valid && $x['spkId'] === $t['spkId'] && $x['number'] > $t['number']) {
                $this->fail('APM tidak dapat dibatalkan setelah termin berikutnya dibuat.');
            }
        }
        $t['apm'] = $apm;
        $t['apmStatus'] = $valid ? 'Valid' : 'Perlu Koreksi';
        if (($t['workflowVersion'] ?? 1) >= 2) {
            $value = (float) bcsub((string) $this->termTotal($t), (string) $t['paid'], 2);
            if ($value <= 0) {
                $this->fail('Tagihan sudah lunas.');
            }
            $t['paid'] = $this->termTotal($t);
            $t['paymentHistory'][] = ['amount' => $value, 'ref' => $apm, 'at' => now()->toISOString(), 'actor' => $this->state['actorUserId'] ?? '', 'source' => 'APM'];
        }
        $this->put('terms', $t);
        $this->log('APM dicatat', $apm, '/termin/'.$id);

        return null;
    }

    private function recordPayment(string $id, mixed $amount, string $ref): null
    {
        $this->finance();
        $t = $this->record('terms', $id);
        $value = $this->number($amount);
        $ref = $this->text($ref, 300);
        if ($t['status'] !== 'Disetujui' || $t['apmStatus'] !== 'Valid') {
            $this->fail('Pembayaran membutuhkan approval lengkap dan APM valid.');
        }
        if ($value <= 0 || abs($value * 100 - round($value * 100)) > 0.000001 || ! $ref || bccomp(bcadd((string) $t['paid'], (string) $value, 2), (string) $this->termTotal($t), 2) > 0) {
            $this->fail('Nominal pembayaran tidak valid/melebihi outstanding atau referensi kosong.');
        }
        $t['paid'] = (float) bcadd((string) $t['paid'], (string) $value, 2);
        $t['paymentHistory'][] = ['amount' => $value, 'ref' => $ref, 'at' => now()->toISOString()];
        $this->put('terms', $t);
        $this->log('Pembayaran dicatat', $ref, '/termin/'.$id);

        return null;
    }

    private function attestBapp(string $id, string $no, string $evidence): null
    {
        $this->admin();
        $b = $this->record('bapps', $id);
        $t = $this->record('terms', $b['termId']);
        if ($b['status'] === 'Sah' || $t['type'] !== 'Final' || $t['status'] !== 'Disetujui' || count($t['approvals']) !== $this->approvalCount($t)) {
            $this->fail('BA PP terkunci atau Final belum approved.');
        }
        $this->required(['no' => $no, 'evidence' => $evidence], ['no', 'evidence']);
        $b['no'] = trim($no);
        $b['evidence'] = trim($evidence);
        $b['status'] = 'Sah';
        $this->put('bapps', $b);
        $this->log('BA PP difinalisasi', $b['no'], '/ba-pp/'.$id);

        return null;
    }

    private function releasable(array $b): array
    {
        $t = $this->record('terms', $b['termId']);
        if ($b['status'] !== 'Sah' || $t['type'] !== 'Final' || $t['status'] !== 'Disetujui' || count($t['approvals']) !== $this->approvalCount($t)) {
            $this->fail('BA PP sah dan approval Final lengkap wajib.');
        }

        return $t;
    }

    private function releaseVolume(string $id, array $quantities): null
    {
        $this->admin();
        $b = $this->record('bapps', $id);
        $t = $this->releasable($b);
        $d = $this->record('deals', $this->record('spks', $t['spkId'])['dealId']);
        $total = 0;
        foreach ($quantities as $rowId => $q) {
            $r = collect($d['rows'])->firstWhere('rowId', $rowId);
            if (! $r) {
                $this->fail('Item pelepasan tidak dikenal.');
            }
            foreach (['m', 's'] as $c) {
                $v = $this->number($q[$c] ?? 0, true);
                if ($v > $this->remaining($t['spkId'], $r, $c)) {
                    $this->fail('Pelepasan melebihi sisa volume belum accepted.');
                }
                $total += $v;
            }
        }
        if ($total <= 0) {
            $this->fail('Pilih volume positif untuk dilepas.');
        }
        foreach ($quantities as $rowId => $q) {
            foreach (['m', 's'] as $c) {
                $b['released'][$rowId][$c] = round(($b['released'][$rowId][$c] ?? 0) + ($q[$c] ?? 0), 3);
            }
        }
        $b['releaseHistory'][] = ['quantities' => $quantities, 'at' => now()->toISOString(), 'actor' => $this->state['role']];
        $this->put('bapps', $b);
        $this->log('Volume dilepas oleh Admin', $b['no'], '/ba-pp/'.$id);

        return null;
    }

    private function createContinuation(string $bappId, string $no, array $allocations, string $notes, ?string $id): string
    {
        $this->admin();
        $b = $this->record('bapps', $bappId);
        $t = $this->releasable($b);
        if (! $b['releaseHistory']) {
            $this->fail('Otorisasi pelepasan volume wajib.');
        }
        $no = $this->text($no, 150);
        $notes = $this->text($notes);
        if (($this->state['schema'] ?? 1) < 2 && (! $no || collect($this->state['projects'])->contains(fn ($p) => mb_strtolower($p['no']) === mb_strtolower($no)))) {
            $this->fail('No PP Baru harus diisi manual dan unik.');
        }
        $parent = $this->record('projects', $this->record('deals', $this->record('spks', $t['spkId'])['dealId'])['projectId']);
        if (($this->state['schema'] ?? 1) >= 2) {
            $no = $this->nextProjectNumber(array_merge($parent, ['unit' => $parent['unit'] === 'Operation' ? 'Operation' : 'Konstruksi']));
        }
        $root = $this->record('projects', $parent['rootId']);
        $budget = '0';
        $total = 0;
        foreach ($allocations as $rowId => $q) {
            $r = collect($root['boq'])->firstWhere('id', $rowId);
            if (! $r) {
                $this->fail('Item alokasi tidak dikenal.');
            }
            foreach (['m', 's'] as $c) {
                $v = $this->number($q[$c] ?? 0, true);
                $used = 0;
                foreach ($this->state['projects'] as $p) {
                    if (($p['sourceBapp'] ?? '') === $bappId) {
                        $used += $p['allocations'][$rowId][$c] ?? 0;
                    }
                }
                if ($v > round(($b['released'][$rowId][$c] ?? 0) - $used, 3) || $v > $this->ledger($root['id'], $rowId, $c)['available']) {
                    $this->fail('Volume lanjutan melebihi volume dilepas yang masih tersedia.');
                }
                $total += $v;
                $budget = bcadd($budget, $this->product($v, $r[$c] ?? 0), 6);
            }
        }
        if ($total <= 0) {
            $this->fail('Pilih alokasi volume positif.');
        }
        $id ??= 'pp-'.Str::uuid();
        if ($this->existing('projects', $id)) {
            $this->fail('ID PP sudah digunakan.');
        }
        $p = ['id' => $id, 'no' => $no, 'name' => $parent['name'].' — Lanjutan', 'unit' => $parent['unit'], 'branch' => $parent['branch'], 'projectCode' => $parent['projectCode'], 'budget' => $this->roundAmount($budget), 'date' => now('Asia/Jakarta')->toDateString(), 'status' => 'Draft', 'parentId' => $parent['id'], 'rootId' => $root['id'], 'sourceBapp' => $bappId, 'allocations' => $allocations, 'intro' => $notes, 'team' => [], 'schedule' => $parent['schedule'], 'costs' => ['mandor' => 0, 'material' => 0, 'bop' => 0, 'permit' => 0], 'risk' => '', 'communication' => '', 'quality' => '', 'boqStatus' => 'Final', 'boq' => [], 'created' => now('Asia/Jakarta')->toDateString()];
        if (($this->state['schema'] ?? 1) >= 2) {
            $p = array_merge($p, ['workflowVersion' => 2, 'unit' => $parent['unit'] === 'Operation' ? 'Operation' : 'Konstruksi', 'pmUserId' => $parent['pmUserId'] ?? '', 'managerUserId' => $parent['managerUserId'] ?? '', 'approvals' => [], 'approvalHistory' => []]);
            if (($this->state['schema'] ?? 1) >= 3) {
                $p['workflowVersion'] = 3;
                $p['approverUserIds'] = $parent['approverUserIds'] ?? $parent['paymentApproverUserIds'] ?? [];
                $p['continuationNotes'] = $notes;
                $p['schedule'] = array_fill(0, 5, ['days' => 1]);
                $p = ProjectPlanTemplate::apply($p);
                $owners = [];
                foreach ($root['boq'] as $row) {
                    if (isset($allocations[$row['id']])) {
                        $owners[] = ['rowId' => $row['id'], 'm' => $row['m'], 's' => $row['s'], 'qm' => $row['m'] === null ? 0 : $allocations[$row['id']]['m'], 'qs' => $row['s'] === null ? 0 : $allocations[$row['id']]['s']];
                    }
                }
                $p = ProjectPlanBudget::apply($p, $owners, false);
                $actor = $this->existing('users', $this->state['actorUserId'] ?? '') ?? [];
                $p['preparedBy'] = ['userId' => $actor['id'] ?? '', 'name' => $actor['name'] ?? '', 'nik' => $actor['nik'] ?? '', 'role' => $this->state['role'], 'at' => now()->toISOString()];
            }
        }
        $this->put('projects', $p);
        $this->log('PP lanjutan dibuat · volume reserved', $no.' ← '.$parent['no'], '/project-plan/'.$id);

        return $id;
    }

    private function updateKhs(array $input, bool $remove): null
    {
        $this->admin();
        $old = $this->existing('khs', $input['id'] ?? '');
        if ($remove || ! $old) {
            abort_unless($this->state['role'] === 'Superadmin', 403, 'Tambah/hapus KHS hanya Superadmin.');
        }
        if ($remove) {
            foreach ($this->state['projects'] as $p) {
                if (collect($p['boq'])->contains('khsId', $input['id'])) {
                    $this->fail('KHS pernah digunakan; nonaktifkan untuk menjaga riwayat.');
                }
            }
            $this->state['khs'] = array_values(array_filter($this->state['khs'], fn ($k) => $k['id'] !== $input['id']));
        } else {
            $this->required($input, ['code', 'name', 'unit', 'package']);
            foreach ($this->state['khs'] as $k) {
                if ($k['id'] !== $input['id'] && $k['code'] === $input['code'] && $k['package'] === $input['package']) {
                    $this->fail('Designator/paket sudah ada.');
                }
            }
            if (($input['m'] ?? null) === null && ($input['s'] ?? null) === null) {
                $this->fail('Minimal satu komponen harga wajib.');
            }
            foreach (['m', 's'] as $c) {
                if (($input[$c] ?? null) !== null) {
                    $this->number($input[$c]);
                }
            }
            $k = $input;
            $k['active'] = (bool) ($k['active'] ?? true);
            $k['version'] = ($old['version'] ?? 0) + 1;
            $k['history'] = $old['history'] ?? [];
            $k['history'][] = ['at' => now()->toISOString(), 'actor' => $this->state['role'], 'm' => $k['m'], 's' => $k['s']];
            $this->put('khs', $k);
            foreach ($this->state['projects'] as &$p) {
                if ($p['boqStatus'] === 'Review') {
                    foreach ($p['boq'] as &$r) {
                        if ($r['khsId'] === $k['id']) {
                            $r['reviewed'] = false;
                            $r['m'] = $k['m'];
                            $r['s'] = $k['s'];
                            $r['version'] = $k['version'];
                        }
                    }
                }
            }
            unset($p, $r);
        }
        $this->log($remove ? 'Master KHS dihapus' : 'Master KHS diperbarui', $input['code'] ?? '', '/master-khs');

        return null;
    }

    private function saveUser(array $u, bool $remove): null
    {
        abort_unless($this->state['role'] === 'Superadmin', 403, 'Manajemen pengguna hanya Superadmin.');
        $this->required($u, ['name', 'email', 'role']);
        $this->enum($u['role'], self::ROLES);
        foreach ($this->state['projects'] as $project) {
            foreach (['pmUserId' => 'PM Konstruksi', 'managerUserId' => 'Manager Konstruksi'] as $key => $role) {
                if (($project[$key] ?? '') === ($u['id'] ?? '') && ($remove || empty($u['active']) || $u['role'] !== $role)) {
                    $this->fail('Pengguna masih ditugaskan pada PP; penugasan tidak boleh diputus.');
                }
            }
        }
        if (! filter_var($u['email'], FILTER_VALIDATE_EMAIL)) {
            $this->fail('Email tidak valid.');
        }
        foreach ($this->state['users'] as $x) {
            if ($x['id'] !== $u['id'] && mb_strtolower($x['email']) === mb_strtolower($u['email'])) {
                $this->fail('Email sudah digunakan.');
            }
        }
        if ($remove) {
            $this->state['users'] = array_values(array_filter($this->state['users'], fn ($x) => $x['id'] !== $u['id']));
        } else {
            $u['active'] = (bool) ($u['active'] ?? true);
            $this->put('users', $u);
        }
        $this->log('Pengguna demo '.($remove ? 'dihapus' : 'disimpan'), $u['name'], '/pengaturan?tab=users');

        return null;
    }

    private function saveProfile(string $name, string $email): null
    {
        $name = $this->text($name, 200);
        $email = $this->text($email, 254);
        if (! $name || ! filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $this->fail('Nama/email profil wajib valid.');
        }
        $this->state['profile'] = ['name' => $name, 'email' => $email];
        $this->log('Profil diperbarui', $name, '/pengaturan');

        return null;
    }

    private function changeRole(string $role): null
    {
        $this->state['role'] = $this->enum($role, self::ROLES);
        if (($this->state['schema'] ?? 1) >= 2) {
            $this->state['actorUserId'] = collect($this->state['users'])->first(fn ($u) => $u['active'] && $u['role'] === $role)['id'] ?? '';
        }

        return null;
    }

    public function load(array $state): self
    {
        $this->state = $state;

        return $this;
    }
}
