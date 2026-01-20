$(document).ready(function () {
    $(".paymentMethod").on("click", function () {
        let label = $("#orderLabel");
        let input = $("#orderAmountInput");
        if ($(this).val() === "GiftCard") {
            $("#gift-card-section").removeAttr("hidden");
            $("#credit-card-section").attr("hidden", true);
            label.show().text("Payment Amount: ");
            $("#giftcardno").removeAttr("readonly").focus();
            input.attr("placeholder", "Payment Amount").removeAttr("readonly");
            $("#expire").attr("hidden", true);

            $("#checks").attr("hidden", true);

        } else if ($(this).val() === "CreditCard") {
            $("#gift-card-section").attr("hidden", true);
            $("#credit-card-section").removeAttr("hidden");
            label.show().text("Payment Amount: ");
            input.attr("placeholder", "Payment Amount").removeAttr("readonly");

            $("#checks").attr("hidden", true);

        } else if ($(this).val() === 'Check') {
            $("#gift-card-section").attr("hidden", true);
            $("#credit-card-section").attr("hidden", true);
            $("#checks").attr("hidden", false);


            label.show().text("Check Amount");
            input.attr("placeholder", "Check Amount").removeAttr("readonly");

        } else {

            label.show().text("Payment Amount: ");
            input.attr("placeholder", "Payment Amount").removeAttr("readonly");
            $("#gift-card-section").attr("hidden", true);
            $("#credit-card-section").attr("hidden", true);

            $("#checks").attr("hidden", true);


        }

        $(".btn-payment").removeClass("active");
        $(this).closest("label").addClass("active");
    });

    function appendToPaymentHistory(method, amount) {
        if (!$('#paymentList').length) {
            $('#paymentHistory').append(`<ul class="list-group" id="paymentList"></ul>`);
        }
        $("#paymentList").append(`
            <li class="list-group-item d-flex justify-content-between">
                <span>${method}</span>
                <span>$${parseFloat(amount).toFixed(2)}</span>
            </li>
        `);
    }

    function handlePayment(
        customer_id,
        amount,
        change,
        paymentMethod,
        number,
        x_ref_num,
        totalAmount,
        isPartial,
        orderId,
        customer_email,
        jsonResponse,
        extraData = {} // New parameter for extra fields
    ) {
        let remainingBalance =
            parseFloat($("#remainingBalance").text()) || totalAmount;
        let firstRemainingBalance = remainingBalance - amount;
        $("#remainingBalance").text(firstRemainingBalance.toFixed(2));
        const isStep2 = window.location.href.includes('step-2');
        const titleText = isStep2
            ? `Change due: $${Math.max(0, change).toFixed(2)}\nDo you want to complete this sale?`
            : (change >= 0
                ? `Change is: $${change.toFixed(2)}. Do you want to proceed?`
                : `Partial payment made! Remaining balance is: $${firstRemainingBalance.toFixed(2)}`);

        Swal.fire({
            title: titleText,
            showCancelButton: true,
            confirmButtonText: isStep2 ? "Complete Sale" : "Save",
            cancelButtonText: isStep2 ? "Cancel" : `Don't save`,
            showLoaderOnConfirm: true,
            preConfirm: () => {
                return new Promise((resolve) => {
                    $.ajax({
                        url: isPartial ? cartOrderUpdateUrl : cartOrderStoreUrl,
                        type: "POST",
                        headers: {
                            "X-CSRF-TOKEN": $('meta[name="csrf-token"]').attr(
                                "content"
                            ),
                        },
                        data: {
                            order_id: orderId,
                            amount: amount,
                            customer_id: customer_id ?? 0,
                            payment_method: paymentMethod,
                            acc_number: number,
                            x_ref_num: x_ref_num,
                            ...extraData // Spread extra data into the request
                        },
                        success: function (response) {
                            if (response.success === false) {
                                Swal.fire("Error", response.message || "An error occurred", "error");
                                resolve(false);
                                return;
                            }

                            resolve(response);
                            appendToPaymentHistory(paymentMethod, amount);

                            if (!isPartial) {
                                orderId = response.order_id;
                                $("#order_id").val(orderId);
                                $("#orderAmountInput").val("");
                                $("#giftcardno").val("");
                                $('input[name="payment_method"]:checked').prop(
                                    "checked",
                                    false
                                );

                                handleCardsOnFiles(
                                    customer_id,
                                    orderId,
                                    jsonResponse ? jsonResponse.xInvoice : '',
                                    customer_email,
                                    jsonResponse ? jsonResponse.xMaskedCardNumber : '',
                                    jsonResponse ? jsonResponse.xCardType : '',
                                    jsonResponse ? jsonResponse.xToken : '',
                                    jsonResponse ? jsonResponse.xResult : '',
                                    jsonResponse ? jsonResponse.xStatus : '',
                                    jsonResponse ? jsonResponse.xErrorCode : '',
                                    jsonResponse ? jsonResponse.xName : '',

                                );

                            } else {
                                $("#orderAmountInput").val("");
                                $("#giftcardno").val("");
                                $('input[name="payment_method"]:checked').prop(
                                    "checked",
                                    false
                                );
                                orderId = response.OrderItem.order_id;
                                $("#order_id").val(orderId);

                                handleCardsOnFiles(
                                    customer_id,
                                    orderId,
                                    jsonResponse.xInvoice,
                                    customer_email,
                                    jsonResponse.xMaskedCardNumber,
                                    jsonResponse.xCardType,
                                    jsonResponse.xToken,
                                    jsonResponse.xResult,
                                    jsonResponse.xStatus,
                                    jsonResponse.xErrorCode,

                                );
                            }


                        },
                        error: function (reject) {
                            let msg = "An error occurred";
                            if (reject.responseJSON && reject.responseJSON.message) {
                                msg = reject.responseJSON.message;
                            }
                            Swal.fire("Error", msg, "error");
                            resolve(false);
                        },
                    });
                });
            },
        }).then((result) => {
            if (result.isConfirmed && result.value) {
                let response = result.value;

                finalizeOrder(
                    amount,
                    totalAmount,
                    response,
                    firstRemainingBalance,
                    customer_email,
                    orderId
                );
            } else if (result.dismiss === Swal.DismissReason.cancel) {
                Swal.fire("Changes are not saved", "", "info");
            }
        });
    }

    $("#cardExpiry").on("input", function (e) {
        let input = e.target.value;

        input = input.replace(/[^\d\/]/g, "");

        if (input.length > 2 && input[2] !== "/") {
            input = input.slice(0, 2) + "/" + input.slice(2);
        }

        if (input.length > 5) {
            input = input.slice(0, 5);
        }

        e.target.value = input;
    });

    const offcanvasEl = document.getElementById("offcanvasOrder");
    if (offcanvasEl) {
        var offcanvasOrder = new bootstrap.Offcanvas(offcanvasEl, {
            backdrop: false,
            keyboard: false,
        });
    }
    $("#submitOrderButton").on("click", function () {
        processPayment();
    });

    $("#updateOrderButton").on("click", function (e) {
        e.preventDefault();
        processPayment(true);
    });

    $("#email_invoice").on("input", function () {
        let cust_email = $(this).val();
        $("#cust_email").val(cust_email);
    });
    function processPayment(isPartial = false) {
        let totalAmount = parseFloat(
            $("#total-amount").val().replace(/,/g, "")
        );
        let amount = parseFloat($("#orderAmountInput").val().replace(/,/g, ""));
        let customer_id = $("#customer_id").val();
        let paymentMethod = $('input[name="payment_method"]:checked').val();
        let customer_email = $("#cust_email").val();

        let orderId = $("#order_id").val();



        let xname = $("#orderNameInput").val();
        let xrouting = $("#orderRoutingInput").val();
        let xaccount = $("#orderAccountInput").val();


        let change = 0;
        if (paymentMethod === "Cash") {
            if (amount > totalAmount) {
                change = amount - totalAmount;
            }
            handlePayment(
                customer_id,
                amount,
                change,
                paymentMethod,
                0,
                0,
                totalAmount,
                isPartial,
                orderId,
                customer_email
            );
        } else if (paymentMethod === "GiftCard") {
            let giftCardNumber = $("#giftcardno").val();
            processGiftCardPayment(giftCardNumber, customer_id, amount, totalAmount, isPartial, orderId, customer_email);

        } else if (paymentMethod === "CreditCard") {
            processCreditCardPayment(customer_id, amount, totalAmount, isPartial, orderId, customer_email);

        } else if (paymentMethod === 'Check') {
            processCheckPayment(customer_id, amount, totalAmount, isPartial, orderId, customer_email, xname, xrouting, xaccount);
        }
    }



    function processCheckPayment(customer_id, amount, totalAmount, isPartial, orderId, customer_email, xname, xrouting, xaccount) {
        $.ajax({
            url: processingCheckPayment,
            type: 'GET',
            dataType: 'json',
            data: {

                xAmount: amount,
                xName: xname,
                xRouting: xrouting,
                xAccount: xaccount,
            },
            headers: {
                'X-CSRF-TOKEN': $('meta[name="csrf-token"]').attr('content'),
            },
            success: function (response) {
                console.log('Check response', resp)

                if (response.xResult === 'A') {
                    handlePayment(
                        customer_id,
                        response.xAuthAmount,
                        0,
                        'Check',
                        response.xMaskedAccountNumber,
                        response.xRefNum,
                        totalAmount,
                        isPartial,
                        orderId,
                        customer_email
                    )
                } else {
                    Swal.fire(
                        "Error",
                        `${response.xError}`,
                        "error"
                    );

                    console.log('Response:', response);
                }
            },
            error: function (xhr, error) {
                console.error('Error', xhr, error);
            }
        });
    }

    function processGiftCardPayment(

        giftCardNumber,
        customer_id,
        amount,
        totalAmount,
        isPartial,
        orderId,
        customer_email,

    ) {
        $.ajax({
            url: processGiftCard,
            type: "POST",
            headers: {
                "X-CSRF-TOKEN": $('meta[name="csrf-token"]').attr("content"),
            },
            data: { gift_card_number: giftCardNumber },
            success: function (response) {
                let giftCardAmount = response.amount;
                if (giftCardAmount >= amount) {
                    let remainingBalance = giftCardAmount - amount;
                    $.ajax({
                        url: updateGiftCardBalance,
                        type: "POST",
                        headers: {
                            "X-CSRF-TOKEN": $('meta[name="csrf-token"]').attr(
                                "content"
                            ),
                        },
                        data: {
                            gift_card_number: giftCardNumber,
                            remaining_balance: remainingBalance,
                        },
                        success: function () {
                            handlePayment(
                                customer_id,
                                amount,
                                0,
                                "GiftCard",
                                0,
                                0,
                                totalAmount,
                                isPartial,
                                orderId
                            );
                        },
                        error: function () {
                            Swal.fire(
                                "Error",
                                "Failed to update the gift card balance.",
                                "error"
                            );
                        },
                    });
                } else {
                    Swal.fire(
                        "Gift card Balance is insufficient",
                        `The gift card balance is $${giftCardAmount.toFixed(
                            2
                        )}.`,
                        "warning"
                    );
                }
            },
            error: function () {
                Swal.fire("Error", "Failed to process the gift card.", "error");
            },
        });
    }

    function processCreditCardPayment(
        customer_id,
        amount,
        totalAmount,
        isPartial,
        orderId,
        customer_email
    ) {
        let xCardNum = $('#xCardNum').val();
        let xExp = $('#xExp').val();

        if (!xCardNum || !xExp) {
            Swal.fire("Error", "Please enter Card Number and Expiry Date", "error");
            return;
        }

        handlePayment(
            customer_id,
            amount,
            0,
            "CreditCard",
            xCardNum, // Sending Card Num as primary 'number'
            0,
            totalAmount,
            isPartial,
            orderId,
            customer_email,
            null,
            { xCardNum: xCardNum, xExp: xExp } // Sending both as extra fields to be sure
        );
    }

    function handleCardsOnFiles(
        customer_id,
        orderId,
        invoice,
        customer_email,
        cardNumber,
        cardType,
        token,
        result,
        status,
        errorCode,
        name
    ) {
        $.ajax({
            url: insertCardsOnFiles,
            type: 'POST',
            data: {
                customernumber: customer_id,
                cartid: invoice,
                receipt: orderId,
                email: customer_email,
                xmaskedcardnumber: cardNumber,
                method: cardType,
                xtoken: token,
                name: name,
                gateway_response: JSON.stringify({
                    result: result,
                    status: status,
                    errorCode: errorCode
                }),
            },
            headers: {
                "X-CSRF-TOKEN": $('meta[name="csrf-token"]').attr("content"),
            },
            success: function (response) {
                console.log('Success', response);
            },
            error: function (xhr, error) {
                console.error('Error', xhr, error);
            }
        })
    }




    function finalizeOrder(
        order_amount,
        totalAmount,
        response,
        firstRemainingBalance,
        customer_email,
        orderId
    ) {
        var offcanvas = bootstrap.Offcanvas.getInstance(
            document.getElementById("offcanvasOrder"),
            { backdrop: false }
        );
        $("#offcanvasOrder").on("hide.bs.offcanvas", function (e) {
            e.preventDefault();
        });

        $("#submitOrderButton").hide();
        $("#updateOrderButton").attr("hidden", false);

        if (
            order_amount >= totalAmount
            // response.totalpayAmount.amount >= response.OrderItem.price
        ) {
            offcanvas.hide();
            $("#selected-product tbody").empty();
            $("#card-summary").empty();

            if (window.skipReceiptAndEmail || window.location.href.includes('step-2')) {
                let successMsg = window.checkoutSuccessMessage || "Order placed successfully!";
                toastr.success(successMsg, "Success", {
                    positionClass: "toast-top-right",
                    timeOut: 2000
                });
                setTimeout(function () {
                    clearInputFields(true);
                    if (window.checkoutSuccessRedirectUrl) {
                        window.location.href = window.checkoutSuccessRedirectUrl;
                    } else if (window.location.href.includes('step-2')) {
                        // Fallback redirect if variable missing
                        window.location.href = '/admin/flow-reservation/step-1';
                    } else {
                        window.location.reload();
                    }
                }, 3000);
                return;
            }

            $.ajax({
                url: window.getProductForReceiptUrl || 'cart/get-product-for-receipt',
                type: "GET",
                data: { order_id: orderId },
                success: function (orders_response) {
                    receiptPrint(
                        order_amount,
                        totalAmount,
                        orderId,
                        customer_email,
                        response,
                        orders_response,
                    );
                }
            });

            if (customer_email && customer_email.trim() !== '') {
                sendInvoiceEmail(customer_email, orderId);
            }

        } else {
            $.toast({
                heading: response[0] || "Success",
                text: `Partial Payment only. Remaining balance: $${firstRemainingBalance.toFixed(
                    2
                )}`,
                position: "top-right",
                loaderBg: "#00c263",
                icon: "success",
                hideAfter: 3000,
                stack: 6,
            });
            $("#submitOrderButton").hide();
            $("#updateOrderButton").attr("hidden", false);
        }
    }

    function receiptPrint(order_amount, totalAmount, orderId, customer_email, response, orders_response) {

        let storedLogo = localStorage.getItem("receiptLogo");
        let storedHeaderText = localStorage.getItem("receiptHeaderText") || "";
        let storedFooterText = localStorage.getItem("receiptFooterText") || "";

        let receiptDetails = `
        <html>
        <head>
            <title>Receipt</title>
            <style>
                @page {
                    size: 80mm auto; /* Adjusted for dynamic height */
                    margin: 0;
                }
                body {
                    font-family: Arial, sans-serif;
                    font-size: 12px;
                    width: 72mm; /* Usable width for 80mm printer */
                    margin: 0;
                    padding: 5px;
                    display: flex;
                    flex-direction: column;
                    align-items: center;
                }
                h2, h3 {
                    text-align: center;
                    margin: 5px 0;
                }
                .center {
                    text-align: center;
                }
                .bold {
                    font-weight: bold;
                }
                .receipt-logo {
                    max-width: 70mm; /* Keep logo within 72mm limit */
                    height: auto;
                    display: block;
                    margin: 0 auto 5px;
                }
                table {
                    width: 100%;
                    border-collapse: collapse;
                }
                th, td {
                    padding: 3px;
                    text-align: left;
                    font-size: 11px;
                }
                hr {
                    border: none;
                    border-top: 1px dashed #000;
                    margin: 5px 0;
                }
            </style>
        </head>
        <body>
            ${storedLogo ? `<img class="receipt-logo" src="/storage/receipt_logos/${storedLogo}" alt="Logo">` : ""}
            <p class="bold center">${storedHeaderText}</p>
            <h2>Order Receipt</h2>
            <p><strong>Order ID:</strong> ${orderId}</p>
            <p><strong>Customer Email:</strong> ${(customer_email && customer_email !== 'undefined') ? customer_email : 'N/A'}</p>
            <p><strong>Date:</strong> ${new Date().toLocaleString()}</p>
            <hr>
            <h3>Product Details</h3>
            <table>
                <thead>
                    <tr>
                        <th>Product</th>
                        <th>Qty</th>
                        <th>Price</th>
                        <th>Total</th>
                    </tr>
                </thead>
                <tbody>
        `;

        if (Array.isArray(orders_response.products) && orders_response.products.length > 0) {
            orders_response.products.forEach((item) => {
                let totalPrice = (item.quantity * item.price) + (item.tax || 0) - (item.discount || 0);
                receiptDetails += `
                <tr>
                    <td>${item.product_name}</td>
                    <td class="center">${item.quantity}</td>
                    <td class="bold">$${parseFloat(item.price).toFixed(2)}</td>
                    <td class="bold">$${totalPrice.toFixed(2)}</td>
                </tr>
                `;
            });
        } else {
            receiptDetails += `
            <tr>
                <td colspan="4" class="center">No items found.</td>
            </tr>
            `;
        }

        receiptDetails += `
                </tbody>
            </table>
            <hr>
            <p class="bold"><strong>Total Amount:</strong> $${totalAmount.toFixed(2)}</p>
            <p class="bold"><strong>Paid Amount:</strong> $${!isNaN(parseFloat(order_amount)) ? parseFloat(order_amount).toFixed(2) : "0.00"}</p>
            <p class="bold"><strong>Change:</strong> $${Math.abs(order_amount - totalAmount).toFixed(2)}</p>
            <hr>
            <p class="center">${storedFooterText}</p>
        </body>
        <script>
            window.onload = function() {
                window.print();
                setTimeout(function () { window.close(); }, 1000);
            }
        </script>
        </html>
        `;


        try {
            var printWindow = window.open('', '', 'width=400,height=600');
            if (printWindow) {
                printWindow.document.write(receiptDetails);
                printWindow.document.close();
            } else {
                console.warn("Popup blocked. Receipt could not be printed.");
            }
        } catch (e) {
            console.error("Error attempting to print receipt:", e);
        }

        let successMsg = window.checkoutSuccessMessage || "Order placed successfully!";
        toastr.success(successMsg, "Success", {
            positionClass: "toast-top-right",
            timeOut: 2000
        });

        setTimeout(function () {
            clearInputFields(true);
            if (window.checkoutSuccessRedirectUrl) {
                window.location.href = window.checkoutSuccessRedirectUrl;
            } else {
                window.location.reload();
            }
        }, 1500);
    }




    function clearInputFields(isFullPayment = false) {
        $("#orderAmountInput").val("");
        $("#giftcardno").val("");
        $('input[name="payment_method"]:checked').prop("checked", false);
    }

    function sendInvoiceEmail(customer_email, orderId) {
        if (!customer_email || !customer_email.includes("@")) {
            console.error("Invalid customer email:", customer_email);
            return;
        }

        $.ajax({
            url: sentInvoiceEmail,
            type: "POST",
            headers: {
                "X-CSRF-TOKEN": $('meta[name="csrf-token"]').attr("content"),
            },
            data: {
                email: customer_email,
                order_id: orderId,
            },
            success: function (response) {
                console.log(response.message);
            },
            error: function (xhr) {
                // Silently fail or log to console only, per user request to avoid annoying errors
                console.error("Failed to send invoice email", xhr);
            },
        });
    }

    $('#customer_id').on('change', function () {
        let customer_email = $(this).find('option:selected').data('email');
        $('#cust_email').val(customer_email);
        $('#email_invoice').val(customer_email);
    });

    const totalAmountEl = $("#total-amount");
    if (totalAmountEl.length) {
        let totalAmount = parseFloat(totalAmountEl.val().replace(/,/g, ""));
        $("#displayTotalAmount").text(totalAmount.toFixed(2));
        $("#remainingBalance").text(totalAmount.toFixed(2));
    }

    if (!$('#paymentHistory').length) {
        const paymentHtml = `
            <div id="paymentHistory" class="col-sm-12 border-top">
                <div class="row pt-2">
                    <div class="col-6">
                        <p><b>Payments Made</b></p>
                    </div>
                    <div class="col-6">
                        <ul class="list-group mb-0" id="paymentList"></ul>
                    </div>
                </div>
            </div>
        `;

        // Insert after the "Total" row
        $("#displayTotalAmount").closest('.col-sm-12').after(paymentHtml);
    }

});


