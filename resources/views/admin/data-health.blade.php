{{-- resources/views/admin/data-health.blade.php --}}
<x-app-layout title="Data Health">
    <div class="px-6 lg:px-8 py-8">
        <div class="mb-7">
            <h1 class="display text-2xl font-bold">Data Health</h1>
            <p class="text-sm" style="color:var(--ink-soft);">
                Sync status for every integration. This page is generic — every provider reports the same way.
            </p>
        </div>

        @if (! count($report))
            <div class="rounded-xl px-5 py-8 text-center text-sm" style="background:var(--panel);border:1px solid var(--line);color:var(--ink-soft);">
                No integrations connected yet.
            </div>
        @endif

        <div class="space-y-4">
            @foreach ($report as $row)
                @php
                    $color = match ($row['status']) {
                        'success'   => 'var(--mint-deep)',
                        'partial'   => 'var(--amber)',
                        'failed', 'error' => 'var(--coral)',
                        default     => 'var(--ink-soft)',
                    };
                @endphp
                <div class="rounded-xl p-5" style="background:var(--panel);border:1px solid var(--line);">
                    <div class="flex flex-wrap items-start justify-between gap-3 mb-4">
                        <div>
                            <h3 class="display text-[15px] font-semibold">{{ $row['name'] }}</h3>
                            <div class="mono text-[11px]" style="color:var(--ink-soft);">{{ $row['provider'] }}</div>
                        </div>
                        <form method="POST" action="{{ route('admin.data-health.sync', $row['id']) }}">
                            @csrf
                            <button class="px-3 py-1.5 rounded-lg text-xs font-medium" style="border:1px solid var(--line);">↻ Sync now</button>
                        </form>
                    </div>

                    <div class="grid grid-cols-2 md:grid-cols-5 gap-3 mb-3">
                        <div>
                            <div class="text-[10px] uppercase tracking-wide" style="color:var(--ink-soft);">Status</div>
                            <div class="font-semibold text-sm" style="color:{{ $color }};">{{ ucfirst($row['status']) }}</div>
                        </div>
                        <div>
                            <div class="text-[10px] uppercase tracking-wide" style="color:var(--ink-soft);">Last Sync</div>
                            <div class="font-semibold text-sm" title="{{ $row['last_sync_at'] }}">{{ $row['last_sync'] ?? 'Never' }}</div>
                        </div>
                        <div>
                            <div class="text-[10px] uppercase tracking-wide" style="color:var(--ink-soft);">Records Synced</div>
                            <div class="font-semibold text-sm">{{ number_format($row['records_synced']) }}</div>
                        </div>
                        <div>
                            <div class="text-[10px] uppercase tracking-wide" style="color:var(--ink-soft);">Failed Records</div>
                            <div class="font-semibold text-sm">{{ number_format($row['failed_records']) }}</div>
                        </div>
                        <div>
                            <div class="text-[10px] uppercase tracking-wide" style="color:var(--ink-soft);">Duration</div>
                            <div class="font-semibold text-sm">{{ $row['duration_ms'] !== null ? $row['duration_ms'].' ms' : '—' }}</div>
                        </div>
                    </div>

                    @if ($row['last_error'])
                        <div class="rounded-lg px-3 py-2 text-xs mono mb-3"
                             style="background:rgba(226,105,79,0.10);border:1px solid var(--coral);color:#9E3B24;">
                            {{ $row['last_error'] }}
                        </div>
                    @endif

                    @if (count($row['datasets']))
                        <div class="overflow-auto rounded-lg" style="border:1px solid var(--line);">
                            <table class="w-full text-xs">
                                <thead style="background:var(--panel-alt);">
                                    <tr>
                                        <th class="text-left px-3 py-2 font-semibold uppercase text-[10px]" style="color:var(--ink-soft);">Dataset</th>
                                        <th class="text-left px-3 py-2 font-semibold uppercase text-[10px]" style="color:var(--ink-soft);">OK</th>
                                        <th class="text-left px-3 py-2 font-semibold uppercase text-[10px]" style="color:var(--ink-soft);">Records</th>
                                        <th class="text-left px-3 py-2 font-semibold uppercase text-[10px]" style="color:var(--ink-soft);">ms</th>
                                        <th class="text-left px-3 py-2 font-semibold uppercase text-[10px]" style="color:var(--ink-soft);">Error</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    @foreach ($row['datasets'] as $name => $d)
                                        <tr style="border-top:1px solid var(--panel-alt);">
                                            <td class="px-3 py-2">{{ $name }}</td>
                                            <td class="px-3 py-2">{{ ($d['ok'] ?? false) ? '✓' : '✕' }}</td>
                                            <td class="px-3 py-2">{{ number_format($d['count'] ?? 0) }}</td>
                                            <td class="px-3 py-2">{{ $d['ms'] ?? 0 }}</td>
                                            <td class="px-3 py-2 text-[11px]" style="color:var(--coral);">{{ $d['error'] ?? '' }}</td>
                                        </tr>
                                    @endforeach
                                </tbody>
                            </table>
                        </div>
                    @endif
                </div>
            @endforeach
        </div>
    </div>
</x-app-layout>
