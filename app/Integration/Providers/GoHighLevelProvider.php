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
        $cfMap = $wants('Contacts') ? $this->fetchCustomFieldMap($integration, $context) : [];

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
                $this->rows($this->fetchOpportunities($integration, $pipelines, $userNames))
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
            'Opportunities' => ['Pipeline', 'Stage', 'Status', 'Monetary Value', 'Assigned User', 'Contact', 'Source', 'Created', 'Updated'],
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
            $rows = $this->client->paginate($i, '/users/', 'users', ['locationId' => $i->credential('location_id')], ['limitParam' => 'limit']);
        } catch (\Throwable $e) {
            $context->fail('Users', $e->getMessage());

            return [];
        }

        return array_map(fn ($u) => [
            '_id' => $u['id'] ?? null,
            'Name' => $this->str(trim(($u['firstName'] ?? '').' '.($u['lastName'] ?? '')) ?: ($u['name'] ?? '—')),
            'Email' => $this->str($u['email'] ?? ''),
            'Role' => $this->str($u['roles']['role'] ?? ($u['role'] ?? '')),
        ], $rows);
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

    private function fetchCustomFieldMap(Integration $i, SyncContext $context): array
    {
        try {
            $body = $this->client->get($i, '/locations/'.$i->credential('location_id').'/customFields');
        } catch (\Throwable $e) {
            $context->fail('CustomFields', $e->getMessage());

            return [];
        }

        $defs = $body['customFields'] ?? $body['customField'] ?? [];
        $map = [];

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

        return $map;
    }

    private function prettifyKey(string $key): string
    {
        $tail = str_contains($key, '.') ? substr($key, strrpos($key, '.') + 1) : $key;

        return Str::of($tail)->replace(['_', '-'], ' ')->title()->toString();
    }

    private function fetchOpportunities(Integration $i, array $pipelines, array $userNames): array
    {
        $rows = $this->client->searchOpportunities($i);

        return array_map(function ($o) use ($pipelines, $userNames) {
            $pid = $o['pipelineId'] ?? null;
            $sid = $o['pipelineStageId'] ?? ($o['stageId'] ?? null);
            $assn = $o['assignedTo'] ?? null;

            $contact = $o['contact']['name'] ?? $o['contactName'] ?? $o['name'] ?? '';

            return [
                'Pipeline' => $this->str($pipelines[$pid]['name'] ?? 'Unspecified'),
                'Stage' => $this->str($pipelines[$pid]['stages'][$sid] ?? 'Unspecified'),
                'Status' => $this->str(ucfirst($o['status'] ?? '')),
                'Monetary Value' => $this->num($o['monetaryValue'] ?? 0),
                'Assigned User' => $this->str($userNames[$assn] ?? 'Unassigned'),
                'Contact' => $this->str($contact),
                'Source' => $this->str($o['source'] ?? ''),
                'Created' => $this->date($o['createdAt'] ?? null),
                'Updated' => $this->date($o['updatedAt'] ?? null),
            ];
        }, $rows);
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
