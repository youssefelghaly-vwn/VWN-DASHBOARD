# VWN Dashboard — Architecture & Page Map

A page-by-page map of how the app works: for every screen and every JSON
endpoint, the exact chain of controllers → services → models it runs through,
and where the external APIs (GoHighLevel, Google Sheets, Meta) are actually
called.

If you only read one thing, read **[The one big idea](#the-one-big-idea)** and
**[The sync engine](#the-sync-engine-how-external-data-becomes-local-rows)**.

---

## The one big idea

There are **two completely separate halves**, and they only ever meet in one
database table:

```
                       ┌─────────────────────────────────────────┐
  EXTERNAL APIs        │  WRITE SIDE  (slow, background, per-API)  │
  GoHighLevel  ──────► │  Provider.sync()  ──►  SyncContext.write()│──┐
  Google Sheets ─────► │  (GHL / Sheets / Meta)                    │  │
  Meta Ads ──────────► │                                           │  │
                       └───────────────────────────────────────────┘  │
                                                                       ▼
                                                        ┌────────────────────────────┐
                                                        │   integration_records       │
                                                        │  (integration_id, dataset,  │
                                                        │   payload JSON, ...)         │
                                                        └────────────────────────────┘
                                                                       │
                       ┌───────────────────────────────────────────┐  │
  DASHBOARD UI         │  READ SIDE  (fast, in-request, generic)    │  │
  charts / metrics ◄── │  RecordReader.rows()  ◄────────────────────│──┘
  tables / pickers     │  DashboardData / MetricService aggregate   │
                       └───────────────────────────────────────────┘
```

* **Write side** — each provider knows how to talk to *one* external API and
  reshape its responses into flat rows. It runs in a **queued background job**
  (`SyncIntegrationJob`) because a full pull can take minutes. It is the *only*
  code that makes outbound API calls.
* **Read side** — dashboards, charts, metrics, and tables **never** call an
  external API. They read the local `integration_records` rows through
  `RecordReader` and aggregate them. This is why the UI is fast and can freely
  combine data from several integrations in one chart.

The unit of data is a **dataset**: a named bucket of rows for one integration
(e.g. GHL `Opportunities`, GHL `Pipeline Stages`, a Google Sheet tab like
`Apply and Book Form Leads`, Meta `Performance`).

---

## Module layout (`app/`)

The app is organised by **feature module**, not by Laravel's default
Controllers/Models split:

| Path | Responsibility |
|------|----------------|
| `app/Integration/` | Connecting to external APIs, syncing, and reading local rows. |
| `app/Dashboard/` | Dashboards, charts, and the data that feeds them. |
| `app/Metric/` | The single-number metric engine (KPI tiles). |
| `app/DataHealth/` | Sync status / freshness view. |
| `app/Menu/` | Admin-managed sidebar menu. |
| `app/Support/` | Shared traits: `CastsValues`, `FiltersRows`. |
| `app/Http/` | Auth (Breeze), profile, `EnsureUserIsAdmin` middleware. |

### Key classes at a glance

| Class | Role |
|-------|------|
| `Integration\Models\Integration` | One connection (one GHL location / one Sheet / one Meta owner). Holds `provider`, encrypted `credentials`, and non-secret `config`. |
| `Integration\Models\IntegrationRecord` | One synced row: `integration_id`, `dataset`, `payload` (JSON). |
| `Integration\Models\SyncRun` | One sync attempt + its per-dataset counts/errors. |
| `Integration\Services\IntegrationManager` | Maps a provider key → provider instance (the whole "plugin" system; see `config/integrations.php`). |
| `Integration\Services\SyncService` | Orchestrates a sync: open `SyncRun` → `provider.sync()` → close run. |
| `Integration\Services\SyncContext` | The **only** thing that writes rows to the DB. Accumulates counts/errors. |
| `Integration\Services\RecordReader` | The **only** thing the read side uses to fetch rows. Memoised per request. |
| `Integration\Providers\*` | One class per external API: `GoHighLevelProvider`, `GoogleSheetsProvider`, `MetaAdsProvider`. |
| `Integration\Providers\Ghl\GhlClient` | Thin HTTP layer over the GHL v2 API (auth, retry, pagination). |
| `Dashboard\Services\DashboardData` | Turns a saved `Chart` into a Chart.js payload (labels + datasets). |
| `Metric\Services\MetricService` | Computes a metric's single number (simple aggregate or safe formula). |

---

## Routing & access control

All admin routes live in `routes/web.php` behind `['auth', 'admin']`
(`EnsureUserIsAdmin`) and the `admin.` name prefix. `/dashboard` redirects to
`admin.dashboard` to keep Breeze's redirects working.

> **Note:** `resources/views/dashboard/index.blade.php` is a **legacy** view
> from the original Google-Sheets-only dashboard (it references
> `route('admin.google.index')`, which no longer exists). The live dashboard is
> `resources/views/admin/dashboard.blade.php`. Treat `dashboard/index.blade.php`
> as dead code unless it is re-wired.

---

## Page map — screens and their call pipelines

Each entry lists the **route**, the **controller action**, and the **downstream
pipeline** of method/service calls.

### 1. Dashboard (view a dashboard)

**`GET /` → `GET /dashboards/{slug}`** · `DashboardController@index` / `@show`

```
DashboardController@show(Dashboard $dashboard)
 ├─ RecordReader@schema()                        // builder's source/column lists
 │   └─ foreach connected Integration:
 │        └─ Integration->provider()->schema($integration)
 │             └─ (per provider) reads Integration->rows($dataset) column keys
 └─ view('admin.dashboard', dashboard, schema, charts)
      // charts + metrics are then hydrated client-side (Alpine) via the
      // JSON endpoints below.
```

The Blade renders empty chart/metric shells; **Alpine.js** (inlined in
`admin/dashboard.blade.php`) then calls the JSON endpoints and draws Chart.js.

### 2. Chart data (draw all charts on a dashboard)

**`GET /dashboards/{slug}/charts/data`** · `DashboardController@chartData`

```
DashboardController@chartData(Dashboard $dashboard)
 └─ $dashboard->charts->map(fn Chart $c => DashboardData@buildChart($c))
      DashboardData@buildChart(Chart $c)
       ├─ foreach $c->series as $s:
       │    ├─ RecordReader@rows($s.integration_id, $s.dataset)   // local rows
       │    ├─ FiltersRows@filterRows(rows, chart.filters + series.filters)
       │    └─ DashboardData@aggregate(rows, label_column, column, agg, LIMIT)
       │         // buckets rows by label_column, reduces each bucket by agg,
       │         // arsort(), then array_slice(…, LIMIT)  ← top-N truncation
       ├─ (pie/doughnut/polarArea) recolour one dataset, one colour per slice
       └─ returns { labels, datasets, type, indexAxis, config, … }
```

**Important behaviour — the "top-N" limit.** `aggregate()` sorts buckets
largest-first and keeps only the first `chart.limit` of them. For bar/line
charts that's the intended "top 10". For **pie/doughnut/polar** charts it means
a small limit *hides categories*: a Status pie with `limit=10` shows only the 10
biggest statuses. The builder now defaults category charts to the max (50) and
labels the field **"Max slices"** so every status/stage is drawn. See
[Charts & metrics internals](#charts--metrics-internals).

### 3. Create / update / delete a chart

**`POST /dashboards/{dashboard}/charts`**, **`PUT /charts/{chart}`**,
**`DELETE /charts/{chart}`** · `ChartController@store/@update/@destroy`

```
ChartController@store(Request, Dashboard, DashboardData)
 ├─ validate(title, type, sheet, label_column, aggregate, limit 1..50, series[], filters[])
 ├─ Chart::create(validated + user_id + dashboard_id + position)
 └─ return DashboardData@buildChart($chart)         // immediate preview payload
```

### 4. Raw Rows table + the Pipeline/Stage picker

**`GET /table/data`** · `DashboardController@tableData`

```
DashboardController@tableData(integration_id, dataset)
 ├─ RecordReader@columns(integration_id, dataset)
 └─ RecordReader@rows(integration_id, dataset)
```

**`GET /table/distinct`** · `DashboardController@distinct` — powers the
cascading **Pipeline → Stage** picker in the chart builder.

```
DashboardController@distinct(integration_id, dataset, column, filters?)
 ├─ RecordReader@rows(integration_id, dataset)
 ├─ narrow rows by each {column,value} scope   // e.g. Pipeline = "Outbound SDR"
 └─ unique, non-empty, sorted values of `column`
```

The builder reads pipeline options from the **`Pipeline Stages`** dataset (the
full GHL catalogue, synced independently of opportunity counts), not from the
`Opportunities` rows — so empty pipelines still appear. See
`pipelineOptions()` / `stageOptions()` in `admin/dashboard.blade.php`.

### 5. Metrics (KPI tiles)

**`GET /dashboards/{slug}/metrics`** · `MetricController@index` →
`MetricService@build` per metric.
**`POST /metrics/preview`**, **`POST …/metrics`**, **`PUT /metrics/{metric}`**,
**`DELETE …`** · create/preview/update/delete.

```
MetricService@build(Metric $m)
 ├─ mode = 'formula' ? evaluateFormula() : computeSimple(simpleConfig())
 │    computeSimple(cfg)
 │     ├─ RecordReader@rows(cfg.integration_id, cfg.sheet)
 │     ├─ applyFilter(rows, cfg)      // FiltersRows + legacy single filter
 │     └─ reduce by agg: count / count_if / percent_if / sum / avg / min / max
 │    evaluateFormula(m)
 │     ├─ resolve each {var} by computeSimple(var cfg)
 │     └─ evaluateArithmetic()        // safe shunting-yard, digits & + - * / ( ) only
 └─ returns { value, display, error, config }
```

### 6. Integrations (connect / sync / edit / disconnect)

**`GET /integrations`** · `IntegrationController@index` — lists integrations +
provider catalogue.

**`POST /integrations`** · `@store` (connect a new one):

```
IntegrationController@store
 ├─ IntegrationManager@get(provider)          // provider instance
 ├─ Provider@connect(integration, credentials)
 │    // validates creds with a LIVE call, then saves the Integration row
 └─ SyncIntegrationJob::dispatch(id)          // first sync runs in background
```

**`POST /integrations/{integration}/sync`** · `@sync` — dispatches
`SyncIntegrationJob` (see next section).
**`PUT /integrations/{integration}`** · `@update` — re-runs `connect()` on the
same row; blank fields keep their existing values (token rotation).
**`DELETE …`** · `@destroy` — `provider.disconnect()` then delete locally.

### 7. Data Health

**`GET /data-health`** · `DataHealthController@index` →
`DataHealthService` (reads latest `SyncRun` per integration + per-dataset meta).
**`POST /data-health/{integration}/sync`** — same `SyncIntegrationJob` dispatch.

### 8. Menu management

**`GET/POST/DELETE /menu`** · `MenuController` → `MenuBuilder` /
`MenuItem` model. Admin-managed sidebar entries.

---

## The sync engine — how external data becomes local rows

Every "Sync" button and the first connect go through **one** job and **one**
service, regardless of provider:

```
SyncIntegrationJob::dispatch(id)      // queued; timeout 600s, tries 1, unique per integration
 └─ SyncService@run(Integration)
     ├─ create SyncRun(status = RUNNING)
     ├─ context = new SyncContext(integration)
     ├─ Integration->provider()->sync(integration, context)   // ← provider-specific
     │     └─ (provider fetches each dataset and calls context->write(dataset, rows))
     ├─ status = context.failedRecords() > 0 ? PARTIAL : SUCCESS   (or FAILED on throw)
     └─ update SyncRun(counts, errors, per-dataset meta) + Integration(last_synced_at, status)
```

`SyncContext@write($dataset, $rows)` is the single DB writer: it **replaces**
the dataset transactionally (`delete` then chunked `insert` of 500) and records
`{ok, count, ms, error}` for that dataset. `SyncContext@fail($dataset, $msg)`
records a dataset failure **without** aborting the whole sync — so one broken
dataset degrades to "0 rows + last error" instead of killing the run.

---

## GoHighLevel provider — datasets & the GHL specifics

`GoHighLevelProvider@sync()` pulls up to four selected datasets. Order and
dependencies:

```
sync(integration, context)
 ├─ users      = fetchUsers()             (also used to resolve assignee names)
 ├─ pipelines  = fetchPipelines()         GET /opportunities/pipelines?locationId=…
 ├─ cfMap      = fetchCustomFieldMap()    GET /locations/{id}/customFields?model=contact|opportunity
 │                                        (one call per needed model, merged → both contact and
 │                                         opportunity custom-field ids resolve to column names)
 │
 ├─ if Opportunities:
 │     ├─ context.write('Pipeline Stages', pipelineStageRows(pipelines))   ← written FIRST
 │     │     // every stage of every pipeline, even empty ones → drives the picker
 │     └─ context.write('Opportunities', fetchOpportunities(...))
 │           └─ GhlClient@searchOpportunities()   GET /opportunities/search
 ├─ if Contacts:      context.write('Contacts', fetchContacts(...))   (incremental, see below)
 ├─ if Appointments:  context.write('Appointments', fetchAppointments(...))
 └─ if Users:         context.write('Users', users)
```

### `GhlClient` — HTTP, auth, pagination

* **Auth:** `Authorization: Bearer <private-integration-token>` +
  `Version: 2021-07-28`. Token is long-lived; no refresh.
* **`get()` / `post()`:** one retry on HTTP 429 (respecting `Retry-After`);
  connection errors retried with exponential backoff by `send()`.
* **`decode()`:** 401 → a scope-hint exception; other failures → error with the
  GHL message.

Three ways data is paged, matched to how each GHL endpoint behaves:

| Method | Used for | Pagination strategy |
|--------|----------|---------------------|
| `paginate()` | Users, calendar events | Generic: trusts the response cursor (`meta.startAfterId` / `meta.nextPageUrl`) over a page-size heuristic. |
| `searchContacts()` | Contacts | POST `/contacts/search`, `searchAfter` cursor from the last row, sorted `dateUpdated desc`; **incremental** via a watermark + cached snapshot. |
| `searchOpportunities()` | Opportunities | GET `/opportunities/search`; loops to exhaustion using `meta.total` as the completion signal, cursor from `meta.startAfterId`/`startAfter` (falling back to the last row's id, then page-number paging), deduped by opportunity id. |

**Why opportunities has its own method (the "only 20" bug).** GHL's
`/opportunities/search` returns a small first page (≈20) and expects you to
follow its cursor. Relying on a generic size heuristic truncated the sync to
that first page. `searchOpportunities()` mirrors the contacts approach —
loop-until-complete with a hard `meta.total` stop and id dedup — so the whole
location syncs. `Pipeline Stages` is written **before** opportunities so the
pipeline pickers populate even if the (potentially large) opportunity pull is
slow or throttled.

### Contacts are incremental

`fetchContacts()` keeps a raw-contact snapshot in the cache
(`integration:{id}:ghl_contacts_raw`, 7-day TTL) and a `contacts_watermark` in
the integration config. Only the first sync walks the whole list; later syncs
pull just the delta (contacts updated after the watermark) and merge it over the
snapshot.

---

## Google Sheets provider

`GoogleSheetsProvider` reads a **published Apps Script** endpoint that returns
`{ "TabName": [ {col: val, …}, … ], … }`. Each tab becomes a dataset written
verbatim (no incremental logic). `connect()` validates the URL shape
(`https://script.google.com/…`) and the tab list, then fetches once to confirm.

---

## Meta Ads provider

`MetaAdsProvider` (+ `Meta\MetaClient`) reads the Meta Marketing/Graph API for
ad performance insights (`insights_days_back`, default 90). Written as a
`Performance` dataset. Same read/write split as the others.

---

## Charts & metrics internals

### Aggregation (`DashboardData@aggregate`)

```
aggregate(rows, labelColumn, valueColumn?, agg='count', limit=10)
 ├─ bucket rows by trimmed labelColumn value ('' → 'Unspecified')
 ├─ reduce each bucket: count | sum | avg | min | max   (numeric() cleans strings like "$1,200")
 ├─ arsort() (largest first)
 └─ array_slice(…, limit)     ← keeps only the top `limit` buckets
```

* **Chart type → Chart.js mapping** lives in `DashboardData::TYPE_MAP`
  (`bar`, `horizontalBar`, `stackedBar`, `line`, `area`, `pie`, `doughnut`,
  `polarArea`, `radar`, `scatter`, `bubble`).
* **Multi-series charts:** the first series defines the label set; later series
  are re-aligned to those labels (missing → 0). This is what lets one chart mix
  integrations.
* **Category charts (pie/doughnut/polar):** because each distinct value is one
  slice, the `limit` is effectively a **max-slices** cap. The builder now
  defaults these to 50 and shows a "Max slices" hint (`isCategoryChart()` /
  `onTypeChange()` in `admin/dashboard.blade.php`), so a Status/Stage pie draws
  every category instead of the top few.

### Metric formula evaluator (`MetricService`)

`evaluateArithmetic()` is a hand-rolled shunting-yard parser locked to digits
and `+ - * / ( )` — no `eval`, no functions — so user formulas are safe. `{var}`
placeholders are resolved to numbers via `computeSimple()` before arithmetic.

---

## Data model (tables)

| Table | Purpose | Key columns |
|-------|---------|-------------|
| `integrations` | One connection. | `provider`, `name`, `credentials` (encrypted), `config` (JSON), `status`, `last_synced_at` |
| `integration_records` | Synced rows (the read side's whole world). | `integration_id`, `dataset`, `external_id`, `payload` (JSON), `record_date` |
| `sync_runs` | One sync attempt. | `status`, `records_synced`, `failed_records`, `last_error`, `meta` (per-dataset) |
| `dashboards` | A dashboard. | `slug`, `name`, `is_default`, `position` |
| `dashboard_sections` | A titled divider that groups + nests widgets (one level deep). | `dashboard_id`, `parent_id` (self-ref, null = top level), `title`, `position` |
| `charts` | A chart widget. | `dashboard_id`, `section_id` (null = ungrouped), `type`, `sheet` (dataset), `label_column`, `series` (JSON), `filters` (JSON), `aggregate`, `limit`, `width` (full/twothirds/half/third), `height` (px), `position` |
| `metrics` | A KPI tile. | `mode`, `integration_id`, `section_id`, `sheet`, `agg`, `column`, `filters`, `expression`, `variables`, `format`, `position` |
| `menu_items` | Sidebar entries. | label, route/url, position |

---

## Adding a new integration (the extension point)

1. Write a provider implementing `Integration\Providers\IntegrationProvider`
   (`key`, `label`, `connect`, `disconnect`, `sync`, `schema`).
2. Add one line to `config/integrations.php` `providers` map.

Nothing else changes: `IntegrationManager` resolves it, `SyncIntegrationJob`
syncs it, `RecordReader` reads it, and dashboards/metrics aggregate it — all
provider-agnostic.
