{{-- resources/views/admin/sheets.blade.php --}}
<x-app-layout title="Sheets">
    @push('head')
        {{-- Third-party grid (MIT) + charts, loaded the same way the dashboard loads Chart.js. --}}
        <link href="https://cdn.jsdelivr.net/npm/tabulator-tables@6.3.1/dist/css/tabulator.min.css" rel="stylesheet">
        <script src="https://cdn.jsdelivr.net/npm/tabulator-tables@6.3.1/dist/js/tabulator.min.js"></script>
        <script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.1/dist/chart.umd.min.js"></script>
        <style>
            .tabulator { font-size: 12.5px; border:1px solid var(--line); border-radius:10px; background:var(--panel); }
            .tabulator .tabulator-header { background:var(--panel-alt); border-bottom:1px solid var(--line); }
            .tabulator .tabulator-col { background:var(--panel-alt); }
            .tabulator-row.tabulator-row-even { background:rgba(0,0,0,0.015); }
            .chip { display:inline-flex; align-items:center; gap:6px; padding:3px 9px; border-radius:999px;
                    font-size:11px; background:var(--panel-alt); border:1px solid var(--line); }
            .chip button { line-height:1; opacity:.5; } .chip button:hover { opacity:1; }
            .sheet-tab { border:1px solid var(--line); background:var(--panel); }
            .sheet-tab.is-active { background:rgba(79,227,166,0.14); border-color:var(--mint-deep); color:var(--mint-deep); font-weight:600; }
            [x-cloak]{ display:none !important; }
        </style>
    @endpush

    <div x-data="sheetsApp(@js([
            'schema'    => $schema,
            'sheets'    => $sheets->map(fn ($s) => ['id' => $s->id, 'name' => $s->name, 'integration_id' => $s->integration_id, 'dataset' => $s->dataset, 'config' => $s->config ?? []]),
            'store'     => route('admin.sheets.store'),
            'dataUrl'   => route('admin.sheets.index'),
            'csrf'      => csrf_token(),
        ]))" x-init="boot()" class="px-6 lg:px-8 py-8">

        {{-- ============ Header ============ --}}
        <div class="flex flex-wrap items-end justify-between gap-4 mb-6">
            <div>
                <h1 class="display text-2xl font-bold">Sheets</h1>
                <p class="text-sm" style="color:var(--ink-soft);">
                    Open any synced table like a spreadsheet — filter, look up, and chart it. Read-only: nothing here changes your data.
                </p>
            </div>
            <button @click="openNewSheet()" :disabled="!sources.length"
                    class="px-4 py-2 rounded-lg text-sm font-semibold text-white disabled:opacity-40"
                    style="background:var(--mint-deep);">+ New sheet</button>
        </div>

        <template x-if="!sources.length">
            <div class="rounded-xl px-4 py-3 text-sm mb-6"
                 style="background:rgba(238,159,78,0.12);border:1px solid var(--amber);color:#7A4A12;">
                No synced data yet — connect and sync an integration in
                <a href="{{ route('admin.integrations.index') }}" class="underline font-semibold">Integrations</a>.
            </div>
        </template>

        {{-- ============ Tab bar ============ --}}
        <div class="flex flex-wrap items-center gap-2 mb-5" x-show="sheets.length" x-cloak>
            <template x-for="s in sheets" :key="s.id">
                <button @click="openSheet(s.id)"
                        class="sheet-tab px-3.5 py-1.5 rounded-lg text-[13px] transition"
                        :class="{ 'is-active': s.id === activeId }" x-text="s.name"></button>
            </template>
        </div>

        {{-- ============ Active sheet ============ --}}
        <div x-show="active" x-cloak>
            {{-- Toolbar --}}
            <div class="flex flex-wrap items-center gap-2 mb-3">
                <input type="search" x-model.debounce.300ms="search" @input="applyView()"
                       placeholder="Search this table…"
                       class="rounded-lg text-sm px-3 py-2 w-56"
                       style="border:1px solid var(--line);background:var(--panel-alt);">

                <button @click="openFilters()" class="px-3 py-2 rounded-lg text-sm font-medium"
                        style="border:1px solid var(--line);background:var(--panel);">
                    ⧩ Filters <span x-show="conditions.length" x-text="'(' + conditions.length + ')'" class="font-semibold"></span>
                </button>

                <select x-model="group" @change="applyView(); persist()"
                        class="rounded-lg text-sm px-3 py-2" style="border:1px solid var(--line);background:var(--panel-alt);">
                    <option value="">No grouping</option>
                    <template x-for="c in columns" :key="'g'+c"><option :value="c" x-text="'Group by: ' + c"></option></template>
                </select>

                <label class="flex items-center gap-1.5 text-sm px-3 py-2 rounded-lg"
                       style="border:1px solid var(--line);background:var(--panel);">
                    <input type="checkbox" x-model="totals" @change="rebuild(); persist()"> Totals
                </label>

                <button @click="openLookup()" class="px-3 py-2 rounded-lg text-sm font-medium"
                        style="border:1px solid var(--line);background:var(--panel);">🔗 Add lookup</button>
                <button @click="openChart()" class="px-3 py-2 rounded-lg text-sm font-medium"
                        style="border:1px solid var(--line);background:var(--panel);">📊 Add chart</button>
                <button @click="exportCsv()" class="px-3 py-2 rounded-lg text-sm font-medium"
                        style="border:1px solid var(--line);background:var(--panel);">⬇ Export CSV</button>

                <div class="ml-auto flex items-center gap-2">
                    <span class="text-xs" style="color:var(--ink-soft);" x-text="sourceLabel"></span>
                    <button @click="deleteSheet()" class="px-3 py-2 rounded-lg text-sm font-medium"
                            style="border:1px solid var(--line);color:var(--coral);background:var(--panel);">Delete</button>
                </div>
            </div>

            {{-- Active filter + lookup chips --}}
            <div class="flex flex-wrap items-center gap-2 mb-3" x-show="conditions.length || lookups.length">
                <template x-for="(c, i) in conditions" :key="'c'+i">
                    <span class="chip">
                        <span x-text="c.column + ' ' + opLabel(c.operator) + ' ' + (c.value || '')"></span>
                        <button @click="conditions.splice(i,1); applyView(); persist()">✕</button>
                    </span>
                </template>
                <template x-for="(lk, i) in lookups" :key="'l'+i">
                    <span class="chip" style="background:rgba(79,227,166,0.10);">
                        🔗 <span x-text="lk.name"></span>
                        <button @click="removeLookup(i)">✕</button>
                    </span>
                </template>
            </div>

            {{-- Grid --}}
            <div class="relative">
                <div x-show="loading" class="absolute inset-0 z-10 flex items-center justify-center text-sm"
                     style="background:rgba(255,255,255,0.6);color:var(--ink-soft);">Loading…</div>
                <div id="grid"></div>
            </div>
            <p class="text-[11px] mt-2" style="color:var(--ink-soft);" x-text="rowCountLabel"></p>

            {{-- In-sheet charts --}}
            <div class="grid grid-cols-1 xl:grid-cols-2 gap-4 mt-6" x-show="charts.length">
                <template x-for="ch in charts" :key="ch.cid">
                    <div class="rounded-xl p-5" style="background:var(--panel);border:1px solid var(--line);">
                        <div class="flex items-start justify-between mb-3">
                            <div>
                                <h3 class="display text-[14px] font-semibold uppercase tracking-wide" x-text="ch.title"></h3>
                                <p class="text-[11px] mt-0.5" style="color:var(--ink-soft);"
                                   x-text="'grouped by ' + ch.label_column + (ch.value_column ? (' · ' + ch.agg + '(' + ch.value_column + ')') : ' · count')"></p>
                            </div>
                            <button @click="removeChart(ch.cid)" class="text-sm opacity-40 hover:opacity-100" title="Remove chart">✕</button>
                        </div>
                        <div class="h-64"><canvas :id="'chart-' + ch.cid"></canvas></div>
                    </div>
                </template>
            </div>
        </div>

        {{-- ================= Modals ================= --}}

        {{-- New sheet --}}
        <div x-show="modal==='sheet'" x-cloak class="fixed inset-0 z-50 flex items-center justify-center p-4"
             style="background:rgba(0,0,0,0.35);" @click.self="modal=null">
            <div class="w-full max-w-md rounded-xl p-6" style="background:var(--panel);border:1px solid var(--line);">
                <h3 class="display text-lg font-bold mb-4">New sheet</h3>
                <label class="block text-xs font-semibold mb-1.5">Name</label>
                <input x-model="form.name" placeholder="e.g. Booked leads"
                       class="w-full rounded-lg text-sm px-3 py-2 mb-3" style="border:1px solid var(--line);background:var(--panel-alt);">
                <label class="block text-xs font-semibold mb-1.5">Source table</label>
                <select x-model="form.key" class="w-full rounded-lg text-sm px-3 py-2 mb-4"
                        style="border:1px solid var(--line);background:var(--panel-alt);">
                    <template x-for="s in sources" :key="s.key"><option :value="s.key" x-text="s.label"></option></template>
                </select>
                <p x-show="formError" x-text="formError" class="text-xs mb-3" style="color:var(--coral);"></p>
                <div class="flex justify-end gap-2">
                    <button @click="modal=null" class="px-4 py-2 rounded-lg text-sm" style="border:1px solid var(--line);">Cancel</button>
                    <button @click="createSheet()" class="px-4 py-2 rounded-lg text-sm font-semibold text-white" style="background:var(--mint-deep);">Create</button>
                </div>
            </div>
        </div>

        {{-- Filter builder --}}
        <div x-show="modal==='filters'" x-cloak class="fixed inset-0 z-50 flex items-center justify-center p-4"
             style="background:rgba(0,0,0,0.35);" @click.self="modal=null">
            <div class="w-full max-w-2xl rounded-xl p-6" style="background:var(--panel);border:1px solid var(--line);">
                <h3 class="display text-lg font-bold mb-1">Filters</h3>
                <p class="text-xs mb-4" style="color:var(--ink-soft);">Build a query with multiple conditions — no formulas to learn.</p>

                <div class="flex items-center gap-2 mb-3 text-sm">
                    <span>Match</span>
                    <select x-model="draftMatch" class="rounded-lg text-sm px-2 py-1.5" style="border:1px solid var(--line);background:var(--panel-alt);">
                        <option value="all">ALL conditions (AND)</option>
                        <option value="any">ANY condition (OR)</option>
                    </select>
                </div>

                <div class="space-y-2 mb-3">
                    <template x-for="(c, i) in draft" :key="i">
                        <div class="grid grid-cols-12 gap-2 items-center">
                            <select x-model="c.column" class="col-span-4 rounded-lg text-sm px-2 py-1.5" style="border:1px solid var(--line);background:var(--panel-alt);">
                                <option value="">Column…</option>
                                <template x-for="col in columns" :key="'fc'+col"><option :value="col" x-text="col"></option></template>
                            </select>
                            <select x-model="c.operator" class="col-span-3 rounded-lg text-sm px-2 py-1.5" style="border:1px solid var(--line);background:var(--panel-alt);">
                                <template x-for="op in operators" :key="op.v"><option :value="op.v" x-text="op.t"></option></template>
                            </select>
                            <input x-model="c.value" :disabled="['empty','not_empty'].includes(c.operator)"
                                   placeholder="value" class="col-span-4 rounded-lg text-sm px-2 py-1.5 disabled:opacity-40"
                                   style="border:1px solid var(--line);background:var(--panel-alt);">
                            <button @click="draft.splice(i,1)" class="col-span-1 text-sm opacity-50 hover:opacity-100">✕</button>
                        </div>
                    </template>
                </div>

                <button @click="draft.push({column:'',operator:'contains',value:''})"
                        class="text-sm px-3 py-1.5 rounded-lg mb-5" style="border:1px solid var(--line);background:var(--panel-alt);">+ Add condition</button>

                <div class="flex justify-between">
                    <button @click="draft=[]; draftMatch='all'" class="px-3 py-2 rounded-lg text-sm" style="border:1px solid var(--line);">Clear all</button>
                    <div class="flex gap-2">
                        <button @click="modal=null" class="px-4 py-2 rounded-lg text-sm" style="border:1px solid var(--line);">Cancel</button>
                        <button @click="applyFilters()" class="px-4 py-2 rounded-lg text-sm font-semibold text-white" style="background:var(--mint-deep);">Apply</button>
                    </div>
                </div>
            </div>
        </div>

        {{-- Add lookup (VLOOKUP) --}}
        <div x-show="modal==='lookup'" x-cloak class="fixed inset-0 z-50 flex items-center justify-center p-4"
             style="background:rgba(0,0,0,0.35);" @click.self="modal=null">
            <div class="w-full max-w-lg rounded-xl p-6" style="background:var(--panel);border:1px solid var(--line);">
                <h3 class="display text-lg font-bold mb-1">Add lookup column</h3>
                <p class="text-xs mb-4" style="color:var(--ink-soft);">Pull a matching value from another table — a spreadsheet VLOOKUP across your integrations.</p>

                <label class="block text-xs font-semibold mb-1.5">New column name</label>
                <input x-model="lk.name" placeholder="e.g. Owner email"
                       class="w-full rounded-lg text-sm px-3 py-2 mb-3" style="border:1px solid var(--line);background:var(--panel-alt);">

                <label class="block text-xs font-semibold mb-1.5">Look up in table</label>
                <select x-model="lk.key" class="w-full rounded-lg text-sm px-3 py-2 mb-3" style="border:1px solid var(--line);background:var(--panel-alt);">
                    <option value="">Select a table…</option>
                    <template x-for="s in sources" :key="'lks'+s.key"><option :value="s.key" x-text="s.label"></option></template>
                </select>

                <div class="grid grid-cols-2 gap-3 mb-3">
                    <div>
                        <label class="block text-xs font-semibold mb-1.5">Match this column…</label>
                        <select x-model="lk.local_key" class="w-full rounded-lg text-sm px-3 py-2" style="border:1px solid var(--line);background:var(--panel-alt);">
                            <template x-for="c in columns" :key="'lkl'+c"><option :value="c" x-text="c"></option></template>
                        </select>
                    </div>
                    <div>
                        <label class="block text-xs font-semibold mb-1.5">…to this column</label>
                        <select x-model="lk.foreign_key" class="w-full rounded-lg text-sm px-3 py-2" style="border:1px solid var(--line);background:var(--panel-alt);">
                            <template x-for="c in columnsFor(lk.key)" :key="'lkf'+c"><option :value="c" x-text="c"></option></template>
                        </select>
                    </div>
                </div>

                <label class="block text-xs font-semibold mb-1.5">Return value from</label>
                <select x-model="lk.return_column" class="w-full rounded-lg text-sm px-3 py-2 mb-4" style="border:1px solid var(--line);background:var(--panel-alt);">
                    <template x-for="c in columnsFor(lk.key)" :key="'lkr'+c"><option :value="c" x-text="c"></option></template>
                </select>

                <p x-show="lkError" x-text="lkError" class="text-xs mb-3" style="color:var(--coral);"></p>
                <div class="flex justify-end gap-2">
                    <button @click="modal=null" class="px-4 py-2 rounded-lg text-sm" style="border:1px solid var(--line);">Cancel</button>
                    <button @click="saveLookup()" class="px-4 py-2 rounded-lg text-sm font-semibold text-white" style="background:var(--mint-deep);">Add column</button>
                </div>
            </div>
        </div>

        {{-- Add chart --}}
        <div x-show="modal==='chart'" x-cloak class="fixed inset-0 z-50 flex items-center justify-center p-4"
             style="background:rgba(0,0,0,0.35);" @click.self="modal=null">
            <div class="w-full max-w-lg rounded-xl p-6" style="background:var(--panel);border:1px solid var(--line);">
                <h3 class="display text-lg font-bold mb-4">Add chart</h3>
                <div class="grid grid-cols-2 gap-3 mb-3">
                    <div class="col-span-2">
                        <label class="block text-xs font-semibold mb-1.5">Title</label>
                        <input x-model="ch.title" placeholder="e.g. Leads by status" class="w-full rounded-lg text-sm px-3 py-2" style="border:1px solid var(--line);background:var(--panel-alt);">
                    </div>
                    <div>
                        <label class="block text-xs font-semibold mb-1.5">Type</label>
                        <select x-model="ch.type" @change="ch.limit = isCategory(ch.type) ? 50 : 10" class="w-full rounded-lg text-sm px-3 py-2" style="border:1px solid var(--line);background:var(--panel-alt);">
                            <option value="bar">Bar</option>
                            <option value="horizontalBar">Bar (horizontal)</option>
                            <option value="line">Line</option>
                            <option value="pie">Pie</option>
                            <option value="doughnut">Doughnut</option>
                        </select>
                    </div>
                    <div>
                        <label class="block text-xs font-semibold mb-1.5" x-text="isCategory(ch.type) ? 'Max slices' : 'Rows shown'"></label>
                        <input type="number" min="1" max="50" x-model.number="ch.limit" class="w-full rounded-lg text-sm px-3 py-2" style="border:1px solid var(--line);background:var(--panel-alt);">
                    </div>
                    <div>
                        <label class="block text-xs font-semibold mb-1.5">Group by (label)</label>
                        <select x-model="ch.label_column" class="w-full rounded-lg text-sm px-3 py-2" style="border:1px solid var(--line);background:var(--panel-alt);">
                            <option value="">Select…</option>
                            <template x-for="c in columns" :key="'chl'+c"><option :value="c" x-text="c"></option></template>
                        </select>
                    </div>
                    <div>
                        <label class="block text-xs font-semibold mb-1.5">Aggregate</label>
                        <select x-model="ch.agg" class="w-full rounded-lg text-sm px-3 py-2" style="border:1px solid var(--line);background:var(--panel-alt);">
                            <option value="count">Count</option>
                            <option value="sum">Sum</option>
                            <option value="avg">Average</option>
                            <option value="min">Min</option>
                            <option value="max">Max</option>
                        </select>
                    </div>
                    <div class="col-span-2" x-show="ch.agg !== 'count'">
                        <label class="block text-xs font-semibold mb-1.5">Value column</label>
                        <select x-model="ch.value_column" class="w-full rounded-lg text-sm px-3 py-2" style="border:1px solid var(--line);background:var(--panel-alt);">
                            <option value="">Select…</option>
                            <template x-for="c in columns" :key="'chv'+c"><option :value="c" x-text="c"></option></template>
                        </select>
                    </div>
                </div>
                <p x-show="chError" x-text="chError" class="text-xs mb-3" style="color:var(--coral);"></p>
                <div class="flex justify-end gap-2">
                    <button @click="modal=null" class="px-4 py-2 rounded-lg text-sm" style="border:1px solid var(--line);">Cancel</button>
                    <button @click="saveChart()" class="px-4 py-2 rounded-lg text-sm font-semibold text-white" style="background:var(--mint-deep);">Add chart</button>
                </div>
            </div>
        </div>
    </div>

    @push('scripts')
    <script>
    function sheetsApp(cfg) {
        return {
            cfg,
            sources: [],
            sheets: cfg.sheets || [],
            activeId: null,
            active: null,
            columns: [],
            data: { columns: [], rows: [], lookups: [] },
            table: null,
            chartInstances: {},
            loading: false,

            // View state (mirrors active.config; persisted on change).
            search: '', conditions: [], group: '', totals: false, lookups: [], charts: [],

            // Modal + builders.
            modal: null, formError: '', lkError: '', chError: '',
            form: { name: '', key: '' },
            draft: [], draftMatch: 'all',
            lk: { name: '', key: '', local_key: '', foreign_key: '', return_column: '' },
            ch: { title: '', type: 'bar', limit: 50, label_column: '', agg: 'count', value_column: '' },

            operators: [
                { v: 'contains', t: 'contains' }, { v: 'not_contains', t: 'does not contain' },
                { v: 'eq', t: 'equals' }, { v: 'neq', t: 'not equals' },
                { v: 'gt', t: '>' }, { v: 'lt', t: '<' }, { v: 'gte', t: '≥' }, { v: 'lte', t: '≤' },
                { v: 'empty', t: 'is empty' }, { v: 'not_empty', t: 'is not empty' },
            ],

            boot() {
                this.sources = this.sourcesFrom(cfg.schema);
                this.form.key = this.sources[0]?.key || '';
                if (this.sheets.length) this.openSheet(this.sheets[0].id);
            },

            /* ---------- sources / columns (same shape as the chart builder) ---------- */
            sourcesFrom(schema) {
                return (schema || []).map(s => ({
                    key: (s.integration_id ?? '') + '::' + s.dataset,
                    label: s.integration + ' · ' + s.dataset,
                    integration_id: s.integration_id ?? null,
                    dataset: s.dataset,
                    columns: s.columns || [],
                }));
            },
            splitKey(key) {
                const [id, ...rest] = String(key).split('::');
                return { integration_id: id === '' ? null : Number(id), dataset: rest.join('::') };
            },
            columnsFor(key) {
                return this.sources.find(s => s.key === key)?.columns || [];
            },

            get sourceLabel() {
                if (!this.active) return '';
                const k = (this.active.integration_id ?? '') + '::' + this.active.dataset;
                return this.sources.find(s => s.key === k)?.label || this.active.dataset;
            },
            get rowCountLabel() {
                if (!this.table) return '';
                const shown = this.table.getDataCount('active');
                const total = this.data.rows.length;
                return shown === total ? `${total} rows` : `${shown} of ${total} rows`;
            },

            /* ---------- open / create / delete ---------- */
            async openSheet(id) {
                this.activeId = id;
                this.loading = true;
                const res = await fetch(`${cfg.dataUrl}/${id}/data`, { headers: { Accept: 'application/json' } });
                const payload = await res.json();
                this.loading = false;

                this.active = this.sheets.find(s => s.id === id);
                this.data = { columns: payload.columns, rows: payload.rows, lookups: payload.lookups || [] };
                this.columns = payload.columns;

                const c = payload.config || {};
                this.search = c.search || '';
                this.conditions = c.conditions || [];
                this._match = c.match || 'all';
                this.group = c.group || '';
                this.totals = !!c.totals;
                this.lookups = c.lookups || [];
                this.charts = c.charts || [];

                await this.$nextTick();
                this.rebuild();
            },

            openNewSheet() { this.form = { name: '', key: this.sources[0]?.key || '' }; this.formError = ''; this.modal = 'sheet'; },

            async createSheet() {
                this.formError = '';
                if (!this.form.name.trim()) { this.formError = 'Name is required.'; return; }
                const src = this.splitKey(this.form.key);
                const res = await fetch(cfg.store, {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json', Accept: 'application/json', 'X-CSRF-TOKEN': cfg.csrf },
                    body: JSON.stringify({ name: this.form.name.trim(), integration_id: src.integration_id, dataset: src.dataset }),
                });
                if (!res.ok) { this.formError = 'Could not create the sheet.'; return; }
                const sheet = await res.json();
                this.sheets.push(sheet);
                this.modal = null;
                this.openSheet(sheet.id);
            },

            async deleteSheet() {
                if (!this.active || !confirm('Delete this sheet? Your data is not affected.')) return;
                await fetch(`${cfg.dataUrl}/${this.activeId}`, { method: 'DELETE', headers: { 'X-CSRF-TOKEN': cfg.csrf } });
                this.sheets = this.sheets.filter(s => s.id !== this.activeId);
                this.destroyCharts();
                this.table?.destroy(); this.table = null;
                this.active = null; this.activeId = null;
                if (this.sheets.length) this.openSheet(this.sheets[0].id);
            },

            /* ---------- grid ---------- */
            rebuild() {
                this.table?.destroy();
                const el = document.getElementById('grid');
                if (!el) return;

                const cols = this.data.columns.map(col => {
                    const def = { title: col, field: col, headerFilter: 'input', headerFilterPlaceholder: '🔍', minWidth: 110 };
                    if (this.data.lookups.includes(col)) def.headerFilterPlaceholder = '🔗';
                    if (this.totals) def.bottomCalc = this.isNumeric(col) ? 'sum' : 'count';
                    return def;
                });

                this.table = new Tabulator(el, {
                    data: this.data.rows,
                    columns: cols,
                    layout: 'fitData',
                    height: '560px',
                    nestedFieldSeparator: false,
                    movableColumns: true,
                    pagination: true, paginationSize: 100, paginationSizeSelector: [50, 100, 250, 500],
                    placeholder: 'No rows',
                });

                this.table.on('tableBuilt', () => this.applyView());
                this.table.on('dataFiltered', () => this.drawCharts());
            },

            applyView() {
                if (!this.table) return;
                this.table.setFilter((row) => this.rowMatches(row));
                this.table.setGroupBy(this.group || false);
                this.drawCharts();
            },

            rowMatches(data) {
                const s = (this.search || '').trim().toLowerCase();
                if (s && !this.data.columns.some(c => String(data[c] ?? '').toLowerCase().includes(s))) return false;

                const conds = (this.conditions || []).filter(c => c.column && c.operator);
                if (!conds.length) return true;
                const res = conds.map(c => this.evalCond(data[c.column], c.operator, c.value));
                return this.matchMode === 'any' ? res.some(Boolean) : res.every(Boolean);
            },

            evalCond(raw, op, val) {
                const hay = String(raw ?? '').trim().toLowerCase();
                const needle = String(val ?? '').trim().toLowerCase();
                const n = parseFloat(String(raw).replace(/[^0-9.\-]/g, ''));
                const m = parseFloat(String(val).replace(/[^0-9.\-]/g, ''));
                switch (op) {
                    case 'eq': return hay === needle;
                    case 'neq': return hay !== needle;
                    case 'contains': return needle !== '' && hay.includes(needle);
                    case 'not_contains': return needle === '' || !hay.includes(needle);
                    case 'gt': return !isNaN(n) && !isNaN(m) && n > m;
                    case 'lt': return !isNaN(n) && !isNaN(m) && n < m;
                    case 'gte': return !isNaN(n) && !isNaN(m) && n >= m;
                    case 'lte': return !isNaN(n) && !isNaN(m) && n <= m;
                    case 'empty': return hay === '';
                    case 'not_empty': return hay !== '';
                    default: return true;
                }
            },

            /* ---------- filters modal ---------- */
            openFilters() {
                this.draft = JSON.parse(JSON.stringify(this.conditions.length ? this.conditions : [{ column: '', operator: 'contains', value: '' }]));
                this.draftMatch = this.matchMode;
                this.modal = 'filters';
            },
            applyFilters() {
                this.conditions = this.draft.filter(c => c.column && c.operator);
                this.matchMode = this.draftMatch;
                this.modal = null;
                this.applyView();
                this.persist();
            },
            opLabel(v) { return this.operators.find(o => o.v === v)?.t || v; },

            /* ---------- lookups (VLOOKUP) ---------- */
            openLookup() {
                this.lk = { name: '', key: this.sources[0]?.key || '', local_key: this.columns[0] || '', foreign_key: '', return_column: '' };
                this.lkError = '';
                this.modal = 'lookup';
            },
            async saveLookup() {
                this.lkError = '';
                const src = this.splitKey(this.lk.key);
                if (!this.lk.name.trim() || !this.lk.local_key || !this.lk.foreign_key || !this.lk.return_column) {
                    this.lkError = 'Fill in every field.'; return;
                }
                if (this.data.columns.includes(this.lk.name.trim())) { this.lkError = 'A column with that name already exists.'; return; }

                this.lookups.push({
                    name: this.lk.name.trim(), integration_id: src.integration_id, dataset: src.dataset,
                    local_key: this.lk.local_key, foreign_key: this.lk.foreign_key, return_column: this.lk.return_column,
                });
                this.modal = null;
                await this.persist();
                this.openSheet(this.activeId); // reload so the server resolves the new column
            },
            async removeLookup(i) {
                this.lookups.splice(i, 1);
                await this.persist();
                this.openSheet(this.activeId);
            },

            /* ---------- charts ---------- */
            isCategory(type) { return ['pie', 'doughnut', 'polarArea'].includes(type); },
            openChart() {
                this.ch = { title: '', type: 'bar', limit: 50, label_column: this.columns[0] || '', agg: 'count', value_column: '' };
                this.chError = '';
                this.modal = 'chart';
            },
            saveChart() {
                this.chError = '';
                if (!this.ch.title.trim() || !this.ch.label_column) { this.chError = 'Title and group-by column are required.'; return; }
                if (this.ch.agg !== 'count' && !this.ch.value_column) { this.chError = 'Pick a value column for that aggregate.'; return; }
                this.charts.push({ cid: 'c' + Date.now(), ...JSON.parse(JSON.stringify(this.ch)), title: this.ch.title.trim() });
                this.modal = null;
                this.persist();
                this.$nextTick(() => this.drawCharts());
            },
            removeChart(cid) {
                this.chartInstances[cid]?.destroy(); delete this.chartInstances[cid];
                this.charts = this.charts.filter(c => c.cid !== cid);
                this.persist();
            },
            destroyCharts() { Object.values(this.chartInstances).forEach(i => i.destroy()); this.chartInstances = {}; },

            drawCharts() {
                const rows = this.table ? this.table.getData('active') : this.data.rows;
                this.charts.forEach(ch => {
                    this.$nextTick(() => {
                        const el = document.getElementById('chart-' + ch.cid);
                        if (!el) return;
                        const { labels, values } = this.aggregate(rows, ch);
                        const palette = ['#4FE3A6', '#EE9F4E', '#E2694F', '#7FA396', '#2E9E76', '#C97A2A', '#8FBFAE', '#D98A6F'];
                        const perSlice = this.isCategory(ch.type);
                        this.chartInstances[ch.cid]?.destroy();
                        this.chartInstances[ch.cid] = new Chart(el, {
                            type: ch.type === 'horizontalBar' ? 'bar' : ch.type,
                            data: {
                                labels,
                                datasets: [{
                                    label: ch.agg === 'count' ? 'Count' : `${ch.agg}(${ch.value_column})`,
                                    data: values,
                                    backgroundColor: perSlice ? labels.map((_, i) => palette[i % palette.length]) : palette[0],
                                    borderColor: perSlice ? '#fff' : palette[0], borderWidth: perSlice ? 2 : 1,
                                }],
                            },
                            options: {
                                responsive: true, maintainAspectRatio: false,
                                indexAxis: ch.type === 'horizontalBar' ? 'y' : 'x',
                                plugins: { legend: { position: 'bottom', labels: { boxWidth: 10, font: { size: 11 } } } },
                                scales: perSlice ? {} : { y: { beginAtZero: true } },
                            },
                        });
                    });
                });
            },

            aggregate(rows, ch) {
                const buckets = {};
                rows.forEach(r => {
                    const label = String(r[ch.label_column] ?? '').trim() || 'Unspecified';
                    (buckets[label] ??= []).push(ch.agg === 'count' ? 1 : this.numeric(r[ch.value_column]));
                });
                const reduced = Object.entries(buckets).map(([label, vals]) => {
                    const nums = vals.filter(v => v !== null);
                    let v;
                    switch (ch.agg) {
                        case 'sum': v = nums.reduce((a, b) => a + b, 0); break;
                        case 'avg': v = nums.length ? nums.reduce((a, b) => a + b, 0) / nums.length : 0; break;
                        case 'min': v = nums.length ? Math.min(...nums) : 0; break;
                        case 'max': v = nums.length ? Math.max(...nums) : 0; break;
                        default: v = nums.length;
                    }
                    return [label, Math.round(v * 100) / 100];
                });
                reduced.sort((a, b) => b[1] - a[1]);
                const top = reduced.slice(0, ch.limit || 10);
                return { labels: top.map(r => r[0]), values: top.map(r => r[1]) };
            },

            /* ---------- helpers ---------- */
            isNumeric(col) {
                let seen = 0, num = 0;
                for (let i = 0; i < this.data.rows.length && seen < 40; i++) {
                    const v = this.data.rows[i][col];
                    if (v === '' || v == null) continue;
                    seen++;
                    if (/[0-9]/.test(String(v)) && !isNaN(parseFloat(String(v).replace(/[^0-9.\-]/g, '')))) num++;
                }
                return seen > 0 && num / seen > 0.7;
            },
            numeric(v) {
                if (typeof v === 'number') return v;
                const clean = String(v ?? '').replace(/[^0-9.\-]/g, '');
                return clean === '' || clean === '-' ? null : parseFloat(clean);
            },
            exportCsv() { this.table?.download('csv', (this.active?.name || 'sheet') + '.csv'); },

            // matchMode lives on the active config; keep a convenient accessor.
            get matchMode() { return this._match || (this.active?.config?.match) || 'all'; },
            set matchMode(v) { this._match = v; },

            async persist() {
                if (!this.activeId) return;
                const config = {
                    search: this.search, match: this.matchMode, conditions: this.conditions,
                    group: this.group, totals: this.totals, lookups: this.lookups, charts: this.charts,
                };
                if (this.active) this.active.config = config;
                await fetch(`${cfg.dataUrl}/${this.activeId}`, {
                    method: 'PUT',
                    headers: { 'Content-Type': 'application/json', Accept: 'application/json', 'X-CSRF-TOKEN': cfg.csrf },
                    body: JSON.stringify({ config }),
                });
            },
        };
    }
    </script>
    @endpush
</x-app-layout>
