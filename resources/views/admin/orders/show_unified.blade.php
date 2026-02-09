@extends('layouts.admin')

@section('title', 'Booking Details')

@section('content')
<div class="content container-fluid">
    <!-- Page Header -->
    <div class="page-header mb-4">
        <div class="row align-items-center">
            <div class="col-sm mb-2 mb-sm-0">
                <nav aria-label="breadcrumb">
                    <ol class="breadcrumb breadcrumb-no-gutter">
                        <li class="breadcrumb-item"><a class="breadcrumb-link" href="{{ route('admin.unified-bookings.index') }}">Unified Bookings</a></li>
                        <li class="breadcrumb-item active" aria-current="page">{{ $mainRes->group_confirmation_code }}</li>
                    </ol>
                </nav>
                <h1 class="page-header-title">
                    Booking #{{ $mainRes->group_confirmation_code }}
                </h1>
            </div>
            <div class="col-sm-auto">
                @if($balanceDue <= 0)
                    <span class="badge bg-soft-success text-success px-3 py-2" style="font-size: 1rem;">
                        <i class="tio-checkmark-circle me-1"></i> Fully Paid
                    </span>
                @elseif($totalPayments > 0)
                    <span class="badge bg-soft-warning text-warning px-3 py-2" style="font-size: 1rem;">
                        <i class="tio-time me-1"></i> Partially Paid
                    </span>
                @else
                    <span class="badge bg-soft-danger text-danger px-3 py-2" style="font-size: 1rem;">
                        <i class="tio-clear me-1"></i> Unpaid
                    </span>
                @endif
            </div>
        </div>
    </div>

    <div class="row">
        <!-- Left Column: Customer & Reservations -->
        <div class="col-lg-8">
            <!-- Customer Information -->
            <div class="card mb-4 shadow-sm">
                <div class="card-header bg-white">
                    <h5 class="card-header-title mb-0">
                        <i class="tio-user me-2"></i> Customer Information
                    </h5>
                </div>
                <div class="card-body">
                    <div class="row">
                        <div class="col-md-6">
                            <p class="mb-2">
                                <strong>Name:</strong> {{ $mainRes->fname }} {{ $mainRes->lname }}
                            </p>
                            <p class="mb-2">
                                <strong>Email:</strong> 
                                <a href="mailto:{{ $mainRes->email }}">{{ $mainRes->email }}</a>
                            </p>
                        </div>
                        <div class="col-md-6">
                            @if($mainRes->user)
                            <p class="mb-2">
                                <strong>Phone:</strong> 
                                @if($mainRes->user->phone)
                                    <a href="tel:{{ $mainRes->user->phone }}">{{ $mainRes->user->phone }}</a>
                                @else
                                    <span class="text-muted">N/A</span>
                                @endif
                            </p>
                            @endif
                            <p class="mb-2">
                                <strong>Customer ID:</strong> {{ $mainRes->customernumber }}
                            </p>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Reservations in this Booking -->
            <div class="card mb-4 shadow-sm">
                <div class="card-header bg-white">
                    <h5 class="card-header-title mb-0">
                        <i class="tio-camping-tent me-2"></i> Reservations ({{ $reservations->count() }})
                    </h5>
                </div>
                <div class="card-body p-0">
                    <div class="table-responsive">
                        <table class="table table-borderless table-nowrap table-align-middle mb-0">
                            <thead class="thead-light">
                                <tr>
                                    <th>Site</th>
                                    <th>Check-In</th>
                                    <th>Check-Out</th>
                                    <th>Nights</th>
                                    <th class="text-end">Total</th>
                                    <th>Status</th>
                                    <th class="text-end">Actions</th>
                                </tr>
                            </thead>
                            <tbody>
                                @foreach($reservations as $res)
                                <tr>
                                    <td>
                                        <a href="{{ route('admin.reservations.show', $res->id) }}" class="text-primary fw-semibold">
                                            {{ $res->site->sitename ?? $res->siteid }}
                                        </a>
                                    </td>
                                    <td>{{ \Carbon\Carbon::parse($res->cid)->format('M d, Y') }}</td>
                                    <td>{{ \Carbon\Carbon::parse($res->cod)->format('M d, Y') }}</td>
                                    <td>{{ $res->nights ?? 0 }}</td>
                                    <td class="text-end fw-semibold">${{ number_format($res->total, 2) }}</td>
                                    <td>
                                        @if($res->status === 'Cancelled')
                                            <span class="badge bg-soft-secondary text-secondary">Cancelled</span>
                                        @else
                                            <span class="badge bg-soft-primary text-primary">Active</span>
                                        @endif
                                    </td>
                                    <td class="text-end">
                                        <div class="btn-group btn-group-sm" role="group">
                                            <a href="{{ route('admin.reservations.modify', $res->id) }}" 
                                               class="btn btn-white" title="Modify">
                                                <i class="tio-edit"></i>
                                            </a>
                                            @if($res->status !== 'Cancelled')
                                            <button type="button" class="btn btn-white text-danger" 
                                                    onclick="refundReservation({{ $res->id }}, {{ $res->total }})" 
                                                    title="Refund">
                                                <i class="tio-money-vs"></i>
                                            </button>
                                            @endif
                                        </div>
                                    </td>
                                </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>

            <!-- Financial Ledger -->
            <div class="card shadow-sm">
                <div class="card-header bg-white">
                    <h5 class="card-header-title mb-0">
                        <i class="tio-receipt me-2"></i> Financial Ledger
                    </h5>
                </div>
                <div class="card-body p-0">
                    <div class="table-responsive">
                        <table class="table table-sm table-borderless table-nowrap mb-0 ledger-table">
                            <thead class="thead-light">
                                <tr>
                                    <th>Date</th>
                                    <th>Description</th>
                                    <th class="text-end">Charges</th>
                                    <th class="text-end">Payments</th>
                                    <th>Reference</th>
                                </tr>
                            </thead>
                            <tbody>
                                @php $runningBalance = 0; @endphp
                                @foreach($ledger as $entry)
                                    @php
                                        $runningBalance += $entry['amount'];
                                        $isCharge = $entry['type'] === 'charge';
                                        $isPayment = $entry['type'] === 'payment';
                                        $isRefund = $entry['type'] === 'refund';
                                    @endphp
                                    <tr class="{{ $isPayment ? 'table-success-soft' : ($isRefund ? 'table-warning-soft' : '') }}">
                                        <td class="text-nowrap">
                                            {{ \Carbon\Carbon::parse($entry['date'])->format('M d, Y') }}
                                        </td>
                                        <td>
                                            @if($isPayment)
                                                <i class="tio-checkmark-circle text-success me-1"></i>
                                            @elseif($isRefund)
                                                <i class="tio-money-vs text-warning me-1"></i>
                                            @else
                                                <i class="tio-add-circle text-primary me-1"></i>
                                            @endif
                                            {{ $entry['description'] }}
                                        </td>
                                        <td class="text-end">
                                            @if($isCharge)
                                                <span class="text-dark fw-semibold">${{ number_format($entry['amount'], 2) }}</span>
                                            @else
                                                <span class="text-muted">—</span>
                                            @endif
                                        </td>
                                        <td class="text-end">
                                            @if($isPayment)
                                                <span class="text-success fw-semibold">${{ number_format(abs($entry['amount']), 2) }}</span>
                                            @elseif($isRefund)
                                                <span class="text-warning fw-semibold">${{ number_format($entry['amount'], 2) }}</span>
                                            @else
                                                <span class="text-muted">—</span>
                                            @endif
                                        </td>
                                        <td class="text-muted small">{{ $entry['ref'] ?? '—' }}</td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>

        <!-- Right Column: Financial Summary -->
        <div class="col-lg-4">
            <div class="card shadow-sm sticky-top" style="top: 1rem;">
                <div class="card-header bg-primary text-white">
                    <h5 class="card-header-title mb-0 text-white">
                        <i class="tio-dollar me-2"></i> Financial Summary
                    </h5>
                </div>
                <div class="card-body">
                    <div class="mb-3 pb-3 border-bottom">
                        <div class="d-flex justify-content-between mb-2">
                            <span class="text-muted">Total Charges:</span>
                            <span class="fw-semibold">${{ number_format($totalCharges, 2) }}</span>
                        </div>
                        <div class="d-flex justify-content-between mb-2">
                            <span class="text-muted">Total Payments:</span>
                            <span class="text-success fw-semibold">-${{ number_format($totalPayments, 2) }}</span>
                        </div>
                        @if($totalRefunds > 0)
                        <div class="d-flex justify-content-between mb-2">
                            <span class="text-muted">Total Refunds:</span>
                            <span class="text-warning fw-semibold">+${{ number_format($totalRefunds, 2) }}</span>
                        </div>
                        @endif
                    </div>
                    <div class="d-flex justify-content-between align-items-center">
                        <span class="h5 mb-0">Balance Due:</span>
                        <span class="h4 mb-0 {{ $balanceDue > 0 ? 'text-danger' : 'text-success' }}">
                            ${{ number_format(abs($balanceDue), 2) }}
                        </span>
                    </div>
                    @if($balanceDue < 0)
                        <p class="text-muted small mt-2 mb-0">
                            <i class="tio-info-outined me-1"></i> Credit balance available
                        </p>
                    @endif
                </div>
            </div>
        </div>
    </div>
</div>

<style>
.ledger-table tbody tr {
    border-bottom: 1px solid #e7eaf3;
}

.ledger-table tbody tr:last-child {
    border-bottom: none;
}

.table-success-soft {
    background-color: rgba(0, 201, 167, 0.05);
}

.table-warning-soft {
    background-color: rgba(255, 193, 7, 0.05);
}

.card {
    border-radius: 0.5rem;
}

.sticky-top {
    z-index: 1020;
}
</style>

<script>
function refundReservation(reservationId, total) {
    Swal.fire({
        title: 'Process Refund',
        html: `
            <div class="mb-3">
                <label class="form-label">Refund Amount</label>
                <input type="number" id="refund_amount" class="form-control" 
                       placeholder="0.00" step="0.01" max="${total}" value="${total}">
            </div>
            <div class="mb-3">
                <label class="form-label">Reason</label>
                <textarea id="refund_reason" class="form-control" rows="3" 
                          placeholder="Enter refund reason..."></textarea>
            </div>
            <div class="mb-3">
                <label class="form-label">Refund Method</label>
                <select id="refund_method" class="form-control">
                    <option value="credit_card">Credit Card</option>
                    <option value="cash">Cash</option>
                    <option value="account_credit">Account Credit</option>
                    <option value="gift_card">Gift Card</option>
                    <option value="other">Other</option>
                </select>
            </div>
        `,
        showCancelButton: true,
        confirmButtonText: 'Process Refund',
        confirmButtonColor: '#dc3545',
        cancelButtonText: 'Cancel',
        preConfirm: () => {
            const amount = document.getElementById('refund_amount').value;
            const reason = document.getElementById('refund_reason').value;
            const method = document.getElementById('refund_method').value;

            if (!amount || amount <= 0) {
                Swal.showValidationMessage('Please enter a valid refund amount');
                return false;
            }
            if (!reason) {
                Swal.showValidationMessage('Please enter a refund reason');
                return false;
            }

            return { amount, reason, method };
        }
    }).then((result) => {
        if (result.isConfirmed) {
            fetch(`/admin/money/refund-single/${reservationId}`, {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content
                },
                body: JSON.stringify({
                    refund_amount: result.value.amount,
                    reason: result.value.reason,
                    method: result.value.method
                })
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    Swal.fire('Success!', data.message, 'success').then(() => {
                        location.reload();
                    });
                } else {
                    Swal.fire('Error!', data.message, 'error');
                }
            })
            .catch(error => {
                Swal.fire('Error!', 'Failed to process refund', 'error');
            });
        }
    });
}
</script>
@endsection
