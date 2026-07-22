{{-- resources/views/admin/dashboard.blade.php --}}
<x-app-layout :title="$dashboard?->name ?? 'Dashboard'">
    @push('head')
        <script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.1/dist/chart.umd.min.js"></script>
    @endpush

    @if (! $dashboard)
        {{-- No dashboard yet — usually means nothing is connected. --}}
        <div class="px-6 lg:px-8 py-16 text-center">
            <h1 class="display text-2xl font-bold mb-2">No dashboard yet</h1>
            <p class="text-sm mb-6" style="color:var(--ink-soft);">
                Connect an integration to start syncing data, then build your first dashboard.
            </p>
            <div class="flex items-center justify-center gap-2">
                <a href="{{ route('admin.integrations.index') }}"
                   class="inline-block px-5 py-2.5 rounded-lg text-sm font-semibold text-white"
                   style="background:var(--mint-deep);">Connect an integration</a>
                <form method="POST" action="{{ route('admin.dashboards.store') }}"
                      onsubmit="const n=prompt('Name for the new dashboard:'); if(!n){return false;} this.name.value=n;">
                    @csrf
                    <input type="hidden" name="name">
                    <button class="px-5 py-2.5 rounded-lg text-sm font-medium" style="border:1px solid var(--line);background:var(--panel);">
                        Create a dashboard
                    </button>
                </form>
            </div>
        </div>
    @else
    <div x-data="dashboard(@js([
            'schema'       => $schema,
            'dashboardId'  => $dashboard->id,
            'chartData'    => route('admin.dashboards.charts.data', $dashboard->slug),
            'metricsIndex' => route('admin.dashboards.metrics.index', $dashboard->slug),
            'chartsStore'  => route('admin.charts.store', $dashboard->id),
            'metricsStore' => route('admin.metrics.store', $dashboard->id),
            'metricsPreview' => route('admin.metrics.preview'),
            'tableData'    => route('admin.table.data'),
            'distinctData' => route('admin.table.distinct'),
        ]))" x-init="boot()" class="px-6 lg:px-8 py-8">

        <div class="flex flex-wrap items-end justify-between gap-4 mb-7">
            <div>
                <h1 class="display text-2xl font-bold">{{ $dashboard->name }}</h1>
                <p class="text-sm" style="color:var(--ink-soft);">
                    Charts and metrics built from your synced integration data.
                </p>
            </div>

            <div class="flex gap-2">
                <form method="POST" action="{{ route('admin.dashboards.store') }}"
                      onsubmit="const n=prompt('Name for the new dashboard:'); if(!n){return false;} this.name.value=n;">
                    @csrf
                    <input type="hidden" name="name">
                    <button class="px-4 py-2 rounded-lg text-sm font-medium" style="border:1px solid var(--line);background:var(--panel);">
                        + New dashboard
                    </button>
                </form>
                <button @click="openBuilder()" :disabled="!sources.length"
                        class="px-4 py-2 rounded-lg text-sm font-semibold text-white disabled:opacity-40"
                        style="background:var(--mint-deep);">
                    + New chart
                </button>
            </div>
        </div>

        <template x-if="!sources.length && !loading">
            <div class="rounded-xl px-4 py-3 text-sm mb-6"
                 style="background:rgba(238,159,78,0.12);border:1px solid var(--amber);color:#7A4A12;">
                No synced data yet — connect and sync an integration in
                <a href="{{ route('admin.integrations.index') }}" class="underline font-semibold">Integrations</a>.
            </div>
        </template>

        {{-- ============================ METRICS ============================ --}}
        <div class="flex items-center justify-between mb-3">
            <span class="text-[10.5px] font-bold uppercase tracking-[1.5px]" style="color:var(--mint-deep);">Metrics</span>
            <button @click="openMetric()" :disabled="!sources.length"
                    class="text-xs px-3 py-1.5 rounded-md font-medium disabled:opacity-40"
                    style="border:1px solid var(--line);background:var(--panel);">+ New metric</button>
        </div>

        <div class="grid grid-cols-2 md:grid-cols-3 xl:grid-cols-6 gap-3 mb-9">
            <template x-for="m in metrics" :key="m.id">
                <div class="rounded-xl p-4 relative group"
                     :style="m.accent ? 'background:var(--sidebar);border:1px solid var(--sidebar);' : 'background:var(--panel);border:1px solid var(--line);'">
                    <button @click="openMetric(m.id)"
                            class="absolute top-2.5 right-2.5 text-sm opacity-0 group-hover:opacity-60 transition"
                            :style="m.accent ? 'color:#fff;' : ''" title="Configure metric">⚙</button>
                    <div class="text-[11px] uppercase tracking-wide mb-2.5"
                         :style="m.accent ? 'color:#8FAFA3;' : 'color:var(--ink-soft);'" x-text="m.title"></div>
                    <div class="display text-[30px] font-bold leading-none"
                         :style="m.accent ? 'color:#fff;' : ''" x-text="m.display"></div>
                    <div class="text-[11.5px] mt-2 font-semibold"
                         :style="m.error ? 'color:var(--coral);' : (m.accent ? 'color:var(--mint);' : 'color:var(--mint-deep);')"
                         x-text="m.error || m.subtitle || ''"></div>
                </div>
            </template>

            <template x-if="!metrics.length && !loading && sources.length">
                <div class="col-span-full text-center text-xs py-8 rounded-xl"
                     style="border:1px dashed var(--line);color:var(--ink-soft);">
                    No metrics yet. Add counts, sums, or formulas like
                    <span class="mono" style="color:var(--mint-deep);">{won} / {messaged} * 100</span>.
                </div>
            </template>
        </div>

        {{-- ============================ CHARTS ============================ --}}
        <div class="grid grid-cols-1 xl:grid-cols-2 gap-4">
            <template x-for="c in charts" :key="c.id">
                <div class="rounded-xl p-5" style="background:var(--panel);border:1px solid var(--line);">
                    <div class="flex items-start justify-between mb-3">
                        <div>
                            <h3 class="display text-[14.5px] font-semibold uppercase tracking-wide" x-text="c.title"></h3>
                            <p class="text-[11.5px] mt-0.5" style="color:var(--ink-soft);"
                               x-text="c.config.sheet + ' · grouped by ' + c.config.label_column"></p>
                        </div>
                        <button @click="openBuilder(c.id)"
                                class="text-lg leading-none transition hover:opacity-100 opacity-40"
                                title="Configure chart">⚙</button>
                    </div>
                    <div class="h-64"><canvas :id="'cv-' + c.id"></canvas></div>
                </div>
            </template>

            <template x-if="!charts.length && !loading && sources.length">
                <div class="col-span-full text-center text-sm py-20 rounded-xl"
                     style="border:1px dashed var(--line);color:var(--ink-soft);">
                    No charts yet. Click <b>+ New chart</b> to build one — series can even combine integrations.
                </div>
            </template>
        </div>

        {{-- ============================ TABLE ============================ --}}
        <template x-if="sources.length">
            <div class="rounded-xl p-5 mt-4" style="background:var(--panel);border:1px solid var(--line);">
                <div class="flex flex-wrap items-center justify-between gap-3 mb-4">
                    <div>
                        <h3 class="display text-[14.5px] font-semibold uppercase tracking-wide">Raw Rows</h3>
                        <p class="text-[11.5px] mt-0.5" style="color:var(--ink-soft);">
                            Pick any synced dataset and search across it.
                        </p>
                    </div>
                    <div class="flex gap-2">
                        <select x-model="tableKey" @change="loadTable()"
                                class="rounded-lg text-xs px-3 py-2"
                                style="border:1px solid var(--line);background:var(--panel-alt);">
                            <template x-for="s in sources" :key="s.key">
                                <option :value="s.key" x-text="s.label"></option>
                            </template>
                        </select>
                        <input x-model="tableQuery" placeholder="Search…"
                               class="rounded-lg text-xs px-3 py-2 w-48"
                               style="border:1px solid var(--line);background:var(--panel-alt);">
                    </div>
                </div>

                <div class="overflow-auto max-h-[460px] rounded-lg" style="border:1px solid var(--line);">
                    <table class="w-full text-xs">
                        <thead class="sticky top-0" style="background:var(--panel);">
                            <tr>
                                <template x-for="col in tableColumns" :key="col">
                                    <th class="text-left px-3 py-2.5 font-semibold uppercase text-[10px] tracking-wide whitespace-nowrap"
                                        style="color:var(--ink-soft);border-bottom:1px solid var(--line);" x-text="col"></th>
                                </template>
                            </tr>
                        </thead>
                        <tbody>
                            <template x-for="(row, i) in visibleRows" :key="i">
                                <tr class="hover:opacity-80" style="border-top:1px solid var(--panel-alt);">
                                    <template x-for="col in tableColumns" :key="col">
                                        <td class="px-3 py-2.5 align-middle max-w-[240px] truncate"
                                            :title="row[col]" x-text="row[col]"></td>
                                    </template>
                                </tr>
                            </template>
                            <template x-if="!visibleRows.length">
                                <tr><td :colspan="tableColumns.length || 1"
                                        class="text-center py-8 text-xs" style="color:var(--ink-soft);">No rows match.</td></tr>
                            </template>
                        </tbody>
                    </table>
                </div>

                <p class="mono text-[11px] mt-2.5" style="color:var(--ink-soft);"
                   x-text="visibleRows.length + ' of ' + tableRows.length + ' rows'"></p>
            </div>
        </template>

        {{-- ============================ CHART BUILDER MODAL ============================ --}}
        <div x-show="builder.open" x-cloak
             class="fixed inset-0 flex items-center justify-center p-4 z-50"
             style="background:rgba(14,33,29,0.55);"
             @click.self="builder.open = false" @keydown.escape.window="builder.open = false">
            <div class="rounded-2xl p-6 w-full max-w-2xl max-h-[90vh] overflow-auto" style="background:var(--panel);">
                <h3 class="display text-lg font-bold mb-5" x-text="builder.id ? 'Configure chart' : 'New chart'"></h3>

                <div class="grid grid-cols-2 gap-4 mb-5">
                    <div class="col-span-2">
                        <label class="block text-xs font-semibold mb-1.5">Title</label>
                        <input x-model="builder.title" placeholder="e.g. Leads by Source"
                               class="w-full rounded-lg text-sm px-3 py-2"
                               style="border:1px solid var(--line);background:var(--panel-alt);">
                    </div>

                    <div>
                        <label class="block text-xs font-semibold mb-1.5">Chart type</label>
                        <select x-model="builder.type" class="w-full rounded-lg text-sm px-3 py-2"
                                style="border:1px solid var(--line);background:var(--panel-alt);">
                            <optgroup label="Bar">
                                <option value="bar">Bar</option>
                                <option value="horizontalBar">Bar (horizontal)</option>
                                <option value="stackedBar">Bar (stacked)</option>
                            </optgroup>
                            <optgroup label="Line">
                                <option value="line">Line</option>
                                <option value="area">Area</option>
                            </optgroup>
                            <optgroup label="Circular">
                                <option value="pie">Pie</option>
                                <option value="doughnut">Doughnut</option>
                                <option value="polarArea">Polar area</option>
                                <option value="radar">Radar</option>
                            </optgroup>
                            <optgroup label="Distribution">
                                <option value="scatter">Scatter</option>
                                <option value="bubble">Bubble</option>
                            </optgroup>
                        </select>
                    </div>

                    <div>
                        <label class="block text-xs font-semibold mb-1.5">Rows shown</label>
                        <input type="number" min="1" max="50" x-model.number="builder.limit"
                               class="w-full rounded-lg text-sm px-3 py-2"
                               style="border:1px solid var(--line);background:var(--panel-alt);">
                    </div>

                    <div>
                        <label class="block text-xs font-semibold mb-1.5">Group-by source</label>
                        <select x-model="builder.key" @change="builder.label_column = ''; builder.filters = []"
                                class="w-full rounded-lg text-sm px-3 py-2"
                                style="border:1px solid var(--line);background:var(--panel-alt);">
                            <template x-for="s in sources" :key="s.key">
                                <option :value="s.key" x-text="s.label"></option>
                            </template>
                        </select>
                    </div>

                    <div>
                        <label class="block text-xs font-semibold mb-1.5">Group-by column (X axis)</label>
                        <select x-model="builder.label_column"
                                class="w-full rounded-lg text-sm px-3 py-2"
                                style="border:1px solid var(--line);background:var(--panel-alt);">
                            <option value="">Select…</option>
                            <template x-for="col in columnsFor(builder.key)" :key="col">
                                <option :value="col" x-text="col"></option>
                            </template>
                        </select>
                    </div>

                    {{-- Pipeline → Stage cascading picker — scopes the whole chart to one
                         pipeline (and optionally one kanban stage) when the source has them. --}}
                    <template x-if="columnsFor(builder.key).includes('Pipeline')">
                        <div class="col-span-2 grid grid-cols-2 gap-4 p-3 rounded-lg" style="background:var(--panel-alt);">
                            <div>
                                <label class="block text-xs font-semibold mb-1.5">Pipeline</label>
                                <select :value="filterVal(builder.filters, 'Pipeline')"
                                        @change="setFilterVal(builder.filters, 'Pipeline', $event.target.value); setFilterVal(builder.filters, 'Stage', '')"
                                        class="w-full rounded-lg text-sm px-3 py-2"
                                        style="border:1px solid var(--line);background:var(--panel);">
                                    <option value="">— all pipelines —</option>
                                    <template x-for="p in pipelineOptions(builder.key)" :key="p">
                                        <option :value="p" x-text="p"></option>
                                    </template>
                                </select>
                            </div>
                            <div x-show="filterVal(builder.filters, 'Pipeline')" x-cloak>
                                <label class="block text-xs font-semibold mb-1.5">Stage (kanban card)</label>
                                <select :value="filterVal(builder.filters, 'Stage')"
                                        @change="setFilterVal(builder.filters, 'Stage', $event.target.value)"
                                        class="w-full rounded-lg text-sm px-3 py-2"
                                        style="border:1px solid var(--line);background:var(--panel);">
                                    <option value="">— all stages —</option>
                                    <template x-for="st in stageOptions(builder)" :key="st">
                                        <option :value="st" x-text="st"></option>
                                    </template>
                                </select>
                            </div>
                        </div>
                    </template>
                </div>

                {{-- SERIES --}}
                <div class="pt-5" style="border-top:1px solid var(--line);">
                    <div class="flex items-center justify-between mb-3">
                        <div>
                            <span class="text-[10.5px] font-bold uppercase tracking-[1.5px]" style="color:var(--ink-soft);">Series (Y axis)</span>
                            <p class="text-[11px] mt-0.5" style="color:var(--ink-soft);">Each series can pull from a different integration.</p>
                        </div>
                        <button @click="addSeries()" class="text-xs px-3 py-1.5 rounded-md font-medium"
                                style="border:1px solid var(--line);">+ Add series</button>
                    </div>

                    <template x-for="(s, i) in builder.series" :key="i">
                        <div class="grid grid-cols-12 gap-2 items-end mb-2 p-3 rounded-lg" style="background:var(--panel-alt);">
                            <div class="col-span-3">
                                <label class="block text-[10px] mb-1" style="color:var(--ink-soft);">Source</label>
                                <select x-model="s.key" @change="s.column = ''"
                                        class="w-full rounded text-xs px-2 py-1.5"
                                        style="border:1px solid var(--line);background:var(--panel);">
                                    <template x-for="src in sources" :key="src.key">
                                        <option :value="src.key" x-text="src.label"></option>
                                    </template>
                                </select>
                            </div>
                            <div class="col-span-3">
                                <label class="block text-[10px] mb-1" style="color:var(--ink-soft);">Value column</label>
                                <select x-model="s.column" class="w-full rounded text-xs px-2 py-1.5"
                                        style="border:1px solid var(--line);background:var(--panel);">
                                    <option value="">— row count —</option>
                                    <template x-for="col in columnsFor(s.key)" :key="col">
                                        <option :value="col" x-text="col"></option>
                                    </template>
                                </select>
                            </div>
                            <div class="col-span-2">
                                <label class="block text-[10px] mb-1" style="color:var(--ink-soft);">Aggregate</label>
                                <select x-model="s.agg" class="w-full rounded text-xs px-2 py-1.5"
                                        style="border:1px solid var(--line);background:var(--panel);">
                                    <option value="count">Count</option>
                                    <option value="sum">Sum</option>
                                    <option value="avg">Average</option>
                                    <option value="min">Min</option>
                                    <option value="max">Max</option>
                                </select>
                            </div>
                            <div class="col-span-3">
                                <label class="block text-[10px] mb-1" style="color:var(--ink-soft);">Label</label>
                                <input x-model="s.label" class="w-full rounded text-xs px-2 py-1.5"
                                       style="border:1px solid var(--line);background:var(--panel);">
                            </div>
                            <div class="col-span-1">
                                <button @click="builder.series.splice(i, 1)" x-show="builder.series.length > 1"
                                        class="w-full text-sm py-1.5" style="color:var(--coral);">✕</button>
                            </div>
                        </div>
                    </template>
                </div>

                <p x-show="builder.error" x-cloak x-text="builder.error" class="text-xs mt-4" style="color:var(--coral);"></p>

                <div class="flex items-center justify-between mt-6 gap-3">
                    <button x-show="builder.id" x-cloak @click="destroyChart()" class="text-xs font-semibold" style="color:var(--coral);">Delete chart</button>
                    <div class="flex gap-2 ml-auto">
                        <button @click="builder.open = false" class="px-4 py-2 rounded-lg text-sm font-medium" style="border:1px solid var(--line);">Cancel</button>
                        <button @click="saveChart()" :disabled="builder.saving"
                                class="px-5 py-2 rounded-lg text-sm font-semibold text-white disabled:opacity-50" style="background:var(--mint-deep);">
                            <span x-text="builder.saving ? 'Saving…' : 'Save'"></span>
                        </button>
                    </div>
                </div>
            </div>
        </div>

        {{-- ============================ METRIC BUILDER MODAL ============================ --}}
        <div x-show="metric.open" x-cloak
             class="fixed inset-0 flex items-center justify-center p-4 z-50"
             style="background:rgba(14,33,29,0.55);"
             @click.self="metric.open = false" @keydown.escape.window="metric.open = false">
            <div class="rounded-2xl p-6 w-full max-w-2xl max-h-[90vh] overflow-auto" style="background:var(--panel);">
                <h3 class="display text-lg font-bold mb-5" x-text="metric.id ? 'Configure metric' : 'New metric'"></h3>

                <div class="grid grid-cols-2 gap-4 mb-5">
                    <div>
                        <label class="block text-xs font-semibold mb-1.5">Title</label>
                        <input x-model="metric.title" placeholder="Conversion Rate"
                               class="w-full rounded-lg text-sm px-3 py-2" style="border:1px solid var(--line);background:var(--panel-alt);">
                    </div>
                    <div>
                        <label class="block text-xs font-semibold mb-1.5">Subtitle (optional)</label>
                        <input x-model="metric.subtitle" placeholder="won ÷ messaged"
                               class="w-full rounded-lg text-sm px-3 py-2" style="border:1px solid var(--line);background:var(--panel-alt);">
                    </div>
                </div>

                <div class="flex gap-2 mb-5">
                    <button @click="metric.mode = 'simple'" class="px-4 py-2 rounded-lg text-xs font-semibold"
                            :style="metric.mode === 'simple' ? 'background:var(--mint-deep);color:#fff;' : 'border:1px solid var(--line);'">Simple count / sum</button>
                    <button @click="metric.mode = 'formula'; ensureVariables()" class="px-4 py-2 rounded-lg text-xs font-semibold"
                            :style="metric.mode === 'formula' ? 'background:var(--mint-deep);color:#fff;' : 'border:1px solid var(--line);'">Formula</button>
                </div>

                <div x-show="metric.mode === 'simple'" class="p-4 rounded-lg mb-5" style="background:var(--panel-alt);">
                    @include('admin.partials.metric-source', ['bind' => 'metric.simple'])
                </div>

                <div x-show="metric.mode === 'formula'" x-cloak class="mb-5">
                    <div class="flex items-center justify-between mb-2">
                        <div>
                            <span class="text-[10.5px] font-bold uppercase tracking-[1.5px]" style="color:var(--ink-soft);">Variables</span>
                            <p class="text-[11px] mt-0.5" style="color:var(--ink-soft);">
                                Each becomes a number you can reference as
                                <span class="mono" style="color:var(--mint-deep);">{name}</span>.
                            </p>
                        </div>
                        <button @click="addVariable()" class="text-xs px-3 py-1.5 rounded-md font-medium" style="border:1px solid var(--line);">+ Add variable</button>
                    </div>

                    <template x-for="(v, i) in metric.varList" :key="i">
                        <div class="p-4 rounded-lg mb-2" style="background:var(--panel-alt);">
                            <div class="flex items-center justify-between mb-3">
                                <div class="flex items-center gap-2">
                                    <span class="text-[11px] font-semibold" style="color:var(--ink-soft);">Name</span>
                                    <input x-model="v.name" placeholder="messaged" class="mono rounded text-xs px-2 py-1 w-32"
                                           style="border:1px solid var(--line);background:var(--panel);">
                                    <span class="mono text-[11px]" style="color:var(--mint-deep);" x-text="'{' + (v.name || '…') + '}'"></span>
                                </div>
                                <button @click="metric.varList.splice(i, 1)" x-show="metric.varList.length > 1" class="text-sm" style="color:var(--coral);">✕</button>
                            </div>
                            @include('admin.partials.metric-source', ['bind' => 'v'])
                        </div>
                    </template>

                    <div class="mt-4">
                        <label class="block text-xs font-semibold mb-1.5">Expression</label>
                        <input x-model="metric.expression" placeholder="{won} / {messaged} * 100"
                               class="mono w-full rounded-lg text-sm px-3 py-2.5" style="border:1px solid var(--line);background:var(--panel-alt);">
                        <p class="text-[11px] mt-1.5" style="color:var(--ink-soft);">
                            Only numbers and <span class="mono">+ − × ÷ ( )</span> are allowed. Division by zero returns 0.
                        </p>
                    </div>
                </div>

                <div class="grid grid-cols-3 gap-4 pt-5" style="border-top:1px solid var(--line);">
                    <div>
                        <label class="block text-xs font-semibold mb-1.5">Format</label>
                        <select x-model="metric.format" class="w-full rounded-lg text-sm px-3 py-2" style="border:1px solid var(--line);background:var(--panel-alt);">
                            <option value="number">Number</option>
                            <option value="percent">Percent (%)</option>
                            <option value="currency">Currency ($)</option>
                        </select>
                    </div>
                    <div>
                        <label class="block text-xs font-semibold mb-1.5">Decimals</label>
                        <input type="number" min="0" max="4" x-model.number="metric.decimals"
                               class="w-full rounded-lg text-sm px-3 py-2" style="border:1px solid var(--line);background:var(--panel-alt);">
                    </div>
                    <div class="flex items-end">
                        <label class="flex items-center gap-2 text-xs font-semibold pb-2.5">
                            <input type="checkbox" x-model="metric.accent" class="rounded"> Dark accent card
                        </label>
                    </div>
                </div>

                <div class="flex items-center justify-between mt-5 p-4 rounded-lg" style="background:var(--panel-alt);">
                    <div>
                        <span class="text-[10.5px] uppercase tracking-wide" style="color:var(--ink-soft);">Live preview</span>
                        <div class="display text-2xl font-bold mt-1"
                             :style="metric.previewError ? 'color:var(--coral);font-size:14px;' : ''"
                             x-text="metric.previewError || metric.preview"></div>
                    </div>
                    <button @click="previewMetric()" :disabled="metric.previewing"
                            class="text-xs px-3 py-2 rounded-md font-medium disabled:opacity-50" style="border:1px solid var(--line);background:var(--panel);">
                        <span x-text="metric.previewing ? 'Calculating…' : '↻ Test'"></span>
                    </button>
                </div>

                <p x-show="metric.error" x-cloak x-text="metric.error" class="text-xs mt-4" style="color:var(--coral);"></p>

                <div class="flex items-center justify-between mt-6 gap-3">
                    <button x-show="metric.id" x-cloak @click="destroyMetric()" class="text-xs font-semibold" style="color:var(--coral);">Delete metric</button>
                    <div class="flex gap-2 ml-auto">
                        <button @click="metric.open = false" class="px-4 py-2 rounded-lg text-sm font-medium" style="border:1px solid var(--line);">Cancel</button>
                        <button @click="saveMetric()" :disabled="metric.saving"
                                class="px-5 py-2 rounded-lg text-sm font-semibold text-white disabled:opacity-50" style="background:var(--mint-deep);">
                            <span x-text="metric.saving ? 'Saving…' : 'Save'"></span>
                        </button>
                    </div>
                </div>
            </div>
        </div>
    </div>

    @push('scripts')
    <script>
    function dashboard(cfg) {
        return {
            cfg,
            // Flatten the schema into selectable sources (integration + dataset).
            sources: cfg.schema.map(s => ({
                key: s.integration_id + '::' + s.dataset,
                integration_id: s.integration_id,
                dataset: s.dataset,
                label: s.integration + ' · ' + s.dataset,
                columns: s.columns || [],
            })),

            charts: [],
            metrics: [],
            instances: {},
            loading: true,

            tableKey: '',
            tableColumns: [],
            tableRows: [],
            tableQuery: '',

            builder: { open: false, id: null, title: '', type: 'bar', key: '', label_column: '', limit: 10, series: [], filters: [], error: '', saving: false },
            metric: { open: false, id: null, title: '', subtitle: '', mode: 'simple', format: 'number', decimals: 0, accent: false, simple: {}, varList: [], expression: '', preview: '—', previewError: '', previewing: false, error: '', saving: false },

            // Pipeline/Stage cascading picker: distinct values are fetched once
            // per source (or per source+pipeline for stages) and cached here.
            distinctCache: {},

            get csrf() { return document.querySelector('meta[name=csrf-token]').content; },

            get firstKey() { return this.sources[0]?.key || ''; },

            columnsFor(key) { return this.sources.find(s => s.key === key)?.columns || []; },

            splitKey(key) {
                const [integration_id, ...rest] = String(key).split('::');
                return { integration_id: Number(integration_id), dataset: rest.join('::') };
            },

            get visibleRows() {
                if (!this.tableQuery) return this.tableRows.slice(0, 300);
                const q = this.tableQuery.toLowerCase();
                return this.tableRows.filter(r => Object.values(r).some(v => String(v).toLowerCase().includes(q))).slice(0, 300);
            },

            async boot() {
                if (!this.sources.length) { this.loading = false; return; }
                this.tableKey = this.firstKey;
                await Promise.all([this.loadCharts(), this.loadMetrics(), this.loadTable()]);
                this.loading = false;
            },

            blankSource() {
                return { key: this.firstKey, agg: 'count', column: '', filter_column: '', filter_operator: 'eq', filter_value: '', filters: [] };
            },

            /* ---------- Pipeline/Stage cascading picker ---------- */
            filterVal(filters, column) {
                const f = (filters || []).find(f => f.column === column);
                return f ? f.value : '';
            },

            setFilterVal(filters, column, value, operator = 'eq') {
                const i = filters.findIndex(f => f.column === column);
                if (value === '' || value == null) {
                    if (i !== -1) filters.splice(i, 1);
                    return;
                }
                if (i !== -1) { filters[i].value = value; filters[i].operator = operator; }
                else filters.push({ column, operator, value });
            },

            async fetchDistinct(key, column, scopes = []) {
                if (!key) return [];
                const { integration_id, dataset } = this.splitKey(key);
                const url = new URL(this.cfg.distinctData, window.location.origin);
                url.searchParams.set('integration_id', integration_id);
                url.searchParams.set('dataset', dataset);
                url.searchParams.set('column', column);
                if (scopes.length) url.searchParams.set('filters', JSON.stringify(scopes));

                const res = await fetch(url, { headers: { Accept: 'application/json' } });
                return res.ok ? res.json() : [];
            },

            // Options come from the "Pipeline Stages" catalogue dataset (every
            // pipeline/stage GHL has, synced independent of opportunity counts),
            // not from the Opportunities rows themselves — so empty pipelines
            // and empty stages still show up, not just ones with synced rows.
            catalogKey(key) {
                return this.splitKey(key).integration_id + '::Pipeline Stages';
            },

            pipelineOptions(key) {
                const catalogKey = this.catalogKey(key);
                const cacheKey = catalogKey + '::Pipeline';
                if (!(cacheKey in this.distinctCache)) {
                    this.distinctCache[cacheKey] = [];
                    this.fetchDistinct(catalogKey, 'Pipeline').then(vals => this.distinctCache[cacheKey] = vals);
                }
                return this.distinctCache[cacheKey];
            },

            stageOptions(bind) {
                const pipeline = this.filterVal(bind.filters, 'Pipeline');
                if (!pipeline) return [];

                const catalogKey = this.catalogKey(bind.key);
                const cacheKey = catalogKey + '::Stage::' + pipeline;
                if (!(cacheKey in this.distinctCache)) {
                    this.distinctCache[cacheKey] = [];
                    this.fetchDistinct(catalogKey, 'Stage', [{ column: 'Pipeline', value: pipeline }]).then(vals => this.distinctCache[cacheKey] = vals);
                }
                return this.distinctCache[cacheKey];
            },

            /* ---------- charts ---------- */
            async loadCharts() {
                const res = await fetch(this.cfg.chartData, { headers: { Accept: 'application/json' } });
                this.charts = await res.json();
                await this.$nextTick();
                this.charts.forEach(c => this.draw(c));
            },

            draw(c) {
                const el = document.getElementById('cv-' + c.id);
                if (!el) return;
                this.instances[c.id]?.destroy();

                const round = ['pie', 'doughnut', 'polarArea', 'radar'].includes(c.type);
                const xy = ['scatter', 'bubble'].includes(c.type);
                let scales = {};
                if (!round) {
                    scales = {
                        x: { stacked: c.stacked, grid: { display: false }, ticks: { font: { size: 10 }, maxRotation: 40, autoSkip: true, callback: xy ? (v) => c.labels[v] ?? '' : undefined } },
                        y: { stacked: c.stacked, beginAtZero: true, grid: { color: '#EFEAD9' }, ticks: { font: { size: 10 } } },
                    };
                }

                this.instances[c.id] = new Chart(el, {
                    type: c.type,
                    data: { labels: xy ? undefined : c.labels, datasets: c.datasets },
                    options: {
                        responsive: true, maintainAspectRatio: false, indexAxis: c.indexAxis,
                        plugins: {
                            legend: { position: 'bottom', labels: { boxWidth: 10, font: { size: 11 } } },
                            tooltip: { intersect: false, mode: round || xy ? 'nearest' : 'index', callbacks: xy ? { label: (ctx) => `${c.labels[ctx.parsed.x] ?? ''}: ${ctx.parsed.y}` } : {} },
                        },
                        scales,
                    },
                });
            },

            openBuilder(id = null) {
                const c = id ? this.charts.find(x => x.id === id) : null;

                if (!c || !c.config) {
                    this.builder = {
                        open: true, id: null, title: '', type: 'bar', key: this.firstKey, label_column: '', limit: 10,
                        series: [{ key: this.firstKey, column: '', agg: 'count', label: 'Count' }],
                        filters: [], error: '', saving: false,
                    };
                    return;
                }

                const chartKey = c.config.integration_id + '::' + c.config.sheet;
                this.builder = {
                    open: true, id: c.id, title: c.title,
                    type: c.indexAxis === 'y' ? 'horizontalBar' : c.type,
                    key: chartKey, label_column: c.config.label_column, limit: c.config.limit,
                    series: (c.config.series || []).map(s => ({
                        key: (s.integration_id ?? c.config.integration_id) + '::' + (s.dataset ?? s.sheet ?? c.config.sheet),
                        column: s.column || '', agg: s.agg || 'count', label: s.label || 'Count', color: s.color,
                    })),
                    filters: c.config.filters || [],
                    error: '', saving: false,
                };
            },

            addSeries() {
                this.builder.series.push({ key: this.builder.key, column: '', agg: 'count', label: 'Series ' + (this.builder.series.length + 1) });
            },

            chartPayload() {
                const chart = this.splitKey(this.builder.key);
                return {
                    title: this.builder.title,
                    type: this.builder.type,
                    integration_id: chart.integration_id,
                    sheet: chart.dataset,
                    label_column: this.builder.label_column,
                    aggregate: this.builder.series[0]?.agg || 'count',
                    limit: this.builder.limit,
                    filters: this.builder.filters || [],
                    series: this.builder.series.map(s => {
                        const src = this.splitKey(s.key);
                        return { integration_id: src.integration_id, sheet: src.dataset, column: s.column, agg: s.agg, label: s.label, color: s.color };
                    }),
                };
            },

            async saveChart() {
                this.builder.error = '';
                if (!this.builder.title.trim()) { this.builder.error = 'Title is required.'; return; }
                if (!this.builder.label_column) { this.builder.error = 'Pick a group-by column.'; return; }

                this.builder.saving = true;
                const editing = Boolean(this.builder.id);
                const url = editing ? `/admin/charts/${this.builder.id}` : this.cfg.chartsStore;

                const res = await fetch(url, {
                    method: editing ? 'PUT' : 'POST',
                    headers: { 'Content-Type': 'application/json', Accept: 'application/json', 'X-CSRF-TOKEN': this.csrf },
                    body: JSON.stringify(this.chartPayload()),
                });

                this.builder.saving = false;
                if (!res.ok) { const err = await res.json().catch(() => ({})); this.builder.error = err.message || 'Could not save the chart.'; return; }

                this.builder.open = false;
                await this.loadCharts();
            },

            async destroyChart() {
                if (!confirm('Delete this chart?')) return;
                await fetch(`/admin/charts/${this.builder.id}`, { method: 'DELETE', headers: { 'X-CSRF-TOKEN': this.csrf } });
                this.instances[this.builder.id]?.destroy();
                delete this.instances[this.builder.id];
                this.builder.open = false;
                await this.loadCharts();
            },

            /* ---------- metrics ---------- */
            async loadMetrics() {
                const res = await fetch(this.cfg.metricsIndex, { headers: { Accept: 'application/json' } });
                this.metrics = await res.json();
            },

            openMetric(id = null) {
                const m = id ? this.metrics.find(x => x.id === id) : null;

                if (!m || !m.config) {
                    this.metric = {
                        open: true, id: null, title: '', subtitle: '', mode: 'simple', format: 'number', decimals: 0, accent: false,
                        simple: this.blankSource(), varList: [{ name: 'a', ...this.blankSource() }], expression: '',
                        preview: '—', previewError: '', previewing: false, error: '', saving: false,
                    };
                    return;
                }

                const cfg = m.config;
                const keyOf = (o) => (o.integration_id ?? cfg.integration_id) + '::' + (o.sheet || '');
                const varList = Object.entries(cfg.variables || {}).map(([name, v]) => ({
                    name, key: keyOf(v), agg: v.agg || 'count', column: v.column || '',
                    filter_column: v.filter_column || '', filter_operator: v.filter_operator || 'eq', filter_value: v.filter_value || '',
                    filters: v.filters || [],
                }));

                this.metric = {
                    open: true, id: m.id, title: cfg.title, subtitle: cfg.subtitle || '',
                    mode: cfg.mode, format: cfg.format, decimals: cfg.decimals, accent: Boolean(cfg.accent),
                    simple: { key: cfg.integration_id + '::' + (cfg.sheet || ''), agg: cfg.agg || 'count', column: cfg.column || '', filter_column: cfg.filter_column || '', filter_operator: cfg.filter_operator || 'eq', filter_value: cfg.filter_value || '', filters: cfg.filters || [] },
                    varList: varList.length ? varList : [{ name: 'a', ...this.blankSource() }],
                    expression: cfg.expression || '', preview: m.display, previewError: '', previewing: false, error: '', saving: false,
                };
            },

            ensureVariables() { if (!this.metric.varList.length) this.metric.varList = [{ name: 'a', ...this.blankSource() }]; },
            addVariable() { const next = String.fromCharCode(97 + this.metric.varList.length); this.metric.varList.push({ name: next, ...this.blankSource() }); },

            sourceConfig(o) {
                const src = this.splitKey(o.key);
                return { integration_id: src.integration_id, sheet: src.dataset, agg: o.agg, column: o.column, filter_column: o.filter_column, filter_operator: o.filter_operator, filter_value: o.filter_value, filters: o.filters || [] };
            },

            metricPayload() {
                const m = this.metric;
                const base = { title: m.title, subtitle: m.subtitle, mode: m.mode, format: m.format, decimals: m.decimals, accent: m.accent };

                if (m.mode === 'simple') {
                    const cfg = this.sourceConfig(m.simple);
                    return { ...base, integration_id: cfg.integration_id, ...cfg };
                }

                const variables = {};
                let integration_id = null;
                m.varList.forEach(v => {
                    if (!v.name) return;
                    const cfg = this.sourceConfig(v);
                    integration_id = integration_id ?? cfg.integration_id;
                    variables[v.name] = cfg;
                });

                return { ...base, integration_id, expression: m.expression, variables };
            },

            async previewMetric() {
                this.metric.previewing = true;
                this.metric.previewError = '';
                const res = await fetch(this.cfg.metricsPreview, {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json', Accept: 'application/json', 'X-CSRF-TOKEN': this.csrf },
                    body: JSON.stringify(this.metricPayload()),
                });
                this.metric.previewing = false;
                if (!res.ok) { const err = await res.json().catch(() => ({})); this.metric.previewError = err.message || 'Invalid configuration.'; return; }
                const json = await res.json();
                this.metric.preview = json.display;
                this.metric.previewError = json.error || '';
            },

            async saveMetric() {
                this.metric.error = '';
                if (!this.metric.title.trim()) { this.metric.error = 'Title is required.'; return; }
                if (this.metric.mode === 'formula' && !this.metric.expression.trim()) { this.metric.error = 'Enter a formula expression.'; return; }

                this.metric.saving = true;
                const editing = Boolean(this.metric.id);
                const url = editing ? `/admin/metrics/${this.metric.id}` : this.cfg.metricsStore;

                const res = await fetch(url, {
                    method: editing ? 'PUT' : 'POST',
                    headers: { 'Content-Type': 'application/json', Accept: 'application/json', 'X-CSRF-TOKEN': this.csrf },
                    body: JSON.stringify(this.metricPayload()),
                });

                this.metric.saving = false;
                if (!res.ok) { const err = await res.json().catch(() => ({})); this.metric.error = err.message || 'Could not save the metric.'; return; }

                this.metric.open = false;
                await this.loadMetrics();
            },

            async destroyMetric() {
                if (!confirm('Delete this metric?')) return;
                await fetch(`/admin/metrics/${this.metric.id}`, { method: 'DELETE', headers: { 'X-CSRF-TOKEN': this.csrf } });
                this.metric.open = false;
                await this.loadMetrics();
            },

            /* ---------- table ---------- */
            async loadTable() {
                if (!this.tableKey) return;
                const { integration_id, dataset } = this.splitKey(this.tableKey);
                const url = new URL(this.cfg.tableData, window.location.origin);
                url.searchParams.set('integration_id', integration_id);
                url.searchParams.set('dataset', dataset);

                const res = await fetch(url, { headers: { Accept: 'application/json' } });
                const json = await res.json();
                this.tableColumns = json.columns;
                this.tableRows = json.rows;
                this.tableQuery = '';
            },
        };
    }
    </script>
    @endpush
    @endif
</x-app-layout>
