{{-- resources/views/admin/partials/ghl-opportunity-fields.blade.php --}}
{{--
    Which GoHighLevel opportunity fields become columns. The catalogue is
    written by the sync ("Opportunity Fields" dataset), so this is a plain list
    render — no API call happens on this page.

    A location can define a thousand custom fields, hence the text filter: every
    row stays in the DOM and is only hidden, so a selected field that the filter
    hides is still submitted instead of being silently unticked.
--}}
@php
    $selectedKeys = $integration->setting('opportunity_fields');
    $selectedKeys = is_array($selectedKeys) ? array_values($selectedKeys) : [];

    // Grouped by Kind, sorted by Label — the order the picker renders in.
    $sorted = collect($fields)
        ->sortBy([['Kind', 'asc'], ['Label', 'asc']])
        ->values()
        ->all();
@endphp

<div class="mt-4 p-4 rounded-lg" style="background:var(--panel-alt);">
    <div class="flex items-baseline justify-between gap-3 mb-1.5">
        <h4 class="display text-[13px] font-semibold uppercase tracking-wide">Opportunity columns</h4>
        <span class="mono text-[11px]" style="color:var(--ink-soft);">{{ count($sorted) }} available</span>
    </div>
    <p class="text-[11px] mb-3" style="color:var(--ink-soft);">
        Pick the fields that become columns on every opportunity row. Picked fields appear even when
        blank — GoHighLevel leaves empty custom fields out of a record entirely, so an unpicked field
        only shows up by accident, whenever some record happens to carry a value for it.
    </p>

    @if (! $sorted)
        <div class="rounded-lg px-4 py-6 text-center text-[12px]" style="border:1px dashed var(--line);color:var(--ink-soft);">
            No field catalogue yet — it is built during a sync.
            Run <span class="font-semibold">↻ Sync</span> above, then reopen this panel to pick columns.
        </div>
    @else
        <form method="POST" action="{{ route('admin.integrations.opportunity-fields', $integration) }}"
              x-data="{
                  q: '',
                  selected: {{ Js::from($selectedKeys) }},
                  matches(label, type) {
                      const q = this.q.trim().toLowerCase();
                      return ! q || label.toLowerCase().includes(q) || type.toLowerCase().includes(q);
                  },
              }">
            @csrf @method('PUT')

            <div class="flex flex-wrap items-center gap-3 mb-3">
                <input x-model="q" type="search" placeholder="Filter fields…"
                       class="flex-1 min-w-[180px] rounded-lg text-sm px-3 py-2"
                       style="border:1px solid var(--line);background:var(--panel);">
                <span class="mono text-[11px]" style="color:var(--ink-soft);">
                    <span x-text="selected.length"></span> selected
                </span>
                <button type="button" @click="selected = []"
                        class="px-3 py-1.5 rounded-lg text-xs font-medium" style="border:1px solid var(--line);">
                    Clear all
                </button>
            </div>

            <div x-show="selected.length > 60" x-cloak class="rounded-md px-3 py-2 text-[11px] mb-3"
                 style="background:rgba(226,105,79,0.10);border:1px solid var(--coral);color:#9E3B24;">
                <span class="font-semibold">That is a lot of columns.</span>
                Every picked field becomes a key on every opportunity row, so a large selection bloats the
                stored payloads and slows the sheet grid down. Keep it to the fields you actually chart or filter on.
            </div>

            <div class="rounded-lg max-h-72 overflow-y-auto" style="border:1px solid var(--line);background:var(--panel);">
                @foreach (collect($sorted)->groupBy('Kind') as $kind => $group)
                    <div class="px-3 py-1.5 mono text-[10px] uppercase tracking-wide sticky top-0"
                         style="background:var(--panel-alt);border-bottom:1px solid var(--line);color:var(--ink-soft);">
                        {{ $kind }} · {{ count($group) }}
                    </div>
                    @foreach ($group as $field)
                        <label class="flex items-center gap-2.5 px-3 py-1.5 text-[12px] cursor-pointer"
                               x-show="matches({{ Js::from($field['Label'] ?? '') }}, {{ Js::from($field['Type'] ?? '') }})">
                            <input type="checkbox" name="fields[]" value="{{ $field['Key'] }}" x-model="selected"
                                   class="rounded" style="accent-color:var(--mint-deep);">
                            <span class="flex-1 min-w-0 truncate">{{ $field['Label'] }}</span>
                            <span class="mono text-[10px]" style="color:var(--ink-soft);">{{ $field['Type'] }}</span>
                        </label>
                    @endforeach
                @endforeach
            </div>

            <div class="flex flex-wrap items-center gap-3 mt-3">
                <button class="px-4 py-2 rounded-lg text-sm font-semibold text-white" style="background:var(--mint-deep);">
                    Save columns
                </button>
                <span class="text-[11px]" style="color:var(--ink-soft);">
                    Saving queues a sync — columns are written into each row, so they only change after one.
                </span>
            </div>
        </form>
    @endif
</div>
