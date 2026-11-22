<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex,nofollow">
    <title>@yield('title', 'Error')</title>
    <style>
        html, body {
            margin: 0;
            min-height: 100%;
            background: #f9fafb;
            color: #111827;
            font-family: ui-sans-serif, system-ui, -apple-system, BlinkMacSystemFont, "Segoe UI", sans-serif;
        }

        a {
            color: #4f46e5;
        }
    </style>
</head>
<body class="min-h-screen bg-gray-50 antialiased">
@yield('content')
</body>
</html>
