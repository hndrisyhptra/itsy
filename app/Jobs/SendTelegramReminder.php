<?php

namespace App\Jobs;

use App\Services\TelegramReminders;
use App\Support\Area3MigrationTarget;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

class SendTelegramReminder implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $timeout = 90;

    public int $uniqueFor = 180;

    public function __construct(public int $userId, public ?string $testLog = null, public ?string $binding = null)
    {
        $this->onConnection('notifications')->onQueue('area3-notifications');
    }

    public function uniqueId(): string
    {
        return 'area3-telegram-'.$this->userId.'-'.($this->testLog ?? 'reminder');
    }

    public function handle(TelegramReminders $service): void
    {
        Area3MigrationTarget::assertAllowed();
        $sent = Cache::lock('area3-telegram-send-'.$this->userId, 100)->get(function () use ($service) {
            $service->send($this->userId, $this->testLog, $this->binding);
        });
        if ($sent === false && $this->testLog) {
            DB::table('telegram_notification_logs')->where('id_telegram_notification_log', $this->testLog)->where('status', 'queued')->update(['status' => 'cancelled', 'detail' => 'Pengiriman lain sedang berlangsung; silakan uji kembali setelah selesai.']);
        }
    }
}
