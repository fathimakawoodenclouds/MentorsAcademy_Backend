<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\File;

class LocationController extends Controller
{
    private $jsonPath;

    public function __construct()
    {
        $this->jsonPath = database_path('data/india_locations.json');
    }

    /**
     * Get all states of India.
     */
    public function getStates()
    {
        try {
            $states = Cache::remember('india_states', 60 * 60 * 24, function () {
                if (!File::exists($this->jsonPath)) {
                    return [];
                }
                $india = json_decode(File::get($this->jsonPath), true);
                
                if (!$india || !isset($india['states'])) {
                    return [];
                }

                return collect($india['states'])->map(function ($state) {
                    return [
                        'id' => $state['id'],
                        'name' => $state['name']
                    ];
                })->toArray();
            });

            return response()->json($states);
        } catch (\Exception $e) {
            return response()->json(['error' => 'Failed to fetch states: ' . $e->getMessage()], 500);
        }
    }

    /**
     * Get cities for a specific state in India.
     */
    public function getCities($stateId)
    {
        try {
            $cities = Cache::remember("state_cities_{$stateId}", 60 * 60 * 24, function () use ($stateId) {
                if (!File::exists($this->jsonPath)) {
                    return [];
                }
                $india = json_decode(File::get($this->jsonPath), true);
                
                if (!$india || !isset($india['states'])) {
                    return [];
                }

                $state = collect($india['states'])->firstWhere('id', (int)$stateId);
                if (!$state || !isset($state['cities'])) {
                    return [];
                }

                return collect($state['cities'])->map(function ($city) {
                    return [
                        'id' => $city['id'],
                        'name' => $city['name']
                    ];
                })->toArray();
            });

            return response()->json($cities);
        } catch (\Exception $e) {
            return response()->json(['error' => 'Failed to fetch cities: ' . $e->getMessage()], 500);
        }
    }
}
