<?php

namespace App\Http\Controllers;

use App\Services\AbuseScoringService;
use App\Models\Klien;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;

/**
 * RecipientComplaintWebhookController
 * 
 * Handles incoming spam/complaint webhooks from messaging providers
 * (Gupshup, Twilio, Vonage, etc) and processes them through the
 * abuse scoring system.
 * 
 * Security:
 * - Validates webhook signatures
 * - Checks IP whitelist
 * - Rate limiting applied
 * - Audit logging enabled
 */
class RecipientComplaintWebhookController extends Controller
{
    protected $abuseScoringService;

    public function __construct(AbuseScoringService $abuseScoringService)
    {
        $this->abuseScoringService = $abuseScoringService;
        
        // Apply webhook security middleware
        $this->middleware('gupshup.signature')->only('gupshupComplaint');
        $this->middleware('gupshup.ip')->only('gupshupComplaint');
    }

    /**
     * Handle Gupshup complaint webhook
     * 
     * Gupshup sends complaints when recipients mark messages as spam
     * or report abuse through WhatsApp.
     */
    public function gupshupComplaint(Request $request)
    {
        try {
            Log::info('Gupshup complaint webhook received', [
                'payload' => $request->all(),
                'ip' => $request->ip(),
            ]);

            // Parse Gupshup payload
            $complaintData = $this->parseGupshupComplaint($request);

            if (!$complaintData) {
                Log::error('Failed to parse Gupshup complaint payload');
                return response()->json(['error' => 'Invalid payload'], 400);
            }

            // Find klien by phone number or API key
            $klien = $this->findKlienByIdentifier($complaintData['sender_number']);

            if (!$klien) {
                Log::warning('Klien not found for complaint', [
                    'sender_number' => $complaintData['sender_number'],
                ]);
                return response()->json(['error' => 'Klien not found'], 404);
            }

            // Record complaint through abuse scoring service
            $complaint = $this->abuseScoringService->recordComplaint(
                $klien->id,
                $complaintData['recipient_phone'],
                $complaintData['complaint_type'],
                'provider_webhook',
                [
                    'provider_name' => 'gupshup',
                    'message_id' => $complaintData['message_id'] ?? null,
                    'message_sample' => $complaintData['message_content'] ?? null,
                    'reason' => $complaintData['reason'] ?? null,
                    'recipient_name' => $complaintData['recipient_name'] ?? null,
                    'received_at' => $complaintData['timestamp'] ?? now(),
                    'raw_payload' => $request->all(),
                ]
            );

            Log::info('Complaint processed successfully', [
                'complaint_id' => $complaint->id,
                'klien_id' => $klien->id,
            ]);

            return response()->json([
                'success' => true,
                'complaint_id' => $complaint->id,
                'message' => 'Complaint recorded and processed',
            ], 200);

        } catch (\Exception $e) {
            Log::error('Failed to process Gupshup complaint', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            return response()->json([
                'success' => false,
                'error' => 'Failed to process complaint',
            ], 500);
        }
    }

    /**
     * Parse Gupshup complaint payload
     */
    protected function parseGupshupComplaint(Request $request): ?array
    {
        // Gupshup complaint webhook structure
        // Adjust based on actual Gupshup webhook format
        
        $type = $request->input('type');
        $payload = $request->input('payload', []);

        if ($type !== 'complaint' && $type !== 'spam_report') {
            return null;
        }

        // Map Gupshup complaint type to our system
        $complaintType = $this->mapGupshupComplaintType($payload['complaint_type'] ?? 'spam');

        return [
            'sender_number' => $payload['sender'] ?? $request->input('sender'),
            'recipient_phone' => $payload['recipient'] ?? $request->input('recipient'),
            'recipient_name' => $payload['recipient_name'] ?? null,
            'complaint_type' => $complaintType,
            'message_id' => $payload['message_id'] ?? $request->input('messageId'),
            'message_content' => $payload['message_content'] ?? $request->input('message'),
            'reason' => $payload['reason'] ?? $request->input('reason'),
            'timestamp' => $payload['timestamp'] ?? $request->input('timestamp'),
        ];
    }

    /**
     * Map Gupshup complaint types to our system
     */
    protected function mapGupshupComplaintType(string $gupshupType): string
    {
        return match(strtolower($gupshupType)) {
            'spam', 'unsolicited' => 'spam',
            'abuse', 'harassment', 'threatening' => 'abuse',
            'phishing', 'scam', 'fraud' => 'phishing',
            'inappropriate', 'offensive' => 'inappropriate',
            'frequency', 'too_many', 'excessive' => 'frequency',
            default => 'other',
        };
    }

    /**
     * Handle Twilio complaint webhook
     */
    public function twilioComplaint(Request $request)
    {
        try {
            Log::info('Twilio complaint webhook received', [
                'payload' => $request->all(),
                'ip' => $request->ip(),
            ]);

            // Parse Twilio payload
            $complaintData = $this->parseTwilioComplaint($request);

            if (!$complaintData) {
                return response()->json(['error' => 'Invalid payload'], 400);
            }

            // Find klien
            $klien = $this->findKlienByIdentifier($complaintData['sender_number']);

            if (!$klien) {
                return response()->json(['error' => 'Klien not found'], 404);
            }

            // Record complaint
            $complaint = $this->abuseScoringService->recordComplaint(
                $klien->id,
                $complaintData['recipient_phone'],
                $complaintData['complaint_type'],
                'provider_webhook',
                [
                    'provider_name' => 'twilio',
                    'message_id' => $complaintData['message_id'] ?? null,
                    'message_sample' => $complaintData['message_content'] ?? null,
                    'reason' => $complaintData['reason'] ?? null,
                    'received_at' => $complaintData['timestamp'] ?? now(),
                    'raw_payload' => $request->all(),
                ]
            );

            return response()->json([
                'success' => true,
                'complaint_id' => $complaint->id,
            ], 200);

        } catch (\Exception $e) {
            Log::error('Failed to process Twilio complaint', [
                'error' => $e->getMessage(),
            ]);

            return response()->json(['error' => 'Failed to process complaint'], 500);
        }
    }

    /**
     * Parse Twilio complaint payload
     */
    protected function parseTwilioComplaint(Request $request): ?array
    {
        // Twilio complaint webhook structure
        // Adjust based on actual Twilio webhook format
        
        return [
            'sender_number' => $request->input('From'),
            'recipient_phone' => $request->input('To'),
            'complaint_type' => $this->mapTwilioComplaintType($request->input('FeedbackType', 'spam')),
            'message_id' => $request->input('MessageSid'),
            'message_content' => $request->input('Body'),
            'reason' => $request->input('Reason'),
            'timestamp' => $request->input('DateSent'),
        ];
    }

    /**
     * Map Twilio complaint types
     */
    protected function mapTwilioComplaintType(string $twilioType): string
    {
        return match(strtolower($twilioType)) {
            'spam' => 'spam',
            'abuse' => 'abuse',
            'fraud' => 'phishing',
            default => 'other',
        };
    }

    /**
     * Generic complaint webhook handler
     * 
     * Can be used by any provider or manual reports
     */
    public function  genericComplaint(Request $request)
    {
        try {
            // Validate request
            $validator = Validator::make($request->all(), [
                'klien_id' => 'required|integer|exists:kliens,id',
                'recipient_phone' => 'required|string|max:20',
                'complaint_type' => 'required|in:spam,abuse,phishing,inappropriate,frequency,other',
                'complaint_source' => 'required|in:provider_webhook,manual_report,internal_flag,third_party',
                'provider_name' => 'nullable|string|max:50',
                'message_id' => 'nullable|string|max:100',
                'message_sample' => 'nullable|string|max:500',
                'reason' => 'nullable|string|max:1000',
            ]);

            if ($validator->fails()) {
                return response()->json([
                    'success' => false,
                    'errors' => $validator->errors(),
                ], 422);
            }

            $data = $validator->validated();

            // Record complaint
            $complaint = $this->abuseScoringService->recordComplaint(
                $data['klien_id'],
                $data['recipient_phone'],
                $data['complaint_type'],
                $data['complaint_source'],
                [
                    'provider_name' => $data['provider_name'] ?? null,
                    'message_id' => $data['message_id'] ?? null,
                    'message_sample' => $data['message_sample'] ?? null,
                    'reason' => $data['reason'] ?? null,
                    'received_at' => now(),
                    'submitted_by' => auth()->id() ?? null,
                ]
            );

            return response()->json([
                'success' => true,
                'complaint_id' => $complaint->id,
                'message' => 'Complaint recorded successfully',
            ], 201);

        } catch (\Exception $e) {
            Log::error('Failed to process generic complaint', [
                'error' => $e->getMessage(),
            ]);

            return response()->json([
                'success' => false,
                'error' => 'Failed to process complaint',
            ], 500);
        }
    }

    /**
     * Find klien by phone number or other identifier
     */
    protected function findKlienByIdentifier(string $identifier): ?Klien
    {
        // Try to find by phone number
        $klien = Klien::where('whatsapp_number', $identifier)
            ->orWhere('phone', $identifier)
            ->first();

        if ($klien) {
            return $klien;
        }

        // Try to find by domain
        if (str_contains($identifier, '@')) {
            $domain = explode('@', $identifier)[1];
            $klien = Klien::where('domain', $domain)->first();
        }

        return $klien;
    }

    /**
     * Test endpoint (for development/testing only)
     * Remove in production or protect with auth
     */
    public function testComplaint(Request $request)
    {
        if (!app()->environment(['local', 'development'])) {
            return response()->json(['error' => 'Not available'], 403);
        }

        $klienId = $request->input('klien_id', 1);
        $recipientPhone = $request->input('recipient_phone', '628123456789');
        $complaintType = $request->input('complaint_type', 'spam');

        $complaint = $this->abuseScoringService->recordComplaint(
            $klienId,
            $recipientPhone,
            $complaintType,
            'manual_report',
            [
                'provider_name' => 'test',
                'reason' => 'Test complaint for development',
                'test_mode' => true,
            ]
        );

        return response()->json([
            'success' => true,
            'complaint' => $complaint,
            'abuse_score' => $complaint->klien->abuseScore,
        ]);
    }
}

