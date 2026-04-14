<?php

namespace App\Services;

use App\Models\SalesGpsPing;
use Carbon\Carbon;
use Illuminate\Support\Collection;

class SalesTrackingService
{
    /**
     * Haversine distance between two WGS84 coordinates (kilometers).
     */
    public static function haversineKm(float $lat1, float $lon1, float $lat2, float $lon2): float
    {
        $earthKm = 6371.0;
        $dLat = deg2rad($lat2 - $lat1);
        $dLon = deg2rad($lon2 - $lon1);
        $a = sin($dLat / 2) ** 2
            + cos(deg2rad($lat1)) * cos(deg2rad($lat2)) * sin($dLon / 2) ** 2;

        return $earthKm * 2 * atan2(sqrt($a), sqrt(1 - $a));
    }

    /**
     * Sum sequential segment distances for pings in a time window (ordered by time).
     */
    public function sumPingTrailKm(int $salesExecutiveId, Carbon $from, Carbon $to): float
    {
        $pings = SalesGpsPing::query()
            ->where('sales_executive_id', $salesExecutiveId)
            ->whereBetween('recorded_at', [$from, $to])
            ->orderBy('recorded_at')
            ->orderBy('id')
            ->get(['latitude', 'longitude']);

        if ($pings->count() < 2) {
            return 0.0;
        }

        $total = 0.0;
        $prev = $pings->first();
        foreach ($pings->slice(1) as $cur) {
            $total += self::haversineKm(
                (float) $prev->latitude,
                (float) $prev->longitude,
                (float) $cur->latitude,
                (float) $cur->longitude
            );
            $prev = $cur;
        }

        return round($total, 3);
    }

    /**
     * @return Collection<int, SalesGpsPing>
     */
    public function pingsBetween(int $salesExecutiveId, Carbon $from, Carbon $to): Collection
    {
        return SalesGpsPing::query()
            ->where('sales_executive_id', $salesExecutiveId)
            ->whereBetween('recorded_at', [$from, $to])
            ->orderBy('recorded_at')
            ->orderBy('id')
            ->get();
    }
}
