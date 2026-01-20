<div class="col-12 mb-3">
    <div class="row mb-4">
        <div class="col">
            <label for="email_invoice" id="emailLabel">Email:</label>
            <input type="text" id="email_invoice" class="form-control shadow-sm" placeholder="Enter Email" autocomplete="off">
            <input type="hidden" id="cust_email">
        </div>
    </div>

    <div class="btn-group btn-group-sm btn-block btn-group-toggle d-flex" data-toggle="buttons">
        <label class="btn btn-outline-secondary btn-payment flex-fill active">
            <input type="radio" name="payment_method" class="paymentMethod" id="paymentMethodCash" value="Cash"
                autocomplete="off" checked />
            <i class="bi bi-cash"></i> Cash
        </label>
        <label class="btn btn-outline-secondary btn-payment flex-fill">
            <input type="radio" name="payment_method" class="paymentMethod" id="paymentMethodGiftCard"
                value="GiftCard" autocomplete="off" />
            <i class="bi bi-credit-card-2-front"></i> Gift Card / RFID
        </label>
        <label class="btn btn-outline-secondary btn-payment flex-fill">
            <input type="radio" name="payment_method" class="paymentMethod" id="paymentMethodCheck"
                value="Check" autocomplete="off" />
            <i class="bi bi-credit-card-3-front"></i> Check
        </label>

        <label class="btn btn-outline-secondary btn-payment flex-fill">
            <input type="radio" name="payment_method" class="paymentMethod" id="paymentMethodCreditCard"
                value="CreditCard" autocomplete="off" />
            <i class="bi bi-credit-card"></i> Credit Card
        </label>
    </div>

    <!-- Credit Card Fields -->
    <div class="row mt-4" id="credit-card-section" hidden>
        <div class="col-md-8">
            <label for="xCardNum">Card Number:</label>
            <input type="text" id="xCardNum" class="form-control shadow-sm" placeholder="4111111111111111">
        </div>
        <div class="col-md-4">
            <label for="xExp">Expiry (MM/YY):</label>
            <input type="text" id="xExp" class="form-control shadow-sm" placeholder="10/26">
        </div>
    </div>

    <div class="row mt-4" id="gift-card-section" hidden>
        <label for="giftcardno">Gift Card / RFID Number:</label>
        <div class="input-group">
            <input type="text" id="giftcardno" class="form-control shadow-sm" placeholder="Enter Gift Card No.">
            <div class="input-group-append">
                <button class="btn btn-outline-secondary shadow-sm" id="lookupGiftCardButton" type="button">
                    <i class="bi bi-search"></i> Lookup
                </button>
            </div>
        </div>
        <div class="mt-3">
            <div class="d-flex justify-content-between align-items-center">
                <label class="mb-0">Balance: </label>
                <p class="form-control-plaintext text-primary fw-bold mb-0"> $<span id="giftCardBalanceText">0.00</span></p>
            </div>
        </div>
        
        
    </div>

    
    <div class="row mt-4">
        <div class="col">
            <label for="orderAmountInput" id="orderLabel">Payment Amount:</label>
            <input type="text" id="orderAmountInput" class="form-control shadow-sm" placeholder="Payment Amount">
        </div>
    </div>

    <div class="row " id="checks" hidden>
        <div class="col-12" id="check3" >
            <label for="orderAmountInput" id="nameLabel">Name of Account:</label>
            <input type="text" id="orderNameInput" class="form-control shadow-sm" placeholder="Name of Account:">
        </div>
        <div class="col-mt-6"  id="check1">
            <label for="orderAmountInput" id="routingLabel">Routing No:</label>
            <input type="text" id="orderRoutingInput" class="form-control shadow-sm" placeholder="Routing No:">
        </div>
        <div class="col-mt-6" id="check2" >
            <label for="orderAmountInput" id="accountLabel">Account No:</label>
            <input type="text" id="orderAccountInput" class="form-control shadow-sm" placeholder="Account No:">
        </div>
      
    </div>

   
   
    <!-- Process Button -->
    <div class="row mt-5">
        <div class="col">
            <button type="submit" class="btn btn-success btn-lg btn-block shadow-sm" id="submitOrderButton">
                <i class="bi bi-check-circle"></i> Process
            </button>
            <button type="submit" class="btn btn-success btn-lg btn-block shadow-sm" id="updateOrderButton" hidden>
                <i class="bi bi-arrow-repeat"></i> Process
            </button>
        </div>
    </div>
</div>

<div id="receiptContainer"></div>

<script>
   

    $("#giftcardno").on("keypress", function(e) {
        if (e.which === 13) {
            lookupGiftCard();
        }
    });

    $("#lookupGiftCardButton").on("click", function () {
        lookupGiftCard();
    });

    function lookupGiftCard() {
        let giftCardNumber = $("#giftcardno").val();
        let totalAmount = parseFloat($("#total-amount").val().replace(/,/g, ""));
        
        if (!giftCardNumber) {
            Swal.fire("Error", "Please enter a gift card number", "error");
            return;
        }

        $.ajax({
            url: processGiftCard,
            type: "POST",
            headers: {
                "X-CSRF-TOKEN": $('meta[name="csrf-token"]').attr("content"),
            },
            data: { gift_card_number: giftCardNumber },
            success: function (response) {
                let giftCardBalance = response.amount;
                $("#giftCardBalanceText").text(giftCardBalance.toFixed(2));

                let amountToSpend = 0;
                if (giftCardBalance > totalAmount) {
                    amountToSpend = totalAmount;
                } else {
                    amountToSpend = giftCardBalance;
                }

                $("#orderAmountInput").val(amountToSpend.toFixed(2));
            },
            error: function () {
                Swal.fire("Error", "Failed to lookup the gift card.", "error");
            },
        });
    }
</script>
