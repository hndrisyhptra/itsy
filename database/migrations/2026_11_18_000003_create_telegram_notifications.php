<?php

use App\Support\Area3MigrationTarget;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Area3MigrationTarget::assertAllowed();
        Schema::create('telegram_settings', function (Blueprint $t) {
            $t->unsignedTinyInteger('id_telegram_setting')->primary();
            $t->boolean('enabled')->default(false);
            $t->boolean('all_day')->default(true);
            $t->string('start_time', 5)->default('08:00');
            $t->string('end_time', 5)->default('17:00');
            $t->text('message_template');
            $t->unsignedInteger('version')->default(1);
            $t->timestamp('updated_at')->nullable();
            $t->timestamp('scheduler_at')->nullable();
            $t->timestamp('worker_at')->nullable();
        });
        DB::table('telegram_settings')->insert(['id_telegram_setting' => 1, 'message_template' => "IT’S AREA 3 — PENGINGAT APPROVAL\n\nHalo {name}, ada {count} pengajuan menunggu Anda.\nPaket PP: {pp_count}\nPembayaran: {payment_count}\n\nSilakan tinjau melalui Inbox Approval."]);
        Schema::create('user_telegram_accounts', function (Blueprint $t) {
            $t->uuid('id_user_telegram')->primary();
            $t->unsignedBigInteger('user_id')->unique();
            $t->char('chat_hash', 64)->unique();
            $t->text('chat_cipher');
            $t->string('telegram_name', 150);
            $t->string('telegram_username', 100)->nullable();
            $t->uuid('binding_version');
            $t->timestamp('confirmed_at')->nullable();
            $t->boolean('blocked')->default(false);
            $t->timestamps();
            $t->foreign('user_id')->references('id_user')->on('users');
        });
        Schema::create('telegram_link_tokens', function (Blueprint $t) {
            $t->uuid('id_telegram_link_token')->primary();
            $t->unsignedBigInteger('user_id')->unique();
            $t->char('token_hash', 64)->unique();
            $t->timestamp('expires_at');
            $t->timestamp('consumed_at')->nullable();
            $t->foreign('user_id')->references('id_user')->on('users');
        });
        Schema::create('telegram_finance_pics', function (Blueprint $t) {
            $t->uuid('id_telegram_finance_pic')->primary();
            $t->unsignedBigInteger('branch_id');
            $t->unsignedSmallInteger('work_unit_id');
            $t->unsignedBigInteger('user_id');
            $t->unique(['branch_id', 'work_unit_id'], 'telegram_pic_scope');
            $t->foreign('branch_id')->references('id_branch')->on('branches');
            $t->foreign('work_unit_id')->references('id_work_unit')->on('work_units');
            $t->foreign('user_id')->references('id_user')->on('users');
        });
        Schema::create('approval_notification_tasks', function (Blueprint $t) {
            $t->uuid('id_approval_notification')->primary();
            $t->char('target_key', 64)->unique();
            $t->unsignedBigInteger('user_id');
            $t->string('kind', 12);
            $t->string('target_id', 100);
            $t->timestamp('next_remind_at');
            $t->timestamp('sent_at')->nullable();
            $t->timestamp('closed_at')->nullable();
            $t->index(['closed_at', 'next_remind_at', 'user_id'], 'telegram_tasks_due');
            $t->index(['user_id', 'closed_at'], 'telegram_tasks_user');
        });
        Schema::create('telegram_notification_logs', function (Blueprint $t) {
            $t->uuid('id_telegram_notification_log')->primary();
            $t->unsignedBigInteger('user_id')->nullable();
            $t->string('event', 30);
            $t->string('status', 20);
            $t->string('detail', 250);
            $t->string('telegram_message_id', 30)->nullable();
            $t->timestamp('created_at');
            $t->index(['created_at', 'id_telegram_notification_log'], 'telegram_log_page');
            $t->index(['user_id', 'created_at'], 'telegram_log_user');
        });
    }

    public function down(): void
    {
        Area3MigrationTarget::assertAllowed();
        foreach (['user_telegram_accounts', 'telegram_link_tokens', 'telegram_finance_pics', 'approval_notification_tasks', 'telegram_notification_logs'] as $table) {
            if (DB::table($table)->exists()) {
                throw new RuntimeException('Data Telegram sudah ada; rollback ditolak agar koneksi/audit tidak hilang.');
            }
        }
        foreach (['telegram_notification_logs', 'approval_notification_tasks', 'telegram_finance_pics', 'telegram_link_tokens', 'user_telegram_accounts', 'telegram_settings'] as $table) {
            Schema::dropIfExists($table);
        }
    }
};
