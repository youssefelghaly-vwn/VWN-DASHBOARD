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

    public function __construct(private GhlClient $client) {}

    public function label(): string
    {
        return 'GoHighLevel';
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
        $key        = 'ghl:'.$connection->id.':all';
        $ttl        = optional(\App\Models\SheetSetting::current())->cache_ttl ?? 60;

        if ($fresh) {
            Cache::forget($key);
        }

        return Cache::remember($key, $ttl, function () use ($connection) {
            $this->errors = [];

            // Prerequisites for labelling other sheets.
            $users     = $this->safe('Users', fn () => $this->fetchUsers($connection), []);
            $userNames = collect($users)->pluck('Name', '_id')->all();
            $pipelines = $this->safe('Pipelines', fn () => $this->fetchPipelines($connection), []);
            $cfMap     = $this->safe('CustomFields', fn () => $this->fetchCustomFieldMap($connection), []);

            $payload = [
                'Opportunities' => $this->safe('Opportunities', fn () => $this->fetchOpportunities($connection, $pipelines, $userNames), []),
                'Contacts'      => $this->safe('Contacts', fn () => $this->fetchContacts($connection, $userNames, $cfMap), []),
                'Appointments'  => $this->safe('Appointments', fn () => $this->fetchAppointments($connection, $userNames), []),
                'Users'         => array_map(fn ($u) => collect($u)->except('_id')->all(), $users),
            ];

            if ($this->errors) {
                $payload['_errors'] = $this->errors;
            }

            $connection->forceFill(['last_synced_at' => now()])->save();

            return $payload;
        });
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
            if ($sheet === '_errors' || ! is_array($rows)) {
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

        return array_map(fn ($u) => [
            '_id'   => $u['id'] ?? null,
            'Name'  => $this->str(trim(($u['firstName'] ?? '').' '.($u['lastName'] ?? '')) ?: ($u['name'] ?? '—')),
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
        $body = $this->client->get($c, '/locations/'.$c->location_id.'/customFields');

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

    private function fetchContacts(GhlConnection $c, array $userNames, array $cfMap): array
    {
        $rows = $this->client->paginate($c, '/contacts/', 'contacts', [
            'locationId' => $c->location_id,
        ], ['limitParam' => 'limit']);

        return array_map(function ($ct) use ($userNames, $cfMap) {
            $base = [
                'Name'          => $this->str($ct['contactName'] ?? trim(($ct['firstName'] ?? '').' '.($ct['lastName'] ?? '')) ?: '—'),
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
        }, $rows);
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
                'Appointments:'.($cal['name'] ?? $cal['id'] ?? '?'),
                fn () => $this->client->paginate($c, '/calendars/events', 'events', [
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
                fn ($x) => is_scalar($x) ? (string) $x : json_encode($x),
                $v
            );

            return implode(', ', array_filter($flat, fn ($x) => $x !== ''));
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