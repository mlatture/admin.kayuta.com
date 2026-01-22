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

                        @foreach($draft->cart_data as $item)
                        <div class="item-card bg-light bg-opacity-25">
                            <div class="d-flex justify-content-between align-items-start">
                                <div>
                                    <h6 class="fw-bold mb-1">{{ $item['name'] }}</h6>
                                    <p class="text-muted small mb-0">
                                        <i class="far fa-calendar-alt me-1"></i> {{ $item['start_date'] }} — {{ $item['end_date'] }}
                                    </p>
                                </div>
                                <div class="text-end">
                                    <span class="badge bg-white text-dark border fw-bold px-3 py-2">
                                        ${{ number_format($item['base'], 2) }}
                                    </span>
                                </div>
                            </div>
                        </div>
                        @endforeach
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
                                <select name="payment_method" class="form-select form-select-lg" required>
                                    <option value="Account Credit">Modification Credit</option>
                                    <option value="Cash">Cash</option>
                                    <option value="Check">Check</option>
                                    <option value="Credit Card">Credit Card</option>
                                </select>
                            </div>
                            <div class="col-md-6 mb-3">
                                <label class="form-label small fw-bold text-muted">Reference / Note</label>
                                <input type="text" name="x_ref_num" class="form-control form-control-lg" placeholder="e.g. Check #101 or Transaction ID">
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <div class="col-lg-4">
                <div class="modification-card card sticky-top" style="top: 2rem;">
                    <div class="card-body p-4">
                        <h4 class="fw-bold mb-4">Summary</h4>
                        
                        <div class="price-row d-flex justify-content-between">
                            <span class="text-muted">Total for New Selections</span>
                            <span class="fw-bold">${{ number_format($draft->grand_total, 2) }}</span>
                        </div>

                        <div class="price-row d-flex justify-content-between text-success">
                            <span class="text-muted">Credit from Original Reservation</span>
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
