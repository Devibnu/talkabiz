<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Safely synchronise migration history with existing database schema.
 *
 * For every PENDING migration file this command:
 *  1. Parses the file for Schema::create('table_name', …) calls.
 *  2. If ALL tables referenced already exist → marks the migration as
 *     "ran" in the `migrations` table WITHOUT executing it.
 *  3. If any table is missing → leaves it pending for `php artisan migrate`.
 *
 * This is safe for production: no DROP, no data loss, no schema changes.
 *
 * Usage:
 *   php artisan schema:sync            # dry-run (default)
 *   php artisan schema:sync --force     # actually write to migrations table
 */
class SchemaSyncCommand extends Command
{
    protected $signature = 'schema:sync
                            {--force : Actually mark migrations as ran (default is dry-run)}
                            {--batch= : Batch number to use (default: next batch)}';

    protected $description = 'Sync migration history with existing database schema (no data loss)';

    public function handle(): int
    {
        $this->info('');
        $this->info('╔══════════════════════════════════════════════╗');
        $this->info('║       Schema ↔ Migration Sync Tool          ║');
        $this->info('╚══════════════════════════════════════════════╝');
        $this->info('');

        $isDryRun = !$this->option('force');

        if ($isDryRun) {
            $this->warn('🔍 DRY-RUN mode — no changes will be made. Use --force to apply.');
            $this->info('');
        }

        // Get already migrated
        $migrated = DB::table('migrations')->pluck('migration')->toArray();

        // Get all migration files
        $migrationPath = database_path('migrations');
        $files = collect(glob($migrationPath . '/*.php'))->sort()->values();

        // Determine next batch
        $nextBatch = $this->option('batch')
            ? (int) $this->option('batch')
            : (DB::table('migrations')->max('batch') ?? 0) + 1;

        $pending = [];
        $canSync = [];
        $needsMigrate = [];
        $errors = [];

        foreach ($files as $filePath) {
            $migrationName = pathinfo($filePath, PATHINFO_FILENAME);

            // Skip already migrated
            if (in_array($migrationName, $migrated)) {
                continue;
            }

            $pending[] = $migrationName;

            // Parse file for Schema::create calls
            $content = file_get_contents($filePath);
            $createdTables = $this->parseCreatedTables($content);
            $alteredTables = $this->parseAlteredTables($content);
            $addedColumns = $this->parseAddedColumns($content);

            // Decision logic
            if (empty($createdTables) && empty($alteredTables)) {
                // Migration doesn't create or alter tables (seeder, raw SQL, etc.)
                // Check if it has Schema::table calls with addColumn
                if (!empty($addedColumns)) {
                    // It's an "add column" migration — check if columns exist
                    $allColumnsExist = true;
                    foreach ($addedColumns as $table => $columns) {
                        if (!Schema::hasTable($table)) {
                            $allColumnsExist = false;
                            break;
                        }
                        foreach ($columns as $col) {
                            if (!Schema::hasColumn($table, $col)) {
                                $allColumnsExist = false;
                                break 2;
                            }
                        }
                    }
                    if ($allColumnsExist) {
                        $canSync[] = ['name' => $migrationName, 'reason' => 'All columns already exist', 'tables' => array_keys($addedColumns)];
                    } else {
                        $needsMigrate[] = ['name' => $migrationName, 'reason' => 'Some columns missing', 'tables' => array_keys($addedColumns)];
                    }
                } else {
                    // Unknown migration type — leave for manual migrate
                    $needsMigrate[] = ['name' => $migrationName, 'reason' => 'Non-standard migration (manual review)', 'tables' => []];
                }
                continue;
            }

            // Check if ALL created tables already exist
            if (!empty($createdTables)) {
                $allExist = true;
                $missing = [];
                foreach ($createdTables as $table) {
                    if (!Schema::hasTable($table)) {
                        $allExist = false;
                        $missing[] = $table;
                    }
                }

                if ($allExist) {
                    $canSync[] = ['name' => $migrationName, 'reason' => 'All tables exist', 'tables' => $createdTables];
                } else {
                    $needsMigrate[] = ['name' => $migrationName, 'reason' => 'Missing tables: ' . implode(', ', $missing), 'tables' => $createdTables];
                }
                continue;
            }

            // Only alter table — check if table exists
            if (!empty($alteredTables)) {
                $allExist = true;
                foreach ($alteredTables as $table) {
                    if (!Schema::hasTable($table)) {
                        $allExist = false;
                        break;
                    }
                }

                // For alter-only, we check if columns already added
                if ($allExist && !empty($addedColumns)) {
                    $allColumnsExist = true;
                    foreach ($addedColumns as $table => $columns) {
                        foreach ($columns as $col) {
                            if (!Schema::hasColumn($table, $col)) {
                                $allColumnsExist = false;
                                break 2;
                            }
                        }
                    }
                    if ($allColumnsExist) {
                        $canSync[] = ['name' => $migrationName, 'reason' => 'Table & columns exist', 'tables' => $alteredTables];
                    } else {
                        $needsMigrate[] = ['name' => $migrationName, 'reason' => 'Some columns missing', 'tables' => $alteredTables];
                    }
                } elseif ($allExist) {
                    // Alter with no detectable columns — be safe, leave pending
                    $needsMigrate[] = ['name' => $migrationName, 'reason' => 'Alter migration needs review', 'tables' => $alteredTables];
                } else {
                    $needsMigrate[] = ['name' => $migrationName, 'reason' => 'Target table missing', 'tables' => $alteredTables];
                }
            }
        }

        // Display results
        $this->info("📊 Summary:");
        $this->info("   Total pending:        " . count($pending));
        $this->info("   Can sync (skip):      " . count($canSync));
        $this->info("   Needs migrate (run):  " . count($needsMigrate));
        $this->info('');

        if (!empty($canSync)) {
            $this->info("✅ Will mark as migrated (tables already exist):");
            foreach ($canSync as $item) {
                $this->line("   ✓ {$item['name']}");
                $this->line("     → {$item['reason']}: " . implode(', ', $item['tables']));
            }
            $this->info('');
        }

        if (!empty($needsMigrate)) {
            $this->warn("⏳ Still needs `php artisan migrate` (or manual fix):");
            foreach ($needsMigrate as $item) {
                $this->line("   ⚠ {$item['name']}");
                $this->line("     → {$item['reason']}");
            }
            $this->info('');
        }

        // Apply
        if (!$isDryRun && !empty($canSync)) {
            $this->info("📝 Writing to migrations table (batch {$nextBatch})...");

            $inserted = 0;
            foreach ($canSync as $item) {
                DB::table('migrations')->insert([
                    'migration' => $item['name'],
                    'batch' => $nextBatch,
                ]);
                $inserted++;
            }

            $this->info("✅ Marked {$inserted} migrations as ran.");
            $this->info('');
            $this->info("👉 Now run: php artisan migrate");
            $this->info("   to handle the remaining " . count($needsMigrate) . " pending migration(s).");
        } elseif ($isDryRun && !empty($canSync)) {
            $this->warn("💡 Run with --force to apply: php artisan schema:sync --force");
        }

        $this->info('');
        return self::SUCCESS;
    }

    /**
     * Parse Schema::create('table_name', ...) from migration file content
     */
    protected function parseCreatedTables(string $content): array
    {
        $tables = [];
        // Match Schema::create('table_name' or Schema::create("table_name"
        if (preg_match_all('/Schema::create\s*\(\s*[\'"]([a-zA-Z_][a-zA-Z0-9_]*)[\'"]/', $content, $matches)) {
            $tables = array_merge($tables, $matches[1]);
        }
        return array_unique($tables);
    }

    /**
     * Parse Schema::table('table_name', ...) from migration file content
     */
    protected function parseAlteredTables(string $content): array
    {
        $tables = [];
        if (preg_match_all('/Schema::table\s*\(\s*[\'"]([a-zA-Z_][a-zA-Z0-9_]*)[\'"]/', $content, $matches)) {
            $tables = array_merge($tables, $matches[1]);
        }
        return array_unique($tables);
    }

    /**
     * Parse $table->string('col') / $table->boolean('col') etc. within Schema::table blocks
     */
    protected function parseAddedColumns(string $content): array
    {
        $result = [];

        // Find Schema::table('table_name', function blocks
        $pattern = '/Schema::table\s*\(\s*[\'"]([a-zA-Z_][a-zA-Z0-9_]*)[\'"]\s*,\s*function\s*\([^)]*\)\s*\{(.*?)\}\s*\)/s';
        if (preg_match_all($pattern, $content, $blocks, PREG_SET_ORDER)) {
            foreach ($blocks as $block) {
                $tableName = $block[1];
                $body = $block[2];

                // Match $table->type('column_name')
                $colPattern = '/\$table\s*->\s*(?:string|text|longText|mediumText|integer|bigInteger|unsignedBigInteger|unsignedInteger|tinyInteger|smallInteger|boolean|float|double|decimal|date|dateTime|timestamp|json|jsonb|binary|char|uuid|ulid|enum|foreignId|foreignUuid)\s*\(\s*[\'"]([a-zA-Z_][a-zA-Z0-9_]*)[\'"](?:\s*,\s*[^)]+)?\)/';
                if (preg_match_all($colPattern, $body, $colMatches)) {
                    $result[$tableName] = array_unique($colMatches[1]);
                }
            }
        }

        return $result;
    }
}
