@extends('layouts.admin')

@section('title', 'Modify Reservation')

@section('content')
<div class="content container-fluid">
    <!-- Page Header -->
    <div class="page-header mb-4">
        <div class="row align-items-center">
            <div class="col-sm mb-2 mb-sm-0">
                <nav aria-label="breadcrumb">
                    <ol class="breadcrumb breadcrumb-no-gutter">
                        <li class="breadcrumb-item"><a class="breadcrumb-link" href="{{ route('admin.unified-bookings.index') }}">Unified Bookings</a></li>
                        <li class="breadcrumb-item">
                            <a class="breadcrumb-link" href="{{ route('admin.unified-bookings.show', $reservation->group_confirmation_code) }}">
                                Booking #{{ $reservation->group_confirmation_code }}
                            </a>
                        </li>
                        <li class="breadcrumb-item active" aria-current="page">Modify Reservation</li>
                    </ol>
                </nav>
                <h1 class="page-header-title">
                    <i class="tio-edit me-2"></i> Modify Reservation Dates
                </h1>
            </div>
        </div>
    </div>

    <div class="row">
        <div class="col-lg-8">
            <!-- Site & Reservation Info -->
            <div class="card mb-4 shadow-sm">
                <div class="card-header bg-white">
                    <h5 class="card-header-title mb-0">
                        <i class="tio-camping-tent me-2"></i> Site & Reservation Details
                    </h5>
                </div>
                <div class="card-body">
                    <div class="row">
                        <div class="col-md-6 border-end">
                            <h6 class="text-cap mb-3">Site Information</h6>
                            <div class="d-flex align-items-center mb-2">
                                <span class="text-muted me-2">Site Name:</span>
                                <span class="fw-bold">{{ $reservation->site->sitename ?? $reservation->siteid }}</span>
                            </div>
                            <div class="d-flex align-items-center mb-2">
                                <span class="text-muted me-2">Class:</span>
                                <span class="badge bg-soft-info text-info">{{ $reservation->site->siteclass ?? 'N/A' }}</span>
                            </div>
                            <div class="d-flex align-items-center">
                                <span class="text-muted me-2">Rate Tier:</span>
                                <span class="badge bg-soft-primary text-primary">{{ $reservation->site->ratetier ?? 'N/A' }}</span>
                            </div>
                        </div>
                        <div class="col-md-6 ps-md-4">
                            <h6 class="text-cap mb-3">Customer Information</h6>
                            <div class="d-flex align-items-center mb-2">
                                <span class="text-muted me-2">Name:</span>
                                <span class="fw-bold">{{ $reservation->fname }} {{ $reservation->lname }}</span>
                            </div>
                            <div class="d-flex align-items-center mb-2">
                                <span class="text-muted me-2">Email:</span>
                                <span>{{ $reservation->email }}</span>
                            </div>
                            <div class="d-flex align-items-center">
                                <span class="text-muted me-2">Confirmation:</span>
                                <span class="text-primary fw-semibold">{{ $reservation->confirmation_code ?? $reservation->xconfnum }}</span>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Date Modification -->
            <div class="card shadow-sm">
                <div class="card-header bg-white">
                    <h5 class="card-header-title mb-0">
                        <i class="tio-date-range me-2"></i> Update Dates
                    </h5>
                </div>
                <div class="card-body">
                    <form action="{{ route('admin.reservations.updateReservationDates', $reservation->id) }}" method="POST" id="modifyForm">
                        @csrf
                        <div class="row">
                            <div class="col-md-6 mb-3">
                                <label class="form-label">Check-In Date</label>
                                <div class="input-group input-group-merge">
                                    <div class="input-group-prepend">
                                        <span class="input-group-text"><i class="tio-date-range"></i></span>
                                    </div>
                                    <input type="date" name="cid" id="cid" class="form-control" 
                                           value="{{ \Carbon\Carbon::parse($reservation->cid)->format('Y-m-d') }}" required>
                                </div>
                            </div>
                            <div class="col-md-6 mb-3">
                                <label class="form-label">Check-Out Date</label>
                                <div class="input-group input-group-merge">
                                    <div class="input-group-prepend">
                                        <span class="input-group-text"><i class="tio-date-range"></i></span>
                                    </div>
                                    <input type="date" name="cod" id="cod" class="form-control" 
                                           value="{{ \Carbon\Carbon::parse($reservation->cod)->format('Y-m-d') }}" required>
                                </div>
                            </div>
                        </div>

                        <div class="alert alert-soft-info d-flex align-items-center mb-0" role="alert">
                            <i class="tio-info-outined me-2"></i>
                            <div>
                                Extend or reduce the stay. The total price will update automatically on the right.
                            </div>
                        </div>

                        <hr class="my-4">

                        <div class="d-flex justify-content-end">
                            <a href="{{ route('admin.unified-bookings.show', $reservation->group_confirmation_code) }}" class="btn btn-white me-2">Cancel</a>
                            <button type="submit" class="btn btn-primary" id="saveBtn">Save Changes</button>
                        </div>
                    </form>
                </div>
            </div>
        </div>

        <!-- Price Summary Sidebar -->
        <div class="col-lg-4">
            <div class="card shadow-sm sticky-top" style="top: 1rem;">
                <div class="card-header bg-primary text-white">
                    <h5 class="card-header-title mb-0 text-white">
                        <i class="tio-dollar me-2"></i> Price Adjustment
                    </h5>
                </div>
                <div class="card-body">
                    <div class="mb-3 pb-3 border-bottom">
                        <div class="d-flex justify-content-between mb-2">
                            <span class="text-muted">Original Total:</span>
                            <span class="fw-semibold">${{ number_format($reservation->total, 2) }}</span>
                        </div>
                        <div class="d-flex justify-content-between mb-2">
                            <span class="text-muted">Original Nights:</span>
                            <span>{{ $reservation->nights }} nights</span>
                        </div>
                    </div>

                    <div id="price-update-spinner" class="text-center py-4 d-none">
                        <div class="spinner-border text-primary" role="status">
                            <span class="visually-hidden">Loading...</span>
                        </div>
                        <p class="text-muted small mt-2">Calculating new price...</p>
                    </div>

                    <div id="new-price-container">
                        <div class="d-flex justify-content-between mb-2">
                            <span class="text-muted">New Total:</span>
                            <span class="fw-bold h4 mb-0" id="new-total">${{ number_format($reservation->total, 2) }}</span>
                        </div>
                        <div class="d-flex justify-content-between mb-3">
                            <span class="text-muted">New Nights:</span>
                            <span id="new-nights">{{ $reservation->nights }} nights</span>
                        </div>

                        <div class="p-3 rounded-pill bg-light d-flex justify-content-between align-items-center" id="diff-container">
                            <span class="small text-muted">Difference:</span>
                            <span class="fw-bold" id="price-diff">$0.00</span>
                        </div>
                    </div>

                    <div id="date-error" class="alert alert-soft-danger small mt-3 d-none">
                        <i class="tio-warning-outlined me-1"></i>
                        <span id="error-message"></span>
                    </div>
                </div>
                <div class="card-footer bg-light border-0">
                    <p class="small text-muted mb-0">
                        <i class="tio-info-outined me-1"></i> Changes will be logged for financial audit.
                    </p>
                </div>
            </div>
        </div>
    </div>
</div>

@endsection

@section('javascript')
<script>
$(document).ready(function() {
    const cidInput = $('#cid');
    const codInput = $('#cod');
    const saveBtn = $('#saveBtn');
    const spinner = $('#price-update-spinner');
    const container = $('#new-price-container');
    const errorDiv = $('#date-error');
    const errorMsg = $('#error-message');

    function calculatePrice() {
        const cid = cidInput.val();
        const cod = codInput.val();

        if (!cid || !cod) return;

        spinner.removeClass('d-none');
        container.addClass('opacity-50');
        errorDiv.addClass('d-none');
        saveBtn.prop('disabled', true);

        $.ajax({
            url: "{{ route('admin.reservations.calculatePrice', $reservation->id) }}",
            method: "GET",
            data: { cid, cod },
            success: function(data) {
                $('#new-total').text('$' + data.new_total.toFixed(2));
                $('#new-nights').text(data.nights + ' nights');
                
                const diff = data.difference;
                const diffEl = $('#price-diff');
                const diffCont = $('#diff-container');

                if (diff > 0) {
                    diffEl.text('+$' + diff.toFixed(2)).removeClass('text-success text-muted').addClass('text-danger');
                    diffCont.removeClass('bg-light bg-soft-success').addClass('bg-soft-danger');
                } else if (diff < 0) {
                    diffEl.text('-$' + Math.abs(diff).toFixed(2)).removeClass('text-danger text-muted').addClass('text-success');
                    diffCont.removeClass('bg-light bg-soft-danger').addClass('bg-soft-success');
                } else {
                    diffEl.text('$0.00').removeClass('text-danger text-success').addClass('text-muted');
                    diffCont.removeClass('bg-soft-danger bg-soft-success').addClass('bg-light');
                }

                saveBtn.prop('disabled', false);
            },
            error: function(xhr) {
                errorMsg.text(xhr.responseJSON?.error || 'Invalid date range');
                errorDiv.removeClass('d-none');
            },
            complete: function() {
                spinner.addClass('d-none');
                container.removeClass('opacity-50');
            }
        });
    }

    cidInput.on('change', calculatePrice);
    codInput.on('change', calculatePrice);
});
</script>
@endsection
