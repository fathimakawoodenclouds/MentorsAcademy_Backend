<?php

namespace App\Support;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class UniqueSlug
{
    public static function for(string $table, string $name, ?int $exceptId = null): string
    {
        $base = Str::slug($name) ?: 'item';
        $slug = $base;
        $n = 1;

        while (
            DB::table($table)
                ->where('slug', $slug)
                ->when($exceptId !== null, fn ($q) => $q->where('id', '!=', $exceptId))
                ->exists()
        ) {
            $slug = $base.'-'.$n++;
        }

        return $slug;
    }
}
