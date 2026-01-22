@extends('layouts.admin')

@section('title', 'Modify Reservation – Step 2')

@push('css')
    <style>
        .cart-panel {
            position: sticky;
            top: 20px;
        }
        .cart-item {
            border-bottom: 1px solid #eee;
            padding: 10px 0;
        }
        .cart-item:last-child {
            border-bottom: none;
        }
        .modification-header {
            background: #e3f2fd;
            border-left: 5px solid #2196f3;
            padding: 15px;
            margin-bottom: 20px;
        }
        .price-summary {
            background: #f8f9fa;
            padding: 15px;
            border-radius: 8px;
        }
        .difference-highlight {
            font-size: 1.25rem;
            font-weight: bold;
        }
        .text-overpayment { color: #2e7d32; }
        .text-underpayment { color: #d32f2f; }
    </style>
@endpush

@section('content-header', 'Modify Reservation – Review & Finalize')

@section('content-actions')
    <button class="btn btn-outline-secondary me-2" onclick="window.location.href='{{ route('flow-reservation.step1', ['draft_id' => $draft->draft_id]) }}'">
        <i class="fas fa-arrow-left me-1"></i> Return to Step 1
    </button>
@endsection

@section('content')
<div class="container-fluid py-3">

    <div class="modification-header">
        <h5 class="mb-1"><i class="fas fa-edit me-2"></i> Modification Mode</h5>
        <p class="mb-0 small text-muted">Original Reservation: <strong>#{{ $draft->original_cart_id ?? 'N/A' }}</strong></p>
    </div>

    <form action="{{ route('flow-reservation.finalize-modification', $draft->draft_id) }}" method="POST" id="modificationFinalizeForm">
        @csrf
        <input type="hidden" name="is_modification" value="true">
        <input type="hidden" name="draft_id" value="{{ $draft->draft_id }}">
        <input type="hidden" name="customer_id" value="{{ $draft->customer_id }}">

        <div class="row">
            {{-- Left: Details --}}
            <div class="col-lg-8">
                <div class="card shadow-sm mb-4">
                    <div class="card-header bg-white">
                        <h6 class="mb-0">Customer Information</h6>
                    </div>
                    <div class="card-body">
                        <div class="row">
                            <div class="col-md-6">
                                <p class="mb-1 text-muted small">Name</p>
                                <p class="fw-bold">{{ $primaryCustomer->f_name ?? '' }} {{ $primaryCustomer->l_name ?? '' }}</p>
                            </div>
                            <div class="col-md-6">
                                <p class="mb-1 text-muted small">Email / Phone</p>
                                <p class="fw-bold">{{ $primaryCustomer->email ?? 'N/A' }} / {{ $primaryCustomer->phone ?? 'N/A' }}</p>
                            </div>
                        </div>
                    </div>
                </div>

                <div class="card shadow-sm mb-4">
                    <div class="card-header bg-white">
                        <h6 class="mb-0">New Reservation Selection</h6>
                    </div>
                    <div class="card-body">
                        @foreach($draft->cart_data as $index => $item)
                            <div class="cart-item">
                                <div class="d-flex justify-content-between align-items-center">
                                    <div>
                                        <strong>{{ $item['name'] }}</strong>
                                        <div class="small text-muted">
                                            {{ $item['start_date'] }} to {{ $item['end_date'] }}
                                        </div>
                                    </div>
                                    <div class="text-end">
                                        <div class="fw-bold">${{ number_format($item['base'], 2) }}</div>
                                        @if(($item['lock_fee_amount'] ?? 0) > 0)
                                            <div class="small text-info">+ Site Lock: ${{ number_format($item['lock_fee_amount'], 2) }}</div>
                                        @endif
                                    </div>
                                </div>
                            </div>
                        @endforeach
                    </div>
                </div>

                <div class="card shadow-sm mb-4">
                    <div class="card-header bg-white">
                        <h6 class="mb-0">Payment Method</h6>
                    </div>
                    <div class="card-body">
                        <div class="row">
                            <div class="col-md-6">
                                <label class="form-label">Select Method for Difference</label>
                                <select name="payment_method" class="form-select">
                                    <option value="Account Credit">Account Credit</option>
                                    <option value="Cash">Cash</option>
                                    <option value="Check">Check</option>
                                    <option value="Credit Card">Credit Card (Manual/Terminal)</option>
                                </select>
                            </div>
                            <div class="col-md-6">
                                <label class="form-label">Reference # (Optional)</label>
                                <input type="text" name="x_ref_num" class="form-control" placeholder="Transaction ref or check #">
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            {{-- Right: Financial Summary --}}
            <div class="col-lg-4">
                <div class="card shadow-sm price-summary">
                    <h5 class="mb-4">Modification Summary</h5>
                    
                    <div class="d-flex justify-content-between mb-2">
                        <span class="text-muted">New Selection Subtotal</span>
                        <span>${{ number_format($draft->subtotal, 2) }}</span>
                    </div>

                    @if(($draft->discount_total ?? 0) > 0)
                    <div class="d-flex justify-content-between mb-2 text-danger">
                        <span class="text-muted">New Discounts</span>
                        <span>-${{ number_format($draft->discount_total, 2) }}</span>
                    </div>
                    @endif

                    <div class="d-flex justify-content-between mb-3 fw-bold border-bottom pb-2">
                        <span>New Grand Total</span>
                        <span>${{ number_format($draft->grand_total, 2) }}</span>
                    </div>

                    <div class="d-flex justify-content-between mb-2 text-primary">
                        <span>Original Credit Applied</span>
                        <span class="fw-bold">-${{ number_format($draft->credit_amount, 2) }}</span>
                    </div>

                    <hr>

                    @php
                        $difference = $draft->grand_total - ($draft->credit_amount ?? 0);
                    @endphp

                    <div class="text-center py-3">
                        <p class="mb-1 text-muted small">Difference (Amount Due / Refund)</p>
                        <div class="difference-highlight {{ $difference > 0 ? 'text-underpayment' : 'text-overpayment' }}">
                            {{ $difference >= 0 ? '$' : '-$' }}{{ number_format(abs($difference), 2) }}
                        </div>
                        @if($difference < 0)
                            <div class="small text-success fw-bold mt-1">Full credit applied. Refund due.</div>
                        @elseif($difference == 0)
                            <div class="small text-success fw-bold mt-1">Even exchange. No payment needed.</div>
                        @else
                            <div class="small text-danger fw-bold mt-1">Additional payment required.</div>
                        @endif
                    </div>

                    <button type="submit" class="btn btn-primary w-100 py-3 mt-3 fw-bold shadow">
                        <i class="fas fa-check-circle me-2"></i> Confirm & Finalize Modification
                    </button>
                    
                    <p class="text-center mt-3 small text-muted">
                        This action will cancel the original reservation and create a new replacement.
                    </p>
                </div>
            </div>
        </div>
    </form>
</div>
@endsection
