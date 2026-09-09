{{--
    resources/views/admin/partials/metric-source.blade.php

    A reusable "where does this number come from" editor.
    $bind = the Alpine object path holding { key, agg, column, filter_* }.
    `key` is the composite integration+dataset source (integration_id::dataset).
    Used for a simple metric (bind="metric.simple") and per formula variable.
--}}
<div class="">
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
                {{-- Options load async; re-apply the saved value after they render
                     (and whenever they change) so edits show the right pipeline. --}}
                <select x-effect="pipelineOptions({{ $bind }}.key); $nextTick(() => $el.value = filterVal({{ $bind }}.filters, 'Pipeline'))"
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
                <select x-effect="stageOptions({{ $bind }}); $nextTick(() => $el.value = filterVal({{ $bind }}.filters, 'Stage'))"
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
                @change="window.filterOnOperatorChange({{ $bind }}, 'filter_value', 'filter_operator')"
                class="w-full rounded text-xs px-2 py-1.5"
                style="border:1px solid var(--line);background:var(--panel);">
            @include('admin.partials.filter-operator-options')
        </select>
    </div>

    <div class="col-span-4"
         x-show="{{ $bind }}.filter_column && window.filterInput({{ $bind }}.filter_operator) !== 'none'"
         x-cloak>
        <label class="block text-[10px] mb-1" style="color:var(--ink-soft);">Value</label>
        @include('admin.partials.filter-value-input', ['filterObj' => $bind, 'filterValueKey' => 'filter_value', 'filterOpKey' => 'filter_operator', 'filterColumnKey' => 'filter_column', 'filterSourceKey' => $bind.'.key'])
        <p class="mt-1 text-[10px] leading-tight" style="color:var(--ink-soft);"
           x-show="String({{ $bind }}.filter_operator).startsWith('date_')" x-cloak>
            Relative windows are measured against today each time the metric is read, so
            “in the last N days” still means the last N days tomorrow.
        </p>
        <p class="mt-1 text-[10px] leading-tight" style="color:var(--ink-soft);"
           x-show="['has_all','has_any','not_has_any'].includes({{ $bind }}.filter_operator)" x-cloak>
            For multi-value fields (e.g. Outreach Stages). Separate values with commas —
            <strong>has all</strong> matches rows containing every value, <strong>has any</strong> matches at least one.
        </p>
    </div>

    {{-- EXTRA CONDITIONS — any number of {column, operator, value} rules, all
         ANDed with the filter above and with the Pipeline/Stage picker. This is
         what lets one metric say "Owner = <SDR> AND Outreach Stages has_any
         1st Call". Reserved Pipeline/Stage rows (owned by the picker above) are
         hidden here so the two editors don't fight over the same list. --}}
    <div class="col-span-12 mt-1">
        <div class="flex items-center justify-between mb-1">
            <label class="block text-[10px]" style="color:var(--ink-soft);">Extra conditions (all must match)</label>
            <button type="button"
                    @click="({{ $bind }}.filters = {{ $bind }}.filters || []).push({ column: '', operator: 'eq', value: '' })"
                    class="text-[10px] px-2 py-1 rounded-md font-medium"
                    style="border:1px solid var(--line);background:var(--panel);">+ Add condition</button>
        </div>

        <template x-for="(cond, ci) in ({{ $bind }}.filters || [])" :key="ci">
            <div class="grid grid-cols-12 gap-2 items-end mb-1.5"
                 x-show="!['Pipeline','Stage'].includes(cond.column)" x-cloak>
                <div class="col-span-4">
                    <select x-model="cond.column" class="w-full rounded text-xs px-2 py-1.5"
                            style="border:1px solid var(--line);background:var(--panel);">
                        <option value="">— column —</option>
                        <template x-for="col in columnsFor({{ $bind }}.key)" :key="col">
                            <option :value="col" x-text="col"></option>
                        </template>
                    </select>
                </div>
                <div class="col-span-4">
                    <select x-model="cond.operator"
                            @change="window.filterOnOperatorChange(cond, 'value', 'operator')"
                            class="w-full rounded text-xs px-2 py-1.5"
                            style="border:1px solid var(--line);background:var(--panel);">
                        @include('admin.partials.filter-operator-options')
                    </select>
                </div>
                <div class="col-span-3" x-show="window.filterInput(cond.operator) !== 'none'" x-cloak>
                    @include('admin.partials.filter-value-input', ['filterObj' => 'cond', 'filterSourceKey' => $bind.'.key'])
                </div>
                <div class="col-span-1">
                    <button type="button" @click="{{ $bind }}.filters.splice(ci, 1)"
                            class="text-sm" style="color:var(--coral);">✕</button>
                </div>
            </div>
        </template>
    </div>
</div>
