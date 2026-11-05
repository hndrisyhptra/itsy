<?php

namespace App\Jobs;

use App\Services\UploadQueue;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Throwable;

class ProcessUpload implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public int $timeout = 90;

    public function __construct(public string $taskId)
    {
        $this->onConnection('uploads')->onQueue('area3-uploads');
    }

    public function backoff(): array
    {
        return [5, 20, 45];
    }

    public function middleware(): array
    {
        return [(new WithoutOverlapping('area3-upload-'.$this->taskId))->releaseAfter(5)->expireAfter(150)];
    }

    public function handle(UploadQueue $uploads): void
    {
        $uploads->process($this->taskId);
    }

    public function failed(?Throwable $error): void
    {
        app(UploadQueue::class)->fail($this->taskId, 'Pemrosesan file gagal setelah tiga percobaan. Silakan unggah ulang atau hubungi administrator.');
    }
}
