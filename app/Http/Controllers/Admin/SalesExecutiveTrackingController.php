<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\SalesExecutive;
use App\Models\SalesGpsPing;
use App\Models\SalesIncentiveRecord;
use App\Models\SalesVisit;
use App\Services\SalesTrackingService;
use App\Traits\ApiResponseTrait;
use Carbon\Carbon;
use Illuminate\Http\Request;

/**
 * GPS / visits / TA-distance / incentives.
 *
 * Mobile app: POST JSON to `v1/sales-executive/tracking/ping` (Sanctum + sales_executive role).
 * Payload: latitude, longitude, optional accuracy_meters, recorded_at. Source is stored as mobile_app.
 */
class SalesExecutiveTrackingController extends Controller
{
    use ApiResponseTrait;

    public function __construct(
        protected SalesTrackingService $tracking
    ) {}

    public function summary(Request $request, string $id)
    {
        $exec = SalesExecutive::with(['user.staffProfile'])->find($id);
        if (! $exec) {
            return $this->errorResponse('Sales Executive not found', 404);
        }

        $now = Carbon::now();
        $startOfDay = $now->copy()->startOfDay();
        $startOfMonth = $now->copy()->startOfMonth();

        $latest = SalesGpsPing::query()
            ->where('sales_executive_id', $exec->id)
            ->orderByDesc('recorded_at')
            ->orderByDesc('id')
            ->first();

        $trackingActive = $latest && $latest->recorded_at->greaterThan($now->copy()->subMinutes(15));

        $todayKm = $this->tracking->sumPingTrailKm($exec->id, $startOfDay, $now);
        $monthKm = $this->tracking->sumPingTrailKm($exec->id, $startOfMonth, $now);

        $taPerKm = (float) $exec->ta_allowance;
        $taToday = round($todayKm * $taPerKm, 2);
        $taMonth = round($monthKm * $taPerKm, 2);

        $monthKey = $now->format('Y-m');
        $incentiveRows = SalesIncentiveRecord::query()
            ->where('sales_executive_id', $exec->id)
            ->where('earned_month', $monthKey)
            ->get();

        $curriculum = (float) $incentiveRows->where('product_category', 'curriculum_kits')->sum('total_amount');
        $software = (float) $incentiveRows->where('product_category', 'software_licenses')->sum('total_amount');

        $visitsPreview = SalesVisit::query()
            ->where('sales_executive_id', $exec->id)
            ->with(['school:id,name,school_id,city,state'])
            ->orderByDesc('visited_at')
            ->limit(3)
            ->get()
            ->map(fn (SalesVisit $v) => $this->transformVisit($v));

        $defaultLat = 28.6139;
        $defaultLng = 77.2090;

        return $this->successResponse([
            'latest' => $latest ? [
                'latitude' => (float) $latest->latitude,
                'longitude' => (float) $latest->longitude,
                'recorded_at' => $latest->recorded_at->toIso8601String(),
                'source' => $latest->source,
            ] : null,
            'fallback_center' => [
                'latitude' => $defaultLat,
                'longitude' => $defaultLng,
            ],
            'tracking_active' => $trackingActive,
            'today_distance_km' => $todayKm,
            'month_distance_km' => $monthKm,
            'ta_per_km' => $taPerKm,
            'ta_today_estimate' => $taToday,
            'ta_month_estimate' => $taMonth,
            'visits_preview' => $visitsPreview,
            'incentives_month' => [
                'month' => $monthKey,
                'curriculum_kits' => $curriculum,
                'software_licenses' => $software,
                'total' => $curriculum + $software,
            ],
        ], 'Tracking summary');
    }

    public function storePing(Request $request, string $id)
    {
        $exec = SalesExecutive::find($id);
        if (! $exec) {
            return $this->errorResponse('Sales Executive not found', 404);
        }

        $ping = $this->createPingFromRequest($request, $exec, $request->input('source', 'simulated'));

        return $this->successResponse([
            'id' => $ping->id,
            'latitude' => (float) $ping->latitude,
            'longitude' => (float) $ping->longitude,
            'recorded_at' => $ping->recorded_at->toIso8601String(),
            'source' => $ping->source,
        ], 'Location recorded', 201);
    }

    /**
     * Same persistence as admin ping — for the native app (authenticated sales user).
     */
    public function storeMyPing(Request $request)
    {
        $exec = SalesExecutive::where('user_id', $request->user()->id)->first();
        if (! $exec) {
            return $this->errorResponse('Sales executive profile not linked to this account', 404);
        }

        $ping = $this->createPingFromRequest($request, $exec, 'mobile_app', true);

        return $this->successResponse([
            'id' => $ping->id,
            'latitude' => (float) $ping->latitude,
            'longitude' => (float) $ping->longitude,
            'recorded_at' => $ping->recorded_at->toIso8601String(),
            'source' => $ping->source,
        ], 'Location recorded', 201);
    }

    private function createPingFromRequest(Request $request, SalesExecutive $exec, string $defaultSource, bool $lockSource = false): SalesGpsPing
    {
        $validated = $request->validate([
            'latitude' => 'required|numeric|between:-90,90',
            'longitude' => 'required|numeric|between:-180,180',
            'accuracy_meters' => 'nullable|integer|min:0|max:5000',
            'source' => 'nullable|in:simulated,mobile_app',
            'recorded_at' => 'nullable|date',
        ]);

        $source = $lockSource ? $defaultSource : ($validated['source'] ?? $defaultSource);

        return SalesGpsPing::create([
            'sales_executive_id' => $exec->id,
            'latitude' => $validated['latitude'],
            'longitude' => $validated['longitude'],
            'accuracy_meters' => $validated['accuracy_meters'] ?? null,
            'source' => $source,
            'recorded_at' => isset($validated['recorded_at'])
                ? Carbon::parse($validated['recorded_at'])
                : Carbon::now(),
        ]);
    }

    public function visits(Request $request, string $id)
    {
        $exec = SalesExecutive::find($id);
        if (! $exec) {
            return $this->errorResponse('Sales Executive not found', 404);
        }

        $query = SalesVisit::query()
            ->where('sales_executive_id', $exec->id)
            ->with(['school:id,name,school_id,city,state']);

        if ($request->filled('search')) {
            $s = $request->search;
            $query->where(function ($q) use ($s) {
                $q->where('school_name_snapshot', 'like', "%{$s}%")
                    ->orWhere('location_label', 'like', "%{$s}%")
                    ->orWhere('purpose', 'like', "%{$s}%")
                    ->orWhereHas('school', function ($sq) use ($s) {
                        $sq->where('name', 'like', "%{$s}%")
                            ->orWhere('school_id', 'like', "%{$s}%");
                    });
            });
        }

        if ($request->filled('from_date')) {
            $query->whereDate('visited_at', '>=', $request->from_date);
        }
        if ($request->filled('to_date')) {
            $query->whereDate('visited_at', '<=', $request->to_date);
        }

        $paginated = $query->orderByDesc('visited_at')->paginate($request->integer('per_page', 10));

        $paginated->getCollection()->transform(fn (SalesVisit $v) => $this->transformVisit($v));

        $stats = $this->visitStatsForExecutive($exec->id);
        $payload = $paginated->toArray();
        $payload['stats'] = $stats;

        return response()->json($payload);
    }

    private function visitStatsForExecutive(int $salesExecutiveId): array
    {
        $monthStart = Carbon::now()->startOfMonth();

        return [
            'completed' => SalesVisit::query()->where('sales_executive_id', $salesExecutiveId)->where('status', 'completed')->count(),
            'pending' => SalesVisit::query()->where('sales_executive_id', $salesExecutiveId)->where('status', 'pending')->count(),
            'follow_up' => SalesVisit::query()->where('sales_executive_id', $salesExecutiveId)->where('status', 'follow_up')->count(),
            'visits_this_month' => SalesVisit::query()->where('sales_executive_id', $salesExecutiveId)->where('visited_at', '>=', $monthStart)->count(),
        ];
    }

    private function transformVisit(SalesVisit $v): array
    {
        $school = $v->school;
        $name = $v->school_name_snapshot ?? $school?->name ?? 'Unknown school';
        $schoolCode = $school?->school_id ?? '—';
        $loc = $v->location_label ?? trim(implode(', ', array_filter([$school?->city, $school?->state]))) ?: '—';

        return [
            'id' => $v->id,
            'school' => $name,
            'type' => 'School visit',
            'schoolId' => $schoolCode,
            'location' => $loc,
            'date' => $v->visited_at->format('M j, Y'),
            'rawDate' => $v->visited_at->format('Y-m-d'),
            'time' => $v->visited_at->format('h:i A'),
            'purpose' => $v->purpose ?? '—',
            'status' => ucfirst(str_replace('_', '-', $v->status)),
            'distance_km' => $v->distance_km,
        ];
    }
}
