<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">

<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">

    <title>{{ config('app.name', 'Outreach Command') }}</title>

    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link
        href="https://fonts.googleapis.com/css2?family=Space+Grotesk:wght@500;600;700&family=Inter:wght@400;500;600;700&family=IBM+Plex+Mono:wght@400;500;600&display=swap"
        rel="stylesheet">
    <link rel="icon" type="image/x-icon" href="{{ asset('assets/favicon.ico') }}">
    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>

<body class="antialiased" style="background:var(--bg);">
    <div class="min-h-screen flex flex-col items-center justify-center px-4 py-10 relative overflow-hidden">

        {{-- A single restrained glow behind the card — the one bit of
                 atmosphere on an otherwise still, dark page. --}}
        <div class="pointer-events-none absolute -top-40 left-1/2 -translate-x-1/2 w-[560px] h-[560px] rounded-full"
            style="background:radial-gradient(circle, rgba(43,227,143,0.10) 0%, transparent 70%);"></div>

        <a href="/" class="relative flex items-center gap-2.5 mb-8">
            <img src="{{ asset('assets/logo.png') }}" class="w-[200px]" alt="">
        </a>

        <div class="relative w-full sm:max-w-md rounded-2xl px-7 py-7 sm:px-9 sm:py-8"
            style="background:var(--panel);border:1px solid var(--line);border-top:3px solid var(--mint);box-shadow:0 0 0 1px rgba(43,227,143,0.06),0 24px 48px -20px rgba(0,0,0,0.55);">
            {{ $slot }}
        </div>

        <p class="relative mt-7 text-[11.5px] mono" style="color:var(--ink-soft);">
            &copy; {{ date('Y') }} VWN — Dashboard
        </p>
    </div>
</body>

</html>
