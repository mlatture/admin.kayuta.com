@extends('layouts.admin')

@section('title', 'Customer Details')
@section('content-header', 'Customer Details')

@section('content')
    <div class="container">
        <div class="card shadow">
            <div class="card-header d-flex align-items-center bg-primary text-white">
                <h3 class="mb-0">{{ $customer->f_name }} {{ $customer->l_name }}</h3>

                <div class="ms-auto">
                    <a href="{{ route('seasonal.customer.discounts.index', $customer->id) }}" class="btn btn-light ms-auto "
                        style="color:black !important"> 
                        <i class="fa-solid fa-chalkboard-user"></i> Process Seasonal Discounts
                    </a>
                    <a href="{{ route('admin.customers.account', $customer->id) }}" class="btn btn-light ms-auto "
                        style="color:black !important">
                        <i class="fas fa-user"></i> View Account
                    </a>
                    <a href="{{ route('customers.edit', $customer->id) }}" class="btn btn-light ms-auto "
                        style="color:black !important">
                        <i class="fas fa-edit"></i> Edit Customer
                    </a>
                </div>
            </div>

            <div class="card-body">
                <div class="row">
                    <div class="col-md-6">
                        <p><strong>Email:</strong> {{ $customer->email }}</p>
                        <p><strong>Phone:</strong> {{ $customer->phone }}</p>
                    </div>
                    <div class="col-md-6">
                        <p><strong>Address:</strong> {{ $customer->street_address }}</p>
                        <p><strong>Joined:</strong> {{ \Carbon\Carbon::parse($customer->created_at)->format('F j, Y') }}</p>
                    </div>
                </div>

                <hr>

                <h4 class="mt-4">Reservations</h4>
                <div class="table-responsive">
                    <table class="table table-bordered">
                        <thead class="table-secondary">
                            <tr>
                                <th class="text-center">Actions</th>
                                <th>ID</th>
                                <th>Site</th>
                                <th>Staying</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach ($customer->reservations->sortByDesc('date')->groupBy('cartid') as $cartid => $group)
                                @php $firstReservation = $group->first(); @endphp
                                <tr class="table-primary">
                                    <td class="text-center">
                                        <a href="{{ route('admin.unified-bookings.show', $firstReservation->group_confirmation_code ?? $firstReservation->xconfnum) }}" 
                                           class="btn btn-info btn-sm">
                                            ⓘ Details
                                        </a>
                                        {{-- Edit button already points to admin.reservations.show --}}
                                    </td>
                                    <td>Booking #{{ $firstReservation->cartid }}</td>
                                    <td>{{ $groupedReservations[$cartid] ?? 'N/A' }}</td>
                                    <td>{{ \Carbon\Carbon::parse($firstReservation->cid)->format('F j, Y') }} To
                                        {{ \Carbon\Carbon::parse($firstReservation->cod)->format('F j, Y') }}</td>
                                </tr>
                            @endforeach

                        </tbody>
                    </table>
                </div>

                <h4 class="mt-4">Cart Reservation</h4>
                <div class="table-responsive">
                    <table class="table table-bordered">
                        <thead class="table-secondary">
                            <tr>
                                <th class="text-center">Actions</th>
                                <th>ID</th>
                                <th>Site</th>
                                <th>Staying</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach ($groupedCartReservations as $cartid => $sites)
                                <tr>
                                    <td class="text-center">
                                        <a href="{{ route('admin.reservations.show', $cartid) }}" 
                                            class="btn btn-info btn-sm">
                                             <i class="fas fa-eye"></i> View
                                         </a>
                                     
                                    </td>
                                    <td>Booking #{{ $cartid }}</td>
                                    <td>{{ $sites }}</td>
                                    <td>
                                        @foreach ($customer->cart_reservations->where('cartid', $cartid)->take(1) as $reservation)
                                            {{ \Carbon\Carbon::parse($reservation->cid)->format('F j, Y') }} To
                                            {{ \Carbon\Carbon::parse($reservation->cod)->format('F j, Y') }}
                                        @endforeach
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>


                <hr>

                <h4 class="mt-4">Receipts</h4>
                <div class="table-responsive">
                    <table class="table table-bordered">
                        <thead class="table-secondary">
                            <tr>
                                <th class="text-center">Action</th>
                                <th>Amount</th>
                                <th>Date</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach ($customer->receipts->sortByDesc('date') as $receipt)
                                
                                <tr>
                                    <td class="text-center">
                                        <a class="btn btn-info btn-sm">
                                            ⓘ Details
                                        </a>
                                    </td>
                                    <td>
                                        @if($receipt->payments) 
                                            {{  number_format($receipt->payments->sum('payment'), 2) }}
                                        @else
                                            No Payment Found
                                        @endif
                                    </td>
                                    <td>{{ \Carbon\Carbon::parse($receipt->createdate)->format('F j, Y h:i A') }}</td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>

                <hr>

                <h4 class="mt-4">Cards on File</h4>
                <div class="table-responsive">
                    <table class="table table-bordered">
                        <thead class="table-secondary">
                            <tr>
                                <th>ID</th>
                                <th>Masked Card Number</th>
                                <th>Card Type</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach ($customer->cardsOnFile as $card)
                                <tr>
                                    <td>{{ $card->id }}</td>
                                    <td>{{ $card->xmaskedcardnumber }}</td>
                                    <td>{{ $card->method }}</td>

                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>

                <hr>

                <a href="{{ route('customers.index') }}" class="btn btn-secondary"><i class="fas fa-arrow-left"></i>
                    Back
                    to List</a>
            </div>
        </div>
    </div>


@endsection
@section('js')
    <script>
        $(document).ready(function() {
            $('.table').DataTable({
                responsive: true,
                dom: '<"dt-top-container"<"dt-left-in-div"f><"dt-center-in-div"l><"dt-right-in-div"B>>rt<ip>',
                buttons: [
                    'colvis',
                    'copy',
                    {
                        extend: 'csv',
                    },
                    {
                        extend: 'excel',
                    },
                    {
                        extend: 'pdf',
                    },

                    'print'
                ],
                language: {
                    search: 'Search: ',
                    lengthMenu: 'Show _MENU_ entries',
                },
                pageLength: 10
            });
        })
    </script>
@endsection
