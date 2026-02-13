<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * UpdatePlanRequest - Subscription Only (FINAL CLEAN)
 *
 * Validasi untuk update Plan di Owner Panel.
 * Same rules as StorePlanRequest, code unique ignore current plan.
 */
class UpdatePlanRequest extends FormRequest
{
    public function authorize(): bool
    {
        if (!auth()->check()) {
            return false;
        }

        return in_array(auth()->user()->role, ['owner', 'super_admin'], true);
    }

    public function rules(): array
    {
        $planId = $this->route('plan');

        return [
            // Basic Info
            'code' => [
                'required',
                'string',
                'max:50',
                'regex:/^[a-z0-9\-]+$/',
                Rule::unique('plans', 'code')->ignore($planId),
            ],
            'name' => 'required|string|max:100|min:3',
            'description' => 'nullable|string|max:1000',

            // Pricing & Duration
            'price_monthly' => 'required|numeric|min:0',
            'duration_days' => 'required|integer|min:1|max:365',

            // Capacity Limits
            'max_wa_numbers' => 'required|integer|min:1',
            'max_campaigns' => 'required|integer|min:1',
            'max_recipients_per_campaign' => 'required|integer|min:100',

            // Features (JSON array)
            'features' => 'nullable|array',
            'features.*' => 'string|max:50',

            // Flags
            'is_active' => 'boolean',
            'is_visible' => 'boolean',
            'is_self_serve' => 'boolean',
            'is_popular' => 'boolean',
            'sort_order' => 'nullable|integer|min:0|max:255',
        ];
    }

    public function withValidator($validator)
    {
        $validator->after(function ($validator) {
            // Self-Service plans harus visible
            if ($this->boolean('is_self_serve') && !$this->boolean('is_visible')) {
                $validator->errors()->add(
                    'is_visible',
                    'Paket Self-Service harus visible di landing page.'
                );
            }
        });
    }

    public function attributes(): array
    {
        return [
            'code' => 'Kode Paket',
            'name' => 'Nama Paket',
            'description' => 'Deskripsi',
            'price_monthly' => 'Harga Bulanan',
            'duration_days' => 'Durasi (Hari)',
            'max_wa_numbers' => 'Max Nomor WhatsApp',
            'max_campaigns' => 'Max Campaign',
            'max_recipients_per_campaign' => 'Max Penerima per Campaign',
            'features' => 'Fitur',
            'is_active' => 'Status Aktif',
            'is_visible' => 'Visible di Landing',
            'is_self_serve' => 'Self-Service',
            'is_popular' => 'Populer',
            'sort_order' => 'Urutan Tampil',
        ];
    }

    public function messages(): array
    {
        return [
            'price_monthly.min' => 'Harga tidak boleh negatif.',
            'duration_days.min' => 'Durasi harus minimal 1 hari.',
            'duration_days.max' => 'Durasi tidak boleh lebih dari 365 hari.',
            'max_wa_numbers.min' => 'Minimal 1 nomor WhatsApp harus diizinkan.',
            'code.regex' => 'Kode hanya boleh mengandung huruf kecil, angka, dan tanda hubung.',
            'max_campaigns.min' => 'Minimal 1 campaign harus diizinkan.',
            'max_recipients_per_campaign.min' => 'Minimal 100 penerima per campaign.',
        ];
    }
}
