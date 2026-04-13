<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('media_files', function (Blueprint $table) {
            // Drop old file_url column
            $table->dropColumn('file_url');

            // Add new columns
            $table->string('file_path')->after('file_name');
            $table->string('mime_type')->nullable()->after('file_type');

            // Add index on uploaded_by for performance
            $table->index('uploaded_by');
        });
    }

    public function down(): void
    {
        Schema::table('media_files', function (Blueprint $table) {
            $table->text('file_url')->after('file_size');
            $table->dropColumn(['file_path', 'mime_type']);
            $table->dropIndex(['uploaded_by']);
        });
    }
};
