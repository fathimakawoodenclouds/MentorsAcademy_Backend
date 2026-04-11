<?php

namespace Database\Seeders;

use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

class ActivitySeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $activities = [
            "Swimming", "Karate", "Football", "Basketball", "Chess", 
            "Drama", "Music", "Yoga", "Cricket", "Tennis"
        ];

        foreach ($activities as $name) {
            \App\Models\Activity::updateOrCreate(['name' => $name]);
        }
    }
}
