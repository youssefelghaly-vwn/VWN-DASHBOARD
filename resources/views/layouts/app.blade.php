{{-- resources/views/layouts/app.blade.php --}}
<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">

    <title>{{ $title ?? 'Outreach Command' }} — VWN</title>

    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Space+Grotesk:wght@500;600;700&family=Inter:wght@400;500;600;700&family=IBM+Plex+Mono:wght@400;500;600&display=swap" rel="stylesheet">

    @vite(['resources/css/app.css', 'resources/js/app.js'])

    <style>
        :root{
            --bg:#F6F3EA; --panel:#FFFFFF; --panel-alt:#EFEAD9;
            --ink:#12241F; --ink-soft:#5B6B64; --line:#DED6C0;
            --mint:#4FE3A6; --mint-deep:#1C7A5C; --amber:#EE9F4E; --coral:#E2694F;
            --sidebar:#0E211D; --sidebar-line:#1E3A33;
        }
        body{background:var(--bg);color:var(--ink);font-family:'Inter',sans-serif;-webkit-font-smoothing:antialiased;}
        .display{font-family:'Space Grotesk',sans-serif;}
        .mono{font-family:'IBM Plex Mono',monospace;}
        [x-cloak]{display:none!important;}
    </style>

    @stack('head')
</head>
<body class="min-h-screen">

<div x-data="{ mobileNav: false }" class="lg:grid lg:grid-cols-[248px_1fr] min-h-screen">

    {{-- MOBILE TOPBAR --}}
    <div class="lg:hidden flex items-center justify-between px-4 py-3"
         style="background:var(--sidebar);">
        <span class="display font-bold text-lg text-white tracking-wide">VWN</span>
        <button @click="mobileNav = !mobileNav" class="text-white text-2xl leading-none">&#9776;</button>
    </div>

    {{-- SIDEBAR --}}
    <aside :class="mobileNav ? 'block' : 'hidden'"
           class="lg:block px-5 py-7 flex-col"
           style="background:var(--sidebar);color:#EAF5F0;">

        <div class="hidden lg:flex items-center gap-2.5 mb-8">
            <svg viewBox="0 0 100 100" class="w-8 h-8 shrink-0" fill="none" xmlns="http://www.w3.org/2000/svg">
                <path d="M12 8 C12 32 22 50 40 62 L28 76 C10 62 0 40 0 8 Z" fill="#4FE3A6"/>
                <path d="M46 62 C56 68 68 70 82 68 L82 92 C60 96 42 90 28 78 Z" fill="#4FE3A6"/>
            </svg>
            <div>
                <div class="display font-bold text-[19px] text-white tracking-wide">VWN</div>
                <div class="text-[10.5px] uppercase tracking-[1.5px] mt-0.5" style="color:var(--mint);">
                    Outreach Command
                </div>
            </div>
        </div>

        <nav class="space-y-1">
            {{-- Dashboards come from the DB-backed menu (no hardcoded routes). --}}
            <a href="{{ route('admin.dashboard') }}" @click="mobileNav = false"
               class="flex items-center gap-3 px-3 py-2.5 rounded-lg text-[13.5px] transition"
               style="{{ request()->routeIs('admin.dashboard') ? 'background:rgba(79,227,166,0.12);color:var(--mint);font-weight:600;' : 'color:#B9CCC4;' }}">
                <span class="text-base leading-none">▤</span><span>Dashboard</span>
            </a>

            @foreach (($menuTree ?? []) as $item)
                @php $active = $item->dashboard && request()->routeIs('admin.dashboards.show') && request()->route('dashboard')?->slug === $item->dashboard->slug; @endphp
                <a href="{{ $item->href() }}" @click="mobileNav = false"
                   class="flex items-center gap-3 px-3 py-2.5 rounded-lg text-[13.5px] transition"
                   style="{{ $active ? 'background:rgba(79,227,166,0.12);color:var(--mint);font-weight:600;' : 'color:#B9CCC4;' }}">
                    <span class="text-base leading-none">◧</span><span>{{ $item->label }}</span>
                </a>
            @endforeach

            @php
                $tools = [
                    ['route' => 'admin.integrations.index', 'label' => 'Integrations', 'icon' => '🔌'],
                    ['route' => 'admin.data-health',        'label' => 'Data Health',  'icon' => '❤'],
                    ['route' => 'admin.menu.index',         'label' => 'Menu',         'icon' => '☰'],
                ];
            @endphp
            <div class="mt-4 pt-4 space-y-1" style="border-top:1px solid var(--sidebar-line);">
                @foreach ($tools as $link)
                    @php $active = request()->routeIs($link['route'].'*'); @endphp
                    <a href="{{ route($link['route']) }}" @click="mobileNav = false"
                       class="flex items-center gap-3 px-3 py-2.5 rounded-lg text-[13.5px] transition"
                       style="{{ $active ? 'background:rgba(79,227,166,0.12);color:var(--mint);font-weight:600;' : 'color:#B9CCC4;' }}">
                        <span class="text-base leading-none">{{ $link['icon'] }}</span><span>{{ $link['label'] }}</span>
                    </a>
                @endforeach
            </div>
        </nav>

        {{-- CONNECTION STATUS --}}
        @php
            $integrations = \App\Integration\Models\Integration::query()->get();
            $connected    = $integrations->where('status', 'connected')->count();
        @endphp
        <div class="mt-8 pt-5" style="border-top:1px solid var(--sidebar-line);">
            <div class="text-[10.5px] uppercase tracking-[1.5px] mb-3" style="color:#7FA396;">Integrations</div>
            <div class="flex items-center gap-2 text-[11.5px]" style="color:#B9CCC4;">
                <span class="w-[7px] h-[7px] rounded-full shrink-0"
                      style="background:{{ $connected ? 'var(--mint)' : '#5C7469' }};
                             {{ $connected ? 'box-shadow:0 0 0 3px rgba(79,227,166,0.2);' : '' }}"></span>
                <span>{{ $connected }} of {{ $integrations->count() }} connected</span>
            </div>
            @foreach ($integrations as $integration)
                <div class="mono text-[10px] mt-2" style="color:#4A6459;">
                    {{ $integration->name }} · {{ $integration->last_synced_at?->diffForHumans(short: true) ?? 'never synced' }}
                </div>
            @endforeach
        </div>

        {{-- USER --}}
        <div class="mt-8 pt-5" style="border-top:1px solid var(--sidebar-line);">
            <div class="text-[13px] text-white font-semibold">{{ auth()->user()->name }}</div>
            <div class="text-[11px] mb-3" style="color:#7FA396;">{{ auth()->user()->email }}</div>
            <form method="POST" action="{{ route('logout') }}">
                @csrf
                <button class="w-full py-2 rounded-lg text-[11.5px] mono transition"
                        style="border:1px solid var(--sidebar-line);color:#EAF5F0;background:transparent;"
                        onmouseover="this.style.borderColor='var(--coral)';this.style.color='var(--coral)'"
                        onmouseout="this.style.borderColor='var(--sidebar-line)';this.style.color='#EAF5F0'">
                    Log out
                </button>
            </form>
        </div>
    </aside>

    {{-- MAIN --}}
    <main class="min-w-0">
        @isset($header)
            <div class="px-6 lg:px-8 pt-7 pb-1">
                {{ $header }}
            </div>
        @endisset

        @if (session('status'))
            <div class="mx-6 lg:mx-8 mt-6 rounded-lg px-4 py-3 text-sm"
                 style="background:rgba(79,227,166,0.14);border:1px solid var(--mint);color:var(--mint-deep);">
                {{ session('status') }}
            </div>
        @endif

        @if (session('error'))
            <div class="mx-6 lg:mx-8 mt-6 rounded-lg px-4 py-3 text-sm"
                 style="background:rgba(226,105,79,0.10);border:1px solid var(--coral);color:#9E3B24;">
                {{ session('error') }}
            </div>
        @endif

        {{-- Any validation / caught exception surfaced via withErrors() shows here,
             so failures are never silent for the user. --}}
        @if ($errors->any())
            <div class="mx-6 lg:mx-8 mt-6 rounded-lg px-4 py-3 text-sm"
                 style="background:rgba(226,105,79,0.10);border:1px solid var(--coral);color:#9E3B24;">
                <div class="font-semibold mb-1">Something went wrong:</div>
                <ul class="list-disc ms-5 space-y-0.5">
                    @foreach ($errors->all() as $error)
                        <li>{{ $error }}</li>
                    @endforeach
                </ul>
            </div>
        @endif

        {{ $slot }}
    </main>
</div>

@stack('scripts')
</body>
</html>