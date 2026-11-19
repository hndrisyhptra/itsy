<?php

return [
    'token' => env('TELEGRAM_BOT_TOKEN'),
    'username' => env('TELEGRAM_BOT_USERNAME'),
    'webhook_secret' => env('TELEGRAM_WEBHOOK_SECRET'),
    'app_url' => env('TELEGRAM_APP_URL', env('APP_URL')),
];
