<?php

namespace App\Console\Commands;

use App\Models\ExecutiveHealthSnapshot;
use App\Models\BusinessRiskAlert;
use App\Models\PlatformStatusSummary;
use App\Models\RevenueRiskMetric;
use App\Models\ExecutiveRecommendation;
use Illuminate\Console\Command;

class ExecutiveQuickCommand extends Command
{
    protected $signature = 'executive:quick 
                            {question? : Pertanyaan spesifik (safe|ban|revenue)}';

    protected $description = 'Quick answers untuk pertanyaan eksekutif';

    public function handle(): int
    {
        $question = $this->argument('question');

        if ($question) {
            return $this->answerSpecific($question);
        }

        return $this->showAllAnswers();
    }

    private function showAllAnswers(): int
    {
        $this->newLine();
        $this->line('╔═══════════════════════════════════════════════════════════╗');
        $this->line('║     🎯 EXECUTIVE QUICK ANSWERS                            ║');
        $this->line('║        Jawaban cepat untuk pertanyaan owner               ║');
        $this->line('╚═══════════════════════════════════════════════════════════╝');
        $this->newLine();

        // Question 1: Aman nggak bisnis hari ini?
        $this->answerBusinessSafe();
        $this->newLine();

        // Question 2: Ada risiko BAN atau outage?
        $this->answerBanOutage();
        $this->newLine();

        // Question 3: Pendapatan & reputasi aman?
        $this->answerRevenueReputation();
        $this->newLine();

        // Bonus: What should I do?
        $this->answerWhatToDo();

        $this->newLine();
        $this->line('━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━');
        $this->line('  📊 Full dashboard: php artisan executive:dashboard');
        $this->line('━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━');

        return self::SUCCESS;
    }

    private function answerBusinessSafe(): void
    {
        $this->info('  ❓ "Aman nggak bisnis hari ini?"');
        $this->newLine();

        $health = ExecutiveHealthSnapshot::getLatest();
        $risks = BusinessRiskAlert::getCriticalRisks();
        $status = PlatformStatusSummary::getAllStatus();

        $safe = true;
        $reasons = [];

        // Check health score
        if (!$health) {
            $reasons[] = 'Data health score belum tersedia';
        } elseif ($health->health_score < 60) {
            $safe = false;
            $reasons[] = "Health score rendah ({$health->health_score}/100)";
        } else {
            $reasons[] = "Health score: {$health->health_emoji} {$health->health_score}/100";
        }

        // Check critical risks
        if ($risks->count() > 0) {
            $safe = false;
            $reasons[] = "{$risks->count()} risiko kritis aktif";
        } else {
            $reasons[] = "Tidak ada risiko kritis";
        }

        // Check platform status
        if ($status['overall']['status'] !== 'operational') {
            $safe = false;
            $reasons[] = "Platform: {$status['overall']['label']}";
        } else {
            $reasons[] = "Platform operational";
        }

        // Answer
        if ($safe) {
            $this->line('     ╭────────────────────────────────────────────────────╮');
            $this->line('     │                                                    │');
            $this->line('     │     ✅  <fg=green;options=bold>YA, BISNIS AMAN HARI INI</>              │');
            $this->line('     │                                                    │');
            $this->line('     ╰────────────────────────────────────────────────────╯');
        } else {
            $this->line('     ╭────────────────────────────────────────────────────╮');
            $this->line('     │                                                    │');
            $this->line('     │     ⚠️  <fg=yellow;options=bold>PERLU PERHATIAN</>                        │');
            $this->line('     │                                                    │');
            $this->line('     ╰────────────────────────────────────────────────────╯');
        }

        foreach ($reasons as $reason) {
            $this->line("     • {$reason}");
        }
    }

    private function answerBanOutage(): void
    {
        $this->info('  ❓ "Ada risiko BAN atau outage?"');
        $this->newLine();

        $banRisks = BusinessRiskAlert::active()
            ->where('risk_code', 'LIKE', '%BAN%')
            ->count();

        $status = PlatformStatusSummary::getAllStatus();
        $hasOutage = in_array($status['overall']['status'], ['partial_outage', 'major_outage']);

        if ($banRisks === 0 && !$hasOutage) {
            $this->line('     ╭────────────────────────────────────────────────────╮');
            $this->line('     │                                                    │');
            $this->line('     │     ✅  <fg=green;options=bold>TIDAK ADA RISIKO BAN / OUTAGE</>          │');
            $this->line('     │                                                    │');
            $this->line('     ╰────────────────────────────────────────────────────╯');
            $this->line('     • Platform stabil dan operational');
            $this->line('     • Tidak ada indikasi ban dari WhatsApp');
        } else {
            if ($banRisks > 0 && $hasOutage) {
                $this->line('     ╭────────────────────────────────────────────────────╮');
                $this->line('     │                                                    │');
                $this->line('     │     🔴  <fg=red;options=bold>YA, ADA KEDUANYA!</>                       │');
                $this->line('     │                                                    │');
                $this->line('     ╰────────────────────────────────────────────────────╯');
            } elseif ($banRisks > 0) {
                $this->line('     ╭────────────────────────────────────────────────────╮');
                $this->line('     │                                                    │');
                $this->line('     │     🟠  <fg=bright-red;options=bold>ADA RISIKO BAN</>                         │');
                $this->line('     │                                                    │');
                $this->line('     ╰────────────────────────────────────────────────────╯');
            } else {
                $this->line('     ╭────────────────────────────────────────────────────╮');
                $this->line('     │                                                    │');
                $this->line('     │     🟠  <fg=bright-red;options=bold>ADA OUTAGE</>                              │');
                $this->line('     │                                                    │');
                $this->line('     ╰────────────────────────────────────────────────────╯');
            }

            if ($banRisks > 0) {
                $this->line("     • {$banRisks} alert risiko ban aktif");
            }
            if ($hasOutage) {
                $this->line("     • Platform status: {$status['overall']['label']}");
            }
        }
    }

    private function answerRevenueReputation(): void
    {
        $this->info('  ❓ "Pendapatan & reputasi aman?"');
        $this->newLine();

        $revenue = RevenueRiskMetric::getToday() ?? RevenueRiskMetric::getLatest();
        $reputationRisks = BusinessRiskAlert::active()
            ->whereIn('affected_area', ['reputation', 'customers'])
            ->count();

        $safe = true;
        $reasons = [];

        if ($revenue) {
            if ($revenue->has_risk_signals) {
                $safe = false;
                $reasons[] = "Ada sinyal risiko revenue";
            }
            if ($revenue->achievement_status === 'below_target') {
                $safe = false;
                $reasons[] = "Revenue di bawah target (" . number_format($revenue->revenue_achievement_percent, 0) . "%)";
            } else {
                $reasons[] = "Revenue: {$revenue->achievement_emoji} " . number_format($revenue->revenue_achievement_percent, 0) . "% target";
            }
            $reasons[] = "Trend: {$revenue->revenue_trend_emoji} {$revenue->revenue_trend}";
        } else {
            $reasons[] = "Data revenue belum tersedia";
        }

        if ($reputationRisks > 0) {
            $safe = false;
            $reasons[] = "{$reputationRisks} risiko reputasi aktif";
        } else {
            $reasons[] = "Tidak ada risiko reputasi";
        }

        if ($safe) {
            $this->line('     ╭────────────────────────────────────────────────────╮');
            $this->line('     │                                                    │');
            $this->line('     │     ✅  <fg=green;options=bold>YA, PENDAPATAN & REPUTASI AMAN</>         │');
            $this->line('     │                                                    │');
            $this->line('     ╰────────────────────────────────────────────────────╯');
        } else {
            $this->line('     ╭────────────────────────────────────────────────────╮');
            $this->line('     │                                                    │');
            $this->line('     │     ⚠️  <fg=yellow;options=bold>PERLU PERHATIAN</>                        │');
            $this->line('     │                                                    │');
            $this->line('     ╰────────────────────────────────────────────────────╯');
        }

        foreach ($reasons as $reason) {
            $this->line("     • {$reason}");
        }
    }

    private function answerWhatToDo(): void
    {
        $this->info('  ❓ "Apa yang harus saya lakukan?"');
        $this->newLine();

        $recommendations = ExecutiveRecommendation::active()
            ->valid()
            ->whereIn('urgency', ['critical', 'important'])
            ->ordered()
            ->limit(3)
            ->get();

        if ($recommendations->isEmpty()) {
            $this->line('     ✅ Tidak ada aksi mendesak yang diperlukan.');
            $this->line('     • Platform beroperasi normal');
            $this->line('     • Lanjutkan operasi seperti biasa');
        } else {
            $this->line('     📋 <fg=bright-white>Aksi yang direkomendasikan:</>');;
            $this->newLine();

            foreach ($recommendations as $index => $rec) {
                $num = $index + 1;
                $this->line("     {$num}. {$rec->type_emoji} <fg=bright-white>{$rec->title}</>");
                $this->line("        ➜ {$rec->suggested_action}");
                $this->line("        Owner: {$rec->action_owner}  |  Urgency: {$rec->urgency_label}");
                $this->newLine();
            }
        }
    }

    private function answerSpecific(string $question): int
    {
        $this->newLine();

        match ($question) {
            'safe', 'aman' => $this->answerBusinessSafe(),
            'ban', 'outage' => $this->answerBanOutage(),
            'revenue', 'pendapatan' => $this->answerRevenueReputation(),
            'todo', 'action', 'do' => $this->answerWhatToDo(),
            default => $this->error("Pertanyaan tidak dikenali: {$question}\nValid: safe, ban, revenue, todo"),
        };

        $this->newLine();
        return self::SUCCESS;
    }
}
