<?php
$dir = __DIR__ . '/app/Models';
$files = glob($dir . '/*.php');
foreach ($files as $file) {
    if (strpos($file, 'MediaFile.php') !== false) continue;
    $content = file_get_contents($file);
    if (strpos($content, 'SoftDeletes') === false && strpos($content, 'class') !== false && strpos($content, 'extends Model') !== false) {
        // Add use Illuminate\Database\Eloquent\SoftDeletes; at top
        $content = str_replace('use Illuminate\Database\Eloquent\Model;', "use Illuminate\Database\Eloquent\Model;\nuse Illuminate\Database\Eloquent\SoftDeletes;", $content);
        // Inject trait inside class
        $content = preg_replace('/(class\s+[a-zA-Z0-9_]+\s+extends\s+Model\s*\{(?:\s*use HasFactory;)?)/s', "$1\n    use SoftDeletes;", $content);
        file_put_contents($file, $content);
    }
}
// For User model
$userContent = file_get_contents($dir . '/User.php');
if (strpos($userContent, 'use SoftDeletes') === false) {
    if (strpos($userContent, 'use Illuminate\Database\Eloquent\SoftDeletes;') === false) {
        $userContent = str_replace('use Illuminate\Notifications\Notifiable;', "use Illuminate\Notifications\Notifiable;\nuse Illuminate\Database\Eloquent\SoftDeletes;", $userContent);
    }
    $userContent = str_replace('use HasFactory, Notifiable, HasRoles;', 'use HasFactory, Notifiable, HasRoles, SoftDeletes;', $userContent);
    file_put_contents($dir . '/User.php', $userContent);
}

echo "Patched all models with SoftDeletes\n";
