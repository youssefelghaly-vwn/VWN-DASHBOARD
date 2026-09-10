<?php

namespace App\Integration\Providers;

use App\Integration\Models\Integration;
use App\Integration\Providers\CloudTalk\CloudTalkClient;
use App\Integration\Services\SyncContext;
use App\Support\BusinessTimezone;
use App\Support\CastsValues;
use Illuminate\Support\Carbon;
use RuntimeException;

/**
 * CloudTalk integration. Four datasets — Agents, Campaigns, Calls and Agent
 * Daily — built from the core API at my.cloudtalk.io/api.
 *
 * Two shapes of the same data are written on purpose. `Calls` is one row per
 * call, which any metric can slice with the date filters; `Agent Daily` is one
 * row per agent per day, which is what "performance by date" actually wants —
 * it charts directly against a Date axis and stays small (agents × days) no
 * matter how many calls sit behind it.
 */
class CloudTalkProvider implements IntegrationProvider
{
    use CastsValues;

    public const DATASETS = ['Agents', 'Campaigns', 'Calls', 'Agent Daily'];

    public function __construct(private CloudTalkClient $client) {}

    public function key(): string
    {
        return 'cloudtalk';
    }

    public function label(): string
    {
        return 'CloudTalk';
    }

    /** Validate the key pair with a live call before saving it. */
    public function connect(Integration $integration, array $credentials): void
    {
        $id = trim($credentials['access_key_id'] ?? '');
        $secret = trim($credentials['access_key_secret'] ?? '');

        if ($id === '' || $secret === '') {
            throw new RuntimeException('A CloudTalk Access Key ID and Access Key Secret are required.');
        }

        $integration->forceFill([
            'provider' => $this->key(),
            'credentials' => ['access_key_id' => $id, 'access_key_secret' => $secret],
        ]);

        // The agent roster is the cheapest endpoint that proves the key works
        // AND that it has the Admin rights the core API needs.
        $agents = $this->client->paginate($integration, '/agents/index.json', ['limit' => 1], maxPages: 1);

        $integration->fill([
            'name' => 'CloudTalk',
            'config' => array_merge($integration->config ?? [], [
                'agent_count' => $agents['itemsCount'],
                'datasets' => $integration->setting('datasets', self::DATASETS),
            ]),
            'status' => 'connected',
        ]);

        $integration->save();
    }

    public function disconnect(Integration $integration): void
    {
        // API keys are revoked in CloudTalk's own settings; nothing to do here.
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

        // Agent identity comes from three places, in this order of authority:
        // the roster (the only source that shows an agent with no campaign and
        // no calls), each campaign's assigned-agent list, and finally an agent
        // id seen on a call that matches neither. Campaigns are fetched
        // whenever agents are wanted, because that is where the membership
        // that makes an agent row meaningful lives.
        $needsAgents = $wants('Agents') || $wants('Calls') || $wants('Agent Daily');

        /** @var array<string, string> $failed dataset => why it cannot be written */
        $failed = [];

        $roster = $needsAgents
            ? $this->attempt(fn () => $this->fetchRoster($integration), $failed, ['Agents'])
            : [];

        $campaigns = ($needsAgents || $wants('Campaigns'))
            ? $this->attempt(fn () => $this->fetchCampaigns($integration), $failed, ['Campaigns'])
            : [];

        // A failed call fetch takes Agents down with it: those rows carry
        // call-derived performance columns, and writing them from an empty
        // result would replace real numbers with zeros everywhere they are
        // charted — worse than keeping yesterday's figures and saying so.
        $calls = ($wants('Calls') || $wants('Agent Daily') || $wants('Agents'))
            ? $this->attempt(fn () => $this->fetchCalls($integration), $failed, ['Calls', 'Agent Daily', 'Agents'])
            : [];

        $agents = $this->withCallOnlyAgents($this->buildAgents($roster, $campaigns), $calls);

        $this->put($context, 'Campaigns', $wants('Campaigns'), $failed, fn () => $this->campaignRows($campaigns));
        $this->put($context, 'Agents', $wants('Agents'), $failed, fn () => $this->agentRows($agents, $calls));
        $this->put($context, 'Calls', $wants('Calls'), $failed, fn () => $this->callRows($calls, $agents));
        $this->put($context, 'Agent Daily', $wants('Agent Daily'), $failed, fn () => $this->agentDailyRows($calls, $agents));
    }

    /**
     * Run a fetch, or note against every dataset it feeds why they cannot be
     * written. Returning [] here is not "no data" — $failed is what stops that
     * emptiness from reaching write().
     *
     * @param  array<string, string>  $failed
     * @param  array<int, string>  $datasets
     */
    private function attempt(callable $fetch, array &$failed, array $datasets): array
    {
        try {
            return $fetch();
        } catch (\Throwable $e) {
            foreach ($datasets as $dataset) {
                $failed[$dataset] ??= $e->getMessage();
            }

            return [];
        }
    }

    /**
     * Write one dataset, unless something it depends on failed.
     *
     * write() replaces a dataset wholesale, so writing after a failed fetch
     * would delete the last good rows AND record the dataset as healthy. A
     * dataset whose source failed is reported failed and left untouched.
     *
     * @param  array<string, string>  $failed
     */
    private function put(SyncContext $context, string $dataset, bool $wanted, array $failed, callable $rows): void
    {
        if (! $wanted) {
            return;
        }

        if (isset($failed[$dataset])) {
            $context->fail($dataset, $failed[$dataset]);

            return;
        }

        $this->guard($context, $dataset, fn () => $context->write($dataset, $rows()));
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
            'Agents' => ['Agent', 'Agent ID', 'Email', 'Campaigns', 'Campaign Count', 'On A Campaign', 'Calls', 'Answered', 'Not Answered', 'Answer Rate', 'Talk Time', 'Avg Talk Time'],
            'Campaigns' => ['Campaign', 'Campaign ID', 'Status', 'Mode', 'Attempts', 'Agents', 'Agent Count'],
            'Calls' => ['Date', 'Time', 'Agent', 'Agent ID', 'Direction', 'Status', 'Answered', 'Talk Time', 'Contact', 'Number', 'Campaigns'],
            'Agent Daily' => ['Date', 'Agent', 'Agent ID', 'Campaigns', 'Calls', 'Answered', 'Not Answered', 'Answer Rate', 'Talk Time', 'Avg Talk Time'],
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

    private function record(?string $id, array $payload, ?string $date = null): array
    {
        return ['external_id' => $id, 'payload' => $payload, 'record_date' => $date];
    }

    /* ===================== field extraction ===================== */

    /**
     * CloudTalk wraps list rows by entity — {"Cdr": {...}, "Agent": {...},
     * "Contact": {...}} — and the exact key names differ between accounts and
     * between the core and dialer APIs. So rather than hard-coding one path,
     * flatten the record once and take the first key whose LAST segment matches.
     *
     * @return array<string, mixed> dotted path => scalar
     */
    private function flatten(mixed $value, string $prefix = ''): array
    {
        if (! is_array($value)) {
            return [$prefix => $value];
        }

        $out = [];

        foreach ($value as $key => $child) {
            $path = $prefix === '' ? (string) $key : $prefix.'.'.$key;

            $out += is_array($child) ? $this->flatten($child, $path) : [$path => $child];
        }

        return $out;
    }

    /**
     * First non-empty value in a flattened record whose final path segment is
     * one of $names. Matching the LAST segment on purpose: it finds
     * "Cdr.talking_time" from "talking_time" while refusing to match
     * "availability_status" for "status" — that one is the agent's current
     * online state, repeated identically on every one of their calls, and
     * reading it as a per-call outcome silently makes every answer rate wrong.
     *
     * @param  array<string, mixed>  $flat
     * @param  array<int, string>  $names
     */
    private function pick(array $flat, array $names, mixed $default = null): mixed
    {
        foreach ($names as $name) {
            foreach ($flat as $path => $value) {
                if ($value === null || $value === '' || $value === []) {
                    continue;
                }

                $tail = str_contains($path, '.') ? substr($path, strrpos($path, '.') + 1) : $path;

                if (strcasecmp($tail, $name) === 0) {
                    return $value;
                }
            }
        }

        return $default;
    }

    /**
     * An agent's display name.
     *
     * Deliberately never CloudTalk's `name` field: on a real account that holds
     * a device/extension label ("362284011005"), not a person, so falling back
     * to it produces a roster of phone numbers. "Agent #id" is a worse label
     * but an honest one.
     */
    private function agentName(array $agent): string
    {
        $flat = $this->flatten($agent);

        $parts = trim(
            $this->str($this->pick($flat, ['firstname', 'first_name'], ''))
            .' '.$this->str($this->pick($flat, ['lastname', 'last_name'], ''))
        );

        if ($parts !== '') {
            return $parts;
        }

        $full = $this->str($this->pick($flat, ['fullname', 'full_name'], ''));
        $id = $this->str($this->pick($flat, ['id'], ''));

        return $full !== '' ? $full : ($id !== '' ? "Agent #{$id}" : 'Unknown agent');
    }

    /** Seconds of talk time on a call record, or null when the record says nothing. */
    private function talkSeconds(array $flat): ?float
    {
        $raw = $this->pick($flat, ['talking_time', 'talk_time', 'billsec', 'call_time', 'duration']);

        return is_numeric($raw) ? (float) $raw : null;
    }

    /**
     * Was the call answered? Determined by talk time alone, on purpose —
     * no status field involved. The only "status" anywhere in CloudTalk's
     * payload is Agent.status, which is the agent's live presence
     * ("offline", "busy", ...), not a call outcome; there's no reliable
     * per-call status field to fall back to. answered_at was considered and
     * rejected too: CloudTalk populates it even on a 0-second connect (a
     * voicemail greeting cut off, an immediate drop, etc.), so its presence
     * doesn't mean anyone actually talked.
     */
    private function wasAnswered(?float $seconds): ?bool
    {
        return $seconds === null ? null : $seconds > 0;
    }

    /* ===================== fetchers ===================== */

    /**
     * The full agent roster. This is the only source that surfaces an agent
     * with no campaign assignment AND no calls in the window, which is exactly
     * the agent a performance review needs to see.
     */
    private function fetchRoster(Integration $i): array
    {
        return $this->client->paginate($i, '/agents/index.json')['data'];
    }

    private function fetchCampaigns(Integration $i): array
    {
        return $this->client->paginate($i, '/campaigns/index.json')['data'];
    }

    /**
     * Call history for the configured look-back window.
     *
     * The window is mandatory and bounded: call history has no cheap "since"
     * cursor, every row is stored locally, and RecordReader loads a whole
     * dataset into memory — so an unbounded pull would eventually take the
     * dashboard down. Widen `days_back` deliberately, not by accident.
     */
    protected function fetchCalls(Integration $i): array
    {
        $daysBack = max(1, (int) config('integrations.cloudtalk.days_back', 30));
        $today = Carbon::today(BusinessTimezone::NAME);

        return $this->client->paginate($i, '/calls/index.json', [
            'date_from' => $today->copy()->subDays($daysBack - 1)->format('Y-m-d 00:00:00'),
            'date_to' => $today->format('Y-m-d 23:59:59'),
        ])['data'];
    }

    /* ===================== row shaping ===================== */

    /**
     * Agent id => {id, name, campaigns}, merged from the roster and each
     * campaign's assigned-agent list.
     *
     * @return array<string, array{id: string, name: string, email: string, campaigns: array<int, string>}>
     */
    private function buildAgents(array $roster, array $campaigns): array
    {
        $agents = [];

        foreach ($roster as $entry) {
            $agent = is_array($entry['Agent'] ?? null) ? $entry['Agent'] : $entry;
            $flat = $this->flatten($agent);
            $id = $this->str($this->pick($flat, ['id'], ''));

            if ($id === '') {
                continue;
            }

            $agents[$id] = [
                'id' => $id,
                'name' => $this->agentName($agent),
                'email' => $this->str($this->pick($flat, ['email'], '')),
                'campaigns' => [],
            ];
        }

        foreach ($campaigns as $wrapper) {
            $campaign = $this->str($this->pick($this->flatten($wrapper['Campaign'] ?? []), ['name'], 'Untitled campaign'));

            foreach ($wrapper['Agent'] ?? [] as $entry) {
                $flat = $this->flatten($entry);
                $id = $this->str($this->pick($flat, ['id'], ''));

                if ($id === '') {
                    continue;
                }

                $agents[$id] ??= ['id' => $id, 'name' => $this->agentName($entry), 'email' => '', 'campaigns' => []];
                $agents[$id]['campaigns'][] = $campaign;
            }
        }

        return $agents;
    }

    /** Add a bare record for any agent only ever seen on a call. */
    private function withCallOnlyAgents(array $agents, array $calls): array
    {
        foreach ($calls as $call) {
            $id = $this->str($this->pick($this->flatten($call), ['agent_id', 'user_id'], ''));

            if ($id !== '' && ! isset($agents[$id])) {
                $agents[$id] = ['id' => $id, 'name' => "Agent #{$id}", 'email' => '', 'campaigns' => []];
            }
        }

        return $agents;
    }

    private function campaignRows(array $campaigns): array
    {
        return array_map(function ($wrapper) {
            $flat = $this->flatten($wrapper['Campaign'] ?? []);
            $agents = array_map(fn ($a) => $this->agentName($a), $wrapper['Agent'] ?? []);
            $id = $this->str($this->pick($flat, ['id'], ''));

            return $this->record($id ?: null, [
                'Campaign' => $this->str($this->pick($flat, ['name'], 'Untitled campaign')),
                'Campaign ID' => $id,
                'Status' => $this->str($this->pick($flat, ['status'], '')),
                'Mode' => ($this->pick($flat, ['is_predictive'])) ? 'Predictive' : 'Manual',
                'Attempts' => $this->num($this->pick($flat, ['attempts'], 0)),
                'Agents' => $this->str($agents),
                'Agent Count' => count($agents),
            ]);
        }, $campaigns);
    }

    /** One row per agent, with their totals over the synced call window. */
    private function agentRows(array $agents, array $calls): array
    {
        $stats = $this->tallyBy($calls, fn () => 'all');

        return array_values(array_map(function ($agent) use ($stats) {
            $tally = $stats[$agent['id']]['all'] ?? null;
            $campaigns = array_values(array_unique($agent['campaigns']));

            return $this->record($agent['id'], [
                'Agent' => $this->str($agent['name']),
                'Agent ID' => $agent['id'],
                'Email' => $this->str($agent['email']),
                'Campaigns' => $this->str($campaigns),
                'Campaign Count' => count($campaigns),
                // A plain Yes/No column so "agents on no campaign" is one filter,
                // not a filter on a count.
                'On A Campaign' => $campaigns ? 'Yes' : 'No',
                ...$this->tallyColumns($tally),
            ]);
        }, $agents));
    }

    protected function callRows(array $calls, array $agents): array
    {
        return array_map(function ($call) use ($agents) {
            $flat = $this->flatten($call);
            $id = $this->str($this->pick($flat, ['agent_id', 'user_id'], ''));
            $answeredAt = $this->pick($flat, ['answered_at']);
            $seconds = $this->talkSeconds($flat);
            $status = $this->pick($flat, ['status']);
            $answered = $this->wasAnswered($seconds);

            return $this->record($this->str($this->pick($flat, ['id'], '')) ?: null, [
                'Date' => $this->date($answeredAt, convertToBusinessTz: false),
                'Time' => $this->clockTime($answeredAt),
                'Agent' => $this->str($agents[$id]['name'] ?? ($id === '' ? 'Unassigned' : "Agent #{$id}")),
                'Agent ID' => $id,
                'Direction' => $this->str($this->pick($flat, ['direction', 'call_type', 'type'], '')),
                'Status' => $this->str($status ?? ''),
                // Three states, not a boolean: "Unknown" says the record did not
                // carry enough to judge, which is not the same as "No".
                'Answered' => $answered === null ? 'Unknown' : ($answered ? 'Yes' : 'No'),
                'Talk Time' => $this->num($seconds ?? 0),
                'Contact' => $this->str($this->pick($flat, ['contact_name', 'contact', 'name'], '')),
                'Number' => $this->str($this->pick($flat, ['public_external_number', 'external_number', 'number', 'phone'], '')),
                'Campaigns' => $this->str(array_values(array_unique($agents[$id]['campaigns'] ?? []))),
            ], $this->date($answeredAt, convertToBusinessTz: false) ?: null);
        }, $calls);
    }

    /**
     * One row per agent per day they made calls — the shape "performance by
     * date" wants, since Date is a real column to chart or filter against and
     * the row count stays agents × days however many calls sit behind it.
     */
    private function agentDailyRows(array $calls, array $agents): array
    {
        $stats = $this->tallyBy($calls, fn (array $flat) => $this->date(
            $this->pick($flat, ['answered_at']),
            convertToBusinessTz: false
        ));

        $rows = [];

        foreach ($stats as $agentId => $days) {
            $agent = $agents[$agentId] ?? ['name' => "Agent #{$agentId}", 'campaigns' => []];

            foreach ($days as $day => $tally) {
                if ($day === '') {
                    continue; // no usable date — it would land in a column nothing can filter
                }

                $rows[] = $this->record($agentId.':'.$day, [
                    'Date' => $day,
                    'Agent' => $this->str($agent['name']),
                    'Agent ID' => (string) $agentId,
                    'Campaigns' => $this->str(array_values(array_unique($agent['campaigns']))),
                    ...$this->tallyColumns($tally),
                ], $day);
            }
        }

        return $rows;
    }

    /**
     * Bucket calls by agent and by whatever key $bucket derives from the call —
     * one bucket for lifetime totals, the call's day for the daily rollup.
     *
     * @return array<string, array<string, array{calls: int, answered: int, notAnswered: int, unknown: int, seconds: float, timed: int}>>
     */
    private function tallyBy(array $calls, callable $bucket): array
    {
        $out = [];

        foreach ($calls as $call) {
            $flat = $this->flatten($call);
            $agentId = $this->str($this->pick($flat, ['agent_id', 'user_id'], ''));

            if ($agentId === '') {
                continue;
            }

            $key = (string) $bucket($flat);
            $tally = $out[$agentId][$key] ??= [
                'calls' => 0, 'answered' => 0, 'notAnswered' => 0, 'unknown' => 0, 'seconds' => 0.0, 'timed' => 0,
            ];

            $tally['calls']++;

            $seconds = $this->talkSeconds($flat);
            if ($seconds !== null) {
                $tally['seconds'] += $seconds;
                $tally['timed']++;
            }

            match ($this->wasAnswered($seconds)) {
                true => $tally['answered']++,
                false => $tally['notAnswered']++,
                default => $tally['unknown']++,
            };

            $out[$agentId][$key] = $tally;
        }

        return $out;
    }

    /** The performance columns shared by the Agents and Agent Daily datasets. */
    private function tallyColumns(?array $tally): array
    {
        $calls = $tally['calls'] ?? 0;
        $answered = $tally['answered'] ?? 0;
        $notAnswered = $tally['notAnswered'] ?? 0;
        $seconds = $tally['seconds'] ?? 0.0;
        $timed = $tally['timed'] ?? 0;

        // Rate over the calls we could actually classify: counting unknowns as
        // misses would punish an agent for a gap in the call record.
        $classified = $answered + $notAnswered;

        return [
            'Calls' => $calls,
            'Answered' => $answered,
            'Not Answered' => $notAnswered,
            'Unclassified' => $tally['unknown'] ?? 0,
            'Answer Rate' => $classified ? round($answered / $classified * 100, 1) : 0,
            'Talk Time' => round($seconds / 60, 1),
            'Avg Talk Time' => $timed ? round($seconds / $timed / 60, 1) : 0,
        ];
    }

    /** The clock time of a call, for a readable Calls row; '' when unparseable. */
    private function clockTime(mixed $value): string
    {
        if (! $value) {
            return '';
        }

        try {
            return is_numeric($value)
                ? Carbon::createFromTimestamp((int) $value)->format('H:i')
                : Carbon::parse((string) $value)->format('H:i');
        } catch (\Throwable) {
            return '';
        }
    }
}