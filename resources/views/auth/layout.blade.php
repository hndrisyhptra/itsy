<!doctype html>
<html lang="id">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="theme-color" content="#9FA1FF">
    <meta name="robots" content="noindex,nofollow">
    <title>@yield('title', "Masuk · it's Area 3")</title>
    @vite('resources/js/area3/auth.ts')
</head>
<body class="access-page @yield('body-class')">
@yield('content')
</body>
</html>
