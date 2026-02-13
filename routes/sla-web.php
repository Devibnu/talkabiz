<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\SlaDashboardWebController;
use App\Http\Controllers\SlaAwareSupportController;
use App\Http\Controllers\SlaDashboardController;

/*
|--------------------------------------------------------------------------
| SLA Dashboard Web Routes
|--------------------------------------------------------------------------
|
| These routes handle the web interface for SLA monitoring and management.
| All routes require authentication and proper authorization.
|
*/

// SLA Dashboard Web Interface
Route::middleware(['auth'])->prefix('sla-dashboard')->name('sla-dashboard.')->group(function () {
    
    // Main Dashboard
    Route::get('/', [SlaDashboardWebController::class, 'index'])->name('index');
    
    // Agent Performance
    Route::get('/agents', [SlaDashboardWebController::class, 'agents'])->name('agents');
    Route::get('/agents/{id}', [SlaDashboardWebController::class, 'agentDetails'])->name('agent-details');
    
    // Package Comparison
    Route::get('/packages', [SlaDashboardWebController::class, 'packages'])->name('packages');
    
    // Escalation Management
    Route::get('/escalations', [SlaDashboardWebController::class, 'escalations'])->name('escalations');
    Route::get('/escalations/{id}/timeline', [SlaDashboardWebController::class, 'escalationTimeline'])->name('escalation-timeline');
    Route::post('/escalations/{id}/assign', [SlaDashboardWebController::class, 'assignEscalation'])->name('escalation-assign');
    Route::post('/escalations/{id}/resolve', [SlaDashboardWebController::class, 'resolveEscalation'])->name('escalation-resolve');
    Route::post('/escalations/{id}/notes', [SlaDashboardWebController::class, 'addEscalationNote'])->name('escalation-note');
    Route::post('/escalations/{id}/escalate-higher', [SlaDashboardWebController::class, 'escalateHigher'])->name('escalation-escalate');
    
    // Reports & Export
    Route::get('/export', [SlaDashboardWebController::class, 'export'])->name('export');
    Route::get('/reports', [SlaDashboardWebController::class, 'reports'])->name('reports');
    Route::post('/reports/generate', [SlaDashboardWebController::class, 'generateReport'])->name('generate-report');
    
    // Settings
    Route::get('/settings', [SlaDashboardWebController::class, 'settings'])->name('settings');
    Route::post('/settings', [SlaDashboardWebController::class, 'updateSettings'])->name('update-settings');
    
});

// Customer Self-Service Portal (for viewing own tickets/escalations)
Route::middleware(['auth'])->prefix('support')->name('support.')->group(function () {
    
    // Customer Ticket Management
    Route::get('/tickets', [SlaAwareSupportController::class, 'customerTickets'])->name('tickets');
    Route::get('/tickets/{id}', [SlaAwareSupportController::class, 'viewTicket'])->name('view-ticket');
    Route::post('/tickets', [SlaAwareSupportController::class, 'createTicket'])->name('create-ticket');
    Route::post('/tickets/{id}/response', [SlaAwareSupportController::class, 'addResponse'])->name('add-response');
    
    // Escalation Requests
    Route::post('/tickets/{id}/escalate', [SlaAwareSupportController::class, 'requestEscalation'])->name('request-escalation');
    Route::get('/escalations', [SlaAwareSupportController::class, 'customerEscalations'])->name('escalations');
    
    // Customer satisfaction
    Route::post('/tickets/{id}/satisfaction', [SlaAwareSupportController::class, 'submitSatisfaction'])->name('satisfaction');
    
});

// Agent Support Interface (requires agent/admin role)
Route::middleware(['auth', 'role:agent,admin'])->prefix('agent')->name('agent.')->group(function () {
    
    // Agent Dashboard
    Route::get('/dashboard', [SlaDashboardWebController::class, 'agentDashboard'])->name('dashboard');
    
    // Ticket Management
    Route::get('/tickets', [SlaAwareSupportController::class, 'agentTickets'])->name('tickets');
    Route::get('/tickets/assigned', [SlaAwareSupportController::class, 'assignedTickets'])->name('assigned-tickets');
    Route::post('/tickets/{id}/assign-self', [SlaAwareSupportController::class, 'assignToSelf'])->name('assign-ticket');
    Route::post('/tickets/{id}/resolve', [SlaAwareSupportController::class, 'resolveTicket'])->name('resolve-ticket');
    
    // Quick Actions
    Route::post('/tickets/{id}/priority', [SlaAwareSupportController::class, 'updatePriority'])->name('update-priority');
    Route::post('/tickets/{id}/notes', [SlaAwareSupportController::class, 'addInternalNote'])->name('add-note');
    
});

// Admin Interface (requires admin role)
Route::middleware(['auth', 'role:admin'])->prefix('admin/sla')->name('admin.sla.')->group(function () {
    
    // SLA Definition Management
    Route::get('/definitions', [SlaDashboardWebController::class, 'slaDefinitions'])->name('definitions');
    Route::post('/definitions', [SlaDashboardWebController::class, 'createSlaDefinition'])->name('create-definition');
    Route::put('/definitions/{id}', [SlaDashboardWebController::class, 'updateSlaDefinition'])->name('update-definition');
    Route::delete('/definitions/{id}', [SlaDashboardWebController::class, 'deleteSlaDefinition'])->name('delete-definition');
    
    // Channel Management
    Route::get('/channels', [SlaDashboardWebController::class, 'channels'])->name('channels');
    Route::post('/channels', [SlaDashboardWebController::class, 'createChannel'])->name('create-channel');
    Route::put('/channels/{id}', [SlaDashboardWebController::class, 'updateChannel'])->name('update-channel');
    Route::delete('/channels/{id}', [SlaDashboardWebController::class, 'deleteChannel'])->name('delete-channel');
    
    // System Configuration
    Route::get('/config', [SlaDashboardWebController::class, 'systemConfig'])->name('config');
    Route::post('/config', [SlaDashboardWebController::class, 'updateSystemConfig'])->name('update-config');
    
    // Audit Logs
    Route::get('/audit', [SlaDashboardWebController::class, 'auditLogs'])->name('audit');
    
});

// Real-time updates (WebSocket/AJAX endpoints)
Route::middleware(['auth'])->prefix('sla-api')->name('sla-api.')->group(function () {
    
    // Real-time data endpoints
    Route::get('/realtime/metrics', [SlaDashboardController::class, 'realtimeMetrics'])->name('realtime-metrics');
    Route::get('/realtime/alerts', [SlaDashboardController::class, 'realtimeAlerts'])->name('realtime-alerts');
    Route::get('/realtime/activity', [SlaDashboardController::class, 'realtimeActivity'])->name('realtime-activity');
    
    // Quick status checks
    Route::get('/health', [SlaDashboardController::class, 'healthCheck'])->name('health');
    Route::get('/status/{ticketId}', [SlaDashboardController::class, 'ticketStatus'])->name('ticket-status');
    
});

// Public API endpoints (with proper authentication)
Route::middleware(['auth:api'])->prefix('api/sla')->name('api.sla.')->group(function () {
    
    // Support Operations
    Route::apiResource('tickets', SlaAwareSupportController::class);
    Route::post('tickets/{id}/responses', [SlaAwareSupportController::class, 'store']);
    Route::post('tickets/{id}/escalate', [SlaAwareSupportController::class, 'escalate']);
    
    // Dashboard Data
    Route::get('dashboard/overview', [SlaDashboardController::class, 'overview']);
    Route::get('dashboard/metrics', [SlaDashboardController::class, 'metrics']);
    Route::get('dashboard/agents', [SlaDashboardController::class, 'agentPerformance']);
    Route::get('dashboard/packages', [SlaDashboardController::class, 'packageComparison']);
    Route::get('dashboard/escalations', [SlaDashboardController::class, 'escalationAnalytics']);
    
    // Historical data
    Route::get('analytics/historical', [SlaDashboardController::class, 'historicalData']);
    Route::get('analytics/trends', [SlaDashboardController::class, 'trendAnalysis']);
    Route::get('analytics/performance', [SlaDashboardController::class, 'performanceAnalytics']);
    
});

// Webhook endpoints (for external integrations)
Route::prefix('webhooks/sla')->name('webhooks.sla.')->group(function () {
    
    // External system notifications
    Route::post('ticket-update/{token}', [SlaAwareSupportController::class, 'webhookTicketUpdate'])->name('ticket-update');
    Route::post('escalation-alert/{token}', [SlaDashboardController::class, 'webhookEscalationAlert'])->name('escalation-alert');
    Route::post('sla-breach/{token}', [SlaDashboardController::class, 'webhookSlaBreach'])->name('sla-breach');
    
});