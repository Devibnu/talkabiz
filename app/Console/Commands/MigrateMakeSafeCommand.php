<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Makes all pending migrations idempotent by wrapping:
 *  - Schema::create('x', ...) → if (!Schema::hasTable('x')) { Schema::create(...) }
 *  - $table->type('col') in Schema::table → if (!Schema::hasColumn('tbl','col')) { ... }
 *
 * Usage:
 *   php artisan migrate:make-safe            # dry-run
 *   php artisan migrate:make-safe --force    # actually rewrite files
 */
class MigrateMakeSafeCommand extends Command
{
    protected $signature = 'migrate:make-safe {--force : Actually rewrite files}';
    protected $description = 'Wrap migration Schema calls with existence checks (idempotent)';

    public function handle(): int
    {
        $isDryRun = !$this->option('force');
        $migrated = DB::table('migrations')->pluck('migration')->toArray();
        $files = collect(glob(database_path('migrations/*.php')))->sort()->values();
        
        $modified = 0;
        $skipped = 0;

        foreach ($files as $filePath) {
            $name = pathinfo($filePath, PATHINFO_FILENAME);
            if (in_array($name, $migrated)) continue;

            $original = file_get_contents($filePath);
            $content = $original;

            // Already has hasTable checks — skip
            if (str_contains($content, 'Schema::hasTable') || str_contains($content, 'Schema::hasColumn')) {
                $skipped++;
                continue;
            }

            $changed = false;

            // 1. Wrap Schema::create('table', function (Blueprint $table) { ... });
            //    → if (!Schema::hasTable('table')) { Schema::create(...) }
            $content = preg_replace_callback(
                '/^(\s*)(Schema::create\s*\(\s*[\'"]([a-zA-Z_][a-zA-Z0-9_]*)[\'"])/m',
                function ($m) use (&$changed) {
                    $indent = $m[1];
                    $table = $m[3];
                    $changed = true;
                    return "{$indent}if (!Schema::hasTable('{$table}')) {\n{$indent}    {$m[2]}";
                },
                $content
            );

            // Close the if block after the Schema::create closing });
            if ($changed) {
                // Find each Schema::create block and close the wrapping if
                $content = preg_replace_callback(
                    '/(if \(!Schema::hasTable\([\'"][a-zA-Z_][a-zA-Z0-9_]*[\'"]\)\) \{\n\s+Schema::create\([\'"][a-zA-Z_][a-zA-Z0-9_]*[\'"]\s*,\s*function\s*\([^)]*\)\s*\{)(.*?)(\n\s+\}\s*\)\s*;)/s',
                    function ($m) {
                        // Extract the indent from the Schema::create line
                        preg_match('/^(\s*)if/', $m[0], $indentMatch);
                        $indent = $indentMatch[1] ?? '        ';
                        return $m[1] . $m[2] . $m[3] . "\n{$indent}}";
                    },
                    $content
                );
            }

            // 2. Wrap column adds in Schema::table blocks
            //    Each $table->type('col') → if (!Schema::hasColumn('tbl', 'col')) { $table->type('col')... }
            $content = preg_replace_callback(
                '/Schema::table\s*\(\s*[\'"]([a-zA-Z_][a-zA-Z0-9_]*)[\'"]\s*,\s*function\s*\([^)]*\)\s*\{(.*?)\}\s*\)\s*;/s',
                function ($m) use (&$changed) {
                    $tableName = $m[1];
                    $body = $m[2];

                    // Wrap each column addition line
                    $newBody = preg_replace_callback(
                        '/^(\s+)(\$table\s*->\s*(?:string|text|longText|mediumText|integer|bigInteger|unsignedBigInteger|unsignedInteger|tinyInteger|smallInteger|boolean|float|double|decimal|date|dateTime|timestamp|json|jsonb|binary|char|uuid|ulid|enum|foreignId|foreignUuid)\s*\(\s*[\'"]([a-zA-Z_][a-zA-Z0-9_]*)[\'"].*?;)/m',
                        function ($cm) use ($tableName, &$changed) {
                            $indent = $cm[1];
                            $line = $cm[2];
                            $col = $cm[3];
                            $changed = true;
                            return "{$indent}if (!Schema::hasColumn('{$tableName}', '{$col}')) {\n{$indent}    {$line}\n{$indent}}";
                        },
                        $body
                    );

                    return "Schema::table('{$tableName}', function (Blueprint \$table) {{$newBody}});";
                },
                $content
            );

            if ($content !== $original) {
                $modified++;
                $this->line(($isDryRun ? '[DRY] ' : '[FIX] ') . $name);

                if (!$isDryRun) {
                    file_put_contents($filePath, $content);
                }
            }
        }

        $this->info('');
        $this->info("Modified: {$modified}, Skipped (already safe): {$skipped}");

        if ($isDryRun && $modified > 0) {
            $this->warn('Run with --force to apply changes.');
        }

        return self::SUCCESS;
    }
}
