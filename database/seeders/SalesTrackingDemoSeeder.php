<?php

namespace Database\Seeders;

use App\Models\SalesExecutive;
use App\Models\SalesGpsPing;
use App\Models\SalesIncentiveRecord;
use App\Models\SalesVisit;
use Carbon\Carbon;
use Illuminate\Database\Seeder;

/**
 * Optional demo data for GPS trail, visits, and incentives.
 * Run: php artisan db:seed --class=SalesTrackingDemoSeeder
 */
class SalesTrackingDemoSeeder extends Seeder
{
    public function run(): void
    {
        $exec = SalesExecutive::query()->orderBy('id')->first();
        if (! $exec) {
            $this->command?->warn('No sales executive found. Create one first.');

            return;
        }

        $baseLat = 28.6139;
        $baseLng = 77.2090;
        $now = Carbon::now();

        for ($i = 0; $i < 8; $i++) {
            SalesGpsPing::create([
                'sales_executive_id' => $exec->id,
                'latitude' => $baseLat + ($i * 0.0008) + (sin($i) * 0.0002),
                'longitude' => $baseLng + ($i * 0.0005) + (cos($i) * 0.0002),
                'accuracy_meters' => 12,
                'source' => 'simulated',
                'recorded_at' => $now->copy()->subMinutes(30 - $i * 3),
            ]);
        }

        SalesVisit::create([
            'sales_executive_id' => $exec->id,
            'school_id' => null,
            'school_name_snapshot' => 'Demo Public School',
            'location_label' => 'New Delhi, Delhi',
            'latitude' => $baseLat,
            'longitude' => $baseLng,
            'visited_at' => $now->copy()->subDay(),
            'purpose' => 'Product demo',
            'status' => 'completed',
            'distance_km' => 4.2,
        ]);

        $month = $now->format('Y-m');
        SalesIncentiveRecord::create([
            'sales_executive_id' => $exec->id,
            'product_category' => 'curriculum_kits',
            'quantity' => 10,
            'unit_amount' => 500,
            'total_amount' => 5000,
            'earned_month' => $month,
        ]);
        SalesIncentiveRecord::create([
            'sales_executive_id' => $exec->id,
            'product_category' => 'software_licenses',
            'quantity' => 3,
            'unit_amount' => 2000,
            'total_amount' => 6000,
            'earned_month' => $month,
        ]);

        $this->command?->info("Demo tracking data created for sales_executive id {$exec->id}.");
    }
}
