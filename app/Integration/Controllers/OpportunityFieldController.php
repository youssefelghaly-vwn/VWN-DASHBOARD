<?php

namespace App\Integration\Controllers;

use App\Http\Controllers\Controller;
use App\Integration\Jobs\SyncIntegrationJob;
use App\Integration\Models\Integration;
use App\Integration\Providers\GoHighLevelProvider;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Saves which GoHighLevel opportunity fields become columns.
 *
 * Deliberately NOT part of IntegrationController::update(): that action re-runs
 * the provider's connect() and treats a blank submitted value as "keep the
 * current one", which is exactly wrong for a set — an admin who unticks every
 * field means it, and would otherwise get their previous selection handed back.
 */
class OpportunityFieldController extends Controller
{
    public function update(Request $request, Integration $integration)
    {
        $provider = $integration->provider();

        abort_unless($provider instanceof GoHighLevelProvider, 404);

        $keys = array_column($provider->opportunityFieldCatalogue($integration), 'Key');

        $data = $request->validate([
            // An all-unchecked checkbox group submits no key at all, so the rule
            // is nullable rather than required/present and the miss is read as
            // the empty set below. Anything not in the catalogue this location
            // last synced is rejected, so arbitrary strings can't reach config.
            'fields' => ['nullable', 'array'],
            'fields.*' => ['string', Rule::in($keys)],
        ]);

        $fields = array_values(array_unique($data['fields'] ?? []));

        $integration->updateConfig(['opportunity_fields' => $fields]);

        // Columns are baked into each record's payload at write time, so the new
        // selection only becomes visible once the rows are rewritten.
        SyncIntegrationJob::dispatch($integration->id);

        $count = count($fields);

        return back()->with('status', "Saved {$count} opportunity field(s) for {$integration->name}. A sync is running to apply them.");
    }
}
