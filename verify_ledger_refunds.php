<?php
use App\Models\Reservation;
use App\Models\ReservationDraft;
use App\Models\Site;
use App\Models\User;
use App\Models\Refund;
use App\Models\Payment;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Carbon\Carbon;

Mail::fake();

// Setup
$customer = User::first();
$site1 = Site::first();
$site2 = Site::skip(1)->first();

echo "Site 1: {$site1->siteid}\n";
echo "Site 2: {$site2->siteid}\n";

$cartId = 'LEDGER-TEST-' . Str::random(5);

// 1. Initial Reservation
echo "Creating initial reservation...\n";
$res1 = Reservation::create([
    'cartid' => $cartId,
    'siteid' => $site1->siteid,
    'customernumber' => $customer->id,
    'fname' => $customer->f_name,
    'lname' => $customer->l_name,
    'email' => $customer->email,
    'cid' => Carbon::today()->addYears(4)->addDays(10)->format('Y-m-d 15:00:00'),
    'cod' => Carbon::today()->addYears(4)->addDays(12)->format('Y-m-d 11:00:00'),
    'total' => 100,
    'totalcharges' => 100,
    'subtotal' => 100,
    'nights' => 2,
    'status' => 'confirmed',
    'confirmation_code' => 'L-CONF-' . Str::random(5),
    'xconfnum' => 'L-XCONF-' . Str::random(5),
    'siteclass' => $site1->siteclass ?? 'RV Site'
]);

// 2. Initial Payment
echo "Recording initial payment...\n";
$payment = Payment::create([
    'cartid' => $cartId,
    'payment' => 100,
    'method' => 'Credit Card',
    'customernumber' => $customer->id,
    'email' => $customer->email,
    'transaction_type' => 'Initial Sale'
]);
$res1->update(['payment_id' => $payment->id]);

// 3. Modification Draft (Remove Site 1, Add Site 2)
echo "Modifying reservation (Remove Site 1, Add Site 2)...\n";
$draftId = (string)Str::uuid();
$cartData = [
    [
        'id' => $site2->siteid,
        'name' => 'Site 2',
        'base' => 120,
        'start_date' => Carbon::today()->addYears(4)->addDays(30)->format('Y-m-d'),
        'end_date' => Carbon::today()->addYears(4)->addDays(32)->format('Y-m-d')
    ]
];

$draft = ReservationDraft::create([
    'draft_id' => $draftId,
    'original_cart_id' => $cartId,
    'customer_id' => $customer->id,
    'cart_data' => $cartData,
    'credit_amount' => 100, // from res1
    'grand_total' => 120,
    'original_reservation_ids' => [$res1->id],
    'status' => 'draft'
]);

// 4. Finalize Modification (Delta +20)
echo "Finalizing modification...\n";
$request = new \Illuminate\Http\Request();
$request->headers->set('X-Requested-With', 'XMLHttpRequest');
$request->headers->set('Accept', 'application/json');
$request->merge([
    'payment_method' => 'Cash',
    'cash_tendered' => 20, 
    'amount' => 20
]);

$controller = app(\App\Http\Controllers\FlowReservationController::class);
$response = $controller->finalizeModification($request, $draftId);

if (is_object($response) && method_exists($response, 'getContent')) {
    echo "Response: " . $response->getContent() . "\n";
} else {
    echo "Response: " . print_r($response, true) . "\n";
}

// 5. Verify Ledger
echo "\n--- LEDGER VERIFICATION ---\n";
// Call AdminReservationController show logically
$resController = app(\App\Http\Controllers\AdminReservationController::class);
$view = $resController->show(new \Illuminate\Http\Request(), $res1->id);
$data = $view->getData();
$reservations = $data['reservations'];
$ledger = $data['ledger'];
$netTotal = $data['netTotal'];
$balanceDue = $data['balanceDue'];

echo "\nReservations linked to this group: " . $reservations->count() . "\n";
foreach ($reservations as $r) {
    echo "- ID: {$r->id}, Site: {$r->siteid}, Status: {$r->status}, Group: {$r->group_confirmation_code}, Cart: {$r->cartid}\n";
}

echo "\nLedger Items Found: " . $ledger->count() . "\n";
foreach ($ledger as $item) {
    echo sprintf("[%s] [%s] %-40s: %+.2f (Cur: %s)\n", 
        $item['date'], 
        $item['type'],
        $item['description'], 
        $item['amount'], 
        $item['running_balance']
    );
}

echo "\nSummary Totals:\n";
echo "Net Total (Charges+Payments+Refunds): {$netTotal}\n";
echo "Balance Due: {$balanceDue}\n";

$refunds = Refund::where('cartid', $draftId)->get();
echo "\nRefunds in DB for Cart {$draftId}:\n";
foreach ($refunds as $rf) {
    echo "- Method: {$rf->method}, Amount: {$rf->amount}, Reason: {$rf->reason}\n";
}

if ($balanceDue == 0) {
    echo "\nSUCCESS: Ledger and refunds correctly recorded!\n";
} else {
    echo "\nFAILURE: Balance is not zero: {$balanceDue}\n";
}
