<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" class="h-full">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">

    <title>{{ config('setting.title.'.(lang()->path_alias ?? lang()->path)) }}</title>
    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>
<body class="antialiased h-full">

<main class="min-h-full bg-cover bg-top sm:bg-top"
      style="background-image: url('https://images.unsplash.com/photo-1545972154-9bb223aac798?ixid=MnwxMjA3fDB8MHxwaG90by1wYWdlfHx8fGVufDB8fHx8&ixlib=rb-1.2.1&auto=format&fit=crop&w=3050&q=80&exp=8&con=-15&sat=-75')">
    <div class="max-w-7xl mx-auto px-4 py-16 text-center sm:px-6 sm:py-24 lg:px-8 lg:py-48">
        <p class="text-sm font-semibold text-black/50 uppercase tracking-wide">503 - Service Unavailable</p>
        <h1 class="mt-2 text-4xl font-extrabold text-white tracking-tight sm:text-5xl">Please hang on!</h1>
        <p class="mt-2 text-lg font-medium text-black/50">Uh oh! We're performing maintenance on our website with some tiny updates. We
            will be right back!</p>
        <p class="mt-2 text-lg font-medium text-black/50">Please contact us via
            email {{ config('setting.email_contact') }} if you need more information.</p>
    </div>
</main>

</body>
</html>
