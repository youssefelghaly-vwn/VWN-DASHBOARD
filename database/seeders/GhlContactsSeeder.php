<?php

namespace Database\Seeders;

use App\Integration\Models\Integration;
use App\Integration\Models\IntegrationRecord;
use App\Integration\Models\SyncRun;
use Illuminate\Database\Seeder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

/**
 * Seeds one 'gohighlevel' Integration, scoped to the Contacts dataset only
 * (per client instruction: build from Contacts API only, nothing else).
 *
 * The rows are shaped exactly like GoHighLevelProvider::transformContact()
 * would produce from a real /contacts/search sync — same native fields
 * (Name, Email, Phone, Company, Type, Tags, Source, Assigned User, Country,
 * Website, Created, Updated) plus the custom fields the client's required
 * lead-field list needs (Contact Title, Company Size, Current Stage,
 * Qualification Status, Lead Score, ICP Segment, Campaign / Ad Source,
 * Last Touch Date, Next Action Date, Notes). Those custom fields don't exist
 * on a fresh GHL location — this seed assumes they've been created, which is
 * called out explicitly in the gap-analysis deliverables.
 */
class GhlContactsSeeder extends Seeder
{
    /** Shared with MetaAdsSeeder so "Campaign / Ad Source" values line up with real campaign names. */
    public const META_LF_CAMPAIGNS = [
        'Meta LF — Virtual Assistant Hiring',
        'Meta LF — Bookkeeping & Admin Support',
        'Meta LF — Executive Assistant Q3',
        'Meta LF — Remote Staffing Awareness',
    ];

    public const META_BC_CAMPAIGNS = [
        'Meta BC — Free Staffing Consultation',
        'Meta BC — Book a Call: Scale Your Team',
    ];

    public const META_CAMPAIGNS = [...self::META_LF_CAMPAIGNS, ...self::META_BC_CAMPAIGNS];

    public const SDR_OWNERS = [
        'Abdallah Elaswar',
        'Mark Mokbel',
        'Sarah Haddad',
        'Karim Tannous',
        'Nour Fakhoury',
    ];

    private const ICP_SEGMENTS = [
        'Enterprise SaaS', 'Healthcare & Wellness', 'E-commerce & Retail',
        'Real Estate', 'Professional Services', 'Financial Services', 'Logistics & Supply Chain',
    ];

    private const TITLES = [
        'CEO', 'Founder', 'COO', 'Operations Manager', 'VP Sales', 'HR Director',
        'Office Manager', 'Practice Manager', 'Owner', 'General Manager',
        'Director of Operations', 'Head of Growth', 'Finance Manager',
    ];

    private const COMPANY_SIZES = ['1-10', '11-50', '51-200', '201-500', '500+'];

    private const COUNTRIES = ['US', 'US', 'US', 'CA', 'CA', 'GB', 'AE', 'AU'];

    /**
     * Source category => ['kind' => meta|outbound|other, 'weight' => int].
     * Weights sum to the total contact count generated below.
     */
    private const SOURCES = [
        'Meta — Lead Form' => ['kind' => 'meta', 'weight' => 150],
        'Meta — Booked Call' => ['kind' => 'meta', 'weight' => 40],
        'Apollo — Outbound Email' => ['kind' => 'outbound', 'weight' => 120],
        'LinkedIn — Manual' => ['kind' => 'outbound', 'weight' => 60],
        'Cold Call' => ['kind' => 'outbound', 'weight' => 30],
        'Referral / Organic' => ['kind' => 'other', 'weight' => 20],
    ];

    public function run(): void
    {
        $integration = Integration::updateOrCreate(
            ['provider' => 'gohighlevel', 'name' => 'GoHighLevel — VWN Sales + CD'],
            [
                'credentials' => ['access_token' => null, 'location_id' => 'PNCMlG4voJswQZCqs2Uh', 'location_name' => 'VWN Sales + CD'],
                'config' => ['datasets' => ['Contacts'], 'account_name' => 'VWN Sales + CD'],
                'status' => 'connected',
                'last_synced_at' => now(),
            ]
        );

        $integration->records()->where('dataset', 'Contacts')->delete();

        $faker = \Faker\Factory::create();
        $rows = [];

        foreach (self::SOURCES as $source => $profile) {
            for ($i = 0; $i < $profile['weight']; $i++) {
                $rows[] = $this->buildContact($faker, $source, $profile['kind']);
            }
        }

        shuffle($rows);

        $now = now();
        $data = array_map(fn (array $payload) => [
            'integration_id' => $integration->id,
            'dataset' => 'Contacts',
            'external_id' => (string) Str::uuid(),
            'payload' => json_encode($payload),
            'record_date' => null,
            'created_at' => $now,
            'updated_at' => $now,
        ], $rows);

        foreach (array_chunk($data, 200) as $chunk) {
            IntegrationRecord::insert($chunk);
        }

        SyncRun::create([
            'integration_id' => $integration->id,
            'status' => SyncRun::SUCCESS,
            'started_at' => $now->copy()->subSeconds(4),
            'finished_at' => $now,
            'records_synced' => count($rows),
            'failed_records' => 0,
            'meta' => ['datasets' => [
                'Contacts' => ['ok' => true, 'count' => count($rows), 'ms' => 3800, 'error' => null],
            ]],
        ]);

        $this->command?->info('Seeded '.count($rows).' GHL contacts on integration #'.$integration->id);
    }

    private function buildContact(\Faker\Generator $faker, string $source, string $kind): array
    {
        $created = Carbon::now()->subDays(random_int(0, 90))->subHours(random_int(0, 23));
        $updated = (clone $created)->addDays(random_int(0, min(30, (int) $created->diffInDays(now()))));

        [$qualification, $stage] = $this->qualificationAndStage($kind);
        [$nextActionDate, $followUpStatus] = $this->nextAction();

        $owner = $this->pickOwner($kind);
        $company = $faker->company();

        return [
            'Name' => $faker->name(),
            'Email' => $faker->unique()->safeEmail(),
            'Phone' => $faker->boolean(78) ? $faker->numerify('+1##########') : '',
            'Company' => $company,
            'Type' => $faker->boolean(92) ? 'lead' : 'customer',
            'Tags' => $this->tagsFor($source),
            'Source' => $source,
            'Assigned User' => $owner,
            'Country' => self::COUNTRIES[array_rand(self::COUNTRIES)],
            'Website' => 'https://www.'.Str::slug($company).'.com',
            'Created' => $created->toDateString(),
            'Updated' => $updated->toDateString(),

            // Custom fields (require matching Custom Field definitions in GHL — see gap analysis).
            'Contact Title' => self::TITLES[array_rand(self::TITLES)],
            'Company Size' => self::COMPANY_SIZES[array_rand(self::COMPANY_SIZES)],
            'Current Stage' => $stage,
            'Qualification Status' => $qualification,
            'Lead Score' => $kind === 'outbound' ? $this->leadScore() : '',
            'ICP Segment' => $kind === 'outbound' ? self::ICP_SEGMENTS[array_rand(self::ICP_SEGMENTS)] : '',
            'Campaign / Ad Source' => $kind === 'meta' ? $this->campaignFor($source) : '',
            'Last Touch Date' => $updated->toDateString(),
            'Next Action Date' => $nextActionDate,
            'Follow-up Status' => $followUpStatus,
            'Notes' => $this->noteFor($qualification, $stage),

            // Numeric 0/1 indicators, derived from Current Stage / Qualification Status /
            // Follow-up Status above. The chart builder only aggregates (sum/avg/count) a
            // single value column grouped by one label column — it has no filter — so these
            // let "X by SDR" / "X by Source" charts and conversion-rate charts work without
            // engine changes. See the Word report's "Chart filters" recommendation.
            'Contacted Flag' => $stage === 'New Lead' ? 0 : 1,
            'Booked Call Flag' => $stage === 'Booked Call' ? 1 : 0,
            'Qualified Call Flag' => $stage === 'Qualified Call Completed' ? 1 : 0,
            // Same two flags scaled to 0/100 so avg() grouped by Source reads as a
            // conversion percentage directly (Chart has no filter, only sum/avg/count).
            'Booked Call Rate' => $stage === 'Booked Call' ? 100 : 0,
            'Qualified Call Rate' => $stage === 'Qualified Call Completed' ? 100 : 0,
            'Qualified Flag' => $qualification === 'Qualified' ? 1 : 0,
            'Job Seeker Flag' => $qualification === 'Job Seeker' ? 1 : 0,
            'Bad Fit Flag' => $qualification === 'Bad Fit' ? 1 : 0,
            'Not Qualified Flag' => $qualification === 'Not Qualified' ? 1 : 0,
            'Overdue Flag' => $followUpStatus === 'Overdue' ? 1 : 0,
            'No Next Action Flag' => $followUpStatus === 'No Next Action' ? 1 : 0,
            'Scheduled Follow-up Flag' => $followUpStatus === 'Scheduled' ? 1 : 0,
        ];
    }

    private function pickOwner(string $kind): string
    {
        $unassignedChance = $kind === 'meta' ? 15 : 3;

        if (random_int(1, 100) <= $unassignedChance) {
            return 'Unassigned';
        }

        return self::SDR_OWNERS[array_rand(self::SDR_OWNERS)];
    }

    private function leadScore(): string
    {
        $roll = random_int(1, 100);

        return match (true) {
            $roll <= 15 => 'A',
            $roll <= 50 => 'B',
            $roll <= 85 => 'C',
            default => 'D',
        };
    }

    /** @return array{0:string,1:string} [qualification status, current stage] */
    private function qualificationAndStage(string $kind): array
    {
        $roll = random_int(1, 100);

        $qualification = match ($kind) {
            'meta' => match (true) {
                $roll <= 40 => 'Job Seeker',
                $roll <= 60 => 'Bad Fit',
                $roll <= 85 => 'Qualified',
                $roll <= 95 => 'Not Qualified',
                default => 'Pending Review',
            },
            'outbound' => match (true) {
                $roll <= 35 => 'Qualified',
                $roll <= 65 => 'Not Qualified',
                $roll <= 75 => 'Bad Fit',
                default => 'Pending Review',
            },
            default => match (true) { // referral / organic
                $roll <= 50 => 'Qualified',
                $roll <= 70 => 'Not Qualified',
                $roll <= 80 => 'Bad Fit',
                default => 'Pending Review',
            },
        };

        $stage = match ($qualification) {
            'Job Seeker', 'Bad Fit' => $this->weightedPick(['Disqualified' => 80, 'Contacted' => 20]),
            'Qualified' => $this->weightedPick(['Booked Call' => 55, 'Qualified Call Completed' => 45]),
            'Not Qualified' => $this->weightedPick(['Contacted' => 30, 'Replied' => 30, 'Disqualified' => 40]),
            default => $this->weightedPick(['New Lead' => 35, 'Contacted' => 35, 'Replied' => 20, 'Interested' => 10]),
        };

        return [$qualification, $stage];
    }

    private function weightedPick(array $weights): string
    {
        $roll = random_int(1, array_sum($weights));
        $acc = 0;

        foreach ($weights as $value => $weight) {
            $acc += $weight;
            if ($roll <= $acc) {
                return $value;
            }
        }

        return array_key_first($weights);
    }

    /** @return array{0:string,1:string} [next action date ('' if none), Follow-up Status] */
    private function nextAction(): array
    {
        $roll = random_int(1, 100);

        return match (true) {
            $roll <= 20 => ['', 'No Next Action'],
            $roll <= 55 => [now()->subDays(random_int(1, 21))->toDateString(), 'Overdue'],
            default => [now()->addDays(random_int(0, 14))->toDateString(), 'Scheduled'],
        };
    }

    private function noteFor(string $qualification, string $stage): string
    {
        if (random_int(1, 100) <= 15) {
            return '';
        }

        return match ($qualification) {
            'Job Seeker' => 'Appears to be looking for personal employment, not a business decision-maker — not a fit for staffing services.',
            'Bad Fit' => 'Outside ICP — no budget / no current hiring need.',
            'Qualified' => $stage === 'Booked Call'
                ? 'Call booked — confirmed interest in virtual staffing support.'
                : 'Discovery call completed, moving to proposal.',
            'Not Qualified' => 'Replied but not ready to move forward at this time.',
            default => 'Awaiting response, follow-up scheduled.',
        };
    }

    private function campaignFor(string $source): string
    {
        $pool = $source === 'Meta — Booked Call' ? self::META_BC_CAMPAIGNS : self::META_LF_CAMPAIGNS;

        return $pool[array_rand($pool)];
    }

    private function tagsFor(string $source): string
    {
        $slug = match ($source) {
            'Meta — Lead Form' => 'meta-lead-form',
            'Meta — Booked Call' => 'meta-booked-call',
            'Apollo — Outbound Email' => 'apollo-outbound',
            'LinkedIn — Manual' => 'linked-in leads',
            'Cold Call' => 'cold-call',
            default => 'referral-organic',
        };

        return $slug;
    }
}
