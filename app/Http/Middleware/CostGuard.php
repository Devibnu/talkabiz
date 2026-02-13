<?php

namespace App\Http\Middleware;

use App\Services\MetaCostService;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * CostGuard Middleware
 * 
 * Middleware untuk mencegah pengiriman pesan jika cost limit tercapai.
 * 
 * USAGE:
 * ======
 * Route::post('/broadcast', [...])->middleware('cost.guard:marketing,100');
 * 
 * Parameters:
 * - category: message category (marketing, utility, authentication, service)
 * - count: number of messages (optional, can read from request body)
 * 
 * REQUEST BODY OPTIONS:
 * =====================
 * - recipient_count: jumlah penerima
 * - recipients: array penerima (akan dihitung count-nya)
 * - contacts: array contacts (akan dihitung count-nya)
 */
class CostGuard
{
    protected MetaCostService $metaCostService;

    public function __construct(MetaCostService $metaCostService)
    {
        $this->metaCostService = $metaCostService;
    }

    /**
     * Handle an incoming request.
     */
    public function handle(Request $request, Closure $next, ?string $category = 'marketing', ?int $count = null): Response
    {
        // Get klien from request/user
        $klienId = $this->getKlienId($request);
        
        if (!$klienId) {
            return $next($request);
        }

        // Determine message count
        $messageCount = $count ?? $this->getMessageCount($request);

        // Check if can send
        $check = $this->metaCostService->canSendMessage($klienId, $category, $messageCount);

        if (!$check['can_send']) {
            return response()->json([
                'success' => false,
                'reason' => $check['reason'],
                'message' => $check['message'] ?? 'Tidak dapat mengirim pesan karena batas biaya',
                'data' => [
                    'estimated_cost' => $check['estimated_cost'] ?? 0,
                    'current_daily' => $check['current_daily'] ?? 0,
                    'current_monthly' => $check['current_monthly'] ?? 0,
                    'limit' => $check['limit'] ?? null,
                ],
            ], Response::HTTP_PAYMENT_REQUIRED); // 402 Payment Required
        }

        // Add cost info to request for downstream use
        $request->merge([
            '_cost_guard' => [
                'passed' => true,
                'estimated_cost' => $check['estimated_cost'],
                'category' => $category,
                'count' => $messageCount,
            ],
        ]);

        return $next($request);
    }

    /**
     * Get klien ID from request
     */
    protected function getKlienId(Request $request): ?int
    {
        // From route parameter
        if ($request->route('klien_id')) {
            return (int) $request->route('klien_id');
        }

        // From request body
        if ($request->has('klien_id')) {
            return (int) $request->input('klien_id');
        }

        // From authenticated user
        $user = $request->user();
        if ($user && $user->klien_id) {
            return $user->klien_id;
        }

        return null;
    }

    /**
     * Get message count from request
     */
    protected function getMessageCount(Request $request): int
    {
        // Explicit count
        if ($request->has('recipient_count')) {
            return (int) $request->input('recipient_count');
        }

        if ($request->has('message_count')) {
            return (int) $request->input('message_count');
        }

        // From recipients array
        if ($request->has('recipients') && is_array($request->input('recipients'))) {
            return count($request->input('recipients'));
        }

        // From contacts array
        if ($request->has('contacts') && is_array($request->input('contacts'))) {
            return count($request->input('contacts'));
        }

        // From contact_ids array
        if ($request->has('contact_ids') && is_array($request->input('contact_ids'))) {
            return count($request->input('contact_ids'));
        }

        // Default to 1
        return 1;
    }
}
