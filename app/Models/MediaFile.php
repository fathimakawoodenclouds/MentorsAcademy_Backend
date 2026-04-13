<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Eloquent\Concerns\HasUuids;

class MediaFile extends Model
{
    use SoftDeletes, HasUuids;

    protected $guarded = [];

    protected $appends = ['file_url'];

    public function uploader()
    {
        return $this->belongsTo(User::class, 'uploaded_by');
    }

    /**
     * Generate the full URL dynamically from file_path.
     * Ready for future S3 integration via Storage facade.
     */
    public function getFileUrlAttribute(): ?string
    {
        if (!$this->file_path) return null;
        return url('storage/' . $this->file_path);
    }
}
