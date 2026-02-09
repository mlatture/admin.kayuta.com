@extends('layouts.admin')

@section('title', 'Payment Listing')

@section('content')
<div class="container-fluid">
    <div class="d-flex justify-content-between align-items-center mb-4">
        <h1 class="h3 mb-0 text-gray-800">Payment Listing</h1>
    </div>

    <div class="card shadow mb-4">
        <div class="card-header py-3">
            <form action="{{ route('admin.payments.index') }}" method="GET" class="row g-2">
                <div class="col-md-4">
                    <input type="text" name="search" class="form-control" placeholder="Search by Code, Name or Email..." value="{{ request('search') }}">
                </div>
                <div class="col-md-2">
                    <button type="submit" class="btn btn-primary w-100">Search</button>
                </div>
            </form>
        </div>
        <div class="card-body">
            <div class="table-responsive">
                <table class="table table-bordered" width="100%" cellspacing="0">
                    <thead>
                        <tr>
                            <th>Confirmation Code</th>
                            <th>Customer</th>
                            <th>Stay Dates</th>
                            <th>Total Amount</th>
                            <th>Total Paid</th>
                            <th>Balance</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse($checkouts as $checkout)
                        <tr>
                            <td><code>{{ $checkout->group_confirmation_code }}</code></td>
                            <td>
                                {{ $checkout->fname }} {{ $checkout->lname }}<br>
                                <small class="text-muted">{{ $checkout->email }}</small>
                            </td>
                            <td>
                                {{ \Carbon\Carbon::parse($checkout->min_cid)->format('M d, Y') }} - 
                                {{ \Carbon\Carbon::parse($checkout->max_cod)->format('M d, Y') }}
                            </td>
                            <td>${{ number_format($checkout->grand_total, 2) }}</td>
                            <td>${{ number_format($checkout->net_paid, 2) }}</td>
                            <td>
                                <span class="badge {{ $checkout->balance > 0 ? 'bg-danger' : 'bg-success' }}">
                                    ${{ number_format($checkout->balance, 2) }}
                                </span>
                            </td>
                            <td>
                                <a href="{{ route('admin.orders.show', $checkout->group_confirmation_code) }}" class="btn btn-info btn-sm text-white">
                                    <i class="fas fa-eye"></i> View Details
                                </a>
                            </td>
                        </tr>
                        @empty
                        <tr>
                            <td colspan="7" class="text-center">No checkouts found.</td>
                        </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
            <div class="mt-3">
                {{ $checkouts->links() }}
            </div>
        </div>
    </div>
</div>
@endsection
