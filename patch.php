<?php
$dir = __DIR__ . '/database/migrations';
$files = glob($dir . '/*.php');
foreach ($files as $file) {
    $content = file_get_contents($file);
    // Basic detection for Schema::create
    if (strpos($content, 'Schema::create(') !== false || strpos($content, "Schema::table('users',") !== false) {
        if (strpos($content, '$table->softDeletes();') === false) {
            // Only replace the first closure ending in up() method.
            // A simple regex to replace the first `        });` inside the up method
            $content = preg_replace('/(\s*)\}\);/', "$1    \$table->softDeletes();\n$1});", $content, 1);
            file_put_contents($file, $content);
        }
    }
}
echo "Patched all migrations with softDeletes\n";
