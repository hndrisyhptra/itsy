<?php

namespace App\Services;

/** New workflow; legacy document decisions remain unchanged. */
trait PrototypeWorkflowV2
{
    use PrototypeApprovalChain;

    public const DEFAULT_PP_NUMBER_FORMAT = '{SEQ}/LG.000/TA-03{BRANCH}/{MM}-{YYYY}';

    public static function upgradeState(array $state): array
    {
        $state['schema'] = max(2, $state['schema'] ?? 1);
        $state['settings'] ??= ['ppNumberFormat' => self::DEFAULT_PP_NUMBER_FORMAT, 'nextPpSequence' => 1];
        if (! isset($state['settings']['ppNumberFormatVersion']) && $state['settings']['ppNumberFormat'] === 'PP/{BRANCH}/{YYYY}/{SEQ}') {
            $state['settings']['ppNumberFormat'] = self::DEFAULT_PP_NUMBER_FORMAT;
        }
        $state['settings']['ppNumberFormatVersion'] = 2;
        $state['branches'] ??= array_map(fn ($name, $i) => ['id' => 'branch-'.($i + 1), 'code' => 'BR'.str_pad((string) ($i + 1), 2, '0', STR_PAD_LEFT), 'name' => $name, 'active' => true], array_values(array_unique(array_column($state['projects'], 'branch'))), array_keys(array_values(array_unique(array_column($state['projects'], 'branch')))));
        foreach (['projects', 'terms'] as $collection) {
            foreach ($state[$collection] as &$row) {
                $row['workflowVersion'] ??= 1;
            }
            unset($row);
        }
        $state['actorUserId'] ??= collect($state['users'])->first(fn ($u) => $u['active'] && $u['role'] === $state['role'])['id'] ?? '';

        return $state;
    }

    private function approver(string $id, string $role): array
    {
        $user = $this->existing('users', $id);
        if (! $user || ! $user['active'] || $user['role'] !== $role) {
            $this->fail('Pilih pengguna aktif dengan peran '.$role.'.');
        }

        return $user;
    }

    private function assigned(array $document, string $role): void
    {
        $id = $this->selectedApprover($document, $role);
        $this->approver($id, $role);
        abort_unless($this->state['role'] === $role && ($this->state['actorUserId'] ?? '') === $id, 403, 'Hanya approver yang dipilih pada PP dapat menyetujui dokumen ini.');
    }

    private function approvalCount(array $term): int
    {
        return count($this->approvalRoles($term));
    }

    private function numberFormat(string $format): string
    {
        $format = $this->text($format, 100);
        $literal = str_replace(['{BRANCH}', '{YYYY}', '{MM}', '{UNIT}', '{SEQ}'], '', $format);
        if (substr_count($format, '{SEQ}') !== 1 || ! preg_match('/^[A-Za-z0-9\/._ -]*$/D', $literal)) {
            $this->fail('Format No PP wajib memiliki satu {SEQ}; token tersedia: {BRANCH}, {YYYY}, {MM}, {UNIT}.');
        }

        return $format;
    }

    private function nextProjectNumber(array $p): string
    {
        $settings = $this->state['settings'];
        $format = $this->numberFormat($settings['ppNumberFormat']);
        $branch = collect($this->state['branches'])->firstWhere('name', $p['branch']);
        for ($i = 0; $i < 1000; $i++) {
            $sequence = $settings['nextPpSequence']++;
            $no = strtr($format, ['{SEQ}' => str_pad((string) $sequence, 4, '0', STR_PAD_LEFT), '{BRANCH}' => $branch['code'], '{YYYY}' => now('Asia/Jakarta')->format('Y'), '{MM}' => now('Asia/Jakarta')->format('m'), '{UNIT}' => strtoupper($p['unit'])]);
            if (! collect($this->state['projects'])->contains(fn ($v) => mb_strtolower($v['no']) === mb_strtolower($no))) {
                $settings['issuedPpNumbers'][$no] = ['sequence' => $sequence, 'pattern' => $format, 'branch' => $branch['code'], 'period' => now('Asia/Jakarta')->format('m-Y'), 'at' => now()->toISOString()];
                $this->state['settings'] = $settings;

                return $no;
            }
        }
        $this->fail('Urutan No PP belum tersedia. Periksa format penomoran.');
    }

    private function saveProjectV2(array $input, bool $submit): string
    {
        $this->admin();
        $old = $this->existing('projects', $input['id'] ?? '');
        if ($old && ! $this->canReviseSubmission($old)) {
            $this->fail('PP terkunci pada status ini.');
        }
        $p = array_intersect_key($input, array_flip(['id', 'name', 'unit', 'branch', 'projectCode', 'budget', 'date', 'rootId', 'parentId', 'sourceBapp', 'allocations', 'intro', 'team', 'schedule', 'costs', 'risk', 'communication', 'quality', 'created']));
        $this->enum($p['unit'] ?? '', ['Konstruksi', 'Operation']);
        if (! collect($this->state['branches'])->contains(fn ($b) => $b['name'] === ($p['branch'] ?? '') && $b['active'])) {
            $this->fail('Pilih Branch aktif dari daftar penugasan.');
        }
        $version = ($old['workflowVersion'] ?? 1) >= 2 ? $old['workflowVersion'] : (($this->state['schema'] ?? 1) >= 3 ? 3 : 2);
        if ($version >= 3) {
            $p = ProjectPlanTemplate::apply($p, $submit);
        }
        $scheme = $old && (! empty($old['approvalHistory']) || ! empty($old['approvals']))
            ? (self::usesBusinessSupport($old) ? 'business-support' : ($old['approvalScheme'] ?? 'project-support')) : 'project-support-first';
        $assignments = $this->validateAssignments($input, $version, $p['branch'], $submit, $scheme);
        $import = $input['_boqImport'] ?? null;
        if (! empty($old['boqUpload']) && ! $import) {
            $this->fail('Referensi file BOQ tersimpan harus dipertahankan atau diganti melalui upload.');
        }
        $rows = [];
        if (empty($old['parentId'])) {
            foreach ($input['boq'] ?? [] as $row) {
                $khs = $this->record('khs', $row['khsId'] ?? '');
                if (! $khs['active'] || $this->number($row['qty'] ?? 0, true) <= 0 || collect($rows)->contains('khsId', $khs['id'])) {
                    $this->fail('BOQ harus menggunakan designator aktif, unik, dan volume positif maksimal tiga desimal.');
                }
                $rowId = $this->id($row['id'] ?? '');
                if (collect($rows)->contains('id', $rowId)) {
                    $this->fail('ID baris BOQ duplikat.');
                }
                $source = $import ? collect($import['rows'])->firstWhere('khsId', $khs['id']) : null;
                if ($import && ! $source) {
                    $this->fail('Designator tidak ditemukan dalam file BOQ yang diunggah.');
                }
                $mismatch = $source && ($source['excelM'] !== $khs['m'] || $source['excelS'] !== $khs['s']);
                $note = $source ? $this->text($row['note'] ?? '') : 'Input manual dari Master KHS';
                $sameReviewPrice = ($row['version'] ?? null) === $khs['version']
                    && array_key_exists('m', $row) && array_key_exists('s', $row)
                    && ($row['m'] === null) === ($khs['m'] === null) && ($row['s'] === null) === ($khs['s'] === null)
                    && $row['m'] == $khs['m'] && $row['s'] == $khs['s'];
                $reviewed = ! $mismatch || (($row['reviewed'] ?? false) === true && $note !== '' && $sameReviewPrice);
                if ($submit && ! $reviewed) {
                    $this->fail('Review selisih harga Excel dengan Master KHS dan isi alasan sebelum pengajuan.');
                }
                $rows[] = ['id' => $rowId, 'khsId' => $khs['id'], 'qty' => $row['qty'], 'm' => $khs['m'], 's' => $khs['s'], 'excelM' => $source['excelM'] ?? $khs['m'], 'excelS' => $source['excelS'] ?? $khs['s'], 'version' => $khs['version'], 'reviewed' => $reviewed, 'note' => $note] + ($source ? ['sourceRow' => $source['sourceRow'], 'sourceCode' => $source['sourceCode'], 'sourceQty' => $source['qty']] : []);
            }
            if ((! $rows && ($submit || $version < 3)) || count($rows) > 500) {
                $this->fail('Masukkan 1–500 baris BOQ sebelum Header PP.');
            }
        }
        if ($version >= 3) {
            $source = ! empty($old['parentId']) ? $this->record('projects', $old['rootId'])['boq'] : $rows;
            $owners = [];
            foreach ($source as $row) {
                if (! empty($old['parentId']) && ! isset($old['allocations'][$row['id']])) {
                    continue;
                }
                $owners[] = ['rowId' => $row['id'], 'm' => $row['m'], 's' => $row['s'],
                    'qm' => $row['m'] === null ? 0 : (! empty($old['parentId']) ? ($old['allocations'][$row['id']]['m'] ?? 0) : $row['qty']),
                    'qs' => $row['s'] === null ? 0 : (! empty($old['parentId']) ? ($old['allocations'][$row['id']]['s'] ?? 0) : $row['qty'])];
            }
            foreach (['planBudget', 'riskLevel', 'signatureRows'] as $key) {
                if (array_key_exists($key, $input)) {
                    $p[$key] = $input[$key];
                } elseif (array_key_exists($key, $old ?? [])) {
                    $p[$key] = $old[$key];
                }
            }
            $p = ProjectPlanBudget::apply($p, $owners, $submit);
            $actor = $this->existing('users', $this->state['actorUserId'] ?? '') ?? [];
            $p['preparedBy'] = $old['preparedBy'] ?? ['userId' => $actor['id'] ?? '', 'name' => $actor['name'] ?? '', 'nik' => $actor['nik'] ?? '', 'role' => $this->state['role'], 'at' => now()->toISOString()];
            if (isset($old['previousPlanning'])) {
                $p['previousPlanning'] = $old['previousPlanning'];
            } elseif ($old && ! isset($old['planBudget'])) {
                $p['previousPlanning'] = array_intersect_key($old, array_flip(['costs', 'risk', 'communication', 'quality']));
            }
            $p['signatureUsers'] = [];
            foreach ($assignments as $role => $userId) {
                $u = $this->record('users', $userId);
                $p['signatureUsers'][$role] = ['userId' => $u['id'], 'name' => $u['name'], 'nik' => $u['nik'] ?? ''];
            }
        }
        $p['no'] = $old['no'] ?? $this->nextProjectNumber($p);
        $id = $this->saveProject($p, $submit);
        $p = $this->record('projects', $id);
        if (empty($p['parentId'])) {
            $p['boq'] = $rows;
            $p['boqStatus'] = $submit ? 'Final' : ($rows ? 'Review' : 'Belum diimpor');
        }
        $p['workflowVersion'] = $version;
        if ($version >= 3) {
            $p['approvalScheme'] = $scheme;
        }
        if (isset($old['approvalOrderChange'])) {
            $p['approvalOrderChange'] = $old['approvalOrderChange'];
        }
        if ($import) {
            $p['boqUpload'] = $import['source'];
        }
        $p['pmUserId'] = $assignments['PM Konstruksi'] ?? '';
        if ($version >= 3) {
            $p['approverUserIds'] = $assignments;
        } else {
            $p['managerUserId'] = $assignments['Manager Konstruksi'] ?? '';
        }
        $p['approvals'] = [];
        $p['approvalHistory'] = $old['approvalHistory'] ?? [];
        $p['status'] = $submit ? 'Diajukan' : 'Draft';
        $this->put('projects', $p);
        $this->state['events'][0]['title'] = $submit ? 'PP diajukan ke PM terpilih' : 'Draft PP dan BOQ disimpan';

        return $id;
    }

    private function decideProject(string $id, string $decision, string $note): null
    {
        $p = $this->record('projects', $id);
        if ($p['status'] !== 'Diajukan' || ($p['workflowVersion'] ?? 1) < 2) {
            $this->fail('PP belum diajukan untuk approval.');
        }
        $roles = $this->approvalRoles($p, true);
        $role = self::nextApprovalRole($p, true) ?? '';
        $this->assigned($p, $role);
        $this->enum($decision, ['Setujui', 'Kembalikan', 'Tolak']);
        $note = $this->text($note);
        if ($decision !== 'Setujui' && ! $note) {
            $this->fail('Alasan pengembalian/penolakan wajib.');
        }
        $a = ['role' => $role, 'userId' => $this->state['actorUserId'], 'actorName' => $this->record('users', $this->state['actorUserId'])['name'], 'actorNik' => $this->record('users', $this->state['actorUserId'])['nik'] ?? '', 'decision' => $decision, 'note' => $note, 'at' => now()->toISOString()];
        $p['approvalHistory'][] = $a;
        if ($decision === 'Setujui') {
            $p['approvals'][] = $a;
            if (count($p['approvals']) === count($roles)) {
                $p['status'] = 'Sah';
            }
        } else {
            $p['status'] = $decision === 'Kembalikan' ? 'Dikembalikan' : 'Ditolak';
            $p['approvals'] = [];
        }
        $this->put('projects', $p);
        $this->log('PP '.$decision, $p['no'].' · '.$role, '/project-plan/'.$id);

        return null;
    }

    private function assignProjectApprovers(string $id, string $pm, string $manager): null
    {
        $this->admin();
        $p = $this->record('projects', $id);
        if (($p['workflowVersion'] ?? 1) >= 2 || collect($this->state['terms'])->contains(fn ($t) => ($t['workflowVersion'] ?? 1) >= 2 && $this->record('deals', $this->record('spks', $t['spkId'])['dealId'])['projectId'] === $id)) {
            $this->fail('Penugasan hanya untuk PP lama sebelum pembayaran alur baru dibuat.');
        }
        $p['pmUserId'] = $this->approver($pm, 'PM Konstruksi')['id'];
        $p['managerUserId'] = $this->approver($manager, 'Manager Konstruksi')['id'];
        foreach ([$pm, $manager] as $approverId) {
            $user = $this->record('users', $approverId);
            if (isset($user['branches']) && ! in_array($p['branch'], $user['branches'], true)) {
                $this->fail('PM dan Manager harus ditugaskan pada Branch PP.');
            }
        }
        $this->put('projects', $p);
        $this->log('Penanggung jawab PP lama ditetapkan', $p['no'], '/project-plan/'.$id);

        return null;
    }

    private function setNumberFormat(string $format): null
    {
        $this->admin();
        $this->state['settings']['ppNumberFormat'] = $this->numberFormat($format);
        $this->log('Format No PP diperbarui', $format, '/pengaturan');

        return null;
    }

    private function selectActor(string $id): null
    {
        $u = $this->record('users', $id);
        abort_unless($u['active'] && $u['role'] === $this->state['role'], 403, 'Pilih pengguna aktif sesuai peran demo.');
        $this->state['actorUserId'] = $id;

        return null;
    }

    private function attachTermScan(string $id, array $file): null
    {
        $this->admin();
        $t = $this->record('terms', $id);
        if (($t['workflowVersion'] ?? 1) < 2 || $t['status'] !== 'Disetujui' || count($t['approvals']) !== $this->approvalCount($t) || $t['apmStatus'] === 'Valid' || ($t['scan']['status'] ?? '') === 'Valid') {
            $this->fail('Scan hanya diunggah setelah seluruh approval dan sebelum verifikasi/APM.');
        }
        $t['scanHistory'] ??= [];
        if (isset($t['scan'])) {
            $t['scanHistory'][] = $t['scan'];
        }
        $t['scan'] = $file + ['status' => 'Menunggu Verifikasi', 'uploadedAt' => now()->toISOString(), 'uploadedBy' => $this->state['actorUserId'] ?? ''];
        unset($t['financeApproval']);
        $this->put('terms', $t);
        $this->log('Scan TTD basah diunggah', 'Termin '.$t['number'], '/termin/'.$id);

        return null;
    }

    private function verifyTermScan(string $id, string $decision, string $note): null
    {
        $this->finance();
        $t = $this->record('terms', $id);
        $this->enum($decision, ['Valid', 'Perlu Koreksi']);
        $note = $this->text($note);
        if (($t['workflowVersion'] ?? 1) < 2 || $t['status'] !== 'Disetujui' || count($t['approvals']) !== $this->approvalCount($t) || ! isset($t['scan']) || $t['apmStatus'] === 'Valid' || ($t['scan']['status'] ?? '') !== 'Menunggu Verifikasi') {
            $this->fail('Scan menunggu verifikasi dengan seluruh approval diperlukan.');
        }
        if ($decision === 'Perlu Koreksi' && ! $note) {
            $this->fail('Catatan koreksi scan wajib diisi.');
        }
        $t['scan'] = array_merge($t['scan'], ['status' => $decision, 'note' => $note, 'verifiedAt' => now()->toISOString(), 'verifiedBy' => $this->state['actorUserId'] ?? '', 'verifiedRole' => 'Finance']);
        if (($t['workflowVersion'] ?? 1) >= 3) {
            $user = $this->approver($this->state['actorUserId'] ?? '', 'Finance');
            $approval = ['role' => 'Finance', 'userId' => $user['id'], 'actorName' => $user['name'], 'decision' => $decision === 'Valid' ? 'Setujui' : 'Kembalikan', 'note' => $note, 'at' => now()->toISOString(), 'scanId' => $t['scan']['id']];
            $t['financeHistory'][] = $approval;
            if ($decision === 'Valid') {
                $t['financeApproval'] = $approval;
            } else {
                unset($t['financeApproval']);
            }
        }
        $this->put('terms', $t);
        $this->log('Scan '.$decision, 'Termin '.$t['number'], '/termin/'.$id);

        return null;
    }
}
