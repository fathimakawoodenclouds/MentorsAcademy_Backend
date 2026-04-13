<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\MediaFile;
use App\Traits\ApiResponseTrait;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Intervention\Image\Laravel\Facades\Image;

class MediaUploadController extends Controller
{
    use ApiResponseTrait;

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
            'image' => ['file' => 'mimes:jpg,jpeg,png|max:2048'],
            'file'  => ['file' => 'mimes:pdf,doc,docx|max:5120'],
            'audio' => ['file' => 'mimes:mp3,wav,webm|max:5120'],
        };
        $request->validate($rules);

        // Determine subfolder
        $folder = match ($fileType) {
            'image' => 'chat/images',
            'file'  => 'chat/files',
            'audio' => 'chat/audio',
        };

        if ($fileType === 'image') {
            // Compress image using Intervention Image
            $path = $this->compressAndStoreImage($file, $folder);
        } else {
            // Store file as-is
            $path = $file->store($folder, 'public');
        }

        // Get actual stored file size
        $storedSize = Storage::disk('public')->size($path);

        // Create media record
        $media = MediaFile::create([
            'file_name'   => $file->getClientOriginalName(),
            'file_path'   => $path,
            'file_type'   => $fileType,
            'mime_type'    => $file->getMimeType(),
            'file_size'   => $storedSize,
            'uploaded_by' => $request->user()->id,
        ]);

        return $this->successResponse([
            'media_id'       => $media->id,
            'file_name'      => $media->file_name,
            'file_type'      => $media->file_type,
            'file_url'       => $media->file_url,
            'file_size'      => $media->file_size,
            'original_size'  => $file->getSize(),
            'compressed'     => $fileType === 'image',
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

        // Generate unique filename
        $filename = uniqid() . '_' . time() . '.jpg';
        $path = $folder . '/' . $filename;

        // Store compressed image
        Storage::disk('public')->put($path, (string) $encoded);

        return $path;
    }
}
