{{-- resources/views/admin/ghl-health.blade.php --}}
<x-app-layout title="Data Health">
    <x-slot name="header">
        <h1 class="display text-2xl font-bold">Data Health</h1>
        <p class="text-sm" style="color:var(--ink-soft);">
            What the dashboard pulls from GoHighLevel — and how long each object takes.
        </p>
    </x-slot>

    <div class="px-6 lg:px-8 py-8 max-w-3xl">

        @if (! $connection || ! $isGhl)
            <div class="rounded-xl p-6 text-sm" style="background:var(--panel);border:1px solid var(--line);color:var(--ink-soft);">
                GoHighLevel isn’t the active data source.
                <a href="{{ route('admin.settings') }}" class="underline font-semibold" style="color:var(--mint-deep);">Open Settings</a>
                to connect it.
            </div>
        @else
            @if ($error)
                <div class="mb-5 rounded-lg px-4 py-3 text-sm"
                     style="background:rgba(226,105,79,0.10);border:1px solid var(--coral);color:#9E3B24;">
                    {{ $error }}
                </div>
            @endif

            @php
                $resources = $meta['resources'] ?? [];
                $maxMs     = collect($resources)->max('ms') ?: 1;
            @endphp

            <div class="flex items-center justify-between gap-3 mb-5">
                <div class="text-xs" style="color:var(--ink-soft);">
                    Pulling
                    <b style="color:var(--ink);">{{ implode(', ', $selected) }}</b>.
                    <a href="{{ route('admin.settings') }}" class="underline" style="color:var(--mint-deep);">Change selection</a>
                </div>
                <a href="{{ route('admin.ghl.health', ['refresh' => 1]) }}"
                   class="px-4 py-2 rounded-lg text-sm font-semibold text-white shrink-0"
                   style="background:var(--mint-deep);"
                   onclick="this.textContent='Timing…';">↻ Re-run &amp; time</a>
            </div>

            @if ($resources)
                <div class="rounded-xl overflow-hidden" style="background:var(--panel);border:1px solid var(--line);">
                    <table class="w-full text-xs">
                        <thead>
                            <tr style="background:var(--panel-alt);">
                                <th class="text-left px-4 py-3 font-semibold uppercase text-[10px] tracking-wide" style="color:var(--ink-soft);">Object</th>
                                <th class="text-left px-4 py-3 font-semibold uppercase text-[10px] tracking-wide" style="color:var(--ink-soft);">Endpoint</th>
                                <th class="text-right px-4 py-3 font-semibold uppercase text-[10px] tracking-wide" style="color:var(--ink-soft);">Rows</th>
                                <th class="text-left px-4 py-3 font-semibold uppercase text-[10px] tracking-wide" style="color:var(--ink-soft);width:38%;">Time</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach ($resources as $resource => $info)
                                <tr style="border-top:1px solid var(--panel-alt);">
                                    <td class="px-4 py-3 align-top">
                                        <div class="flex items-center gap-2">
                                            <span class="w-2 h-2 rounded-full shrink-0"
                                                  style="background:{{ ($info['ok'] ?? false) ? 'var(--mint)' : 'var(--coral)' }};"></span>
                                            <span class="font-semibold">{{ $resource }}</span>
                                        </div>
                                        @if (! empty($info['error']))
                                            <div class="mt-1 text-[11px]" style="color:var(--coral);">{{ $info['error'] }}</div>
                                        @endif
                                    </td>
                                    <td class="px-4 py-3 align-top mono text-[11px]" style="color:var(--ink-soft);">{{ $info['endpoint'] ?? '—' }}</td>
                                    <td class="px-4 py-3 align-top text-right mono">{{ number_format($info['count'] ?? 0) }}</td>
                                    <td class="px-4 py-3 align-top">
                                        <div class="flex items-center gap-2">
                                            <div class="flex-1 h-2.5 rounded-full overflow-hidden" style="background:var(--panel-alt);">
                                                <div class="h-full rounded-full"
                                                     style="width:{{ max(3, round(($info['ms'] ?? 0) / $maxMs * 100)) }}%;
                                                            background:{{ ($info['ms'] ?? 0) >= $maxMs && $maxMs > 1 ? 'var(--amber)' : 'var(--mint-deep)' }};"></div>
                                            </div>
                                            <span class="mono text-[11px] w-20 text-right shrink-0">{{ number_format($info['ms'] ?? 0) }} ms</span>
                                        </div>
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>

                <div class="flex items-center justify-between mt-4">
                    <p class="mono text-[11px]" style="color:var(--ink-soft);">
                        Total {{ number_format($meta['total_ms'] ?? 0) }} ms across {{ count($resources) }} calls
                        @if (!empty($meta['ran_at']))· measured {{ \Illuminate\Support\Carbon::parse($meta['ran_at'])->diffForHumans() }}@endif
                    </p>
                    <a href="{{ route('admin.ghl.diagnose') }}" target="_blank"
                       class="text-[11px] underline" style="color:var(--ink-soft);">Raw endpoint probe (JSON)</a>
                </div>

                <div class="mt-6 rounded-lg p-4 text-xs" style="background:var(--panel-alt);color:var(--ink-soft);">
                    <b style="color:var(--ink);">Tuning tip:</b> the slowest object (highlighted amber) is what
                    holds up the dashboard. If you don’t need it, deselect it in
                    <a href="{{ route('admin.settings') }}" class="underline" style="color:var(--mint-deep);">Settings</a>
                    and it won’t be fetched at all. Prerequisites like <span class="mono">Pipelines</span>
                    (Opportunities) and <span class="mono">CustomFields</span> (Contacts) are only fetched when
                    the object that needs them is selected.
                </div>
            @else
                <div class="rounded-xl p-6 text-sm" style="background:var(--panel);border:1px solid var(--line);color:var(--ink-soft);">
                    No timing data yet. Click <b>↻ Re-run &amp; time</b> above to pull the selected objects
                    once and measure each one.
                </div>
            @endif
        @endif
    </div>
</x-app-layout>
