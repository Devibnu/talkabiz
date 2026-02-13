<!-- Complaint Detail View (loaded via AJAX into modal) -->
<div class="complaint-detail">
    <!-- Header Info -->
    <div class="row mb-3">
        <div class="col-md-6">
            <h6 class="mb-2">Complaint Information</h6>
            <table class="table table-sm table-borderless">
                <tr>
                    <td class="text-sm text-secondary" style="width: 40%;">ID:</td>
                    <td class="text-sm font-weight-bold">#{{ $complaint->id }}</td>
                </tr>
                <tr>
                    <td class="text-sm text-secondary">Type:</td>
                    <td>
                        <span class="badge badge-sm bg-gradient-{{ $complaint->complaint_type === 'phishing' ? 'danger' : ($complaint->complaint_type === 'spam' ? 'warning' : 'info') }}">
                            {{ $complaint->getTypeDisplayName() }}
                        </span>
                    </td>
                </tr>
                <tr>
                    <td class="text-sm text-secondary">Severity:</td>
                    <td>
                        <span class="badge badge-sm {{ $complaint->getSeverityBadgeClass() }}">
                            {{ ucfirst($complaint->severity) }}
                        </span>
                    </td>
                </tr>
                <tr>
                    <td class="text-sm text-secondary">Source:</td>
                    <td class="text-sm">{{ ucfirst(str_replace('_', ' ', $complaint->complaint_source)) }}</td>
                </tr>
                <tr>
                    <td class="text-sm text-secondary">Provider:</td>
                    <td class="text-sm">{{ $complaint->provider_name ?? 'N/A' }}</td>
                </tr>
                <tr>
                    <td class="text-sm text-secondary">Score Impact:</td>
                    <td class="text-sm font-weight-bold">{{ number_format($complaint->abuse_score_impact, 0) }}</td>
                </tr>
                <tr>
                    <td class="text-sm text-secondary">Received:</td>
                    <td class="text-sm">{{ $complaint->complaint_received_at->format('Y-m-d H:i:s') }}</td>
                </tr>
            </table>
        </div>

        <div class="col-md-6">
            <h6 class="mb-2">User & Recipient</h6>
            <table class="table table-sm table-borderless">
                <tr>
                    <td class="text-sm text-secondary" style="width: 40%;">User/Klien:</td>
                    <td>
                        @if($complaint->klien)
                            <div class="text-sm font-weight-bold">{{ $complaint->klien->nama_perusahaan }}</div>
                            <div class="text-xs text-secondary">{{ $complaint->klien->email }}</div>
                            <div class="text-xs">
                                Status: 
                                <span class="badge badge-xs bg-gradient-{{ $complaint->klien->status === 'active' ? 'success' : 'danger' }}">
                                    {{ ucfirst($complaint->klien->status) }}
                                </span>
                            </div>
                        @else
                            <span class="text-sm text-secondary">N/A</span>
                        @endif
                    </td>
                </tr>
                <tr>
                    <td class="text-sm text-secondary">Recipient:</td>
                    <td>
                        <div class="text-sm font-weight-bold">{{ $complaint->recipient_phone }}</div>
                        @if($complaint->recipient_name)
                            <div class="text-xs text-secondary">{{ $complaint->recipient_name }}</div>
                        @endif
                    </td>
                </tr>
                @if($complaint->message_id)
                    <tr>
                        <td class="text-sm text-secondary">Message ID:</td>
                        <td class="text-xs font-family-monospace">{{ $complaint->message_id }}</td>
                    </tr>
                @endif
                <tr>
                    <td class="text-sm text-secondary">Reporter:</td>
                    <td class="text-sm">{{ $complaint->reporter_name ?? 'Anonymous' }}</td>
                </tr>
            </table>
        </div>
    </div>

    <!-- Complaint Reason -->
    @if($complaint->complaint_reason)
        <div class="mb-3">
            <h6 class="mb-2">Complaint Reason</h6>
            <div class="card bg-light">
                <div class="card-body">
                    <p class="text-sm mb-0">{{ $complaint->complaint_reason }}</p>
                </div>
            </div>
        </div>
    @endif

    <!-- Message Sample -->
    @if($complaint->message_content_sample)
        <div class="mb-3">
            <h6 class="mb-2">Message Content Sample</h6>
            <div class="card bg-light">
                <div class="card-body">
                    <p class="text-sm mb-0 font-family-monospace">{{ $complaint->message_content_sample }}</p>
                </div>
            </div>
        </div>
    @endif

    <!-- Processing Status -->
    <div class="mb-3">
        <h6 class="mb-2">Processing Status</h6>
        <table class="table table-sm table-borderless">
            <tr>
                <td class="text-sm text-secondary" style="width: 30%;">Status:</td>
                <td>
                    @if($complaint->is_processed)
                        <span class="badge badge-sm bg-gradient-success">
                            <i class="ni ni-check-bold"></i> Processed
                        </span>
                    @else
                        <span class="badge badge-sm bg-gradient-warning">
                            <i class="ni ni-time-alarm"></i> Unprocessed
                        </span>
                    @endif
                </td>
            </tr>
            @if($complaint->processed_at)
                <tr>
                    <td class="text-sm text-secondary">Processed At:</td>
                    <td class="text-sm">{{ $complaint->processed_at->format('Y-m-d H:i:s') }}</td>
                </tr>
            @endif
            @if($complaint->processor)
                <tr>
                    <td class="text-sm text-secondary">Processed By:</td>
                    <td class="text-sm">{{ $complaint->processor->name }}</td>
                </tr>
            @endif
            @if($complaint->action_taken)
                <tr>
                    <td class="text-sm text-secondary">Action Taken:</td>
                    <td>
                        <span class="badge badge-sm bg-gradient-info">{{ $complaint->action_taken }}</span>
                    </td>
                </tr>
            @endif
            @if($complaint->action_notes)
                <tr>
                    <td class="text-sm text-secondary">Notes:</td>
                    <td class="text-sm">{{ $complaint->action_notes }}</td>
                </tr>
            @endif
        </table>
    </div>

    <!-- Abuse Event Link -->
    @if($complaint->abuseEvent)
        <div class="mb-3">
            <h6 class="mb-2">Linked Abuse Event</h6>
            <div class="card bg-light">
                <div class="card-body">
                    <div class="row">
                        <div class="col-6">
                            <div class="text-xs text-secondary">Event ID:</div>
                            <div class="text-sm font-weight-bold">#{{ $complaint->abuseEvent->id }}</div>
                        </div>
                        <div class="col-6">
                            <div class="text-xs text-secondary">Signal Type:</div>
                            <div class="text-sm">{{ $complaint->abuseEvent->signal_type }}</div>
                        </div>
                        <div class="col-6 mt-2">
                            <div class="text-xs text-secondary">Score Impact:</div>
                            <div class="text-sm font-weight-bold">{{ number_format($complaint->abuseEvent->score_impact, 0) }}</div>
                        </div>
                        <div class="col-6 mt-2">
                            <div class="text-xs text-secondary">Created:</div>
                            <div class="text-sm">{{ $complaint->abuseEvent->created_at->format('Y-m-d H:i') }}</div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    @endif

    <!-- User Statistics -->
    @if($klienStats)
        <div class="mb-3">
            <h6 class="mb-2">User Complaint Statistics (30 Days)</h6>
            <div class="row">
                <div class="col-md-3 col-6 mb-2">
                    <div class="card bg-light">
                        <div class="card-body p-2">
                            <div class="text-xs text-secondary">Total</div>
                            <div class="text-sm font-weight-bold">{{ $klienStats['total_complaints'] }}</div>
                        </div>
                    </div>
                </div>
                <div class="col-md-3 col-6 mb-2">
                    <div class="card bg-light">
                        <div class="card-body p-2">
                            <div class="text-xs text-secondary">Critical</div>
                            <div class="text-sm font-weight-bold text-danger">{{ $klienStats['critical_count'] }}</div>
                        </div>
                    </div>
                </div>
                <div class="col-md-3 col-6 mb-2">
                    <div class="card bg-light">
                        <div class="card-body p-2">
                            <div class="text-xs text-secondary">Unprocessed</div>
                            <div class="text-sm font-weight-bold text-warning">{{ $klienStats['unprocessed_count'] }}</div>
                        </div>
                    </div>
                </div>
                <div class="col-md-3 col-6 mb-2">
                    <div class="card bg-light">
                        <div class="card-body p-2">
                            <div class="text-xs text-secondary">Score Impact</div>
                            <div class="text-sm font-weight-bold">{{ number_format($klienStats['total_score_impact'], 0) }}</div>
                        </div>
                    </div>
                </div>
            </div>

            @if(!empty($klienStats['by_type']))
                <div class="mt-2">
                    <div class="text-xs text-secondary mb-1">By Type:</div>
                    @foreach($klienStats['by_type'] as $type => $count)
                        <span class="badge badge-sm bg-gradient-secondary me-1">{{ ucfirst($type) }}: {{ $count }}</span>
                    @endforeach
                </div>
            @endif
        </div>
    @endif

    <!-- Related Complaints -->
    @if($relatedByRecipient->isNotEmpty() || $relatedByKlien->isNotEmpty())
        <div class="mb-3">
            <h6 class="mb-2">Related Complaints</h6>
            
            @if($relatedByRecipient->isNotEmpty())
                <div class="mb-2">
                    <div class="text-sm font-weight-bold mb-1">Same Recipient ({{ $complaint->recipient_phone }})</div>
                    <div class="table-responsive">
                        <table class="table table-sm">
                            <thead>
                                <tr>
                                    <th class="text-xxs">ID</th>
                                    <th class="text-xxs">Type</th>
                                    <th class="text-xxs">Severity</th>
                                    <th class="text-xxs">Date</th>
                                    <th class="text-xxs">Status</th>
                                </tr>
                            </thead>
                            <tbody>
                                @foreach($relatedByRecipient->take(5) as $related)
                                    <tr>
                                        <td class="text-xs">#{{ $related->id }}</td>
                                        <td><span class="badge badge-xs bg-gradient-secondary">{{ $related->getTypeDisplayName() }}</span></td>
                                        <td><span class="badge badge-xs {{ $related->getSeverityBadgeClass() }}">{{ ucfirst($related->severity) }}</span></td>
                                        <td class="text-xs">{{ $related->complaint_received_at->format('Y-m-d') }}</td>
                                        <td><span class="badge badge-xs bg-gradient-{{ $related->is_processed ? 'success' : 'warning' }}">{{ $related->is_processed ? 'Processed' : 'Pending' }}</span></td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                    @if($relatedByRecipient->count() > 5)
                        <div class="text-xs text-secondary">+ {{ $relatedByRecipient->count() - 5 }} more</div>
                    @endif
                </div>
            @endif

            @if($relatedByKlien->isNotEmpty() && $complaint->klien)
                <div class="mb-2">
                    <div class="text-sm font-weight-bold mb-1">Same User ({{ $complaint->klien->nama_perusahaan }})</div>
                    <div class="table-responsive">
                        <table class="table table-sm">
                            <thead>
                                <tr>
                                    <th class="text-xxs">ID</th>
                                    <th class="text-xxs">Recipient</th>
                                    <th class="text-xxs">Type</th>
                                    <th class="text-xxs">Severity</th>
                                    <th class="text-xxs">Date</th>
                                </tr>
                            </thead>
                            <tbody>
                                @foreach($relatedByKlien->take(5) as $related)
                                    <tr>
                                        <td class="text-xs">#{{ $related->id }}</td>
                                        <td class="text-xs">{{ $related->recipient_phone }}</td>
                                        <td><span class="badge badge-xs bg-gradient-secondary">{{ $related->getTypeDisplayName() }}</span></td>
                                        <td><span class="badge badge-xs {{ $related->getSeverityBadgeClass() }}">{{ ucfirst($related->severity) }}</span></td>
                                        <td class="text-xs">{{ $related->complaint_received_at->format('Y-m-d') }}</td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                    @if($relatedByKlien->count() > 5)
                        <div class="text-xs text-secondary">+ {{ $relatedByKlien->count() - 5 }} more</div>
                    @endif
                </div>
            @endif
        </div>
    @endif

    <!-- Metadata -->
    @if($complaint->complaint_metadata)
        <div class="mb-3">
            <h6 class="mb-2">Additional Metadata</h6>
            <div class="card bg-light">
                <div class="card-body">
                    <pre class="text-xs mb-0">{{ json_encode($complaint->complaint_metadata, JSON_PRETTY_PRINT) }}</pre>
                </div>
            </div>
        </div>
    @endif

    <!-- Action Buttons -->
    @if(!$complaint->is_processed)
        <div class="border-top pt-3 mt-3">
            <div class="d-flex justify-content-end gap-2">
                <button type="button" class="btn btn-sm btn-success" onclick="markAsProcessed({{ $complaint->id }})">
                    <i class="ni ni-check-bold"></i> Mark as Processed
                </button>
                <button type="button" class="btn btn-sm btn-danger" onclick="suspendKlien({{ $complaint->id }}); bootstrap.Modal.getInstance(document.getElementById('complaintDetailModal')).hide();">
                    <i class="ni ni-button-pause"></i> Suspend User
                </button>
                <button type="button" class="btn btn-sm btn-warning" onclick="dismissComplaint({{ $complaint->id }}); bootstrap.Modal.getInstance(document.getElementById('complaintDetailModal')).hide();">
                    <i class="ni ni-fat-remove"></i> Dismiss
                </button>
            </div>
        </div>
    @endif
</div>
