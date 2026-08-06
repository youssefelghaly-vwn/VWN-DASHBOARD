{{-- resources/views/admin/dashboard.blade.php --}}
<x-app-layout :title="$dashboard?->name ?? 'Dashboard'">
    @push('head')
        <script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.1/dist/chart.umd.min.js"></script>
        <style>
            /* Chart cards live on a 6-column grid on large screens; each card's
               width (full/two-thirds/half/third) is a column span set via a
               data attribute so it works without a Tailwind rebuild. */
            .chart-grid { display:grid; grid-template-columns:1fr; gap:1rem; }
            .chart-card { grid-column: span 1; }
            @media (min-width:1024px) {
                .chart-grid { grid-template-columns:repeat(6,minmax(0,1fr)); }
                .chart-card[data-w="full"]      { grid-column: span 6; }
                .chart-card[data-w="twothirds"] { grid-column: span 4; }
                .chart-card[data-w="half"]      { grid-column: span 3; }
                .chart-card[data-w="third"]     { grid-column: span 2; }
            }
        </style>
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
            'sectionsIndex' => route('admin.dashboards.sections.index', $dashboard->slug),
            'sectionsStore' => route('admin.sections.store', $dashboard->id),
            'layoutSave'   => route('admin.dashboards.layout', $dashboard->id),
            'loopsIndex'   => route('admin.dashboards.loops.index', $dashboard->slug),
            'loopsStore'   => route('admin.loops.store', $dashboard->id),
            'syncStatusUrl' => route('admin.sync.status'),
            'syncAllUrl'   => route('admin.sync.all'),
            'syncStatus'   => $syncStatus ?? ['at' => null, 'human' => null, 'connected' => 0, 'total' => 0],
        ]))" x-init="boot()" class="px-6 lg:px-8 py-8">

        <div class="flex flex-wrap items-end justify-between gap-4 mb-7">
            <div>
                <h1 class="display text-2xl font-bold">{{ $dashboard->name }}</h1>
                <p class="text-sm" style="color:var(--ink-soft);">
                    Charts and metrics built from your synced integration data.
                </p>
                {{-- Freshness pill — auto-syncs every 5 minutes; label live-updates. --}}
                <div class="flex items-center gap-2 mt-2 text-[11.5px]" style="color:var(--ink-soft);">
                    <span class="inline-flex items-center gap-1.5">
                        <span class="inline-block w-1.5 h-1.5 rounded-full" style="background:var(--mint-deep);"></span>
                        Auto-syncs every 5 min
                    </span>
                    <span>·</span>
                    <span :title="sync.at ? new Date(sync.at).toLocaleString() : ''">
                        Last sync: <b x-text="sync.human || 'never'"></b>
                    </span>
                    <button @click="syncNow()" :disabled="sync.syncing"
                            class="ml-1 px-2 py-0.5 rounded-md text-[11px] font-medium disabled:opacity-50"
                            style="border:1px solid var(--line);background:var(--panel);">
                        <span x-text="sync.syncing ? 'Syncing…' : '⟳ Sync now'"></span>
                    </button>
                </div>
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

        {{-- ============================ LAYOUT (sections + widgets) ============================ --}}
        <div class="flex items-center justify-between mb-4">
            <span class="text-[10.5px] font-bold uppercase tracking-[1.5px]" style="color:var(--mint-deep);">Layout</span>
            <div class="flex gap-2">
                <button @click="addSection()" :disabled="!sources.length"
                        class="text-xs px-3 py-1.5 rounded-md font-medium disabled:opacity-40"
                        style="border:1px solid var(--line);background:var(--panel);">+ Add section</button>
                <button @click="openLoop()" :disabled="!sources.length"
                        class="text-xs px-3 py-1.5 rounded-md font-medium disabled:opacity-40"
                        style="border:1px solid var(--line);background:var(--panel);">⟳ Loop statistics</button>
                <button @click="openMetric()" :disabled="!sources.length"
                        class="text-xs px-3 py-1.5 rounded-md font-medium disabled:opacity-40"
                        style="border:1px solid var(--line);background:var(--panel);">+ New metric</button>
            </div>
        </div>

        {{-- Active loops — chips with refresh (re-expand for new values) + delete. --}}
        <div x-show="loops.length" x-cloak class="flex flex-wrap items-center gap-2 mb-5">
            <span class="text-[10px] uppercase tracking-wide" style="color:var(--ink-soft);">Loops:</span>
            <template x-for="lp in loops" :key="lp.id">
                <span class="inline-flex items-center gap-2 text-[11px] px-2.5 py-1 rounded-full"
                      style="border:1px solid var(--line);background:var(--panel);">
                    <span class="font-semibold" x-text="lp.name"></span>
                    <span style="color:var(--ink-soft);" x-text="'· ' + lp.column + ' (' + lp.values + ')'"></span>
                    <button @click="editLoop(lp)" title="Edit loop (templates apply to all)" class="hover:opacity-100 opacity-60">✎</button>
                    <button @click="refreshLoop(lp)" title="Refresh — pick up new values" class="hover:opacity-100 opacity-60">⟳</button>
                    <button @click="deleteLoop(lp)" title="Delete loop" class="hover:opacity-100 opacity-60" style="color:var(--coral);">✕</button>
                </span>
            </template>
        </div>

        {{-- One template renders every "group": the ungrouped bucket first, then
             each section and its sub-sections in order. Each group draws its own
             metrics grid + charts grid, filtered by section id. --}}
        <template x-for="g in orderedGroups()" :key="g.key">
            <div :class="(g.type === 'ungrouped' && !metricsIn(null).length && !chartsIn(null).length && (metrics.length || charts.length)) ? '' : 'mb-9'">
                {{-- Section header (hr + title + controls). Ungrouped has none. --}}
                <template x-if="g.type === 'section'">
                    <div class="flex items-center gap-3 mb-3" :class="g.level ? 'pl-5' : ''">
                        <span class="display font-semibold whitespace-nowrap"
                              :class="g.level ? 'text-[13px]' : 'text-base'"
                              :style="g.level ? 'color:var(--ink-soft);' : ''"
                              x-text="g.section.title"></span>
                        <div class="flex-1" style="height:1px;background:var(--line);"></div>
                        <div class="flex items-center gap-1.5 text-xs" style="color:var(--ink-soft);">
                            <button @click="moveSection(g.section, -1)" title="Move section up" class="hover:opacity-100 opacity-60">↑</button>
                            <button @click="moveSection(g.section, 1)" title="Move section down" class="hover:opacity-100 opacity-60">↓</button>
                            <button x-show="!g.level" @click="addSubSection(g.section.id)" title="Add sub-section"
                                    class="hover:opacity-100 opacity-60 font-medium">+ sub</button>
                            <button @click="renameSection(g.section)" title="Rename" class="hover:opacity-100 opacity-60">✎</button>
                            <button @click="deleteSection(g.section)" title="Delete section" class="hover:opacity-100 opacity-60" style="color:var(--coral);">🗑</button>
                        </div>
                    </div>
                </template>

                {{-- METRICS in this group --}}
                <div class="grid grid-cols-2 md:grid-cols-3 xl:grid-cols-6 gap-3 mb-4"
                     :class="g.level ? 'pl-5' : ''" x-show="metricsIn(g.sectionId).length">
                    <template x-for="m in metricsIn(g.sectionId)" :key="m.id">
                        <div class="rounded-xl p-4 relative group"
                             :style="m.accent ? 'background:var(--sidebar);border:1px solid var(--sidebar);' : 'background:var(--panel);border:1px solid var(--line);'">
                            <div class="absolute top-2 right-2 flex items-center gap-0.5 opacity-0 group-hover:opacity-70 transition text-sm">
                                <button @click="moveWidget('metric', m.id, -1)" :style="m.accent ? 'color:#fff;' : ''" title="Move earlier">‹</button>
                                <button @click="moveWidget('metric', m.id, 1)" :style="m.accent ? 'color:#fff;' : ''" title="Move later">›</button>
                                <button @click="openMetric(m.id)" :style="m.accent ? 'color:#fff;' : ''" title="Configure metric">⚙</button>
                            </div>
                            <div class="text-[11px] uppercase tracking-wide mb-2.5 pr-12"
                                 :style="m.accent ? 'color:#8FAFA3;' : 'color:var(--ink-soft);'" x-text="m.title"></div>
                            <div class="display text-[30px] font-bold leading-none"
                                 :style="m.accent ? 'color:#fff;' : ''" x-text="m.display"></div>
                            <div class="text-[11.5px] mt-2 font-semibold"
                                 :style="m.error ? 'color:var(--coral);' : (m.accent ? 'color:var(--mint);' : 'color:var(--mint-deep);')"
                                 x-text="m.error || m.subtitle || ''"></div>
                        </div>
                    </template>
                </div>

                {{-- CHARTS in this group --}}
                <div class="chart-grid" :class="g.level ? 'pl-5' : ''" x-show="chartsIn(g.sectionId).length">
                    <template x-for="c in chartsIn(g.sectionId)" :key="c.id">
                        <div class="chart-card rounded-xl p-5 group" :data-w="c.width || 'full'"
                             style="background:var(--panel);border:1px solid var(--line);">
                            <div class="flex items-start justify-between mb-3">
                                <div>
                                    <h3 class="display text-[14.5px] font-semibold uppercase tracking-wide" x-text="c.title"></h3>
                                    <p class="text-[11.5px] mt-0.5" style="color:var(--ink-soft);"
                                       x-text="c.config.sheet + ' · grouped by ' + c.config.label_column"></p>
                                </div>
                                <div class="flex items-center gap-1 text-base leading-none opacity-40 group-hover:opacity-100 transition">
                                    <button @click="moveWidget('chart', c.id, -1)" title="Move earlier">‹</button>
                                    <button @click="moveWidget('chart', c.id, 1)" title="Move later">›</button>
                                    <button @click="openBuilder(c.id)" title="Configure chart">⚙</button>
                                </div>
                            </div>
                            <div :style="'height:' + (c.height ? c.height + 'px' : '16rem')"><canvas :id="'cv-' + c.id"></canvas></div>
                        </div>
                    </template>
                </div>

                {{-- Global empty state — only in the ungrouped bucket, only when nothing exists at all. --}}
                <template x-if="g.type === 'ungrouped' && !metrics.length && !charts.length && !loading && sources.length">
                    <div class="text-center text-sm py-16 rounded-xl"
                         style="border:1px dashed var(--line);color:var(--ink-soft);">
                        Nothing here yet. Add a <b>+ New metric</b> or <b>+ New chart</b>, and use
                        <b>+ Add section</b> to group them (e.g. “General” / “SDR”).
                    </div>
                </template>
            </div>
        </template>

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
             @click.self="closeBuilder()" @keydown.escape.window="builder.open && closeBuilder()">
            <div class="rounded-2xl p-6 w-full max-w-2xl max-h-[90vh] overflow-auto" style="background:var(--panel);">
                <h3 class="display text-lg font-bold mb-5" x-text="builder.captureToLoop ? 'Loop chart template' : (builder.id ? 'Configure chart' : 'New chart')"></h3>

                <div class="grid grid-cols-2 gap-4 mb-5">
                    <div class="col-span-2">
                        <label class="block text-xs font-semibold mb-1.5">Title</label>
                        <input x-model="builder.title" placeholder="e.g. Leads by Source"
                               class="w-full rounded-lg text-sm px-3 py-2"
                               style="border:1px solid var(--line);background:var(--panel-alt);">
                    </div>

                    <div>
                        <label class="block text-xs font-semibold mb-1.5">Chart type</label>
                        <select x-model="builder.type" @change="onTypeChange()" class="w-full rounded-lg text-sm px-3 py-2"
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
                        <label class="block text-xs font-semibold mb-1.5"
                               x-text="isCategoryChart(builder.type) ? 'Max slices' : 'Rows shown'">Rows shown</label>
                        <input type="number" min="1" max="50" x-model.number="builder.limit"
                               class="w-full rounded-lg text-sm px-3 py-2"
                               style="border:1px solid var(--line);background:var(--panel-alt);">
                        <p class="mt-1 text-[10px] leading-tight" style="color:var(--ink-soft);"
                           x-show="isCategoryChart(builder.type)" x-cloak>
                            Only this many categories are drawn (largest first). Raise it to show every status/stage.
                        </p>
                    </div>

                    {{-- Placement + size --}}
                    <div class="col-span-2 grid grid-cols-3 gap-4">
                        <div x-show="!builder.captureToLoop">
                            <label class="block text-xs font-semibold mb-1.5">Section</label>
                            <select x-model="builder.section_id"
                                    class="w-full rounded-lg text-sm px-3 py-2"
                                    style="border:1px solid var(--line);background:var(--panel-alt);">
                                <option value="">— none (ungrouped) —</option>
                                <template x-for="opt in sectionSelectList()" :key="opt.id">
                                    <option :value="opt.id" x-text="opt.label"></option>
                                </template>
                            </select>
                        </div>
                        <div>
                            <label class="block text-xs font-semibold mb-1.5">Width</label>
                            <select x-model="builder.width"
                                    class="w-full rounded-lg text-sm px-3 py-2"
                                    style="border:1px solid var(--line);background:var(--panel-alt);">
                                <option value="full">Full width</option>
                                <option value="twothirds">Two-thirds</option>
                                <option value="half">Half</option>
                                <option value="third">Third</option>
                            </select>
                        </div>
                        <div>
                            <label class="block text-xs font-semibold mb-1.5">Height (px)</label>
                            <input type="number" min="160" max="900" step="20" x-model.number="builder.height"
                                   placeholder="256"
                                   class="w-full rounded-lg text-sm px-3 py-2"
                                   style="border:1px solid var(--line);background:var(--panel-alt);">
                        </div>
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
                                <select x-effect="pipelineOptions(builder.key); $nextTick(() => $el.value = filterVal(builder.filters, 'Pipeline'))"
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
                                <select x-effect="stageOptions(builder); $nextTick(() => $el.value = filterVal(builder.filters, 'Stage'))"
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

                    {{-- CONDITIONS — arbitrary {column, operator, value} filters ANDed
                         onto the whole chart (on top of the Pipeline/Stage picker).
                         Lets a chart say "group by Owner WHERE Outreach Stages
                         has_any 1st Call". Reserved Pipeline/Stage rows (owned by the
                         picker above) are hidden here. --}}
                    <div class="col-span-2 p-3 rounded-lg" style="background:var(--panel-alt);">
                        <div class="flex items-center justify-between mb-2">
                            <label class="block text-xs font-semibold">Conditions (all must match)</label>
                            <button type="button"
                                    @click="(builder.filters = builder.filters || []).push({ column: '', operator: 'eq', value: '' })"
                                    class="text-[11px] px-2.5 py-1 rounded-md font-medium"
                                    style="border:1px solid var(--line);background:var(--panel);">+ Add condition</button>
                        </div>

                        <template x-for="(cond, ci) in (builder.filters || [])" :key="ci">
                            <div class="grid grid-cols-12 gap-2 items-center mb-2"
                                 x-show="!['Pipeline','Stage'].includes(cond.column)" x-cloak>
                                <select x-model="cond.column" class="col-span-4 rounded text-xs px-2 py-1.5"
                                        style="border:1px solid var(--line);background:var(--panel);">
                                    <option value="">— column —</option>
                                    <template x-for="col in columnsFor(builder.key)" :key="col">
                                        <option :value="col" x-text="col"></option>
                                    </template>
                                </select>
                                <select x-model="cond.operator" class="col-span-4 rounded text-xs px-2 py-1.5"
                                        style="border:1px solid var(--line);background:var(--panel);">
                                    <option value="eq">equals</option>
                                    <option value="neq">does not equal</option>
                                    <option value="contains">contains</option>
                                    <option value="not_contains">does not contain</option>
                                    <option value="gt">greater than</option>
                                    <option value="lt">less than</option>
                                    <option value="has_all">has all of (comma-sep)</option>
                                    <option value="has_any">has any of (comma-sep)</option>
                                    <option value="not_has_any">has none of (comma-sep)</option>
                                    <option value="not_empty">is not empty</option>
                                    <option value="empty">is empty</option>
                                </select>
                                <input x-model="cond.value" placeholder="value"
                                       x-show="!['not_empty','empty'].includes(cond.operator)"
                                       class="col-span-3 rounded text-xs px-2 py-1.5"
                                       style="border:1px solid var(--line);background:var(--panel);">
                                <button type="button" @click="builder.filters.splice(ci, 1)"
                                        class="col-span-1 text-sm" style="color:var(--coral);">✕</button>
                            </div>
                        </template>
                    </div>
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
                        <button @click="closeBuilder()" class="px-4 py-2 rounded-lg text-sm font-medium" style="border:1px solid var(--line);">Cancel</button>
                        <button @click="saveChart()" :disabled="builder.saving"
                                class="px-5 py-2 rounded-lg text-sm font-semibold text-white disabled:opacity-50" style="background:var(--mint-deep);">
                            <span x-text="builder.captureToLoop ? (builder.templateIndex === null ? 'Add to loop' : 'Update template') : (builder.saving ? 'Saving…' : 'Save')"></span>
                        </button>
                    </div>
                </div>
            </div>
        </div>

        {{-- ============================ METRIC BUILDER MODAL ============================ --}}
        <div x-show="metric.open" x-cloak
             class="fixed inset-0 flex items-center justify-center p-4 z-50"
             style="background:rgba(14,33,29,0.55);"
             @click.self="closeMetric()" @keydown.escape.window="metric.open && closeMetric()">
            <div class="rounded-2xl p-6 w-full max-w-2xl max-h-[90vh] overflow-auto" style="background:var(--panel);">
                <h3 class="display text-lg font-bold mb-5" x-text="metric.captureToLoop ? 'Loop metric template' : (metric.id ? 'Configure metric' : 'New metric')"></h3>

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
                    <div class="col-span-2" x-show="!metric.captureToLoop">
                        <label class="block text-xs font-semibold mb-1.5">Section</label>
                        <select x-model="metric.section_id"
                                class="w-full rounded-lg text-sm px-3 py-2" style="border:1px solid var(--line);background:var(--panel-alt);">
                            <option value="">— none (ungrouped) —</option>
                            <template x-for="opt in sectionSelectList()" :key="opt.id">
                                <option :value="opt.id" x-text="opt.label"></option>
                            </template>
                        </select>
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
                        <button @click="closeMetric()" class="px-4 py-2 rounded-lg text-sm font-medium" style="border:1px solid var(--line);">Cancel</button>
                        <button @click="saveMetric()" :disabled="metric.saving"
                                class="px-5 py-2 rounded-lg text-sm font-semibold text-white disabled:opacity-50" style="background:var(--mint-deep);">
                            <span x-text="metric.captureToLoop ? (metric.templateIndex === null ? 'Add to loop' : 'Update template') : (metric.saving ? 'Saving…' : 'Save')"></span>
                        </button>
                    </div>
                </div>
            </div>
        </div>

        {{-- ============================ LOOP BUILDER MODAL ============================ --}}
        <div x-show="loop.open" x-cloak
             class="fixed inset-0 flex items-center justify-center p-4 z-50"
             style="background:rgba(14,33,29,0.55);"
             @click.self="closeLoop()" @keydown.escape.window="loop.open && closeLoop()">
            <div class="rounded-2xl p-6 w-full max-w-2xl max-h-[90vh] overflow-auto" style="background:var(--panel);">
                <h3 class="display text-lg font-bold mb-1" x-text="loop.id ? 'Edit loop statistics' : 'Loop statistics'"></h3>
                <p class="text-[12px] mb-5" style="color:var(--ink-soft);">
                    Pick a column (e.g. <b>Owner</b>) and it repeats every template metric &amp; chart below
                    for each distinct value — each in its own sub-section, scoped with
                    <span class="mono">{column} = value</span>.
                    <span x-show="loop.id" x-cloak>Saving re-applies the templates to <b>every</b> value.</span>
                </p>

                <div class="grid grid-cols-2 gap-4 mb-5">
                    <div class="col-span-2">
                        <label class="block text-xs font-semibold mb-1.5">Name (becomes the section title)</label>
                        <input x-model="loop.name" placeholder="SDR Performance"
                               class="w-full rounded-lg text-sm px-3 py-2" style="border:1px solid var(--line);background:var(--panel-alt);">
                    </div>
                    <div>
                        <label class="block text-xs font-semibold mb-1.5">Loop over source</label>
                        <select x-model="loop.key" @change="loop.column = ''"
                                class="w-full rounded-lg text-sm px-3 py-2" style="border:1px solid var(--line);background:var(--panel-alt);">
                            <template x-for="s in sources" :key="s.key">
                                <option :value="s.key" x-text="s.label"></option>
                            </template>
                        </select>
                    </div>
                    <div>
                        <label class="block text-xs font-semibold mb-1.5">Loop over column</label>
                        <select x-model="loop.column"
                                class="w-full rounded-lg text-sm px-3 py-2" style="border:1px solid var(--line);background:var(--panel-alt);">
                            <option value="">Select…</option>
                            <template x-for="col in loopColumns()" :key="col">
                                <option :value="col" x-text="col"></option>
                            </template>
                        </select>
                    </div>
                </div>

                {{-- Optional: only loop over values matching a condition (e.g. Owner contains "SDR"). --}}
                <div class="grid grid-cols-2 gap-4 mb-5 p-3 rounded-lg" style="background:var(--panel-alt);">
                    <div>
                        <label class="block text-[10px] mb-1" style="color:var(--ink-soft);">Only values where (optional)</label>
                        <select x-model="loop.valueOp"
                                class="w-full rounded text-xs px-2 py-1.5" style="border:1px solid var(--line);background:var(--panel);">
                            <option value="">— all values —</option>
                            <option value="contains">contains</option>
                            <option value="eq">equals</option>
                            <option value="neq">does not equal</option>
                            <option value="not_contains">does not contain</option>
                            <option value="has_any">has any of (comma-sep)</option>
                            <option value="has_all">has all of (comma-sep)</option>
                            <option value="not_has_any">has none of (comma-sep)</option>
                        </select>
                    </div>
                    <div x-show="loop.valueOp" x-cloak>
                        <label class="block text-[10px] mb-1" style="color:var(--ink-soft);">Value</label>
                        <input x-model="loop.valueVal" placeholder="e.g. SDR"
                               class="w-full rounded text-xs px-2 py-1.5" style="border:1px solid var(--line);background:var(--panel);">
                    </div>
                </div>

                {{-- Template metrics --}}
                <div class="mb-4">
                    <div class="flex items-center justify-between mb-2">
                        <span class="text-[10.5px] font-bold uppercase tracking-[1.5px]" style="color:var(--ink-soft);">Metric templates</span>
                        <button @click="addMetricTemplate()" class="text-xs px-3 py-1.5 rounded-md font-medium" style="border:1px solid var(--line);">+ Add metric template</button>
                    </div>
                    <template x-for="(t, i) in loop.metricTemplates" :key="i">
                        <div class="flex items-center justify-between rounded-lg px-3 py-2 mb-1.5" style="background:var(--panel-alt);">
                            <span class="text-xs font-medium" x-text="t.title || 'Metric'"></span>
                            <div class="flex items-center gap-3 text-xs">
                                <button @click="editMetricTemplate(i)" class="opacity-70 hover:opacity-100">Edit</button>
                                <button @click="removeMetricTemplate(i)" style="color:var(--coral);">✕</button>
                            </div>
                        </div>
                    </template>
                    <p x-show="!loop.metricTemplates.length" class="text-[11px]" style="color:var(--ink-soft);">None yet.</p>
                </div>

                {{-- Template charts --}}
                <div class="mb-5">
                    <div class="flex items-center justify-between mb-2">
                        <span class="text-[10.5px] font-bold uppercase tracking-[1.5px]" style="color:var(--ink-soft);">Chart templates</span>
                        <button @click="addChartTemplate()" class="text-xs px-3 py-1.5 rounded-md font-medium" style="border:1px solid var(--line);">+ Add chart template</button>
                    </div>
                    <template x-for="(t, i) in loop.chartTemplates" :key="i">
                        <div class="flex items-center justify-between rounded-lg px-3 py-2 mb-1.5" style="background:var(--panel-alt);">
                            <span class="text-xs font-medium" x-text="t.title || 'Chart'"></span>
                            <div class="flex items-center gap-3 text-xs">
                                <button @click="editChartTemplate(i)" class="opacity-70 hover:opacity-100">Edit</button>
                                <button @click="removeChartTemplate(i)" style="color:var(--coral);">✕</button>
                            </div>
                        </div>
                    </template>
                    <p x-show="!loop.chartTemplates.length" class="text-[11px]" style="color:var(--ink-soft);">None yet.</p>
                </div>

                <p x-show="loop.error" x-cloak x-text="loop.error" class="text-xs mb-3" style="color:var(--coral);"></p>

                <div class="flex items-center justify-end gap-2">
                    <button @click="closeLoop()" class="px-4 py-2 rounded-lg text-sm font-medium" style="border:1px solid var(--line);">Cancel</button>
                    <button @click="saveLoop()" :disabled="loop.saving"
                            class="px-5 py-2 rounded-lg text-sm font-semibold text-white disabled:opacity-50" style="background:var(--mint-deep);">
                        <span x-text="loop.saving ? 'Saving…' : (loop.id ? 'Save changes' : 'Build loop')"></span>
                    </button>
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
            sections: [],
            loops: [],
            sync: { at: cfg.syncStatus?.at ?? null, human: cfg.syncStatus?.human ?? null, connected: cfg.syncStatus?.connected ?? 0, total: cfg.syncStatus?.total ?? 0, syncing: false },
            instances: {},
            loading: true,

            tableKey: '',
            tableColumns: [],
            tableRows: [],
            tableQuery: '',

            builder: { open: false, id: null, title: '', type: 'bar', key: '', label_column: '', limit: 10, series: [], filters: [], section_id: '', width: 'full', height: null, error: '', saving: false },
            metric: { open: false, id: null, title: '', subtitle: '', mode: 'simple', format: 'number', decimals: 0, accent: false, section_id: '', simple: {}, varList: [], expression: '', preview: '—', previewError: '', previewing: false, error: '', saving: false, captureToLoop: false, templateIndex: null },
            loop: { open: false, id: null, name: '', key: '', column: '', valueOp: '', valueVal: '', metricTemplates: [], chartTemplates: [], error: '', saving: false },

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
                // Poll data freshness regardless of whether anything is synced yet,
                // so the "Last sync" label updates (and data reloads) as syncs land.
                setInterval(() => this.pollSyncStatus(), 60000);

                if (!this.sources.length) { this.loading = false; return; }
                this.tableKey = this.firstKey;
                await Promise.all([this.loadSections(), this.loadLoops(), this.loadCharts(), this.loadMetrics(), this.loadTable()]);
                this.loading = false;
            },

            /* ---------- data freshness / auto-sync ---------- */
            async pollSyncStatus() {
                try {
                    const res = await fetch(this.cfg.syncStatusUrl, { headers: { Accept: 'application/json' } });
                    if (!res.ok) return;
                    const s = await res.json();
                    const advanced = s.at && s.at !== this.sync.at;
                    this.sync.at = s.at; this.sync.human = s.human;
                    this.sync.connected = s.connected; this.sync.total = s.total;
                    // A newer sync landed → pull the fresh numbers into the widgets.
                    if (advanced && this.sources.length) {
                        await Promise.all([this.loadMetrics(), this.loadCharts(), this.loadLoops()]);
                    }
                } catch (e) { /* transient — try again next tick */ }
            },

            async syncNow() {
                this.sync.syncing = true;
                try {
                    const res = await fetch(this.cfg.syncAllUrl, {
                        method: 'POST',
                        headers: { Accept: 'application/json', 'X-CSRF-TOKEN': this.csrf },
                    });
                    if (res.ok) { const s = await res.json(); this.sync.at = s.at; this.sync.human = s.human; }
                } catch (e) { /* ignore */ }
                this.sync.syncing = false;
                // Syncs run in the background; check back a couple of times to catch completion.
                setTimeout(() => this.pollSyncStatus(), 8000);
                setTimeout(() => this.pollSyncStatus(), 25000);
            },

            /* ---------- loop statistics ---------- */
            async loadLoops() {
                const res = await fetch(this.cfg.loopsIndex, { headers: { Accept: 'application/json' } });
                this.loops = res.ok ? await res.json() : [];
            },

            // The sub-set of sources that carry a Pipeline column? No — loops work
            // over any dataset. Loop column options come from the chosen source.
            openLoop() {
                this.loop = {
                    open: true, id: null, name: '', key: this.firstKey, column: '',
                    valueOp: '', valueVal: '',
                    metricTemplates: [], chartTemplates: [], error: '', saving: false,
                };
            },

            // Edit an existing loop: hydrate the builder from its stored config and
            // templates (converting stored payloads back into editor snapshots).
            editLoop(lp) {
                this.loop = {
                    open: true, id: lp.id, name: lp.name,
                    key: (lp.integration_id ?? '') + '::' + (lp.dataset || ''),
                    column: lp.column,
                    valueOp: lp.value_operator || '', valueVal: lp.value_match || '',
                    metricTemplates: (lp.templates?.metrics || []).map(p => this.metricEditorFromConfig(p)),
                    chartTemplates: (lp.templates?.charts || []).map(p => this.chartEditorFromConfig(p)),
                    error: '', saving: false,
                };
            },

            closeLoop() { this.loop.open = false; },

            clone(o) { return JSON.parse(JSON.stringify(o)); },

            // --- template capture: reuse the metric/chart builders in a mode
            //     where "Save" pushes the config into the loop instead of the DB.
            addMetricTemplate() {
                this.loop.open = false;
                this.openMetric();
                this.metric.captureToLoop = true;
                this.metric.templateIndex = null;
            },

            editMetricTemplate(i) {
                this.loop.open = false;
                this.metric = { ...this.clone(this.loop.metricTemplates[i]), open: true, captureToLoop: true, templateIndex: i, preview: '—', previewError: '', previewing: false, error: '', saving: false };
            },

            captureMetricTemplate() {
                const snap = this.clone(this.metric);
                delete snap.open; delete snap.captureToLoop; delete snap.templateIndex; delete snap.previewing;
                if (this.metric.templateIndex === null) this.loop.metricTemplates.push(snap);
                else this.loop.metricTemplates[this.metric.templateIndex] = snap;
                this.metric.open = false;
                this.metric.captureToLoop = false;
                this.loop.open = true;
            },

            addChartTemplate() {
                this.loop.open = false;
                this.openBuilder();
                this.builder.captureToLoop = true;
                this.builder.templateIndex = null;
            },

            editChartTemplate(i) {
                this.loop.open = false;
                this.builder = { ...this.clone(this.loop.chartTemplates[i]), open: true, captureToLoop: true, templateIndex: i, error: '', saving: false };
            },

            captureChartTemplate() {
                const snap = this.clone(this.builder);
                delete snap.open; delete snap.captureToLoop; delete snap.templateIndex;
                if (this.builder.templateIndex === null || this.builder.templateIndex === undefined) this.loop.chartTemplates.push(snap);
                else this.loop.chartTemplates[this.builder.templateIndex] = snap;
                this.builder.open = false;
                this.builder.captureToLoop = false;
                this.loop.open = true;
            },

            removeMetricTemplate(i) { this.loop.metricTemplates.splice(i, 1); },
            removeChartTemplate(i) { this.loop.chartTemplates.splice(i, 1); },

            // Close handlers that return to the loop modal when we were capturing.
            closeMetric() {
                this.metric.open = false;
                if (this.metric.captureToLoop) { this.metric.captureToLoop = false; this.loop.open = true; }
            },
            closeBuilder() {
                this.builder.open = false;
                if (this.builder.captureToLoop) { this.builder.captureToLoop = false; this.loop.open = true; }
            },

            async saveLoop() {
                this.loop.error = '';
                if (!this.loop.name.trim()) { this.loop.error = 'Give the loop a name.'; return; }
                if (!this.loop.column) { this.loop.error = 'Pick a column to loop over.'; return; }
                if (!this.loop.metricTemplates.length && !this.loop.chartTemplates.length) {
                    this.loop.error = 'Add at least one metric or chart template.'; return;
                }

                this.loop.saving = true;
                const src = this.splitKey(this.loop.key);
                const payload = {
                    name: this.loop.name,
                    integration_id: src.integration_id,
                    dataset: src.dataset,
                    column: this.loop.column,
                    value_operator: this.loop.valueOp || null,
                    value_match: this.loop.valueVal || null,
                    metrics: this.loop.metricTemplates.map(t => this.buildMetricPayload(t)),
                    charts: this.loop.chartTemplates.map(t => this.buildChartPayload(t)),
                };

                const editing = Boolean(this.loop.id);
                const res = await fetch(editing ? `/loops/${this.loop.id}` : this.cfg.loopsStore, {
                    method: editing ? 'PUT' : 'POST',
                    headers: { 'Content-Type': 'application/json', Accept: 'application/json', 'X-CSRF-TOKEN': this.csrf },
                    body: JSON.stringify(payload),
                });
                this.loop.saving = false;
                if (!res.ok) { const err = await res.json().catch(() => ({})); this.loop.error = err.message || 'Could not build the loop.'; return; }

                this.loop.open = false;
                await Promise.all([this.loadLoops(), this.loadSections(), this.loadMetrics(), this.loadCharts()]);
            },

            async refreshLoop(loop) {
                await fetch(`/loops/${loop.id}/refresh`, { method: 'POST', headers: { Accept: 'application/json', 'X-CSRF-TOKEN': this.csrf } });
                await Promise.all([this.loadLoops(), this.loadSections(), this.loadMetrics(), this.loadCharts()]);
            },

            async deleteLoop(loop) {
                if (!confirm(`Delete loop “${loop.name}” and all the sections/widgets it generated?`)) return;
                await fetch(`/loops/${loop.id}`, { method: 'DELETE', headers: { Accept: 'application/json', 'X-CSRF-TOKEN': this.csrf } });
                await Promise.all([this.loadLoops(), this.loadSections(), this.loadMetrics(), this.loadCharts()]);
            },

            loopColumns() { return this.columnsFor(this.loop.key); },

            /* ---------- sections & layout ---------- */
            async loadSections() {
                const res = await fetch(this.cfg.sectionsIndex, { headers: { Accept: 'application/json' } });
                this.sections = res.ok ? await res.json() : [];
            },

            topSections() {
                return this.sections.filter(s => !s.parent_id).sort((a, b) => a.position - b.position);
            },

            subSections(parentId) {
                return this.sections.filter(s => s.parent_id === parentId).sort((a, b) => a.position - b.position);
            },

            // The ordered list of render buckets: ungrouped first, then each top
            // section immediately followed by its sub-sections.
            orderedGroups() {
                const groups = [{ type: 'ungrouped', sectionId: null, level: 0, key: 'ungrouped' }];
                for (const top of this.topSections()) {
                    groups.push({ type: 'section', section: top, sectionId: top.id, level: 0, key: 'sec-' + top.id });
                    for (const sub of this.subSections(top.id)) {
                        groups.push({ type: 'section', section: sub, sectionId: sub.id, level: 1, key: 'sec-' + sub.id });
                    }
                }
                return groups;
            },

            metricsIn(sectionId) {
                return this.metrics.filter(m => (m.section_id ?? null) === sectionId).sort((a, b) => a.position - b.position);
            },

            chartsIn(sectionId) {
                return this.charts.filter(c => (c.section_id ?? null) === sectionId).sort((a, b) => a.position - b.position);
            },

            // Flat list for the builder <select>s: top sections, each followed by
            // its sub-sections (prefixed) so nesting reads clearly.
            sectionSelectList() {
                const out = [];
                for (const top of this.topSections()) {
                    out.push({ id: top.id, label: top.title });
                    for (const sub of this.subSections(top.id)) out.push({ id: sub.id, label: '— ' + sub.title });
                }
                return out;
            },

            async addSection(parentId = null) {
                const title = prompt(parentId ? 'Name for the sub-section:' : 'Name for the section:');
                if (!title) return;
                const res = await fetch(this.cfg.sectionsStore, {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json', Accept: 'application/json', 'X-CSRF-TOKEN': this.csrf },
                    body: JSON.stringify({ title, parent_id: parentId }),
                });
                if (res.ok) this.sections = await res.json();
            },

            addSubSection(parentId) { return this.addSection(parentId); },

            async renameSection(section) {
                const title = prompt('Rename section:', section.title);
                if (!title || title === section.title) return;
                const res = await fetch(`/sections/${section.id}`, {
                    method: 'PUT',
                    headers: { 'Content-Type': 'application/json', Accept: 'application/json', 'X-CSRF-TOKEN': this.csrf },
                    body: JSON.stringify({ title }),
                });
                if (res.ok) this.sections = await res.json();
            },

            async deleteSection(section) {
                if (!confirm(`Delete section “${section.title}”? Its widgets move back to ungrouped.`)) return;
                const res = await fetch(`/sections/${section.id}`, {
                    method: 'DELETE',
                    headers: { Accept: 'application/json', 'X-CSRF-TOKEN': this.csrf },
                });
                if (res.ok) {
                    this.sections = await res.json();
                    // Widgets were freed server-side — reload so their section_id updates.
                    await Promise.all([this.loadMetrics(), this.loadCharts()]);
                }
            },

            // Reorder a section among its siblings (top-level or within a parent).
            moveSection(section, dir) {
                const siblings = section.parent_id ? this.subSections(section.parent_id) : this.topSections();
                const idx = siblings.findIndex(s => s.id === section.id);
                const swap = idx + dir;
                if (swap < 0 || swap >= siblings.length) return;
                const tmp = siblings[idx].position;
                siblings[idx].position = siblings[swap].position;
                siblings[swap].position = tmp;
                this.saveLayout();
            },

            // Reorder a widget within its current section by swapping positions.
            moveWidget(kind, id, dir) {
                const item = (kind === 'chart' ? this.charts : this.metrics).find(x => x.id === id);
                if (!item) return;
                const list = kind === 'chart' ? this.chartsIn(item.section_id ?? null) : this.metricsIn(item.section_id ?? null);
                const idx = list.findIndex(x => x.id === id);
                const swap = idx + dir;
                if (swap < 0 || swap >= list.length) return;
                const tmp = list[idx].position;
                list[idx].position = list[swap].position;
                list[swap].position = tmp;
                this.recomputeGlobalPositions();
                this.saveLayout();
                if (kind === 'chart') this.$nextTick(() => this.charts.forEach(c => this.draw(c)));
            },

            // Normalise positions to 0..n across the whole dashboard, following
            // the visual group order — keeps DB positions tidy and unambiguous.
            recomputeGlobalPositions() {
                let mi = 0, ci = 0;
                for (const g of this.orderedGroups()) {
                    this.metricsIn(g.sectionId).forEach(m => { m.position = mi++; });
                    this.chartsIn(g.sectionId).forEach(c => { c.position = ci++; });
                }
            },

            async saveLayout() {
                await fetch(this.cfg.layoutSave, {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json', Accept: 'application/json', 'X-CSRF-TOKEN': this.csrf },
                    body: JSON.stringify({
                        sections: this.sections.map(s => ({ id: s.id, parent_id: s.parent_id ?? null, position: s.position })),
                        charts: this.charts.map(c => ({ id: c.id, section_id: c.section_id ?? null, position: c.position })),
                        metrics: this.metrics.map(m => ({ id: m.id, section_id: m.section_id ?? null, position: m.position })),
                    }),
                });
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
            // Pie / doughnut / polar area group rows into one slice per distinct
            // value, so a small "max slices" cap silently hides categories. Bar
            // and line charts genuinely want a top-N, so they keep the default.
            isCategoryChart(type) {
                return ['pie', 'doughnut', 'polarArea'].includes(type);
            },

            // When the user switches to a category chart, open the cap up to the
            // max so every distinct value (status, stage, …) is drawn by default.
            // We only raise it, never shrink a value the user chose on purpose.
            onTypeChange() {
                if (this.isCategoryChart(this.builder.type) && this.builder.limit < 50) {
                    this.builder.limit = 50;
                }
            },

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
                        filters: [], section_id: '', width: 'full', height: null, error: '', saving: false,
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
                    section_id: c.section_id != null ? String(c.section_id) : '', width: c.width || 'full', height: c.height || null,
                    error: '', saving: false,
                };
            },

            addSeries() {
                this.builder.series.push({ key: this.builder.key, column: '', agg: 'count', label: 'Series ' + (this.builder.series.length + 1) });
            },

            chartPayload() { return this.buildChartPayload(this.builder); },

            buildChartPayload(b) {
                const chart = this.splitKey(b.key);
                return {
                    title: b.title,
                    type: b.type,
                    integration_id: chart.integration_id,
                    section_id: b.section_id || null,
                    sheet: chart.dataset,
                    label_column: b.label_column,
                    aggregate: (b.series && b.series[0]?.agg) || 'count',
                    limit: b.limit,
                    width: b.width || 'full',
                    height: b.height || null,
                    filters: b.filters || [],
                    series: (b.series || []).map(s => {
                        const src = this.splitKey(s.key);
                        return { integration_id: src.integration_id, sheet: src.dataset, column: s.column, agg: s.agg, label: s.label, color: s.color };
                    }),
                };
            },

            async saveChart() {
                this.builder.error = '';
                if (!this.builder.title.trim()) { this.builder.error = 'Title is required.'; return; }
                if (!this.builder.label_column) { this.builder.error = 'Pick a group-by column.'; return; }

                // Capture mode: this chart is a loop template, not a real chart.
                if (this.builder.captureToLoop) { this.captureChartTemplate(); return; }

                this.builder.saving = true;
                const editing = Boolean(this.builder.id);
                const url = editing ? `/charts/${this.builder.id}` : this.cfg.chartsStore;

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
                await fetch(`/charts/${this.builder.id}`, { method: 'DELETE', headers: { 'X-CSRF-TOKEN': this.csrf } });
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
                        section_id: '', simple: this.blankSource(), varList: [{ name: 'a', ...this.blankSource() }], expression: '',
                        preview: '—', previewError: '', previewing: false, error: '', saving: false,
                    };
                    return;
                }

                this.metric = {
                    open: true, id: m.id, ...this.metricEditorFromConfig(m.config),
                    preview: m.display, previewError: '', previewing: false, error: '', saving: false,
                };
            },

            // Build the metric-editor object from a config/payload (server metric
            // config OR a stored loop template — both share this shape). Used by
            // openMetric, loop-template editing, and loop hydration.
            metricEditorFromConfig(cfg) {
                cfg = cfg || {};
                const keyOf = (o) => (o.integration_id ?? cfg.integration_id ?? '') + '::' + (o.sheet || '');
                const varList = Object.entries(cfg.variables || {}).map(([name, v]) => ({
                    name, key: keyOf(v), agg: v.agg || 'count', column: v.column || '',
                    filter_column: v.filter_column || '', filter_operator: v.filter_operator || 'eq', filter_value: v.filter_value || '',
                    filters: v.filters || [],
                }));

                return {
                    title: cfg.title || '', subtitle: cfg.subtitle || '',
                    mode: cfg.mode || 'simple', format: cfg.format || 'number', decimals: cfg.decimals ?? 0, accent: Boolean(cfg.accent),
                    section_id: cfg.section_id != null ? String(cfg.section_id) : '',
                    simple: { key: (cfg.integration_id ?? '') + '::' + (cfg.sheet || ''), agg: cfg.agg || 'count', column: cfg.column || '', filter_column: cfg.filter_column || '', filter_operator: cfg.filter_operator || 'eq', filter_value: cfg.filter_value || '', filters: cfg.filters || [] },
                    varList: varList.length ? varList : [{ name: 'a', ...this.blankSource() }],
                    expression: cfg.expression || '',
                };
            },

            // Build the chart-builder object from a stored loop template payload
            // (the shape buildChartPayload emits).
            chartEditorFromConfig(c) {
                c = c || {};
                return {
                    title: c.title || '', type: c.type || 'bar',
                    key: (c.integration_id ?? '') + '::' + (c.sheet || ''),
                    label_column: c.label_column || '', limit: c.limit ?? 10,
                    series: (c.series || []).map(s => ({ key: (s.integration_id ?? c.integration_id ?? '') + '::' + (s.sheet ?? c.sheet ?? ''), column: s.column || '', agg: s.agg || 'count', label: s.label || 'Count', color: s.color })),
                    filters: c.filters || [],
                    section_id: c.section_id != null ? String(c.section_id) : '',
                    width: c.width || 'full', height: c.height || null,
                };
            },

            ensureVariables() { if (!this.metric.varList.length) this.metric.varList = [{ name: 'a', ...this.blankSource() }]; },
            addVariable() { const next = String.fromCharCode(97 + this.metric.varList.length); this.metric.varList.push({ name: next, ...this.blankSource() }); },

            sourceConfig(o) {
                const src = this.splitKey(o.key);
                return { integration_id: src.integration_id, sheet: src.dataset, agg: o.agg, column: o.column, filter_column: o.filter_column, filter_operator: o.filter_operator, filter_value: o.filter_value, filters: o.filters || [] };
            },

            metricPayload() { return this.buildMetricPayload(this.metric); },

            buildMetricPayload(m) {
                const base = { title: m.title, subtitle: m.subtitle, mode: m.mode, format: m.format, decimals: m.decimals, accent: m.accent, section_id: m.section_id || null };

                if (m.mode === 'simple') {
                    const cfg = this.sourceConfig(m.simple);
                    return { ...base, integration_id: cfg.integration_id, ...cfg };
                }

                const variables = {};
                let integration_id = null;
                (m.varList || []).forEach(v => {
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

                // Capture mode: this metric is a loop template, not a real metric.
                if (this.metric.captureToLoop) { this.captureMetricTemplate(); return; }

                this.metric.saving = true;
                const editing = Boolean(this.metric.id);
                const url = editing ? `/metrics/${this.metric.id}` : this.cfg.metricsStore;

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
                await fetch(`/metrics/${this.metric.id}`, { method: 'DELETE', headers: { 'X-CSRF-TOKEN': this.csrf } });
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
