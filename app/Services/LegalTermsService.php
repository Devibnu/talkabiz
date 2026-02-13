<?php

namespace App\Services;

use App\Models\LegalDocument;
use App\Models\LegalAcceptance;
use App\Models\LegalDocumentEvent;
use App\Models\LegalPendingAcceptance;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * LegalTermsService
 * 
 * Handles all legal document lifecycle:
 * - Version management
 * - Activation/deactivation
 * - Acceptance recording
 * - Compliance checking
 * - Enforcement status
 */
class LegalTermsService
{
    // ==================== DOCUMENT MANAGEMENT ====================

    /**
     * Create a new legal document version
     */
    public function createDocument(array $data, ?int $createdBy = null): LegalDocument
    {
        return DB::transaction(function () use ($data, $createdBy) {
            // Auto-generate version if not provided
            $version = $data['version'] ?? LegalDocument::generateNextVersion($data['type']);

            $document = LegalDocument::create([
                'type' => $data['type'],
                'version' => $version,
                'title' => $data['title'],
                'summary' => $data['summary'] ?? null,
                'content' => $data['content'],
                'content_format' => $data['content_format'] ?? LegalDocument::FORMAT_HTML,
                'is_active' => false, // Always start inactive
                'is_mandatory' => $data['is_mandatory'] ?? true,
                'effective_at' => $data['effective_at'] ?? null,
                'created_by' => $createdBy,
            ]);

            // Record event
            LegalDocumentEvent::record(
                $document->id,
                LegalDocumentEvent::TYPE_CREATED,
                $createdBy,
                null,
                $document->toArray(),
                'Document created'
            );

            return $document;
        });
    }

    /**
     * Update a document (only if not active)
     */
    public function updateDocument(int $documentId, array $data, ?int $updatedBy = null): LegalDocument
    {
        return DB::transaction(function () use ($documentId, $data, $updatedBy) {
            $document = LegalDocument::findOrFail($documentId);

            // Cannot update active documents
            if ($document->is_active) {
                throw new \RuntimeException('Cannot update active document. Create a new version instead.');
            }

            $oldValues = $document->toArray();

            // Update allowed fields
            $document->fill(array_intersect_key($data, array_flip([
                'title',
                'summary',
                'content',
                'content_format',
                'is_mandatory',
                'effective_at',
            ])));
            $document->save();

            // Record event
            LegalDocumentEvent::record(
                $document->id,
                LegalDocumentEvent::TYPE_UPDATED,
                $updatedBy,
                $oldValues,
                $document->toArray(),
                'Document updated'
            );

            return $document->fresh();
        });
    }

    /**
     * Activate a document version (deactivates previous active version)
     */
    public function activateDocument(
        int $documentId,
        ?int $activatedBy = null,
        bool $createPendingAcceptances = true
    ): LegalDocument {
        return DB::transaction(function () use ($documentId, $activatedBy, $createPendingAcceptances) {
            $document = LegalDocument::findOrFail($documentId);

            // Find and deactivate current active version
            $currentActive = LegalDocument::active()->ofType($document->type)->first();
            
            if ($currentActive && $currentActive->id !== $document->id) {
                $currentActive->is_active = false;
                $currentActive->save();

                LegalDocumentEvent::record(
                    $currentActive->id,
                    LegalDocumentEvent::TYPE_DEACTIVATED,
                    $activatedBy,
                    ['is_active' => true],
                    ['is_active' => false],
                    'Deactivated due to new version activation'
                );
            }

            // Activate new version
            $document->is_active = true;
            $document->published_at = now();
            $document->activated_by = $activatedBy;
            $document->save();

            // Record event
            LegalDocumentEvent::record(
                $document->id,
                LegalDocumentEvent::TYPE_ACTIVATED,
                $activatedBy,
                ['is_active' => false],
                ['is_active' => true, 'published_at' => $document->published_at],
                'Document activated'
            );

            // Create pending acceptances for all active clients
            if ($createPendingAcceptances && $document->is_mandatory) {
                $count = LegalPendingAcceptance::createForAllClients(
                    $document->id,
                    $document->type,
                    true // blocking
                );

                Log::info("Created {$count} pending acceptances for document {$document->id}");
            }

            return $document->fresh();
        });
    }

    /**
     * Deactivate a document
     */
    public function deactivateDocument(int $documentId, ?int $deactivatedBy = null): LegalDocument
    {
        return DB::transaction(function () use ($documentId, $deactivatedBy) {
            $document = LegalDocument::findOrFail($documentId);

            if (!$document->is_active) {
                throw new \RuntimeException('Document is not active.');
            }

            $document->is_active = false;
            $document->save();

            // Remove pending acceptances
            LegalPendingAcceptance::where('legal_document_id', $documentId)->delete();

            // Record event
            LegalDocumentEvent::record(
                $document->id,
                LegalDocumentEvent::TYPE_DEACTIVATED,
                $deactivatedBy,
                ['is_active' => true],
                ['is_active' => false],
                'Document manually deactivated'
            );

            return $document->fresh();
        });
    }

    /**
     * Delete a document (soft delete, only if not active)
     */
    public function deleteDocument(int $documentId, ?int $deletedBy = null): bool
    {
        return DB::transaction(function () use ($documentId, $deletedBy) {
            $document = LegalDocument::findOrFail($documentId);

            if ($document->is_active) {
                throw new \RuntimeException('Cannot delete active document. Deactivate first.');
            }

            // Check if has acceptances
            if ($document->acceptances()->count() > 0) {
                throw new \RuntimeException('Cannot delete document with existing acceptances.');
            }

            // Record event before delete
            LegalDocumentEvent::record(
                $document->id,
                LegalDocumentEvent::TYPE_DELETED,
                $deletedBy,
                $document->toArray(),
                null,
                'Document deleted'
            );

            return $document->delete();
        });
    }

    // ==================== ACCEPTANCE MANAGEMENT ====================

    /**
     * Record client acceptance of a document
     */
    public function recordAcceptance(
        int $klienId,
        int $documentId,
        array $options = []
    ): LegalAcceptance {
        return DB::transaction(function () use ($klienId, $documentId, $options) {
            $document = LegalDocument::findOrFail($documentId);

            // Verify document is active
            if (!$document->is_active) {
                throw new \RuntimeException('Cannot accept inactive document.');
            }

            // Check if already accepted
            $existing = LegalAcceptance::where('klien_id', $klienId)
                ->where('legal_document_id', $documentId)
                ->first();

            if ($existing) {
                throw new \RuntimeException('Document already accepted by this client.');
            }

            // Create acceptance record
            $acceptance = LegalAcceptance::create([
                'klien_id' => $klienId,
                'user_id' => $options['user_id'] ?? null,
                'legal_document_id' => $documentId,
                'document_type' => $document->type,
                'document_version' => $document->version,
                'accepted_at' => now(),
                'acceptance_method' => $options['acceptance_method'] ?? LegalAcceptance::METHOD_WEB_CLICK,
                'ip_address' => $options['ip_address'] ?? null,
                'user_agent' => $options['user_agent'] ?? null,
                'additional_data' => $options['additional_data'] ?? null,
            ]);

            // Remove from pending acceptances
            LegalPendingAcceptance::removeAfterAcceptance($klienId, $documentId);

            Log::info("Klien {$klienId} accepted {$document->type} v{$document->version}");

            return $acceptance;
        });
    }

    /**
     * Bulk accept all pending mandatory documents
     */
    public function acceptAllPending(int $klienId, array $options = []): array
    {
        $pendingDocs = LegalAcceptance::getPendingDocuments($klienId);
        $acceptances = [];

        foreach ($pendingDocs as $doc) {
            try {
                $acceptances[] = $this->recordAcceptance($klienId, $doc->id, $options);
            } catch (\Exception $e) {
                Log::warning("Failed to record acceptance for doc {$doc->id}: " . $e->getMessage());
            }
        }

        return $acceptances;
    }

    // ==================== COMPLIANCE CHECKING ====================

    /**
     * Check if client has accepted all mandatory active documents
     */
    public function checkCompliance(int $klienId): array
    {
        $mandatoryDocs = LegalDocument::getActiveMandatoryDocuments();
        $results = [
            'is_compliant' => true,
            'accepted' => [],
            'pending' => [],
        ];

        foreach ($mandatoryDocs as $doc) {
            $acceptance = LegalAcceptance::where('klien_id', $klienId)
                ->where('legal_document_id', $doc->id)
                ->first();

            if ($acceptance) {
                $results['accepted'][] = [
                    'document_id' => $doc->id,
                    'type' => $doc->type,
                    'version' => $doc->version,
                    'title' => $doc->title,
                    'accepted_at' => $acceptance->accepted_at,
                ];
            } else {
                $results['is_compliant'] = false;
                $results['pending'][] = [
                    'document_id' => $doc->id,
                    'type' => $doc->type,
                    'version' => $doc->version,
                    'title' => $doc->title,
                    'summary' => $doc->summary,
                    'is_mandatory' => $doc->is_mandatory,
                ];
            }
        }

        return $results;
    }

    /**
     * Get blocking documents for a client
     */
    public function getBlockingDocuments(int $klienId): \Illuminate\Database\Eloquent\Collection
    {
        return LegalPendingAcceptance::getBlockingForKlien($klienId)
            ->map(function ($pending) {
                return $pending->legalDocument;
            });
    }

    /**
     * Check if client access should be blocked
     */
    public function shouldBlockAccess(int $klienId): bool
    {
        return LegalPendingAcceptance::hasBlockingPending($klienId);
    }

    // ==================== OWNER/ADMIN VIEWS ====================

    /**
     * Get document acceptance statistics
     */
    public function getDocumentStats(int $documentId): array
    {
        $document = LegalDocument::findOrFail($documentId);
        $totalActiveClients = \App\Models\Klien::where('aktif', true)->count();

        return [
            'document_id' => $document->id,
            'type' => $document->type,
            'version' => $document->version,
            'is_active' => $document->is_active,
            'total_active_clients' => $totalActiveClients,
            'accepted_count' => $document->acceptances()->count(),
            'pending_count' => $document->pendingAcceptances()->count(),
            'acceptance_rate' => $totalActiveClients > 0 
                ? round(($document->acceptances()->count() / $totalActiveClients) * 100, 2)
                : 0,
            'published_at' => $document->published_at,
        ];
    }

    /**
     * Get overall compliance statistics
     */
    public function getComplianceStats(): array
    {
        $totalActiveClients = \App\Models\Klien::where('aktif', true)->count();
        $mandatoryDocs = LegalDocument::getActiveMandatoryDocuments();

        // Get fully compliant clients count
        $compliantClients = 0;
        $clientIds = \App\Models\Klien::where('aktif', true)->pluck('id');

        foreach ($clientIds as $klienId) {
            if (LegalAcceptance::hasAcceptedAllMandatory($klienId)) {
                $compliantClients++;
            }
        }

        // Get pending acceptance breakdown
        $pendingByType = [];
        foreach ($mandatoryDocs as $doc) {
            $pendingByType[$doc->type] = LegalPendingAcceptance::where('legal_document_id', $doc->id)->count();
        }

        return [
            'total_active_clients' => $totalActiveClients,
            'fully_compliant_clients' => $compliantClients,
            'compliance_rate' => $totalActiveClients > 0 
                ? round(($compliantClients / $totalActiveClients) * 100, 2)
                : 0,
            'mandatory_documents_count' => $mandatoryDocs->count(),
            'pending_by_type' => $pendingByType,
            'total_pending_acceptances' => LegalPendingAcceptance::blocking()->count(),
        ];
    }

    /**
     * Get acceptance history for a client
     */
    public function getClientAcceptanceHistory(int $klienId): \Illuminate\Database\Eloquent\Collection
    {
        return LegalAcceptance::forKlien($klienId)
            ->with('legalDocument')
            ->orderByDesc('accepted_at')
            ->get();
    }

    /**
     * Get clients who haven't accepted a specific document
     */
    public function getClientsNotAccepted(int $documentId, array $filters = []): \Illuminate\Database\Eloquent\Collection
    {
        $acceptedKlienIds = LegalAcceptance::where('legal_document_id', $documentId)
            ->pluck('klien_id')
            ->toArray();

        $query = \App\Models\Klien::where('aktif', true)
            ->whereNotIn('id', $acceptedKlienIds);

        if (!empty($filters['search'])) {
            $query->where(function ($q) use ($filters) {
                $q->where('nama', 'like', "%{$filters['search']}%")
                  ->orWhere('email', 'like', "%{$filters['search']}%");
            });
        }

        return $query->get();
    }

    /**
     * Get all active documents with acceptance status for a client
     */
    public function getClientDocumentStatus(int $klienId): array
    {
        $activeDocs = LegalDocument::active()->get();
        $results = [];

        foreach ($activeDocs as $doc) {
            $acceptance = LegalAcceptance::where('klien_id', $klienId)
                ->where('legal_document_id', $doc->id)
                ->first();

            $results[] = [
                'document_id' => $doc->id,
                'type' => $doc->type,
                'type_label' => $doc->type_label,
                'version' => $doc->version,
                'title' => $doc->title,
                'is_mandatory' => $doc->is_mandatory,
                'is_accepted' => $acceptance !== null,
                'accepted_at' => $acceptance?->accepted_at,
            ];
        }

        return $results;
    }

    // ==================== REPORTS ====================

    /**
     * Export acceptance report
     */
    public function exportAcceptanceReport(array $filters = []): array
    {
        $query = LegalAcceptance::with(['klien', 'legalDocument', 'user']);

        if (!empty($filters['document_id'])) {
            $query->forDocument($filters['document_id']);
        }

        if (!empty($filters['document_type'])) {
            $query->ofType($filters['document_type']);
        }

        if (!empty($filters['start_date']) && !empty($filters['end_date'])) {
            $query->acceptedBetween($filters['start_date'], $filters['end_date']);
        }

        return $query->orderByDesc('accepted_at')
            ->get()
            ->map(function ($acceptance) {
                return [
                    'acceptance_id' => $acceptance->id,
                    'klien_id' => $acceptance->klien_id,
                    'klien_name' => $acceptance->klien->nama ?? null,
                    'klien_email' => $acceptance->klien->email ?? null,
                    'document_type' => $acceptance->document_type,
                    'document_version' => $acceptance->document_version,
                    'document_title' => $acceptance->legalDocument->title ?? null,
                    'accepted_at' => $acceptance->accepted_at->toIso8601String(),
                    'acceptance_method' => $acceptance->acceptance_method,
                    'ip_address' => $acceptance->ip_address,
                    'user_agent' => $acceptance->user_agent,
                ];
            })
            ->toArray();
    }

    /**
     * Get document version history
     */
    public function getVersionHistory(string $type): array
    {
        return LegalDocument::getVersionHistory($type)
            ->map(function ($doc) {
                return [
                    'id' => $doc->id,
                    'version' => $doc->version,
                    'title' => $doc->title,
                    'is_active' => $doc->is_active,
                    'is_mandatory' => $doc->is_mandatory,
                    'published_at' => $doc->published_at,
                    'effective_at' => $doc->effective_at,
                    'acceptance_count' => $doc->acceptances()->count(),
                    'created_at' => $doc->created_at,
                ];
            })
            ->toArray();
    }
}
