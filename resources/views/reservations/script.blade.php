@push('js')
    <script src="https://cdnjs.cloudflare.com/ajax/libs/select2/4.0.13/js/select2.min.js"></script>

    <script>
        $(document).ready(function() {
            $('[data-bs-toggle="tooltip"]').each(function() {
                new bootstrap.Tooltip(this, {
                    html: true,
                    sanitize: false,
                });
            });

            $('.site-info-icon').each(function() {
                const $icon = $(this);
                const siteDataJson = $icon.data('site-data');

                if (!siteDataJson) return;

                try {
                    const data = siteDataJson;
                    let tooltipContent = '';

                    $.each(data, function(key, value) {
                        if (value !== null && typeof value !== 'undefined') {
                            tooltipContent += `<strong>${key}:</strong> ${value} <br>`;
                        }
                    });

                    $icon.attr('data-bs-original-title', tooltipContent);
                } catch (e) {
                    console.error('Error parsing site data JSON:', e);
                }
            })

            function applyFiltersAndReload() {
                const startDate = $('#startDatePicker').val();
                const seasonalFilter = $('#seasonalFilter').val() || 'short';

                const rigLength = $('#rigLength').val() || '';
                const selectedTypes = $('#typeFilter').val() || [];
                const selectedTier = $('#tierFilter').val() || [];
                const selectedSites = $('#siteFilter').val() || [];

                let queryString = `?startDate=${startDate}&seasonalFilter=${seasonalFilter}`;

                rigLength && (queryString += `&riglength=${encodeURIComponent(rigLength)}`);

                selectedTypes.forEach(type => {

                    queryString += `&siteclass[]=${encodeURIComponent(type)}`;
                });

                selectedTier.forEach(tier => {
                    queryString += `&ratetier[]=${encodeURIComponent(tier)}`;
                });

                selectedSites.forEach(siteId => {
                    queryString += `&siteid[]=${encodeURIComponent(siteId)}`;
                });

                window.location.href = queryString;
            }

            function navigateAndReload(days) {
                const current = new Date($('#startDatePicker').val());
                current.setDate(current.getDate() + days);

                $('#startDatePicker').val(current.toISOString().split('T')[0]);

                applyFiltersAndReload();
            }



            $('#prev30').on('click', function() {
                navigateAndReload(-30);
            });

            $('#next30').on('click', function() {
                navigateAndReload(30);
            });

            $('.applyFilter').on('click', function(e) {
                applyFiltersAndReload();
            })

            $('#siteFilter, #typeFilter, #tierFilter').select2({

                dropdownParent: $('#filtersModal')
            });




        });






        $(document).on('click', '.reservation-details', function() {
            let groupCode = $(this).data('group-code');
            if (groupCode) {
                window.location.href = "{{ route('admin.unified-bookings.show', ':id') }}".replace(':id', groupCode);
            } else {
                console.error('Group code not found for reservation');
            }
        });

        function fetchReservationDetails(reservationId) {
            $.ajax({
                url: "{{ route('reservations.details') }}",
                type: 'GET',
                data: {
                    id: reservationId
                },
                success: function(item) {
                    const c = item.customerRecord || {};
                    const format = d => d ? moment(d).format('MMM DD, YYYY') : 'N/A';
                    const balance = parseFloat(item.balance || 0).toFixed(2);
                    console.log('customer', item);
                    $('#resCustomerName').text(`${item.fname || 'Guest'} ${item.lname || ''}`);
                    $('#resArrivalDate').text(format(item.cid));
                    $('#resDepartureDate').text(format(item.cod));
                    $('#resSiteId').text(item.siteid || 'N/A');
                    $('#resRigType').text(item.rigtype || 'N/A');
                    $('#resRigLength').text(item.riglength || 'N/A');
                    $('#resSiteClass').text(item.siteclass || 'N/A');

                    $('#resEmail').text(c.email || 'N/A');
                    $('#resPhone').text(c.phone || 'N/A');
                    $('#resNights').text(item.nights || 'N/A');
                    $('#resCartId').text(item.cartid || 'N/A');
                    $('#resComments').text(item.comments || 'N/A');
                    $('#resTotal').text(parseFloat(item.total || 0).toFixed(2));

                    const balanceBadge = $('#resBalanceBadge');
                    balanceBadge.text(`$${balance}`);
                    balanceBadge.removeClass('bg-danger bg-success').addClass(balance > 0 ? 'bg-danger' :
                        'bg-success');

                    $('#resCheckIn').html(item.checkedin ||
                        `<input type="checkbox" class="checked_in_date" onclick="handleCheckIn(this, '${item.cartid}')">`
                    );
                    $('#resCheckOut').html(item.checkedout ||
                        `<input type="checkbox" class="checked_out_date" onclick="handleCheckOut(this, '${item.cartid}')">`
                    );
                    $('#resSource').text(item.source || 'Walk-In');

                    $('#action1').data('id', item.cartid);
                    $('#action3').data('id', item.cartid);

                    $('#reservationDetailsContent').show();
                    $('#reservationDetailsModal').modal('show');
                },
                error: function(err) {
                    alert('Unable to fetch reservation details.');
                    console.error(err);
                }
            });
        }

        let originalCID = null;
        let originalCOD = null;

        $(document).on('click', '#btnAdjustDates', function() {
            const cid = $('#resArrivalDate').text();
            const cod = $('#resDepartureDate').text();
            if (cid && cod) {
                originalCID = moment(cid, 'MMM DD, YYYY');
                originalCOD = moment(cod, 'MMM DD, YYYY');
                updateDateRangePicker();
                $('#adjustDatesPicker').slideDown();
            }
        });

        function updateDateRangePicker() {
            $('#adjustDateRange').val(`${originalCID.format('MMM DD, YYYY')} → ${originalCOD.format('MMM DD, YYYY')}`);
        }

        function adjustDates(direction) {
            originalCID.add(direction, 'days');
            originalCOD.add(direction, 'days');
            updateDateRangePicker();
        }

        function submitAdjustedDates() {
            // Send AJAX PATCH or POST to your backend with new CID/COD
            Swal.fire('Dates adjusted!', 'You can now apply charges/refunds and email a receipt.', 'success');
        }



        function handleCheckIn(checkbox, cartId) {
            Swal.fire({
                title: 'Confirm Check-In',
                text: 'Are you sure you want to check in this guest?',
                icon: 'question',
                showCancelButton: true,
                confirmButtonText: 'Yes, check in',
                cancelButtonText: 'Cancel',
            }).then((result) => {
                if (result.isConfirmed) {
                    const now = new Date().toLocaleString();
                    const checkInRoute = "{{ route('reservations.updateCheckedIn') }}";

                    fetch(checkInRoute, {
                            method: 'PATCH',
                            headers: {
                                'Content-Type': 'application/json',
                                'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').getAttribute(
                                    'content'),
                            },
                            body: JSON.stringify({
                                cartid: cartId
                            }),
                        })
                        .then(res => res.json())
                        .then(data => {
                            if (data.success) {
                                checkbox.parentElement.innerHTML = now;
                                Swal.fire('Checked in!', `Time: ${now}`, 'success');
                            } else {
                                Swal.fire('Error', data.message || 'Could not check in.', 'error');
                                checkbox.checked = false;
                            }
                        })
                        .catch(err => {
                            console.error(err);
                            Swal.fire('Error', 'Request failed. Please try again.', 'error');
                            checkbox.checked = false;
                        });
                } else {
                    checkbox.checked = false;
                }
            });
        }


        function handleCheckOut(checkbox, cartId) {
            Swal.fire({
                title: 'Confirm Check-Out',
                text: 'Are you sure you want to check out this guest?',
                icon: 'question',
                showCancelButton: true,
                confirmButtonText: 'Yes, check out',
                cancelButtonText: 'Cancel',
            }).then((result) => {
                if (result.isConfirmed) {
                    const checkOutRoute = "{{ route('reservations.updateCheckedOut') }}";

                    fetch(checkOutRoute, {
                            method: 'PATCH',
                            headers: {
                                'Content-Type': 'application/json',
                                'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').getAttribute(
                                    'content'),
                            },
                            body: JSON.stringify({
                                cartid: cartId
                            }),
                        })
                        .then(res => res.json())
                        .then(data => {
                            if (data.success) {
                                checkbox.parentElement.innerHTML = formatDateTime(data.checked_out_date);
                                Swal.fire('Checked out!', `Time: ${formatDateTime(data.checked_out_date)}`,
                                    'success');
                            } else {
                                Swal.fire('Error', data.message || 'Could not check out.', 'error');
                                checkbox.checked = false;
                            }
                        })
                        .catch(err => {
                            console.error(err);
                            Swal.fire('Error', 'Request failed. Please try again.', 'error');
                            checkbox.checked = false;
                        });

                } else {
                    checkbox.checked = false;
                }
            });
        }

        function formatDateTime(datetimeStr) {
            const date = new Date(datetimeStr);
            return date.toLocaleString('en-US', {
                year: 'numeric',
                month: 'short',
                day: 'numeric',
                hour: 'numeric',
                minute: '2-digit',
                hour12: true
            });
        }




        $(document).on("click", "#action1", function() {
            const id = $(this).data("id");
            url = "{{ route('admin.reservations.show', ':id') }}"
                .replace(':id', id);
            window.location = url;
        });


        $('#check-available').on('click', function() {
            const isHidden = $('#ganttTableAvailable').is(':hidden');

            if (isHidden) {
                const sitesFromTable = [];

                $('#siteTableBody tr').each(function() {
                    const $row = $(this);

                    sitesFromTable.push({
                        id: $row.data('site-id'),
                        siteid: $row.data('site-siteid'),
                        siteclass: $row.data('site-siteclass'),
                        images: $row.data('site-images'),
                        price: parseFloat($row.data('site-price')),
                    });
                });

                checkAvailable(sitesFromTable);
                $('.management-table').hide();
                $('#ganttTableAvailable').show();
                $('.filter-container').removeAttr('hidden');
            } else {
                $('.management-table').show();
                $('#ganttTableAvailable').hide();
                $('.filter-container').attr('hidden', true);
            }
        });



        function checkAvailable(data) {
            console.log("Data from Laravel:", data);

            $('#site-list').empty();

            if (!Array.isArray(data) || data.length === 0) {
                $('#site-list').append('<p>No sites available</p>');
                return;
            }

            let sitesHTML = data.map(site => `
                <label class="list-group-item">
                    <input type="checkbox" class="site-checkbox" value="${site.id}">
                    ${site.siteid || site.sitename || 'Unnamed Site'}
                </label>
            `).join('');

            $('#site-list').append(sitesHTML);
            showFilteredCards([], data, true);

            $('#apply-filter').off('click').on('click', function() {
                let selectedSites = $('.site-checkbox:checked').map(function() {
                    return $(this).val();
                }).get();

                if (selectedSites.length === 0) {
                    showFilteredCards([], data, true);
                } else {
                    showFilteredCards(selectedSites, data, false);
                }

                $('#filterModal').modal('hide');
                $('body').removeClass('modal-open');
                $('.modal-backdrop').remove();
            });

            $('#ganttTableAvailable').attr('hidden', false);
            $('.filter-container').attr('hidden', false);
        }

        const storageBaseUrl = "{{ asset('storage/sites') }}";
        const fallbackImage =
            "https://storage.googleapis.com/proudcity/mebanenc/uploads/2021/03/placeholder-image-300x225.png";

        function showFilteredCards(selectedSiteIds, allSites, showAll = false) {
            console.log('all sites', allSites);
            let filteredSites = showAll ?
                allSites :
                allSites.filter(site => selectedSiteIds.includes(String(site.id)));

            let cardHtml = '';
            const imageRotationData = [];

            filteredSites.forEach(site => {
                let images = [];

                if (Array.isArray(site.images)) {
                    images = site.images;
                } else if (typeof site.images === 'string') {
                    try {
                        let parsed = JSON.parse(site.images);
                        images = Array.isArray(parsed) ? parsed : [parsed];
                    } catch (e) {
                        images = [];
                    }
                }

                images = images.map(img => `${storageBaseUrl}/${img}`);
                if (!images.length) images = [fallbackImage];

                const imgId = `img-${site.id}-${Math.floor(Math.random() * 10000)}`;
                console.log('Site:', site);
                console.log('Parsed images:', images);

                cardHtml += `
                    <div class="col-md-3 mb-3">
                        <div class="card site-card" data-id="${site.id}" data-price="${site.price || 100}">
                            <img id="${imgId}" src="${images[0]}" class="card-img-top" alt="Site Image"
                                style="max-height: 200px; object-fit: cover;"
                                onerror="this.onerror=null; this.src='${fallbackImage}'">
                            <div class="card-body text-center">
                                <h5 class="card-title">${site.siteid}</h5>
                                <p class="card-text">${site.siteclass}</p>
                            </div>
                        </div>
                    </div>
                `;

                if (images.length > 1) {
                    imageRotationData.push({
                        imgId,
                        images
                    });
                }
            });

            document.getElementById("ganttTableAvailable").innerHTML = `<div class="row">${cardHtml}</div>`;

            document.querySelectorAll('.site-card').forEach(card => {
                card.addEventListener('click', function() {
                    toggleSite(this);
                });
            });

            imageRotationData.forEach(({
                imgId,
                images
            }) => {
                let index = 0;
                setInterval(() => {
                    index = (index + 1) % images.length;
                    const imgEl = document.getElementById(imgId);
                    if (imgEl) imgEl.src = images[index];
                }, 3000);
            });
        }





        let today = new Date().toISOString().split('T')[0];
        let tomorrow = new Date();
        tomorrow.setDate(tomorrow.getDate() + 1);
        tomorrow = tomorrow.toISOString().split('T')[0];


        let selectedSites = [];
        let nightsCounts = 1;


        function toggleSite(card) {
            let siteId = card.getAttribute("data-id");
            let price = parseFloat(card.getAttribute("data-price"));

            let index = selectedSites.findIndex(site => site.id === siteId);

            if (index > -1) {
                selectedSites.splice(index, 1);
                card.classList.remove("selected");
            } else {
                selectedSites.push({
                    id: siteId,
                    price: price
                });
                card.classList.add("selected");
            }

            console.log('Updated Selected Sites:', selectedSites);
            document.getElementById("quoteButton").hidden = selectedSites.length === 0;
        }

        function calculateNights() {
            let checkin = new Date(document.getElementById("checkin").value);
            let checkout = new Date(document.getElementById("checkout").value);

            if (!isNaN(checkin.getTime()) && !isNaN(checkout.getTime())) {
                let timeDiff = checkout.getTime() - checkin.getTime();
                nightsCounts = timeDiff / (1000 * 3600 * 24);

                $("#nights").val(`${nightsCounts}`);
                console.log(`Global Nights Count Updated: ${nightsCounts}`); // ✅ Debugging
            }
        }






        $(document).ready(function() {
            $('#sitename').select2();
            $('#siteclass').select2();
            $('.reservation-select').select2();
        });

        $('#customerDate').daterangepicker({
            autoUpdateInput: false,
            locale: {
                cancelLabel: 'Clear'
            }
        });

        $('#customerDate').on('apply.daterangepicker', function(ev, picker) {
            $(this).val(picker.startDate.format('MM/DD/YYYY') + ' - ' + picker.endDate.format(
                'MM/DD/YYYY'));
        });

        $('#customerDate').on('cancel.daterangepicker', function(ev, picker) {
            $(this).val('');
        });

        $('#customerDate').attr("placeholder", "Select Date");

        $('#customersTable').DataTable({
            // 'responsive': true,
            order: [
                [0, 'asc']
            ],
            "columnDefs": [{
                    "sortable": false,
                    "targets": 1
                },
                {
                    "sortable": false,
                    "targets": 8
                },
                {
                    "sortable": false,
                    "targets": 9
                }
            ]
        });

        function openReservationModal() {
            $('.res-cid').val('');
            $('.res-cod').val('');
            $('.reservationId').val('');
            $('.alert-success').addClass('d-none').text('');
            $('.alert-danger').addClass('d-none').text('');
            $(':input').removeClass('is-invalid');
            $('.error--msg').remove();
            $(`#reservationDateModal`).modal('toggle');
        }

        function openReservationSiteModal() {
            $('.res-siteid').val('');
            $('.res-siteclass').val('');
            $('.reservationId2').val('');
            $('.alert-success').addClass('d-none').text('');
            $('.alert-danger').addClass('d-none').text('');
            $(':input').removeClass('is-invalid');
            $('.error--msg').remove();
            $(`#reservationSiteModal`).modal('toggle');
        }

        function closeReservationModal(modalId) {
            $(`#${modalId}`).modal('hide');
        }

        function saveReservationDates(input) {
            $(input).attr('disabled', true);
            $(':input').removeClass('is-invalid');
            $('.error--msg').remove();
            $('.alert-success').addClass('d-none').text('');
            $('.alert-danger').addClass('d-none').text('');
            $.post($('#reservationDateForm').attr('action'), $('#reservationDateForm').serialize()).done(function(res) {
                    if (res.status == "success") {
                        $('.alert-success').removeClass('d-none').text(res.message);
                        setTimeout(function() {
                            $('#reservationDateModal').modal('hide');
                            $(input).attr('disabled', false);
                            $('.alert-success').addClass('d-none').text('');
                            $('.alert-danger').addClass('d-none').text('');
                        }, 1500);
                    } else {
                        $(input).attr('disabled', false);
                        $('.alert-danger').removeClass('d-none').text(res.message)
                    }
                })
                .fail(function(jqXHR, textStatus, errorThrown) {
                    $(input).attr('disabled', false);
                    if (jqXHR.status === 422) {
                        $.each(jqXHR.responseJSON.errors, function(k, v) {
                            $(`#${k}`).addClass('is-invalid').after(
                                `<span class="error--msg" role="alert"><strong class="text-danger">${v[0]}</strong></span>`
                            );
                        });
                    }
                });
        }

        function saveReservationSites(input) {
            $(input).attr('disabled', true);
            $(':input').removeClass('is-invalid');
            $('.error--msg').remove();
            $('.alert-success').addClass('d-none').text('');
            $('.alert-danger').addClass('d-none').text('');
            $.post($('#reservationSiteForm').attr('action'), $('#reservationSiteForm').serialize()).done(function(res) {
                    if (res.status == "success") {
                        $('.alert-success').removeClass('d-none').text(res.message);
                        setTimeout(function() {
                            $('#reservationSiteModal').modal('hide');
                            $(input).attr('disabled', false);
                            $('.alert-success').addClass('d-none').text('');
                            $('.alert-danger').addClass('d-none').text('');
                        }, 1500);
                    } else {
                        $(input).attr('disabled', false);
                        $('.alert-danger').removeClass('d-none').text(res.message)
                    }
                })
                .fail(function(jqXHR, textStatus, errorThrown) {
                    $(input).attr('disabled', false);
                    if (jqXHR.status === 422) {
                        $.each(jqXHR.responseJSON.errors, function(k, v) {
                            $(`#${k}`).addClass('is-invalid').after(
                                `<span class="error--msg" role="alert"><strong class="text-danger">${v[0]}</strong></span>`
                            );
                        });
                    }
                });
        }

        let selectedCells = [];

        $('.selectable-site').on('click', function(e) {
            const cell = $(this);
            const siteId = cell.data('site-id');
            const date = cell.data('date');

            cell.toggleClass('bg-primary text-white');

            const index = selectedCells.findIndex(c => c.date === date && c.siteId === siteId);
            if (index > -1) {
                selectedCells.splice(index, 1);
            } else {
                selectedCells.push({
                    date,
                    siteId
                });
            }

            updateMultiSiteCartButton();
        });

        function updateMultiSiteCartButton() {
            const btn = $('#btnCreateReservation');

            if (selectedCells.length > 0) {
                const groups = selectedCells.reduce((acc, current) => {
                    if (!acc[current.siteId]) acc[current.siteId] = [];
                    acc[current.siteId].push(current.date);
                    return acc;
                }, {});

                let queryString = "";
                const siteIds = Object.keys(groups);

                siteIds.forEach((siteId, index) => {
                    const dates = groups[siteId].sort();
                    const cid = dates[0];

                    const lastDate = new Date(dates[dates.length - 1]);
                    lastDate.setDate(lastDate.getDate() + 1);
                    const cod = lastDate.toISOString().split('T')[0];

                    queryString +=
                        `items[${index}][siteId]=${siteId}&items[${index}][cid]=${cid}&items[${index}][cod]=${cod}`;

                    if (index < siteIds.length - 1) queryString += "&";
                });

                const url = `{{ route('flow-reservation.step1') }}?${queryString}`;

                btn.attr('href', url);
                btn.text(`Add ${siteIds.length} Site(s) to Cart`);
                btn.removeClass('d-none');
            } else {
                btn.addClass('d-none');
            }
        }
    </script>
@endpush
