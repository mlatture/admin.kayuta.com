<div class="modal fade" id="reservationDetailsModal" tabindex="-1" aria-labelledby="reservationDetailsModalLabel"
    aria-hidden="true">
    <div class="modal-dialog modal-xl modal-dialog-scrollable">
        <div class="modal-content">
            <div class="modal-header bg-primary text-white">
                <h5 class="modal-title" id="reservationDetailsModalLabel">Reservation Details</h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"
                    aria-label="Close"></button>
            </div>
            <div class="modal-body" id="reservationDetailsModalBody">
                <div class="card card-body" id="reservationDetailsContent" style="display:none;">
                    <div class="row">
                        <div class="col-md-6">
                            <p><strong>Customer:</strong> <span id="resCustomerName"></span></p>
                            <p><strong>Arrival:</strong> <span id="resArrivalDate"></span></p>
                            <p><strong>Departure:</strong> <span id="resDepartureDate"></span></p>
                            <p><strong>Site ID:</strong> <span id="resSiteId"></span></p>
                            <p><strong>Rig Type:</strong> <span id="resRigType"></span></p>
                            <p><strong>Rig Length:</strong> <span id="resRigLength"></span></p>
                            <p><strong>Class:</strong> <span id="resSiteClass"></span></p>
                            <p><strong>Status:</strong> <span id="resStatusBadge" class="badge"></span></p>
                        </div>
                        <div class="col-md-6">
                            <p><strong>Email:</strong> <span id="resEmail"></span></p>
                            <p><strong>Phone:</strong> <span id="resPhone"></span></p>
                            <p><strong>Length of Stay:</strong> <span id="resNights"></span> nights</p>
                            <p><strong>Confirmation Number:</strong> <span id="resCartId"></span></p>
                            <p><strong>Comments:</strong> <span id="resComments"></span></p>
                            <p><strong>Total:</strong> $<span id="resTotal"></span></p>
                            <p><strong>Balance:</strong>
                                <span id="resBalanceBadge" class="badge rounded-pill"></span>
                            </p>
                        </div>
                    </div>

                    <hr>

                    <div class="row">
                        <div class="col-md-6">
                            <p><strong>Check in:</strong> <span id="resCheckIn"></span></p>
                        </div>
                        <div class="col-md-6">
                            <p><strong>Check out:</strong> <span id="resCheckOut"></span></p>
                        </div>
                    </div>

                    <hr>

                    <p><strong>Source:</strong> <span id="resSource"></span></p>

                    <hr>

                    {{-- ADJUST DATES BUTTON --}}
                    <div class="mb-4 text-center">
                        <button class="btn btn-outline-primary" id="btnAdjustDates">
                            <i class="fa-solid fa-calendar-days me-1"></i> Adjust Dates
                        </button>
                    </div>

                    <div id="adjustDatesPicker" style="display: none;">
                        <div class="d-flex align-items-center justify-content-center gap-2 mb-3">
                            <button class="btn btn-sm btn-secondary" onclick="adjustDates(-1)"><i
                                    class="fa-solid fa-arrow-left"></i></button>
                            <input type="text" id="adjustDateRange" class="form-control text-center" readonly
                                style="max-width: 240px;" />
                            <button class="btn btn-sm btn-secondary" onclick="adjustDates(1)"><i
                                    class="fa-solid fa-arrow-right"></i></button>
                        </div>
                        <div class="text-center mb-2">
                            <button class="btn btn-success" onclick="submitAdjustedDates()">Save Changes & Email
                                Receipt</button>
                        </div>
                    </div>

                    <hr>

                    <div class="row">
                        <div class="col-md-6">
                            <div class="card mb-2 action-card" style="cursor: pointer; height: 150px;" id="action1">
                                <div class="card-body d-flex justify-content-center align-items-center">
                                    <h6 class="card-title text-center">
                                        <i class="fa-solid fa-pen-to-square"></i> Edit Reservations
                                    </h6>
                                </div>
                            </div>
                        </div>
                        <div class="col-md-6">
                            <div class="card mb-2 action-card" style="cursor: pointer; height: 150px;" id="action3">
                                <div class="card-body d-flex justify-content-center align-items-center">
                                    <h6 class="card-title text-center">
                                        <i class="fa-solid fa-hand-holding-dollar"></i> Payment
                                    </h6>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
            </div>
        </div>
    </div>
</div>
