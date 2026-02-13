<div class="table-responsive">
    @if($rule_list->isEmpty())
        <p class="text-center text-muted py-4">No rules in this category yet.</p>
    @else
        <table class="table table-hover align-items-center mb-0">
            <thead>
                <tr>
                    <th class="text-uppercase text-secondary text-xxs font-weight-bolder opacity-7">Rule</th>
                    <th class="text-uppercase text-secondary text-xxs font-weight-bolder opacity-7">Context</th>
                    <th class="text-uppercase text-secondary text-xxs font-weight-bolder opacity-7">Limits</th>
                    <th class="text-uppercase text-secondary text-xxs font-weight-bolder opacity-7">Action</th>
                    <th class="text-uppercase text-secondary text-xxs font-weight-bolder opacity-7">Priority</th>
                    <th class="text-uppercase text-secondary text-xxs font-weight-bolder opacity-7">Status</th>
                    <th class="text-uppercase text-secondary text-xxs font-weight-bolder opacity-7">Actions</th>
                </tr>
            </thead>
            <tbody>
                @foreach($rule_list as $rule)
                    <tr>
                        <td>
                            <div class="d-flex flex-column">
                                <h6 class="mb-0 text-sm">{{ $rule->name }}</h6>
                                @if($rule->description)
                                    <p class="text-xs text-secondary mb-0">{{ Str::limit($rule->description, 50) }}</p>
                                @endif
                                @if($rule->endpoint_pattern)
                                    <span class="badge badge-sm bg-gradient-dark mt-1">{{ $rule->endpoint_pattern }}</span>
                                @endif
                            </div>
                        </td>
                        <td>
                            <span class="badge badge-sm bg-gradient-info">{{ ucfirst($rule->context_type) }}</span>
                            @if($rule->risk_level)
                                <br><span class="badge badge-sm bg-gradient-{{ $rule->risk_level == 'critical' ? 'danger' : ($rule->risk_level == 'high' ? 'warning' : 'info') }} mt-1">
                                    Risk: {{ ucfirst($rule->risk_level) }}
                                </span>
                            @endif
                            @if($rule->saldo_status)
                                <br><span class="badge badge-sm bg-gradient-success mt-1">
                                    Saldo: {{ ucfirst($rule->saldo_status) }}
                                </span>
                            @endif
                        </td>
                        <td>
                            <div class="d-flex flex-column">
                                <span class="text-xs"><strong>{{ $rule->max_requests }}</strong> req / {{ $rule->window_seconds }}s</span>
                                <span class="text-xxs text-muted">{{ ucfirst(str_replace('_', ' ', $rule->algorithm)) }}</span>
                            </div>
                        </td>
                        <td>
                            @if($rule->action == 'block')
                                <span class="badge badge-sm bg-gradient-danger">BLOCK</span>
                            @elseif($rule->action == 'throttle')
                                <span class="badge badge-sm bg-gradient-warning">THROTTLE</span>
                                @if($rule->throttle_delay_ms)
                                    <br><span class="text-xxs text-muted">{{ $rule->throttle_delay_ms }}ms delay</span>
                                @endif
                            @else
                                <span class="badge badge-sm bg-gradient-info">WARN</span>
                            @endif
                        </td>
                        <td>
                            <span class="badge badge-sm bg-gradient-secondary">{{ $rule->priority }}</span>
                        </td>
                        <td>
                            <div class="form-check form-switch">
                                <input class="form-check-input" type="checkbox" 
                                       id="toggle-{{ $rule->id }}" 
                                       {{ $rule->is_active ? 'checked' : '' }}
                                       onchange="toggleRule({{ $rule->id }})">
                                <label class="form-check-label text-xs" for="toggle-{{ $rule->id }}">
                                    {{ $rule->is_active ? 'Active' : 'Inactive' }}
                                </label>
                            </div>
                        </td>
                        <td>
                            <button type="button" class="btn btn-sm btn-info mb-0" 
                                    data-bs-toggle="modal" 
                                    data-bs-target="#editModal-{{ $rule->id }}">
                                <i class="fas fa-edit"></i>
                            </button>
                            <button type="button" class="btn btn-sm btn-danger mb-0" 
                                    onclick="deleteRule({{ $rule->id }})">
                                <i class="fas fa-trash"></i>
                            </button>
                        </td>
                    </tr>

                    {{-- Edit Modal for this rule --}}
                    <div class="modal fade" id="editModal-{{ $rule->id }}" tabindex="-1" aria-hidden="true">
                        <div class="modal-dialog modal-lg">
                            <div class="modal-content">
                                <div class="modal-header">
                                    <h5 class="modal-title">Edit: {{ $rule->name }}</h5>
                                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                                </div>
                                <div class="modal-body">
                                    <form id="editForm-{{ $rule->id }}">
                                        <div class="row">
                                            <div class="col-md-6 mb-3">
                                                <label class="form-label">Max Requests *</label>
                                                <input type="number" class="form-control" name="max_requests" 
                                                       value="{{ $rule->max_requests }}" min="0" max="10000" required>
                                            </div>
                                            <div class="col-md-6 mb-3">
                                                <label class="form-label">Window (seconds) *</label>
                                                <input type="number" class="form-control" name="window_seconds" 
                                                       value="{{ $rule->window_seconds }}" min="1" max="86400" required>
                                            </div>
                                        </div>

                                        <div class="row">
                                            <div class="col-md-4 mb-3">
                                                <label class="form-label">Algorithm *</label>
                                                <select class="form-control" name="algorithm" required>
                                                    <option value="sliding_window" {{ $rule->algorithm == 'sliding_window' ? 'selected' : '' }}>Sliding Window</option>
                                                    <option value="token_bucket" {{ $rule->algorithm == 'token_bucket' ? 'selected' : '' }}>Token Bucket</option>
                                                </select>
                                            </div>
                                            <div class="col-md-4 mb-3">
                                                <label class="form-label">Action *</label>
                                                <select class="form-control" name="action" required>
                                                    <option value="block" {{ $rule->action == 'block' ? 'selected' : '' }}>Block</option>
                                                    <option value="throttle" {{ $rule->action == 'throttle' ? 'selected' : '' }}>Throttle</option>
                                                    <option value="warn" {{ $rule->action == 'warn' ? 'selected' : '' }}>Warn</option>
                                                </select>
                                            </div>
                                            <div class="col-md-4 mb-3">
                                                <label class="form-label">Priority *</label>
                                                <input type="number" class="form-control" name="priority" 
                                                       value="{{ $rule->priority }}" min="1" max="100" required>
                                            </div>
                                        </div>

                                        <div class="mb-3">
                                            <label class="form-label">Throttle Delay (ms)</label>
                                            <input type="number" class="form-control" name="throttle_delay_ms" 
                                                   value="{{ $rule->throttle_delay_ms }}" min="0" max="10000">
                                        </div>

                                        <div class="mb-3">
                                            <label class="form-label">Block Message</label>
                                            <input type="text" class="form-control" name="block_message" 
                                                   value="{{ $rule->block_message }}">
                                        </div>

                                        <div class="form-check mb-3">
                                            <input class="form-check-input" type="checkbox" name="is_active" 
                                                   value="1" {{ $rule->is_active ? 'checked' : '' }}>
                                            <label class="form-check-label">Active</label>
                                        </div>
                                    </form>
                                </div>
                                <div class="modal-footer">
                                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                                    <button type="button" class="btn btn-primary" onclick="updateRule({{ $rule->id }})">
                                        Save Changes
                                    </button>
                                </div>
                            </div>
                        </div>
                    </div>
                @endforeach
            </tbody>
        </table>
    @endif
</div>
