<?php

return [
    // Local XAMPP can launch one worker using the same OS user as Apache.
    // Supervised production deployments should set AREA3_UPLOAD_AUTOSTART=false.
    'autostart' => (bool) env('AREA3_UPLOAD_AUTOSTART', env('APP_ENV', 'production') === 'local'),
    'php' => env('AREA3_WORKER_PHP', PHP_BINDIR.'/php'),
];
