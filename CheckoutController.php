<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Schema;
use App\Model\{
    BusinessSetting, Coupon, Addon, Site, Reservation, Receipt, CardsOnFile, Payment, User
};
use App\Mail\ReservationConfirmation;
use App\Jobs\Front\SendRegisterCheckoutJob;
use Carbon\Carbon;
use Exception;
use Mail;
use Illuminate\Support\Facades\Validator;
use App\Services\ConfirmationCodeService;
use App\Services\BookingContext;

class CheckoutController extends Controller
{
    protected $reservation;
    protected $receipt;
    protected $cardsOnFile;
    protected $payment;
    protected $user;
    protected ConfirmationCodeService $confirmationCodes;

    public function __construct(
        Reservation $reservation,
        Receipt $receipt,
        CardsOnFile $cardsOnFile,
        Payment $payment,
        User $user,
        ConfirmationCodeService $confirmationCodes
    ) {
        $this->reservation  = $reservation;
        $this->receipt      = $receipt;
        $this->cardsOnFile  = $cardsOnFile;
        $this->payment      = $payment;
        $this->user         = $user;
        $this->confirmationCodes = $confirmationCodes;

    }

    /**
     * POST /api/checkout
     * Performs a full checkout process via API.
     */
//     public function checkout(Request $request)
// {
//     // ✅ 1. Manual validator with JSON error response
//     $validator = Validator::make($request->all(), [
//         'fname'               => 'required|string|max:100',
//         'lname'               => 'required|string|max:100',
//         'email'               => 'required|email',
//         'phone'               => 'required|string|max:20',
//         'street_address'      => 'required|string|max:255',
//         'city'                => 'required|string|max:100',
//         'state'               => 'required|string|max:50',
//         'zip'                 => 'required|string|max:10',

//         'xCardNum'            => 'required|digits_between:13,19',
//         'xExp'                => ['required', 'regex:/^(0[1-9]|1[0-2])\/?([0-9]{2})$/'],
//         'xAmount'             => 'required|numeric|min:0.5',

//         'applicable_coupon'   => 'nullable|string|max:50',

//         'api_cart.cart_id'    => 'required|string',
//         'api_cart.cart_token' => 'required|string',
//     ]);

//     if ($validator->fails()) {
//         return response()->json([
//             'status'  => 'error',
//             'message' => 'The given data was invalid.',
//             'errors'  => $validator->errors(),
//         ], 422);
//     }

//     $validated = $validator->validated();

//     // ✅ 2. Maintenance guard (already API-style)
//     $maintenance_mode = BusinessSetting::where('type', 'maintenance_mode')->first();
//     if ($maintenance_mode && $maintenance_mode->value) {
//         return response()->json([
//             'status'  => 'error',
//             'message' => 'This site is undergoing maintenance. Please try again later.',
//         ], 503);
//     }

// // dd($validated);
//     try {
//         DB::beginTransaction();

//         // ✅ 3. Ensure a customer exists or create one
//         if (! auth('customer')->user()) {
//             $existingUser = $this->user->whereFirst(['email' => $validated['email']]);

//             if (! $existingUser) {
//                 $password = rand(100000000, 999999999);

//                 $data = [
//                     'email'          => $validated['email'],
//                     'f_name'         => $validated['fname'],
//                     'l_name'         => $validated['lname'],
//                     'phone'          => $validated['phone'],
//                     'password'       => $password,
//                     'street_address' => $validated['street_address'],
//                     'state'          => $validated['state'],
//                     'city'           => $validated['city'],
//                     'zip'            => $validated['zip'],
//                 ];

//                 dispatch(new SendRegisterCheckoutJob($data));

//                 $data['password'] = bcrypt($password);
//                 $user = $this->user->storeUser($data);
//             } else {
//                 $user = $existingUser;
//             }
//         } else {
//             $user = auth('customer')->user();
//         }

//         // ✅ 4. Get cart info from request (already JSON style)
//         $apiCart = $validated['api_cart'] ?? null;
//         if (empty($apiCart['cart_id']) || empty($apiCart['cart_token'])) {
//             return response()->json([
//                 'status'  => 'error',
//                 'message' => 'Missing or invalid cart credentials.',
//             ], 400);
//         }

//         $apiBase = rtrim(config('services.flow.base_url', env('BOOK_API_BASE', 'https://book.kayuta.com')), '/');

//         $res = Http::timeout(10)->acceptJson()
//             ->withToken(env('BOOK_API_KEY'))
//             ->get("{$apiBase}/api/v1/cart/{$apiCart['cart_id']}", [
//                 'cart_token' => (string) $apiCart['cart_token'],
//             ]);

//         if (! $res->successful()) {
//             return response()->json([
//                 'status'  => 'error',
//                 'message' => 'Unable to load cart data from upstream.',
//                 'upstream_status' => $res->status(),
//             ], 400);
//         }

//         $channelCart = $res->json('data.cart') ?? $res->json('cart') ?? null;
//         $items       = is_array($channelCart['items'] ?? null) ? $channelCart['items'] : [];

//         if (! $channelCart || count($items) === 0) {
//             return response()->json([
//                 'status'  => 'error',
//                 'message' => 'Your cart is empty.',
//             ], 400);
//         }

//         // ✅ 5. Compute amount and coupon discount
//         $amount   = (float) $validated['xAmount'];
//         $discount = 0;

//         if (! empty($validated['applicable_coupon'])) {
//             $coupon = Coupon::where('code', $validated['applicable_coupon'])
//                 ->where('expire_date', '>=', date('Y-m-d'))
//                 ->where('start_date', '<=', date('Y-m-d'))
//                 ->first();

//             if ($coupon && $coupon->min_purchase < $amount) {
//                 if ($coupon->discount_type === 'amount') {
//                     $discount = $coupon->discount;
//                 } elseif ($coupon->discount_type === 'percentage') {
//                     $discount = ($coupon->discount * $amount) / 100;
//                     if ($discount > $coupon->max_discount) {
//                         $discount = $coupon->max_discount;
//                     }
//                 }

//                 $amount = max(0, $amount - $discount);
//             }
//         }

//         // ✅ 6. Process Payment via Cardknox
//         $apiKey     = config('services.cardknox.api_key');
//         $cardNumber = $validated['xCardNum'];
//         $xExp       = str_replace('/', '', $validated['xExp']);

//         $postData = [
//             'xKey'          => $apiKey,
//             'xVersion'      => '4.5.5',
//             'xCommand'      => 'cc:Sale',
//             'xAmount'       => $amount == 0 ? 100 : $amount,
//             'xCardNum'      => $cardNumber,
//             'xExp'          => $xExp,
//             'xSoftwareVersion' => '1.0',
//                 'xSoftwareName'    => 'KayutaLake',
//                 'xInvoice'         => 'RECUR-' . uniqid() . '-' . now()->format('YmdHis'),
//         ];
        
    

//         $ch = curl_init('https://x1.cardknox.com/gateway');
//         curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
//         curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($postData));
//         $response = curl_exec($ch);

//         if ($response === false) {
//             DB::rollBack();
//             return response()->json([
//                 'status'  => 'error',
//                 'message' => 'Unable to contact payment gateway.',
//             ], 502);
//         }

//         parse_str($response, $responseArray);

//         if (($responseArray['xStatus'] ?? '') !== 'Approved') {
//             DB::rollBack();
//             return response()->json([
//                 'status'  => 'error',
//                 'message' => $responseArray['xError'] ?? 'Payment failed',
//                 'gateway' => $responseArray,
//             ], 400);
//         }

//         // ✅ 7. Build reservations from channel items
//         $xAuthCode = $responseArray['xAuthCode'] ?? '';
//         $xToken    = $responseArray['xToken'] ?? '';

//         $carts = collect();
//         foreach ($items as $it) {
//             $snap = $it['price_snapshot'] ?? [];

//             $carts->push((object) [
//                 'cartid'      => 'ch_' . ($it['id'] ?? uniqid()),
//                 'siteid'      => $it['site_id'] ?? null,
//                 'cid'         => $it['start_date'] ?? null,
//                 'cod'         => $it['end_date'] ?? null,
//                 'siteclass'   => data_get($it, 'site.siteclass'),
//                 'total'       => (float) ($it['total'] ?? ($snap['total'] ?? 0)),
//                 'totaltax'    => (float) ($snap['tax'] ?? 0),
//                 'subtotal'    => (float) ($snap['subtotal'] ?? 0),
//                 'nights'      => (int) ($it['nights'] ?? 1),
//                 'hookups'     => data_get($it, 'site.hookup'),
//                 'sitelock'    => (float) ($snap['sitelock_fee'] ?? 0),
//                 'addons_json' => $it['add_ons'] ?? ($it['addons_json'] ?? null),
//             ]);
//         }

//         $reservationIds = [];

//         foreach ($carts as $cart) {
//             $receipt       = $this->receipt->storeReceipt(['cartid' => $cart->cartid]);
//             $addonsPayload = $this->normalizeAddons($cart->addons_json);

//             $reservationData = [
//                 'xconfnum'       => $xAuthCode,
//                 'cartid'         => $cart->cartid,
//                 'source'         => 'Online Booking',
//                 'createdby'      => 'API',
//                 'fname'          => $user->f_name,
//                 'lname'          => $user->l_name,
//                 'customernumber' => $user->id,
//                 'siteid'         => $cart->siteid,
//                 'cid'            => $cart->cid,
//                 'cod'            => $cart->cod,
//                 'siteclass'      => $cart->siteclass,
//                 'totalcharges'   => $cart->total,
//                 'nights'         => $cart->nights,
//                 'subtotal'       => $cart->subtotal,
//                 'totaltax'       => $cart->totaltax,
//                 'ratetier'       => $cart->hookups,
//                 'sitelock'       => $cart->sitelock,
//                 'addons_json'    => $addonsPayload,
//                 'receipt'        => $receipt->id,
//             ];

//             $reservation      = $this->reservation->storeReservation($reservationData);
//             $reservationIds[] = $reservation->id;

//             // Card-on-file + payment
//             $this->cardsOnFile->storeCards([
//                 'customernumber'    => $user->id,
//                 'method'            => $responseArray['xCardType'] ?? 'Card',
//                 'cartid'            => $cart->cartid,
//                 'email'             => $user->email,
//                 'xmaskedcardnumber' => $responseArray['xMaskedCardNumber'] ?? '',
//                 'xtoken'            => $xToken,
//                 'receipt'           => $receipt->id,
//                 'gateway_response'  => json_encode($responseArray),
//             ]);

//             $this->payment->storePayment([
//                 'customernumber' => $user->id,
//                 'method'         => $responseArray['xCardType'] ?? 'Card',
//                 'cartid'         => $cart->cartid,
//                 'email'          => $user->email,
//                 'payment'        => $responseArray['xAuthAmount'] ?? $amount,
//                 'receipt'        => $receipt->id,
//                 'x_ref_num'      => $responseArray['xRefNum'] ?? null,
//             ]);
//         }

//         // ✅ 8. Build confirmation details for response & email
//         $reservations = $this->reservation->getWhereInIds($reservationIds);

//         $details = $reservations->map(function ($res) {
//          return [
//         'site'      => $res->site->sitename ?? 'N/A',
//         'check_in'  => $res->cid,
//         'check_out' => $res->cod,
//         'total'     => $res->total,
//         'addons'    => $res->addons_json,
//       ];
//          })->values()->toArray();


//             $reservationConfirmation = new \App\Mail\ReservationConfirmation($carts, $validated['email'], $details);
//             $reservationConfirmation->send();

//         DB::commit();

//         // ✅ 9. Final API JSON response
//         return response()->json([
//             'status'       => 'success',
//             'message'      => 'Checkout completed successfully.',
//             'discount'     => $discount,
//             'reservations' => $reservations, // you might want to wrap this in a Resource later
//         ], 200);

//     } catch (Exception $e) {
//         DB::rollBack();

//         return response()->json([
//             'status'  => 'error',
//             'message' => 'Server error during checkout.',
//             'error'   => $e->getMessage(), // remove in production or behind DEBUG flag
//         ], 500);
//     }
// }



public function checkout(Request $request)
{
    $validator = Validator::make($request->all(), [
        'fname'          => 'required|string|max:100',
        'lname'          => 'required|string|max:100',
        'email'          => 'required|email',
        'phone'          => 'required|string|max:20',
        'street_address' => 'required|string|max:255',
        'city'           => 'required|string|max:100',
        'state'          => 'required|string|max:50',
        'zip'            => 'required|string|max:10',

        'xAmount'           => 'required|numeric|min:0',
        'applicable_coupon' => 'nullable|string|max:50',

        'api_cart.cart_id'    => 'required|string',
        'api_cart.cart_token' => 'required|string',

        'payment_method' => 'required|in:card,ach,cash,gift_card',

        'xCardNum' => 'required_if:payment_method,card|digits_between:13,19',
        'xExp'     => ['required_if:payment_method,card', 'regex:/^(0[1-9]|1[0-2])\/?([0-9]{2})$/'],

        'ach'          => 'required_if:payment_method,ach|array',
        'ach.name'     => 'required_if:payment_method,ach|string|max:100',
        'ach.routing'  => 'required_if:payment_method,ach|digits:9',
        'ach.account'  => 'required_if:payment_method,ach|string|min:4|max:17',

        'gift_card_code' => 'required_if:payment_method,gift_card|string|max:50',

        'cash_tendered' => 'required_if:payment_method,cash|numeric|min:0.01',
    ]);

    if ($validator->fails()) {
        return response()->json([
            'ok'      => false,
            'message' => 'The given data was invalid.',
            'errors'  => $validator->errors(),
        ], 422);
    }

    $validated     = $validator->validated();
    $paymentMethod = $validated['payment_method'];

    $maintenance_mode = BusinessSetting::where('type', 'maintenance_mode')->first();
    if ($maintenance_mode && $maintenance_mode->value) {
        return response()->json([
            'ok'      => false,
            'message' => 'This site is undergoing maintenance. Please try again later.',
            'errors'  => [],
        ], 503);
    }

    // ✅ helper: column exists?
    $hasColumn = function (string $table, string $column): bool {
        try { return Schema::hasColumn($table, $column); } catch (\Throwable $e) { return false; }
    };

    // ✅ helper: normalize addons safely
    $normalizeAddons = function ($addons) {
        if (empty($addons)) return null;

        if (is_string($addons)) {
            $decoded = json_decode($addons, true);
            return is_array($decoded) ? $decoded : null;
        }

        if (is_array($addons)) return $addons;

        return null;
    };

    try {
        DB::beginTransaction();

        // ✅ Customer (API doesn't login)
        $existingUser = $this->user->whereFirst(['email' => $validated['email']]);

        if (!$existingUser) {
            $password = rand(100000000, 999999999);
            $data = [
                'email'          => $validated['email'],
                'f_name'         => $validated['fname'],
                'l_name'         => $validated['lname'],
                'phone'          => $validated['phone'],
                'password'       => $password,
                'street_address' => $validated['street_address'],
                'state'          => $validated['state'],
                'city'           => $validated['city'],
                'zip'            => $validated['zip'],
            ];

            dispatch(new SendRegisterCheckoutJob($data));
            $data['password'] = bcrypt($password);
            $user = $this->user->storeUser($data);
        } else {
            $user = $existingUser;
        }

        // ✅ Upstream cart
        $apiCart = $validated['api_cart'];
        $apiBase = rtrim(config('services.flow.base_url', env('BOOK_API_BASE', 'https://book.kayuta.com')), '/');

        $res = Http::timeout(10)->acceptJson()
            ->withToken(env('BOOK_API_KEY'))
            ->get("{$apiBase}/api/v1/cart/{$apiCart['cart_id']}", [
                'cart_token' => (string) $apiCart['cart_token'],
            ]);

        if (!$res->successful()) {
            DB::rollBack();
            return response()->json([
                'ok'      => false,
                'message' => 'Unable to load cart data from upstream.',
                'errors'  => ['upstream_status' => $res->status()],
            ], 400);
        }

        $channelCart = $res->json('data.cart') ?? $res->json('cart') ?? null;
        $items       = is_array($channelCart['items'] ?? null) ? $channelCart['items'] : [];

        if (!$channelCart || count($items) === 0) {
            DB::rollBack();
            return response()->json([
                'ok'      => false,
                'message' => 'Your cart is empty.',
                'errors'  => [],
            ], 400);
        }

        $amount = (float) $validated['xAmount'];

        // ✅ Payment gateway variables
        $gatewayResponse    = [];
        $xAuthCode          = '';
        $xToken             = '';
        $xRefNum            = null;
        $maskedCardNumber   = '';
        $paymentMethodLabel = '';
        $paidAmount         = $amount;
        $storeCardOnFile    = false;
        $paymentMeta        = [];

        if ($paymentMethod === 'card') {
            $apiKey     = config('services.cardknox.api_key');
            $cardNumber = $validated['xCardNum'];
            $xExp       = str_replace('/', '', $validated['xExp']);

            $postData = [
                'xKey'             => $apiKey,
                'xVersion'         => '4.5.5',
                'xCommand'         => 'cc:Sale',
                'xAmount'          => $amount == 0 ? 100 : $amount,
                'xCardNum'         => $cardNumber,
                'xExp'             => $xExp,
                'xSoftwareVersion' => '1.0',
                'xSoftwareName'    => 'KayutaLake',
                'xInvoice'         => 'RECUR-' . uniqid() . '-' . now()->format('YmdHis'),
            ];

            $ch = curl_init('https://x1.cardknox.com/gateway');
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($postData));
            curl_setopt($ch, CURLOPT_HTTPHEADER, [
                'Content-type: application/x-www-form-urlencoded',
                'X-Recurring-Api-Version: 1.0',
            ]);

            $response = curl_exec($ch);
            if ($response === false) {
                DB::rollBack();
                return response()->json([
                    'ok'      => false,
                    'message' => 'Unable to contact payment gateway.',
                    'errors'  => [],
                ], 502);
            }

            parse_str($response, $responseArray);
            $gatewayResponse = $responseArray;

            if (($responseArray['xStatus'] ?? '') !== 'Approved') {
                DB::rollBack();
                return response()->json([
                    'ok'      => false,
                    'message' => $responseArray['xError'] ?? 'Payment failed',
                    'errors'  => ['gateway' => $responseArray],
                ], 400);
            }

            $xAuthCode          = $responseArray['xAuthCode'] ?? '';
            $xToken             = $responseArray['xToken'] ?? '';
            $xRefNum            = $responseArray['xRefNum'] ?? null;
            $maskedCardNumber   = $responseArray['xMaskedCardNumber'] ?? '';
            $paidAmount         = (float) ($responseArray['xAuthAmount'] ?? $amount);
            $paymentMethodLabel = $responseArray['xCardType'] ?? 'Card';
            $storeCardOnFile    = true;

            $paymentMeta = [
                'type'        => 'card',
                'masked_card' => $maskedCardNumber,
            ];

        } elseif ($paymentMethod === 'cash') {
            $cashTendered = (float) $validated['cash_tendered'];
            if ($cashTendered < $amount) {
                DB::rollBack();
                return response()->json([
                    'ok'      => false,
                    'message' => 'Cash tendered must be at least the total amount.',
                    'errors'  => ['cash_tendered' => ['Not enough cash tendered']],
                ], 422);
            }

            $xAuthCode          = 'CASH-' . now()->format('YmdHis') . '-' . uniqid();
            $paymentMethodLabel = 'Cash';
            $paidAmount         = $amount;
            $paymentMeta = [
                'type'          => 'cash',
                'cash_tendered' => $cashTendered,
                'change'        => $cashTendered - $amount,
            ];

        } else {
            // ACH / Gift Card placeholders
            $xAuthCode          = strtoupper($paymentMethod) . '-' . strtoupper(uniqid());
            $paymentMethodLabel = ucfirst(str_replace('_', ' ', $paymentMethod));
            $paidAmount         = $amount;
            $paymentMeta = ['type' => $paymentMethod];
        }

        // ✅ Group confirmation code (use only if column exists)
        $groupConfirmationCode = 'CONF-' . strtoupper(substr(md5(uniqid('', true)), 0, 10));

        // ✅ Build carts
        $carts = collect();
        foreach ($items as $it) {
            $snap = $it['price_snapshot'] ?? [];
            $carts->push((object)[
                'cartid'      => 'ch_' . ($it['id'] ?? uniqid()),
                'siteid'      => $it['site_id'] ?? null,
                'cid'         => $it['start_date'] ?? null,
                'cod'         => $it['end_date'] ?? null,
                'siteclass'   => data_get($it, 'site.siteclass'),
                'total'       => (float) ($it['grand_total'] ?? ($snap['grand_total'] ?? 0)),
                'totaltax'    => (float) ($snap['tax_total'] ?? 0),
                'subtotal'    => (float) ($snap['sub_total'] ?? 0),
                'nights'      => (int) ($it['nights'] ?? 1),
                'hookups'     => data_get($it, 'site.hookup'),
                'sitelock'    => (float) ($snap['sitelock_fee'] ?? 0),
                'addons_json' => $it['add_ons'] ?? ($it['addons_json'] ?? null),
            ]);
        }

        // ✅ Create reservations
        $reservationIds = [];
        $allCartIds     = [];
        $allReceipts    = [];

        foreach ($carts as $cart) {
            $receipt       = $this->receipt->storeReceipt(['cartid' => $cart->cartid]);
            $addonsPayload = $normalizeAddons($cart->addons_json);

            // scheduled cid/cod with rate tier times
            $site     = \App\Model\Site::where('siteid', $cart->siteid)->first();
            $rateTier = $site ? \App\Model\RateTier::where('tier', $site->hookup)->first() : null;

            $inDate  = $cart->cid ? \Carbon\Carbon::parse($cart->cid)->format('Y-m-d') : null;
            $outDate = $cart->cod ? \Carbon\Carbon::parse($cart->cod)->format('Y-m-d') : null;

            $inTime = ($rateTier && !empty($rateTier->check_in))
                ? \Carbon\Carbon::parse($rateTier->check_in)->format('H:i:s')
                : '15:00:00';

            $outTime = ($rateTier && !empty($rateTier->check_out))
                ? \Carbon\Carbon::parse($rateTier->check_out)->format('H:i:s')
                : '11:00:00';

            $scheduledCid = $inDate  ? "{$inDate} {$inTime}"   : null;
            $scheduledCod = $outDate ? "{$outDate} {$outTime}" : null;

            $reservationData = [
                'xconfnum'       => $xAuthCode,
                'cartid'         => $cart->cartid,
                'source'         => 'Online Booking',
                'createdby'      => 'API',
                'fname'          => $user->f_name,
                'lname'          => $user->l_name,
                'customernumber' => $user->id,
                'siteid'         => $cart->siteid,

                'cid'            => $scheduledCid,
                'cod'            => $scheduledCod,
                'checkedin'      => null,
                'checkedout'     => null,

                'siteclass'      => $cart->siteclass,
                'totalcharges'   => $cart->total,
                'total'          => $cart->total,
                'nights'         => $cart->nights,
                'subtotal'       => $cart->subtotal,
                'totaltax'       => $cart->totaltax,
                'ratetier'       => $cart->hookups,
                'sitelock'       => (float) $cart->sitelock,
                'receipt'        => $receipt->id,
            ];

            if ($addonsPayload !== null) {
                $reservationData['addons_json'] = $addonsPayload;
            }

            if ($hasColumn('reservations', 'group_confirmation_code')) {
                $reservationData['group_confirmation_code'] = $groupConfirmationCode;
            }

            $reservation = $this->reservation->storeReservation($reservationData);
            $reservationIds[] = $reservation->id;

            // unique confirmation code (avoid duplicate constraint crash)
            if (empty($reservation->confirmation_code)) {
                $tries = 0;
                do {
                    $tries++;
                    $code = $this->confirmationCodes->generateForReservation($reservation);
                    $exists = \App\Model\Reservation::where('confirmation_code', $code)->exists();
                } while ($exists && $tries < 5);

                if ($exists) {
                    $code = 'CONF-' . strtoupper(substr(md5(uniqid('', true)), 0, 12));
                }

                $reservation->confirmation_code = $code;
                $reservation->save();
            }

            if ($storeCardOnFile && !empty($xToken)) {
                $this->cardsOnFile->storeCards([
                    'customernumber'    => $user->id,
                    'method'            => $paymentMethodLabel,
                    'cartid'            => $cart->cartid,
                    'email'             => $user->email,
                    'xmaskedcardnumber' => $maskedCardNumber,
                    'xtoken'            => $xToken,
                    'receipt'           => $receipt->id,
                    'gateway_response'  => json_encode($gatewayResponse),
                ]);
            }

            $allCartIds[]  = $cart->cartid;
            $allReceipts[] = $receipt->id;
        }

        // ✅ ONE payment row total
        $primaryCartId = $allCartIds[0] ?? null;

        $paymentPayload = [
            'customernumber' => $user->id,
            'method'         => $paymentMethodLabel ?: ucfirst($paymentMethod),
            'cartid'         => $primaryCartId,
            'email'          => $user->email,
            'payment'        => $paidAmount,
            'receipt'        => $allReceipts[0] ?? null,
            'x_ref_num'      => $xRefNum,
        ];

        if ($hasColumn('payments', 'meta')) {
            // if meta column exists, store JSON string (safe for TEXT or JSON columns)
            $paymentPayload['meta'] = json_encode($paymentMeta);
        }

        $paymentRow = $this->payment->storePayment($paymentPayload);
        $paymentId  = $paymentRow->id ?? null;

        if ($paymentId && $hasColumn('reservations', 'payment_id')) {
            \App\Model\Reservation::whereIn('id', $reservationIds)->update(['payment_id' => $paymentId]);
        }

        DB::commit();

        return response()->json([
            'ok'                      => true,
            'message'                 => 'Checkout completed successfully.',
            'payment_method'          => $paymentMethod,
            'payment_id'              => $paymentId,
            'group_confirmation_code' => $hasColumn('reservations', 'group_confirmation_code') ? $groupConfirmationCode : null,
            'reservation_ids'         => $reservationIds,
        ], 200);

    } catch (\Throwable $e) {
        DB::rollBack();

        // ✅ IMPORTANT: return the REAL error while you’re debugging
        return response()->json([
            'ok'      => false,
            'message' => 'Server error during checkout.',
            'errors'  => [
                'exception' => get_class($e),
                'detail'    => $e->getMessage(),
                'file'      => $e->getFile(),
                'line'      => $e->getLine(),
            ],
        ], 500);
    }
}

// public function modificationReservations(Request $request)
// {
//     $validator = Validator::make($request->all(), [
//         'draft_id'        => 'required|string|max:64',
//         'customer_id'     => 'required|integer|min:1',
//         'payment_method'  => 'required|in:card,ach,cash,gift_card',

//         'xCardNum'        => 'required_if:payment_method,card|digits_between:13,19',
//         'xExp'            => ['required_if:payment_method,card', 'regex:/^(0[1-9]|1[0-2])\/?([0-9]{2})$/'],

//         'cash_tendered'   => 'required_if:payment_method,cash|numeric|min:0.01',

//         // optional: allow client to pass old breakdown; otherwise we use draft->old_reservations_breakdown
//         'old_reservations_breakdown' => 'sometimes|array',
//     ]);

//     if ($validator->fails()) {
//         return response()->json([
//             'ok'      => false,
//             'message' => 'Validation failed.',
//             'errors'  => $validator->errors(),
//         ], 422);
//     }

//     $v = $validator->validated();

//     $hasColumn = function (string $table, string $column): bool {
//         try { return \Schema::hasColumn($table, $column); } catch (\Throwable $e) { return false; }
//     };

//     $normalizeAddons = function ($addons) {
//         if (empty($addons)) return [];
//         if (is_string($addons)) {
//             $decoded = json_decode($addons, true);
//             return is_array($decoded) ? $decoded : [];
//         }
//         if (is_array($addons)) return $addons;
//         return [];
//     };

//     $normalizeOccupants = function ($occ) {
//         // your CartItemsController normalizes occupants; we keep it simple but compatible
//         if (is_array($occ) && !empty($occ)) return $occ;
//         return ['adults' => 1, 'children' => 0];
//     };

//     $generateConfirmationCode = function (): string {
//         return 'CONF-' . strtoupper(substr(md5(uniqid('', true)), 0, 12));
//     };

//     $round2 = fn($n) => round((float)$n, 2);

//     try {
//         DB::beginTransaction();

//         /* -------------------------------------------------------------
//          | 1) Load draft
//          * -------------------------------------------------------------*/
//         $draft = \App\Model\ReservationDraft::where('draft_id', $v['draft_id'])
//             ->lockForUpdate()
//             ->first();

//         if (!$draft) {
//             DB::rollBack();
//             return response()->json(['ok' => false, 'message' => 'Draft not found'], 404);
//         }

//         if ((int)$draft->customer_id !== (int)$v['customer_id']) {
//             DB::rollBack();
//             return response()->json(['ok' => false, 'message' => 'Unauthorized'], 403);
//         }

//         if (($draft->status ?? '') !== 'draft') {
//             DB::rollBack();
//             return response()->json(['ok' => false, 'message' => 'Draft already finalized'], 409);
//         }

//         $cartData = is_string($draft->cart_data)
//             ? json_decode($draft->cart_data, true)
//             : (array)$draft->cart_data;

//         if (empty($cartData) || !is_array($cartData)) {
//             DB::rollBack();
//             return response()->json(['ok' => false, 'message' => 'Draft cart empty'], 400);
//         }

//         $originalIds = is_string($draft->original_reservation_ids)
//             ? json_decode($draft->original_reservation_ids, true)
//             : (array)$draft->original_reservation_ids;

//         $originalIds = array_values(array_filter($originalIds));
//         if (empty($originalIds)) {
//             DB::rollBack();
//             return response()->json(['ok' => false, 'message' => 'No original reservations found in draft'], 400);
//         }

//         $customer = \App\Model\User::findOrFail($draft->customer_id);

//         /* -------------------------------------------------------------
//          | 2) Load original reservations
//          * -------------------------------------------------------------*/
//         $originalReservations = \App\Model\Reservation::whereIn('id', $originalIds)
//             ->lockForUpdate()
//             ->get();

//         if ($originalReservations->isEmpty()) {
//             DB::rollBack();
//             return response()->json(['ok' => false, 'message' => 'Original reservations missing'], 400);
//         }

//         /* -------------------------------------------------------------
//          | 3) Refund breakdown (per old reservation)
//          * -------------------------------------------------------------*/
//         $oldBreakdown = $request->input('old_reservations_breakdown');
//         if (!is_array($oldBreakdown)) {
//             $oldBreakdown = !empty($draft->old_reservations_breakdown)
//                 ? (is_string($draft->old_reservations_breakdown) ? json_decode($draft->old_reservations_breakdown, true) : (array)$draft->old_reservations_breakdown)
//                 : null;
//         }

//         if (!is_array($oldBreakdown)) {
//             $oldBreakdown = $originalReservations->map(function ($r) {
//                 $total = (float)($r->totalcharges ?? $r->total ?? 0);
//                 return [
//                     'id' => (int)$r->id,
//                     'siteid' => (string)$r->siteid,
//                     'expected_full_refund' => $total,
//                 ];
//             })->values()->all();
//         }

//         $refundAmountByReservationId = [];
//         foreach ($oldBreakdown as $b) {
//             $rid = (int)($b['id'] ?? 0);
//             if (!$rid) continue;
//             $amt = (float)($b['expected_full_refund'] ?? $b['total'] ?? 0);
//             if ($amt > 0) $refundAmountByReservationId[$rid] = $amt;
//         }

//         /* -------------------------------------------------------------
//          | 4) Locate original payment
//          * -------------------------------------------------------------*/
//         $originalPaymentId = $originalReservations->pluck('payment_id')->filter()->first();
//         $originalPayment   = $originalPaymentId ? \App\Model\Payment::find($originalPaymentId) : null;

//         $origPaymentMethod = $originalPayment->method ?? null;
//         $origGatewayRef    = $originalPayment->x_ref_num ?? null;
//         $origIsGateway     = !empty($origGatewayRef);

//         /* -------------------------------------------------------------
//          | 5) Create/load ChannelCart (DIRECT DB, like CartController@store)
//          * -------------------------------------------------------------*/
//         $channelCartId = (int)($draft->external_cart_id ?? 0);
//         $cartToken = null;

//         if ($channelCartId > 0) {
//             $cartRow = DB::table('channel_carts')->lockForUpdate()->where('id', $channelCartId)->first();
//             if (!$cartRow) $channelCartId = 0;
//             else $cartToken = (string)($cartRow->token ?? '');
//         }

//         if ($channelCartId <= 0) {
//             // If you DON'T have auth_scope here, store nulls for org/channel ids (same as controller does when auth missing)
//             $auth = request()->attributes->get('auth_scope', []);
//             $orgId = (int)($auth['property_id'] ?? 0);
//             $bookingChannelId = (int)($auth['booking_channel_id'] ?? 0);
//             $channelId = (int)($auth['channel_id'] ?? 0);

//             $timeout = (int) config('cart.ttl_seconds', 1800);
//             $cartToken = (string) \Illuminate\Support\Str::uuid();

//             $channelCartId = DB::table('channel_carts')->insertGetId([
//                 'organization_id'    => $orgId ?: null,
//                 'booking_channel_id' => $bookingChannelId ?: null,
//                 'channel_id'         => $channelId ?: null,
//                 'token'              => $cartToken,
//                 'status'             => 'open',
//                 'expires_at'         => now()->addSeconds($timeout),
//                 'currency'           => 'USD',
//                 'utm_source'         => 'modification',
//                 'utm_medium'         => 'api',
//                 'utm_campaign'       => 'reservation_modification',
//                 'created_at'         => now(),
//                 'updated_at'         => now(),
//             ]);

//             if ($hasColumn('reservation_drafts', 'external_cart_id')) {
//                 $draft->external_cart_id = $channelCartId;
//             }
//             $draft->save();
//         }

//         if (!$cartToken) {
//             $cartRow = DB::table('channel_carts')->lockForUpdate()->where('id', $channelCartId)->first();
//             $cartToken = (string)($cartRow->token ?? '');
//             if (!$cartToken) {
//                 DB::rollBack();
//                 return response()->json([
//                     'ok' => false,
//                     'message' => 'ChannelCart token missing',
//                     'errors' => ['cart_id' => $channelCartId],
//                 ], 500);
//             }
//         }

//         /* -------------------------------------------------------------
//          | 6) Build cart_itemms DIRECT DB (like CartItemsController@store)
//          |    Then reservation.cartid = ch_<cart_itemms.id>
//          * -------------------------------------------------------------*/
//         $newCartItemIds = [];
//         $newCartIds = [];
//         $primaryCartId = null;

//         foreach ($cartData as $idx => $item) {
//             if (!is_array($item)) continue;

//             // Your draft uses `id` for site code (JL07)
//             $siteId = $item['siteid'] ?? $item['site_id'] ?? $item['id'] ?? null;
//             $start  = $item['start_date'] ?? null;
//             $end    = $item['end_date'] ?? null;

//             if (!$siteId || !$start || !$end) continue;

//             // validate site exists (exactly like CartItemsController)
//             $siteRow = DB::table('sites')->where('siteid', (string)$siteId)->first();
//             if (!$siteRow) {
//                 DB::rollBack();
//                 return response()->json([
//                     'ok' => false,
//                     'message' => 'Invalid site_id in draft cart_data',
//                     'errors' => ['site_id' => $siteId, 'idx' => $idx],
//                 ], 422);
//             }

//             // lock cart
//             $cartRow = DB::table('channel_carts')->lockForUpdate()->where('id', $channelCartId)->first();
//             if (!$cartRow) {
//                 DB::rollBack();
//                 return response()->json(['ok' => false, 'message' => 'Cart not found'], 404);
//             }

//             if (!hash_equals((string)$cartRow->token, (string)$cartToken)) {
//                 DB::rollBack();
//                 return response()->json([
//                     'ok' => false,
//                     'message' => 'INVALID_CART_TOKEN (draft/cart token mismatch)',
//                     'errors' => ['cart_id' => $channelCartId],
//                 ], 401);
//             }

//             if (now()->greaterThanOrEqualTo($cartRow->expires_at)) {
//                 DB::rollBack();
//                 return response()->json(['ok' => false, 'message' => 'CART_EXPIRED'], 410);
//             }

//             $occupants = $normalizeOccupants($item['occupants'] ?? $item['occupants_json'] ?? null);
//             $addons    = $normalizeAddons($item['add_ons'] ?? $item['addons_json'] ?? []);
//             $siteLockFee = (float)($item['lock_fee_amount'] ?? $item['site_lock_fee'] ?? 0);
//             $ratetier = $item['ratetier'] ?? null;

//             $payload = [
//                 'site_id'       => (string)$siteId,
//                 'site_lock_fee' => $siteLockFee,
//                 'start_date'    => Carbon::parse($start)->format('Y-m-d'),
//                 'end_date'      => Carbon::parse($end)->format('Y-m-d'),
//                 'ratetier'      => $ratetier,
//                 'occupants'     => $occupants,
//                 'add_ons'       => $addons,
//             ];
//             $dedupeKey = sha1(json_encode($payload));

//             // duplicate check
//             $existing = \App\Model\CartItemm::where('channel_cart_id', $channelCartId)
//                 ->where('dedupe_key', $dedupeKey)
//                 ->lockForUpdate()
//                 ->first();

//             if ($existing) {
//                 $newCartItemIds[$idx] = (int)$existing->id;
//                 $newCartIds[$idx]     = 'ch_' . (int)$existing->id;
//                 if (!$primaryCartId) $primaryCartId = $newCartIds[$idx];
//                 continue;
//             }

//             // overlap check: reservations
//             $overlap = DB::table('reservations')
//                 ->whereIn('status', ['confirmed', 'pending'])
//                 ->where(function ($q) use ($payload) {
//                     $q->where('cid', '<', $payload['end_date'])
//                       ->where('cod', '>', $payload['start_date']);
//                 })
//                 ->exists();

//             if ($overlap) {
//                 DB::rollBack();
//                 return response()->json([
//                     'ok' => false,
//                     'message' => 'OVERLAPPING_RESERVATION',
//                     'errors' => ['site_id' => $siteId, 'start' => $payload['start_date'], 'end' => $payload['end_date']],
//                 ], 409);
//             }

//             // overlap check: holds from other carts
//             $holds = \App\Model\InventoryHoldd::where('site_id', (string)$siteId)
//                 ->where('hold_expires_at', '>', now())
//                 ->where(function ($q) use ($payload) {
//                     $q->where('start_date', '<', $payload['end_date'])
//                       ->where('end_date', '>', $payload['start_date']);
//                 })
//                 ->lockForUpdate()
//                 ->get();

//             foreach ($holds as $h) {
//                 if ((int)$h->channel_cart_id !== (int)$chaBookingContextnnelCartId) {
//                     DB::rollBack();
//                     return response()->json([
//                         'ok' => false,
//                         'message' => 'OVERLAPPING_RESERVATION',
//                         'errors' => ['site_id' => $siteId],
//                     ], 409);
//                 }
//             }

//             // price snapshot (same as controller)
//             // You MUST have these classes/services available in this controller context.
//             // dd('sadasd');
//             $context = new (
//                 $payload['start_date'],
//                 $payload['end_date'],
//                 $payload['ratetier'],
//                 $payload['site_lock_fee']
//             );

//             $priceSnapshot = $this->priceService->quote($context);

//             // extend cart expiry (same as controller)
//             $timeoutMin = (int) config('business.cart_timeout_minutes', 30);
//             $newExpires = now()->addMinutes($timeoutMin);

//             DB::table('channel_carts')->where('id', $channelCartId)->update([
//                 'expires_at' => $newExpires,
//                 'updated_at' => now(),
//             ]);

//             // create cart_itemms
//             $itemId = DB::table('cart_itemms')->insertGetId([
//                 'channel_cart_id'      => $channelCartId,
//                 'site_id'              => (string)$siteId,
//                 'start_date'           => $payload['start_date'],
//                 'end_date'             => $payload['end_date'],
//                 'occupants_json'        => json_encode($payload['occupants']),
//                 'addons_json'           => json_encode($payload['add_ons']),
//                 'price_snapshot_json'   => json_encode($priceSnapshot),
//                 'dedupe_key'            => $dedupeKey,
//                 'hold_expires_at'       => $newExpires,
//                 'status'                => 'active',
//                 'created_at'            => now(),
//                 'updated_at'            => now(),
//             ]);

//             // create inventory hold
//             DB::table('inventory_holdds')->insert([
//                 'site_id'         => (string)$siteId,
//                 'start_date'      => $payload['start_date'],
//                 'end_date'        => $payload['end_date'],
//                 'channel_cart_id' => $channelCartId,
//                 'cart_item_id'    => $itemId,
//                 'hold_expires_at' => $newExpires,
//                 'created_at'      => now(),
//                 'updated_at'      => now(),
//             ]);

//             $newCartItemIds[$idx] = (int)$itemId;
//             $newCartIds[$idx]     = 'ch_' . (int)$itemId;
//             if (!$primaryCartId) $primaryCartId = $newCartIds[$idx];
//         }

//         if (!$primaryCartId) {
//             DB::rollBack();
//             return response()->json([
//                 'ok' => false,
//                 'message' => 'Unable to build any cart items for this draft',
//                 'errors' => ['cart_data' => $cartData],
//             ], 400);
//         }

//         /* -------------------------------------------------------------
//          | 7) Refund (ONLY if amounts > 0) + ONE gateway refund total
//          * -------------------------------------------------------------*/
//         $refunds = [];
//         $refundPlan = [];
//         $refundedTotal = 0.0;

//         foreach ($originalReservations as $r) {
//             $rid = (int)$r->id;
//             $amt = (float)($refundAmountByReservationId[$rid] ?? 0);
//             if ($amt > 0) {
//                 $refundPlan[] = ['reservation' => $r, 'amount' => $amt];
//                 $refundedTotal += $amt;
//             }
//         }

//         $gatewayRefundXRef = null;
//         $gatewayRefundResp = null;
//         $gatewayRefundStatus = 'manual';

//         if ($origIsGateway && $refundedTotal > 0) {
//             $post = [
//                 'xKey'             => config('services.cardknox.api_key'),
//                 'xVersion'         => '4.5.5',
//                 'xCommand'         => 'cc:Refund',
//                 'xAmount'          => $round2($refundedTotal),
//                 'xRefNum'          => $origGatewayRef,
//                 'xInvoice'         => 'REF-' . uniqid() . '-' . now()->format('YmdHis'),
//                 'xSoftwareName'    => 'KayutaLake',
//                 'xSoftwareVersion' => '1.0',
//             ];

//             $ch = curl_init('https://x1.cardknox.com/gateway');
//             curl_setopt_array($ch, [
//                 CURLOPT_RETURNTRANSFER => true,
//                 CURLOPT_POSTFIELDS     => http_build_query($post),
//                 CURLOPT_HTTPHEADER     => [
//                     'Content-type: application/x-www-form-urlencoded',
//                     'X-Recurring-Api-Version: 1.0',
//                 ],
//             ]);

//             $raw = curl_exec($ch);
//             if ($raw === false) {
//                 DB::rollBack();
//                 return response()->json(['ok' => false, 'message' => 'Unable to contact refund gateway.'], 502);
//             }

//             parse_str($raw, $resp);
//             $gatewayRefundResp = $resp;

//             if (($resp['xStatus'] ?? '') !== 'Approved') {
//                 DB::rollBack();
//                 return response()->json([
//                     'ok'      => false,
//                     'message' => $resp['xError'] ?? 'Refund failed',
//                     'errors'  => ['gateway' => $resp],
//                 ], 400);
//             }

//             $gatewayRefundXRef   = $resp['xRefNum'] ?? null;
//             $gatewayRefundStatus = 'approved';
//         }

//         foreach ($refundPlan as $i => $row) {
//             $r = $row['reservation'];
//             $rid = (int)$r->id;
//             $refundAmount = (float)$row['amount'];
//             if ($refundAmount <= 0) continue;

//             $refundMethod = $origPaymentMethod ?: 'Cash';
//             $refundXRef   = null;
//             $refundStatus = 'manual';
//             $gatewayResp  = null;

//             if ($origIsGateway) {
//                 $refundMethod = 'Card';
//                 $refundStatus = $gatewayRefundStatus;
//                 $refundXRef   = ($i === 0) ? $gatewayRefundXRef : null;
//                 $gatewayResp  = ($i === 0) ? $gatewayRefundResp : null;
//             }

//             $refundPayload = [
//                 'cartid'          => $primaryCartId, // ✅ ch_<cart_itemms.id> like ch_525
//                 'reservations_id' => $rid,
//                 'amount'          => $refundAmount,
//                 'method'          => $refundMethod,
//                 'reason'          => 'Reservation Modification',
//                 'x_ref_num'       => $refundXRef,
//                 'created_by'      => auth()->user()->name ?? 'System',
//             ];

//             if ($hasColumn('refunds', 'status')) $refundPayload['status'] = $refundStatus;

//             if ($hasColumn('refunds', 'meta')) {
//                 $refundPayload['meta'] = json_encode([
//                     'type'                => 'modification_refund',
//                     'draft_id'            => $draft->draft_id,
//                     'original_payment_id' => $originalPaymentId,
//                     'refund_mode'         => $origIsGateway ? 'gateway_one_refund_allocated' : 'manual',
//                     'gateway_refund_xref' => $gatewayRefundXRef,
//                     'gateway_response'    => $gatewayResp,
//                 ]);
//             }

//             $refundRow = \App\Model\Refund::create($refundPayload);

//             $refunds[] = [
//                 'refund_id'      => (int)$refundRow->id,
//                 'reservation_id' => $rid,
//                 'siteid'         => (string)$r->siteid,
//                 'amount'         => $round2($refundAmount),
//                 'method'         => $refundMethod,
//                 'gateway_ref'    => $refundXRef,
//                 'status'         => $refundStatus,
//             ];
//         }

//         /* -------------------------------------------------------------
//          | 8) Cancel originals
//          * -------------------------------------------------------------*/
//         $cancelledReservations = [];
//         foreach ($originalReservations as $r) {
//             $r->status = 'Cancelled';
//             if ($hasColumn('reservations', 'cancelled_at')) $r->cancelled_at = now();
//             if ($hasColumn('reservations', 'cancel_reason')) $r->cancel_reason = 'Modification';
//             $r->save();

//             $cancelledReservations[] = [
//                 'reservation_id'  => (int)$r->id,
//                 'siteid'          => (string)$r->siteid,
//                 'refunded_amount' => $round2((float)($refundAmountByReservationId[$r->id] ?? 0)),
//             ];
//         }

//         /* -------------------------------------------------------------
//          | 9) Charge new total (draft grand_total)
//          * -------------------------------------------------------------*/
//         $newTotal = (float)$draft->grand_total;
//         if ($newTotal < 0) $newTotal = 0;

//         $paymentId = null;
//         $paymentMethodLabel = '';
//         $xRefNumNew = null;
//         $xAuthCodeNew = '';
//         $gatewaySaleResp = [];

//         if ($newTotal > 0) {
//             if ($v['payment_method'] === 'card') {
//                 $post = [
//                     'xKey'             => config('services.cardknox.api_key'),
//                     'xVersion'         => '4.5.5',
//                     'xCommand'         => 'cc:Sale',
//                     'xAmount'          => $newTotal,
//                     'xCardNum'         => $v['xCardNum'],
//                     'xExp'             => str_replace('/', '', $v['xExp']),
//                     'xSoftwareVersion' => '1.0',
//                     'xSoftwareName'    => 'KayutaLake',
//                     'xInvoice'         => 'RECUR-' . uniqid() . '-' . now()->format('YmdHis'),
//                 ];

//                 $ch = curl_init('https://x1.cardknox.com/gateway');
//                 curl_setopt_array($ch, [
//                     CURLOPT_RETURNTRANSFER => true,
//                     CURLOPT_POSTFIELDS     => http_build_query($post),
//                     CURLOPT_HTTPHEADER     => [
//                         'Content-type: application/x-www-form-urlencoded',
//                         'X-Recurring-Api-Version: 1.0',
//                     ],
//                 ]);

//                 $raw = curl_exec($ch);
//                 if ($raw === false) {
//                     DB::rollBack();
//                     return response()->json(['ok' => false, 'message' => 'Unable to contact payment gateway.'], 502);
//                 }

//                 parse_str($raw, $sale);
//                 $gatewaySaleResp = $sale;

//                 if (($sale['xStatus'] ?? '') !== 'Approved') {
//                     DB::rollBack();
//                     return response()->json([
//                         'ok' => false,
//                         'message' => $sale['xError'] ?? 'Payment failed',
//                         'errors' => ['gateway' => $sale],
//                     ], 400);
//                 }

//                 $paymentMethodLabel = $sale['xCardType'] ?? 'Card';
//                 $xRefNumNew = $sale['xRefNum'] ?? null;
//                 $xAuthCodeNew = $sale['xAuthCode'] ?? ('MOD-' . uniqid());

//             } elseif ($v['payment_method'] === 'cash') {
//                 $cashTendered = (float)$v['cash_tendered'];
//                 if ($cashTendered < $newTotal) {
//                     DB::rollBack();
//                     return response()->json([
//                         'ok' => false,
//                         'message' => 'Cash tendered must be at least the new total amount.',
//                         'errors' => ['cash_tendered' => ['Not enough cash tendered']],
//                     ], 422);
//                 }

//                 $paymentMethodLabel = 'Cash';
//                 $xAuthCodeNew = 'CASH-' . now()->format('YmdHis') . '-' . uniqid();
//             } else {
//                 $paymentMethodLabel = ucfirst(str_replace('_', ' ', $v['payment_method']));
//                 $xAuthCodeNew = strtoupper($v['payment_method']) . '-' . strtoupper(uniqid());
//             }

//             $paymentPayload = [
//                 'customernumber' => $customer->id,
//                 'method'         => $paymentMethodLabel ?: ucfirst($v['payment_method']),
//                 'cartid'         => $primaryCartId, // ✅ primary cartid like checkout
//                 'email'          => $customer->email,
//                 'payment'        => $newTotal,
//                 'receipt'        => rand(1000, 9999),
//                 'x_ref_num'      => $xRefNumNew,
//             ];

//             if ($hasColumn('payments', 'meta')) {
//                 $paymentPayload['meta'] = json_encode([
//                     'type'            => 'modification',
//                     'draft_id'        => $draft->draft_id,
//                     'channel_cart_id' => $channelCartId,
//                     'refunded_total'  => $round2($refundedTotal),
//                     'sale_gateway'    => $gatewaySaleResp,
//                     'refund_gateway'  => $gatewayRefundResp,
//                     'refund_xref'     => $gatewayRefundXRef,
//                     'primary_cartid'  => $primaryCartId,
//                 ]);
//             }

//             $paymentRow = \App\Model\Payment::create($paymentPayload);
//             $paymentId = $paymentRow->id ?? null;
//         }

//         /* -------------------------------------------------------------
//          | 10) Create NEW reservations (cartid = ch_<cart_itemms.id>)
//          * -------------------------------------------------------------*/
//         $groupConfirmationCode = 'CONF-' . strtoupper(substr(md5(uniqid('', true)), 0, 10));
//         $newReservations = [];

//         foreach ($cartData as $idx => $item) {
//             if (!is_array($item)) continue;

//             $siteid = $item['siteid'] ?? $item['site_id'] ?? $item['id'] ?? null;
//             if (!$siteid) continue;

//             $site     = \App\Model\Site::where('siteid', $siteid)->first();
//             $rateTier = $site ? \App\Model\RateTier::where('tier', $site->hookup)->first() : null;

//             $inDate  = !empty($item['start_date']) ? Carbon::parse($item['start_date'])->format('Y-m-d') : null;
//             $outDate = !empty($item['end_date'])   ? Carbon::parse($item['end_date'])->format('Y-m-d')   : null;

//             $inTime = ($rateTier && !empty($rateTier->check_in))
//                 ? Carbon::parse($rateTier->check_in)->format('H:i:s')
//                 : '15:00:00';

//             $outTime = ($rateTier && !empty($rateTier->check_out))
//                 ? Carbon::parse($rateTier->check_out)->format('H:i:s')
//                 : '11:00:00';

//             $scheduledCid = $inDate  ? "{$inDate} {$inTime}"   : null;
//             $scheduledCod = $outDate ? "{$outDate} {$outTime}" : null;

//             $cartidForThisReservation = $newCartIds[$idx] ?? null;
//             if (!$cartidForThisReservation) {
//                 DB::rollBack();
//                 return response()->json([
//                     'ok' => false,
//                     'message' => 'Missing cartid for reservation (cart_itemms not created)',
//                     'errors' => ['idx' => $idx],
//                 ], 500);
//             }

//             // totals
//             $base     = (float)($item['base'] ?? 0);
//             $sitelock = (float)($item['lock_fee_amount'] ?? 0);

//             // If modification should charge ONLY lock fee, use $sitelock.
//             // If it should be base+lock, use $base + $sitelock.
//             // I'll use item['total'] if present, else base+lock.
//             $totalCharges = (float)($item['total'] ?? ($base + $sitelock));

//             $reservationData = [
//                 'xconfnum'       => $xAuthCodeNew ?: ('MOD-' . uniqid()),
//                 'cartid'         => $cartidForThisReservation, // ✅ ch_<cart_itemms.id>
//                 'source'         => 'Online Booking',
//                 'createdby'      => 'API',
//                 'fname'          => $customer->f_name ?? '',
//                 'lname'          => $customer->l_name ?? '',
//                 'customernumber' => $customer->id,
//                 'siteid'         => $siteid,
//                 'cid'            => $scheduledCid,
//                 'cod'            => $scheduledCod,
//                 'siteclass'      => $item['siteclass'] ?? ($site->siteclass ?? null),
//                 'subtotal'       => (float)($item['subtotal'] ?? $base),
//                 'totaltax'       => 0,
//                 'nights'         => ($inDate && $outDate) ? Carbon::parse($outDate)->diffInDays(Carbon::parse($inDate)) : 1,
//                 'sitelock'       => $sitelock,
//                 'receipt'        => rand(1000, 9999),
//                 'totalcharges'   => $totalCharges,
//                 'total'          => $totalCharges,
//             ];

//             $addonsPayload = $normalizeAddons($item['add_ons'] ?? $item['addons_json'] ?? []);
//             if (!empty($addonsPayload) && $hasColumn('reservations', 'addons_json')) {
//                 $reservationData['addons_json'] = $addonsPayload;
//             }

//             if ($paymentId && $hasColumn('reservations', 'payment_id')) {
//                 $reservationData['payment_id'] = $paymentId;
//             }

//             if ($hasColumn('reservations', 'group_confirmation_code')) {
//                 $reservationData['group_confirmation_code'] = $groupConfirmationCode;
//             }

//             $reservation = \App\Model\Reservation::create($reservationData);

//             if ($hasColumn('reservations', 'confirmation_code') && empty($reservation->confirmation_code)) {
//                 $tries = 0;
//                 do {
//                     $tries++;
//                     $code = $generateConfirmationCode();
//                     $exists = \App\Model\Reservation::where('confirmation_code', $code)->exists();
//                 } while ($exists && $tries < 5);

//                 $reservation->confirmation_code = $code;
//                 $reservation->save();
//             }

//             $newReservations[] = [
//                 'reservation_id'    => (int)$reservation->id,
//                 'siteid'            => (string)$siteid,
//                 'cid'               => (string)$reservation->cid,
//                 'cod'               => (string)$reservation->cod,
//                 'confirmation_code' => $reservation->confirmation_code ?? null,
//                 'payment_id'        => $paymentId,
//                 'cartid'            => $cartidForThisReservation,
//                 'cart_itemm_id'     => (int)($newCartItemIds[$idx] ?? 0),
//             ];
//         }

//         /* -------------------------------------------------------------
//          | 11) Finalize draft
//          * -------------------------------------------------------------*/
//         $draft->status = 'confirmed';
//         if ($hasColumn('reservation_drafts', 'payment_id')) $draft->payment_id = $paymentId;
//         $draft->save();

//         DB::commit();

//         return response()->json([
//             'ok' => true,
//             'draft_id' => $draft->draft_id,
//             'channel_cart_id' => $channelCartId,
//             'primary_cartid' => $primaryCartId,

//             'refunds' => $refunds,
//             'refund_summary' => [
//                 'count' => count($refunds),
//                 'total' => $round2($refundedTotal),
//             ],

//             'payment' => [
//                 'payment_id'  => $paymentId,
//                 'method'      => $paymentMethodLabel ?: ucfirst($v['payment_method']),
//                 'amount'      => $round2($newTotal),
//                 'gateway_ref' => $xRefNumNew,
//                 'cartid'      => $primaryCartId,
//             ],

//             'new_reservations' => $newReservations,
//             'cancelled_reservations' => $cancelledReservations,
//         ], 200);

//     } catch (\Throwable $e) {
//         DB::rollBack();

//         return response()->json([
//             'ok' => false,
//             'message' => 'Modification checkout failed',
//             'error' => $e->getMessage(),
//             'file' => $e->getFile(),
//             'line' => $e->getLine(),
//         ], 500);
//     }
// }


public function modificationReservations(Request $request)
{
    $validator = Validator::make($request->all(), [
        'draft_id'        => 'required|string|max:64',
        'customer_id'     => 'required|integer|min:1',
        'payment_method'  => 'required|in:card,ach,cash,gift_card',

        'xCardNum'        => 'required_if:payment_method,card|digits_between:13,19',
        'xExp'            => ['required_if:payment_method,card', 'regex:/^(0[1-9]|1[0-2])\/?([0-9]{2})$/'],

        'cash_tendered'   => 'required_if:payment_method,cash|numeric|min:0.01',

        'old_reservations_breakdown' => 'sometimes|array',
    ]);

    if ($validator->fails()) {
        return response()->json([
            'ok'      => false,
            'message' => 'Validation failed.',
            'errors'  => $validator->errors(),
        ], 422);
    }

    $v = $validator->validated();

    $hasColumn = function (string $table, string $column): bool {
        try { return Schema::hasColumn($table, $column); } catch (\Throwable $e) { return false; }
    };

    $normalizeAddons = function ($addons) {
        if (empty($addons)) return [];
        if (is_string($addons)) {
            $decoded = json_decode($addons, true);
            return is_array($decoded) ? $decoded : [];
        }
        if (is_array($addons)) return $addons;
        return [];
    };

    $normalizeOccupants = function ($occ) {
        if (is_array($occ) && !empty($occ)) return $occ;
        return ['adults' => 1, 'children' => 0];
    };

    $generateConfirmationCode = function (): string {
        return 'CONF-' . strtoupper(substr(md5(uniqid('', true)), 0, 12));
    };

    $round2 = fn($n) => round((float)$n, 2);

    try {
        DB::beginTransaction();

        /* -------------------------------------------------------------
         | 1) Load draft
         * -------------------------------------------------------------*/
        $draft = \App\Model\ReservationDraft::where('draft_id', $v['draft_id'])
            ->lockForUpdate()
            ->first();

        if (!$draft) {
            DB::rollBack();
            return response()->json(['ok' => false, 'message' => 'Draft not found'], 404);
        }

        if ((int)$draft->customer_id !== (int)$v['customer_id']) {
            DB::rollBack();
            return response()->json(['ok' => false, 'message' => 'Unauthorized'], 403);
        }

        if (($draft->status ?? '') !== 'draft') {
            DB::rollBack();
            return response()->json(['ok' => false, 'message' => 'Draft already finalized'], 409);
        }

        $cartData = is_string($draft->cart_data)
            ? json_decode($draft->cart_data, true)
            : (array)$draft->cart_data;

        if (empty($cartData) || !is_array($cartData)) {
            DB::rollBack();
            return response()->json(['ok' => false, 'message' => 'Draft cart empty'], 400);
        }

        $originalIds = is_string($draft->original_reservation_ids)
            ? json_decode($draft->original_reservation_ids, true)
            : (array)$draft->original_reservation_ids;

        $originalIds = array_values(array_filter($originalIds));
        if (empty($originalIds)) {
            DB::rollBack();
            return response()->json(['ok' => false, 'message' => 'No original reservations found in draft'], 400);
        }

        $customer = \App\Model\User::findOrFail($draft->customer_id);

        /* -------------------------------------------------------------
         | 2) Load original reservations
         * -------------------------------------------------------------*/
        $originalReservations = \App\Model\Reservation::whereIn('id', $originalIds)
            ->lockForUpdate()
            ->get();

        if ($originalReservations->isEmpty()) {
            DB::rollBack();
            return response()->json(['ok' => false, 'message' => 'Original reservations missing'], 400);
        }

        /* -------------------------------------------------------------
         | 3) Refund breakdown
         * -------------------------------------------------------------*/
        $oldBreakdown = $request->input('old_reservations_breakdown');
        if (!is_array($oldBreakdown)) {
            $oldBreakdown = !empty($draft->old_reservations_breakdown)
                ? (is_string($draft->old_reservations_breakdown) ? json_decode($draft->old_reservations_breakdown, true) : (array)$draft->old_reservations_breakdown)
                : null;
        }

        if (!is_array($oldBreakdown)) {
            $oldBreakdown = $originalReservations->map(function ($r) {
                $total = (float)($r->totalcharges ?? $r->total ?? 0);
                return [
                    'id' => (int)$r->id,
                    'siteid' => (string)$r->siteid,
                    'expected_full_refund' => $total,
                ];
            })->values()->all();
        }

        $refundAmountByReservationId = [];
        foreach ($oldBreakdown as $b) {
            $rid = (int)($b['id'] ?? 0);
            if (!$rid) continue;
            $amt = (float)($b['expected_full_refund'] ?? $b['total'] ?? 0);
            if ($amt > 0) $refundAmountByReservationId[$rid] = $amt;
        }

        /* -------------------------------------------------------------
         | 4) Locate original payment
         * -------------------------------------------------------------*/
        $originalPaymentId = $originalReservations->pluck('payment_id')->filter()->first();
        $originalPayment   = $originalPaymentId ? \App\Model\Payment::find($originalPaymentId) : null;

        $origPaymentMethod = $originalPayment->method ?? null;
        $origGatewayRef    = $originalPayment->x_ref_num ?? null;
        $origIsGateway     = !empty($origGatewayRef);

        /* -------------------------------------------------------------
         | 5) Create/load ChannelCart (DIRECT DB)
         * -------------------------------------------------------------*/
        $channelCartId = (int)($draft->external_cart_id ?? 0);
        $cartToken = null;

        if ($channelCartId > 0) {
            $cartRow = DB::table('channel_carts')->lockForUpdate()->where('id', $channelCartId)->first();
            if (!$cartRow) $channelCartId = 0;
            else $cartToken = (string)($cartRow->token ?? '');
        }

        if ($channelCartId <= 0) {
            $auth = request()->attributes->get('auth_scope', []);
            $orgId = (int)($auth['property_id'] ?? 0);
            $bookingChannelId = (int)($auth['booking_channel_id'] ?? 0);
            $channelId = (int)($auth['channel_id'] ?? 0);

            $timeout = (int) config('cart.ttl_seconds', 1800);
            $cartToken = (string) \Illuminate\Support\Str::uuid();

            $channelCartId = DB::table('channel_carts')->insertGetId([
                'organization_id'    => $orgId ?: null,
                'booking_channel_id' => $bookingChannelId ?: null,
                'channel_id'         => $channelId ?: null,
                'token'              => $cartToken,
                'status'             => 'open',
                'expires_at'         => now()->addSeconds($timeout),
                'currency'           => 'USD',
                'utm_source'         => 'modification',
                'utm_medium'         => 'api',
                'utm_campaign'       => 'reservation_modification',
                'created_at'         => now(),
                'updated_at'         => now(),
            ]);

            if ($hasColumn('reservation_drafts', 'external_cart_id')) {
                $draft->external_cart_id = $channelCartId;
            }
            $draft->save();
        }

        if (!$cartToken) {
            $cartRow = DB::table('channel_carts')->lockForUpdate()->where('id', $channelCartId)->first();
            $cartToken = (string)($cartRow->token ?? '');
            if (!$cartToken) {
                DB::rollBack();
                return response()->json([
                    'ok' => false,
                    'message' => 'ChannelCart token missing',
                    'errors' => ['cart_id' => $channelCartId],
                ], 500);
            }
        }

        /* -------------------------------------------------------------
         | 6) Build cart_itemms DIRECT DB (NO price service)
         |    reservation.cartid = ch_<cart_itemms.id>
         * -------------------------------------------------------------*/
        $newCartItemIds = [];
        $newCartIds = [];
        $primaryCartId = null;

        foreach ($cartData as $idx => $item) {
            if (!is_array($item)) continue;

            $siteId = $item['siteid'] ?? $item['site_id'] ?? $item['id'] ?? null;
            $start  = $item['start_date'] ?? null;
            $end    = $item['end_date'] ?? null;

            if (!$siteId || !$start || !$end) continue;

            // validate site exists
            $siteRow = DB::table('sites')->where('siteid', (string)$siteId)->first();
            if (!$siteRow) {
                DB::rollBack();
                return response()->json([
                    'ok' => false,
                    'message' => 'Invalid site_id in draft cart_data',
                    'errors' => ['site_id' => $siteId, 'idx' => $idx],
                ], 422);
            }

            // lock cart
            $cartRow = DB::table('channel_carts')->lockForUpdate()->where('id', $channelCartId)->first();
            if (!$cartRow) {
                DB::rollBack();
                return response()->json(['ok' => false, 'message' => 'Cart not found'], 404);
            }

            if (!hash_equals((string)$cartRow->token, (string)$cartToken)) {
                DB::rollBack();
                return response()->json([
                    'ok' => false,
                    'message' => 'INVALID_CART_TOKEN (draft/cart token mismatch)',
                    'errors' => ['cart_id' => $channelCartId],
                ], 401);
            }

            if (now()->greaterThanOrEqualTo($cartRow->expires_at)) {
                DB::rollBack();
                return response()->json(['ok' => false, 'message' => 'CART_EXPIRED'], 410);
            }

            $occupants = $normalizeOccupants($item['occupants'] ?? $item['occupants_json'] ?? null);
            $addons    = $normalizeAddons($item['add_ons'] ?? $item['addons_json'] ?? []);
            $siteLockFee = (float)($item['lock_fee_amount'] ?? $item['site_lock_fee'] ?? 0);
            $base = (float)($item['base'] ?? 0);

            $startDate = Carbon::parse($start)->format('Y-m-d');
            $endDate   = Carbon::parse($end)->format('Y-m-d');

            // Draft should decide totals; prefer item total/grand_total if present
            $grand = (float)(
                $item['grand_total']
                ?? $item['total']
                ?? ($base + $siteLockFee)
            );

            $payload = [
                'site_id'       => (string)$siteId,
                'site_lock_fee' => $siteLockFee,
                'start_date'    => $startDate,
                'end_date'      => $endDate,
                'ratetier'      => $item['ratetier'] ?? null,
                'occupants'     => $occupants,
                'add_ons'       => $addons,
            ];
            $dedupeKey = sha1(json_encode($payload));

            // duplicate check
            $existing = \App\Model\CartItemm::where('channel_cart_id', $channelCartId)
                ->where('dedupe_key', $dedupeKey)
                ->lockForUpdate()
                ->first();

            if ($existing) {
                $newCartItemIds[$idx] = (int)$existing->id;
                $newCartIds[$idx]     = 'ch_' . (int)$existing->id;
                if (!$primaryCartId) $primaryCartId = $newCartIds[$idx];
                continue;
            }

            // overlap check: reservations
            $overlap = DB::table('reservations')
                ->whereIn('status', ['confirmed', 'pending'])
                ->where(function ($q) use ($startDate, $endDate) {
                    $q->where('cid', '<', $endDate)
                      ->where('cod', '>', $startDate);
                })
                ->exists();

            if ($overlap) {
                DB::rollBack();
                return response()->json([
                    'ok' => false,
                    'message' => 'OVERLAPPING_RESERVATION',
                    'errors' => ['site_id' => $siteId, 'start' => $startDate, 'end' => $endDate],
                ], 409);
            }

            // overlap check: holds from other carts
            $holds = \App\Model\InventoryHoldd::where('site_id', (string)$siteId)
                ->where('hold_expires_at', '>', now())
                ->where(function ($q) use ($startDate, $endDate) {
                    $q->where('start_date', '<', $endDate)
                      ->where('end_date', '>', $startDate);
                })
                ->lockForUpdate()
                ->get();

            foreach ($holds as $h) {
                if ((int)$h->channel_cart_id !== (int)$channelCartId) {
                    DB::rollBack();
                    return response()->json([
                        'ok' => false,
                        'message' => 'OVERLAPPING_RESERVATION',
                        'errors' => ['site_id' => $siteId],
                    ], 409);
                }
            }

            // ✅ price snapshot without services
            $priceSnapshot = [
                'sub_total'     => $round2($base),
                'tax_total'     => 0,
                'sitelock_fee'  => $round2($siteLockFee),
                'grand_total'   => $round2($grand),
                'source'        => 'modification_draft',
            ];

            // extend cart expiry (same as controller)
            $timeoutMin = (int) config('business.cart_timeout_minutes', 30);
            $newExpires = now()->addMinutes($timeoutMin);

            DB::table('channel_carts')->where('id', $channelCartId)->update([
                'expires_at' => $newExpires,
                'updated_at' => now(),
            ]);

            // create cart_itemms
            $itemId = DB::table('cart_itemms')->insertGetId([
                'channel_cart_id'       => $channelCartId,
                'site_id'               => (string)$siteId,
                'start_date'            => $startDate,
                'end_date'              => $endDate,
                'occupants_json'        => json_encode($payload['occupants']),
                'addons_json'           => json_encode($payload['add_ons']),
                'price_snapshot_json'   => json_encode($priceSnapshot),
                'dedupe_key'            => $dedupeKey,
                'hold_expires_at'       => $newExpires,
                'status'                => 'active',
                'created_at'            => now(),
                'updated_at'            => now(),
            ]);

            // create inventory hold
            DB::table('inventory_holdds')->insert([
                'site_id'         => (string)$siteId,
                'start_date'      => $startDate,
                'end_date'        => $endDate,
                'channel_cart_id' => $channelCartId,
                'cart_item_id'    => $itemId,
                'hold_expires_at' => $newExpires,
                'created_at'      => now(),
                'updated_at'      => now(),
            ]);

            $newCartItemIds[$idx] = (int)$itemId;
            $newCartIds[$idx]     = 'ch_' . (int)$itemId;
            if (!$primaryCartId) $primaryCartId = $newCartIds[$idx];
        }

        if (!$primaryCartId) {
            DB::rollBack();
            return response()->json([
                'ok' => false,
                'message' => 'Unable to build any cart items for this draft',
                'errors' => ['cart_data' => $cartData],
            ], 400);
        }

        /* -------------------------------------------------------------
         | 7) Refund (ONE gateway refund total, allocate rows)
         * -------------------------------------------------------------*/
        $refunds = [];
        $refundPlan = [];
        $refundedTotal = 0.0;

        foreach ($originalReservations as $r) {
            $rid = (int)$r->id;
            $amt = (float)($refundAmountByReservationId[$rid] ?? 0);
            if ($amt > 0) {
                $refundPlan[] = ['reservation' => $r, 'amount' => $amt];
                $refundedTotal += $amt;
            }
        }

        $gatewayRefundXRef = null;
        $gatewayRefundResp = null;
        $gatewayRefundStatus = 'manual';

        if ($origIsGateway && $refundedTotal > 0) {
            $post = [
                'xKey'             => config('services.cardknox.api_key'),
                'xVersion'         => '4.5.5',
                'xCommand'         => 'cc:Refund',
                'xAmount'          => $round2($refundedTotal),
                'xRefNum'          => $origGatewayRef,
                'xInvoice'         => 'REF-' . uniqid() . '-' . now()->format('YmdHis'),
                'xSoftwareName'    => 'KayutaLake',
                'xSoftwareVersion' => '1.0',
            ];

            $ch = curl_init('https://x1.cardknox.com/gateway');
            curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_POSTFIELDS     => http_build_query($post),
                CURLOPT_HTTPHEADER     => [
                    'Content-type: application/x-www-form-urlencoded',
                    'X-Recurring-Api-Version: 1.0',
                ],
            ]);

            $raw = curl_exec($ch);
            if ($raw === false) {
                DB::rollBack();
                return response()->json(['ok' => false, 'message' => 'Unable to contact refund gateway.'], 502);
            }

            parse_str($raw, $resp);
            $gatewayRefundResp = $resp;

            if (($resp['xStatus'] ?? '') !== 'Approved') {
                DB::rollBack();
                return response()->json([
                    'ok'      => false,
                    'message' => $resp['xError'] ?? 'Refund failed',
                    'errors'  => ['gateway' => $resp],
                ], 400);
            }

            $gatewayRefundXRef   = $resp['xRefNum'] ?? null;
            $gatewayRefundStatus = 'approved';
        }

        foreach ($refundPlan as $i => $row) {
            $r = $row['reservation'];
            $rid = (int)$r->id;
            $refundAmount = (float)$row['amount'];
            if ($refundAmount <= 0) continue;

            $refundMethod = $origPaymentMethod ?: 'Cash';
            $refundXRef   = null;
            $refundStatus = 'manual';
            $gatewayResp  = null;

            if ($origIsGateway) {
                $refundMethod = 'Card';
                $refundStatus = $gatewayRefundStatus;
                $refundXRef   = ($i === 0) ? $gatewayRefundXRef : null;
                $gatewayResp  = ($i === 0) ? $gatewayRefundResp : null;
            }

            $refundPayload = [
                'cartid'          => $primaryCartId, // ✅ ch_<cart_itemms.id>
                'reservations_id' => $rid,
                'amount'          => $refundAmount,
                'method'          => $refundMethod,
                'reason'          => 'Reservation Modification',
                'x_ref_num'       => $refundXRef,
                'created_by'      => auth()->user()->name ?? 'System',
            ];

            if ($hasColumn('refunds', 'status')) $refundPayload['status'] = $refundStatus;

            if ($hasColumn('refunds', 'meta')) {
                $refundPayload['meta'] = json_encode([
                    'type'                => 'modification_refund',
                    'draft_id'            => $draft->draft_id,
                    'original_payment_id' => $originalPaymentId,
                    'refund_mode'         => $origIsGateway ? 'gateway_one_refund_allocated' : 'manual',
                    'gateway_refund_xref' => $gatewayRefundXRef,
                    'gateway_response'    => $gatewayResp,
                ]);
            }

            $refundRow = \App\Model\Refund::create($refundPayload);

            $refunds[] = [
                'refund_id'      => (int)$refundRow->id,
                'reservation_id' => $rid,
                'siteid'         => (string)$r->siteid,
                'amount'         => $round2($refundAmount),
                'method'         => $refundMethod,
                'gateway_ref'    => $refundXRef,
                'status'         => $refundStatus,
            ];
        }

        /* -------------------------------------------------------------
         | 8) Cancel originals
         * -------------------------------------------------------------*/
        $cancelledReservations = [];
        foreach ($originalReservations as $r) {
            $r->status = 'Cancelled';
            if ($hasColumn('reservations', 'cancelled_at')) $r->cancelled_at = now();
            if ($hasColumn('reservations', 'cancel_reason')) $r->cancel_reason = 'Modification';
            $r->save();

            $cancelledReservations[] = [
                'reservation_id'  => (int)$r->id,
                'siteid'          => (string)$r->siteid,
                'refunded_amount' => $round2((float)($refundAmountByReservationId[$r->id] ?? 0)),
            ];
        }

        /* -------------------------------------------------------------
         | 9) Charge new total
         * -------------------------------------------------------------*/
        $newTotal = (float)$draft->grand_total;
        if ($newTotal < 0) $newTotal = 0;

        $paymentId = null;
        $paymentMethodLabel = '';
        $xRefNumNew = null;
        $xAuthCodeNew = '';
        $gatewaySaleResp = [];

        if ($newTotal > 0) {
            if ($v['payment_method'] === 'card') {
                $post = [
                    'xKey'             => config('services.cardknox.api_key'),
                    'xVersion'         => '4.5.5',
                    'xCommand'         => 'cc:Sale',
                    'xAmount'          => $newTotal,
                    'xCardNum'         => $v['xCardNum'],
                    'xExp'             => str_replace('/', '', $v['xExp']),
                    'xSoftwareVersion' => '1.0',
                    'xSoftwareName'    => 'KayutaLake',
                    'xInvoice'         => 'RECUR-' . uniqid() . '-' . now()->format('YmdHis'),
                ];

                $ch = curl_init('https://x1.cardknox.com/gateway');
                curl_setopt_array($ch, [
                    CURLOPT_RETURNTRANSFER => true,
                    CURLOPT_POSTFIELDS     => http_build_query($post),
                    CURLOPT_HTTPHEADER     => [
                        'Content-type: application/x-www-form-urlencoded',
                        'X-Recurring-Api-Version: 1.0',
                    ],
                ]);

                $raw = curl_exec($ch);
                if ($raw === false) {
                    DB::rollBack();
                    return response()->json(['ok' => false, 'message' => 'Unable to contact payment gateway.'], 502);
                }

                parse_str($raw, $sale);
                $gatewaySaleResp = $sale;

                if (($sale['xStatus'] ?? '') !== 'Approved') {
                    DB::rollBack();
                    return response()->json([
                        'ok' => false,
                        'message' => $sale['xError'] ?? 'Payment failed',
                        'errors' => ['gateway' => $sale],
                    ], 400);
                }

                $paymentMethodLabel = $sale['xCardType'] ?? 'Card';
                $xRefNumNew = $sale['xRefNum'] ?? null;
                $xAuthCodeNew = $sale['xAuthCode'] ?? ('MOD-' . uniqid());

            } elseif ($v['payment_method'] === 'cash') {
                $cashTendered = (float)$v['cash_tendered'];
                if ($cashTendered < $newTotal) {
                    DB::rollBack();
                    return response()->json([
                        'ok' => false,
                        'message' => 'Cash tendered must be at least the new total amount.',
                        'errors' => ['cash_tendered' => ['Not enough cash tendered']],
                    ], 422);
                }

                $paymentMethodLabel = 'Cash';
                $xAuthCodeNew = 'CASH-' . now()->format('YmdHis') . '-' . uniqid();
            } else {
                $paymentMethodLabel = ucfirst(str_replace('_', ' ', $v['payment_method']));
                $xAuthCodeNew = strtoupper($v['payment_method']) . '-' . strtoupper(uniqid());
            }

            $paymentPayload = [
                'customernumber' => $customer->id,
                'method'         => $paymentMethodLabel ?: ucfirst($v['payment_method']),
                'cartid'         => $primaryCartId,
                'email'          => $customer->email,
                'payment'        => $newTotal,
                'receipt'        => rand(1000, 9999),
                'x_ref_num'      => $xRefNumNew,
            ];

            if ($hasColumn('payments', 'meta')) {
                $paymentPayload['meta'] = json_encode([
                    'type'            => 'modification',
                    'draft_id'        => $draft->draft_id,
                    'channel_cart_id' => $channelCartId,
                    'refunded_total'  => $round2($refundedTotal),
                    'sale_gateway'    => $gatewaySaleResp,
                    'refund_gateway'  => $gatewayRefundResp,
                    'refund_xref'     => $gatewayRefundXRef,
                    'primary_cartid'  => $primaryCartId,
                ]);
            }

            $paymentRow = \App\Model\Payment::create($paymentPayload);
            $paymentId = $paymentRow->id ?? null;
        }

        /* -------------------------------------------------------------
         | 10) Create NEW reservations (cartid = ch_<cart_itemms.id>)
         * -------------------------------------------------------------*/
        $groupConfirmationCode = 'CONF-' . strtoupper(substr(md5(uniqid('', true)), 0, 10));
        $newReservations = [];

        foreach ($cartData as $idx => $item) {
            if (!is_array($item)) continue;

            $siteid = $item['siteid'] ?? $item['site_id'] ?? $item['id'] ?? null;
            if (!$siteid) continue;

            $site     = \App\Model\Site::where('siteid', $siteid)->first();
            $rateTier = $site ? \App\Model\RateTier::where('tier', $site->hookup)->first() : null;

            $inDate  = !empty($item['start_date']) ? Carbon::parse($item['start_date'])->format('Y-m-d') : null;
            $outDate = !empty($item['end_date'])   ? Carbon::parse($item['end_date'])->format('Y-m-d')   : null;

            $inTime = ($rateTier && !empty($rateTier->check_in))
                ? Carbon::parse($rateTier->check_in)->format('H:i:s')
                : '15:00:00';

            $outTime = ($rateTier && !empty($rateTier->check_out))
                ? Carbon::parse($rateTier->check_out)->format('H:i:s')
                : '11:00:00';

            $scheduledCid = $inDate  ? "{$inDate} {$inTime}"   : null;
            $scheduledCod = $outDate ? "{$outDate} {$outTime}" : null;

            $cartidForThisReservation = $newCartIds[$idx] ?? null;
            if (!$cartidForThisReservation) {
                DB::rollBack();
                return response()->json([
                    'ok' => false,
                    'message' => 'Missing cartid for reservation (cart_itemms not created)',
                    'errors' => ['idx' => $idx],
                ], 500);
            }

            $base     = (float)($item['base'] ?? 0);
            $sitelock = (float)($item['lock_fee_amount'] ?? 0);

            $totalCharges = (float)(
                $item['grand_total']
                ?? $item['total']
                ?? ($base + $sitelock)
            );

            $reservationData = [
                'xconfnum'       => $xAuthCodeNew ?: ('MOD-' . uniqid()),
                'cartid'         => $cartidForThisReservation, // ✅ ch_<cart_itemms.id>
                'source'         => 'Online Booking',
                'createdby'      => 'API',
                'fname'          => $customer->f_name ?? '',
                'lname'          => $customer->l_name ?? '',
                'customernumber' => $customer->id,
                'siteid'         => $siteid,
                'cid'            => $scheduledCid,
                'cod'            => $scheduledCod,
                'siteclass'      => $item['siteclass'] ?? ($site->siteclass ?? null),
                'subtotal'       => (float)($item['subtotal'] ?? $base),
                'totaltax'       => 0,
                'nights'         => ($inDate && $outDate) ? Carbon::parse($outDate)->diffInDays(Carbon::parse($inDate)) : 1,
                'sitelock'       => $sitelock,
                'receipt'        => rand(1000, 9999),
                'totalcharges'   => $totalCharges,
                'total'          => $totalCharges,
            ];

            $addonsPayload = $normalizeAddons($item['add_ons'] ?? $item['addons_json'] ?? []);
            if (!empty($addonsPayload) && $hasColumn('reservations', 'addons_json')) {
                $reservationData['addons_json'] = $addonsPayload;
            }

            if ($paymentId && $hasColumn('reservations', 'payment_id')) {
                $reservationData['payment_id'] = $paymentId;
            }

            if ($hasColumn('reservations', 'group_confirmation_code')) {
                $reservationData['group_confirmation_code'] = $groupConfirmationCode;
            }

            $reservation = \App\Model\Reservation::create($reservationData);

            if ($hasColumn('reservations', 'confirmation_code') && empty($reservation->confirmation_code)) {
                $tries = 0;
                do {
                    $tries++;
                    $code = $generateConfirmationCode();
                    $exists = \App\Model\Reservation::where('confirmation_code', $code)->exists();
                } while ($exists && $tries < 5);

                $reservation->confirmation_code = $code;
                $reservation->save();
            }

            $newReservations[] = [
                'reservation_id'    => (int)$reservation->id,
                'siteid'            => (string)$siteid,
                'cid'               => (string)$reservation->cid,
                'cod'               => (string)$reservation->cod,
                'confirmation_code' => $reservation->confirmation_code ?? null,
                'payment_id'        => $paymentId,
                'cartid'            => $cartidForThisReservation,
                'cart_itemm_id'     => (int)($newCartItemIds[$idx] ?? 0),
            ];
        }

        /* -------------------------------------------------------------
         | 11) Finalize draft
         * -------------------------------------------------------------*/
        $draft->status = 'confirmed';
        if ($hasColumn('reservation_drafts', 'payment_id')) $draft->payment_id = $paymentId;
        $draft->save();

        DB::commit();

        return response()->json([
            'ok' => true,
            'draft_id' => $draft->draft_id,
            'channel_cart_id' => $channelCartId,
            'primary_cartid' => $primaryCartId,

            'refunds' => $refunds,
            'refund_summary' => [
                'count' => count($refunds),
                'total' => $round2($refundedTotal),
            ],

            'payment' => [
                'payment_id'  => $paymentId,
                'method'      => $paymentMethodLabel ?: ucfirst($v['payment_method']),
                'amount'      => $round2($newTotal),
                'gateway_ref' => $xRefNumNew,
                'cartid'      => $primaryCartId,
            ],

            'new_reservations' => $newReservations,
            'cancelled_reservations' => $cancelledReservations,
        ], 200);

    } catch (\Throwable $e) {
        DB::rollBack();

        return response()->json([
            'ok' => false,
            'message' => 'Modification checkout failed',
            'error' => $e->getMessage(),
            'file' => $e->getFile(),
            'line' => $e->getLine(),
        ], 500);
    }
}


    private function normalizeAddons($addons)
    {
        if (empty($addons)) return [];
        if (is_string($addons)) {
            $decoded = json_decode($addons, true);
            return is_array($decoded) ? $decoded : [];
        }
        return is_array($addons) ? $addons : [];
    }
}
