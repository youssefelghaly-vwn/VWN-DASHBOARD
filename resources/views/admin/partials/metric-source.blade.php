{{--
    resources/views/admin/partials/metric-source.blade.php

    A reusable "where does this number come from" editor.
    $bind = the Alpine object path holding { key, agg, column, filter_* }.
    `key` is the composite integration+dataset source (integration_id::dataset).
    Used for a simple metric (bind="metric.simple") and per formula variable.
--}}
<div class="grid grid-cols-12 gap-2">
    <div class="col-span-3">
        <label class="block text-[10px] mb-1" style="color:var(--ink-soft);">Source</label>
        <select x-model="{{ $bind }}.key"
                @change="{{ $bind }}.column = ''; {{ $bind }}.filter_column = ''; {{ $bind }}.filters = []"
                class="w-full rounded text-xs px-2 py-1.5"
                style="border:1px solid var(--line);background:var(--panel);">
            <template x-for="s in sources" :key="s.key">
                <option :value="s.key" x-text="s.label"></option>
            </template>
        </select>
    </div>

    <div class="col-span-3">
        <label class="block text-[10px] mb-1" style="color:var(--ink-soft);">Measure</label>
        <select x-model="{{ $bind }}.agg"
                class="w-full rounded text-xs px-2 py-1.5"
                style="border:1px solid var(--line);background:var(--panel);">
            <option value="count">Count rows</option>
            <option value="count_if">Count where…</option>
            <option value="percent_if">% of rows where…</option>
            <option value="sum">Sum of column</option>
            <option value="avg">Average of column</option>
            <option value="min">Min of column</option>
            <option value="max">Max of column</option>
        </select>
    </div>

    <div class="col-span-6"
         x-show="['sum','avg','min','max'].includes({{ $bind }}.agg)"
         x-cloak>
        <label class="block text-[10px] mb-1" style="color:var(--ink-soft);">Value column</label>
        <select x-model="{{ $bind }}.column"
                class="w-full rounded text-xs px-2 py-1.5"
                style="border:1px solid var(--line);background:var(--panel);">
            <option value="">Select…</option>
            <template x-for="col in columnsFor({{ $bind }}.key)" :key="col">
                <option :value="col" x-text="col"></option>
            </template>
        </select>
    </div>

    {{-- PIPELINE / STAGE — cascading picker, only shown when the source has these columns --}}
    <template x-if="columnsFor({{ $bind }}.key).includes('Pipeline')">
        <div class="col-span-12 grid grid-cols-12 gap-2">
            <div class="col-span-6">
                <label class="block text-[10px] mb-1" style="color:var(--ink-soft);">Pipeline</label>
                <select :value="filterVal({{ $bind }}.filters, 'Pipeline')"
                        @change="setFilterVal({{ $bind }}.filters, 'Pipeline', $event.target.value); setFilterVal({{ $bind }}.filters, 'Stage', '')"
                        class="w-full rounded text-xs px-2 py-1.5"
                        style="border:1px solid var(--line);background:var(--panel);">
                    <option value="">— all pipelines —</option>
                    <template x-for="p in pipelineOptions({{ $bind }}.key)" :key="p">
                        <option :value="p" x-text="p"></option>
                    </template>
                </select>
            </div>
            <div class="col-span-6" x-show="filterVal({{ $bind }}.filters, 'Pipeline')" x-cloak>
                <label class="block text-[10px] mb-1" style="color:var(--ink-soft);">Stage (kanban card)</label>
                <select :value="filterVal({{ $bind }}.filters, 'Stage')"
                        @change="setFilterVal({{ $bind }}.filters, 'Stage', $event.target.value)"
                        class="w-full rounded text-xs px-2 py-1.5"
                        style="border:1px solid var(--line);background:var(--panel);">
                    <option value="">— all stages —</option>
                    <template x-for="st in stageOptions({{ $bind }})" :key="st">
                        <option :value="st" x-text="st"></option>
                    </template>
                </select>
            </div>
        </div>
    </template>

    {{-- FILTER — optional row condition, shared by every measure --}}
    <div class="col-span-4">
        <label class="block text-[10px] mb-1" style="color:var(--ink-soft);">Filter column (optional)</label>
        <select x-model="{{ $bind }}.filter_column"
                class="w-full rounded text-xs px-2 py-1.5"
                style="border:1px solid var(--line);background:var(--panel);">
            <option value="">— no filter —</option>
            <template x-for="col in columnsFor({{ $bind }}.key)" :key="col">
                <option :value="col" x-text="col"></option>
            </template>
        </select>
    </div>

    <div class="col-span-4" x-show="{{ $bind }}.filter_column" x-cloak>
        <label class="block text-[10px] mb-1" style="color:var(--ink-soft);">Condition</label>
        <select x-model="{{ $bind }}.filter_operator"
                class="w-full rounded text-xs px-2 py-1.5"
                style="border:1px solid var(--line);background:var(--panel);">
            <option value="eq">equals</option>
            <option value="neq">does not equal</option>
            <option value="contains">contains</option>
            <option value="not_contains">does not contain</option>
            <option value="gt">greater than</option>
            <option value="lt">less than</option>
            <option value="not_empty">is not empty</option>
            <option value="empty">is empty</option>
        </select>
    </div>

    <div class="col-span-4"
         x-show="{{ $bind }}.filter_column && !['not_empty','empty'].includes({{ $bind }}.filter_operator)"
         x-cloak>
        <label class="block text-[10px] mb-1" style="color:var(--ink-soft);">Value</label>
        <input x-model="{{ $bind }}.filter_value" placeholder="e.g. Booked"
               class="w-full rounded text-xs px-2 py-1.5"
               style="border:1px solid var(--line);background:var(--panel);">
    </div>
</div>
