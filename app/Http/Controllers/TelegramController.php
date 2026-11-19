<?php

namespace App\Http\Controllers;

use App\Jobs\SendTelegramReminder;
use App\Models\User;
use App\Services\AccountAccess;
use App\Services\TelegramAccounts;
use App\Services\TelegramApi;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class TelegramController extends Controller
{
    public function profile(Request $r, TelegramAccounts $service)
    {
        return response()->json($service->profile((int) $r->user()->getKey()));
    }

    public function changeProfile(Request $r, TelegramAccounts $service)
    {
        $service->ready();
        $input = $r->validate(['action' => 'required|in:link,confirm,disconnect,test', 'binding_version' => 'nullable|uuid', 'request_id' => 'required|uuid']);
        $id = (int) $r->user()->getKey();
        $service->actor($id);
        if ($input['action'] === 'link') {
            return response()->json($service->link($id));
        }
        if ($input['action'] === 'test') {
            DB::transaction(function () use ($id, $input, $service) {
                User::whereKey($id)->lockForUpdate()->firstOrFail();
                $service->actor($id);
                $a = DB::table('user_telegram_accounts')->where('user_id', $id)->whereNotNull('confirmed_at')->where('blocked', false)->first();
                abort_unless($a && $a->binding_version === ($input['binding_version'] ?? ''), 409, 'Hubungkan dan konfirmasikan Telegram terlebih dahulu.');
                $old = DB::table('telegram_notification_logs')->where('id_telegram_notification_log', $input['request_id'])->first();
                if ($old) {
                    abort_unless((int) $old->user_id === $id && $old->event === 'test', 409, 'Identitas permintaan telah digunakan.');

                    return;
                }
                $log = $service->log($id, 'test', 'queued', 'Uji kirim diminta pengguna.', $input['request_id']);
                SendTelegramReminder::dispatch($id, $log, $a->binding_version);
            });

            return response()->json(['message' => 'Pesan uji masuk antrean. Lihat Telegram atau riwayat pengiriman.']);
        }
        $service->change($id, $input['action'], $input['binding_version'] ?? null);

        return response()->json(['message' => $input['action'] === 'confirm' ? 'Akun Telegram terkonfirmasi.' : 'Koneksi Telegram diputus.']);
    }

    public function webhook(Request $r, TelegramAccounts $service)
    {
        $secret = (string) config('telegram.webhook_secret');
        abort_unless(strlen($secret) >= 32 && hash_equals($secret, (string) $r->header('X-Telegram-Bot-Api-Secret-Token')), 403);
        abort_if(strlen($r->getContent()) > 16384, 413);
        $service->ready();
        $service->webhook($r->json()->all());

        return response()->json(['ok' => true]);
    }

    public function admin(Request $r, TelegramAccounts $service)
    {
        $service->ready();
        $service->actor((int) $r->user()->getKey(), true);
        $input = $r->validate(['section' => 'nullable|in:settings,users,logs,pics,candidates', 'q' => 'nullable|string|max:100', 'page' => 'nullable|integer|min:1|max:10000', 'branch_id' => 'nullable|integer', 'work_unit_id' => 'nullable|integer']);
        $section = $input['section'] ?? 'settings';
        $page = (int) ($input['page'] ?? 1);
        $q = trim($input['q'] ?? '');
        if ($section === 'settings') {
            $settings = DB::table('telegram_settings')->first();

            return response()->json(['settings' => $settings, 'bot' => ['configured' => (bool) config('telegram.token'), 'username' => config('telegram.username'), 'webhook_configured' => strlen((string) config('telegram.webhook_secret')) >= 32, 'app_url' => config('telegram.app_url')]]);
        }
        if ($section === 'users') {
            $rows = DB::table('users as u')->leftJoin('user_telegram_accounts as t', 't.user_id', '=', 'u.id_user')->when($q !== '', fn ($b) => $b->where(fn ($b) => $b->where('u.name', 'like', '%'.$q.'%')->orWhere('u.username', 'like', '%'.$q.'%')))
                ->orderBy('u.id_user')->offset(($page - 1) * 20)->limit(21)->get(['u.id_user', 'u.name', 'u.username', 'u.active', 'u.role', 't.telegram_name', 't.telegram_username', 't.confirmed_at', 't.blocked', 't.binding_version']);
        } elseif ($section === 'logs') {
            $rows = DB::table('telegram_notification_logs as l')->leftJoin('users as u', 'u.id_user', '=', 'l.user_id')->orderByDesc('l.created_at')->orderByDesc('l.id_telegram_notification_log')->offset(($page - 1) * 20)->limit(21)->get(['l.id_telegram_notification_log', 'u.name', 'l.event', 'l.status', 'l.detail', 'l.created_at']);
        } elseif ($section === 'pics') {
            $rows = DB::table('telegram_finance_pics as p')->join('branches as b', 'b.id_branch', '=', 'p.branch_id')->join('work_units as w', 'w.id_work_unit', '=', 'p.work_unit_id')->join('users as u', 'u.id_user', '=', 'p.user_id')->orderBy('b.name')->offset(($page - 1) * 20)->limit(21)->get(['p.id_telegram_finance_pic', 'b.name as branch', 'w.name as unit', 'u.name']);

            return response()->json(['rows' => $rows->take(20)->values(), 'has_more' => $rows->count() > 20, 'branches' => DB::table('branches')->whereNull('deleted_at')->where('is_active', true)->orderBy('name')->get(['id_branch', 'name']), 'units' => DB::table('work_units')->whereIn('code', ['KONSTRUKSI', 'OPERATION'])->get(['id_work_unit', 'name', 'code']), 'version' => DB::table('telegram_settings')->value('version')]);
        } else {
            $users = User::where('role', 'FINANCE')->where('active', true)->when($q !== '', fn ($b) => $b->where('name', 'like', '%'.$q.'%'))->orderBy('id_user')->offset(($page - 1) * 20)->limit(21)->get(['id_user', 'name', 'username', 'email', 'jabatan', 'role', 'active', 'work_unit_id', 'access_scope', 'id_regions']);
            $contexts = app(AccountAccess::class)->contexts($users);
            $unit = DB::table('work_units')->where('id_work_unit', $input['work_unit_id'] ?? 0)->value('code');
            $label = ['KONSTRUKSI' => 'Konstruksi', 'OPERATION' => 'Operation'][$unit] ?? '';
            $rows = $users->take(20)->filter(fn ($u) => isset($contexts[$u->id_user]) && in_array((int) ($input['branch_id'] ?? 0), $contexts[$u->id_user]['branchIds']) && in_array($label, $contexts[$u->id_user]['workUnits']))->map(fn ($u) => ['id_user' => $u->id_user, 'name' => $u->name])->values();

            return response()->json(['rows' => $rows, 'has_more' => $users->count() > 20]);
        }

        return response()->json(['rows' => $rows->take(20)->values(), 'has_more' => $rows->count() > 20]);
    }

    public function changeAdmin(Request $r, TelegramAccounts $service)
    {
        $service->ready();
        $id = (int) $r->user()->getKey();
        $service->actor($id, true);
        $input = $r->validate(['action' => 'required|in:save,pic,delete_pic,probe', 'version' => 'required_unless:action,probe|integer|min:1', 'enabled' => 'required_if:action,save|boolean', 'all_day' => 'required_if:action,save|boolean', 'start_time' => 'required_if:action,save|date_format:H:i', 'end_time' => 'required_if:action,save|date_format:H:i', 'message_template' => 'required_if:action,save|string|max:1800', 'branch_id' => 'required_if:action,pic|integer', 'work_unit_id' => 'required_if:action,pic|integer', 'user_id' => 'required_if:action,pic|integer', 'id_pic' => 'required_if:action,delete_pic|uuid']);
        if ($input['action'] === 'probe') {
            $me = app(TelegramApi::class)->call('getMe', []);
            $webhook = app(TelegramApi::class)->call('getWebhookInfo', []);

            $identity = $me['ok'] && strcasecmp((string) ($me['result']['username'] ?? ''), (string) config('telegram.username')) === 0;

            return response()->json(['message' => $identity ? 'Bot dapat diakses dan username sesuai.' : 'Bot/username belum sesuai. Periksa konfigurasi server.', 'ok' => $identity, 'webhook_ok' => $webhook['ok'] && ($webhook['result']['url'] ?? '') === rtrim((string) config('telegram.app_url'), '/').'/api/telegram/webhook', 'pending_updates' => $webhook['ok'] ? ($webhook['result']['pending_update_count'] ?? 0) : null]);
        }
        DB::transaction(function () use ($input, $id, $service) {
            User::whereKey($id)->lockForUpdate()->firstOrFail();
            $service->actor($id, true);
            $settings = DB::table('telegram_settings')->where('id_telegram_setting', 1)->lockForUpdate()->first();
            abort_unless((int) $settings->version === (int) $input['version'], 409, 'Pengaturan berubah. Muat ulang sebelum menyimpan.');
            if ($input['action'] === 'save') {
                preg_match_all('/\{[^}]*\}/', $input['message_template'], $matches);
                abort_if(array_diff($matches[0], ['{name}', '{count}', '{pp_count}', '{payment_count}']), 422, 'Placeholder pesan tidak dikenal.');
                abort_if(! $input['all_day'] && $input['start_time'] === $input['end_time'], 422, 'Awal dan akhir jam pengiriman harus berbeda.');
                abort_if($input['enabled'] && (! config('telegram.token') || ! preg_match('/^[A-Za-z0-9_]{5,32}$/D', (string) config('telegram.username')) || ! preg_match('/^[A-Za-z0-9_-]{32,256}$/D', (string) config('telegram.webhook_secret')) || ! filter_var(config('telegram.app_url'), FILTER_VALIDATE_URL) || parse_url((string) config('telegram.app_url'), PHP_URL_SCHEME) !== 'https'), 422, 'Lengkapi konfigurasi bot/webhook server sebelum mengaktifkan reminder.');
                DB::table('telegram_settings')->where('id_telegram_setting', 1)->update(array_intersect_key($input, array_flip(['enabled', 'all_day', 'start_time', 'end_time', 'message_template'])));
            } elseif ($input['action'] === 'pic') {
                $user = User::whereKey($input['user_id'])->lockForUpdate()->firstOrFail();
                $context = app(AccountAccess::class)->context($user);
                $unit = DB::table('work_units')->where('id_work_unit', $input['work_unit_id'])->value('code');
                $label = ['KONSTRUKSI' => 'Konstruksi', 'OPERATION' => 'Operation'][$unit] ?? '';
                abort_unless($context && $context['code'] === 'FINANCE' && in_array($input['branch_id'], $context['branchIds']) && in_array($label, $context['workUnits']), 422, 'Pilih Finance aktif dengan Branch dan Unit Kerja sesuai.');
                DB::table('telegram_finance_pics')->updateOrInsert(['branch_id' => $input['branch_id'], 'work_unit_id' => $input['work_unit_id']], ['id_telegram_finance_pic' => (string) Str::uuid(), 'user_id' => $input['user_id']]);
            } else {
                DB::table('telegram_finance_pics')->where('id_telegram_finance_pic', $input['id_pic'])->delete();
            }
            DB::table('telegram_settings')->where('id_telegram_setting', 1)->update(['version' => $settings->version + 1, 'updated_at' => now()]);
            $service->log($id, $input['action'], 'success', 'Pengaturan Telegram diperbarui oleh Superadmin.');
        });

        return response()->json(['message' => 'Pengaturan Telegram disimpan.']);
    }
}
