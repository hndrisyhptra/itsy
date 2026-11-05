<!doctype html>
<html lang="id">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <meta name="theme-color" content="#9FA1FF">
    <meta name="robots" content="noindex,nofollow">
    <title>it's Area 3 · Construction Workspace</title>
    <script>window.__AREA3__ = {{ Illuminate\Support\Js::from($boot) }};</script>
    @vite('resources/js/area3/main.tsx')
</head>
<body><div id="root"></div><noscript>Aktifkan JavaScript untuk menggunakan aplikasi it's Area 3.</noscript></body>
</html>
