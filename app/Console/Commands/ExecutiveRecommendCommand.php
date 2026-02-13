<?php

namespace App\Console\Commands;

use App\Services\ExecutiveDashboardService;
use App\Models\ExecutiveRecommendation;
use Illuminate\Console\Command;

class ExecutiveRecommendCommand extends Command
{
    protected $signature = 'executive:recommend 
                            {--generate : Generate rekomendasi baru berdasarkan kondisi saat ini}
                            {--all : Tampilkan semua rekomendasi (termasuk expired)}
                            {--act= : Mark recommendation as acted (by ID)}
                            {--dismiss= : Dismiss recommendation (by ID)}';

    protected $description = 'Kelola rekomendasi executive dashboard';

    private ExecutiveDashboardService $service;

    public function __construct(ExecutiveDashboardService $service)
    {
        parent::__construct();
        $this->service = $service;
    }

    public function handle(): int
    {
        if ($actId = $this->option('act')) {
            return $this->actOnRecommendation($actId);
        }

        if ($dismissId = $this->option('dismiss')) {
            return $this->dismissRecommendation($dismissId);
        }

        if ($this->option('generate')) {
            return $this->generateRecommendations();
        }

        return $this->showRecommendations();
    }

    private function showRecommendations(): int
    {
        $this->newLine();
        $this->info('💡 EXECUTIVE RECOMMENDATIONS');
        $this->line('━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━');
        $this->newLine();

        $query = $this->option('all')
            ? ExecutiveRecommendation::query()
            : ExecutiveRecommendation::active()->valid();

        $recommendations = $query->ordered()->get();

        if ($recommendations->isEmpty()) {
            $this->line('  Tidak ada rekomendasi aktif saat ini.');
            $this->newLine();
            $this->line('  💡 Generate rekomendasi: php artisan executive:recommend --generate');
            return self::SUCCESS;
        }

        // Group by category
        $grouped = $recommendations->groupBy('category');

        foreach ($grouped as $category => $recs) {
            $categoryLabel = ExecutiveRecommendation::first()->category_label ?? ucfirst($category);
            $this->info("  📂 {$categoryLabel}");
            $this->newLine();

            foreach ($recs as $rec) {
                $this->showRecommendationDetail($rec);
            }
        }

        $this->newLine();
        $this->info('💡 Actions:');
        $this->line('  • Act: php artisan executive:recommend --act=<id>');
        $this->line('  • Dismiss: php artisan executive:recommend --dismiss=<id>');
        $this->line('  • Generate new: php artisan executive:recommend --generate');

        return self::SUCCESS;
    }

    private function showRecommendationDetail(ExecutiveRecommendation $rec): void
    {
        $urgencyColor = match ($rec->urgency) {
            'critical' => 'red',
            'important' => 'bright-red',
            'consider' => 'yellow',
            default => 'blue',
        };

        $typeColor = match ($rec->recommendation_type) {
            'stop' => 'red',
            'hold' => 'yellow',
            'caution' => 'bright-red',
            'action' => 'bright-white',
            default => 'green',
        };

        $this->line("     ┌──────────────────────────────────────────────────────");
        $this->line("     │ {$rec->type_emoji} <fg=bright-white;options=bold>{$rec->title}</>");
        $this->line("     │ ID: <fg=gray>{$rec->recommendation_id}</>");
        $this->line("     │ ");
        $this->line("     │ 📝 {$rec->description}");
        $this->line("     │ ");
        $this->line("     │ Type: <fg={$typeColor}>{$rec->type_label}</>  |  Urgency: <fg={$urgencyColor}>{$rec->urgency_label}</>");
        $this->line("     │ Confidence: {$rec->confidence_percent}  |  Status: " . strtoupper($rec->status));
        $this->line("     │ ");
        $this->line("     │ ✅ Action: {$rec->suggested_action}");
        $this->line("     │    Owner: " . ($rec->action_owner ?? '-'));
        
        if ($rec->valid_until) {
            $this->line("     │ ⏰ Valid until: " . $rec->valid_until->format('d M Y H:i'));
        }

        $this->line("     └──────────────────────────────────────────────────────");
        $this->newLine();
    }

    private function generateRecommendations(): int
    {
        $this->info('🔄 Generating recommendations based on current state...');
        $this->newLine();

        $recommendations = $this->service->generateRecommendations();

        if (empty($recommendations)) {
            $this->line('  ℹ️ No new recommendations generated.');
            $this->line('     Current state doesn\'t require specific recommendations.');
        } else {
            $this->info("  ✅ Generated " . count($recommendations) . " recommendation(s):");
            $this->newLine();

            foreach ($recommendations as $rec) {
                $this->line("     {$rec->type_emoji} {$rec->title}");
                $this->line("        [{$rec->urgency_label}] {$rec->suggested_action}");
                $this->newLine();
            }
        }

        return self::SUCCESS;
    }

    private function actOnRecommendation(string $id): int
    {
        $rec = ExecutiveRecommendation::where('recommendation_id', $id)->first();

        if (!$rec) {
            $this->error("❌ Recommendation not found: {$id}");
            return self::FAILURE;
        }

        $notes = $this->ask('Action notes (optional)');

        $rec->markActed(1, $notes); // Assuming user ID 1 for CLI

        $this->info("✅ Recommendation marked as acted: {$rec->title}");
        return self::SUCCESS;
    }

    private function dismissRecommendation(string $id): int
    {
        $rec = ExecutiveRecommendation::where('recommendation_id', $id)->first();

        if (!$rec) {
            $this->error("❌ Recommendation not found: {$id}");
            return self::FAILURE;
        }

        $reason = $this->ask('Dismiss reason (optional)');

        $rec->dismiss($reason);

        $this->info("✅ Recommendation dismissed: {$rec->title}");
        return self::SUCCESS;
    }
}
