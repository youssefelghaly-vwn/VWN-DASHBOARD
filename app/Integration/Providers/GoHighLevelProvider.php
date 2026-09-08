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

    /** The derived catalogue of everything an admin can pick as an opportunity column. */
    public const OPPORTUNITY_FIELDS_DATASET = 'Opportunity Fields';

    /**
     * Opportunity fields GHL already sends but the base row shape throws away.
     * They are offered alongside custom fields in the picker, so an admin can
     * surface e.g. the raw opportunity id or a UTM source without us guessing
     * which of them every location cares about.
     *
     * Keyed by the `data_get` path into the raw opportunity — that path is also
     * the catalogue key ("native:{path}"), so nothing else has to stay in sync.
     */
    public const OPPORTUNITY_NATIVE_FIELDS = [
        'id' => ['label' => 'Opportunity ID', 'cast' => 'str'],
        'name' => ['label' => 'Opportunity Name', 'cast' => 'str'],
        'lastStatusChangeAt' => ['label' => 'Last Status Change', 'cast' => 'date'],
        'lastStageChangeAt' => ['label' => 'Last Stage Change', 'cast' => 'date'],
        'effectiveProbability' => ['label' => 'Effective Probability', 'cast' => 'num'],
        'forecastProbability' => ['label' => 'Forecast Probability', 'cast' => 'num'],
        'lostReasonId' => ['label' => 'Lost Reason ID', 'cast' => 'str'],
        'contactId' => ['label' => 'Contact ID', 'cast' => 'str'],
        'attributions.0.utmSessionSource' => ['label' => 'UTM Session Source', 'cast' => 'str'],
        'attributions.0.medium' => ['label' => 'Attribution Medium', 'cast' => 'str'],
        'contact.score.0.score' => ['label' => 'Contact Score', 'cast' => 'num'],
    ];

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
        $cfDefs = $cfModels ? $this->fetchCustomFieldDefinitions($integration, $context, $cfModels) : [];
        $cfMap = $this->customFieldNames($cfDefs);

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

            // Same reasoning as the pipeline catalogue: the field picker has to
            // keep working when the (large, throttle-prone) opportunity fetch
            // fails, so the catalogue lands first.
            $fieldRows = $this->opportunityFieldRows($cfDefs);

            $this->guard($context, self::OPPORTUNITY_FIELDS_DATASET, fn () => $context->write(
                self::OPPORTUNITY_FIELDS_DATASET,
                $this->rows($fieldRows)
            ));

            // Resolved against the catalogue we just built, not the stored one:
            // a field deleted in GHL since the last sync drops out of the row
            // shape instead of becoming a column named after an opaque id.
            $selection = $this->selectedOpportunityFields($integration, $this->catalogueLabels($fieldRows));

            $this->guard($context, 'Opportunities', fn () => $context->write(
                'Opportunities',
                $this->rows($this->fetchOpportunities($integration, $pipelines, $userNames, $cfMap, $selection))
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
            // Opportunities with a saved selection have a DECLARED shape, so the
            // builder dropdowns come from the picker rather than from whatever
            // the last sync happened to observe — a column can no longer vanish
            // because the final record carrying a value was cleared in GHL.
            $declared = $dataset === 'Opportunities' ? $this->declaredOpportunityColumns($integration) : null;

            if ($declared !== null) {
                $out[$dataset] = $declared;

                continue;
            }

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

    /**
     * Base columns plus the labels of every selected field, or null when no
     * selection has ever been saved (legacy observed-column behaviour).
     *
     * @return array<int, string>|null
     */
    private function declaredOpportunityColumns(Integration $integration): ?array
    {
        $selection = $this->selectedOpportunityFields(
            $integration,
            $this->catalogueLabels($this->opportunityFieldCatalogue($integration))
        );

        if ($selection === null) {
            return null;
        }

        return array_values(array_unique([
            ...$this->baseColumns('Opportunities'),
            ...array_values($selection),
        ]));
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
     * Custom-field DEFINITIONS keyed by id, merged across the requested GHL
     * models ('contact' and/or 'opportunity'). GHL stores custom-field values
     * on both contacts and opportunities keyed by id only, so these definitions
     * are what turn an opaque id like "vZVi0DXoQn2QRdlzQlNM" into a column such
     * as "Outreach Stages". Contact and opportunity fields live under different
     * models, hence one call per model, merged.
     *
     * The whole definition is kept (not just the name) because the field picker
     * needs the model to know which fields belong to opportunities and the data
     * type to show alongside the label — and this is the only place we fetch
     * them, so reducing to a name map here would cost a second API call.
     *
     * @param  array<int, string>  $models
     * @return array<string, array{id: string, name: string, dataType: string, model: string}>
     */
    private function fetchCustomFieldDefinitions(Integration $i, SyncContext $context, array $models = ['contact']): array
    {
        $out = [];

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

                if (! $name) {
                    continue;
                }

                $out[$id] = [
                    'id' => (string) $id,
                    'name' => (string) $name,
                    'dataType' => $this->str($f['dataType'] ?? ''),
                    // GHL doesn't always echo the model back; the model we asked
                    // for is authoritative in that case.
                    'model' => $this->str($f['model'] ?? $model),
                ];
            }
        }

        return $out;
    }

    /**
     * The thin {id → display name} view of the definitions that the contact and
     * opportunity transformers have always taken.
     *
     * @param  array<string, array{name: string}>  $defs
     * @return array<string, string>
     */
    private function customFieldNames(array $defs): array
    {
        return array_map(fn (array $f) => $f['name'], $defs);
    }

    private function prettifyKey(string $key): string
    {
        $tail = str_contains($key, '.') ? substr($key, strrpos($key, '.') + 1) : $key;

        return Str::of($tail)->replace(['_', '-'], ' ')->title()->toString();
    }

    /* ============== opportunity field catalogue + selection ============== */

    /**
     * One catalogue record per field an admin may promote to a column: the
     * opportunity custom fields GHL defines for this location, plus the native
     * payload fields the base row shape drops. Keys are stable and opaque —
     * "cf:{id}" survives a field being renamed in GHL, "native:{path}" is the
     * data_get path we read it back with.
     *
     * @param  array<string, array{id: string, name: string, dataType: string, model: string}>  $cfDefs
     * @return array<int, array<string, string>>
     */
    private function opportunityFieldRows(array $cfDefs): array
    {
        $rows = [];

        foreach (self::OPPORTUNITY_NATIVE_FIELDS as $path => $spec) {
            $rows[] = [
                'Key' => 'native:'.$path,
                'Label' => $spec['label'],
                'Kind' => 'Native',
                'Type' => $this->castType($spec['cast']),
            ];
        }

        foreach ($cfDefs as $def) {
            if ($def['model'] !== 'opportunity') {
                continue;
            }

            $rows[] = [
                'Key' => 'cf:'.$def['id'],
                'Label' => $this->str($def['name']),
                'Kind' => 'Custom',
                'Type' => $def['dataType'] !== '' ? $def['dataType'] : 'TEXT',
            ];
        }

        return $rows;
    }

    /** GHL's own dataType vocabulary, so native and custom rows read alike. */
    private function castType(string $cast): string
    {
        return match ($cast) {
            'num' => 'NUMERICAL',
            'date' => 'DATE',
            default => 'TEXT',
        };
    }

    /**
     * The catalogue the last sync wrote. Read from local records — nothing in
     * the read path is allowed to call GHL — so the settings screen and
     * schema() can both list the pickable fields without a sync.
     *
     * @return array<int, array<string, string>>
     */
    public function opportunityFieldCatalogue(Integration $integration): array
    {
        return $integration->rows(self::OPPORTUNITY_FIELDS_DATASET);
    }

    /**
     * @param  array<int, array<string, string>>  $rows
     * @return array<string, string> catalogue key => column label
     */
    private function catalogueLabels(array $rows): array
    {
        $out = [];

        foreach ($rows as $row) {
            if (($key = $row['Key'] ?? null) && ($label = $row['Label'] ?? null)) {
                $out[$key] = $label;
            }
        }

        return $out;
    }

    /**
     * The admin's chosen fields as {catalogue key → column label}, or null when
     * no selection has ever been saved.
     *
     * That null is the whole point of the check: a MISSING config key means the
     * integration predates the picker and must keep its legacy behaviour (emit
     * whatever custom fields the records happen to carry), while an EMPTY array
     * is a deliberate "base columns only". Collapsing the two would silently
     * strip columns from every integration on deploy.
     *
     * @param  array<string, string>  $catalogue
     * @return array<string, string>|null
     */
    private function selectedOpportunityFields(Integration $integration, array $catalogue): ?array
    {
        $keys = $integration->setting('opportunity_fields');

        if (! is_array($keys)) {
            return null;
        }

        $out = [];

        foreach ($keys as $key) {
            // A key the catalogue no longer knows is a field deleted in GHL:
            // drop it rather than emit a column named after an opaque id.
            if (is_string($key) && isset($catalogue[$key])) {
                $out[$key] = $catalogue[$key];
            }
        }

        return $out;
    }

    /**
     * Read one selected field off a raw opportunity, or null when the record
     * carries no value for it (the caller fills those from a blank template).
     *
     * @param  array<string, array<string, mixed>>  $customById  the record's customFields, indexed by id
     */
    private function selectedFieldValue(string $key, array $o, array $customById): mixed
    {
        if (str_starts_with($key, 'cf:')) {
            $cf = $customById[substr($key, 3)] ?? null;

            return $cf === null ? null : $this->str($this->customFieldValue($cf));
        }

        $path = substr($key, strlen('native:'));
        $spec = self::OPPORTUNITY_NATIVE_FIELDS[$path] ?? null;
        $raw = $spec ? data_get($o, $path) : null;

        if ($raw === null) {
            return null;
        }

        return match ($spec['cast']) {
            'num' => $this->num($raw),
            'date' => $this->date($raw),
            default => $this->str($raw),
        };
    }

    /**
     * @param  array<string, string>|null  $selection  catalogue key => column label,
     *                                                 or null for the legacy observed-columns behaviour
     */
    private function fetchOpportunities(Integration $i, array $pipelines, array $userNames, array $cfMap = [], ?array $selection = null): array
    {
        $rows = $this->client->searchOpportunities($i);

        // Every selected field is a key on every row, value or not. GHL simply
        // omits empty custom fields from a record, so without this template a
        // field nobody has filled in yet would never become a column at all.
        $template = $selection === null ? [] : array_fill_keys(array_values($selection), '');

        return array_map(function ($o) use ($pipelines, $userNames, $cfMap, $selection, $template) {
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
            if ($selection === null) {
                return $base + $this->opportunityCustomFields($o, $cfMap);
            }

            // Indexed once per row, not once per selected field — a location
            // with hundreds of picked fields would otherwise rescan the record's
            // customFields hundreds of times.
            $customById = [];
            foreach ($o['customFields'] ?? [] as $cf) {
                if ($id = $cf['id'] ?? null) {
                    $customById[$id] = $cf;
                }
            }

            $values = [];
            foreach ($selection as $key => $label) {
                $value = $this->selectedFieldValue($key, $o, $customById);

                if ($value !== null) {
                    $values[$label] = $value;
                }
            }

            // Real values win over the blanks; the template supplies the rest.
            return $base + $values + $template;
        }, $rows);
    }

    /**
     * The value of one `customFields` entry, whatever key GHL parked it under.
     *
     * The v2 API names that key after the field's TYPE — fieldValueString,
     * fieldValueArray, and for a date field fieldValueDate — while the
     * single-opportunity endpoint just says fieldValue. Listing the ones we
     * have seen and stopping there means an unlisted type reads as empty,
     * which looks exactly like "nobody filled this in": a date column would
     * exist, show blank, and quietly match no date filter. So the known keys
     * are tried in order and anything else fieldValue* is still accepted.
     */
    private function customFieldValue(array $cf): mixed
    {
        foreach (['fieldValueArray', 'fieldValueString', 'fieldValue', 'value'] as $key) {
            if (isset($cf[$key])) {
                return $cf[$key];
            }
        }

        foreach ($cf as $key => $value) {
            if (str_starts_with($key, 'fieldValue')) {
                return $value;
            }
        }

        return '';
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

            $out[$this->str($name)] = $this->str($this->customFieldValue($cf));
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

            $base[$this->str($name)] = $this->str($this->customFieldValue($cf));
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
