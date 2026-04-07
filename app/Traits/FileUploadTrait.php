<?php

namespace App\Traits;

use App\Models\MediaFile;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

trait FileUploadTrait
{
    /**
     * Upload a file and record it in the media_files table.
     */
    public function uploadFile(UploadedFile $file, string $directory = 'uploads', ?int $userId = null): MediaFile
    {
        $path = $file->store($directory, 'public'); 
        
        $mimeType = $file->getClientMimeType();
        $fileType = 'file';
        if (str_starts_with($mimeType, 'image/')) {
            $fileType = 'image';
        } elseif (str_starts_with($mimeType, 'audio/')) {
            $fileType = 'audio';
        }

        return MediaFile::create([
            'file_name' => $file->getClientOriginalName(),
            'file_url' => Storage::disk('public')->url($path),
            'file_type' => $fileType,
            'file_size' => $file->getSize(),
            'uploaded_by' => $userId,
        ]);
    }

    /**
     * Delete an uploaded file and its database record (Soft Delete applied automatically contextually).
     */
    public function deleteFile(MediaFile $mediaFile, string $disk = 'public'): bool
    {
        // Actually purging the physical file might be reserved for forceDelete, 
        // but for now we follow soft delete logic (keep file physically, soft delete DB record).
        return $mediaFile->delete();
    }
}
