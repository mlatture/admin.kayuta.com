<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Auth;
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
        try { return \Schema::hasColumn($table, $column); } catch (\Throwable $e) { return false; }
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
                'total'       => (float) ($it['total'] ?? ($snap['total'] ?? 0)),
                'totaltax'    => (float) ($snap['tax'] ?? 0),
                'subtotal'    => (float) ($snap['subtotal'] ?? 0),
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


 public function modificationReservations(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'draft_id'        => 'required|string|max:64',
            'customer_id'     => 'required|integer|min:1',
            'payment_method'  => 'required|in:card,ach,cash,gift_card',

            // card
            'xCardNum'        => 'required_if:payment_method,card|digits_between:13,19',
            'xExp'            => ['required_if:payment_method,card', 'regex:/^(0[1-9]|1[0-2])\/?([0-9]{2})$/'],

            // cash
            'cash_tendered'   => 'required_if:payment_method,cash|numeric|min:0.01',

            // refund behavior
            'refund_to_original' => 'nullable|boolean',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'ok'      => false,
                'message' => 'Validation failed.',
                'errors'  => $validator->errors(),
            ], 422);
        }

        $v = $validator->validated();

        $hasColumn = fn ($t, $c) => \Schema::hasColumn($t, $c);

        $itemKey = function (array $i) {
            $site = $i['siteid'] ?? $i['site_id'] ?? $i['id'] ?? '';
            return $site . '|' . ($i['start_date'] ?? '') . '|' . ($i['end_date'] ?? '');
        };

        try {
            DB::beginTransaction();

            /* -------------------------------------------------------------
             | 1. Load draft
             * -------------------------------------------------------------*/
            $draft = \App\Models\ReservationDraft::where('draft_id', $v['draft_id'])
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

            if ($draft->status !== 'draft') {
                DB::rollBack();
                return response()->json([
                    'ok' => false,
                    'message' => 'Draft already finalized',
                ], 409);
            }

            $cartData = is_string($draft->cart_data)
                ? json_decode($draft->cart_data, true)
                : (array)$draft->cart_data;

            if (empty($cartData)) {
                DB::rollBack();
                return response()->json(['ok' => false, 'message' => 'Draft cart empty'], 400);
            }

            $originalIds = is_string($draft->original_reservation_ids)
                ? json_decode($draft->original_reservation_ids, true)
                : (array)$draft->original_reservation_ids;

            $customer = \App\Models\User::findOrFail($draft->customer_id);

            /* -------------------------------------------------------------
             | 2. Load original reservations
             * -------------------------------------------------------------*/
            $originalReservations = \App\Models\Reservation::whereIn('id', $originalIds)->get();
            if ($originalReservations->isEmpty()) {
                DB::rollBack();
                return response()->json(['ok' => false, 'message' => 'Original reservations missing'], 400);
            }

            /* -------------------------------------------------------------
             | 3. Compute totals
             * -------------------------------------------------------------*/
            $newTotal = (float)$draft->grand_total;
            $credit   = (float)$draft->credit_amount;
            $net      = round($newTotal - $credit, 2);

            /* -------------------------------------------------------------
             | 4. Diff OLD vs NEW
             * -------------------------------------------------------------*/
            $oldMap = [];
            foreach ($originalReservations as $r) {
                $oldMap[$itemKey([
                    'siteid' => $r->siteid,
                    'start_date' => Carbon::parse($r->cid)->toDateString(),
                    'end_date'   => Carbon::parse($r->cod)->toDateString(),
                ])] = $r;
            }

            $newMap = [];
            foreach ($cartData as $item) {
                $newMap[$itemKey($item)] = $item;
            }

            $removed = array_diff_key($oldMap, $newMap);
            $added   = array_diff_key($newMap, $oldMap);
            $kept    = array_intersect_key($newMap, $oldMap);

            /* -------------------------------------------------------------
             | 5. Locate original payment
             * -------------------------------------------------------------*/
            $originalPaymentId = $originalReservations->pluck('payment_id')->filter()->first();
            $originalPayment   = $originalPaymentId
                ? \App\Models\Payment::find($originalPaymentId)
                : null;

            $paymentId = null;
            $refundId  = null;

            /* -------------------------------------------------------------
             | 6. NET > 0 → CHARGE
             * -------------------------------------------------------------*/
            if ($net > 0) {
                if ($v['payment_method'] === 'card') {
                    $post = [
                        'xKey'     => config('services.cardknox.api_key'),
                        'xVersion' => '4.5.5',
                        'xCommand' => 'cc:Sale',
                        'xAmount'  => $net,
                        'xCardNum' => $v['xCardNum'],
                        'xExp'     => str_replace('/', '', $v['xExp']),
                        'xInvoice' => 'MOD-' . uniqid(),
                    ];

                    $ch = curl_init('https://x1.cardknox.com/gateway');
                    curl_setopt_array($ch, [
                        CURLOPT_RETURNTRANSFER => true,
                        CURLOPT_POSTFIELDS => http_build_query($post),
                        CURLOPT_HTTPHEADER => ['Content-Type: application/x-www-form-urlencoded'],
                    ]);

                    parse_str(curl_exec($ch), $resp);

                    if (($resp['xStatus'] ?? '') !== 'Approved') {
                        DB::rollBack();
                        return response()->json(['ok' => false, 'message' => $resp['xError']], 400);
                    }

                    $payment = \App\Models\Payment::create([
                        'cartid'         => $draft->draft_id,
                        'receipt'        => rand(1000, 9999),
                        'method'         => $resp['xCardType'] ?? 'Card',
                        'customernumber' => $customer->id,
                        'email'          => $customer->email,
                        'payment'        => $net,
                        'x_ref_num'      => $resp['xRefNum'] ?? null,
                    ]);

                    $paymentId = $payment->id;
                }
            }

            /* -------------------------------------------------------------
             | 7. NET < 0 → REFUND
             * -------------------------------------------------------------*/
            if ($net < 0) {
                $refundAmount = abs($net);
                $origRef = $originalPayment->x_ref_num ?? null;

                if (!$origRef) {
                    DB::rollBack();
                    return response()->json(['ok' => false, 'message' => 'Original payment reference missing'], 422);
                }

                $post = [
                    'xKey'     => config('services.cardknox.api_key'),
                    'xVersion' => '4.5.5',
                    'xCommand' => 'cc:Refund',
                    'xAmount'  => $refundAmount,
                    'xRefNum'  => $origRef,
                    'xInvoice' => 'REF-' . uniqid(),
                ];

                $ch = curl_init('https://x1.cardknox.com/gateway');
                curl_setopt_array($ch, [
                    CURLOPT_RETURNTRANSFER => true,
                    CURLOPT_POSTFIELDS => http_build_query($post),
                ]);

                parse_str(curl_exec($ch), $resp);

                if (($resp['xStatus'] ?? '') !== 'Approved') {
                    DB::rollBack();
                    return response()->json(['ok' => false, 'message' => $resp['xError']], 400);
                }

                $refund = \App\Models\Refund::create([
                    'cartid'          => $draft->draft_id,
                    'reservations_id' => $originalReservations->first()->id ?? 0,
                    'amount'          => $refundAmount,
                    'method'          => 'Card',
                    'reason'          => 'Reservation Modification',
                    'x_ref_num'       => $resp['xRefNum'],
                    'created_by'      => auth()->user()->name ?? 'System',
                ]);

                $refundId = $refund->id;
            }

            /* -------------------------------------------------------------
             | 8. Apply reservation changes
             * -------------------------------------------------------------*/
            foreach ($removed as $r) {
                $r->update([
                    'status' => 'Cancelled',
                ]);
            }

            foreach ($kept as $key => $item) {
                $r = $oldMap[$key];
                $r->cid = Carbon::parse($item['start_date'])->setTime(15,0);
                $r->cod = Carbon::parse($item['end_date'])->setTime(11,0);
                $r->save();
            }

            $newReservationIds = [];
            foreach ($added as $item) {
                $res = \App\Models\Reservation::create([
                    'cartid' => $draft->draft_id,
                    'source' => 'Modification',
                    'email' => $customer->email,
                    'fname' => $customer->f_name ?? '',
                    'lname' => $customer->l_name ?? '',
                    'customernumber' => $customer->id,
                    'siteid' => $item['id'],
                    'cid' => Carbon::parse($item['start_date'])->setTime(15,0),
                    'cod' => Carbon::parse($item['end_date'])->setTime(11,0),
                    'total' => $item['base'] + ($item['lock_fee_amount'] ?? 0),
                    'subtotal' => $item['base'],
                    'totaltax' => 0, // Assuming tax already handled or not needed for mod
                    'siteclass' => $item['siteclass'] ?? '',
                    'nights' => Carbon::parse($item['end_date'])->diffInDays(Carbon::parse($item['start_date'])),
                    'base' => $item['base'],
                    'sitelock' => (float) ($item['lock_fee_amount'] ?? 0),
                    'rigtype' => $item['hookup'] ?? '',
                    'riglength' => $item['rig_length'] ?? 0,
                    'xconfnum' => 'MOD-' . rand(1000, 9999),
                    'createdby' => auth()->user()->name ?? 'system',
                    'receipt' => rand(1000, 9999),
                    'rid' => 'uc',
                ]);
                $newReservationIds[] = $res->id;
            }

            /* -------------------------------------------------------------
             | 9. Finalize draft
             * -------------------------------------------------------------*/
            $draft->update([
                'status' => 'confirmed',
            ]);

            DB::commit();

            return response()->json([
                'ok' => true,
                'draft_id' => $draft->draft_id,
                'new_total' => $newTotal,
                'credit' => $credit,
                'net' => $net,
                'payment_id' => $paymentId,
                'refund_id' => $refundId,
                'removed_reservations' => array_values(array_map(fn($r) => $r->id, $removed)),
                'new_reservation_ids' => $newReservationIds,
            ]);

        } catch (\Throwable $e) {
            DB::rollBack();
            return response()->json([
                'ok' => false,
                'message' => 'Modification checkout failed',
                'error' => $e->getMessage(),
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
