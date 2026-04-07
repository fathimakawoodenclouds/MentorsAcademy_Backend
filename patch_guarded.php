<?php
$dir = __DIR__ . '/app/Models';
$files = glob($dir . '/*.php');
foreach ($files as $file) {
    if (strpos($file, 'User.php') !== false) continue; // User uses Fillable Attributes natively
    if (strpos($file, 'MediaFile.php') !== false) continue; // Already added manually
    if (strpos($file, 'StaffProfile.php') !== false) continue; // Just added
    
    $content = file_get_contents($file);
    if (strpos($content, '$guarded') === false && strpos($content, '$fillable') === false) {
        $content = str_replace('use SoftDeletes;', "use SoftDeletes;\n\n    protected \$guarded = [];", $content);
        file_put_contents($file, $content);
    }
}
echo "Added guarded property to all models!\n";
