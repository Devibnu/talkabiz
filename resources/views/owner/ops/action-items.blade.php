@extends('layouts.owner')

@section('title', 'Action Items')

@section('content')
<div class="container-fluid py-4">
    {{-- Header --}}
    <div class="row mb-4">
        <div class="col-12">
            <div class="card bg-gradient-warning shadow-lg">
                <div class="card-body p-4">
                    <div class="row align-items-center">
                        <div class="col-lg-8">
                            <h2 class="text-white mb-1">
                                📋 Action Items
                            </h2>
                            <p class="text-white opacity-8 mb-0">
                                Track and manage tasks from daily ops checks
                            </p>
                        </div>
                        <div class="col-lg-4 text-end">
                            <button class="btn btn-white" data-bs-toggle="modal" data-bs-target="#addItemModal">
                                <i class="fas fa-plus me-2"></i> Add Item
                            </button>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    {{-- Filter Tabs --}}
    <div class="row mb-4">
        <div class="col-12">
            <ul class="nav nav-pills">
                <li class="nav-item">
                    <a class="nav-link {{ $status === 'open' ? 'active' : '' }}" 
                       href="{{ route('owner.ops.action-items', ['status' => 'open']) }}">
                        Open
                    </a>
                </li>
                <li class="nav-item">
                    <a class="nav-link {{ $status === 'in_progress' ? 'active' : '' }}" 
                       href="{{ route('owner.ops.action-items', ['status' => 'in_progress']) }}">
                        In Progress
                    </a>
                </li>
                <li class="nav-item">
                    <a class="nav-link {{ $status === 'completed' ? 'active' : '' }}" 
                       href="{{ route('owner.ops.action-items', ['status' => 'completed']) }}">
                        Completed
                    </a>
                </li>
                <li class="nav-item">
                    <a class="nav-link {{ $status === 'all' ? 'active' : '' }}" 
                       href="{{ route('owner.ops.action-items', ['status' => 'all']) }}">
                        All
                    </a>
                </li>
            </ul>
        </div>
    </div>

    {{-- Items List --}}
    <div class="row">
        <div class="col-12">
            <div class="card">
                <div class="card-body px-0 pt-0 pb-2">
                    @if($items->count() > 0)
                        <div class="table-responsive">
                            <table class="table align-items-center mb-0">
                                <thead>
                                    <tr>
                                        <th class="text-uppercase text-secondary text-xxs font-weight-bolder opacity-7 ps-4">Priority</th>
                                        <th class="text-uppercase text-secondary text-xxs font-weight-bolder opacity-7">Item</th>
                                        <th class="text-uppercase text-secondary text-xxs font-weight-bolder opacity-7">Category</th>
                                        <th class="text-uppercase text-secondary text-xxs font-weight-bolder opacity-7">Status</th>
                                        <th class="text-uppercase text-secondary text-xxs font-weight-bolder opacity-7">Due Date</th>
                                        <th class="text-uppercase text-secondary text-xxs font-weight-bolder opacity-7 text-end pe-4">Action</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    @foreach($items as $item)
                                        @php
                                            $priorityColors = [
                                                'critical' => 'danger',
                                                'high' => 'warning',
                                                'medium' => 'primary',
                                                'low' => 'secondary'
                                            ];
                                            $statusColors = [
                                                'open' => 'primary',
                                                'in_progress' => 'info',
                                                'completed' => 'success',
                                                'wont_fix' => 'secondary',
                                                'deferred' => 'dark'
                                            ];
                                        @endphp
                                        <tr>
                                            <td class="ps-4">
                                                <span class="badge bg-{{ $priorityColors[$item->priority] ?? 'secondary' }}">
                                                    {{ strtoupper($item->priority) }}
                                                </span>
                                            </td>
                                            <td>
                                                <span class="text-sm font-weight-bold">{{ $item->title }}</span>
                                                @if($item->description)
                                                    <br>
                                                    <small class="text-muted">{{ Str::limit($item->description, 60) }}</small>
                                                @endif
                                            </td>
                                            <td>
                                                <span class="badge bg-light text-dark">{{ $item->category }}</span>
                                            </td>
                                            <td>
                                                <span class="badge bg-{{ $statusColors[$item->status] ?? 'secondary' }}">
                                                    {{ str_replace('_', ' ', strtoupper($item->status)) }}
                                                </span>
                                            </td>
                                            <td>
                                                @if($item->due_date)
                                                    @php
                                                        $dueDate = \Carbon\Carbon::parse($item->due_date);
                                                        $isOverdue = $dueDate->isPast() && $item->status !== 'completed';
                                                    @endphp
                                                    <span class="{{ $isOverdue ? 'text-danger' : '' }}">
                                                        {{ $dueDate->format('d M Y') }}
                                                        @if($isOverdue)
                                                            <i class="fas fa-exclamation-triangle"></i>
                                                        @endif
                                                    </span>
                                                @else
                                                    <span class="text-muted">-</span>
                                                @endif
                                            </td>
                                            <td class="text-end pe-4">
                                                <div class="dropdown">
                                                    <button class="btn btn-sm btn-outline-secondary dropdown-toggle" 
                                                            type="button" data-bs-toggle="dropdown">
                                                        Actions
                                                    </button>
                                                    <ul class="dropdown-menu dropdown-menu-end">
                                                        @if($item->status === 'open')
                                                            <li>
                                                                <form action="{{ route('owner.ops.update-action-item', $item->id) }}" method="POST">
                                                                    @csrf
                                                                    @method('PUT')
                                                                    <input type="hidden" name="status" value="in_progress">
                                                                    <button type="submit" class="dropdown-item">
                                                                        <i class="fas fa-play me-2"></i> Start
                                                                    </button>
                                                                </form>
                                                            </li>
                                                        @endif
                                                        @if($item->status !== 'completed')
                                                            <li>
                                                                <form action="{{ route('owner.ops.update-action-item', $item->id) }}" method="POST">
                                                                    @csrf
                                                                    @method('PUT')
                                                                    <input type="hidden" name="status" value="completed">
                                                                    <button type="submit" class="dropdown-item text-success">
                                                                        <i class="fas fa-check me-2"></i> Complete
                                                                    </button>
                                                                </form>
                                                            </li>
                                                            <li>
                                                                <form action="{{ route('owner.ops.update-action-item', $item->id) }}" method="POST">
                                                                    @csrf
                                                                    @method('PUT')
                                                                    <input type="hidden" name="status" value="deferred">
                                                                    <button type="submit" class="dropdown-item text-warning">
                                                                        <i class="fas fa-clock me-2"></i> Defer
                                                                    </button>
                                                                </form>
                                                            </li>
                                                            <li>
                                                                <form action="{{ route('owner.ops.update-action-item', $item->id) }}" method="POST">
                                                                    @csrf
                                                                    @method('PUT')
                                                                    <input type="hidden" name="status" value="wont_fix">
                                                                    <button type="submit" class="dropdown-item text-secondary">
                                                                        <i class="fas fa-times me-2"></i> Won't Fix
                                                                    </button>
                                                                </form>
                                                            </li>
                                                        @endif
                                                    </ul>
                                                </div>
                                            </td>
                                        </tr>
                                    @endforeach
                                </tbody>
                            </table>
                        </div>

                        {{-- Pagination --}}
                        <div class="px-4 pt-3">
                            {{ $items->links() }}
                        </div>
                    @else
                        <div class="text-center py-5">
                            <i class="fas fa-clipboard-check fa-3x text-muted mb-3"></i>
                            <p class="text-muted mb-0">No action items found</p>
                        </div>
                    @endif
                </div>
            </div>
        </div>
    </div>

    {{-- Back Button --}}
    <div class="row mt-4">
        <div class="col-12">
            <a href="{{ route('owner.ops.index') }}" class="btn btn-outline-secondary">
                <i class="fas fa-arrow-left me-2"></i> Back to Daily Ops
            </a>
        </div>
    </div>
</div>

{{-- Add Item Modal --}}
<div class="modal fade" id="addItemModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <form action="{{ route('owner.ops.create-action-item') }}" method="POST">
                @csrf
                <div class="modal-header">
                    <h5 class="modal-title">Add Action Item</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <div class="mb-3">
                        <label class="form-label">Title *</label>
                        <input type="text" name="title" class="form-control" required>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Description</label>
                        <textarea name="description" class="form-control" rows="3"></textarea>
                    </div>
                    <div class="row">
                        <div class="col-md-6 mb-3">
                            <label class="form-label">Priority *</label>
                            <select name="priority" class="form-select" required>
                                <option value="critical">Critical</option>
                                <option value="high">High</option>
                                <option value="medium" selected>Medium</option>
                                <option value="low">Low</option>
                            </select>
                        </div>
                        <div class="col-md-6 mb-3">
                            <label class="form-label">Category *</label>
                            <select name="category" class="form-select" required>
                                <option value="stability">Stability</option>
                                <option value="deliverability">Deliverability</option>
                                <option value="billing">Billing</option>
                                <option value="ux">UX</option>
                                <option value="security">Security</option>
                                <option value="other" selected>Other</option>
                            </select>
                        </div>
                    </div>
                    <div class="row">
                        <div class="col-md-6 mb-3">
                            <label class="form-label">Due Date</label>
                            <input type="date" name="due_date" class="form-control">
                        </div>
                        <div class="col-md-6 mb-3">
                            <label class="form-label">Assigned To</label>
                            <input type="text" name="assigned_to" class="form-control" placeholder="Name">
                        </div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-primary">Create Item</button>
                </div>
            </form>
        </div>
    </div>
</div>
@endsection
