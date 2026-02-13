<?php

namespace App\Http\Controllers\Owner;

use App\Http\Controllers\Controller;
use App\Models\LandingItem;
use App\Models\LandingSection;
use App\Services\LandingCacheService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

class OwnerLandingController extends Controller
{
    public function index()
    {
        $sections = LandingSection::with(['items' => function ($query) {
            $query->ordered();
        }])
            ->ordered()
            ->get();

        return view('owner.landing.index', compact('sections'));
    }

    public function editSection(LandingSection $section)
    {
        return view('owner.landing.edit-section', compact('section'));
    }

    public function updateSection(Request $request, LandingSection $section)
    {
        $validated = $request->validate([
            'title' => 'required|string|max:150',
            'subtitle' => 'nullable|string|max:255',
            'is_active' => 'boolean',
            'order' => 'nullable|integer|min:0|max:999',
        ]);

        $validated['is_active'] = $request->boolean('is_active');

        $section->update($validated);

        // Flush landing cache
        app(LandingCacheService::class)->clear();

        return redirect()
            ->route('owner.landing.index')
            ->with('success', 'Section landing berhasil diupdate.');
    }

    public function editItem(LandingItem $item)
    {
        $bulletsText = '';
        if (is_array($item->bullets)) {
            $bulletsText = implode("\n", $item->bullets);
        }

        return view('owner.landing.edit-item', compact('item', 'bulletsText'));
    }

    public function updateItem(Request $request, LandingItem $item)
    {
        $validator = Validator::make($request->all(), [
            'title' => 'required|string|max:150',
            'description' => 'nullable|string|max:1000',
            'icon' => 'nullable|string|max:50',
            'cta_label' => 'nullable|string|max:50',
            'cta_url' => 'nullable|url|max:255',
            'is_active' => 'boolean',
            'order' => 'nullable|integer|min:0|max:999',
            'bullets_text' => 'nullable|string',
        ]);

        $validator->after(function ($validator) use ($request) {
            $bullets = $this->parseBullets($request->input('bullets_text'));

            if (count($bullets) > 6) {
                $validator->errors()->add('bullets_text', 'Maksimal 6 bullet.');
            }

            foreach ($bullets as $bullet) {
                if (mb_strlen($bullet) > 80) {
                    $validator->errors()->add('bullets_text', 'Panjang bullet maksimal 80 karakter.');
                    break;
                }
            }
        });

        $validator->validate();

        $bullets = $this->parseBullets($request->input('bullets_text'));

        $item->update([
            'title' => $request->input('title'),
            'description' => $request->input('description'),
            'icon' => $request->input('icon'),
            'cta_label' => $request->input('cta_label'),
            'cta_url' => $request->input('cta_url'),
            'is_active' => $request->boolean('is_active'),
            'order' => $request->input('order') ?? 0,
            'bullets' => empty($bullets) ? null : $bullets,
        ]);

        // Flush landing cache
        app(LandingCacheService::class)->clear();

        return redirect()
            ->route('owner.landing.index')
            ->with('success', 'Item landing berhasil diupdate.');
    }

    private function parseBullets(?string $input): array
    {
        if ($input === null) {
            return [];
        }

        $lines = preg_split('/\r\n|\r|\n/', $input);
        $bullets = [];

        foreach ($lines as $line) {
            $line = trim($line);
            if ($line === '') {
                continue;
            }
            $bullets[] = $line;
        }

        return $bullets;
    }
}
