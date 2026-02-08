@extends('layouts.admin')

@section('title', 'Finalize Reservation Modification')

@push('css')
    <style>
        :root {
            --primary-gradient: linear-gradient(135deg, #2196f3 0%, #1565c0 100%);
            --glass-bg: rgba(255, 255, 255, 0.95);
        }
        .modification-card {
            border: none;
            border-radius: 15px;
            box-shadow: 0 10px 30px rgba(0,0,0,0.08);
            background: var(--glass-bg);
            backdrop-filter: blur(10px);
            overflow: hidden;
        }
        .header-section {
            background: var(--primary-gradient);
            color: white;
            padding: 2.5rem 2rem;
            margin-bottom: 2rem;
            border-radius: 15px;
            box-shadow: 0 4px 15px rgba(33, 150, 243, 0.3);
        }
        .status-badge {
            background: rgba(255,255,255,0.2);
            padding: 0.5rem 1rem;
            border-radius: 50px;
            font-size: 0.85rem;
            font-weight: 600;
        }
        .price-row {
            padding: 1rem 0;
            border-bottom: 1px solid #f0f0f0;
        }
        .price-row:last-child {
            border-bottom: none;
        }
        .summary-total {
            background: #f8f9fa;
            border-radius: 12px;
            padding: 1.5rem;
        }
        .difference-amount {
            font-size: 2rem;
            font-weight: 800;
            letter-spacing: -1px;
        }
        .btn-finalize {
            background: var(--primary-gradient);
            border: none;
            padding: 1.2rem;
            font-size: 1.1rem;
            font-weight: 700;
            transition: all 0.3s cubic-bezier(0.175, 0.885, 0.32, 1.275);
            border-radius: 12px;
        }
        .btn-finalize:hover {
            transform: translateY(-3px);
            box-shadow: 0 8px 25px rgba(33, 150, 243, 0.4);
        }
        .item-card {
            border: 1px solid #eee;
            border-radius: 10px;
            padding: 1rem;
            margin-bottom: 1rem;
            transition: border-color 0.3s;
        }
        .item-card:hover {
            border-color: #2196f3;
        }
    </style>
@endpush

@section('content')
<div class="container py-4">
    <div class="header-section d-flex justify-content-between align-items-center">
        <div>
            <h1 class="h2 mb-1 fw-bold">Review Modification</h1>
            <p class="mb-0 opacity-75">Update reservation #{{ $draft->original_cart_id ?? 'N/A' }} details and finalize</p>
        </div>
        <div class="status-badge">
            <i class="fas fa-sync-alt fa-spin me-1"></i> Modification in Progress
        </div>
    </div>

    <form action="{{ route('flow-reservation.finalize-modification', $draft->draft_id) }}" method="POST">
        @csrf
        <input type="hidden" name="is_modification" value="true">

        <div class="row g-4">
            <div class="col-lg-8">
                <div class="modification-card card mb-4">
                    <div class="card-body p-4">
                        <div class="d-flex align-items-center mb-4">
                            <div class="rounded-circle bg-primary bg-opacity-10 p-3 me-3">
                                <i class="fas fa-shopping-cart text-primary"></i>
                            </div>
                            <h4 class="mb-0 fw-bold">New Selection</h4>
                        </div>

                        @if(isset($summary['item_breakdown']['added_items']) && $summary['item_breakdown']['added_items']->count() > 0)
                        <div class="mb-4">
                            <h6 class="text-primary fw-bold mb-3"><i class="fas fa-plus-circle me-1"></i> Added Items (New Charges)</h6>
                            @foreach($summary['item_breakdown']['added_items'] as $item)
                                <div class="item-card bg-light bg-opacity-25 pb-2">
                                    <div class="d-flex justify-content-between align-items-start mb-2">
                                        <div>
                                            <h6 class="fw-bold mb-1">{{ $item['site'] }}</h6>
                                            <p class="text-muted small mb-0">
                                                <i class="far fa-calendar-alt me-1"></i> {{ $item['dates'] }}
                                            </p>
                                        </div>
                                        <div class="text-end">
                                            <div class="h6 fw-bold mb-0 text-primary">+ ${{ number_format($item['charge_amount'], 2) }}</div>
                                        </div>
                                    </div>
                                </div>
                            @endforeach
                        </div>
                        @endif

                        @if(isset($summary['item_breakdown']['cancelled_items']) && $summary['item_breakdown']['cancelled_items']->count() > 0)
                        <div class="mb-4">
                            <h6 class="text-danger fw-bold mb-3"><i class="fas fa-minus-circle me-1"></i> Cancelled Items (Refunds)</h6>
                            @foreach($summary['item_breakdown']['cancelled_items'] as $item)
                                <div class="item-card bg-danger bg-opacity-10 pb-2" style="border-color: #ffcccc;">
                                    <div class="d-flex justify-content-between align-items-start mb-2">
                                        <div>
                                            <h6 class="fw-bold mb-1">{{ $item['site'] }}</h6>
                                            <p class="text-muted small mb-0">
                                                <i class="far fa-calendar-alt me-1"></i> {{ $item['dates'] }}
                                            </p>
                                        </div>
                                        <div class="text-end">
                                            <div class="h6 fw-bold mb-0 text-danger">- ${{ number_format($item['refund_due'], 2) }}</div>
                                        </div>
                                    </div>
                                </div>
                            @endforeach
                        </div>
                        @endif

                        @if(isset($summary['item_breakdown']['unchanged_items']) && $summary['item_breakdown']['unchanged_items']->count() > 0)
                        <div class="mb-4">
                            <h6 class="text-muted fw-bold mb-3"><i class="fas fa-check-circle me-1"></i> Unchanged Items</h6>
                            @foreach($summary['item_breakdown']['unchanged_items'] as $item)
                                <div class="item-card bg-secondary bg-opacity-10 pb-2">
                                    <div class="d-flex justify-content-between align-items-start mb-2">
                                        <div>
                                            <h6 class="fw-bold mb-1">{{ $item['site'] }}</h6>
                                            <p class="text-muted small mb-0">
                                                <i class="far fa-calendar-alt me-1"></i> {{ $item['dates'] }}
                                            </p>
                                        </div>
                                        <div class="text-end">
                                            <div class="small text-muted mb-0">Original: ${{ number_format($item['original_paid'], 2) }}</div>
                                            <div class="h6 fw-bold mb-0 text-muted">$0.00 (Unchanged)</div>
                                        </div>
                                    </div>
                                </div>
                            @endforeach
                        </div>
                        @endif
                    </div>
                </div>

                <div class="modification-card card">
                    <div class="card-body p-4">
                        <div class="d-flex align-items-center mb-4">
                            <div class="rounded-circle bg-success bg-opacity-10 p-3 me-3">
                                <i class="fas fa-wallet text-success"></i>
                            </div>
                            <h4 class="mb-0 fw-bold">Payment Adjustments</h4>
                        </div>

                        <div class="row">
                            <div class="col-md-6 mb-3">
                                <label class="form-label small fw-bold text-muted">Payment Method for Balance</label>
                                <select name="payment_method" id="payment_method" class="form-select form-select-lg" required>
                                    <option value="Account Credit">Modification Credit</option>
                                    <option value="Cash">Cash</option>
                                    <option value="Check">Check</option>
                                    <option value="Credit Card">Credit Card</option>
                                    @if($primaryCustomer && $primaryCustomer->cardsOnFile->count() > 0)
                                        <option value="Card On File">Use Card On File</option>
                                    @endif
                                </select>
                            </div>
                            <div class="col-md-6 mb-3">
                                <label class="form-label small fw-bold text-muted">Reference / Note</label>
                                <input type="text" name="x_ref_num" class="form-control form-control-lg" placeholder="e.g. Check #101 or Transaction ID">
                            </div>
                        </div>

                        <div id="cc_fields" style="display: none;">
                            <hr class="my-4">
                            <div class="row">
                                <div class="col-md-6 mb-3">
                                    <label class="form-label small fw-bold text-muted">Card Number</label>
                                    <input type="text" name="xCardNum" class="form-control form-control-lg" placeholder="1234 5678 1234 5678">
                                </div>
                                <div class="col-md-3 mb-3">
                                    <label class="form-label small fw-bold text-muted">Expiration</label>
                                    <input type="text" name="xExp" class="form-control form-control-lg" placeholder="MM/YY">
                                </div>
                                <div class="col-md-3 mb-3">
                                    <label class="form-label small fw-bold text-muted">CVV</label>
                                    <input type="text" name="xCvv" class="form-control form-control-lg" placeholder="123">
                                </div>
                            </div>
                        </div>

                        <div id="cash_fields" style="display: none;">
                            <hr class="my-4">
                            <div class="row">
                                <div class="col-md-6 mb-3">
                                    <label class="form-label small fw-bold text-muted">Cash Tendered</label>
                                    <div class="input-group">
                                        <span class="input-group-text">$</span>
                                        <input type="number" step="0.01" name="cash_tendered" id="cash_tendered" class="form-control form-control-lg" placeholder="0.00">
                                    </div>
                                    <div class="form-text text-danger" id="cash_warning" style="display:none;">
                                        Insufficient cash tendered.
                                    </div>
                                </div>
                                <div class="col-md-6 mb-3">
                                    <label class="form-label small fw-bold text-muted">Change to Return</label>
                                    <div class="h3 fw-bold text-success mb-0" id="cash_change">$0.00</div>
                                </div>
                            </div>
                        </div>

                        <input type="hidden" name="amount" id="amount_input" value="{{ $draft->grand_total - $draft->credit_amount }}">

                        <script>
                            document.addEventListener('DOMContentLoaded', function() {
                                const methodSelect = document.getElementById('payment_method');
                                const ccFields = document.getElementById('cc_fields');
                                const cashFields = document.getElementById('cash_fields');
                                const cashTenderedInput = document.getElementById('cash_tendered');
                                const cashChangeDisplay = document.getElementById('cash_change');
                                const cashWarning = document.getElementById('cash_warning');
                                const deltaAmount = {{ $draft->grand_total - $draft->credit_amount }};
                                const form = document.querySelector('form');

                                function toggleFields() {
                                    const method = methodSelect.value;
                                    
                                    // CC Fields
                                    if (deltaAmount > 0 && method === 'Credit Card') {
                                        ccFields.style.display = 'block';
                                        ccFields.querySelectorAll('input').forEach(i => i.required = true);
                                    } else {
                                        ccFields.style.display = 'none';
                                        ccFields.querySelectorAll('input').forEach(i => i.required = false);
                                    }

                                    // Cash Fields
                                    if (deltaAmount > 0 && method === 'Cash') {
                                        cashFields.style.display = 'block';
                                        cashTenderedInput.required = true;
                                        if (!cashTenderedInput.value) {
                                            cashTenderedInput.value = deltaAmount.toFixed(2);
                                        }
                                        updateChange();
                                    } else {
                                        cashFields.style.display = 'none';
                                        cashTenderedInput.required = false;
                                    }
                                }

                                function updateChange() {
                                    const tendered = parseFloat(cashTenderedInput.value) || 0;
                                    const change = tendered - deltaAmount;
                                    cashChangeDisplay.textContent = '$' + Math.max(0, change).toFixed(2);
                                    
                                    if (tendered < deltaAmount) {
                                        cashWarning.style.display = 'block';
                                        cashTenderedInput.classList.add('is-invalid');
                                    } else {
                                        cashWarning.style.display = 'none';
                                        cashTenderedInput.classList.remove('is-invalid');
                                    }
                                }

                                methodSelect.addEventListener('change', toggleFields);
                                cashTenderedInput.addEventListener('input', updateChange);
                                
                                form.addEventListener('submit', function(e) {
                                    if (methodSelect.value === 'Cash') {
                                        const tendered = parseFloat(cashTenderedInput.value) || 0;
                                        if (tendered < deltaAmount) {
                                            e.preventDefault();
                                            toastr.error('Insufficient cash tendered.');
                                        }
                                    }
                                });

                                toggleFields(); // Init on load
                            });
                        </script>
                    </div>
                </div>
            </div>

            <div class="col-lg-4">
                <div class="modification-card card sticky-top" style="top: 2rem;">
                    <div class="card-body p-4">
                        <h4 class="fw-bold mb-4">Summary</h4>
                        
                        <div class="price-row d-flex justify-content-between text-danger">
                            <span class="text-muted">Total Refunds (Cancelled)</span>
                            <span class="fw-bold">-${{ number_format($summary['financial_summary']['total_refunds'], 2) }}</span>
                        </div>

                        <div class="price-row d-flex justify-content-between text-primary">
                            <span class="text-muted">Total New Charges</span>
                            <span class="fw-bold">+${{ number_format($summary['financial_summary']['total_new_charges'], 2) }}</span>
                        </div>
                        
                        <div class="price-row d-flex justify-content-between border-top">
                            <span class="text-muted">Grand Total Cart</span>
                            <span class="fw-bold">${{ number_format($draft->grand_total, 2) }}</span>
                        </div>

                        <div class="price-row d-flex justify-content-between text-success">
                            <span class="text-muted">Original Credit Applied</span>
                            <span class="fw-bold">-${{ number_format($draft->credit_amount, 2) }}</span>
                        </div>

                        @php $diff = $draft->grand_total - $draft->credit_amount; @endphp

                        <div class="summary-total text-center my-4">
                            <p class="small text-muted mb-1 text-uppercase fw-bold">Net Difference</p>
                            <div class="difference-amount {{ $diff < 0 ? 'text-success' : 'text-primary' }}">
                                {{ $diff < 0 ? '-$' . number_format(abs($diff), 2) : '$' . number_format($diff, 2) }}
                            </div>
                            <p class="small mt-2 mb-0 {{ $diff < 0 ? 'text-success' : 'text-primary' }} fw-bold">
                                {{ $diff < 0 ? 'Refund to Customer' : ($diff == 0 ? 'No balance change' : 'Payment Required') }}
                            </p>
                        </div>

                        <button type="submit" class="btn btn-primary w-100 btn-finalize shadow">
                            <i class="fas fa-check-circle me-2"></i> Confirm Modification
                        </button>

                        <div class="text-center mt-3">
                            <a href="{{ route('flow-reservation.step1', ['draft_id' => $draft->draft_id]) }}" class="text-decoration-none small text-muted">
                                <i class="fas fa-arrow-left me-1"></i> Cancel and go back
                            </a>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </form>
</div>
@endsection
