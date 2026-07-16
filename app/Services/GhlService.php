<?php

namespace App\Services;

use App\Contracts\DataSource;
use App\Models\GhlConnection;
use App\Services\Concerns\AggregatesRows;
use App\Services\Ghl\GhlClient;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use RuntimeException;

/**
 * Presents GoHighLevel as a set of virtual "sheets" so the entire chart/metric
 * builder, buildChart(), and the controllers work unchanged.
 *
 * Virtual sheets: Opportunities, Contacts, Appointments, Users.
 *
 * CUSTOM FIELDS: the /contacts endpoint returns customFields as
 * { id, value } with NO human name — the names live in
 * /locations/{id}/customFields. We fetch that once, build an id→name map, and
 * translate each contact's custom-field IDs into real column names. Unknown
 * IDs (deleted/archived fields) are skipped so no cryptic IDs leak into the UI.
 *
 * RESILIENCE: each resource fetch runs inside safe(), so one slow/broken
 * endpoint degrades to an empty sheet instead of failing the whole dashboard.
 */
class GhlService implements DataSource
{
    use AggregatesRows;

    public const SHEETS = ['Opportunities', 'Contacts', 'Appointments', 'Users'];

    private array $errors = [];

    /** Per-resource timing/row-count captured during the last all() run. */
    private array $meta = [];

    public function __construct(private GhlClient $client) {}

    public function label(): string
    {
        return 'GoHighLevel';
    }

    /** The full catalogue of objects GHL can expose (for the settings UI). */
    public function availableSheets(): array
    {
        return self::SHEETS;
    }

    /** The objects the admin has chosen to pull; defaults to all of them. */
    public function selectedSheets(): array
    {
        return \App\Models\SheetSetting::current()?->ghlObjects() ?? self::SHEETS;
    }

    private function connection(): GhlConnection
    {
        $connection = GhlConnection::current();

        if (! $connection) {
            throw new RuntimeException('GoHighLevel is not connected. Go to Settings to connect.');
        }

        return $connection;
    }

    public function all(bool $fresh = false): array
    {
        $connection = $this->connection();
        $key        = 'ghl:' . $connection->id . ':all';
        $ttl        = optional(\App\Models\SheetSetting::current())->cache_ttl ?? 60;

        // The live, blocking fetch path. ONLY the queued SyncGhlJob passes
        // fresh:true — a web request must never take this branch, because a full
        // sync is far longer than any HTTP timeout allows.
        if ($fresh) {
            Cache::forget($key);

            // Cache::remember with the real fetch closure. Store for the FULL day
            // rather than the short display TTL: the job is what refreshes data,
            // so the cache should outlive the TTL and only be replaced by the next
            // job run. This is what makes the dashboard non-blocking.
            return Cache::remember($key, now()->addDay(), function () use ($connection) {
                return $this->runSync($connection);
            });
        }

        // The web path: read-only. If we have data, serve it. If not, kick off a
        // background sync and hand back a valid empty payload so the page renders.
        $cached = Cache::get($key);

        if (is_array($cached)) {
            return $cached;
        }

        $this->ensureFresh();

        return [
            '_meta' => [
                'selected'  => array_values($this->selectedSheets()),
                'resources' => [],
                'total_ms'  => 0,
                'ran_at'    => null,
                'syncing'   => true,
            ],
        ];
    }

    /**
     * The actual fetch work, extracted from the old all() body so both the job
     * (via fresh:true) and any future manual call share one implementation.
     */
    private function runSync(GhlConnection $connection): array
    {
        $this->errors = [];
        $this->meta   = [];
        $startedAt    = hrtime(true);

        $selected = $this->selectedSheets();

        $wants          = fn(string $s) => in_array($s, $selected, true);
        $needsUserNames = $wants('Opportunities') || $wants('Contacts') || $wants('Appointments');

        $users     = ($wants('Users') || $needsUserNames)
            ? $this->timed('Users', '/users/', fn() => $this->fetchUsers($connection), [])
            : [];
        $userNames = collect($users)->pluck('Name', '_id')->all();

        $pipelines = $wants('Opportunities')
            ? $this->timed('Pipelines', '/opportunities/pipelines', fn() => $this->fetchPipelines($connection), [])
            : [];

        $cfMap = $wants('Contacts')
            ? $this->timed('CustomFields', '/locations/{id}/customFields', fn() => $this->fetchCustomFieldMap($connection), [])
            : [];

        $payload = [];

        if ($wants('Opportunities')) {
            $payload['Opportunities'] = $this->timed('Opportunities', '/opportunities/search', fn() => $this->fetchOpportunities($connection, $pipelines, $userNames), []);
        }

        if ($wants('Contacts')) {
            $payload['Contacts'] = $this->timed('Contacts', '/contacts/search', fn() => $this->fetchContacts($connection, $userNames, $cfMap), []);
        }

        if ($wants('Appointments')) {
            $payload['Appointments'] = $this->timed('Appointments', '/calendars/events', fn() => $this->fetchAppointments($connection, $userNames), []);
        }

        if ($wants('Users')) {
            $payload['Users'] = array_map(fn($u) => collect($u)->except('_id')->all(), $users);
        }

        if ($this->errors) {
            $payload['_errors'] = $this->errors;
        }

        $payload['_meta'] = [
            'selected'  => array_values($selected),
            'resources' => $this->meta,
            'total_ms'  => (int) round((hrtime(true) - $startedAt) / 1e6),
            'ran_at'    => now()->toIso8601String(),
            'syncing'   => false,
        ];

        Cache::put($this->metaCacheKey($connection), $payload['_meta'], now()->addDay());

        $connection->forceFill(['last_synced_at' => now()])->save();

        return $payload;
    }

    /**
     * Dispatch a background sync unless one is already queued/running. Cheap to
     * call on every page load — ShouldBeUnique on the job dedupes.
     */
    public function ensureFresh(): void
    {
        if ($connection = GhlConnection::current()) {
            \App\Jobs\SyncGhlJob::dispatch($connection->id);
        }
    }

    /** True while a sync has been requested but not yet written fresh data. */
    public function isSyncing(): bool
    {
        $connection = GhlConnection::current();

        if (! $connection) {
            return false;
        }

        return ! Cache::has('ghl:' . $connection->id . ':all');
    }

    private function metaCacheKey(GhlConnection $connection): string
    {
        return 'ghl:' . $connection->id . ':meta';
    }

    /**
     * Last-sync timings/row-counts, read from the durable meta cache so it does
     * NOT provoke a fetch. Returns [] until the first sync has run.
     */
    public function lastMeta(): array
    {
        $connection = GhlConnection::current();

        if (! $connection) {
            return [];
        }

        return Cache::get($this->metaCacheKey($connection), []);
    }

    /**
     * safe() + a stopwatch. Records ms, row count, ok/error and the endpoint
     * per resource so the Data Health view can show exactly what was called and
     * how long it took.
     */
    private function timed(string $resource, string $endpoint, callable $fn, mixed $fallback): mixed
    {
        $startedAt = hrtime(true);
        $result    = $this->safe($resource, $fn, $fallback);
        $ms        = (int) round((hrtime(true) - $startedAt) / 1e6);

        $this->meta[$resource] = [
            'ok'       => ! isset($this->errors[$resource]),
            'ms'       => $ms,
            'count'    => is_array($result) ? count($result) : 0,
            'endpoint' => $endpoint,
            'error'    => $this->errors[$resource] ?? null,
        ];

        return $result;
    }

    private function safe(string $resource, callable $fn, mixed $fallback): mixed
    {
        try {
            return $fn();
        } catch (\Throwable $e) {
            Log::warning("GHL fetch failed for {$resource}", ['error' => $e->getMessage()]);
            $this->errors[$resource] = $e->getMessage();

            return $fallback;
        }
    }

    public function sheet(string $name, bool $fresh = false): array
    {
        return data_get($this->all($fresh), $name, []);
    }

    public function schema(): array
    {
        $out = [];

        foreach ($this->all() as $sheet => $rows) {
            if (in_array($sheet, ['_errors', '_meta'], true) || ! is_array($rows)) {
                continue;
            }

            $cols = [];
            foreach ($rows as $row) {
                foreach (array_keys($row) as $k) {
                    $cols[$k] = true;
                }
            }
            $out[$sheet] = array_keys($cols);
        }

        return $out;
    }

    public function columns(string $name): array
    {
        $cols = [];
        foreach ($this->sheet($name) as $row) {
            if (! is_array($row)) {
                continue;
            }
            foreach (array_keys($row) as $k) {
                $cols[$k] = true;
            }
        }

        return array_keys($cols);
    }

    /* ===================== RESOURCE FETCHERS ===================== */

    private function fetchUsers(GhlConnection $c): array
    {
        $rows = $this->client->paginate($c, '/users/', 'users', [
            'locationId' => $c->location_id,
        ]);

        return array_map(fn($u) => [
            '_id'   => $u['id'] ?? null,
            'Name'  => $this->str(trim(($u['firstName'] ?? '') . ' ' . ($u['lastName'] ?? '')) ?: ($u['name'] ?? '—')),
            'Email' => $this->str($u['email'] ?? ''),
            'Role'  => $this->str($u['roles']['role'] ?? ($u['role'] ?? '')),
        ], $rows);
    }

    private function fetchPipelines(GhlConnection $c): array
    {
        $body = $this->client->get($c, '/opportunities/pipelines', [
            'locationId' => $c->location_id,
        ]);

        $map = [];

        foreach ($body['pipelines'] ?? [] as $p) {
            $stages = [];
            foreach ($p['stages'] ?? [] as $s) {
                $stages[$s['id']] = $s['name'] ?? '';
            }
            $map[$p['id']] = ['name' => $p['name'] ?? '', 'stages' => $stages];
        }

        return $map;
    }

    /**
     * Build [customFieldId => readableName] from the location's field
     * definitions. Prefers the human `name`, falls back to `fieldKey`.
     * If your account's response shape differs, this is the ONE method to tweak.
     */
    private function fetchCustomFieldMap(GhlConnection $c): array
    {
        $body = $this->client->get($c, '/locations/' . $c->location_id . '/customFields');

        // GHL usually returns { "customFields": [ { id, name, fieldKey, ... } ] }
        $defs = $body['customFields'] ?? $body['customField'] ?? [];

        $map = [];

        foreach ($defs as $f) {
            $id = $f['id'] ?? null;
            if (! $id) {
                continue;
            }

            // Prefer human name; fall back to a cleaned fieldKey; never the raw id.
            $name = $f['name']
                ?? (isset($f['fieldKey']) ? $this->prettifyKey($f['fieldKey']) : null);

            if ($name) {
                $map[$id] = $name;
            }
        }

        return $map;
    }

    /** "contact.podcast_campaign" → "Podcast Campaign" */
    private function prettifyKey(string $key): string
    {
        $tail = str_contains($key, '.') ? substr($key, strrpos($key, '.') + 1) : $key;

        return \Illuminate\Support\Str::of($tail)
            ->replace(['_', '-'], ' ')
            ->title()
            ->toString();
    }

    private function fetchOpportunities(GhlConnection $c, array $pipelines, array $userNames): array
    {
        $rows = $this->client->paginate($c, '/opportunities/search', 'opportunities', [
            'location_id' => $c->location_id,
        ]);

        return array_map(function ($o) use ($pipelines, $userNames) {
            $pid  = $o['pipelineId'] ?? null;
            $sid  = $o['pipelineStageId'] ?? ($o['stageId'] ?? null);
            $assn = $o['assignedTo'] ?? null;

            $contact = $o['contact']['name']
                ?? $o['contactName']
                ?? $o['name']
                ?? '';

            return [
                'Pipeline'       => $this->str($pipelines[$pid]['name'] ?? 'Unspecified'),
                'Stage'          => $this->str($pipelines[$pid]['stages'][$sid] ?? 'Unspecified'),
                'Status'         => $this->str(ucfirst($o['status'] ?? '')),
                'Monetary Value' => $this->num($o['monetaryValue'] ?? 0),
                'Assigned User'  => $this->str($userNames[$assn] ?? 'Unassigned'),
                'Contact'        => $this->str($contact),
                'Source'         => $this->str($o['source'] ?? ''),
                'Created'        => $this->date($o['createdAt'] ?? null),
                'Updated'        => $this->date($o['updatedAt'] ?? null),
            ];
        }, $rows);
    }

    /**
     * Contacts, fetched incrementally via /contacts/search.
     *
     * A durable per-connection snapshot of RAW contacts (keyed by id) is kept
     * in the cache. Each sync pulls only contacts updated since the last run's
     * watermark and merges them over the snapshot, so only the very first sync
     * walks the whole list — later syncs are cheap and never approach the HTTP
     * timeout that the old GET /contacts/ pagination hit.
     *
     * Note: hard-deletes in GHL aren't observed by a delta pull, so a stale row
     * can linger until the snapshot expires (7 days) and a full rebuild runs.
     */
    private function fetchContacts(GhlConnection $c, array $userNames, array $cfMap): array
    {
        $snapshotKey  = $this->contactsSnapshotKey($c);
        $watermarkKey = $this->contactsWatermarkKey($c);

        $stored    = Cache::get($snapshotKey);
        $stored    = is_array($stored) ? $stored : [];
        $watermark = Cache::get($watermarkKey);

        // Incremental only once we already hold a snapshot; otherwise full pull.
        $sinceMs = ($stored && is_int($watermark)) ? $watermark : null;

        $result  = $this->client->searchContacts($c, $sinceMs);
        $fetched = $result['contacts'];

        $merged = $stored;
        $maxWm  = is_int($watermark) ? $watermark : 0;

        foreach ($fetched as $ct) {
            $id = $ct['id'] ?? null;
            if (! $id) {
                continue;
            }
            $merged[$id] = $ct;
            $maxWm       = max($maxWm, $this->contactUpdatedMs($ct));
        }

        // `total` reflects the location's true contact count; fall back to the
        // snapshot size if GHL omitted it.
        $total = max((int) $result['total'], count($merged));

        Cache::put($snapshotKey, $merged, now()->addDays(7));
        Cache::put($this->contactsTotalKey($c), $total, now()->addDays(7));
        if ($maxWm > 0) {
            Cache::put($watermarkKey, $maxWm, now()->addDays(7));
        }

        return array_map(
            fn ($ct) => $this->transformContact($ct, $userNames, $cfMap),
            array_values($merged)
        );
    }

    /** Shape one raw GHL contact into the display row used by charts/metrics. */
    private function transformContact(array $ct, array $userNames, array $cfMap): array
    {
        $base = [
            'Name'          => $this->str($ct['contactName'] ?? trim(($ct['firstName'] ?? '') . ' ' . ($ct['lastName'] ?? '')) ?: '—'),
            'Email'         => $this->str($ct['email'] ?? ''),
            'Phone'         => $this->str($ct['phone'] ?? ''),
            'Company'       => $this->str($ct['companyName'] ?? ''),
            'Type'          => $this->str($ct['type'] ?? ''),
            'Tags'          => $this->str($ct['tags'] ?? []),
            'Source'        => $this->str($ct['source'] ?? ''),
            'Assigned User' => $this->str($userNames[$ct['assignedTo'] ?? null] ?? 'Unassigned'),
            'Created'       => $this->date($ct['dateAdded'] ?? null),
        ];

        // Resolve custom-field IDs → readable names. Skip anything the
        // definitions map doesn't know (deleted/archived fields), so no
        // cryptic IDs ever appear as columns.
        foreach ($ct['customFields'] ?? [] as $cf) {
            $id   = $cf['id'] ?? null;
            $name = $id ? ($cfMap[$id] ?? null) : null;

            if ($name === null) {
                continue;
            }

            $base[$this->str($name)] = $this->str($cf['value'] ?? '');
        }

        return $base;
    }

    /** Same tolerant epoch-ms parse the client uses, for watermark tracking. */
    private function contactUpdatedMs(array $ct): int
    {
        $raw = $ct['dateUpdated'] ?? $ct['dateAdded'] ?? null;

        if ($raw === null || $raw === '') {
            return 0;
        }

        if (is_numeric($raw)) {
            return (int) $raw;
        }

        try {
            return (int) \Illuminate\Support\Carbon::parse($raw)->getTimestampMs();
        } catch (\Throwable) {
            return 0;
        }
    }

    private function contactsSnapshotKey(GhlConnection $c): string
    {
        return 'ghl:' . $c->id . ':contacts_raw';
    }

    private function contactsWatermarkKey(GhlConnection $c): string
    {
        return 'ghl:' . $c->id . ':contacts_wm';
    }

    private function contactsTotalKey(GhlConnection $c): string
    {
        return 'ghl:' . $c->id . ':contacts_total';
    }

    /**
     * The location's total contact count as recorded by the last sync — read
     * from cache only, so it NEVER triggers a live fetch on the web path.
     * Returns null when no sync has populated it yet, letting callers fall back
     * to row counting. This lets a plain "count" metric on Contacts resolve
     * from a single cached integer instead of materialising every row.
     */
    public function contactsTotal(): ?int
    {
        $connection = GhlConnection::current();

        if (! $connection) {
            return null;
        }

        $total = Cache::get($this->contactsTotalKey($connection));

        return is_int($total) ? $total : null;
    }

    private function fetchAppointments(GhlConnection $c, array $userNames): array
    {
        $calendars = $this->client->get($c, '/calendars/', [
            'locationId' => $c->location_id,
        ])['calendars'] ?? [];

        $maxCalendars = config('ghl.max_calendars', 15);
        $calendars    = array_slice($calendars, 0, $maxCalendars);

        $back    = (int) config('ghl.events_days_back', 90);
        $forward = (int) config('ghl.events_days_forward', 30);

        $out = [];

        foreach ($calendars as $cal) {
            $events = $this->safe(
                'Appointments:' . ($cal['name'] ?? $cal['id'] ?? '?'),
                fn() => $this->client->paginate($c, '/calendars/events', 'events', [
                    'locationId' => $c->location_id,
                    'calendarId' => $cal['id'] ?? null,
                    'startTime'  => now()->subDays($back)->getTimestampMs(),
                    'endTime'    => now()->addDays($forward)->getTimestampMs(),
                ]),
                []
            );

            foreach ($events as $e) {
                $out[] = [
                    'Calendar'      => $this->str($cal['name'] ?? 'Unspecified'),
                    'Status'        => $this->str(ucfirst($e['appointmentStatus'] ?? ($e['status'] ?? ''))),
                    'Assigned User' => $this->str($userNames[$e['assignedUserId'] ?? null] ?? 'Unassigned'),
                    'Contact'       => $this->str($e['contactId'] ?? ''),
                    'Start'         => $this->date($e['startTime'] ?? null),
                    'End'           => $this->date($e['endTime'] ?? null),
                    'Created'       => $this->date($e['dateAdded'] ?? null),
                ];
            }
        }

        return $out;
    }

    private function str(mixed $v): string
    {
        if (is_array($v)) {
            $flat = array_map(
                fn($x) => is_scalar($x) ? (string) $x : json_encode($x),
                $v
            );

            return implode(', ', array_filter($flat, fn($x) => $x !== ''));
        }

        if (is_bool($v)) {
            return $v ? 'true' : 'false';
        }

        return $v === null ? '' : (string) $v;
    }

    private function num(mixed $v): float|int|string
    {
        if (is_numeric($v)) {
            return $v + 0;
        }

        return $this->str($v);
    }

    private function date(mixed $v): string
    {
        if (! $v) {
            return '';
        }

        try {
            return is_numeric($v)
                ? \Illuminate\Support\Carbon::createFromTimestampMs((int) $v)->toDateString()
                : \Illuminate\Support\Carbon::parse($v)->toDateString();
        } catch (\Throwable) {
            return $this->str($v);
        }
    }
}
