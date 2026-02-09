@extends('layouts.admin')

@section('title', 'Order Details #' . $mainRes->group_confirmation_code)

@section('css')
<style>
    .ledger-row-charge { border-left: 4px solid #4e73df; }
    .ledger-row-payment { border-left: 4px solid #1cc88a; }
    .ledger-row-refund { border-left: 4px solid #e74a3b; }
    @media print {
        .d-print-none { display: none !important; }
        .card { border: 1px solid #ddd !important; box-shadow: none !important; }
    }
</style>
@endsection

@section('content')
<div class="container-fluid">
    <div class="d-flex justify-content-between align-items-center mb-4 d-print-none">
        <div>
            <h1 class="h3 mb-0 text-gray-800">Order #{{ $mainRes->group_confirmation_code }}</h1>
            <p class="text-muted mb-0">Customer: {{ $mainRes->fname }} {{ $mainRes->lname }} ({{ $mainRes->email }})</p>
        </div>
        <div>
            <span class="badge {{ $balanceDue > 0 ? 'bg-danger' : 'bg-success' }} fs-5">
                Balance Due: ${{ number_format($balanceDue, 2) }}
            </span>
            <button onclick="window.print()" class="btn btn-secondary btn-sm ms-2"><i class="fas fa-print"></i> Print</button>
        </div>
    </div>

    <div class="row">
        <!-- Reservations List -->
        <div class="col-lg-8">
            <div class="card shadow mb-4">
                <div class="card-header py-3">
                    <h6 class="m-0 font-weight-bold text-primary">Reservations in this Order</h6>
                </div>
                <div class="card-body p-0">
                    <table class="table table-hover mb-0">
                        <thead class="bg-light">
                            <tr>
                                <th>Site</th>
                                <th>Dates</th>
                                <th>Status</th>
                                <th class="text-end">Total</th>
                                <th class="text-center">Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach($reservations as $res)
                            <tr>
                                <td>
                                    <strong>{{ $res->site->name ?? $res->siteid }}</strong><br>
                                    <small>{{ $res->siteclass }}</small>
                                </td>
                                <td>
                                    {{ $res->cid->format('M d, Y') }} - {{ $res->cod->format('M d, Y') }}<br>
                                    <small class="text-muted">{{ $res->nights }} nights</small>
                                </td>
                                <td>
                                    <span class="badge @if($res->status == 'Cancelled') bg-danger @elseif($res->status == 'Paid') bg-success @else bg-warning @endif">
                                        {{ $res->status }}
                                    </span>
                                </td>
                                <td class="text-end">${{ number_format($res->total, 2) }}</td>
                                <td class="text-center">
                                    <div class="btn-group">
                                        <a href="{{ route('admin.reservations.modify', $res->cartid) }}" class="btn btn-sm btn-outline-primary" title="Modify">
                                            <i class="fas fa-edit"></i> Modify
                                        </a>
                                        <button onclick="refundSingle({{ $res->id }}, '{{ $res->siteid }}', {{ $res->total }})" class="btn btn-sm btn-outline-danger" title="Refund" @if($res->status == 'Cancelled' && $res->total == 0) disabled @endif>
                                            <i class="fas fa-undo"></i> Refund
                                        </button>
                                    </div>
                                </td>
                            </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            </div>

            <!-- Ledger / Transaction History -->
            <div class="card shadow mb-4">
                <div class="card-header py-3">
                    <h6 class="m-0 font-weight-bold text-primary">Financial Ledger</h6>
                </div>
                <div class="card-body p-0">
                    <table class="table table-sm mb-0">
                        <thead class="bg-light">
                            <tr>
                                <th>Date</th>
                                <th>Description</th>
                                <th>Ref</th>
                                <th class="text-end">Amount</th>
                            </tr>
                        </thead>
                        <tbody>
                            @php $runningBalance = 0; @endphp
                            @foreach($ledger as $entry)
                            @php $runningBalance += $entry['amount']; @endphp
                            <tr class="ledger-row-{{ $entry['type'] }}">
                                <td>{{ \Carbon\Carbon::parse($entry['date'])->format('M d, Y H:i') }}</td>
                                <td>{{ $entry['description'] }}</td>
                                <td><small class="text-muted">{{ $entry['ref'] }}</small></td>
                                <td class="text-end {{ $entry['amount'] < 0 ? 'text-success' : 'text-danger' }}">
                                    {{ $entry['amount'] < 0 ? '-' : '' }}${{ number_format(abs($entry['amount']), 2) }}
                                </td>
                            </tr>
                            @endforeach
                        </tbody>
                        <tfoot class="bg-light">
                            <tr>
                                <th colspan="3" class="text-end">Current Balance Due:</th>
                                <th class="text-end">${{ number_format($runningBalance, 2) }}</th>
                            </tr>
                        </tfoot>
                    </table>
                </div>
            </div>
        </div>

        <!-- Sidebar Summary -->
        <div class="col-lg-4">
            <div class="card shadow mb-4">
                <div class="card-header py-3">
                    <h6 class="m-0 font-weight-bold text-primary">Financial Summary</h6>
                </div>
                <div class="card-body">
                    <div class="d-flex justify-content-between mb-2">
                        <span>Total Charges:</span>
                        <span class="font-weight-bold">${{ number_format($totalCharges, 2) }}</span>
                    </div>
                    <div class="d-flex justify-content-between mb-2">
                        <span>Total Paid:</span>
                        <span class="text-success">${{ number_format($totalPayments, 2) }}</span>
                    </div>
                    <div class="d-flex justify-content-between mb-2 border-bottom pb-2">
                        <span>Total Refunds:</span>
                        <span class="text-danger">-${{ number_format($totalRefunds, 2) }}</span>
                    </div>
                    <div class="d-flex justify-content-between mt-2 fs-5">
                        <span>Balance Due:</span>
                        <span class="font-weight-bold {{ $balanceDue > 0 ? 'text-danger' : 'text-success' }}">
                            ${{ number_format($balanceDue, 2) }}
                        </span>
                    </div>
                </div>
            </div>

            <div class="card shadow mb-4">
                <div class="card-header py-3">
                    <h6 class="m-0 font-weight-bold text-primary">Customer Information</h6>
                </div>
                <div class="card-body">
                    <p><strong>Name:</strong> {{ $mainRes->fname }} {{ $mainRes->lname }}</p>
                    <p><strong>Email:</strong> {{ $mainRes->email }}</p>
                    @if($mainRes->user)
                    <p><strong>Phone:</strong> {{ $mainRes->user->phone ?? 'N/A' }}</p>
                    <a href="{{ route('customers.show', $mainRes->user->id) }}" class="btn btn-sm btn-info text-white w-100">View Customer Profile</a>
                    @endif
                </div>
            </div>
        </div>
    </div>
</div>

@push('scripts')
<script>
function refundSingle(id, site, total) {
    Swal.fire({
        title: 'Refund Reservation',
        html: `
            <div class="text-start">
                <p>Refunding Reservation for Site: <strong>${site}</strong></p>
                <div class="mb-3">
                    <label class="form-label">Refund Amount ($)</label>
                    <input type="number" id="refund_amount" class="form-control" value="${total}" step="0.01" max="${total}">
                </div>
                <div class="mb-3">
                    <label class="form-label">Reason</label>
                    <input type="text" id="refund_reason" class="form-control" placeholder="e.g. Customer cancelled">
                </div>
                <div class="mb-3">
                    <label class="form-label">Method</label>
                    <select id="refund_method" class="form-select">
                        <option value="credit_card">Credit Card (Gateway)</option>
                        <option value="cash">Cash</option>
                        <option value="account_credit">Account Credit</option>
                        <option value="other">Other</option>
                    </select>
                </div>
            </div>
        `,
        showCancelButton: true,
        confirmButtonText: 'Process Refund',
        showLoaderOnConfirm: true,
        preConfirm: () => {
            const amount = document.getElementById('refund_amount').value;
            const reason = document.getElementById('refund_reason').value;
            const method = document.getElementById('refund_method').value;

            if (!amount || amount <= 0) {
                Swal.showValidationMessage('Please enter a valid amount');
                return false;
            }
            if (!reason) {
                Swal.showValidationMessage('Please enter a reason');
                return false;
            }

            return fetch(`{{ url('admin/money/refund-single') }}/${id}`, {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'X-CSRF-TOKEN': '{{ csrf_token() }}'
                },
                body: JSON.stringify({
                    refund_amount: amount,
                    reason: reason,
                    method: method
                })
            })
            .then(response => {
                if (!response.ok) {
                    return response.json().then(json => { throw new Error(json.message || 'Refund failed') });
                }
                return response.json();
            })
            .catch(error => {
                Swal.showValidationMessage(`Request failed: ${error}`);
            });
        },
        allowOutsideClick: () => !Swal.isLoading()
    }).then((result) => {
        if (result.isConfirmed) {
            Swal.fire('Success', 'Refund processed successfully', 'success').then(() => {
                location.reload();
            });
        }
    });
}
</script>
@endpush
@endsection
