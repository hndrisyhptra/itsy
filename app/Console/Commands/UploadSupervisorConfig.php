<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;

class UploadSupervisorConfig extends Command
{
    protected $signature = 'area3:supervisor-config {--user= : User OS PHP-FPM situs, misalnya www}';

    protected $description = 'Tampilkan konfigurasi Supervisor worker upload; tidak memasang atau menjalankan proses';

    public function handle(): int
    {
        $user = $this->option('user');
        if (! is_string($user) || ! preg_match('/\A[a-z_][a-z0-9_-]*\z/i', $user) || strtolower($user) === 'root') {
            $this->error('Isi --user dengan user OS PHP-FPM situs (misalnya www), bukan root.');

            return self::FAILURE;
        }

        $paths = ['{{project}}' => base_path(), '{{storage}}' => storage_path(), '{{php}}' => PHP_BINARY];
        foreach ($paths as $path) {
            // Supervisor treats semicolons/comments and percent interpolation specially.
            // Fail closed instead of emitting an ambiguous executable configuration.
            if (! preg_match('#\A/[a-zA-Z0-9_./-]+\z#', $path) || str_contains($path, '..')) {
                $this->error('Path worker harus absolut tanpa spasi, karakter khusus atau segmen parent. Sesuaikan konfigurasi Supervisor secara manual.');

                return self::FAILURE;
            }
        }

        $templatePath = base_path('deploy/supervisor/area3-uploads.conf.stub');
        if (! is_file($templatePath) || ! is_readable($templatePath)) {
            $this->error('Template Supervisor tidak tersedia. Pastikan folder deploy ikut diunggah.');

            return self::FAILURE;
        }

        $template = file_get_contents($templatePath);
        $this->output->write(strtr($template, $paths + ['{{user}}' => $user]));

        return self::SUCCESS;
    }
}
