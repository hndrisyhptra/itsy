<?php

namespace Tests\Feature;

use App\Services\PrototypeDomain;
use App\Services\PrototypeWorkspaceService;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

class PrototypeDomainTest extends TestCase
{
    private array $state;

    private function scenario(string $name): void
    {
        // Retain regression coverage for documents saved under the original workflow.
        $this->state = json_decode(file_get_contents(database_path('demo/'.$name.'.json')), true, 512, JSON_THROW_ON_ERROR);
    }

    private function runCommand(string $name, array $args, ?string $id = null): mixed
    {
        $result = app(PrototypeDomain::class)->execute($this->state, $name, $args, $id);
        $this->state = $result['state'];

        return $result['result'];
    }

    private function role(string $role): void
    {
        $this->runCommand('role', [$role]);
    }

    private function rejected(callable $fn, string $message = ''): void
    {
        $before = $this->state;
        try {
            $fn();
            $this->fail('Invalid command should have been rejected.');
        } catch (ValidationException|HttpException $e) {
            $this->assertEquals($before, $this->state);
            if ($message) {
                $this->assertStringContainsString($message, $e->getMessage());
            }
        }
    }

    private function term(float $ratio = .2, string $type = 'Parsial'): string
    {
        $this->role('Admin Konstruksi');
        $spk = $this->state['spks'][0];
        $id = $this->runCommand('createTerm', [$spk['id']]);
        $t = collect($this->state['terms'])->firstWhere('id', $id);
        $d = collect($this->state['deals'])->firstWhere('id', $spk['dealId']);
        $t['type'] = $type;
        foreach ($d['rows'] as $r) {
            $t['actuals'][$r['rowId']] = ['m' => $r['m'] ? round($r['qm'] * $ratio, 3) : 0, 's' => $r['s'] ? round($r['qs'] * $ratio, 3) : 0];
        }
        $this->runCommand('saveTerm', [$t, true]);

        return $id;
    }

    private function approve(string $id, ?string $apm = null): void
    {
        foreach (['PM Konstruksi', 'Manager Konstruksi', 'Finance'] as $role) {
            $this->role($role);
            $this->runCommand('decide', [$id, 'Setujui', '']);
        }
        if ($apm) {
            $this->runCommand('recordApm', [$id, $apm, true]);
        }
        $this->role('Admin Konstruksi');
    }

    public function test_all_seed_scenarios_have_identical_financial_and_ledger_rules(): void
    {
        foreach (PrototypeWorkspaceService::SCENARIOS as $name) {
            $this->scenario($name);
            $domain = app(PrototypeDomain::class)->load($this->state);
            $this->assertEquals(134780000, $domain->boqTotal($this->state['projects'][0]));
            foreach ($this->state['projects'] as $p) {
                if (empty($p['parentId']) && $p['boqStatus'] === 'Final') {
                    foreach ($p['boq'] as $r) {
                        foreach (['m', 's'] as $c) {
                            $l = $domain->ledger($p['id'], $r['id'], $c);
                            $this->assertEquals($l['total'], round($l['available'] + $l['reserved'] + $l['committed'] + $l['accepted'], 3));
                        }
                    }
                }
            }
        }
    }

    public function test_boq_review_is_required_before_official_price_snapshot(): void
    {
        $this->scenario('A');
        $this->rejected(fn () => $this->runCommand('finalizeBoq', ['pp-1']));
        $row = $this->state['projects'][0]['boq'][1];
        $this->rejected(fn () => $this->runCommand('reviewBoq', ['pp-1', $row['id'], '']));
        $this->runCommand('reviewBoq', ['pp-1', $row['id'], 'Gunakan Master KHS']);
        $this->runCommand('finalizeBoq', ['pp-1']);
        $this->assertSame('Final', $this->state['projects'][0]['boqStatus']);
        $this->assertEquals(14250, $this->state['projects'][0]['boq'][1]['m']);
    }

    public function test_each_agreed_component_is_capped_by_its_own_official_khs_price(): void
    {
        $this->scenario('C');
        $d = $this->state['deals'][0];
        $this->state['deals'] = [];
        $d['status'] = 'Draft';
        $d['rows'][0]['pm'] = $d['rows'][0]['capM'] + 1;
        $d['rows'][0]['ps'] = 0;
        $this->rejected(fn () => $this->runCommand('saveDeal', [$d, 'save']), 'Material');
        $d['rows'][0]['pm'] = 15000;
        $d['rows'][0]['ps'] = $d['rows'][0]['capS'] + 1;
        $this->rejected(fn () => $this->runCommand('saveDeal', [$d, 'save']), 'Jasa');
        $d['rows'][0]['ps'] = 10000;
        $d['rows'][0]['capM'] = 1000000;
        $this->rejected(fn () => $this->runCommand('saveDeal', [$d, 'save']), 'KHS');
    }

    public function test_reservations_cannot_be_allocated_again(): void
    {
        $this->scenario('C');
        $d = $this->state['deals'][0];
        $d['id'] = 'bakh-duplicate';
        $d['no'] = 'BAKH/DUPLICATE';
        $d['status'] = 'Draft';
        $this->rejected(fn () => $this->runCommand('saveDeal', [$d, 'save']), 'Volume');
    }

    public function test_approval_roles_are_sequential_and_submitted_actuals_are_immutable(): void
    {
        $this->scenario('D');
        $id = $this->term();
        $this->role('Finance');
        $this->rejected(fn () => $this->runCommand('decide', [$id, 'Setujui', '']));
        $this->role('Superadmin');
        $this->rejected(fn () => $this->runCommand('decide', [$id, 'Setujui', '']));
        $this->role('Admin Konstruksi');
        $this->rejected(fn () => $this->runCommand('saveTerm', [$this->state['terms'][0], false]), 'terkunci');
        $this->approve($id);
        $this->assertSame('Disetujui', $this->state['terms'][0]['status']);
        $this->assertCount(3, $this->state['terms'][0]['approvals']);
    }

    public function test_return_requires_reason_and_resubmission_preserves_prior_history(): void
    {
        $this->scenario('D');
        $id = $this->term();
        $this->role('PM Konstruksi');
        $this->rejected(fn () => $this->runCommand('decide', [$id, 'Kembalikan', '']), 'Alasan');
        $this->runCommand('decide', [$id, 'Kembalikan', 'Koreksi aktual']);
        $this->role('Admin Konstruksi');
        $this->runCommand('saveTerm', [$this->state['terms'][0], true]);
        $this->approve($id);
        $this->assertCount(4, $this->state['terms'][0]['history']);
        $this->assertCount(3, $this->state['terms'][0]['approvals']);
    }

    public function test_apm_gates_next_term_but_full_payment_does_not(): void
    {
        $this->scenario('D');
        $id = $this->term(.4);
        $this->approve($id);
        $this->rejected(fn () => $this->runCommand('createTerm', [$this->state['spks'][0]['id']]), 'APM');
        $this->role('Finance');
        $this->runCommand('recordApm', [$id, 'APM/QA/001', true]);
        $this->role('Admin Konstruksi');
        $this->runCommand('createTerm', [$this->state['spks'][0]['id']]);
        $this->assertEquals(0, $this->state['terms'][0]['paid']);
        $this->role('Finance');
        $this->rejected(fn () => $this->runCommand('recordApm', [$id, 'APM/QA/001', false]));
    }

    public function test_actual_amounts_are_not_double_counted_and_overbilling_is_rejected(): void
    {
        $this->scenario('D');
        $id = $this->term(.4);
        $this->approve($id, 'APM/QA/001');
        $second = $this->term(.2);
        $domain = app(PrototypeDomain::class)->load($this->state);
        $this->assertEquals(22912600, $domain->termTotal(collect($this->state['terms'])->firstWhere('id', $second)));
        $this->role('Admin Konstruksi');
        $this->state['terms'][1]['status'] = 'Draft';
        $draft = $this->state['terms'][1];
        $row = $this->state['deals'][0]['rows'][0]['rowId'];
        $draft['actuals'][$row]['m'] = 721;
        $this->rejected(fn () => $this->runCommand('saveTerm', [$draft, true]), 'Volume');
    }

    public function test_maximum_three_terms_and_early_final_close_the_cycle(): void
    {
        $this->scenario('D');
        $one = $this->term();
        $this->approve($one, 'APM/QA/001');
        $two = $this->term();
        $this->approve($two, 'APM/QA/002');
        $id = $this->runCommand('createTerm', [$this->state['spks'][0]['id']]);
        $three = collect($this->state['terms'])->firstWhere('id', $id);
        $this->assertSame('Final', $three['type']);
        $three['type'] = 'Parsial';
        $this->rejected(fn () => $this->runCommand('saveTerm', [$three, true]), 'wajib Final');
        $this->rejected(fn () => $this->runCommand('createTerm', [$this->state['spks'][0]['id']]));
        $this->scenario('D');
        $final = $this->term(.1, 'Final');
        $this->approve($final);
        $this->rejected(fn () => $this->runCommand('createTerm', [$this->state['spks'][0]['id']]));
    }

    public function test_payment_is_independent_and_cannot_exceed_outstanding(): void
    {
        $this->scenario('D');
        $id = $this->term(.2);
        $this->approve($id, 'APM/QA/001');
        $this->role('Finance');
        $this->runCommand('recordPayment', [$id, 1000000, 'TRF/QA/001']);
        $this->assertEquals(1000000, $this->state['terms'][0]['paid']);
        $this->rejected(fn () => $this->runCommand('recordPayment', [$id, 22912600, 'TRF/QA/002']), 'outstanding');
    }

    public function test_release_and_continuation_use_only_authorized_component_remainder(): void
    {
        $this->scenario('E');
        $b = $this->state['bapps'][0];
        $row = $this->state['deals'][0]['rows'][0]['rowId'];
        $this->rejected(fn () => $this->runCommand('releaseVolume', [$b['id'], [$row => ['m' => 100, 's' => 70]]]), 'BA PP');
        $this->runCommand('attestBapp', [$b['id'], 'BAPP/QA/001', 'Bukti demo']);
        $this->rejected(fn () => $this->runCommand('releaseVolume', [$b['id'], [$row => ['m' => 301, 's' => 0]]]), 'melebihi');
        $this->runCommand('releaseVolume', [$b['id'], [$row => ['m' => 100, 's' => 70]]]);
        $id = $this->runCommand('createContinuation', [$b['id'], 'PP/QA/NEXT', [$row => ['m' => 60, 's' => 30]], 'Alokasi QA']);
        $p = collect($this->state['projects'])->firstWhere('id', $id);
        $this->assertEquals(1485000, $p['budget']);
        $this->assertSame('pp-1', $p['rootId']);
        $this->assertEquals(240000000, $this->state['projects'][0]['budget']);
        $domain = app(PrototypeDomain::class)->load($this->state);
        $this->assertEquals(['total' => 1200, 'available' => 40, 'reserved' => 60, 'committed' => 200, 'accepted' => 900], $domain->ledger('pp-1', $row, 'm'));
        $this->rejected(fn () => $this->runCommand('createContinuation', [$b['id'], 'PP/QA/OVER', [$row => ['m' => 41, 's' => 0]], '']), 'melebihi');
    }

    public function test_khs_changes_revalidate_drafts_without_repricing_issued_history(): void
    {
        $this->scenario('overview');
        $oldDeal = $this->state['deals'][0];
        $k = $this->state['khs'][1];
        $k['m'] += 100;
        $this->runCommand('updateKhs', [$k, false]);
        $this->assertEquals($oldDeal, $this->state['deals'][0]);
        $this->assertEquals(14250, $this->state['projects'][0]['boq'][1]['m']);
        $draft = collect($this->state['projects'])->firstWhere('boqStatus', 'Review');
        $this->assertFalse($draft['boq'][1]['reviewed']);
    }

    public function test_precision_and_issued_snapshot_protection_are_enforced_by_php(): void
    {
        $this->scenario('D');
        $id = $this->runCommand('createTerm', [$this->state['spks'][0]['id']]);
        $t = $this->state['terms'][0];
        $row = $this->state['deals'][0]['rows'][0]['rowId'];
        $t['actuals'][$row] = ['m' => .0001, 's' => 0];
        $this->rejected(fn () => $this->runCommand('saveTerm', [$t, true]), 'desimal');
        $this->rejected(fn () => $this->runCommand('saveDeal', [$this->state['deals'][0], 'save']), 'terkunci');
        $this->rejected(fn () => $this->runCommand('saveSpk', [$this->state['spks'][0], 'save']), 'terkunci');
    }
}
