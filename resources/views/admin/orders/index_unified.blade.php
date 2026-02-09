@extends('layouts.admin')

@section('title', 'Unified Bookings')

@section('content')
<div class="content container-fluid">
    <!-- Page Header -->
    <div class="page-header mb-4">
        <div class="row align-items-center">
            <div class="col-sm mb-2 mb-sm-0">
                <h1 class="page-header-title">
                    <i class="tio-wallet me-2"></i> Unified Bookings
                </h1>
                <p class="text-muted mb-0">Manage all checkout groups and reservations</p>
            </div>
        </div>
    </div>

    <!-- Search & Filter Card -->
    <div class="card mb-4 shadow-sm">
        <div class="card-body">
            <form method="GET" action="{{ route('admin.unified-bookings.index') }}">
                <div class="row g-3">
                    <div class="col-md-10">
                        <div class="input-group input-group-merge">
                            <div class="input-group-prepend input-group-text">
                                <i class="tio-search"></i>
                            </div>
                            <input type="text" name="search" class="form-control" 
                                   placeholder="Search by confirmation code, name, or email..." 
                                   value="{{ request('search') }}">
                        </div>
                    </div>
                    <div class="col-md-2">
                        <button type="submit" class="btn btn-primary w-100">
                            <i class="tio-search me-1"></i> Search
                        </button>
                    </div>
                </div>
            </form>
        </div>
    </div>

    <!-- Bookings Table -->
    <div class="card shadow-sm">
        <div class="card-header bg-white border-bottom">
            <h5 class="card-header-title mb-0">All Bookings</h5>
        </div>
        <div class="table-responsive">
            <table class="table table-hover table-borderless table-thead-bordered table-nowrap table-align-middle card-table">
                <thead class="thead-light">
                    <tr>
                        <th>Confirmation Code</th>
                        <th>Customer</th>
                        <th>Check-In</th>
                        <th>Check-Out</th>
                        <th class="text-end">Total</th>
                        <th class="text-end">Paid</th>
                        <th class="text-end">Balance</th>
                        <th>Status</th>
                        <th class="text-end">Actions</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse($checkouts as $checkout)
                    <tr>
                        <td>
                            <a href="{{ route('admin.unified-bookings.show', $checkout->group_confirmation_code) }}" 
                               class="text-primary fw-semibold">
                                {{ $checkout->group_confirmation_code }}
                            </a>
                        </td>
                        <td>
                            <div class="d-flex flex-column">
                                <span class="fw-medium">{{ $checkout->fname }} {{ $checkout->lname }}</span>
                                <small class="text-muted">{{ $checkout->email }}</small>
                            </div>
                        </td>
                        <td>{{ \Carbon\Carbon::parse($checkout->min_cid)->format('M d, Y') }}</td>
                        <td>{{ \Carbon\Carbon::parse($checkout->max_cod)->format('M d, Y') }}</td>
                        <td class="text-end fw-semibold">${{ number_format($checkout->grand_total, 2) }}</td>
                        <td class="text-end">${{ number_format($checkout->net_paid, 2) }}</td>
                        <td class="text-end">
                            <span class="{{ $checkout->balance > 0 ? 'text-danger' : 'text-success' }} fw-semibold">
                                ${{ number_format(abs($checkout->balance), 2) }}
                            </span>
                        </td>
                        <td>
                            @if($checkout->balance <= 0)
                                <span class="badge bg-soft-success text-success">
                                    <i class="tio-checkmark-circle me-1"></i> Paid
                                </span>
                            @elseif($checkout->net_paid > 0)
                                <span class="badge bg-soft-warning text-warning">
                                    <i class="tio-time me-1"></i> Partial
                                </span>
                            @else
                                <span class="badge bg-soft-danger text-danger">
                                    <i class="tio-clear me-1"></i> Unpaid
                                </span>
                            @endif
                        </td>
                        <td class="text-end">
                            <a href="{{ route('admin.unified-bookings.show', $checkout->group_confirmation_code) }}" 
                               class="btn btn-sm btn-white">
                                <i class="tio-visible-outlined me-1"></i> View
                            </a>
                        </td>
                    </tr>
                    @empty
                    <tr>
                        <td colspan="9" class="text-center py-5">
                            <div class="text-muted">
                                <i class="tio-search" style="font-size: 3rem;"></i>
                                <p class="mt-3">No bookings found</p>
                            </div>
                        </td>
                    </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
        <div class="card-footer border-top">
            {{ $checkouts->links() }}
        </div>
    </div>
</div>

<style>
.table-hover tbody tr:hover {
    background-color: rgba(55, 125, 255, 0.04);
    transition: background-color 0.2s ease;
}

.badge {
    padding: 0.35rem 0.65rem;
    font-weight: 600;
    font-size: 0.75rem;
}

.card {
    border-radius: 0.5rem;
}

.card-header {
    border-top-left-radius: 0.5rem;
    border-top-right-radius: 0.5rem;
}
</style>
@endsection
