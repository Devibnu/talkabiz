<?php

namespace App\Services;

class TemplateRenderService
{
    protected array $variables = [
        'nama',
        'telepon',
        'email',
        'produk',
        'harga',
        'tanggal',
        'no_order',
    ];

    public function render(string $template, array $data): string
    {
        $rendered = $template;
        
        foreach ($this->variables as $var) {
            $value = $data[$var] ?? '';
            $rendered = str_replace('{{' . $var . '}}', $value, $rendered);
            $rendered = str_replace('{{ ' . $var . ' }}', $value, $rendered);
        }
        
        return $rendered;
    }

    public function renderFromContact(string $template, ?object $contact, array $extraData = []): string
    {
        $data = array_merge([
            'nama' => $contact->nama ?? $contact->nama_customer ?? '',
            'telepon' => $contact->no_telepon ?? $contact->no_whatsapp ?? '',
            'email' => $contact->email ?? '',
            'produk' => '',
            'harga' => '',
            'tanggal' => now()->format('d/m/Y'),
            'no_order' => '',
        ], $extraData);
        
        return $this->render($template, $data);
    }

    public function getVariables(): array
    {
        return $this->variables;
    }

    public function extractVariablesFromTemplate(string $template): array
    {
        preg_match_all('/\{\{\s*(\w+)\s*\}\}/', $template, $matches);
        return array_unique($matches[1] ?? []);
    }
}
