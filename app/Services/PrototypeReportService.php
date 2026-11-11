<?php

namespace App\Services;

class PrototypeReportService
{
    public const REPORTS = ['Monitoring Project Plan', 'Monitoring BOQ', 'Monitoring SPK', 'Monitoring Termin', 'Outstanding Payment', 'Approval Performance', 'Mandor Performance', 'Remaining BOQ Volume', 'Hierarki PP'];

    public function build(array $s, array $filters): array
    {
        $report = $filters['report'];
        $status = $filters['status'] ?? '';
        $mandor = $filters['mandor'] ?? '';
        $domain = app(PrototypeDomain::class)->load($s);
        $allProjects = collect($s['projects'])->keyBy('id');
        $deals = collect($s['deals'])->keyBy('id');
        $allSpks = collect($s['spks'])->keyBy('id');
        $projects = collect($s['projects'])->filter(function ($p) use ($filters, $s, $report, $status, $mandor) {
            foreach (['branch', 'unit'] as $key) {
                if (! empty($filters[$key]) && $p[$key] !== $filters[$key]) {
                    return false;
                }
            }
            if (! empty($filters['project']) && $p['id'] !== $filters['project']) {
                return false;
            }
            if (! empty($filters['from']) && $p['date'] < $filters['from']) {
                return false;
            }
            if (! empty($filters['to']) && $p['date'] > $filters['to']) {
                return false;
            }
            if ($mandor && $report !== 'Remaining BOQ Volume' && ! collect($s['deals'])->contains(fn ($d) => $d['projectId'] === $p['id'] && $d['mandor'] === $mandor)) {
                return false;
            }
            if ($status && in_array($report, ['Monitoring Project Plan', 'Hierarki PP'], true) && $p['status'] !== $status) {
                return false;
            }
            if ($status && in_array($report, ['Monitoring BOQ', 'Remaining BOQ Volume'], true) && $p['boqStatus'] !== $status) {
                return false;
            }

            return true;
        });
        $spks = collect($s['spks'])->filter(fn ($v) => $projects->contains('id', $deals[$v['dealId']]['projectId']) && (! $mandor || $deals[$v['dealId']]['mandor'] === $mandor) && (! $status || ! in_array($report, ['Monitoring SPK', 'Mandor Performance'], true) || $v['status'] === $status));
        $terms = collect($s['terms'])->filter(fn ($t) => $spks->contains('id', $t['spkId']) && (! $status || ! in_array($report, ['Monitoring Termin', 'Outstanding Payment', 'Approval Performance'], true) || $t['status'] === $status));
        $rows = [];
        if (in_array($report, ['Monitoring Project Plan', 'Monitoring BOQ'], true)) {
            $headers = ['No PP', 'Nama Kegiatan', 'Branch', 'Jenis', 'Nilai Anggaran / Subalokasi', 'BOQ Owner', 'Status'];
            foreach ($projects as $p) {
                $rows[] = [$p['no'], $p['name'], $p['branch'], empty($p['parentId']) ? 'Induk' : 'Lanjutan', $p['budget'], empty($p['parentId']) ? $domain->boqTotal($p) : $p['budget'], $report === 'Monitoring BOQ' ? $p['boqStatus'] : $p['status']];
            }
        } elseif ($report === 'Monitoring SPK') {
            $headers = ['No SPK', 'No PP', 'Mandor', 'Nilai SPK', 'Status'];
            foreach ($spks as $v) {
                $d = $deals[$v['dealId']];
                $rows[] = [$v['no'], $allProjects[$d['projectId']]['no'], $d['mandor'], $domain->dealTotal($d), $v['status']];
            }
        } elseif (in_array($report, ['Monitoring Termin', 'Outstanding Payment'], true)) {
            $headers = ['No PP', 'No SPK', 'Mandor', 'Termin', 'Jenis', 'Tagihan', 'Terbayar', 'Outstanding', 'Status', 'Status APM'];
            foreach ($terms as $t) {
                if ($report === 'Outstanding Payment' && $t['status'] !== 'Disetujui') {
                    continue;
                }
                $v = $allSpks[$t['spkId']];
                $d = $deals[$v['dealId']];
                $amount = $domain->termTotal($t);
                $rows[] = [$allProjects[$d['projectId']]['no'], $v['no'], $d['mandor'], $t['number'], $t['type'], $amount, $t['paid'], $t['status'] === 'Disetujui' ? round($amount - $t['paid'], 2) : 0, $t['status'], $t['apmStatus']];
            }
        } elseif ($report === 'Approval Performance') {
            $headers = ['Role', 'Setujui', 'Dikembalikan', 'Ditolak', 'Menunggu', 'Rata-rata Respon (jam)'];
            foreach (['PM Konstruksi', 'Manager Konstruksi', 'Admin Area', 'Mgr Deployment', 'Mgr Bussiness Support', 'Mgr Project Support', 'Head of PMO', 'Finance'] as $role) {
                $counts = ['Setujui' => 0, 'Kembalikan' => 0, 'Tolak' => 0];
                $hours = [];
                $waiting = 0;
                foreach ($terms as $t) {
                    if (($t['status'] === 'Diajukan' && PrototypeDomain::nextApprovalRole($t) === $role)
                        || ($role === 'Finance' && PrototypeDomain::financeTaskPending($t))) {
                        $waiting++;
                    }
                    foreach (array_merge($t['history'], $t['financeHistory'] ?? [], PrototypeDomain::partialFinanceHistory($t), $t['financePaymentHistory'] ?? []) as $a) {
                        if ($a['role'] === $role) {
                            $counts[$a['decision']]++;
                            $hours[] = (strtotime($a['at']) - strtotime($a['requestedAt'] ?? $t['submitted'] ?? $a['at'])) / 3600;
                        }
                    }
                }
                $rows[] = [$role, $counts['Setujui'], $counts['Kembalikan'], $counts['Tolak'], $waiting, $hours ? round(array_sum($hours) / count($hours), 2) : 0];
            }
        } elseif ($report === 'Mandor Performance') {
            $headers = ['Mandor', 'SPK Sah', 'Nilai Kontrak', 'Tagihan Disetujui', 'Terbayar', 'Outstanding'];
            foreach ($spks->map(fn ($v) => $deals[$v['dealId']]['mandor'])->unique() as $name) {
                $vs = $spks->filter(fn ($v) => $deals[$v['dealId']]['mandor'] === $name && $v['status'] === 'Sah');
                $ts = $terms->filter(fn ($t) => $vs->contains('id', $t['spkId']) && $t['status'] === 'Disetujui');
                $billed = $ts->sum(fn ($t) => $domain->termTotal($t));
                $paid = $ts->sum('paid');
                $rows[] = [$name, $vs->count(), $vs->sum(fn ($v) => $domain->dealTotal($deals[$v['dealId']])), $billed, $paid, round($billed - $paid, 2)];
            }
        } elseif ($report === 'Remaining BOQ Volume') {
            $headers = ['Root PP', 'Designator', 'Komponen', 'Satuan', 'Volume Root', 'Available', 'Reserved', 'Committed', 'Accepted'];
            $masters = collect($s['khs'])->keyBy('id');
            foreach ($projects as $p) {
                if (! empty($p['parentId']) || $p['boqStatus'] !== 'Final') {
                    continue;
                }
                foreach ($p['boq'] as $r) {
                    foreach (['m', 's'] as $c) {
                        if ($r[$c] === null) {
                            continue;
                        }
                        $l = $domain->ledger($p['id'], $r['id'], $c);
                        $k = $masters[$r['khsId']];
                        $rows[] = [$p['no'], $k['code'], $c === 'm' ? 'Material' : 'Jasa', $k['unit'], $l['total'], $l['available'], $l['reserved'], $l['committed'], $l['accepted']];
                    }
                }
            }
        } else {
            $headers = ['No PP', 'Parent PP', 'Root PP', 'Sumber BA PP', 'Budget Root', 'Subalokasi', 'Status'];
            $bapps = collect($s['bapps'])->keyBy('id');
            foreach ($projects as $p) {
                $rows[] = [$p['no'], empty($p['parentId']) ? '—' : $allProjects[$p['parentId']]['no'], $allProjects[$p['rootId']]['no'], $bapps[$p['sourceBapp'] ?? '']['no'] ?? '—', empty($p['parentId']) ? $p['budget'] : 0, empty($p['parentId']) ? 0 : $p['budget'], $p['status']];
            }
        }

        return compact('headers', 'rows');
    }
}
