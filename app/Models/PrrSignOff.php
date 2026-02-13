<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * PRR Sign Off
 * 
 * Approval sign-off dari stakeholder.
 * 
 * Roles:
 * - tech_lead: Technical lead approval
 * - ops_lead: Operations lead approval
 * - security: Security team approval
 * - business: Business owner approval
 * - qa: QA lead approval
 * - cto: CTO approval (final)
 */
class PrrSignOff extends Model
{
    use HasFactory;

    protected $table = 'prr_sign_offs';

    protected $fillable = [
        'review_id',
        'role',
        'signer_name',
        'signer_email',
        'signer_user_id',
        'decision',
        'comments',
        'conditions',
        'signed_at',
        'signature_hash',
    ];

    protected $casts = [
        'conditions' => 'array',
        'signed_at' => 'datetime',
    ];

    // =========================================================================
    // RELATIONSHIPS
    // =========================================================================

    public function review(): BelongsTo
    {
        return $this->belongsTo(PrrReview::class, 'review_id');
    }

    public function signerUser()
    {
        return $this->belongsTo(User::class, 'signer_user_id');
    }

    // =========================================================================
    // SCOPES
    // =========================================================================

    public function scopeApproved($query)
    {
        return $query->where('decision', 'approve');
    }

    public function scopeRejected($query)
    {
        return $query->where('decision', 'reject');
    }

    public function scopeByRole($query, string $role)
    {
        return $query->where('role', $role);
    }

    // =========================================================================
    // ACCESSORS
    // =========================================================================

    public function getIsApprovedAttribute(): bool
    {
        return $this->decision === 'approve';
    }

    public function getIsRejectedAttribute(): bool
    {
        return $this->decision === 'reject';
    }

    public function getDecisionIconAttribute(): string
    {
        return match ($this->decision) {
            'approve' => '✅',
            'reject' => '❌',
            'abstain' => '⏸️',
            default => '❓',
        };
    }

    public function getRoleLabelAttribute(): string
    {
        return match ($this->role) {
            'tech_lead' => 'Technical Lead',
            'ops_lead' => 'Operations Lead',
            'security' => 'Security Team',
            'business' => 'Business Owner',
            'qa' => 'QA Lead',
            'cto' => 'CTO',
            default => ucfirst(str_replace('_', ' ', $this->role)),
        };
    }

    // =========================================================================
    // METHODS
    // =========================================================================

    /**
     * Create sign-off record
     */
    public static function signOff(
        PrrReview $review,
        string $role,
        string $decision,
        string $signerName,
        ?string $signerEmail = null,
        ?int $signerUserId = null,
        ?string $comments = null,
        ?array $conditions = null
    ): self {
        // Generate signature hash for tamper detection
        $signatureData = json_encode([
            'review_id' => $review->id,
            'role' => $role,
            'decision' => $decision,
            'signer_name' => $signerName,
            'signed_at' => now()->toIso8601String(),
        ]);
        $signatureHash = hash('sha256', $signatureData . config('app.key'));

        return static::updateOrCreate(
            [
                'review_id' => $review->id,
                'role' => $role,
            ],
            [
                'signer_name' => $signerName,
                'signer_email' => $signerEmail,
                'signer_user_id' => $signerUserId,
                'decision' => $decision,
                'comments' => $comments,
                'conditions' => $conditions,
                'signed_at' => now(),
                'signature_hash' => $signatureHash,
            ]
        );
    }

    /**
     * Verify signature integrity
     */
    public function verifySignature(): bool
    {
        $signatureData = json_encode([
            'review_id' => $this->review_id,
            'role' => $this->role,
            'decision' => $this->decision,
            'signer_name' => $this->signer_name,
            'signed_at' => $this->signed_at->toIso8601String(),
        ]);
        $expectedHash = hash('sha256', $signatureData . config('app.key'));

        return hash_equals($expectedHash, $this->signature_hash);
    }

    // =========================================================================
    // REQUIRED SIGN-OFFS
    // =========================================================================

    public static function getRequiredRoles(): array
    {
        return [
            'tech_lead' => [
                'label' => 'Technical Lead',
                'description' => 'Approves technical implementation and architecture',
                'required' => true,
            ],
            'ops_lead' => [
                'label' => 'Operations Lead',
                'description' => 'Approves operational readiness and procedures',
                'required' => true,
            ],
            'security' => [
                'label' => 'Security Team',
                'description' => 'Approves security controls and compliance',
                'required' => true,
            ],
            'business' => [
                'label' => 'Business Owner',
                'description' => 'Approves business readiness and customer experience',
                'required' => true,
            ],
            'qa' => [
                'label' => 'QA Lead',
                'description' => 'Approves testing coverage and quality',
                'required' => false,
            ],
            'cto' => [
                'label' => 'CTO',
                'description' => 'Final approval for go-live',
                'required' => true,
            ],
        ];
    }

    /**
     * Check if all required sign-offs are complete for a review
     */
    public static function allRequiredComplete(PrrReview $review): bool
    {
        $requiredRoles = collect(static::getRequiredRoles())
            ->filter(fn($r) => $r['required'])
            ->keys()
            ->toArray();

        $completedRoles = $review->signOffs()
            ->approved()
            ->pluck('role')
            ->toArray();

        return empty(array_diff($requiredRoles, $completedRoles));
    }

    /**
     * Get missing required sign-offs
     */
    public static function getMissingRequired(PrrReview $review): array
    {
        $requiredRoles = collect(static::getRequiredRoles())
            ->filter(fn($r) => $r['required'])
            ->toArray();

        $completedRoles = $review->signOffs()
            ->approved()
            ->pluck('role')
            ->toArray();

        $missing = [];
        foreach ($requiredRoles as $role => $info) {
            if (!in_array($role, $completedRoles)) {
                $missing[$role] = $info;
            }
        }

        return $missing;
    }
}
