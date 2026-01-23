@extends('layouts.admin')

@section('title', 'Reservation Draft – Search & Cart (Step 1)')

@push('css')
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/flatpickr/dist/flatpickr.min.css">
    <style>
        .cart-panel {
            position: sticky;
            top: 20px;
            max-height: calc(100vh - 40px);
            display: flex;
            flex-direction: column;
        }

        .cart-panel .card-body {
            overflow-y: auto;
            flex: 1;
        }

        .cart-item {
            border-bottom: 1px solid #eee;
            padding: 10px 0;
        }

        .cart-item:last-child {
            border-bottom: none;
        }

        .add-on-selector {
            font-size: 0.85rem;
        }

        .total-row {
            font-weight: bold;
            font-size: 1.1rem;
        }

        .platform-fee-info {
            font-size: 0.75rem;
            color: #666;
            font-style: italic;
        }

        .occupants-input {
            width: 60px;
            display: inline-block;
        }
    </style>
@endpush

@section('content')
    <div class="container-fluid py-3">
        <div class="d-flex align-items-center justify-content-between mb-4">
            <h1 class="h3 mb-0">Reservation Draft – Search & Cart (Step 1)</h1>
        </div>

        {{-- Top Section: Search Criteria --}}
        <div class="card shadow-sm mb-4">
            <div class="card-body">
                <form id="searchForm" class="row g-3 align-items-end">
                    <div class="col-md-4">
                        <label class="form-label">Dates</label>
                        <input type="text" class="form-control" id="dateRange" placeholder="Select Dates" required>
                        <input type="hidden" name="start_date" id="startDate">
                        <input type="hidden" name="end_date" id="endDate">
                    </div>
                    <div class="col-md-2">
                        <label class="form-label">Site Class</label>
                        <select class="form-select" name="siteclass">
                            <option value="">Any</option>
                            @foreach ($siteClasses as $class)
                                <option value="{{ $class->siteclass }}">{{ $class->siteclass }}</option>
                            @endforeach
                        </select>
                    </div>
                    <div class="col-md-2">
                        <label class="form-label">Hookup</label>
                        <select class="form-select" name="hookup">
                            <option value="">Any</option>
                            @foreach ($siteHookups as $hookup)
                                <option value="{{ $hookup->sitehookup }}">{{ $hookup->sitehookup }}</option>
                            @endforeach
                        </select>
                    </div>
                    <div class="col-md-4">
                        <label class="form-label">Rig Length (ft)</label>
                        <input type="number" class="form-control" name="rig_length" placeholder="e.g. 30">
                    </div>
                </form>
            </div>
        </div>

        <div class="row">
            {{-- Left 2/3: Search Results --}}
            <div class="col-lg-8">
                <div class="card shadow-sm h-100">
                    <div class="card-header bg-white">
                        <h5 class="mb-0">Available Sites</h5>
                    </div>
                    <div class="card-body p-0">
                        <div class="px-3 py-2 bg-light border-bottom">
                            <span class="text-muted small"><i class="fas fa-info-circle me-1"></i> Tip: Click any site row to view more details and amenities.</span>
                        </div>
                        <div class="table-responsive">
                            <table class="table table-hover mb-0" id="resultsTable">
                                <thead class="table-light">
                                    <tr>
                                        <th>Site</th>
                                        <th>Hookup</th>
                                        <th>Rig Length</th>
                                        <th>Occupants / Site Lock Fee</th>
                                        <th class="text-end">Actions</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <tr>
                                        <td colspan="5" class="text-center py-5 text-muted">Enter search criteria above
                                            to see available sites.</td>
                                    </tr>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>
            </div>

            {{-- Right 1/3: Cart Panel --}}
            <div class="col-lg-4">


                <div class="card shadow-sm cart-panel">
                    <div class="card-header bg-white d-flex justify-content-between align-items-center">
                        <h5 class="mb-0">Your Cart</h5>
                        <span class="badge bg-primary" id="cartCount">0</span>
                    </div>
                    <div class="card-body">


                        <div id="cartItems">
                            <p class="text-muted text-center py-3" id="emptyCartMsg">
                                Cart is empty.
                            </p>
                            <div id="auto-fetch-loader" class="d-none text-muted" style="font-size:20px">
                                <i class="fa fa-spinner fa-spin"></i> Updating cart…
                            </div>
                        </div>

                        <hr>

                        {{-- Discounts & Coupons --}}
                        <div class="mb-3">
                            <label class="form-label small fw-bold">Coupon Code</label>
                            <div class="input-group input-group-sm">
                                <input type="text" class="form-control" id="couponCode" placeholder="Enter code">
                                <button class="btn btn-outline-secondary" type="button" id="applyCoupon">Apply</button>
                            </div>
                        </div>
                             <label class="form-label small fw-bold">Instant Discount ($)</label>
                            <input type="number" class="form-control form-control-sm" id="instantDiscount" step="0.01"
                                value="{{ $draft ? number_format($draft->discount_total, 2, '.', '') : '0.00' }}">
                        </div>
                        <div class="mb-3" id="discountReasonContainer" style="display: {{ ($draft && $draft->discount_total > 0) ? 'block' : 'none' }};">
                            <label class="form-label small fw-bold">Discount Reason</label>
                            <input type="text" class="form-control form-control-sm" id="discountReason" placeholder="Reason for discount..."
                                value="{{ $draft ? $draft->discount_reason : '' }}">
                        </div>

                        <hr>

                        {{-- Totals --}}
                        <div class="d-flex justify-content-between mb-2">
                            <span>Subtotal</span>
                            <span id="subtotalDisplay">$0.00</span>
                        </div>
                        <div class="d-flex justify-content-between mb-2 text-danger">
                            <span>Discounts</span>
                            <span id="discountsDisplay">-$0.00</span>
                        </div>
                        {{-- <div class="d-flex justify-content-between mb-2">
                            <span>Estimated Tax</span>
                            <span id="taxDisplay">$0.00</span>
                        </div> --}}
                        @if($draft && $draft->is_modification)
                        <div class="d-flex justify-content-between mb-2 text-success">
                            <span><i class="fas fa-minus-circle me-1"></i> Immediate Refund</span>
                            <span id="immediateRefundDisplay">$0.00</span>
                        </div>
                        <div class="d-flex justify-content-between mb-2 text-primary">
                            <span><i class="fas fa-plus-circle me-1"></i> New Charges</span>
                            <span id="newChargesDisplay">$0.00</span>
                        </div>
                        <hr>
                        @endif
                        @if(($draft->credit_amount ?? 0) > 0)
                        <div class="d-flex justify-content-between mb-2 text-primary">
                            <span>Modification Credit (Total Paid)</span>
                            <span id="creditDisplay">-${{ number_format($draft->credit_amount, 2) }}</span>
                        </div>
                        @endif
                        <div class="d-flex justify-content-between mb-2 total-row">
                            <span>Grand Total</span>
                            <span id="grandTotalDisplay">$0.00</span>
                        </div>
                        <div class="platform-fee-info mt-2">
                            * Pricing includes a platform fee per site.
                        </div>
                    </div>
                    <div class="card-footer bg-white border-top-0">
                        <button class="btn btn-success w-100 py-2" id="nextBtn" disabled>
                            Next <i class="fas fa-arrow-right ms-1"></i>
                        </button>
                    </div>
                </div>
            </div>
        </div>
    </div>

    @include('reservations.modals.site-details')
@endsection

@push('js')
    <script src="https://cdn.jsdelivr.net/npm/flatpickr"></script>
    <script>
        $(function() {
            let cart = @json($draft ? $draft->cart_data : []);
            const originalReservations = @json($originalReservations ?? []);
            // Platform fee removed to match manage/reservation logic
            // let platformFee = 0; 
            // Tax calculation removed as per user request
            
            // Perform search function (Debounced for text input)
            function performSearch() {
                const startDate = $('#startDate').val();
                const endDate = $('#endDate').val();
                
                if (!startDate || !endDate) return;

                const tbody = $('#resultsTable tbody');

                $.get("{{ route('flow-reservation.search') }}", $('#searchForm').serialize())
                    .done(function(res) {
                        const siteLockFee = parseFloat(res.site_lock_fee) || 0;
                        const units = res.data.response.results.units;
                        tbody.empty();

                        if (units.length === 0) {
                            tbody.append(
                                '<tr><td colspan="7" class="text-center py-5">No available sites found for these criteria.</td></tr>'
                            );
                        } else {
                            // Calculate nights
                            const s = new Date(startDate);
                            const e = new Date(endDate);
                            const diffTime = Math.abs(e - s);
                            const nights = Math.ceil(diffTime / (1000 * 60 * 60 * 24)) || 1;

                            units.forEach(unit => {
                                const basePrice = parseFloat(unit.price_quote.total);
                                const avgNight = parseFloat(unit.price_quote.avg_nightly || 0);
                                const total = basePrice + siteLockFee; // Initial view assumes site lock checked
                                
                                tbody.append(`
                                    <tr class="view-site-details" data-id="${unit.site_id}" style="cursor: pointer;">
                                        <td>
                                            <strong>${unit.name}</strong><br>
                                            <div class="badge bg-success small">Available</div>
                                            <div class="mt-2 small text-muted">
                                                <i class="far fa-calendar-alt me-1"></i> ${startDate} to ${endDate}<br>
                                                <i class="fas fa-moon me-1"></i> ${nights} nights
                                            </div>
                                        </td>
                                        <td>${unit.hookup || 'N/A'}</td>
                                        <td>${unit.minlength || '0'} - ${unit.maxlength || 'N/A'}</td>
                                        <td>
                                            <div class="d-flex gap-2 mb-2">
                                                <div>
                                                    <label class="small text-muted d-block text-center">Adults</label>
                                                    <input type="number" class="form-control form-control-sm occupants-input adults" value="2" min="1" style="width: 60px;">
                                                </div>
                                                <div>
                                                    <label class="small text-muted d-block text-center">Children</label>
                                                    <input type="number" class="form-control form-control-sm occupants-input children" value="0" min="0" style="width: 60px;">
                                                </div>
                                            </div>
                                            <div class="form-check small mt-2">
                                                <input class="form-check-input site-lock-toggle" type="checkbox" id="lock_${unit.site_id}" checked data-fee="${siteLockFee}">
                                                <label class="form-check-label text-muted" for="lock_${unit.site_id}">
                                                    Site Lock Fee: $<span class="fee-display-label">${siteLockFee.toFixed(2)}</span>
                                                </label>
                                            </div>
                                        </td>
                                        <td class="text-end">
                                            <div class="small mb-2">
                                                <div class="d-flex justify-content-between">
                                                    <span class="fw-bold">Sub Total:</span>
                                                    <span>$${basePrice.toFixed(2)}</span>
                                                </div>
                                                <div class="d-flex justify-content-between">
                                                    <span class="fw-bold">Avg/Night:</span>
                                                    <span>$${avgNight.toFixed(2)}</span>
                                                </div>
                                                <div class="d-flex justify-content-between">
                                                    <span class="fw-bold">Extras:</span>
                                                    <span>$<span class="extras-display-val">${siteLockFee.toFixed(2)}</span></span>
                                                </div>
                                                <div class="d-flex justify-content-between border-top pt-1 mt-1 fs-6">
                                                    <span class="fw-bold">Total:</span>
                                                    <span class="fw-bold">$<span class="total-display-val">${total.toFixed(2)}</span></span>
                                                </div>
                                            </div>
                                            <button class="btn btn-primary btn-sm w-100 add-to-cart" 
                                                data-id="${unit.site_id}" 
                                                data-name="${unit.name}" 
                                                data-base="${basePrice}"
                                                data-fee="0"
                                                data-start="${startDate}"
                                                data-end="${endDate}">
                                                Add to Cart
                                            </button>
                                        </td>
                                    </tr>
                                `);
                            });
                        }
                    })
                    .fail(function() {
                        console.error('Search failed');
                    });
            }

            // Initialize Flatpickr and Listeners
            const fp = flatpickr("#dateRange", {
                mode: "range",
                dateFormat: "Y-m-d",
                minDate: "today",
                onClose: function(selectedDates, dateStr, instance) {
                    if (selectedDates.length === 2) {
                        $('#startDate').val(instance.formatDate(selectedDates[0], "Y-m-d"));
                        $('#endDate').val(instance.formatDate(selectedDates[1], "Y-m-d"));
                        performSearch();
                    }
                }
            });

            $('#searchForm select').on('change', performSearch);
            $('#searchForm input[name="rig_length"]').on('change keyup', function() {
                clearTimeout(window.searchTimeout);
                window.searchTimeout = setTimeout(performSearch, 500);
            });

             $(document).on('change', '.site-lock-toggle', function() {
                const $row = $(this).closest('tr');
                const fee = parseFloat($(this).data('fee'));
                const isChecked = $(this).is(':checked');
                const basePrice = parseFloat($row.find('.add-to-cart').data('base'));

                const currentFee = isChecked ? fee : 0;
                const total = basePrice + currentFee;

                $row.find('.fee-display-label').text(currentFee.toFixed(2));
                $row.find('.extras-display-val').text(currentFee.toFixed(2));
                $row.find('.total-display-val').text(total.toFixed(2));
            });

            // Add to Cart
            $(document).on('click', '.add-to-cart', function() {
                 const $row = $(this).closest('tr');
                const adults = parseInt($row.find('.adults').val()) || 0;
                const children = parseInt($row.find('.children').val()) || 0;
                const siteLockChecked = $row.find('.site-lock-toggle').is(':checked');
                const siteLockFeeStatus = siteLockChecked ? 'on' : 'off';
                // Get fee from the checkbox data attribute
                const siteLockFeeVal = siteLockChecked ? parseFloat($row.find('.site-lock-toggle').data('fee')) : 0;

                const item = {
                    id: $(this).data('id'),
                    name: $(this).data('name'),
                    base: parseFloat($(this).data('base')),
                    fee: parseFloat($(this).data('fee')), // This is platform fee (0)
                    lock_fee_amount: siteLockFeeVal,      // Store the actual fee amount
                    start_date: $(this).data('start'),
                    end_date: $(this).data('end'),
                    occupants: {
                        adults: adults,
                        children: children
                    },
                    site_lock_fee: siteLockFeeStatus
                };

                cart.push(item);
                updateCartUI();
                $(this).prop('disabled', true).text('Added');
            });

             // Remove from Cart
            $(document).on('click', '.remove-item', function() {
                const index = $(this).data('index');
                const siteId = cart[index].id;
                cart.splice(index, 1);

                // Re-enable button in results if it exists
                $(`.add-to-cart[data-id="${siteId}"]`).prop('disabled', false).text('Add to Cart');

                updateCartUI();
            });

            function updateCartUI() {
                const $container = $('#cartItems');
                $container.empty();

                if (cart.length === 0) {
                    $('#emptyCartMsg').show();
                    $('#nextBtn').prop('disabled', true);
                } else {
                    $('#emptyCartMsg').hide();
                    $('#nextBtn').prop('disabled', false);

                    cart.forEach((item, index) => {
                        const baseVal = parseFloat(item.base) || 0;
                        const lockFeeVal = parseFloat(item.lock_fee_amount) || 0;
                        const feeVal = parseFloat(item.fee) || 0;
                        const itemTotal = baseVal + lockFeeVal + feeVal;
                        
                        // Calculate nights
                        let nightsText = '';
                        if (item.start_date && item.end_date) {
                            const s = new Date(item.start_date);
                            const e = new Date(item.end_date);
                            const diff = Math.abs(e - s);
                            const nights = Math.ceil(diff / (1000 * 60 * 60 * 24)) || 0;
                            nightsText = `<div class="x-small text-muted">${item.start_date} – ${item.end_date} (${nights} nights)</div>`;
                        }

                        $container.append(`
                            <div class="cart-item" data-index="${index}">
                                <div class="d-flex justify-content-between">
                                    <strong>${item.name}</strong>
                                    <a href="javascript:void(0)" class="text-danger remove-item" data-index="${index}"><i class="fas fa-trash"></i></a>
                                </div>
                                ${nightsText}
                                <div class="small text-muted">
                                    Base: $${baseVal.toFixed(2)}
                                    ${lockFeeVal > 0 ? `<br><span class="text-info fs-xs">+ Site Lock: $${lockFeeVal.toFixed(2)}</span>` : ''}
                                </div>
                                <div class="small fw-bold text-end">Item Total: $${itemTotal.toFixed(2)}</div>
                            </div>
                        `);
                    });
                }

                $('#cartCount').text(cart.length);
                updateTotals();
            }

            function updateTotals() {
                let subtotal = 0;
                cart.forEach(item => {
                     subtotal += (parseFloat(item.base) || 0) + (parseFloat(item.lock_fee_amount) || 0) + (parseFloat(item.fee) || 0);
                });

                const instantDiscount = parseFloat($('#instantDiscount').val()) || 0;
                
                // Toggle Discount Reason visibility
                if (instantDiscount > 0) {
                    $('#discountReasonContainer').show();
                } else {
                    $('#discountReasonContainer').hide();
                }

                const couponDiscount = parseFloat(window.appliedCouponDiscount) || 0;

                const totalDiscount = instantDiscount + couponDiscount;
                const subtotalAfterDiscount = Math.max(0, subtotal - totalDiscount);
                // Tax calculation removed as per user request
                const tax = 0;
                
                const credit = parseFloat(@json($draft->credit_amount ?? 0));
                const visualGrandTotal = Math.max(0, subtotalAfterDiscount - credit);

                // Modification Specifics
                if (originalReservations.length > 0) {
                    let matchedOriginalIds = [];
                    let totalRefunds = 0;
                    let totalNewCharges = 0;
                    
                    cart.forEach(item => {
                        const siteId = item.id;
                        const start = item.start_date || item.cid;
                        const end = item.end_date || item.cod;
                        
                        // Simple string comparison for dates (Y-m-d)
                        const match = originalReservations.find(old => {
                            const oldStart = old.cid.split('T')[0];
                            const oldEnd = old.cod.split('T')[0];
                            return !matchedOriginalIds.includes(old.id) &&
                                   String(old.siteid) === String(siteId) &&
                                   oldStart === start &&
                                   oldEnd === end;
                        });
                        
                        if (match) {
                            matchedOriginalIds.push(match.id);
                        } else {
                            totalNewCharges += (parseFloat(item.base) || 0) + (parseFloat(item.lock_fee_amount) || 0) + (parseFloat(item.fee) || 0);
                        }
                    });
                    
                    originalReservations.forEach(old => {
                        if (!matchedOriginalIds.includes(old.id)) {
                            totalRefunds += parseFloat(old.subtotal) || 0;
                        }
                    });
                    
                    $('#immediateRefundDisplay').text('$' + totalRefunds.toFixed(2));
                    $('#newChargesDisplay').text('$' + totalNewCharges.toFixed(2));
                }

                $('#subtotalDisplay').text('$' + subtotal.toFixed(2));
                $('#discountsDisplay').text('-$' + totalDiscount.toFixed(2));
                $('#taxDisplay').text('$' + tax.toFixed(2));
                $('#grandTotalDisplay').text('$' + visualGrandTotal.toFixed(2));

                window.currentTotals = {
                    subtotal: subtotal,
                    discount_total: totalDiscount,
                    estimated_tax: 0,
                    platform_fee_total: 0,
                    grand_total: subtotalAfterDiscount // Save gross total here
                };
            }

            // Next Step
            $('#nextBtn').on('click', function() {
                const $btn = $(this);
                $btn.prop('disabled', true).text('Saving Draft...');

                $.post("{{ route('flow-reservation.save-draft') }}", {
                        _token: "{{ csrf_token() }}",
                        draft_id: "{{ $draft->draft_id ?? '' }}",
                        cart_data: cart,
                        totals: window.currentTotals,
                        discount_reason: $('#discountReason').val(),
                        coupon_code: window.appliedCouponCode || ''
                    })
                    .done(function(res) {
                        window.location.href = res.redirect_url;
                    })
                    .fail(function() {
                        alert('Failed to save draft. Please try again.');
                        $btn.prop('disabled', false).text('Next');
                    });
            });

            // Apply Coupon
            $('#applyCoupon').on('click', function() {
                const $btn = $(this);
                const code = $('#couponCode').val();
                
                if (!code) return alert('Please enter a coupon code.');
                
                let subtotal = 0;
                cart.forEach(item => {
                    subtotal += (parseFloat(item.base) || 0) + (parseFloat(item.lock_fee_amount) || 0) + (parseFloat(item.fee) || 0);
                });

                if (subtotal <= 0) return alert('Cart is empty.');

                $btn.prop('disabled', true).html('<i class="fas fa-spinner fa-spin"></i>');

                $.post("{{ route('flow-reservation.apply-coupon') }}", {
                    _token: "{{ csrf_token() }}",
                    code: code,
                    subtotal: subtotal
                }).done(function(res) {
                    window.appliedCouponDiscount = res.discount_amount;
                    window.appliedCouponCode = res.code;
                    updateTotals();
                    alert('Coupon applied: ' + res.label + ' (-$' + res.discount_amount.toFixed(2) + ')');
                }).fail(function(xhr) {
                    const msg = xhr.responseJSON ? xhr.responseJSON.message : 'Failed to apply coupon.';
                    alert(msg);
                }).always(function() {
                    $btn.prop('disabled', false).text('Apply');
                });
            });


            // Automatation
            const itemsToAutomate = getUrlItems();

            if (itemsToAutomate.length > 0) {
                processAutomatedSelections(itemsToAutomate);
            }

            async function processAutomatedSelections(items) {
                for (const item of items) {
                    await autoFetchAndAdd(item.siteId, item.cid, item.cod);
                }
            }

            function autoFetchAndAdd(siteId, cid, cod) {
                const loader = $('#auto-fetch-loader');
                loader.removeClass('d-none');

                return $.get("{{ route('flow-reservation.search') }}", {
                        start_date: cid,
                        end_date: cod,
                        siteid: siteId
                    })
                    .done(function(res) {
                        // const platformFee = parseFloat(res.platform_fee) || 5.00; // Removed
                        const units = res.data.response.results.units;

                        const unit = units.find(u => u.site_id === siteId);



                        if (unit) {
                            const automatedItem = {
                                id: unit.site_id,
                                name: unit.name,
                                base: parseFloat(unit.price_quote.total),
                                fee: 0, // platformFee (Removed)
                                cid: cid, // We store the specific dates for this site
                                cod: cod,
                                lock_fee_amount: 0, // Auto-add assumes no lock fee initially, or we could fetch it?
                                site_lock_fee: 'off'
                            };



                            // Push to global cart array
                            cart.push(automatedItem);

                            // Update Sidebar UI and Totals
                            updateCartUI();

                        }
                    })
                    .fail(function() {
                        console.error(`Failed to automate site: ${siteId}`);
                    })
                    .always(function() {
                        loader.addClass('d-none');
                    });
            }

            function getUrlItems() {
                const params = new URLSearchParams(window.location.search);
                const items = [];
                for (const [key, value] of params.entries()) {
                    const match = key.match(/items\[(\d+)\]\[(\w+)\]/);
                    if (match) {
                        const index = match[1];
                        const field = match[2];
                        if (!items[index]) items[index] = {};
                        items[index][field] = value;
                    }
                }
                return items.filter(Boolean);
            }


            // Routes for JS
            const routes = {
                siteDetails: "{{ route('flow-reservation.site-details') }}",
                information: "{{ route('flow-reservation.information') }}",
                applyCoupon: "{{ route('flow-reservation.apply-coupon') }}"
            };

            // View Site Details Handler (Row Click)
            $(document).on('click', '.view-site-details', function(e) {
                // Prevent trigger if clicking button, input, or label
                if ($(e.target).closest('button, input, label, .form-check').length) return;

                const siteId = $(this).data('id');
                const startDate = $('#startDate').val();
                const endDate = $('#endDate').val();
                
                // Get row data efficiently
                const $row = $(this).closest('tr');
                const $addBtn = $row.find('.add-to-cart');
                
                // Copy data to modal Add button
                const modalAddBtn = $('#addToCartSite');
                modalAddBtn.data('id', $addBtn.data('id'));
                modalAddBtn.data('name', $addBtn.data('name'));
                modalAddBtn.data('base', $addBtn.data('base'));
                modalAddBtn.data('fee', $addBtn.data('fee'));
                modalAddBtn.data('start', $addBtn.data('start'));
                modalAddBtn.data('end', $addBtn.data('end'));
                
                // Show info that we are loading
                $('#sdName').text('Loading...');
                
                $.get(routes.siteDetails, {
                    site_id: siteId,
                    uscid: startDate,
                    uscod: endDate
                }).done(function(res) {
                    populateSiteDetails(res, startDate, endDate, modalAddBtn);
                    $('#siteDetailsModal').modal('show');
                }).fail(function() {
                    alert('Failed to load site details.');
                });
            });

            function populateSiteDetails(res, start, end, $btnData) {
                const r = res.response || res; // API structure
                const s = r.site || r; 
                
                // --- Load Important Information ---
                $.get(routes.information).done(infoRes => {
                    const infos = infoRes.information || [];
                    const $infoCardBody = $('#infoCardBody'); // Ensure this ID exists in modal, else fallback or add it
                     // If modal structure differs, we might need to adjust or inject raw html
                     // The user request implies the modal structure IS correct or shared.
                     // Let's assume the modal file `reservations.modals.site-details` has this structure.
                     // If not, we might need to verify the modal file content again.
                     // Assuming shared modal file: 'reservations/modals/site-details.blade.php'
                    
                    if ($infoCardBody.length) {
                        $infoCardBody.empty();
                        if (infos.length > 0) {
                             $('#sdTitleInfo').text('Important Information');
                             infos.forEach(info => {
                                if (info.title && info.description) {
                                    $infoCardBody.append(`
                                        <div class="mb-3">
                                            <h6 class="text-dark mb-0 fw-bold">${info.title}</h6>
                                            <p class="small text-muted mb-0">${info.description}</p>
                                        </div>
                                    `);
                                }
                             });
                        } else {
                            $('#sdTitleInfo').text('Information Not Available');
                            $infoCardBody.append('<p class="text-muted fst-italic">No important information currently listed.</p>');
                        }
                    }
                });

                // Site Info
                $('#sdName').text(s.name || s.sitename || $btnData.data('name'));
                $('#sdSiteId').text(s.site_id || s.siteid);
                $('#sdClass').text((s.class || s.siteclass || '').replace(/_/g, ' '));
                $('#sdHookup').text(s.hookup || 'N/A');
                // Rig logic from reference
                if (s.constraints?.rig_length) {
                    $('#sdRig').text(`${s.constraints.rig_length.min}ft – ${s.constraints.rig_length.max}ft`);
                } else {
                    $('#sdRig').text((s.maxlength || 0) + ' ft');
                }

                // Attributes
                $('#sdAttributes').text(s.attributes || s.description || 'No additional description.');

                // Amenities
                const $amenitiesList = $('#sdAmenities').empty();
                const amenities = s.amenities || [];
                // Handle different amenities formats (string vs array of strings vs array of objects)
                if (Array.isArray(amenities)) {
                    if (amenities.length > 0) {
                        amenities.forEach(a => {
                            const txt = typeof a === 'string' ? a : (a.amenity || a);
                             $amenitiesList.append(`<li><span class="badge badge-pill badge-primary text-white" style="background-color: #0d6efd; margin-right:4px; margin-bottom:4px;">${txt.replace(/_/g, ' ')}</span></li>`);
                        });
                    } else {
                        $amenitiesList.append('<li class="text-muted small">None Listed</li>');
                    }
                } else if (typeof amenities === 'string') {
                    // existing logic for comma-separated
                     const arr = amenities.split(',').filter(Boolean);
                      if (arr.length) {
                        arr.forEach(a => $amenitiesList.append(`<li><span class="badge badge-pill badge-primary text-white">${a}</span></li>`));
                    }
                }

                 // Images
                const siteImages = r.media?.images || r.media?.gallery || s.images || [];
                const container = $('#sdImagesContainer');
                container.empty();
                const imageBasePath = '/storage/sites/'; 
                let slidesHtml = '';

                if (Array.isArray(siteImages) && siteImages.length > 0) {
                     siteImages.forEach((img, index) => {
                        const filename = typeof img === 'string' ? img : img.filename;
                        const src = typeof img === 'string' ? `${imageBasePath}${filename}` : `/sites/images/${img.siteid}/${img.filename}`; // Handle both formats
                        
                        const isActive = index === 0 ? 'active' : '';
                        slidesHtml += `
                            <div class="carousel-item ${isActive}">
                                <img src="${src}" class="d-block w-100 rounded-top" alt="Site Image" style="height: 400px; object-fit: cover;">
                            </div>
                        `;
                    });
                } else {
                    slidesHtml = `
                        <div class="carousel-item active has-background-light d-flex align-items-center justify-content-center" style="height: 400px; background: #f8f9fa;">
                           <span class="text-muted">No Images Available</span>
                        </div>
                    `;
                }
                container.html(slidesHtml);
                
                // Re-init bootstrap carousel logic
                const $carouselElement = $('#siteImagesCarousel');
                if ($carouselElement.length) {
                     // If bootstrap 5
                     const nativeCarouselElement = $carouselElement[0];
                     // try dispose
                    try {
                         const bsCarousel = bootstrap.Carousel.getInstance(nativeCarouselElement);
                         if (bsCarousel) bsCarousel.dispose();
                    } catch(e){}
                    new bootstrap.Carousel(nativeCarouselElement);
                     $carouselElement.show();
                }

                // Pricing
                // User wants exact same display. Reference uses response data directly.
                // We fallback to button data if response doesn't have pricing snapshot (common in direct site view vs availability view)
                const base = parseFloat($btnData.data('base'));
                const d1 = new Date(start);
                const d2 = new Date(end);
                 // Calculate nights
                const diff = Math.abs(d2 - d1);
                const nights = Math.ceil(diff / (1000 * 60 * 60 * 24)) || 1;
                const avg = base / nights;

                $('#sdStay').text(nights);
                $('#sdAvgNight').text(avg.toFixed(2));
                $('#sdTotal').text(base.toFixed(2));
                if (r.policies?.minimum_stay) {
                     $('#sdMinStay').text(r.policies.minimum_stay);
                }
            }

            $('#addToCartSite').on('click', function() {
                const siteId = $(this).data('id');
                // Trigger click on the original button to reuse logic
                $(`.add-to-cart[data-id="${siteId}"]`).click();
                $('#siteDetailsModal').modal('hide');
            });

            // Restore from draft (at the end to ensure UI/Flatpickr is ready)
            @if($draft)
            (function() {
                const draftCart = @json($draft->cart_data);
                if (draftCart && draftCart.length > 0) {
                    const draftDiscountTotal = parseFloat(@json($draft->discount_total)) || 0;
                    const draftCouponCode = @json($draft->coupon_code);
                    
                    $('#discountReason').val(@json($draft->discount_reason));
                    
                    if (draftCouponCode) {
                        $('#couponCode').val(draftCouponCode);
                    } else {
                        $('#instantDiscount').val(draftDiscountTotal.toFixed(2));
                    }
                    
                    // Pre-fill date picker from first item
                    const firstItem = draftCart[0];
                    if (firstItem.start_date && firstItem.end_date) {
                        $('#startDate').val(firstItem.start_date);
                        $('#endDate').val(firstItem.end_date);
                        // Update flatpickr
                        if (fp) {
                            fp.setDate([firstItem.start_date, firstItem.end_date], false);
                        }
                    }

                    updateCartUI();

                    // If there was a coupon, try to re-apply it to get the breakdown
                    if (draftCouponCode) {
                        let subtotal = 0;
                        cart.forEach(item => {
                            subtotal += (parseFloat(item.base) || 0) + (parseFloat(item.lock_fee_amount) || 0) + (parseFloat(item.fee) || 0);
                        });

                        $.post("{{ route('flow-reservation.apply-coupon') }}", {
                            _token: "{{ csrf_token() }}",
                            code: draftCouponCode,
                            subtotal: subtotal
                        }).done(function(res) {
                            window.appliedCouponDiscount = res.discount_amount;
                            window.appliedCouponCode = res.code;
                            
                            // Remaining is instant discount
                            const instant = Math.max(0, draftDiscountTotal - res.discount_amount);
                            $('#instantDiscount').val(instant.toFixed(2));
                            
                            updateTotals();
                        });
                    }
                }
            })();
            @endif

        });
    </script>
@endpush
