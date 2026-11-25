<?php

/** Explicit isolated MariaDB QA only; never uses .env database credentials/socket. */
declare(strict_types=1);

use App\Models\PrototypeWorkspace;
use App\Models\User;
use App\Services\AccountAccess;
use App\Services\BudgetPidAllocation;
use App\Services\BudgetPidBranch;
use App\Services\BudgetPidMaster;
use App\Services\BudgetPidWorkbook;
use App\Services\FinanceDocuments;
use App\Services\KasbonSettlement;
use App\Services\ProjectCancellation;
use App\Services\PrototypeDomain;
use App\Services\PrototypeWorkspaceService;
use App\Services\RelationalWorkflowStore;
use App\Services\UploadQueue;
use App\Services\WorkUnitAccess;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpException;

$qaRoot = realpath($argv[1] ?? '') ?: '';
$phase = $argv[2] ?? 'migrate';
if (! preg_match('#^/private/tmp/area3-pid-stage4-[a-zA-Z0-9-]+$#D', $qaRoot)
    || trim((string) @file_get_contents($qaRoot.'/qa-marker')) !== 'area3-budget-pid-stage4-isolated') {
    throw new RuntimeException('Requires an explicit private /private/tmp/area3-pid-stage4-* QA instance and marker.');
}
$socket = $qaRoot.'/mysql.sock';
$identity = new PDO('mysql:unix_socket='.$socket.';dbname=its;charset=utf8mb4', 'root', '', [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
$server = $identity->query('SELECT @@datadir AS datadir, @@socket AS socket, @@skip_networking AS offline, VERSION() AS version')->fetch(PDO::FETCH_ASSOC);
if (realpath($server['datadir']) !== realpath($qaRoot.'/mysql') || $server['socket'] !== $socket || (int) $server['offline'] !== 1) {
    throw new RuntimeException('Refused: database identity is not the isolated offline QA instance.');
}
if ($phase === 'shutdown') {
    $identity->exec('SHUTDOWN');
    exit(0);
}
$identity = null;

require dirname(__DIR__).'/vendor/autoload.php';
$keyFile = $qaRoot.'/app-key';
if (! is_file($keyFile)) {
    file_put_contents($keyFile, 'base64:'.base64_encode(random_bytes(32)));
    chmod($keyFile, 0600);
}
$environment = ['APP_ENV' => 'testing', 'APP_KEY' => trim(file_get_contents($keyFile)),
    'APP_CONFIG_CACHE' => $qaRoot.'/config-unused.php', 'APP_SERVICES_CACHE' => $qaRoot.'/services.php',
    'APP_PACKAGES_CACHE' => $qaRoot.'/packages.php', 'APP_ROUTES_CACHE' => $qaRoot.'/routes.php',
    'DB_CONNECTION' => 'mysql', 'DB_HOST' => '127.0.0.1', 'DB_SOCKET' => $socket, 'DB_DATABASE' => 'its', 'DB_USERNAME' => 'root', 'DB_PASSWORD' => '', 'DB_URL' => '',
    'CACHE_STORE' => 'array', 'SESSION_DRIVER' => 'array', 'QUEUE_CONNECTION' => 'database', 'LOG_CHANNEL' => 'stderr',
    'MAIL_MAILER' => 'array', 'BCRYPT_ROUNDS' => '4', 'VIEW_COMPILED_PATH' => $qaRoot.'/views'];
foreach ($environment as $name => $value) {
    putenv($name.'='.$value);
    $_ENV[$name] = $_SERVER[$name] = $value;
}
$app = require dirname(__DIR__).'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
config(['filesystems.disks.local.root' => $qaRoot.'/files', 'telegram.token' => null]);
set_exception_handler(function (Throwable $error): void {
    $message = $error instanceof ValidationException ? json_encode($error->errors()) : $error->getMessage();
    fwrite(STDERR, get_class($error).': '.$message.PHP_EOL);
    exit(1);
});

if ($phase === 'migrate') {
    $exit = Artisan::call('migrate', ['--force' => true]);
    if ($exit !== 0) {
        throw new RuntimeException(Artisan::output());
    }
    echo json_encode(['phase' => 'migrate', 'engine' => $server['version'], 'migrations' => DB::table('migrations')->count(), 'offline' => true, 'isolated' => true], JSON_THROW_ON_ERROR).PHP_EOL;
    exit(0);
}
function qaCheck(bool $condition, string $message): void
{
    if (! $condition) {
        throw new RuntimeException('QA failed: '.$message);
    }
}
function qaActor(int $id): void
{
    Auth::setUser(User::findOrFail($id));
}
function qaWorkspace(): PrototypeWorkspace
{
    return app(PrototypeWorkspaceService::class)->workspace(request());
}
function qaState(): array
{
    return app(PrototypeWorkspaceService::class)->payload(qaWorkspace())['state'];
}
function qaCommand(string $name, array $args, ?int $revision = null, ?string $uuid = null): array
{
    $w = qaWorkspace();

    return app(PrototypeWorkspaceService::class)->command($w, ['command' => $name, 'args' => $args,
        'revision' => $revision ?? $w->revision, 'request_id' => $uuid ?? (string) Str::uuid(), 'result_id' => null]);
}
function qaProject(string $id): array
{
    return collect(qaState()['projects'])->firstWhere('id', $id);
}
function qaPackage(string $pid, array $users, int $qty = 10, bool $operation = false): array
{
    qaActor($users[$operation ? 'Admin Operation' : 'Admin Konstruksi']);
    $state = qaState();
    $service = app(PrototypeWorkspaceService::class);
    $p = $service->scenario('B')['projects'][0];
    $p['id'] = $p['rootId'] = 'pp-'.Str::uuid();
    $p['projectCode'] = $pid;
    $p['name'] = 'Isolated QA PID';
    $p['branch'] = 'SURABAYA';
    $p['unit'] = $operation ? 'Operation' : 'Konstruksi';
    $p['date'] = '2026-11-01';
    $p['workStartDate'] = '2026-11-02';
    $p['riskLevel'] = 'SEDANG';
    $p['approverUserIds'] = [];
    foreach ($operation ? WorkUnitAccess::OPS_APPROVERS : PrototypeDomain::APPROVERS_V3 as $role) {
        $p['approverUserIds'][$role] = 'db-user-'.$users[$role];
    }
    $k = collect($state['khs'])->first(fn ($k) => $k['active'] && ($k['s'] ?? 0) >= 100);
    $m = collect($state['khs'])->first(fn ($k) => $k['active'] && ($k['m'] ?? 0) >= 100);
    $p['boq'] = [['id' => 'qa-service', 'khsId' => $k['id'], 'qty' => $qty, 'm' => $k['m'], 's' => $k['s']],
        ['id' => 'qa-material', 'khsId' => $m['id'], 'qty' => $qty, 'm' => $m['m'], 's' => $m['s']]];
    $budget = json_decode(file_get_contents(dirname(__DIR__).'/tests/Fixtures/pp-budget-vector.json'), true, 512, JSON_THROW_ON_ERROR)['project']['planBudget'];
    $budget['materials'][0]['qty'] = 1;
    $budget['mandor'] = [['rowId' => 'qa-service', 'qtyM' => 0, 'qtyS' => 5, 'priceM' => 0, 'priceS' => 100],
        ['rowId' => 'qa-material', 'qtyM' => 0, 'qtyS' => 0, 'priceM' => 0, 'priceS' => 0]];
    $p['planBudget'] = $budget;
    qaCommand('saveProjectBundle', [$p, false]);
    $p = qaProject($p['id']);
    $d = $service->scenario('C')['deals'][0];
    $d['id'] = 'bakh-'.Str::uuid();
    $d['projectId'] = $p['id'];
    $d['date'] = $d['start'] = $d['end'] = $p['schedule'][0]['start'];
    $d['rows'] = [['rowId' => 'qa-service', 'm' => false, 's' => true, 'qm' => 0, 'qs' => 5, 'pm' => 0, 'ps' => 100,
        'sphM' => 0, 'sphS' => 100, 'offerM' => 0, 'offerS' => 100, 'capM' => $k['m'], 'capS' => $k['s']]];
    qaCommand('saveDeal', [$d, 'save']);
    $v = $service->scenario('D')['spks'][0];
    $v['id'] = 'spk-'.Str::uuid();
    $v['dealId'] = $d['id'];
    $v['date'] = $v['start'] = $v['end'] = $d['start'];
    qaCommand('saveSpk', [$v, 'save']);
    $files = [];
    foreach (['mandor-ktp', 'mandor-bank'] as $kind) {
        $file = new UploadedFile(dirname(__DIR__).'/resources/js/area3/assets/spk-telkom-akses.png', 'qa-image.png', null, null, true);
        $task = app(UploadQueue::class)->enqueue(qaWorkspace(), $file, $kind, $v['id'], (string) Str::uuid(), qaWorkspace()->revision);
        app(UploadQueue::class)->process($task['id']);
        qaCheck(DB::table('upload_tasks')->where('id_upload_task', $task['id'])->value('status') === 'completed', 'private QA image processing');
        $files[] = $task['id'];
    }
    app(FinanceDocuments::class)->savePayee(qaWorkspace(), $v['id'], ['data' => ['nik' => '0011223344556677', 'accountNumber' => '0001234567',
        'accountName' => 'ISOLATED QA', 'bank' => 'QA BANK', 'ktpFileId' => $files[0], 'bankFileId' => $files[1]],
        'request_id' => (string) Str::uuid(), 'revision' => qaWorkspace()->revision]);

    return [qaProject($p['id']), $v['id']];
}
function qaWorkbook(string $path, string $pid, array $values): void
{
    $xml = '<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"><sheetData>';
    $rows = [['.', 'Level', 'Description', 'Title', 'ProCstPlan', 'ProCstPlan_2_', 'Budget', 'Budget_2_', 'Release', 'Release_2_', 'Tot.cst.cm', 'Tot.cst.cm_2_', 'Act.costs', 'Act.costs_2_']];
    foreach (['TOTAL', ...BudgetPidWorkbook::CATEGORIES] as $i => $category) {
        $rows[] = ['', $i === 0 ? '02' : '04', $i === 0 ? 'Isolated QA PID' : $category, $i === 0 ? $pid : $pid.'-01-0'.$i,
            0, 'IDR', 0, 'IDR', $values[$category], 'IDR', 0, 'IDR', 0, 'IDR'];
    }
    foreach ($rows as $index => $row) {
        $n = $index + 1;
        $xml .= '<row r="'.$n.'">';
        foreach ($row as $c => $value) {
            $xml .= '<c r="'.chr(65 + $c).$n.'" t="inlineStr"><is><t>'.htmlspecialchars((string) $value, ENT_XML1 | ENT_QUOTES, 'UTF-8').'</t></is></c>';
        }
        $xml .= '</row>';
    }
    $xml .= '</sheetData></worksheet>';
    $zip = new ZipArchive;
    $zip->open($path, ZipArchive::CREATE | ZipArchive::OVERWRITE);
    $zip->addFromString('xl/workbook.xml', '<workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships"><sheets><sheet name="Budget" sheetId="1" r:id="rId1"/></sheets></workbook>');
    $zip->addFromString('xl/_rels/workbook.xml.rels', '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="rId1" Target="worksheets/sheet1.xml"/></Relationships>');
    $zip->addFromString('xl/worksheets/sheet1.xml', $xml);
    $zip->close();
}
function qaWait(string $file): void
{
    $until = microtime(true) + 12;
    while (! is_file($file)) {
        qaCheck(microtime(true) < $until, 'barrier timeout');
        usleep(20000);
    }
}
function qaChildren(string $root, array $phases): array
{
    $children = [];
    try {
        foreach ($phases as $i => $phase) {
            $spec = [0 => ['file', '/dev/null', 'r'], 1 => ['file', $root.'/child-'.$i.'.json', 'w'], 2 => ['file', $root.'/child-'.$i.'.log', 'w']];
            $children[] = proc_open([PHP_BINARY, '-d', 'memory_limit=512M', __FILE__, $root, $phase], $spec, $pipes);
        }
        qaWait($root.'/ready-0');
        qaWait($root.'/ready-1');
        file_put_contents($root.'/go', '1');
        $results = [];
        foreach ($children as $i => $child) {
            qaCheck(proc_close($child) === 0, 'child failure; inspect isolated child log');
            $results[] = json_decode(file_get_contents($root.'/child-'.$i.'.json'), true, 512, JSON_THROW_ON_ERROR);
        }
        $children = [];

        return $results;
    } finally {
        foreach ($children as $child) {
            if (is_resource($child)) {
                proc_terminate($child);
                proc_close($child);
            }
        }
    }
}
if ($phase === 'operation') {
    qaCheck(! is_file($qaRoot.'/operation-result.json'), 'operation fixture already completed');
    $f = json_decode(file_get_contents($qaRoot.'/scenario.json'), true, 512, JSON_THROW_ON_ERROR);
    $users = $f['users'];
    $branches = DB::table('branches')->pluck('id_branch')->all();
    $region = DB::table('branches')->where('name', 'SURABAYA')->value('region_id');
    foreach (['Admin Operation', ...WorkUnitAccess::OPS_APPROVERS] as $i => $role) {
        $user = User::factory()->create(['username' => 'stage4-operation-'.$i, 'name' => 'QA '.$role]);
        app(AccountAccess::class)->assign($user, array_search($role, AccountAccess::ROLES, true), $branches);
        if (in_array($role, ['Manager SS', 'General Manager Operation'], true)) {
            DB::table('users')->where('id_user', $user->getKey())->update(['access_scope' => 'REGION', 'id_regions' => $region]);
        }
        $users[$role] = (int) $user->getKey();
    }
    [$p] = qaPackage('QA-OPERATION-STAGE4', $users, 10, true);
    $values = ['TOTAL' => $p['planBudgetTotals']['ownerTotal'], 'Material' => $p['planBudgetTotals']['ownerM'], 'Non Material' => $p['planBudgetTotals']['ownerS'], 'Operasional' => $p['planBudgetTotals']['bop'], 'Tenaga Kerja' => 0, 'Depresiasi' => 0];
    $path = $qaRoot.'/operation-budget.xlsx';
    qaWorkbook($path, $p['projectCode'], $values);
    qaActor($users['Finance']);
    $records = app(BudgetPidWorkbook::class)->read($path);
    $master = app(BudgetPidMaster::class);
    $preview = $master->import($users['Finance'], $records, ['unit' => 'Operation', 'stage' => 'preview'], hash_file('sha256', $path), 'operation-qa.xlsx');
    $r = $master->import($users['Finance'], $records, ['unit' => 'Operation', 'stage' => 'commit', 'version' => $preview['version'], 'preview_hash' => $preview['preview_hash'], 'request_id' => (string) Str::uuid()], hash_file('sha256', $path), 'operation-qa.xlsx');
    app(BudgetPidBranch::class)->change($users['Finance'], ['ids' => [DB::table('budget_pids')->where('pid', $p['projectCode'])->value('id_budget_pid')], 'unit' => 'Operation', 'branch_id' => (int) DB::table('branches')->where('name', 'SURABAYA')->value('id_branch'), 'version' => $r['version'], 'request_id' => (string) Str::uuid()]);
    qaActor($users['Admin Operation']);
    qaCommand('submitProjectBundle', [$p['id']]);
    foreach (WorkUnitAccess::OPS_APPROVERS as $role) {
        qaActor($users[$role]);
        qaCommand('decideProject', [$p['id'], 'Setujui', '']);
    }
    qaCheck(qaProject($p['id'])['status'] === 'Sah', 'operation actual five approvals');
    qaActor($users['Admin Konstruksi']);
    $revision = qaWorkspace()->revision;
    try {
        qaPackage($p['projectCode'], $users);
        throw new RuntimeException('Expected cross-unit PID refusal');
    } catch (HttpException $error) {
        qaCheck($error->getStatusCode() === 422, 'cross-unit PID rejected');
    }
    qaCheck(qaWorkspace()->revision === $revision, 'cross-unit rejection atomic');
    $result = ['phase' => 'operation', 'real_excel_unit_selected' => true, 'explicit_branch_mapping' => true, 'five_actual_operation_approvals' => true, 'region_scoped_manager_and_gm' => true, 'cross_unit_pid_rollback' => true];
    file_put_contents($qaRoot.'/operation-result.json', json_encode($result, JSON_THROW_ON_ERROR));
    echo json_encode($result, JSON_THROW_ON_ERROR).PHP_EOL;
    exit(0);
}
if ($phase === 'verify-settlement') {
    // Explicitly finish the already paid QA chain; never repeat approval/transfer or reset data.
    $f = json_decode(file_get_contents($qaRoot.'/scenario.json'), true, 512, JSON_THROW_ON_ERROR);
    qaActor($f['users']['Admin Konstruksi']);
    $p = qaProject($f['second']);
    $state = qaState();
    $deal = collect($state['deals'])->firstWhere('projectId', $p['id']);
    $spk = collect($state['spks'])->firstWhere('dealId', $deal['id']);
    $t = collect($state['terms'])->firstWhere('spkId', $spk['id']);
    $b = collect($state['bapps'])->firstWhere('termId', $t['id']);
    qaCheck($p['status'] === 'Sah' && $t['paid'] > 0 && count($t['approvals']) === 7, 'actual seven approvals and payment');
    $ppId = app(RelationalWorkflowStore::class)->id(qaWorkspace()->id, 'projects', $p['id']);
    $a = DB::table('budget_pid_allocations')->where('pp_id', $ppId)->first();
    $credit = BudgetPidAllocation::integer(2 * collect($p['boq'])->firstWhere('id', 'qa-service')['s']);
    qaCheck((int) $a->service === (int) $a->planned_service - $credit && (int) $a->material === (int) $a->planned_material && (int) $a->bop === (int) $a->planned_bop, 'exact unused-service credit');
    qaCheck(! collect($state['projects'])->contains('parentId', $p['id']), 'no existing continuation');
    qaCommand('createContinuation', [$b['id'], '', ['qa-service' => ['m' => 0, 's' => 2]], 'Isolated QA continuation']);
    $child = collect(qaState()['projects'])->firstWhere('parentId', $p['id']);
    qaCheck($child['budgetPidPolicy'] === BudgetPidAllocation::POLICY && BudgetPidAllocation::integer($child['planBudgetTotals']['ownerTotal']) === $credit, 'continuation tracked exact credit');
    $result = ['phase' => 'settlement', 'actual_five_project_and_seven_payment_approvals' => true, 'private_kasbon_and_transfer_proof' => true, 'optional_apm' => true, 'paid_retains_budget' => true, 'unused_service_release_only' => true, 'continuation_tracked' => true, 'fixture_assertion_numeric_type_corrected' => true];
    file_put_contents($qaRoot.'/settlement-result.json', json_encode($result, JSON_THROW_ON_ERROR));
    echo json_encode($result, JSON_THROW_ON_ERROR).PHP_EOL;
    exit(0);
}
if ($phase === 'settlement') {
    qaCheck(! is_file($qaRoot.'/settlement-result.json'), 'settlement already completed');
    $f = json_decode(file_get_contents($qaRoot.'/scenario.json'), true, 512, JSON_THROW_ON_ERROR);
    $users = $f['users'];
    qaActor($users['Admin Konstruksi']);
    $p = qaProject($f['second']);
    qaCheck($p['status'] === 'Diajukan', 'settlement requires original pending fixture');
    $sqlPP = app(RelationalWorkflowStore::class)->id(qaWorkspace()->id, 'projects', $p['id']);
    $reserved = (array) DB::table('budget_pid_allocations')->where('pp_id', $sqlPP)->first();
    foreach (PrototypeDomain::APPROVERS_V3 as $role) {
        qaActor($users[$role]);
        qaCommand('decideProject', [$p['id'], 'Setujui', '']);
    }
    qaActor($users['Admin Konstruksi']);
    $state = qaState();
    $deal = collect($state['deals'])->firstWhere('projectId', $p['id']);
    $spk = collect($state['spks'])->firstWhere('dealId', $deal['id']);
    qaCommand('createTerm', [$spk['id']]);
    $getTerm = fn () => collect(qaState()['terms'])->firstWhere('spkId', $spk['id']);
    $t = $getTerm();
    $t['type'] = 'Final';
    $t['actuals'] = ['qa-service' => ['m' => 0, 's' => 1]];
    qaCommand('saveTerm', [$t, false]);
    qaCommand('attestBarp', [$getTerm()]);
    qaCommand('prepareBapp', [$t['id']]);
    $b = collect(qaState()['bapps'])->firstWhere('termId', $t['id']);
    qaCommand('attestBapp', [$b['id'], $b['no']]);
    $manager = User::factory()->create(['username' => 'stage4-settlement', 'name' => 'QA Settlement']);
    app(AccountAccess::class)->assign($manager, array_search('Mgr Finance & Settlement', AccountAccess::ROLES, true), DB::table('branches')->pluck('id_branch')->all());
    $users['Mgr Finance & Settlement'] = (int) $manager->getKey();
    $form = ['settlementUserId' => 'db-user-'.$manager->getKey(), 'payeeVersion' => 1,
        'costCenter' => 'QA CC', 'wbs' => 'QA WBS', 'activity' => 'QA kasbon', 'place' => 'Surabaya', 'date' => '2026-11-15', 'evp' => 'ISOLATED QA',
        'rows' => [['accountCode' => 'QA1', 'accountName' => 'QA Account', 'text' => 'QA Work', 'amount' => (string) KasbonSettlement::quote($deal, $getTerm())['net']]]];
    app(FinanceDocuments::class)->issue(qaWorkspace(), $t['id'], ['data' => $form, 'revision' => qaWorkspace()->revision, 'request_id' => (string) Str::uuid()]);
    foreach ([...PrototypeDomain::APPROVERS_V3, 'Mgr Finance & Settlement', 'Finance'] as $role) {
        qaActor($users[$role]);
        qaCommand('decide', [$t['id'], 'Setujui', '']);
    }
    $file = new UploadedFile(dirname(__DIR__).'/resources/js/area3/assets/spk-telkom-akses.png', 'qa-proof.png', null, null, true);
    $task = app(UploadQueue::class)->enqueue(qaWorkspace(), $file, 'transfer-proof', $t['id'], (string) Str::uuid(), qaWorkspace()->revision);
    app(UploadQueue::class)->process($task['id']);
    qaCheck(DB::table('upload_tasks')->where('id_upload_task', $task['id'])->value('status') === 'completed', 'transfer proof processed');
    qaCommand('settleFinancePayment', [$t['id'], '']);
    qaCheck($getTerm()['paid'] > 0, 'real application settlement');
    qaCheck($reserved === (array) DB::table('budget_pid_allocations')->where('pp_id', $sqlPP)->first(), 'paid retains allocation unchanged');
    qaActor($users['Admin Konstruksi']);
    qaCommand('releaseVolume', [$b['id'], ['qa-service' => ['m' => 0, 's' => 2]]]);
    $credit = BudgetPidAllocation::integer(2 * collect($p['boq'])->firstWhere('id', 'qa-service')['s']);
    $after = DB::table('budget_pid_allocations')->where('pp_id', $sqlPP)->first();
    qaCheck((int) $after->service === (int) $reserved['service'] - $credit && (int) $after->bop === (int) $reserved['bop'] && (int) $after->material === (int) $reserved['material'], 'release credits unused service only');
    qaCommand('createContinuation', [$b['id'], '', ['qa-service' => ['m' => 0, 's' => 2]], 'Isolated QA continuation']);
    $child = collect(qaState()['projects'])->firstWhere('parentId', $p['id']);
    qaCheck($child['budgetPidPolicy'] === BudgetPidAllocation::POLICY && BudgetPidAllocation::integer($child['planBudgetTotals']['ownerTotal']) === $credit, 'continuation tracked exact credit');
    $result = ['phase' => 'settlement', 'actual_five_project_and_seven_payment_approvals' => true, 'private_kasbon_and_transfer_proof' => true, 'optional_apm' => true, 'paid_retains_budget' => true, 'unused_service_release_only' => true, 'continuation_tracked' => true];
    file_put_contents($qaRoot.'/settlement-result.json', json_encode($result, JSON_THROW_ON_ERROR));
    echo json_encode($result, JSON_THROW_ON_ERROR).PHP_EOL;
    exit(0);
}
if ($phase === 'verify-schema') {
    $keys = ['budget_pid_controls' => 'id_budget_control', 'budget_pid_imports' => 'id_budget_import', 'budget_pids' => 'id_budget_pid',
        'budget_pid_items' => 'id_budget_item', 'budget_pid_changes' => 'id_budget_change', 'budget_pid_branch_changes' => 'id_budget_branch_change',
        'budget_pid_allocations' => 'id_budget_allocation', 'budget_pid_allocation_changes' => 'id_budget_allocation_change',
        'prototype_workspaces' => 'id', 'project_plans' => 'id_pp', 'contracts' => 'id_contract', 'work_orders' => 'id_spk',
        'payment_terms' => 'id_payment_term', 'term_actuals' => 'id_term_actual', 'approval_runs' => 'id_approval_run', 'approval_steps' => 'id_approval_step',
        'approval_decisions' => 'id_approval_decision', 'finance_kasbons' => 'id_kasbon', 'finance_private_files' => 'id_finance_file',
        'apm_settlements' => 'id_apm_settlement', 'document_files' => 'id_document_file', 'ba_completions' => 'id_ba_completion',
        'volume_balances' => 'id_volume_balance', 'volume_movements' => 'id_volume_movement'];
    $snapshot = function () use ($keys): string {
        $rows = [];
        foreach ($keys as $table => $key) {
            $values = DB::table($table)->get()->map(fn ($row) => json_encode($row, JSON_THROW_ON_ERROR))->all();
            sort($values);
            $rows[$table] = $values;
        }

        return hash('sha256', json_encode($rows, JSON_THROW_ON_ERROR));
    };
    $before = $snapshot();
    foreach (['2026_11_24_000001_create_budgeting_pid_master', '2026_11_24_000002_map_budget_pid_branches', '2026_11_24_000003_create_budget_pid_allocations'] as $name) {
        $migration = require dirname(__DIR__).'/database/migrations/'.$name.'.php';
        $migration->up();
        $blocked = false;
        try {
            $migration->down();
        } catch (RuntimeException $error) {
            $blocked = true;
        }
        qaCheck($blocked, 'populated migration rollback is refused');
    }
    qaCheck($before === $snapshot(), 'migration resume/refused rollback preserve all fixture data');
    $result = ['phase' => 'verify-schema', 'tables_hashed' => count($keys), 'resume_preserves_data' => true, 'populated_rollbacks_refused' => 3, 'archive_and_workflow_preserved' => true];
    file_put_contents($qaRoot.'/schema-result.json', json_encode($result, JSON_THROW_ON_ERROR));
    echo json_encode($result, JSON_THROW_ON_ERROR).PHP_EOL;
    exit(0);
}
if (str_starts_with($phase, 'submit-child-')) {
    $i = (int) substr($phase, -1);
    $fixture = json_decode(file_get_contents($qaRoot.'/race.json'), true, 512, JSON_THROW_ON_ERROR);
    qaActor($fixture['actor']);
    file_put_contents($qaRoot.'/ready-'.$i, '1');
    qaWait($qaRoot.'/go');
    try {
        qaCommand('submitProjectBundle', [$fixture['ids'][$i]], $fixture['revision']);
        $status = 200;
    } catch (HttpResponseException $error) {
        $status = $error->getResponse()->getStatusCode();
    } catch (HttpException $error) {
        $status = $error->getStatusCode();
    }
    echo json_encode(['index' => $i, 'status' => $status], JSON_THROW_ON_ERROR).PHP_EOL;
    exit(0);
}
if ($phase === 'concurrency') {
    qaCheck(! is_file($qaRoot.'/race.json'), 'concurrency fixture already exists');
    $fixture = json_decode(file_get_contents($qaRoot.'/scenario.json'), true, 512, JSON_THROW_ON_ERROR);
    [$a] = qaPackage($fixture['pid'], $fixture['users'], 5);
    [$b] = qaPackage($fixture['pid'], $fixture['users'], 5);
    file_put_contents($qaRoot.'/race.json', json_encode(['ids' => [$a['id'], $b['id']], 'actor' => $fixture['users']['Admin Konstruksi'], 'revision' => qaWorkspace()->revision], JSON_THROW_ON_ERROR));
    $results = qaChildren($qaRoot, ['submit-child-0', 'submit-child-1']);
    $codes = array_column($results, 'status');
    sort($codes);
    qaCheck($codes === [200, 409], 'exactly one actual concurrent submission succeeds');
    $loser = $results[0]['status'] === 409 ? $a : $b;
    $revision = qaWorkspace()->revision;
    try {
        qaCommand('submitProjectBundle', [$loser['id']]);
        throw new RuntimeException('Expected refreshed loser budget refusal');
    } catch (HttpException $error) {
        qaCheck($error->getStatusCode() === 422, 'fresh explicit retry rejects insufficient budget');
    }
    qaCheck(qaWorkspace()->revision === $revision, 'loser budget refusal is atomic');
    $branch = (int) DB::table('branches')->where('name', 'SURABAYA')->value('id_branch');
    $usage = app(BudgetPidAllocation::class)->usage($fixture['pid'], 'Konstruksi', $branch);
    qaCheck($usage['TOTAL'] === $fixture['values']['TOTAL'] && $usage['Material'] === $fixture['values']['Material'] && $usage['Non Material'] === $fixture['values']['Non Material'], 'parallel submissions do not exceed caps');
    $result = ['phase' => 'concurrency', 'independent_processes' => 2, 'statuses' => $codes, 'fresh_retry_status' => 422, 'cap_preserved' => true];
    file_put_contents($qaRoot.'/concurrency-result.json', json_encode($result, JSON_THROW_ON_ERROR));
    echo json_encode($result, JSON_THROW_ON_ERROR).PHP_EOL;
    exit(0);
}
if (in_array($phase, ['snapshot-writer', 'snapshot-reader'], true)) {
    $f = json_decode(file_get_contents($qaRoot.'/snapshot.json'), true, 512, JSON_THROW_ON_ERROR);
    DB::beginTransaction();
    if ($phase === 'snapshot-writer') {
        DB::table('budget_pid_controls')->where('id_budget_control', 1)->lockForUpdate()->first();
        file_put_contents($qaRoot.'/snapshot-writer-ready', '1');
        qaWait($qaRoot.'/snapshot-reader-ready');
        usleep(300000);
        DB::table('budget_pid_allocations')->where('id_budget_allocation', $f['allocation'])->update(['material' => 5, 'service' => 6]);
        DB::commit();
        echo json_encode(['writer' => 'committed'], JSON_THROW_ON_ERROR).PHP_EOL;
    } else {
        qaWait($qaRoot.'/snapshot-writer-ready');
        $allocator = app(BudgetPidAllocation::class);
        $before = $allocator->usage('QA-LOCK-PROBE', 'Konstruksi', $f['branch']);
        file_put_contents($qaRoot.'/snapshot-reader-ready', '1');
        $start = microtime(true);
        DB::table('budget_pid_controls')->where('id_budget_control', 1)->lockForUpdate()->first();
        $snapshot = $allocator->usage('QA-LOCK-PROBE', 'Konstruksi', $f['branch']);
        $current = $allocator->usage('QA-LOCK-PROBE', 'Konstruksi', $f['branch'], null, true);
        DB::commit();
        qaCheck($before['TOTAL'] === 7 && $snapshot['TOTAL'] === 7 && $current['TOTAL'] === 11, 'current locking read avoids stale RR snapshot');
        echo json_encode(['before' => 7, 'stale_snapshot' => 7, 'current_locking' => 11, 'wait_ms' => (int) ((microtime(true) - $start) * 1000)], JSON_THROW_ON_ERROR).PHP_EOL;
    }
    exit(0);
}
if ($phase === 'snapshot') {
    qaCheck(! is_file($qaRoot.'/snapshot.json'), 'snapshot fixture already exists');
    $f = json_decode(file_get_contents($qaRoot.'/scenario.json'), true, 512, JSON_THROW_ON_ERROR);
    qaActor($f['users']['Admin Konstruksi']);
    $p = app(PrototypeWorkspaceService::class)->scenario('B')['projects'][0];
    $p['id'] = $p['rootId'] = 'pp-'.Str::uuid();
    $p['projectCode'] = 'QA-LOCK-PROBE';
    $p['branch'] = 'SURABAYA';
    $p['unit'] = 'Konstruksi';
    $p['boq'] = [];
    unset($p['approverUserIds'], $p['pmUserId'], $p['managerUserId']);
    qaCommand('saveProjectBundle', [$p, false]);
    $pp = app(RelationalWorkflowStore::class)->id(qaWorkspace()->id, 'projects', $p['id']);
    $allocation = DB::table('budget_pid_allocations')->where('pp_id', $pp)->first();
    // Synthetic read-isolation fixture only, not an application approval or paid event.
    DB::table('budget_pid_allocations')->where('id_budget_allocation', $allocation->id_budget_allocation)->update(['status' => 'reserved', 'material' => 3, 'service' => 4]);
    file_put_contents($qaRoot.'/snapshot.json', json_encode(['allocation' => $allocation->id_budget_allocation, 'branch' => (int) $allocation->branch_id], JSON_THROW_ON_ERROR));
    $children = [];
    foreach (['snapshot-writer', 'snapshot-reader'] as $i => $childPhase) {
        $children[] = proc_open([PHP_BINARY, '-d', 'memory_limit=512M', __FILE__, $qaRoot, $childPhase], [0 => ['file', '/dev/null', 'r'], 1 => ['file', $qaRoot.'/snapshot-'.$i.'.json', 'w'], 2 => ['file', $qaRoot.'/snapshot-'.$i.'.log', 'w']], $pipes);
    }
    foreach ($children as $child) {
        qaCheck(proc_close($child) === 0, 'snapshot child result');
    }
    $result = json_decode(file_get_contents($qaRoot.'/snapshot-1.json'), true, 512, JSON_THROW_ON_ERROR);
    $result['phase'] = 'snapshot';
    $result['isolation'] = DB::selectOne('SELECT @@tx_isolation AS isolation_name')->isolation_name;
    file_put_contents($qaRoot.'/snapshot-result.json', json_encode($result, JSON_THROW_ON_ERROR));
    echo json_encode($result, JSON_THROW_ON_ERROR).PHP_EOL;
    exit(0);
}
if (in_array($phase, ['scenario', 'resume-scenario'], true)) {
    $users = [];
    $branches = DB::table('branches')->pluck('id_branch')->all();
    $roles = array_unique(['Superadmin', 'Finance', 'Admin Konstruksi', ...PrototypeDomain::APPROVERS_V3]);
    if ($phase === 'scenario') {
        qaCheck(DB::table('users')->count() === 0, 'scenario requires fresh isolated fixtures');
        foreach ($roles as $i => $role) {
            $u = User::factory()->create(['name' => 'QA '.$role, 'username' => 'stage4-'.$i, 'email' => 'qa-stage4-'.$i.'@example.test']);
            app(AccountAccess::class)->assign($u, array_search($role, AccountAccess::ROLES, true), $branches);
            $users[$role] = (int) $u->getKey();
        }
        [$first] = qaPackage('26KT03R424-QA01', $users);
    } else {
        // Resume only the known fixture setup, never reset an existing database.
        qaCheck(DB::table('users')->count() === count($roles) && DB::table('project_plans')->count() === 1 && DB::table('budget_pids')->count() === 0, 'unexpected resume fixture');
        foreach ($roles as $i => $role) {
            $u = User::where('username', 'stage4-'.$i)->where('name', 'QA '.$role)->firstOrFail();
            $users[$role] = (int) $u->getKey();
        }
        qaActor($users['Admin Konstruksi']);
        $first = qaState()['projects'][0];
    }
    $values = ['TOTAL' => $first['planBudgetTotals']['ownerTotal'], 'Material' => $first['planBudgetTotals']['ownerM'],
        'Non Material' => $first['planBudgetTotals']['ownerS'], 'Operasional' => $first['planBudgetTotals']['bop'] * 2, 'Tenaga Kerja' => 0, 'Depresiasi' => 0];
    $path = $qaRoot.'/synthetic-budget.xlsx';
    qaWorkbook($path, $first['projectCode'], $values);
    qaActor($users['Finance']);
    $records = app(BudgetPidWorkbook::class)->read($path);
    $master = app(BudgetPidMaster::class);
    $preview = $master->import($users['Finance'], $records, ['unit' => 'Konstruksi', 'stage' => 'preview'], hash_file('sha256', $path), 'qa.xlsx');
    qaCheck(DB::table('budget_pids')->count() === 0, 'preview is read only');
    $input = ['unit' => 'Konstruksi', 'stage' => 'commit', 'version' => $preview['version'], 'preview_hash' => $preview['preview_hash'], 'request_id' => (string) Str::uuid()];
    $master->import($users['Finance'], $records, $input, hash_file('sha256', $path), 'qa.xlsx');
    qaCheck($master->import($users['Finance'], $records, $input, hash_file('sha256', $path), 'qa.xlsx')['replayed'], 'import UUID replay');
    $pidRow = DB::table('budget_pids')->first();
    app(BudgetPidBranch::class)->change($users['Finance'], ['ids' => [$pidRow->id_budget_pid], 'unit' => 'Konstruksi',
        'branch_id' => (int) DB::table('branches')->where('name', 'SURABAYA')->value('id_branch'), 'version' => 1, 'request_id' => (string) Str::uuid()]);
    qaActor($users['Admin Konstruksi']);
    qaCommand('submitProjectBundle', [$first['id']]);
    [$second] = qaPackage($first['projectCode'], $users);
    $revision = qaWorkspace()->revision;
    try {
        qaCommand('submitProjectBundle', [$second['id']]);
        throw new RuntimeException('Expected budget cap refusal');
    } catch (HttpException $error) {
        qaCheck($error->getStatusCode() === 422, 'over-budget is rejected');
    }
    qaCheck(qaWorkspace()->revision === $revision, 'budget rejection rolls back revision');
    qaActor($users['PM Konstruksi']);
    qaCommand('decideProject', [$first['id'], 'Kembalikan', 'Isolated QA revision']);
    qaActor($users['Admin Konstruksi']);
    foreach ([$first['id'], $second['id']] as $id) {
        $p = qaProject($id);
        foreach ($p['boq'] as &$line) {
            $line['qty'] = 5;
        }
        unset($line);
        qaCommand('saveProjectBundle', [$p, false]);
        qaCommand('submitProjectBundle', [$id]);
    }
    qaCheck(DB::table('budget_pid_allocations')->where('status', 'reserved')->count() === 2, 'own reservation replacement supports two PP');
    qaActor($users['Superadmin']);
    $state = qaState();
    $p = qaProject($first['id']);
    qaCommand('cancelProject', [$p['id'], ProjectCancellation::plan($state, $p)['expected'], 'Isolated QA cancellation']);
    qaCheck(DB::table('budget_pid_allocations')->where('status', 'cancelled')->count() === 1, 'cancel credits budget');
    foreach (['2026_11_24_000001_create_budgeting_pid_master', '2026_11_24_000002_map_budget_pid_branches', '2026_11_24_000003_create_budget_pid_allocations'] as $name) {
        (require dirname(__DIR__).'/database/migrations/'.$name.'.php')->up();
    }
    file_put_contents($qaRoot.'/scenario.json', json_encode(['users' => $users, 'first' => $first['id'], 'second' => $second['id'], 'pid' => $first['projectCode'], 'values' => $values], JSON_THROW_ON_ERROR));
    echo json_encode(['phase' => 'scenario', 'migration_resume_with_data' => true, 'real_workbook_preview_commit_replay' => true,
        'explicit_branch_not_pid_prefix' => true, 'over_budget_rollback' => true, 'return_resubmit_two_pp' => true, 'cancel_credit' => true], JSON_THROW_ON_ERROR).PHP_EOL;
    exit(0);
}
throw new RuntimeException('Unknown QA phase.');
