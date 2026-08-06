# Building the Executive & SDR Dashboards — step by step

A click-by-click guide for building the **Executive (Company)** and **SDR
(per-rep)** dashboards out of the GoHighLevel data the app syncs, using the
metric and chart builders. No code required — everything here is done in the UI.

It assumes the recent changes that make GHL **Opportunity custom fields**
(e.g. *Outreach Stages*), the **Owner**, and **multi-value “has any / has all”
conditions** available to the builders. If those columns don’t appear yet,
start at [Step 0](#step-0--re-sync-so-the-new-columns-appear).

---

## Table of contents

1. [Step 0 — re-sync so the new columns appear](#step-0--re-sync-so-the-new-columns-appear)
2. [What data you now have](#what-data-you-now-have)
3. [The 5 metric recipes you’ll reuse](#the-5-metric-recipes-youll-reuse)
4. [How to find the exact option labels](#how-to-find-the-exact-option-labels)
5. [Executive (Company) dashboard — every KPI](#executive-company-dashboard--every-kpi)
6. [SDR dashboard (per rep) — every KPI](#sdr-dashboard-per-rep--every-kpi)
7. [Charts to add](#charts-to-add)
8. [KPIs that need data we don’t sync yet](#kpis-that-need-data-we-dont-sync-yet)

---

## Step 0 — re-sync so the new columns appear

The builder dropdowns are built from **columns that exist in already-synced
rows**. Custom fields and Owner only show up after a fresh sync.

1. Left sidebar → **Data Health** (or **Integrations**).
2. Find **GoHighLevel — VWN Sales + CD**.
3. Click **Sync** and wait until it finishes (status returns to *success*).
4. Re-open a builder. In any **Filter column** / **Group-by column** dropdown for
   the *Opportunities* source you should now see **Owner**, **Company**,
   **Email**, **Phone**, **Contact Tags**, and every custom field such as
   **Outreach Stages**, **Responded-on**, **LinkedIn URL**, etc.

> Multi-value custom fields (e.g. *Outreach Stages* = `1st Email, 1st Linked-IN`)
> are stored as a **comma-separated list**. Match them with the new
> **has any / has all / has none** conditions.

---

## What data you now have

**Source: `GoHighLevel — VWN Sales + CD · Opportunities`**

| Column | Meaning |
|--------|---------|
| Pipeline | Which pipeline the opportunity lives in |
| Stage | Current kanban stage (e.g. *Replied/Connected*, *Appointment Booked*) |
| Status | `Open` / `Won` / `Lost` / `Abandoned` |
| Monetary Value | Deal value |
| **Owner** | The assigned SDR (same value as *Assigned User*) |
| Contact / Company / Email / Phone | From the linked contact |
| Contact Tags | Comma-separated tags on the contact |
| Source, Created, Updated | Standard fields |
| **Outreach Stages** | Multi-value: which outreach steps were done — `1st Email`, `1st Linked-IN`, `1st Call`, `1st SMS`, LinkedIn follow-ups, … |
| **Responded-on** | Multi-value: channel the lead replied on — `Call`, `SMS`, … |
| *(other custom fields)* | Whatever exists in your GHL location |

Other sources you can also use: **`… · Appointments`** (Calendar, Status,
Assigned User, Contact, Start, End) and **`… · Contacts`**.

> The exact **Outreach Stages** option labels are defined in *your* GHL
> picklist. This guide uses the ones visible in the data (`1st Email`,
> `1st Linked-IN`, `1st Call`, `1st SMS`). Wherever a KPI needs a step like
> *“LinkedIn Follow-up #1”*, substitute the **exact label** from your picklist
> (see [How to find the exact option labels](#how-to-find-the-exact-option-labels)).

---

## The 5 metric recipes you’ll reuse

Open the metric builder with **+ New metric** (top-right of the metrics row).
Every KPI below is one of these five shapes.

### Recipe A — a total (count of rows)
- **Measure:** `Count rows`
- Optionally scope with the **Pipeline** picker or a condition.
- **Format:** Number

### Recipe B — a filtered count (“count where …”)
- **Measure:** `Count where…`
- **Filter column / Condition / Value:** e.g. `Status` `equals` `Won`
- For multi-value fields use `has any of` / `has all of` and type a
  **comma-separated** value, e.g. `Outreach Stages` `has any of` `1st Call`.
- **Format:** Number

### Recipe C — a percentage of the whole dataset (“% of rows where …”)
- **Measure:** `% of rows where…`
- Set the condition (numerator). **Denominator = every row in the source.**
- **Format:** Percent
- Use this only when the denominator really is *all* rows of that source. When
  the denominator is a **subset** (e.g. shows ÷ booked), use Recipe D instead.

### Recipe D — a rate between two counts (Formula)
- Click the **Formula** toggle.
- Add a **variable** per number. Each variable has its **own** Source, Measure
  and conditions — build them exactly like Recipe A/B.
- **Expression:** e.g. `{held} / {booked} * 100`
- **Format:** Percent. (Division by zero safely returns 0.)

### Recipe E — a sum/average of a number column
- **Measure:** `Sum of column` (or Average/Min/Max)
- **Value column:** e.g. `Monetary Value`
- **Format:** Currency or Number

### Combining conditions (Owner **and** Outreach Stage)
Below the single Filter row there is **“Extra conditions (all must match)”**.
Click **+ Add condition** to AND more rules. This is how a per-SDR KPI filters
by **Owner = <rep>** *and* **Outreach Stages has any 1st Call** at the same time.
(The same **Conditions** editor now exists on the **chart** builder too.)

Always click **↻ Test** to see the live preview number before **Save**.

---

## How to find the exact option labels

Multi-value fields only match if you type the label exactly (matching is
case-insensitive but the words must match). Two easy ways to read the real
labels:

- **Quick pie chart:** **+ New chart** → Type *Doughnut* → Group-by column
  **Outreach Stages** → Save. Each slice label is a real value combination.
- **Raw rows table:** sidebar → **Sheets** (or the Rows table) → pick the
  *Opportunities* dataset and read the **Outreach Stages** / **Stage** columns.

Write the labels down; you’ll paste them into the condition **Value** boxes.

---

## Executive (Company) dashboard — every KPI

Create/choose a dashboard (**+ New dashboard** → “Executive”). Then add each
tile with **+ New metric**. The **Source** for all of these is
`GoHighLevel — VWN Sales + CD · Opportunities` unless noted.

> **Wording note:** “Contacted”, “Conversations”, “Qualified”, etc. must map to
> *something in your data*. The mapping below uses the most reliable available
> signal and flags any proxy. Adjust a Stage/label to match your pipeline’s
> naming.

| # | KPI | Recipe | Exact settings |
|---|-----|--------|----------------|
| 1 | **Total Leads** | A | `Count rows`. (Optional: Pipeline = *Linked In Campaign Pipeline*.) Format Number. |
| 2 | **Leads Contacted** | B | `Count where…` · `Outreach Stages` `is not empty`. |
| 3 | **Contact Rate %** | D | `{contacted}/{leads}*100`. `contacted` = *count_if* `Outreach Stages` `is not empty`; `leads` = *count*. Percent. |
| 4 | **Total Outreach Activities** | D | `{calls}+{sms}+{li}` where each is *count_if* `Outreach Stages` `has any of` the channel’s step labels (e.g. calls = `1st Call`). Number. **Proxy — counts leads reached per channel, not raw dial/message volume** (see [caveats](#kpis-that-need-data-we-dont-sync-yet)). |
| 5 | **Total Conversations** | B | `Count where…` · `Responded-on` `is not empty`. *(Alt: `Stage` `equals` `Replied/Connected`.)* |
| 6 | **Appointments Booked** | B | `Count where…` · `Stage` `contains` `Appointment`. *(Alt source: `… · Appointments`, `Status` `is not empty`.)* |
| 7 | **Conversation Rate %** | D | `{conversations}/{contacted}*100`. Percent. |
| 8 | **Booking Rate %** | D | `{booked}/{conversations}*100`. Percent. |
| 9 | **Meetings Held** | B | Source `… · Appointments`. `Count where…` · `Status` `equals` `Showed`. |
| 10 | **Show Rate %** | D | `{held}/{booked}*100`. Percent. |
| 11 | **Qualified Leads** | B | `Count where…` · `Stage` `equals` *your qualified stage* (e.g. `Qualified`). *(Alt: `Contact Tags` `has any of` `qualified`.)* |
| 12 | **Opportunity Rate %** | D | `{qualified}/{leads}*100`. Percent. |
| 13 | **Closed Deals** | B | `Count where…` · `Status` `equals` `Won`. |
| 14 | **Close Rate %** | D | `{won}/{qualified}*100` *(or `/{leads}`)*. Percent. |
| 15 | **Lost Leads** | B | `Count where…` · `Status` `has any of` `Lost, Abandoned`. |

### Worked example — “Contact Rate %” (Recipe D)

1. **+ New metric** → Title `Contact Rate %` → subtitle `contacted ÷ leads`.
2. Click **Formula**.
3. Variable **1**: name `contacted` → Source *… · Opportunities* → Measure
   `Count where…` → Filter column `Outreach Stages` → Condition `is not empty`.
4. **+ Add variable** → name `leads` → same Source → Measure `Count rows`.
5. **Expression:** `{contacted} / {leads} * 100`.
6. **Format** = Percent, **Decimals** = 1 → **↻ Test** → **Save**.

### Worked example — “Lost Leads” with a multi-value condition (Recipe B)

1. **+ New metric** → Title `Lost Leads`.
2. Keep **Simple** → Measure `Count where…`.
3. Filter column `Status` → Condition `has any of` → Value `Lost, Abandoned`.
4. Format Number → **↻ Test** → **Save**. (Counts rows whose Status is *either*.)

---

## SDR dashboard (per rep) — every KPI

Every SDR KPI is an Executive KPI **plus one condition: `Owner equals <rep>`**.
Add it in **Extra conditions → + Add condition** (Simple metrics) or as an extra
**variable condition** (Formula metrics).

You have two ways to present “per SDR”:

### Option 1 — one dashboard per SDR (KPI tiles)
Best when you want a rep’s full scorecard on one screen.
1. **+ New dashboard** → name it e.g. `SDR — Zainab`.
2. Rebuild the tiles below; on **each** add **Owner** `equals` `Zainab Makarfi`.
   - Tip: build them once, then use each metric’s **Configure → Save** on the
     rep’s dashboard, changing only the Owner value.

### Option 2 — compare all SDRs at once (charts grouped by Owner)
Best for leaderboards. See [Charts to add](#charts-to-add) — a bar chart with
**Group-by column = Owner** and a **Condition** for the activity draws one bar
per rep.

### SDR — Activity

| KPI | Recipe | Settings (Source: *Opportunities*, + `Owner equals <rep>`) |
|-----|--------|-----------------------------------------------------------|
| Total Activities | B | `Count where…` `Outreach Stages` `is not empty`. *(Proxy.)* |
| Calls Made | B | `Outreach Stages` `has any of` `1st Call` *(+ any call-step labels)*. *(Proxy — leads reached by call.)* |
| Calls Answered | — | **Needs dialer data** — not in GHL opportunities. See [caveats](#kpis-that-need-data-we-dont-sync-yet). |
| Answer Rate % | — | **Needs dialer data.** |
| Total call duration | — | **Needs dialer data.** |
| SMS Sent | B | `Outreach Stages` `has any of` `1st SMS` *(+ sms-step labels)*. *(Proxy.)* |
| LinkedIn Connection Requests Sent | B | `Outreach Stages` `has any of` `1st Linked-IN` *(your request label)*. |
| LinkedIn Connections Accepted | B | `Outreach Stages` `has any of` *your “accepted” label* *(or `Stage` `equals` `Replied/Connected`)*. |
| LinkedIn 1st Messages Sent | B | `Outreach Stages` `has any of` *your “1st message” label*. |
| LinkedIn Follow-up #1 / #2 / #3 Sent | B | one tile each: `Outreach Stages` `has any of` *the exact follow-up label*. |

### SDR — Performance

| KPI | Recipe | Settings (+ `Owner equals <rep>`) |
|-----|--------|-----------------------------------|
| Total Conversations | B | `Responded-on` `is not empty`. |
| Appointments Booked | B | `Stage` `contains` `Appointment`. |
| Meetings Held | B | Source *Appointments*, `Status` `equals` `Showed`, `Assigned User` `equals` `<rep>`. |
| Qualified Leads | B | `Stage` `equals` *qualified stage*. |
| Opportunities Created | A/B | `Count rows` (all their opps) — or scope by Stage/Status as you define “opportunity”. |
| Closed Deals | B | `Status` `equals` `Won`. |

### SDR — Conversion Rates (all Recipe D, Format Percent)

Each is a formula where **both** variables also carry `Owner equals <rep>`:

| KPI | Expression | Numerator / Denominator |
|-----|------------|-------------------------|
| Contact Rate % | `{contacted}/{leads}*100` | contacted = Outreach Stages not empty / leads = all their opps |
| Conversation Rate % | `{conv}/{contacted}*100` | conv = Responded-on not empty |
| Booking Rate % | `{booked}/{conv}*100` | booked = Stage contains Appointment |
| Show Rate % | `{held}/{booked}*100` | held = Appointments Status = Showed |
| Qualification Rate % | `{qualified}/{leads}*100` | qualified = Stage = qualified stage |
| Opportunity Conversion % | `{opps}/{qualified}*100` | define opps per your funnel |
| Close Rate % | `{won}/{qualified}*100` | won = Status = Won |

### Worked example — “Calls Made” for one SDR

1. **+ New metric** → Title `Calls Made — Zainab`.
2. Simple → Measure `Count where…`.
3. Filter column `Outreach Stages` → Condition `has any of` → Value `1st Call`.
4. **Extra conditions → + Add condition** → `Owner` `equals` `Zainab Makarfi`.
5. Format Number → **↻ Test** → **Save**.

---

## Charts to add

Open with **+ New chart**. Useful ones:

| Chart | Type | Group-by column | Conditions | Series |
|-------|------|-----------------|-----------|--------|
| Opportunities by Stage | Doughnut | `Stage` | — | Count |
| Opportunities by SDR | Bar | `Owner` | — | Count |
| Leads by Source | Bar | `Source` | — | Count |
| Calls made per SDR | Bar | `Owner` | `Outreach Stages` `has any of` `1st Call` | Count |
| LinkedIn requests per SDR | Bar | `Owner` | `Outreach Stages` `has any of` `1st Linked-IN` | Count |
| Won value by SDR | Bar | `Owner` | `Status` `equals` `Won` | Value column `Monetary Value`, Aggregate `Sum` |

Steps for a grouped, filtered chart (e.g. *Calls made per SDR*):
1. **+ New chart** → Title, Type **Bar**.
2. **Group-by source** = *… · Opportunities*; **Group-by column** = `Owner`.
3. **Conditions → + Add condition** → `Outreach Stages` `has any of` `1st Call`.
4. **Series** → leave Value column empty for a row **Count** → **Save**.

> For **Doughnut/Pie/Polar** charts raise **“Max slices”** so every
> Stage/Owner is drawn (it defaults high, but bump it if a category is missing).

---

## Loop statistics — build every SDR at once (recommended)

Instead of rebuilding the same tiles per rep, a **Loop** defines the tiles once
and repeats them for every distinct value of a column (e.g. every Owner). Each
value gets its own sub-section, and every generated widget is auto-scoped with
`{column} = value` — so you never type the owner name.

**Steps**

1. In the **Layout** toolbar click **⟳ Loop statistics**.
2. **Name** it (this becomes the parent section title, e.g. `SDR Performance`).
3. **Loop over source** = `… · Opportunities`, **Loop over column** = `Owner`.
4. *(Optional)* **Only values where** — narrow which values loop, e.g.
   `contains` `SDR`, or `equals` a specific name. Leave as “all values” for every owner.
5. **+ Add metric template** — the normal metric builder opens. Build the tile
   *without* the owner condition (the loop adds it). Example: `SMS Sent` →
   `Count where` · `Outreach Stages` `has any of` `1st SMS`. Click **Add to loop**.
   Repeat for each KPI (Calls Made, Conversations, Booking Rate %, …). Chart
   templates work the same way via **+ Add chart template**.
6. Click **Build loop**.

The loop creates the `SDR Performance` section, a sub-section per owner
(Kareem, Ahmed, …), and inside each a copy of every template scoped to that
owner. Formula templates are scoped in **every** variable, so per-owner rates
are correct.

**Maintaining a loop** — a chip appears under the Layout toolbar for each loop:
- **✎ Edit** re-opens the builder pre-filled with the loop's column, filter and
  templates. Add/remove/change any metric or chart template and **Save changes** —
  the new set is re-applied to **every** value at once (so you never edit each
  owner's tiles individually).
- **⟳ Refresh** re-expands it: new owners get their sub-section + tiles, removed
  ones disappear. Run it after a sync adds people.
- **✕ Delete** removes the loop and everything it generated.

> The loop injects `{column} = value` on each widget, so a template should read
> the **same dataset** as the loop column (e.g. loop Owner in Opportunities →
> templates over Opportunities). Value matching is case-insensitive.

---

## KPIs that need data we don’t sync yet

These are **not present in GHL’s opportunity/contact data**, so they can’t be
computed from the current sync. They need an activity/dialer feed:

- **Calls Answered**, **Answer Rate %**, **Total call duration** — these are
  per-call telephony facts. Get them by connecting the dialer
  (e.g. Readymode) as an integration/dataset, then:
  - Calls Answered → `Count where` outcome `equals` `Answered`.
  - Answer Rate % → Formula `{answered}/{dialed}*100`.
  - Total call duration → `Sum of column` on the duration column.
- **Raw activity volume** (total dials, total messages) — the *Outreach Stages*
  field records **which steps a lead reached**, not **how many times** you
  acted. If a step can repeat, “count of leads at that step” under-counts true
  volume. For exact volume, track a **numeric** custom field per channel and use
  **Recipe E — Sum of column**.
- **Granular LinkedIn steps** (Requests / Accepted / 1st Message / Follow-ups) —
  these work **only if** your *Outreach Stages* picklist actually contains those
  step labels. If it doesn’t, add them in GHL, tag opportunities as reps
  progress, re-sync, then build the tiles with `has any of <label>`.

Once that data is flowing, every “needs data” KPI becomes a normal Recipe B/D/E
tile — the builder already supports it.
