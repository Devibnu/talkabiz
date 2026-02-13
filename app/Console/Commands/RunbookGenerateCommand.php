<?php

namespace App\Console\Commands;

use App\Services\RunbookService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;

/**
 * Runbook Generate Command
 * 
 * Generate runbook documentation.
 */
class RunbookGenerateCommand extends Command
{
    protected $signature = 'runbook:generate 
                            {--output=runbook.md : Output filename}
                            {--format=markdown : Output format (markdown/html)}';

    protected $description = 'Generate runbook documentation';

    public function handle(RunbookService $service): int
    {
        $output = $this->option('output');
        $format = $this->option('format');

        $this->newLine();
        $this->info("📚 Generating Runbook Documentation...");
        $this->newLine();

        try {
            $content = $service->generateRunbookDoc();

            if ($format === 'html') {
                // Convert markdown to basic HTML
                $content = $this->markdownToHtml($content);
                if (!str_ends_with($output, '.html')) {
                    $output = str_replace('.md', '.html', $output);
                }
            }

            // Save to storage
            $path = storage_path("app/{$output}");
            file_put_contents($path, $content);

            $this->info("✅ Runbook generated successfully!");
            $this->newLine();
            $this->line("   📄 File: {$path}");
            $this->line("   📊 Format: {$format}");
            $this->line("   📏 Size: " . $this->formatBytes(strlen($content)));
            $this->newLine();

            // Preview
            $lines = explode("\n", $content);
            $preview = array_slice($lines, 0, 20);
            
            $this->info("📝 Preview:");
            $this->line("─────────────────────────────────────────────────────────────────");
            foreach ($preview as $line) {
                $this->line($line);
            }
            if (count($lines) > 20) {
                $this->line("...");
                $this->line("(" . (count($lines) - 20) . " more lines)");
            }

            return self::SUCCESS;

        } catch (\Exception $e) {
            $this->error("❌ Error: {$e->getMessage()}");
            return self::FAILURE;
        }
    }

    protected function markdownToHtml(string $markdown): string
    {
        $html = "<!DOCTYPE html>\n<html>\n<head>\n";
        $html .= "<title>SOC/NOC Runbook - Talkabiz</title>\n";
        $html .= "<style>\n";
        $html .= "body { font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif; max-width: 900px; margin: 0 auto; padding: 20px; }\n";
        $html .= "h1 { color: #1a1a1a; border-bottom: 2px solid #007bff; padding-bottom: 10px; }\n";
        $html .= "h2 { color: #333; margin-top: 30px; }\n";
        $html .= "h3 { color: #555; }\n";
        $html .= "h4 { color: #666; }\n";
        $html .= "table { border-collapse: collapse; width: 100%; margin: 15px 0; }\n";
        $html .= "th, td { border: 1px solid #ddd; padding: 10px; text-align: left; }\n";
        $html .= "th { background: #f5f5f5; }\n";
        $html .= "code { background: #f4f4f4; padding: 2px 6px; border-radius: 3px; }\n";
        $html .= "pre { background: #f4f4f4; padding: 15px; border-radius: 5px; overflow-x: auto; }\n";
        $html .= "ul { padding-left: 25px; }\n";
        $html .= ".severity-sev1 { color: #dc3545; font-weight: bold; }\n";
        $html .= ".severity-sev2 { color: #fd7e14; font-weight: bold; }\n";
        $html .= ".severity-sev3 { color: #ffc107; }\n";
        $html .= ".severity-sev4 { color: #28a745; }\n";
        $html .= "</style>\n</head>\n<body>\n";

        // Basic markdown conversion
        $content = htmlspecialchars($markdown);
        
        // Headers
        $content = preg_replace('/^#### (.+)$/m', '<h4>$1</h4>', $content);
        $content = preg_replace('/^### (.+)$/m', '<h3>$1</h3>', $content);
        $content = preg_replace('/^## (.+)$/m', '<h2>$1</h2>', $content);
        $content = preg_replace('/^# (.+)$/m', '<h1>$1</h1>', $content);
        
        // Code blocks
        $content = preg_replace('/```\n([\s\S]*?)\n```/', '<pre>$1</pre>', $content);
        
        // Inline code
        $content = preg_replace('/`([^`]+)`/', '<code>$1</code>', $content);
        
        // Bold
        $content = preg_replace('/\*\*([^*]+)\*\*/', '<strong>$1</strong>', $content);
        
        // Lists
        $content = preg_replace('/^- (.+)$/m', '<li>$1</li>', $content);
        $content = preg_replace('/^(\d+)\. (.+)$/m', '<li>$2</li>', $content);
        
        // Tables (basic)
        $content = preg_replace('/\|([^|]+)\|/m', '<td>$1</td>', $content);
        
        // Line breaks
        $content = nl2br($content);

        $html .= $content;
        $html .= "\n</body>\n</html>";

        return $html;
    }

    protected function formatBytes(int $bytes): string
    {
        if ($bytes >= 1024 * 1024) {
            return round($bytes / 1024 / 1024, 2) . ' MB';
        }
        if ($bytes >= 1024) {
            return round($bytes / 1024, 2) . ' KB';
        }
        return $bytes . ' bytes';
    }
}
