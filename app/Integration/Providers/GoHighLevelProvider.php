<?php

namespace App\Integration\Providers;

use App\Integration\Models\Integration;
use App\Integration\Providers\Ghl\GhlClient;
use App\Integration\Services\SyncContext;
use App\Support\CastsValues;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * GoHighLevel integration. Presents four datasets — Opportunities, Contacts,
 * Appointments, Users — built from the v2 API and written into the local
 * records table. This is just one provider among several: it knows how to talk
 * to GHL and nothing about dashboards, health, or menus.
 */
class GoHighLevelProvider implements IntegrationProvider
{
    use CastsValues;

    public const DATASETS = ['Opportunities', 'Contacts', 'Appointments', 'Users'];

    public function __construct(private GhlClient $client) {}

    public function key(): string
    {
        return 'gohighlevel';
    }

    public function label(): string
    {
        return 'GoHighLevel';
    }

    /**
     * Validate the private-integration token + location id with a live call,
     * then persist. The location call also gives us a display name.
     */
    public function connect(Integration $integration, array $credentials): void
    {
        $token = trim($credentials['access_token'] ?? '');
        $location = trim($credentials['location_id'] ?? '');

        if ($token === '' || $location === '') {
            throw new RuntimeException('A private integration token and location id are required.');
        }

        $integration->forceFill([
            'provider' => $this->key(),
            'credentials' => ['access_token' => $token, 'location_id' => $location],
        ]);

        $body = $this->client->get($integration, '/locations/'.$location);
        $name = $body['location']['name'] ?? $body['name'] ?? null;

        $integration->fill([
            'name' => $name ? "GoHighLevel — {$name}" : 'GoHighLevel',
            'credentials' => ['access_token' => $token, 'location_id' => $location, 'location_name' => $name],
            'config' => array_merge($integration->config ?? [], [
                'datasets' => $integration->setting('datasets', self::DATASETS),
            ]),
            'status' => 'connected',
        ]);

        $integration->save();
    }

    public function disconnect(Integration $integration): void
    {
        // Private integration tokens are revoked in GHL's own UI; nothing to do.
    }

    /** Datasets the admin chose to pull; defaults to all of them. */
    private function selectedDatasets(Integration $integration): array
    {
        $chosen = collect($integration->setting('datasets', self::DATASETS))
            ->intersect(self::DATASETS)
            ->values();

        return $chosen->isEmpty() ? self::DATASETS : $chosen->all();
    }

    public function sync(Integration $integration, SyncContext $context): void
    {
        $selected = $this->selectedDatasets($integration);
        $wants = fn (string $d) => in_array($d, $selected, true);

        $needsUserNames = $wants('Opportunities') || $wants('Contacts') || $wants('Appointments');

        $users = ($wants('Users') || $needsUserNames) ? $this->fetchUsers($integration, $context) : [];
        $userNames = collect($users)->pluck('Name', '_id')->all();

        $pipelines = $wants('Opportunities') ? $this->fetchPipelines($integration, $context) : [];

        // Custom-field labels are keyed by "model" in GHL (contact vs
        // opportunity), so pull only the models we actually need. Both
        // Contacts and Opportunities store their custom values by field id
        // only — this map is what turns an opaque id into a real column name.
        $cfModels = [];
        if ($wants('Contacts')) {
            $cfModels[] = 'contact';
        }
        if ($wants('Opportunities')) {
            $cfModels[] = 'opportunity';
        }
        $cfMap = $cfModels ? $this->fetchCustomFieldMap($integration, $context, $cfModels) : [];

        if ($wants('Opportunities')) {
            // The full pipeline/stage catalogue — every stage of every pipeline,
            // even ones with zero opportunities in them right now — so the
            // dashboard's Pipeline/Stage picker can list them all, not just
            // whichever ones happen to have synced rows. Written BEFORE the
            // opportunity pull (which can be large and slow) so the pipelines
            // always land even if fetching opportunities is throttled or fails.
            $this->guard($context, 'Pipeline Stages', fn () => $context->write(
                'Pipeline Stages',
                $this->rows($this->pipelineStageRows($pipelines))
            ));

            $this->guard($context, 'Opportunities', fn () => $context->write(
                'Opportunities',
                $this->rows($this->fetchOpportunities($integration, $pipelines, $userNames, $cfMap))
            ));
        }

        if ($wants('Contacts')) {
            $this->guard($context, 'Contacts', fn () => $context->write(
                'Contacts',
                $this->rows($this->fetchContacts($integration, $userNames, $cfMap))
            ));
        }

        if ($wants('Appointments')) {
            $this->guard($context, 'Appointments', fn () => $context->write(
                'Appointments',
                $this->rows($this->fetchAppointments($integration, $userNames))
            ));
        }

        if ($wants('Users')) {
            $this->guard($context, 'Users', fn () => $context->write(
                'Users',
                $this->rows(array_map(fn ($u) => collect($u)->except('_id')->all(), $users))
            ));
        }
    }

    public function schema(Integration $integration): array
    {
        $out = [];

        foreach ($this->selectedDatasets($integration) as $dataset) {
            $rows = $integration->rows($dataset);

            $cols = [];
            foreach ($rows as $row) {
                foreach (array_keys($row) as $k) {
                    $cols[$k] = true;
                }
            }

            $out[$dataset] = array_keys($cols) ?: $this->baseColumns($dataset);
        }

        return $out;
    }

    /** Column set shown before the first sync has produced any rows. */
    private function baseColumns(string $dataset): array
    {
        return match ($dataset) {
            'Opportunities' => ['Pipeline', 'Stage', 'Status', 'Monetary Value', 'Owner', 'Assigned User', 'Contact', 'Company', 'Email', 'Phone', 'Contact Tags', 'Source', 'Created', 'Updated'],
            'Contacts' => ['Name', 'Email', 'Phone', 'Company', 'Type', 'Tags', 'Source', 'Assigned User', 'Country', 'Website', 'Created', 'Updated'],
            'Appointments' => ['Calendar', 'Status', 'Assigned User', 'Contact', 'Start', 'End', 'Created'],
            'Users' => ['Name', 'Email', 'Role'],
            default => [],
        };
    }

    /* ===================== helpers ===================== */

    /** Wrap a dataset write so one failure reports to health without aborting. */
    private function guard(SyncContext $context, string $dataset, callable $fn): void
    {
        try {
            $fn();
        } catch (\Throwable $e) {
            $context->fail($dataset, $e->getMessage());
        }
    }

    /** Shape plain payload arrays into the {external_id, payload} record form. */
    private function rows(array $payloads, ?string $idKey = null): array
    {
        return array_map(fn ($p) => [
            'external_id' => $idKey ? ($p[$idKey] ?? null) : null,
            'payload' => $p,
        ], $payloads);
    }

    private function fetchUsers(Integration $i, SyncContext $context): array
    {
        try {
            // GET /users/ returns every user for the location in a single
            // response and rejects unknown query params — so no pagination and
            // no `limit` (which is what made this 422 before).
            $body = $this->client->get($i, '/users/', [
                'locationId' => $i->credential('location_id'),
            ]);

            $rows = $body['users'] ?? [];
        } catch (\Throwable $e) {
            $context->fail('Users', $e->getMessage());

            return [];
        }

        $users = array_map(fn ($u) => [
            '_id' => $u['id'] ?? null,
            'Name' => $this->str(trim(($u['firstName'] ?? '').' '.($u['lastName'] ?? '')) ?: ($u['name'] ?? '—')),
            'Email' => $this->str($u['email'] ?? ''),
            'Role' => $this->str($u['roles']['role'] ?? ($u['role'] ?? '')),
            'Deleted' => ($u['deleted'] ?? false) ? 'Yes' : 'No',
        ], $rows);

        // Drop id-less users: pluck('Name', '_id') would file them under the ""
        // key, which is also what $userNames[null] resolves to — so every
        // unassigned opportunity would inherit a real person's name.
        return array_values(array_filter($users, fn ($u) => $u['_id'] !== null));
    }

    private function fetchPipelines(Integration $i, SyncContext $context): array
    {
        try {
            $body = $this->client->get($i, '/opportunities/pipelines', ['locationId' => $i->credential('location_id')]);
        } catch (\Throwable $e) {
            $context->fail('Pipelines', $e->getMessage());

            return [];
        }

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

    /** One row per {Pipeline, Stage} pair, straight from the pipeline catalogue — not from opportunities. */
    private function pipelineStageRows(array $pipelines): array
    {
        $rows = [];

        foreach ($pipelines as $pipeline) {
            $name = $this->str($pipeline['name'] ?? '');
            $stages = $pipeline['stages'] ?? [];

            if (! $stages) {
                $rows[] = ['Pipeline' => $name, 'Stage' => ''];

                continue;
            }

            foreach ($stages as $stageName) {
                $rows[] = ['Pipeline' => $name, 'Stage' => $this->str($stageName)];
            }
        }

        return $rows;
    }

    /**
     * Map of {custom-field id → display name}, merged across the requested GHL
     * models ('contact' and/or 'opportunity'). GHL stores custom-field values
     * on both contacts and opportunities keyed by id only, so this map is what
     * turns an opaque id like "vZVi0DXoQn2QRdlzQlNM" into a column such as
     * "Outreach Stages". Contact and opportunity fields live under different
     * models, hence one call per model, merged.
     *
     * @param  array<int, string>  $models
     */
    private function fetchCustomFieldMap(Integration $i, SyncContext $context, array $models = ['contact']): array
    {
        $map = [];

        foreach ($models as $model) {
            try {
                $body = $this->client->get(
                    $i,
                    '/locations/'.$i->credential('location_id').'/customFields',
                    $model ? ['model' => $model] : []
                );
            } catch (\Throwable $e) {
                $context->fail('CustomFields', $e->getMessage());

                continue;
            }

            $defs = $body['customFields'] ?? $body['customField'] ?? [];

            foreach ($defs as $f) {
                $id = $f['id'] ?? null;
                if (! $id) {
                    continue;
                }

                $name = $f['name'] ?? (isset($f['fieldKey']) ? $this->prettifyKey($f['fieldKey']) : null);

                if ($name) {
                    $map[$id] = $name;
                }
            }
        }

        return $map;
    }

    private function prettifyKey(string $key): string
    {
        $tail = str_contains($key, '.') ? substr($key, strrpos($key, '.') + 1) : $key;

        return Str::of($tail)->replace(['_', '-'], ' ')->title()->toString();
    }

    private function fetchOpportunities(Integration $i, array $pipelines, array $userNames, array $cfMap = []): array
    {
        $rows = $this->client->searchOpportunities($i);

        return array_map(function ($o) use ($pipelines, $userNames, $cfMap) {
            $pid = $o['pipelineId'] ?? null;
            $sid = $o['pipelineStageId'] ?? ($o['stageId'] ?? null);
            $assn = $o['assignedTo'] ?? null;

            $contact = $o['contact']['name'] ?? $o['contactName'] ?? $o['name'] ?? '';
            $owner = $this->str($userNames[$assn] ?? 'Unassigned');

            $base = [
                'Pipeline' => $this->str($pipelines[$pid]['name'] ?? 'Unspecified'),
                'Stage' => $this->str($pipelines[$pid]['stages'][$sid] ?? 'Unspecified'),
                'Status' => $this->str(ucfirst($o['status'] ?? '')),
                'Monetary Value' => $this->num($o['monetaryValue'] ?? 0),
                // "Owner" is GHL's own label for the assignee; keep the existing
                // "Assigned User" column too so older charts/metrics keep working.
                'Owner' => $owner,
                'Assigned User' => $owner,
                'Contact' => $this->str($contact),
                'Company' => $this->str($o['contact']['companyName'] ?? ''),
                'Email' => $this->str($o['contact']['email'] ?? ''),
                'Phone' => $this->str($o['contact']['phone'] ?? ''),
                'Contact Tags' => $this->str($o['contact']['tags'] ?? []),
                'Source' => $this->str($o['source'] ?? ''),
                'Created' => $this->date($o['createdAt'] ?? null),
                'Updated' => $this->date($o['updatedAt'] ?? null),
            ];

            // Union keeps base columns authoritative if a custom field happens
            // to share one of their names.
            return $base + $this->opportunityCustomFields($o, $cfMap);
        }, $rows);
    }

    /**
     * Flatten an opportunity's `customFields` into named columns, resolving each
     * opaque field id to its label via $cfMap. Single-value fields land as
     * strings; multi-select array fields (e.g. "Outreach Stages" →
     * ["1st Email", "1st Linked-IN"]) are joined into a comma-separated string,
     * which the `has_all` / `has_any` filter operators match token-by-token.
     *
     * @return array<string, string>
     */
    private function opportunityCustomFields(array $o, array $cfMap): array
    {
        $out = [];

        foreach ($o['customFields'] ?? [] as $cf) {
            $id = $cf['id'] ?? null;
            $name = $id ? ($cfMap[$id] ?? null) : null;

            if ($name === null) {
                continue;
            }

            $value = $cf['fieldValueArray']
                ?? $cf['fieldValueString']
                ?? $cf['fieldValue']
                ?? $cf['value']
                ?? '';

            $out[$this->str($name)] = $this->str($value);
        }

        return $out;
    }

    /**
     * Contacts via /contacts/search, kept incremental. A raw-contact snapshot
     * (keyed by id) lives in the cache and a watermark in the integration config,
     * so only the first sync walks the whole list; later syncs pull just the
     * delta and merge it over the snapshot.
     */
    private function fetchContacts(Integration $i, array $userNames, array $cfMap): array
    {
        $snapshotKey = 'integration:'.$i->id.':ghl_contacts_raw';

        $stored = Cache::get($snapshotKey);
        $stored = is_array($stored) ? $stored : [];
        $watermark = $i->setting('contacts_watermark');

        $sinceMs = ($stored && is_int($watermark)) ? $watermark : null;

        $result = $this->client->searchContacts($i, $sinceMs);
        $fetched = $result['contacts'];

        $merged = $stored;
        $maxWm = is_int($watermark) ? $watermark : 0;

        foreach ($fetched as $ct) {
            $id = $ct['id'] ?? null;
            if (! $id) {
                continue;
            }
            $merged[$id] = $ct;
            $maxWm = max($maxWm, $this->client->contactUpdatedMs($ct));
        }

        Cache::put($snapshotKey, $merged, now()->addDays(7));
        if ($maxWm > 0) {
            $i->updateConfig(['contacts_watermark' => $maxWm]);
        }

        return array_map(
            fn ($ct) => $this->transformContact($ct, $userNames, $cfMap),
            array_values($merged)
        );
    }

    private function transformContact(array $ct, array $userNames, array $cfMap): array
    {
        $base = [
            'Name' => $this->str($ct['contactName'] ?? trim(($ct['firstName'] ?? '').' '.($ct['lastName'] ?? '')) ?: '—'),
            'Email' => $this->str($ct['email'] ?? ''),
            'Phone' => $this->str($ct['phone'] ?? ''),
            'Company' => $this->str($ct['companyName'] ?? ''),
            'Type' => $this->str($ct['type'] ?? ''),
            'Tags' => $this->str($ct['tags'] ?? []),
            'Source' => $this->str($ct['source'] ?? ''),
            'Assigned User' => $this->str($userNames[$ct['assignedTo'] ?? null] ?? 'Unassigned'),
            'Country' => $this->str($ct['country'] ?? ''),
            'Website' => $this->str($ct['website'] ?? ''),
            'Created' => $this->date($ct['dateAdded'] ?? null),
            'Updated' => $this->date($ct['dateUpdated'] ?? null),
        ];

        foreach ($ct['customFields'] ?? [] as $cf) {
            $id = $cf['id'] ?? null;
            $name = $id ? ($cfMap[$id] ?? null) : null;

            if ($name === null) {
                continue;
            }

            $base[$this->str($name)] = $this->str($cf['value'] ?? '');
        }

        return $base;
    }

    private function fetchAppointments(Integration $i, array $userNames): array
    {
        $location = $i->credential('location_id');
        $calendars = $this->client->get($i, '/calendars/', ['locationId' => $location])['calendars'] ?? [];

        $maxCalendars = (int) config('integrations.gohighlevel.max_calendars', 15);
        $calendars = array_slice($calendars, 0, $maxCalendars);

        $back = (int) config('integrations.gohighlevel.events_days_back', 90);
        $forward = (int) config('integrations.gohighlevel.events_days_forward', 30);

        $out = [];

        foreach ($calendars as $cal) {
            try {
                $events = $this->client->paginate($i, '/calendars/events', 'events', [
                    'locationId' => $location,
                    'calendarId' => $cal['id'] ?? null,
                    'startTime' => now()->subDays($back)->getTimestampMs(),
                    'endTime' => now()->addDays($forward)->getTimestampMs(),
                ]);
            } catch (\Throwable) {
                $events = [];
            }

            foreach ($events as $e) {
                $out[] = [
                    'Calendar' => $this->str($cal['name'] ?? 'Unspecified'),
                    'Status' => $this->str(ucfirst($e['appointmentStatus'] ?? ($e['status'] ?? ''))),
                    'Assigned User' => $this->str($userNames[$e['assignedUserId'] ?? null] ?? 'Unassigned'),
                    'Contact' => $this->str($e['contactId'] ?? ''),
                    'Start' => $this->date($e['startTime'] ?? null),
                    'End' => $this->date($e['endTime'] ?? null),
                    'Created' => $this->date($e['dateAdded'] ?? null),
                ];
            }
        }

        return $out;
    }
}
