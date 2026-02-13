<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     * 
     * Legal & Terms Enforcement System
     * - Versioning untuk TOS, Privacy, dan AUP
     * - Hanya satu versi active per dokumen
     * - Client wajib explicit accept versi active
     * - Simpan acceptance log (immutable)
     */
    public function up(): void
    {
        // Legal Documents - Versioned legal documents
        if (!Schema::hasTable('legal_documents')) {
            Schema::create('legal_documents', function (Blueprint $table) {
            $table->id();
            $table->string('type', 50); // tos, privacy, aup
            $table->string('version', 20); // e.g., 1.0, 1.1, 2.0
            $table->string('title');
            $table->text('summary')->nullable(); // Brief summary of changes
            $table->longText('content'); // Full document content (HTML or Markdown)
            $table->string('content_format', 20)->default('html'); // html, markdown
            $table->boolean('is_active')->default(false); // Only one active per type
            $table->boolean('is_mandatory')->default(true); // Must accept to use service
            $table->timestamp('published_at')->nullable(); // When made active
            $table->timestamp('effective_at')->nullable(); // When terms take effect
            $table->unsignedBigInteger('created_by')->nullable();
            $table->unsignedBigInteger('activated_by')->nullable();
            $table->timestamps();
            $table->softDeletes();

            // Indexes
            $table->index(['type', 'is_active']);
            $table->unique(['type', 'version']); // No duplicate versions per type
            $table->index('is_active');
            $table->index('effective_at');

            // Foreign keys
            $table->foreign('created_by')->references('id')->on('users')->nullOnDelete();
            $table->foreign('activated_by')->references('id')->on('users')->nullOnDelete();
        });
}

        // Legal Acceptances - Immutable acceptance log
        if (!Schema::hasTable('legal_acceptances')) {
            Schema::create('legal_acceptances', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('klien_id');
            $table->unsignedBigInteger('user_id')->nullable(); // User who clicked accept
            $table->unsignedBigInteger('legal_document_id');
            $table->string('document_type', 50); // Denormalized for quick queries
            $table->string('document_version', 20); // Denormalized for audit
            $table->timestamp('accepted_at');
            $table->string('acceptance_method', 50)->default('web_click'); // web_click, api, checkbox
            $table->string('ip_address', 45)->nullable();
            $table->text('user_agent')->nullable();
            $table->json('additional_data')->nullable(); // Browser info, device, etc.
            $table->timestamps();

            // No update/delete - this is immutable
            // Indexes for quick lookups
            $table->index(['klien_id', 'document_type']);
            $table->index(['klien_id', 'legal_document_id']);
            $table->index('accepted_at');
            $table->index('document_type');

            // Foreign keys
            $table->foreign('klien_id')->references('id')->on('klien')->onDelete('cascade');
            $table->foreign('user_id')->references('id')->on('users')->nullOnDelete();
            $table->foreign('legal_document_id')->references('id')->on('legal_documents')->onDelete('cascade');
        });
}

        // Legal Document Versions History - Track all changes
        if (!Schema::hasTable('legal_document_events')) {
            Schema::create('legal_document_events', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('legal_document_id');
            $table->string('event_type', 50); // created, updated, activated, deactivated, deleted
            $table->unsignedBigInteger('performed_by')->nullable();
            $table->json('old_values')->nullable();
            $table->json('new_values')->nullable();
            $table->text('notes')->nullable();
            $table->string('ip_address', 45)->nullable();
            $table->timestamp('created_at');

            // Foreign keys
            $table->foreign('legal_document_id')->references('id')->on('legal_documents')->onDelete('cascade');
            $table->foreign('performed_by')->references('id')->on('users')->nullOnDelete();

            $table->index(['legal_document_id', 'event_type']);
            $table->index('created_at');
        });
}

        // Pending Acceptances - Track who needs to accept what
        if (!Schema::hasTable('legal_pending_acceptances')) {
            Schema::create('legal_pending_acceptances', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('klien_id');
            $table->unsignedBigInteger('legal_document_id');
            $table->string('document_type', 50);
            $table->timestamp('required_since'); // When acceptance became required
            $table->timestamp('reminded_at')->nullable(); // Last reminder sent
            $table->unsignedInteger('reminder_count')->default(0);
            $table->boolean('is_blocking')->default(true); // Block access until accepted
            $table->timestamps();

            $table->unique(['klien_id', 'legal_document_id']);
            $table->index(['klien_id', 'is_blocking']);
            $table->index('document_type');

            $table->foreign('klien_id')->references('id')->on('klien')->onDelete('cascade');
            $table->foreign('legal_document_id')->references('id')->on('legal_documents')->onDelete('cascade');
        });
}
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('legal_pending_acceptances');
        Schema::dropIfExists('legal_document_events');
        Schema::dropIfExists('legal_acceptances');
        Schema::dropIfExists('legal_documents');
    }
};
