<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\MediaFile;
use App\Traits\ApiResponseTrait;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Intervention\Image\Laravel\Facades\Image;
use Throwable;

class MediaUploadController extends Controller
{
    use ApiResponseTrait;

    /**
     * Paginated library of uploaded media (super-admin / shared uploads).
     */
    public function index(Request $request)
    {
        $request->validate([
            'file_type' => 'sometimes|in:image,file,audio,all',
            'q' => 'sometimes|string|max:255',
            'per_page' => 'sometimes|integer|min:1|max:60',
            'page' => 'sometimes|integer|min:1',
        ]);

        $query = MediaFile::query()->orderByDesc('created_at');

        if ($request->filled('file_type') && $request->input('file_type') !== 'all') {
            $query->where('file_type', $request->input('file_type'));
        }

        if ($request->filled('q')) {
            $s = $request->input('q');
            $query->where('file_name', 'like', '%'.$s.'%');
        }

        $perPage = min(max($request->integer('per_page', 24), 1), 60);
        $paginator = $query->paginate($perPage);

        $paginator->getCollection()->transform(function (MediaFile $m) {
            return [
                'id' => $m->id,
                'file_name' => $m->file_name,
                'file_type' => $m->file_type,
                'mime_type' => $m->mime_type,
                'file_url' => $m->file_url,
                'file_size' => $m->file_size,
                'created_at' => $m->created_at?->toIso8601String(),
            ];
        });

        return $this->successResponse([
            'items' => $paginator->items(),
            'total' => $paginator->total(),
            'per_page' => $paginator->perPage(),
            'current_page' => $paginator->currentPage(),
            'last_page' => $paginator->lastPage(),
        ], 'Media files retrieved successfully');
    }

    /**
     * Upload a media file for chat.
     * Images are compressed to save storage.
     * Uses Storage facade for S3-ready architecture.
     */
    public function upload(Request $request)
    {
        $request->validate([
            'file' => 'required|file',
            'file_type' => 'required|in:image,file,audio',
        ]);

        $file = $request->file('file');
        $fileType = $request->input('file_type');

        // Type-specific validation
        $rules = match ($fileType) {
            'image' => ['file' => 'mimes:jpg,jpeg,png,gif,webp|max:5120'],
            'file' => ['file' => 'mimes:pdf,doc,docx|max:5120'],
            'audio' => ['file' => 'mimes:mp3,wav,webm|max:5120'],
        };
        $request->validate($rules);

        // Determine subfolder
        $folder = match ($fileType) {
            'image' => 'chat/images',
            'file' => 'chat/files',
            'audio' => 'chat/audio',
        };

        if ($fileType === 'image') {
            // Compress image when GD/Imagick is available, otherwise gracefully fall back.
            try {
                $path = $this->compressAndStoreImage($file, $folder);
            } catch (Throwable $e) {
                Log::warning('Image compression unavailable, storing original image', [
                    'reason' => $e->getMessage(),
                    'user_id' => $request->user()->id,
                ]);

                $originalFileName = $this->buildUniqueOriginalFileName($file->getClientOriginalName(), $folder);
                $path = $file->storeAs($folder, $originalFileName, 'public');
            }
        } else {
            // Store file as-is while preserving original file name
            $originalFileName = $this->buildUniqueOriginalFileName($file->getClientOriginalName(), $folder);
            $path = $file->storeAs($folder, $originalFileName, 'public');
        }

        // Get actual stored file size
        $storedSize = Storage::disk('public')->size($path);

        // Create media record
        $media = MediaFile::create([
            'file_name' => basename($file->getClientOriginalName()),
            'file_path' => $path,
            'file_type' => $fileType,
            'mime_type' => $file->getMimeType(),
            'file_size' => $storedSize,
            'uploaded_by' => $request->user()->id,
        ]);

        return $this->successResponse([
            'media_id' => $media->id,
            'file_name' => $media->file_name,
            'file_type' => $media->file_type,
            'file_url' => $media->file_url,
            'file_size' => $media->file_size,
            'original_size' => $file->getSize(),
            'compressed' => $fileType === 'image',
        ], 'File uploaded successfully', 201);
    }

    /**
     * Compress image to 80% quality, resize if larger than 1200px,
     * and store to the public disk.
     */
    private function compressAndStoreImage($file, string $folder): string
    {
        $image = Image::read($file->getRealPath());

        // Resize if wider than 1200px (maintain aspect ratio)
        $width = $image->width();
        if ($width > 1200) {
            $image->scale(width: 1200);
        }

        // Encode to JPEG at 80% quality
        $encoded = $image->toJpeg(80);

        // Keep a readable image name while storing JPEG output
        $baseName = pathinfo(basename($file->getClientOriginalName()), PATHINFO_FILENAME);
        $normalizedBaseName = Str::slug($baseName, '_') ?: 'image';
        $filename = $this->buildUniqueOriginalFileName($normalizedBaseName.'.jpg', $folder);
        $path = $folder.'/'.$filename;

        // Store compressed image
        Storage::disk('public')->put($path, (string) $encoded);

        return $path;
    }

    private function buildUniqueOriginalFileName(string $originalName, string $folder): string
    {
        $safeOriginalName = basename($originalName);
        $name = pathinfo($safeOriginalName, PATHINFO_FILENAME);
        $extension = pathinfo($safeOriginalName, PATHINFO_EXTENSION);

        $normalizedName = trim(preg_replace('/[^A-Za-z0-9_\-\s]/', '', $name)) ?: 'file';
        $candidate = $extension ? "{$normalizedName}.{$extension}" : $normalizedName;
        $counter = 1;

        while (Storage::disk('public')->exists("{$folder}/{$candidate}")) {
            $candidate = $extension
                ? "{$normalizedName}_{$counter}.{$extension}"
                : "{$normalizedName}_{$counter}";
            $counter++;
        }

        return $candidate;
    }
}
