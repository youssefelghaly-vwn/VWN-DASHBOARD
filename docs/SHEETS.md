# Sheets — read-only Excel-style workspace

A spreadsheet-like view over your **already-synced** data. Open any integration
dataset as a tab, then filter, look up, total, and chart it — like Excel / Google
Sheets, but **read-only**: nothing here ever edits, deletes, or writes back to
your source data.

## What you can do

- **Tabs** — each "sheet" is a tab bound to one dataset from any integration.
  Create as many as you like; they're saved server-side and persist across
  sessions.
- **Filter** — a visual condition builder (column · operator · value, matched by
  **ALL** = AND or **ANY** = OR) plus per-column header filters and a global
  search box. No formulas to learn.
- **Group & total** — group rows by any column and toggle column totals
  (sum for numeric columns, count otherwise).
- **Lookups (VLOOKUP)** — add a column that pulls a matching value from *another*
  integration's table by a shared key — a real cross-dataset join.
- **Charts** — build bar / line / pie / doughnut charts inside the sheet; they
  reflect the current filters. (Pie/doughnut default to showing every category.)
- **Export** — download the current (filtered) view as CSV.

## How it's built

Third-party libraries, loaded via CDN in the page head (same pattern as the
dashboard's Chart.js):

- **[Tabulator](http://tabulator.info) 6.x** (MIT) — the grid: sorting, header
  filters, grouping, column calcs, CSV export, virtual rendering for large sets.
- **Chart.js 4.x** — in-sheet charts (already used elsewhere in the app).

### Data flow

```
GET /sheets                     SheetController@index   → renders admin/sheets.blade.php
                                                          (+ RecordReader@schema for the source picker)
POST /sheets                    @store                  → create a tab (name, integration_id, dataset)
GET  /sheets/{sheet}/data       @data                   → SheetData@payload(sheet)
        └─ RecordReader@rows(integration_id, dataset)   → base rows (local only)
        └─ resolves each config.lookups[] by indexing   → adds VLOOKUP columns
           the foreign dataset on its key
PUT  /sheets/{sheet}            @update                 → persist view state (config JSON)
DELETE /sheets/{sheet}          @destroy                → remove the tab (source data untouched)
```

**Server does:** assemble base rows + resolve lookup columns (the one operation
that must join data). **Browser does:** all filtering, sorting, grouping,
totals, and charting over those rows (Tabulator + Chart.js), so the experience
is instant and the server stays simple.

### Persistence

Everything a sheet remembers lives in `sheets.config` (JSON):

```jsonc
{
  "search": "",                 // global search term
  "match": "all",               // "all" (AND) | "any" (OR)
  "conditions": [ { "column": "Status", "operator": "eq", "value": "Booked" } ],
  "group": "Status",            // group-by column ('' = none)
  "totals": true,               // show column totals
  "lookups": [ {                // VLOOKUP columns (resolved server-side)
    "name": "Owner email", "integration_id": 3, "dataset": "Users",
    "local_key": "Assigned User", "foreign_key": "Name", "return_column": "Email"
  } ],
  "charts": [ {                 // in-sheet charts (computed client-side)
    "cid": "c…", "title": "By status", "type": "pie",
    "label_column": "Status", "agg": "count", "value_column": "", "limit": 50
  } ]
}
```

### Model / files

| File | Role |
|------|------|
| `app/Sheet/Models/Sheet.php` | One tab (`name`, `integration_id`, `dataset`, `config`). |
| `app/Sheet/Services/SheetData.php` | Base rows + VLOOKUP resolution via `RecordReader`. |
| `app/Sheet/Controllers/SheetController.php` | index / store / data / update / destroy. |
| `resources/views/admin/sheets.blade.php` | The workspace UI (Alpine + Tabulator + Chart.js). |
| `database/migrations/*_create_sheets_table.php` | `sheets` table. |

It reuses the same read side as dashboards (`RecordReader`) and the same
source/column shape the chart builder uses, so any dataset that appears in a
chart is available as a sheet.
