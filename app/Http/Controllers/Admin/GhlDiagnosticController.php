<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\GhlConnection;
use App\Services\Ghl\GhlClient;

/**
 * Hits each GHL resource endpoint one at a time and reports which succeed and
 * which fail, with GHL's own error text. This turns a single opaque 401 into a
 * per-resource checklist so you know exactly which scope to add.
 *
 * Visit /admin/ghl/diagnose while connected.
 */
class GhlDiagnosticController extends Controller
{
    public function __construct(private GhlClient $client) {}

    public function __invoke()
    {
        $c = GhlConnection::current();

        if (! $c) {
            return response()->json(['error' => 'Not connected.'], 400);
        }

        $loc = $c->location_id;

        // Each probe: [label, path, query]. Kept to 1 record where possible.
        $probes = [
            'location'      => ['/locations/'.$loc, []],
            'users'         => ['/users/', ['locationId' => $loc, 'limit' => 1]],
            'pipelines'     => ['/opportunities/pipelines', ['locationId' => $loc]],
            'opportunities' => ['/opportunities/search', ['location_id' => $loc, 'limit' => 1]],
            'contacts'      => ['/contacts/', ['locationId' => $loc, 'limit' => 1]],
            'calendars'     => ['/calendars/', ['locationId' => $loc]],
        ];

        $results = [];

        foreach ($probes as $name => [$path, $query]) {
            try {
                $body = $this->client->get($c, $path, $query);
                $results[$name] = [
                    'ok'      => true,
                    'path'    => $path,
                    'sample'  => $this->summarize($body),
                ];
            } catch (\Throwable $e) {
                $results[$name] = [
                    'ok'    => false,
                    'path'  => $path,
                    'error' => $e->getMessage(),
                ];
            }
        }

        return response()->json([
            'location_id'   => $loc,
            'api_version'   => config('ghl.api_version'),
            'api_base'      => config('ghl.api_base'),
            'results'       => $results,
        ], 200, [], JSON_PRETTY_PRINT);
    }

    /** Show top-level keys + first-item keys so we can verify field names too. */
    private function summarize(array $body): array
    {
        $keys = array_keys($body);
        $out  = ['top_level_keys' => $keys];

        foreach (['users', 'opportunities', 'contacts', 'calendars', 'pipelines'] as $k) {
            if (! empty($body[$k]) && is_array($body[$k][0] ?? null)) {
                $out['first_'.$k.'_keys'] = array_keys($body[$k][0]);
                break;
            }
        }

        return $out;
    }
}