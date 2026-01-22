<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Models\Site;
use App\Models\SiteClass;
use App\Models\SiteHookup;
use App\Models\RateTier;
use App\Models\BusinessSettings;
use App\Models\ReservationDraft;
use App\Models\Addon;
use App\Models\Coupon;
use App\Models\User; // Added
use App\Models\CartReservation; // Added
use App\Models\Reservation; // Added
use App\Models\Payment; // Added
use App\Models\GiftCard; // Added
use App\Services\ReservationLogService; // Added
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\DB; // Added
use Illuminate\Support\Str;
use App\Models\Infos;
use Carbon\Carbon;

class FlowReservationController extends Controller
{
    public function step1(Request $request)
    {
        $siteClasses = SiteClass::orderBy('siteclass')->get();
        $siteHookups = SiteHookup::orderBy('orderby')->get();
        
        $draft = null;
        if ($request->has('draft_id')) {
            $draft = ReservationDraft::where('draft_id', $request->draft_id)->first();
            if (!$draft) {
                \Log::warning('Reservation draft not found for ID: ' . $request->draft_id);
            }
        }
        
        // Tax calculation removed as per user request
        
        return view('flow-reservation.step1', compact('siteClasses', 'siteHookups', 'draft'));
    }

    public function saveDraft(Request $request)
    {
        $request->validate([
            'cart_data' => 'required|array',
            'totals' => 'required|array',
            'discount_reason' => 'nullable|string',
            'coupon_code' => 'nullable|string',
            'draft_id' => 'nullable|string', // Allow passing existing draft ID
        ]);

        $existingDraft = null;
        if ($request->draft_id) {
            $existingDraft = ReservationDraft::where('draft_id', $request->draft_id)->first();
        }

        $draftId = $existingDraft ? $existingDraft->draft_id : (string) Str::uuid();
        
        $data = [
            'draft_id' => $draftId,
            'cart_data' => $request->cart_data,
            'subtotal' => $request->totals['subtotal'] ?? 0,
            'discount_total' => $request->totals['discount_total'] ?? 0,
            'estimated_tax' => 0, // Tax calculation removed
            'platform_fee_total' => $request->totals['platform_fee_total'] ?? 0,
            'discount_reason' => $request->input('discount_reason'),
            'coupon_code'    => $request->input('coupon_code'),
        ];

        // Modification Context Preservation
        if ($existingDraft) {
            $data['is_modification'] = $existingDraft->is_modification;
            $data['credit_amount'] = $existingDraft->credit_amount;
            $data['original_reservation_ids'] = $existingDraft->original_reservation_ids;
            $data['customer_id'] = $existingDraft->customer_id; // Preserve customer
            
            // Recalculate Grand Total with Credit
            $grandTotal = ($request->totals['grand_total'] ?? 0);
            
            // If the frontend didn't already deduct the credit (it likely didn't in Step 1 JS),
            // and we are ensuring the backend record is correct.
            // Actually, Step 1 JS calculates Grand Total = Subtotal - Discount. 
            // It doesn't know about Credit yet usually (unless we update JS too). 
            // But even if JS sends a Grand Total, we should apply Credit logic here for the DB record.
            
            // Wait, if JS sent grand_total = 175, and credit is 175.
            // We should store grand_total as 0.
            
            // Let's recalculate grand_total based on subtotal - discount - credit
            $subtotal = $data['subtotal'];
            $discount = $data['discount_total'];
            $credit = $data['credit_amount'] ?? 0;
            
            $data['grand_total'] = max(0, $subtotal - $discount - $credit);
            
            $existingDraft->update($data);
            $draft = $existingDraft;
        } else {
             $data['grand_total'] = $request->totals['grand_total'] ?? 0;
             $draft = ReservationDraft::create($data);
        }

        return response()->json([
            'success' => true,
            'draft_id' => $draftId,
            'redirect_url' => route('flow-reservation.step2', ['draft_id' => $draftId])
        ]);
    }

    public function applyCoupon(Request $request)
    {
        $data = $request->validate([
            'code' => ['required', 'string', 'max:64'],
            'subtotal' => ['required', 'numeric', 'min:0'],
        ]);

        $code = trim($data['code']);
        $subtotal = (float) $data['subtotal'];
        $today = now()->toDateString();

        $coupon = DB::table('coupons')
            ->whereRaw('LOWER(`code`) = ?', [mb_strtolower($code)])
            ->where('status', 1)
            ->where(function ($q) use ($today) {
                $q->whereNull('start_date')->orWhere('start_date', '<=', $today);
            })
            ->where(function ($q) use ($today) {
                $q->whereNull('expire_date')->orWhere('expire_date', '>=', $today);
            })
            ->first();

        if (!$coupon) {
            return response()->json(['success' => false, 'message' => 'Invalid or expired coupon.'], 422);
        }

        $minPurchase = (float) $coupon->min_purchase;
        if ($minPurchase > 0 && $subtotal < $minPurchase) {
            return response()->json(['success' => false, 'message' => 'Minimum purchase of $' . $minPurchase . ' not met.'], 422);
        }

        if (!is_null($coupon->limit)) {
            $used = DB::table('reservations')->where('discountcode', $coupon->code)->count();
            if ($used >= (int) $coupon->limit) {
                return response()->json(['success' => false, 'message' => 'Coupon redemption limit reached.'], 422);
            }
        }

        $discountType = strtolower((string) $coupon->discount_type);
        $rawDiscount = (float) $coupon->discount;
        $maxDiscount = (float) $coupon->max_discount;

        $discountAmount = 0.0;
        if (in_array($discountType, ['percentage', 'percent'], true)) {
            $discountAmount = round($subtotal * ($rawDiscount / 100), 2);
        } else {
            $discountAmount = round(min($rawDiscount, $subtotal), 2);
        }

        if ($maxDiscount > 0) {
            $discountAmount = min($discountAmount, $maxDiscount);
        }

        return response()->json([
            'success' => true,
            'code' => $coupon->code,
            'discount_amount' => $discountAmount,
            'label' => $coupon->title ?: 'Coupon'
        ]);
    }

    public function step2(Request $request, $draft_id)
    {
        $draft = ReservationDraft::where('draft_id', $draft_id)->firstOrFail();
        
        if ($draft->status === 'confirmed') {
            return redirect()->to('admin/reservations/invoice/' . $draft->draft_id);
        }

        $primaryCustomer = $draft->customer_id ? User::find($draft->customer_id) : null;
        
        return view('flow-reservation.step2', compact('draft', 'primaryCustomer'));
    }

    public function updateCustomer(Request $request, $draft_id)
    {
        $draft = ReservationDraft::where('draft_id', $draft_id)->firstOrFail();
        
        $validated = $request->validate([
            'customer_id' => 'nullable|integer',
            'primary' => 'nullable|array',
            'guest_data' => 'nullable|array',
        ]);

        $customerId = $validated['customer_id'] ?? null;
        $primary = $validated['primary'] ?? [];

        // If creating a new customer
        if (!$customerId && !empty($primary['f_name'])) {
            // Check if user already exists by email if provided
            $user = null;
            if (!empty($primary['email'])) {
                $user = \App\Models\User::where('email', $primary['email'])->first();
            }

            if (!$user) {
                $user = \App\Models\User::create([
                    'f_name' => $primary['f_name'],
                    'l_name' => $primary['l_name'] ?? '',
                    'email' => $primary['email'] ?? null,
                    'phone' => $primary['phone'] ?? null,
                    'street_address' => $primary['street_address'] ?? null,
                    'city' => $primary['city'] ?? null,
                    'state' => $primary['state'] ?? null,
                    'zip' => $primary['zip'] ?? null,
                    'password' => \Illuminate\Support\Facades\Hash::make(\Illuminate\Support\Str::random(12)),
                ]);
            }
            $customerId = $user->id;
        } elseif ($customerId) {
            // Update existing customer
            $user = \App\Models\User::find($customerId);
            if ($user) {
                $user->update([
                    'f_name' => $primary['f_name'] ?? $user->f_name,
                    'l_name' => $primary['l_name'] ?? $user->l_name,
                    'email' => $primary['email'] ?? $user->email,
                    'phone' => $primary['phone'] ?? $user->phone,
                    'street_address' => $primary['street_address'] ?? $user->street_address,
                    'city' => $primary['city'] ?? $user->city,
                    'state' => $primary['state'] ?? $user->state,
                    'zip' => $primary['zip'] ?? $user->zip,
                ]);
            }
        }

        $draft->customer_id = $customerId;
        if ($request->has('guest_data')) {
            $draft->guest_data = $validated['guest_data'];
        }

        $draft->save();

        return response()->json([
            'success' => true,
            'message' => 'Customer information updated successfully.',
            'customer_id' => $customerId
        ]);
    }


    public function removeItem(Request $request, $draft_id)
    {
        $draft = ReservationDraft::where('draft_id', $draft_id)->firstOrFail();
        $index = $request->input('index');
        
        $cart = $draft->cart_data;
        if (isset($cart[$index])) {
            array_splice($cart, $index, 1);
            $draft->cart_data = $cart;
            
            // Recalculate totals
            $subtotal = 0;
            $platformFeeTotal = 0;
            foreach ($cart as $item) {
                $subtotal += ($item['base'] ?? 0) + ($item['fee'] ?? 0);
                $platformFeeTotal += $item['fee'] ?? 0;
            }

            $discount = $draft->discount_total;
            $subtotalAfterDiscount = max(0, $subtotal - $discount);
            // Tax calculation removed as per user request
            
            $draft->subtotal = $subtotal;
            $draft->platform_fee_total = $platformFeeTotal;
            $draft->estimated_tax = 0;
            $draft->grand_total = $subtotalAfterDiscount; // No tax added
            
            $draft->save();
        }

        return response()->json([
            'success' => true,
            'draft' => $draft
        ]);
    }


    public function search(Request $request)
    {
        $validated = $request->validate([
            'start_date' => ['required', 'date'],
            'end_date' => ['required', 'date', 'after:start_date'],
            'siteclass' => ['nullable', 'string'],
            'hookup' => ['nullable', 'string'],
            'rig_length' => ['nullable', 'numeric', 'min:0', 'max:100'],
        ]);

        $query = [
            'start_date' => $validated['start_date'],
            'end_date' => $validated['end_date'],
            'with_prices' => true,
            'view' => 'units',
        ];

        if (!empty($validated['siteclass'])) $query['siteclass'] = $validated['siteclass'];
        if (!empty($validated['hookup'])) $query['hookup'] = $validated['hookup'];
        if (!empty($validated['rig_length'])) $query['rig_length'] = $validated['rig_length'];

        try {
            $response = Http::withHeaders([
                'Accept' => 'application/json',
                'Authorization' => 'Bearer ' . env('BOOKING_BEARER_KEY'),
            ])->get(env('BOOK_API_URL') . 'v1/availability', $query);

            if (!$response->successful()) {
                return response()->json(['ok' => false, 'message' => 'API Error'], 500);
            }

            $data = $response->json();
            if (isset($data['response']['results']['units'])) {
                $units = collect($data['response']['results']['units'])
                    ->filter(fn($u) => isset($u['status']['available']) && $u['status']['available'] === true)
                    ->values();

                // 1. Filter by Rig Length
                if (!empty($validated['rig_length'])) {
                    $riglength = (float) $validated['rig_length'];
                    $units = $units->filter(function ($unit) use ($riglength) {
                        $max = isset($unit['maxlength']) ? (float) $unit['maxlength'] : null;
                        return $max !== null && $riglength <= $max;
                    })->values();
                }

                // 2. Filter by Site Class
                if (!empty($validated['siteclass'])) {
                    $siteclass = str_replace(' ', '_', trim($validated['siteclass']));
                    $units = $units->filter(function ($unit) use ($siteclass) {
                        $classes = isset($unit['class']) ? collect(explode(',', $unit['class']))->map(fn($c) => str_replace(' ', '_', trim($c))) : collect();
                        return $classes->contains($siteclass);
                    })->values();
                }

                // 3. Filter by Hookup
                if (!empty($validated['hookup'])) {
                    $hookup = str_replace(' ', '_', trim($validated['hookup']));
                    $units = $units->filter(function ($unit) use ($hookup) {
                        $unitHookup = isset($unit['hookup']) ? str_replace(' ', '_', trim($unit['hookup'])) : null;
                        return $unitHookup === $hookup;
                    })->values();
                }
            } else {
                $units = collect([]);
            }

            return response()->json([
                'ok' => true,
                'data' => [
                    'response' => [
                        'results' => [
                            'units' => $units
                        ],
                        'view' => 'units'
                    ]
                ],
                'platform_fee' => BusinessSettings::where('type', 'platform_fee')->value('value') ?? 5.00,
                'site_lock_fee' => BusinessSettings::where('type', 'site_lock_fee')->value('value') ?? 0,
            ]);

        } catch (\Exception $e) {
            return response()->json(['ok' => false, 'message' => $e->getMessage()], 500);
        }
    }

     public function finalize(Request $request, $draft_id)
    {
        $draft = ReservationDraft::where('draft_id', $draft_id)->firstOrFail();

        if (!$draft->customer_id) {
            return response()->json(['success' => false, 'message' => 'Customer must be bound before finalization.'], 422);
        }

        $customer = User::findOrFail($draft->customer_id);

        try {
            // Step 1: Create cart in external API
            $cartResponse = Http::withHeaders([
                'Accept' => 'application/json',
                'Authorization' => 'Bearer ' . env('BOOKING_BEARER_KEY'),
            ])->post(env('BOOK_API_URL') . 'v1/cart', [
                'utm_source' => 'rvparkhq',
                'utm_medium' => 'referral',
                'utm_campaign' => 'flow_reservation',
            ]);


            if ($cartResponse->failed()) {
                Log::error('Failed to create cart in external API', [
                    'status' => $cartResponse->status(),
                    'body' => $cartResponse->body(),
                ]);
                return response()->json([
                    'success' => false,
                    'message' => 'Failed to initialize cart with booking service.',
                ], 500);
            }

            $cartData = $cartResponse->json();
            $externalCartId = $cartData['data']['cart_id'] ?? null;
            $externalCartToken = $cartData['data']['cart_token'] ?? null;

            if (!$externalCartId || !$externalCartToken) {
                Log::error('External API did not return cart ID or token', ['response' => $cartData]);
                return response()->json([
                    'success' => false,
                    'message' => 'Invalid response from booking service.',
                ], 500);
            }



            // Step 2: Add items to external cart
            foreach ($draft->cart_data as $item) {

                $itemResponse = Http::withHeaders([
                    'Accept' => 'application/json',
                    'Authorization' => 'Bearer ' . env('BOOKING_BEARER_KEY'),
                ])->post(env('BOOK_API_URL') . 'v1/cart/items', [
                    'cart_id' => $externalCartId,
                    'token' => $externalCartToken,
                    'site_id' => $item['id'],
                    'start_date' => $item['start_date'] ?? $item['cid'],
                    'end_date' => $item['end_date'] ?? $item['cod'],
                    'occupants' => [
                      'adults'   => $item['occupants']['adults'] ?? 2,
                      'children' => $item['occupants']['children'] ?? 0,
                    ],

                    'site_lock_fee' => (($item['site_lock_fee'] ?? 'off') === 'on') 
                        ? (float) (BusinessSettings::where('type', 'site_lock_fee')->value('value') ?? 0) 
                        : 0,
                 ]);


                if ($itemResponse->failed()) {
                    Log::error('Failed to add item to external cart', [
                        'status' => $itemResponse->status(),
                        'body' => $itemResponse->body(),
                        'item' => $item,
                    ]);
                    // Continue with other items or fail completely?
                    // For now, we'll continue
                }
            }

            // Step 3: Map payment method from POS drawer format to API format
            $paymentMethod = $request->payment_method ?? 'Cash';
            $apiPaymentMethod = 'cash'; // default
            $paymentData = [];

            switch ($paymentMethod) {
                case 'CreditCard':
                case 'Visa':
                case 'MasterCard':
                case 'Amex':
                case 'Discover':
                case 'Manual':
                    // If it's a POS swipe (has x_ref_num), we treat it as paid externally ('cash' for the booking API)
                    if ($request->x_ref_num) {
                        $apiPaymentMethod = 'cash';
                        $paymentData = [
                            'cash_tendered' => $request->amount ?? $draft->grand_total,
                            'external_ref' => $request->x_ref_num
                        ];
                    } else {
                        $apiPaymentMethod = 'card';
                        $paymentData = [
                           
                                'xCardNum' => $request->xCardNum ?? '',
                                'xExp' => $request->xExp ?? '',
                                'cvv' => $request->cvv ?? '',
                          
                        ];
                    }
                    break;
                case 'Check':
                    $apiPaymentMethod = 'ach';
                    $paymentData = [
                        'ach' => [
                            'routing' => $request->xRouting ?? '',
                            'account' => $request->xAccount ?? '',
                            'name' => $request->xName ?? ($customer->f_name . ' ' . $customer->l_name),
                        ]
                    ];
                    break;
                case 'GiftCard':
                case 'Gift Card':
                    $apiPaymentMethod = 'gift_card';
                    $paymentData = [
                        'gift_card_code' => $request->xBarcode ?? $request->gift_card_code ?? '',
                    ];
                    break;
                case 'Cash':
                default:
                    $apiPaymentMethod = 'cash';
                    $paymentData = [
                        'cash_tendered' => $request->amount ?? $draft->grand_total,
                    ];
                    break;
            }



            // Step 4: Prepare checkout data for external API
            $checkoutData = array_merge([
                'payment_method' => $apiPaymentMethod,
                'xAmount' => $request->amount ?? $draft->grand_total,
                'fname' => $customer->f_name,
                'lname' => $customer->l_name,
                'email' => $customer->email,
                'phone' => $customer->phone ?? '',
                'street_address' => $customer->street_address ?? '',
                'city' => $customer->city ?? '',
                'state' => $customer->state ?? '',
                'zip' => $customer->zip ?? '',
                'custId' => $customer->id,
                'api_cart' => [
        'cart_id'    => (string) $externalCartId,     // 👈 FIX
        'cart_token' => (string) $externalCartToken,  // 👈 SAFE
    ],
            ], $paymentData);



            // Step 5: Call external Checkout API
            $response = Http::withHeaders([
                'Accept' => 'application/json',
                'Authorization' => 'Bearer ' . env('BOOKING_BEARER_KEY'),
            ])->post(env('BOOK_API_URL') . 'v1/checkout', $checkoutData);

            if ($response->failed()) {
                Log::error('Checkout API failed', [
                    'status' => $response->status(),
                    'body' => $response->body(),
                    'draft_id' => $draft_id,
                ]);

                $errorMessage = $response->json()['message'] ?? 'Payment processing failed.';
                // If error message contains "email", replace it with a generic message
                if (str_contains(strtolower($errorMessage), 'email')) {
                    $errorMessage = 'Payment processing failed.';
                }

                return response()->json([
                    'success' => false,
                    'message' => $errorMessage,
                    'errors' => $response->json()['errors'] ?? [],
                ], $response->status());
            }

            // Handle gift card deduction
            if ($apiPaymentMethod === 'gift_card') {
                $giftCardCode = $paymentData['gift_card_code'] ?? null;
                if ($giftCardCode) {
                    GiftCard::where('barcode', $giftCardCode)->decrement('amount', $draft->grand_total);
                }
            }

            // Mark draft as confirmed
            $draft->status = 'confirmed';
            $draft->external_cart_id = $externalCartId;
            $draft->save();

            $apiResponse = $response->json();
            
            // Return JSON for AJAX handler to trigger success message and redirect
            return response()->json([
                'success' => true,
                'order_id' => $draft->draft_id,
                'message' => 'Reservation confirmed successfully.'
            ]);

        } catch (\Exception $e) {
            Log::error("Finalize Error: " . $e->getMessage(), [
                'draft_id' => $draft_id,
                'trace' => $e->getTraceAsString(),
            ]);
            
            return response()->json([
                'success' => false,
                'message' => 'Error connecting to booking service: ' . $e->getMessage()
            ], 500);
        }
    }
    public function viewSiteDetails(Request $request)
    {
        $data = $request->validate([
            'site_id' => ['required', 'string'],
            'uscid' => ['required', 'date'],
            'uscod' => ['required', 'date'],
        ]);

        try {
            $response = Http::withHeaders([
                'Accept' => 'application/json',
                'Authorization' => 'Bearer ' . env('BOOKING_BEARER_KEY'),
            ])->get(env('BOOK_API_URL') . "v1/sites/{$data['site_id']}", $data);

            if ($response->successful()) {
                return response()->json($response->json(), 200);
            }
        } catch (\Exception $e) {
            Log::error('Site details proxy failed', ['error' => $e->getMessage()]);

            return response()->json(
                [
                    'ok' => false,
                    'message' => 'Error connecting to booking service.',
                ],
                500,
            );
        }
    }

    public function information()
    {
        $information = Infos::where('show_in_details', 1)->orderBy('id', 'asc')->get();

        return response()->json(['information' => $information]);
    }

    /**
     * Get the tax rate from business settings
     * 
     * @return float
     */
    private function getTaxRate()
    {
        return (float) (BusinessSettings::where('type', 'reservation_tax_rate')->value('value') ?? 0.07);
    }
    /**
     * Finalize Modification - Dedicated Method
     * 
     * @param Request $request
     * @param string $draft_id
     * @return \Illuminate\Http\JsonResponse
     */
    public function finalizeModification(Request $request, $draft_id)
    {
        // Capture request data as required by "Technical / Development Rules"
        // In a real scenario, we would check environment to determine if we should actually die-dump,
        // but the rule explicitly says "Capture and inspect... using dd($request->all())".
        // To allow the flow to be "tested" without breaking the browser experience completely immediately (unless expected),
        // I will log it first, but the requirement is pretty strict.
        
        // However, for the purpose of this agent task where I need to verify behavior, 
        // I will output it or return it.
        // "Inside finalizeModification(): Capture and inspect... dd($request->all());"
        
        dd($request->all());
    }
}
