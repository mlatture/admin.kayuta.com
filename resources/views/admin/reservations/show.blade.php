@extends('layouts.admin')

@section('title', 'Reservation Details #' . $mainReservation->cartid)

@section('css')
<style>
    @media print {
        @page { size: auto;  margin: 0mm; }
        body { margin: 10mm; background-color: white !important; }
        .d-print-none, .sidebar, .main-header, .footer, .control-sidebar { display: none !important; }
        .content-wrapper { margin-left: 0 !important; background-color: white !important; }
        .card { box-shadow: none !important; border: 1px solid #ddd !important; }
        .badge { border: 1px solid #000; color: #000 !important; }
        .table { width: 100% !important; }
        a[href]:after { content: none !important; } 
    }
</style>
@endsection

@section('content')
<div class="container-fluid">
    <!-- Header Block -->
    <div class="d-flex justify-content-between align-items-center mb-4 d-print-none">
        <div>
            <h1 class="h3 mb-0 text-gray-800">
                Reservation #{{ $mainReservation->xconfnum ?? $mainReservation->cartid }}
                @if($mainReservation->status === 'Cancelled')
                    <span class="badge bg-danger ms-2">CANCELLED</span>
                @endif
            </h1>
            <p class="mb-0 text-muted">
                Created on {{ $mainReservation->created_at->format('M d, Y h:i A') }} 
                by {{ $mainReservation->createdby == 'Guest' ? 'Guest' : $mainReservation->createdby }}
            </p>
        </div>
        <div>
            {{-- Status Badge Removed --}}
             {{-- Balance Due Indicator --}}
             <span class="badge {{ $balanceDue > 0 ? 'bg-danger' : 'bg-success' }} fs-6">
                Balance Due: ${{ number_format($balanceDue, 2) }}
            </span>
        </div>
    </div>

    <!-- Print Header (Visible only on print) -->
    <div class="d-none d-print-block mb-4">
        <h1>Reservation #{{ $mainReservation->xconfnum ?? $mainReservation->cartid }}</h1>
        <p>
            Created on {{ $mainReservation->created_at->format('M d, Y h:i A') }} 
            by {{ $mainReservation->createdby == 'Guest' ? 'Guest' : $mainReservation->createdby }}
        </p>
    </div>

    <!-- Action Toolbar -->
    <div class="row mb-4 d-print-none">
        <div class="col-12">
            <div class="card shadow-sm border-left-primary">
                <div class="card-body py-2 d-flex justify-content-between align-items-center">
                    <div class="d-flex gap-2">
                        <!-- Print -->
                        <a href="{{ request()->fullUrlWithQuery(['print' => 1]) }}" onclick="window.print();" class="btn btn-secondary btn-sm">
                            <i class="fas fa-print me-1"></i> Print
                        </a>
                        
                        <!-- View Customer -->
                        @if(isset($user) && $user->id)
                        <a href="{{ route('customers.show', $user->id) }}" class="btn btn-info text-white btn-sm">
                            <i class="fas fa-user me-1"></i> Customer
                        </a>
                        @endif
                    </div>

                    <div class="d-flex gap-2">
                         @php
                            $activeStatuses = ['Paid', 'Confirmed', 'Pending'];
                            $now = \Carbon\Carbon::now();
                            $today = \Carbon\Carbon::today();

                            // Check-In Logic
                            // Valid if ANY reservation is (Active AND Arrival <= Today AND Not Checked In)
                            $canCheckIn = $reservations->contains(function($r) use ($activeStatuses, $today) {
                                return in_array($r->status, $activeStatuses) && 
                                       $today->gte(\Carbon\Carbon::parse($r->cid)) && 
                                       is_null($r->checkedin);
                            });

                             // Check-Out Logic
                            // Valid if ANY reservation is ((Checked In OR Active) AND Not Checked Out)
                            $canCheckOut = $reservations->contains(function($r) use ($activeStatuses) {
                                return (!is_null($r->checkedin) || in_array($r->status, $activeStatuses)) && 
                                       is_null($r->checkedout);
                            });

                            // Cancellation Logic
                            $cancellationSetting = \App\Models\BusinessSettings::where('type', 'cancellation')->first();
                            $cancellationData = json_decode($cancellationSetting->value ?? '{}', true);
                            $defaultFee = (float)($cancellationData['cancellation_fee'] ?? 0);
                        @endphp
                        
                        <!-- Check In -->
                        <form action="{{ route('admin.reservations.checkin', $mainReservation->cartid) }}" method="POST" style="display:inline;">
                            @csrf
                            <button type="submit" class="btn btn-success btn-sm" {{ !$canCheckIn ? 'disabled' : '' }} title="{{ !$canCheckIn ? 'Not eligible for Check-In' : 'Check In' }}">
                                <i class="fas fa-check-circle me-1"></i> Check In
                            </button>
                        </form>

                        <!-- Check Out -->
                        <form action="{{ route('admin.reservations.checkout', $mainReservation->cartid) }}" method="POST" style="display:inline;">
                            @csrf
                            <button type="submit" class="btn btn-danger btn-sm" {{ !$canCheckOut ? 'disabled' : '' }} title="{{ !$canCheckOut ? 'Not eligible for Check-Out' : 'Check Out' }}">
                                <i class="fas fa-sign-out-alt me-1"></i> Check Out
                            </button>
                        </form>

                        <div class="vr mx-2"></div>

                        <!-- Money Actions Dropdown -->
                        <div class="dropdown">
                            <button class="btn btn-warning btn-sm dropdown-toggle" type="button" id="moneyActionsDropdown" data-bs-toggle="dropdown" aria-expanded="false">
                                <i class="fas fa-hand-holding-usd me-1"></i> Money Actions
                            </button>
                            <ul class="dropdown-menu dropdown-menu-end shadow" aria-labelledby="moneyActionsDropdown">
                                <li><h6 class="dropdown-header">Billing & Refunds</h6></li>
                                <li><a class="dropdown-item" href="#" data-bs-toggle="modal" data-bs-target="#addChargeModal">
                                    <i class="fas fa-plus-circle me-2 text-primary"></i> Add Charge
                                </a></li>
                                <li><a class="dropdown-item" href="#" data-bs-toggle="modal" data-bs-target="#cancelReservationModal">
                                    <i class="fas fa-times-circle me-2 text-danger"></i> Cancel / Refund
                                </a></li>
                                <li><hr class="dropdown-divider"></li>
                                <li><h6 class="dropdown-header">Modification</h6></li>
                                <li>
                                    @if($mainReservation->status === 'Cancelled')
                                        <button class="dropdown-item disabled" type="button" title="Cannot modify cancelled reservation" style="cursor: not-allowed; opacity: 0.6;">
                                            <i class="fas fa-edit me-2 text-muted"></i> Modify Reservation
                                        </button>
                                    @else
                                        <a class="dropdown-item" href="{{ route('admin.reservations.modify', $mainReservation->cartid) }}">
                                            <i class="fas fa-edit me-2 text-primary"></i> Modify Reservation
                                        </a>
                                    @endif
                                </li>
                                <li><a class="dropdown-item disabled" href="#" data-bs-toggle="modal" data-bs-target="#moveSiteModal" title="Use Modify Flow">
                                    <i class="fas fa-exchange-alt me-2 text-muted"></i> Move Sites (Legacy)
                                </a></li>
                                <li><a class="dropdown-item disabled" href="#" data-bs-toggle="modal" data-bs-target="#changeDatesModal" title="Use Modify Flow">
                                    <i class="fas fa-calendar-alt me-2 text-muted"></i> Change Dates (Legacy)
                                </a></li>
                            </ul>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Customer & Reservation Info -->
    <div class="row">
        <div class="col-md-4 mb-4">
            <div class="card shadow h-100 py-2">
                <div class="card-body">
                    <h5 class="card-title text-primary fw-bold">Customer Information</h5>
                    <hr>
                    <p><strong>Name:</strong> {{ $user->f_name ?? $mainReservation->fname }} {{ $user->l_name ?? $mainReservation->lname }}</p>
                    <p><strong>Email:</strong> {{ $user->email ?? 'N/A' }}</p>
                    <p><strong>Phone:</strong> {{ $user->phone ?? 'N/A' }}</p>
                    <p><strong>Address:</strong> {{ $user->address ?? 'N/A' }}</p>
                </div>
            </div>
        </div>

        <div class="col-md-8 mb-4">
            <div class="card shadow h-100 py-2">
                <div class="card-body">
                    <h5 class="card-title text-primary fw-bold">Site Summary</h5>
                    <div class="table-responsive">
                        <table class="table table-bordered">
                            <thead class="table-light">
                                <tr>
                                    <th>Site ID</th>
                                    <th>Dates</th>
                                    <th>Type</th>
                                    <th>Rig</th>
                                    <th>Guests</th>
                                    <th>Pets</th>
                                </tr>
                            </thead>
                            <tbody>
                                @foreach($reservations as $res)
                                <tr>
                                    <td>
                                        <span class="badge bg-info text-dark">{{ $res->siteid }}</span>
                                    </td>
                                    <td>
                                        {{ \Carbon\Carbon::parse($res->cid)->format('M d') }} - 
                                        {{ \Carbon\Carbon::parse($res->cod)->format('M d, Y') }}
                                        <br>
                                        <small class="text-muted">({{ $res->nights }} nights)</small>
                                    </td>
                                    <td>{{ $res->siteclass }}</td>
                                    <td>{{ $res->riglength }}' {{ $res->rigtype }}</td>
                                    <td>{{ $res->adults ?? 0 }} Ad, {{ $res->children ?? 0 }} Ch</td>
                                    <td>{{ $res->pets ?? 0 }}</td>
                                </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>
    </div>

<!-- Financial Ledger -->
<div class="card shadow mb-4">
    <div class="card-header py-3 d-flex justify-content-between align-items-center">
        <h6 class="m-0 font-weight-bold text-primary">Financial Ledger</h6>
    </div>

    <div class="card-body p-0">
        <div class="table-responsive">
            <table class="table table-hover table-striped mb-0">
                <thead class="table-light">
                    <tr>
                        <th>Date / Time</th>
                        <th>Description</th>
                        <th>Type</th>
                        <th class="text-end">Transaction Amount</th>
                        <th class="text-end">Running Balance</th>
                        <th>Ref</th>
                    </tr>
                </thead>

                <tbody>
                    @foreach($ledger as $item)
                        @php
                            $rowClass = '';
                            if ($item['type'] === 'payment') $rowClass = 'text-success';
                            if ($item['type'] === 'refund')  $rowClass = 'text-danger';

                            $sign = ((float)$item['amount'] >= 0) ? '+' : '-';
                        @endphp

                        <tr style="cursor:pointer;"
                            onclick="showLedgerDetails(
                                '{{ $item['type'] }}',
                                '{{ addslashes($item['description']) }}',
                                {{ (float)$item['amount'] }},
                                '{{ $item['ref'] }}'
                            )">

                            <td>{{ \Carbon\Carbon::parse($item['date'])->format('M d g:i A') }}</td>

                            <td>{{ $item['description'] }}</td>

                            <td>
                                @if($item['type'] === 'charge')
                                    <span class="badge bg-secondary">Charge</span>
                                @elseif($item['type'] === 'payment')
                                    <span class="badge bg-success">Payment</span>
                                @elseif($item['type'] === 'refund')
                                    <span class="badge bg-danger">Refund</span>
                                @endif
                            </td>

                            <td class="text-end fw-bold {{ $rowClass }}">
                                {{ $sign }}${{ number_format(abs((float)$item['amount']), 2) }}
                            </td>

                            <td class="text-end fw-bold">
                                ${{ number_format((float)($item['running_balance'] ?? 0), 2) }}
                            </td>

                            <td>
                                <small class="text-muted">{{ $item['ref'] ?? '-' }}</small>
                            </td>
                        </tr>
                    @endforeach

                    {{-- Summary Rows --}}
                    <tr class="table-active border-top border-dark">
                        <td colspan="3" class="text-end fw-bold">Total Charges</td>
                        <td class="text-end fw-bold">+${{ number_format($totalCharges, 2) }}</td>
                        <td></td>
                        <td></td>
                    </tr>

                    <tr class="table-active">
                        <td colspan="3" class="text-end fw-bold">Total Payments</td>
                        <td class="text-end fw-bold text-success">-${{ number_format(abs($totalPayments), 2) }}</td>
                        <td></td>
                        <td></td>
                    </tr>

                    {{-- Ending balance row intentionally hidden (client request) --}}
                    {{--
                    <tr class="table-active">
                        <td colspan="3" class="text-end fw-bold">Ending Balance</td>
                        <td></td>
                        <td class="text-end fw-bold {{ $balanceDue > 0 ? 'text-danger' : 'text-success' }}">
                            ${{ number_format($balanceDue, 2) }}
                        </td>
                        <td></td>
                    </tr>
                    --}}
                </tbody>
            </table>
        </div>
    </div>
</div>






    <!-- System Logs -->
  <div class="card shadow mb-4">
    <div class="card-header py-3">
        <h6 class="m-0 font-weight-bold text-secondary">System Logs (Read-Only)</h6>
    </div>

    <div class="card-body">
        @if($logs->isEmpty())
            <p class="text-muted mb-0">No logs found for this reservation.</p>
        @else
            <div class="table-responsive" style="max-height: 300px; overflow-y: auto;">
                <table class="table table-sm table-hover mb-0" style="font-size: 0.85rem;">
                    <thead class="thead-light">
                        <tr>
                            <th style="white-space:nowrap;">Timestamp</th>
                            <th>Type</th>
                            <th>User</th>
                            <th>Data</th>
                        </tr>
                    </thead>

                    <tbody>
                        @foreach($logs as $log)
                            @php
                                // Human readable time (e.g., "2 minutes ago")
                                $humanTime = optional($log->created_at)->diffForHumans() ?? '-';

                                // Exact timestamp for tooltip
                                $exactTime = optional($log->created_at)->format('Y-m-d H:i:s') ?? '';

                                // User display
                                $userName = $log->user
                                    ? trim(($log->user->f_name ?? '') . ' ' . ($log->user->l_name ?? ''))
                                    : 'System';

                                if ($userName === '') $userName = 'User #' . ($log->user_id ?? '');
                            @endphp

                            <tr>
                                <td style="white-space:nowrap;" title="{{ $exactTime }}">
                                    {{ $humanTime }}
                                    <div class="text-muted" style="font-size:0.75rem;">
                                        {{ $exactTime }}
                                    </div>
                                </td>

                                <td>{{ $log->event_type ?? $log->transaction_type ?? '-' }}</td>

                                <td>
                                    {{ $userName }}
                                </td>

                                <td>
                                    <details>
                                        <summary>View</summary>

                                        <div class="mt-2">
                                            @if(!empty($log->comment))
                                                <div class="mb-2">
                                                    <strong>Comment:</strong>
                                                    <div class="text-muted">{{ $log->comment }}</div>
                                                </div>
                                            @endif

                                            <div class="mb-2">
                                                <strong>IP:</strong>
                                                <span class="text-muted">{{ $log->ip_address ?? '-' }}</span>
                                            </div>

                                            <div class="mb-2">
                                                <strong>Old Value</strong>
                                                <pre class="mt-1 bg-light p-2 rounded mb-2" style="white-space:pre-wrap;">{{ is_string($log->old_value) ? $log->old_value : json_encode($log->old_value, JSON_PRETTY_PRINT) }}</pre>

                                                <strong>New Value</strong>
                                                <pre class="mt-1 bg-light p-2 rounded mb-0" style="white-space:pre-wrap;">{{ is_string($log->new_value) ? $log->new_value : json_encode($log->new_value, JSON_PRETTY_PRINT) }}</pre>
                                            </div>

                                            {{-- Optional: if you have a JSON column like `after` / `before`, show it --}}
                                            @if(!empty($log->after))
                                                <div class="mt-2">
                                                    <strong>Raw</strong>
                                                    <pre class="mt-1 bg-light p-2 rounded mb-0" style="white-space:pre-wrap;">{{ json_encode(json_decode($log->after), JSON_PRETTY_PRINT) }}</pre>
                                                </div>
                                            @endif
                                        </div>
                                    </details>
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @endif
    </div>
</div>

    <!-- Refunds & Cancellations -->
    @if(!$refunds->isEmpty())
    <div class="card shadow mb-4 border-left-danger">
        <div class="card-header py-3">
            <h6 class="m-0 font-weight-bold text-danger">Payment Refunds & Cancellations</h6>
        </div>
        <div class="card-body">
            <table class="table table-sm">
                <thead>
                    <tr>
                        <th>Date</th>
                        <th>Method</th>
                        <th>Refund</th>
                        <th>Fee</th>
                        <th>Reason</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach($refunds as $refund)
                    <tr>
                        <td>{{ $refund->created_at->format('M d, Y') }}</td>
                        <td>{{ $refund->method }}</td>
                        <td class="text-danger">-${{ number_format($refund->amount, 2) }}</td>
                        <td class="text-secondary">${{ number_format($refund->cancellation_fee, 2) }}</td>
                        <td>{{ $refund->reason }}</td>
                    </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    </div>
    @endif


</div>
</div>

<!-- Modals -->

<!-- Add Charge Modal -->
<div class="modal fade" id="addChargeModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog">
        <form id="addChargeForm">
            @csrf
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title">Add Additional Charge</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body">
                    <div class="mb-3">
                        <label class="form-label">Payment Method</label>
                        <select name="method" id="addChargeMethod" class="form-select" required>
                            <option value="cash">Cash</option>
                            @if($mainReservation->cardOnFile)
                                <option value="credit_card_on_file" data-token="{{ $mainReservation->cardOnFile->xtoken }}">Card on File ({{ $mainReservation->cardOnFile->xmaskedcardnumber }})</option>
                            @endif
                            <option value="credit_card_new">New Credit Card (Requires Terminal/Manual)</option>
                            <option value="other">Other</option>
                        </select>
                        <input type="hidden" name="token" id="addChargeToken">
                    </div>
                    <div class="mb-3" id="addChargeRegisterGroup" style="display:none;">
                        <label class="form-label">Cash Register</label>
                        <select name="register_id" class="form-select">
                            <option value="">-- Select Register --</option>
                            @foreach($registers ?? [] as $reg)
                                <option value="{{ $reg->id }}">{{ $reg->name }}</option>
                            @endforeach
                        </select>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Amount ($)</label>
                        <input type="number" step="0.01" name="amount" class="form-control" required placeholder="0.00">
                    </div>
                    <div class="mb-3 form-check">
                        <input type="checkbox" class="form-check-input" id="addChargeTaxable" checked>
                        <label class="form-check-label" for="addChargeTaxable">Taxable (8.75%)</label>
                        <input type="hidden" name="tax" id="addChargeTaxAmount" value="0">
                        <input type="hidden" id="addChargeTaxRate" value="0.0875">
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Comment / Reason</label>
                        <textarea name="comment" class="form-control" required placeholder="Explain this charge (e.g. Cleaning Fee, Damages)..."></textarea>
                    </div>
                    <div class="alert alert-info py-2">
                        <strong>Preview:</strong> Total including tax: $<span id="addChargePreview">0.00</span>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-primary">Add Charge</button>
                </div>
            </div>
        </form>
    </div>
</div>

<!-- Cancel Reservation Modal -->
<div class="modal fade" id="cancelReservationModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-lg">
        <form id="cancelReservationForm">
            @csrf
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title">Cancel Reservation / Refund</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body">
                    <h6>Select Sites to Cancel:</h6>
                    <div class="table-responsive mb-3">
                        <table class="table table-sm table-bordered">
                            <thead>
                                <tr>
                                    <th><input type="checkbox" id="selectAllSites"></th>
                                    <th>Site</th>
                                    <th>Dates</th>
                                    <th>Total Paid</th>
                                </tr>
                            </thead>
                            <tbody>
                                @foreach($reservations as $res)
                                @if($res->status !== 'Cancelled')
                                <tr>
                                    <td><input type="checkbox" name="reservation_ids[]" value="{{ $res->id }}" class="site-checkbox" data-amount="{{ $res->total }}"></td>
                                    <td>{{ $res->siteid }}</td>
                                    <td>{{ \Carbon\Carbon::parse($res->cid)->format('M d') }} - {{ \Carbon\Carbon::parse($res->cod)->format('M d') }}</td>
                                    <td>${{ number_format($res->total, 2) }}</td>
                                </tr>
                                @endif
                                @endforeach
                            </tbody>
                        </table>
                    </div>

                    <div class="row">
                        <div class="col-md-6 mb-3">
                            <label class="form-label">Refund Method</label>
                            <select name="method" id="cancelMethod" class="form-select" required>
                                <option value="credit_card">Original Credit Card (Gateway Refund)</option>
                                <option value="cash">Cash / Manual</option>
                                <option value="account_credit">Account Credit</option>
                                <option value="gift_card">Gift Card</option>
                                <option value="other">Other / Waive</option>
                            </select>
                        </div>
                        <div class="col-md-6 mb-3" id="cancelRegisterGroup" style="display:none;">
                            <label class="form-label">Cash Register</label>
                            <select name="register_id" id="cancelRegisterSelect" class="form-select">
                                <option value="">-- Select Register --</option>
                                @foreach($registers ?? [] as $reg)
                                    <option value="{{ $reg->id }}">{{ $reg->name }}</option>
                                @endforeach
                            </select>
                        </div>
                        <div class="col-md-3 mb-3">
                            <label class="form-label">Pro-rated Paid ($)</label>
                            <input type="number" step="0.01" id="proRatedPaid" class="form-control" readonly value="0.00">
                            <small class="text-muted">Paid amount for these sites</small>
                        </div>
                        <div class="col-md-3 mb-3">
                            <label class="form-label">Refund Amount ($)</label>
                            <input type="number" step="0.01" name="refund_amount" id="finalRefundAmount" class="form-control" value="0.00">
                        </div>
                    </div>

                    <div class="row">
                        <div class="col-md-4 mb-3">
                            <label class="form-label">Cancellation Fee (%)</label>
                            <div class="input-group">
                                <input type="number" step="0.1" name="fee_percent" id="cancellationFeePercent" class="form-control" value="0.0">
                                <span class="input-group-text">%</span>
                            </div>
                            <input type="hidden" name="fee" id="cancellationFeeAmount" value="0">
                            <input type="hidden" id="defaultCancellationFeePercent" value="0">
                        </div>
                        <div class="col-md-8 mb-3" id="overrideReasonGroup" style="display: none;">
                            <label class="form-label text-danger">Fee Override Reason (Required)</label>
                            <input type="text" name="override_reason" id="overrideReasonInput" class="form-control" placeholder="Explain why fee was changed...">
                        </div>
                    </div>

                    <div class="mb-3">
                        <label class="form-label">Cancellation Reason</label>
                        <textarea name="reason" class="form-control" required placeholder="Passenger requested cancellation..."></textarea>
                    </div>

                    <div class="alert alert-warning py-2 mb-0">
                        <i class="fas fa-exclamation-triangle me-1"></i>
                        Refunds to Credit Card are <strong>final</strong> and processed via Cardknox.
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
                    <button type="submit" class="btn btn-danger">Confirm Cancellation</button>
                </div>
            </div>
        </form>
    </div>
</div>

<!-- Move Site Modal -->
<div class="modal fade" id="moveSiteModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog">
        <form id="moveSiteForm">
            @csrf
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title">Move Site</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body">
                    <div class="mb-3">
                        <label class="form-label">Select Site Segment</label>
                        <select name="reservation_id" id="moveSiteResSelect" class="form-select" required>
                            @foreach($reservations as $res)
                                <option value="{{ $res->id }}" data-site="{{ $res->siteid }}">Site {{ $res->siteid }} ({{ \Carbon\Carbon::parse($res->cid)->format('M d') }} - {{ \Carbon\Carbon::parse($res->cod)->format('M d') }})</option>
                            @endforeach
                        </select>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">New Site</label>
                        <select name="new_site_id" id="newSiteIdSelect" class="form-select" required>
                            <option value="">Loading sites...</option>
                        </select>
                        <small class="text-muted">Currently at: <span id="currentMoveSite">N/A</span></small>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Override Price ($) <small class="text-muted">(Optional)</small></label>
                        <input type="number" step="0.01" name="override_price" class="form-control" placeholder="Leave blank for auto-calc">
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Comment</label>
                        <textarea name="comment" class="form-control" required placeholder="Reason for move..."></textarea>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-info text-white">Move Site</button>
                </div>
            </div>
        </form>
    </div>
</div>

<!-- Payment Detail Modal -->
<div class="modal fade" id="paymentDetailModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">Payment/Credit Details</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body">
                <div id="paymentDetailPlaceholder">
                    <p class="text-center">Loading details...</p>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
            </div>
        </div>
    </div>
</div>

<!-- Change Dates Modal -->
<div class="modal fade" id="changeDatesModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog">
        <form id="changeDatesForm">
            @csrf
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title">Change Reservation Dates</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body">
                    <div class="mb-3">
                        <label class="form-label">Select Site Segment</label>
                        <select name="reservation_id" id="changeDatesResSelect" class="form-select" required>
                            @foreach($reservations as $res)
                                <option value="{{ $res->id }}" data-cid="{{ $res->cid->format('Y-m-d') }}" data-cod="{{ $res->cod->format('Y-m-d') }}">Site {{ $res->siteid }}</option>
                            @endforeach
                        </select>
                    </div>
                    <div class="row">
                        <div class="col-md-6 mb-3">
                            <label class="form-label">Arrival</label>
                            <input type="date" name="cid" id="changeCid" class="form-control" required>
                        </div>
                        <div class="col-md-6 mb-3">
                            <label class="form-label">Departure</label>
                            <input type="date" name="cod" id="changeCod" class="form-control" required>
                        </div>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Override Price ($) <small class="text-muted">(Optional)</small></label>
                        <input type="number" step="0.01" name="override_price" class="form-control" placeholder="Leave blank for auto-calc">
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Comment</label>
                        <textarea name="comment" class="form-control" required placeholder="Reason for date change..."></textarea>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-success">Save Changes</button>
                </div>
            </div>
        </form>
        <!-- Refunds (Moved to bottom) -->

</div>
</div>

@push('js')
<script>
$(function() {
    // Shared AJAX Handler
    const handleAction = (url, formData, modal) => {
        Swal.fire({
            title: 'Processing...',
            text: 'Please wait while we update financial records.',
            allowOutsideClick: false,
            didOpen: () => { Swal.showLoading(); }
        });

        $.ajax({
            url: url,
            method: 'POST',
            data: formData,
            success: (res) => {
                if (res.success) {
                    Swal.fire('Success!', res.message, 'success').then(() => {
                        window.location.reload();
                    });
                } else {
                    Swal.fire('Error', res.message, 'error');
                }
            },
            error: (err) => {
                Swal.fire('Error', err.responseJSON?.message || 'Something went wrong.', 'error');
            }
        });
    };

    }).trigger('change');

    // Ledger Details Modal
    window.showLedgerDetails = function(type, desc, amount, ref) {
        let content = `
            <table class="table table-bordered">
                <tr><th>Type</th><td>${type.toUpperCase()}</td></tr>
                <tr><th>Description</th><td>${desc}</td></tr>
                <tr><th>Amount</th><td>$${amount.toFixed(2)}</td></tr>
                <tr><th>Reference</th><td>${ref || 'N/A'}</td></tr>
            </table>
        `;
        $('#paymentDetailPlaceholder').html(content);
        new bootstrap.Modal(document.getElementById('paymentDetailModal')).show();
    };

    $('#cancelMethod').on('change', function() {
        if ($(this).val() === 'cash') {
            $('#cancelRegisterGroup').show().find('select').attr('required', true);
        } else {
            $('#cancelRegisterGroup').hide().find('select').attr('required', false);
        }
    }).trigger('change');

    $('#addChargeForm input[name="amount"], #addChargeTaxable').on('input change', function() {
        const amount = parseFloat($('#addChargeForm input[name="amount"]').val()) || 0;
        const isTaxable = $('#addChargeTaxable').is(':checked');
        const taxRate = parseFloat($('#addChargeTaxRate').val()) || 0;
        const taxAmount = isTaxable ? (amount * taxRate) : 0;
        const total = amount + taxAmount;
        
        $('#addChargeTaxAmount').val(taxAmount.toFixed(4));
        $('#addChargePreview').text(total.toFixed(2));
    });

    $('#addChargeForm').on('submit', function(e) {
        e.preventDefault();
        const id = '{{ $mainReservation->id }}';
        handleAction('{{ url("admin/money/charge") }}/' + id, $(this).serialize(), '#addChargeModal');
    });

    // Cancel / Refund Logic
    $('#selectAllSites').on('change', function() {
        $('.site-checkbox').prop('checked', $(this).is(':checked')).trigger('change');
    });

    $('.site-checkbox').on('change', function() {
        let selectedResTotal = 0;
        $('.site-checkbox:checked').each(function() {
            selectedResTotal += parseFloat($(this).data('amount')) || 0;
        });

        const totalPaid = {{ $totalPaid }};
        const cartTotal = {{ $cartTotal }};
        
        // Allocation rule: paid_alloc = payment_total * (reservation_total / group_total)
        let proRatedPaid = 0;
        if (cartTotal > 0) {
            proRatedPaid = totalPaid * (selectedResTotal / cartTotal);
        }

        $('#proRatedPaid').val(proRatedPaid.toFixed(2));
        updateRefundTotal();
    });

    $('#addChargeMethod').on('change', function() {
        const sel = $(this).find(':selected');
        $('#addChargeToken').val(sel.data('token') || '');
        if ($(this).val() === 'cash') {
            $('#addChargeRegisterGroup').show().find('select').attr('required', true);
        } else {
            $('#addChargeRegisterGroup').hide().find('select').attr('required', false);
        }
    }).trigger('change');

    // Ledger Details Modal
    window.showLedgerDetails = function(type, desc, amount, ref) {
        let title = 'Details';
        let colorClass = 'bg-primary';
        if(type === 'payment') { title = 'Payment Details'; colorClass = 'bg-success'; }
        if(type === 'refund') { title = 'Refund Details'; colorClass = 'bg-danger'; }
        
        // If ref is a string looking like json, parse it
        // We passed raw_obj as encoded json in the blade loop?
        // Actually the blade loop call was: showLedgerDetails(..., {{ $item['ref'] }})
        // Wait, I passed 'ref' as a string.
        // The modal details might need more info.
        
        let content = `
            <table class="table table-bordered">
                <tr><th>Type</th><td><span class="badge ${colorClass}">${type.toUpperCase()}</span></td></tr>
                <tr><th>Description</th><td>${desc}</td></tr>
                <tr><th>Amount</th><td>$${amount.toFixed(2)}</td></tr>
                <tr><th>Reference</th><td>${ref || 'N/A'}</td></tr>
            </table>
        `;
        $('#paymentDetailPlaceholder').html(content);
        new bootstrap.Modal(document.getElementById('paymentDetailModal')).show();
    };

    $('#cancellationFeePercent').on('input', function() {
        const currentPercent = parseFloat($(this).val()) || 0;
        const defaultPercent = parseFloat($('#defaultCancellationFeePercent').val()) || 0;
        
        if (Math.abs(currentPercent - defaultPercent) > 0.01) {
            $('#overrideReasonGroup').show();
            $('#overrideReasonInput').attr('required', true);
        } else {
            $('#overrideReasonGroup').hide();
            $('#overrideReasonInput').attr('required', false);
        }
        updateRefundTotal();
    });

    const updateRefundTotal = () => {
        const paid = parseFloat($('#proRatedPaid').val()) || 0;
        const percent = parseFloat($('#cancellationFeePercent').val()) || 0;
        const feeAmount = paid * (percent / 100);
        const refund = Math.max(0, paid - feeAmount);
        
        $('#cancellationFeeAmount').val(feeAmount.toFixed(2));
        $('#finalRefundAmount').val(refund.toFixed(2));
    };

    $('#cancelReservationForm').on('submit', function(e) {
        e.preventDefault();
        const cartId = '{{ $mainReservation->cartid }}';
        if ($('.site-checkbox:checked').length === 0) {
            return Swal.fire('Error', 'Please select at least one site to cancel.', 'error');
        }
        handleAction('{{ url("admin/money/cancel") }}/' + cartId, $(this).serialize(), '#cancelReservationModal');
    });

    // Move Site Initialize
    $('#moveSiteResSelect').on('change', function() {
        const id = $(this).val();
        $('#currentMoveSite').text($(this).find(':selected').data('site'));
        $('#newSiteIdSelect').html('<option value="">Loading sites...</option>');
        
        $.get('{{ url("admin/money/move-options") }}/' + id, function(res) {
            if (res.success) {
                let html = '<option value="">-- Select New Site --</option>';
                res.options.forEach(opt => {
                    html += `<option value="${opt.siteid}" data-diff="${opt.price_diff}">${opt.label}</option>`;
                });
                $('#newSiteIdSelect').html(html);
            } else {
                $('#newSiteIdSelect').html('<option value="">Failed to load sites</option>');
            }
        });
    }).trigger('change');

    $('#moveSiteForm').on('submit', function(e) {
        e.preventDefault();
        const resId = $('#moveSiteResSelect').val();
        handleAction('{{ url("admin/money/move") }}/' + resId, $(this).serialize(), '#moveSiteModal');
    });

    // Change Dates Initialize
    $('#changeDatesResSelect').on('change', function() {
        const sel = $(this).find(':selected');
        $('#changeCid').val(sel.data('cid'));
        $('#changeCod').val(sel.data('cod'));
    }).trigger('change');

    $('#changeDatesForm').on('submit', function(e) {
        e.preventDefault();
        const resId = $('#changeDatesResSelect').val();
        handleAction('{{ url("admin/money/change-dates") }}/' + resId, $(this).serialize(), '#changeDatesModal');
    });
});
</script>
@endpush
@endsection
