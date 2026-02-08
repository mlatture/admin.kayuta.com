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
use App\Models\Refund; // Added
use App\Models\GiftCard; // Added
use App\Models\Receipt; // Added
use App\Models\CardsOnFile; // Added
use App\Services\ReservationLogService; // Added
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\DB; // Added
use Illuminate\Support\Facades\Schema; // Added
use Illuminate\Support\Facades\Mail; // Added
use App\Mail\ReservationModified; // Added
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
        
        $originalReservations = collect();
        if ($draft && $draft->is_modification) {
            $originalResIds = json_decode($draft->original_reservation_ids ?? '[]', true);
            $originalReservations = Reservation::whereIn('id', $originalResIds)->with('site')->get();
        }
        
        return view('flow-reservation.step1', compact('siteClasses', 'siteHookups', 'draft', 'originalReservations'));
    }

    public function saveDraft(Request $request)
    {
        $request->validate([
            'draft_id' => 'nullable|string',
            'cart_data' => 'required|array',
            'totals' => 'required|array',
            'discount_reason' => 'nullable|string',
            'coupon_code' => 'nullable|string',
        ]);

        $draftId = $request->input('draft_id');
        $draft = null;

        if ($draftId) {
            $draft = ReservationDraft::where('draft_id', $draftId)->first();
        }

        if (!$draft) {
            $draftId = (string) Str::uuid();
            $draft = new ReservationDraft();
            $draft->draft_id = $draftId;
        }
        
        // Extract external_cart_id from items if available
        $externalCartId = null;
        if (!empty($request->cart_data)) {
            foreach ($request->cart_data as $item) {
                if (isset($item['external_cart_id'])) {
                    $externalCartId = $item['external_cart_id'];
                    break;
                }
            }
        }

        $draft->fill([
            'cart_data' => $request->cart_data,
            'subtotal' => $request->totals['subtotal'] ?? 0,
            'discount_total' => $request->totals['discount_total'] ?? 0,
            'estimated_tax' => 0, // Tax calculation removed
            'platform_fee_total' => $request->totals['platform_fee_total'] ?? 0,
            'grand_total' => $request->totals['grand_total'] ?? 0,
            'discount_reason' => $request->input('discount_reason'),
            'coupon_code'    => $request->input('coupon_code'),
        ]);
        
        if ($externalCartId) {
            $draft->external_cart_id = $externalCartId;
        }

        $draft->save();

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
        
        if ($draft->is_modification) {
            $summary = $this->getModificationSummary($draft);
            return view('flow-reservation.step2-modification', compact('draft', 'primaryCustomer', 'summary'));
        }

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

    public function addToCart(Request $request)
    {
        $validated = $request->validate([
            'id' => 'required',
            'cid' => 'required|date',
            'cod' => 'required|date',
            'occupants' => 'nullable|array',
            'site_lock_fee' => 'nullable|string', // 'on' or 'off'
        ]);

        try {
            // 1. Create or Update Cart (Always call as per instruction)
            $cartResponse = Http::withHeaders([
                'Accept' => 'application/json',
                'Authorization' => 'Bearer ' . env('BOOKING_BEARER_KEY'),
            ])->post(env('BOOK_API_URL') . 'v1/cart', [
                'utm_source' => 'rvparkhq',
                'utm_medium' => 'referral',
                'utm_campaign' => 'flow_reservation',
            ]);

            if ($cartResponse->failed()) {
                Log::error('Failed to init external cart', ['body' => $cartResponse->body()]);
                return response()->json(['success' => false, 'message' => 'Failed to initialize cart.'], 500);
            }

            $cartData = $cartResponse->json();
            $externalCartId = $cartData['data']['cart_id'] ?? null;
            $externalCartToken = $cartData['data']['cart_token'] ?? null;

            if (!$externalCartId || !$externalCartToken) {
                return response()->json(['success' => false, 'message' => 'Invalid cart response.'], 500);
            }

            // Fetch local site to get ratetier (hookup)
            $localSite = Site::where('siteid', $validated['id'])->first();
            $rateTier = $localSite ? $localSite->hookup : '';

            // 2. Add Item to that Cart
            $itemResponse = Http::withHeaders([
                'Accept' => 'application/json',
                'Authorization' => 'Bearer ' . env('BOOKING_BEARER_KEY'),
            ])->post(env('BOOK_API_URL') . 'v1/cart/items', [
                'cart_id' => $externalCartId,
                'token' => $externalCartToken,
                'site_id' => $validated['id'],
                'start_date' => $validated['cid'],
                'end_date' => $validated['cod'],
                'ratetier' => $rateTier,
                'occupants' => [
                    'adults'   => $validated['occupants']['adults'] ?? 2,
                    'children' => $validated['occupants']['children'] ?? 0,
                ],
                'site_lock_fee' => ($validated['site_lock_fee'] === 'on') 
                    ? (float) (BusinessSettings::where('type', 'site_lock_fee')->value('value') ?? 0) 
                    : 0,
            ]);

            if ($itemResponse->failed()) {
                Log::error('Failed to add item to external cart', ['body' => $itemResponse->body()]);
                return response()->json(['success' => false, 'message' => 'Failed to add item to cart.'], 500);
            }

            return response()->json([
                'success' => true,
                'external_cart_id' => $externalCartId,
                'external_cart_token' => $externalCartToken
            ]);

        } catch (\Exception $e) {
            Log::error('addToCart Exception', ['error' => $e->getMessage()]);
            return response()->json(['success' => false, 'message' => 'Server error adding to cart.'], 500);
        }
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
            // Retrieve cart info from draft or items
            $externalCartId = $draft->external_cart_id;
            $externalCartToken = null;
            
            // Try to find token in cart items
            if (!empty($draft->cart_data)) {
                foreach ($draft->cart_data as $item) {
                    if (isset($item['external_cart_token'])) {
                        $externalCartToken = $item['external_cart_token'];
                        break;
                    }
                }
                
                // Fallback for ID if not on draft model
                if (!$externalCartId) { 
                     foreach ($draft->cart_data as $item) {
                        if (isset($item['external_cart_id'])) {
                            $externalCartId = $item['external_cart_id'];
                            break;
                        }
                    }
                }
            }
            
            if (!$externalCartId || !$externalCartToken) {
                 return response()->json([
                    'success' => false,
                    'message' => 'Cart session expired or invalid. Please try creating a new reservation.',
                ], 422);
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
    private function getModificationSummary($draft)
    {
        $originalResIds = json_decode($draft->original_reservation_ids ?? '[]', true);
        $oldReservations = Reservation::whereIn('id', $originalResIds)->with('site')->get();
        $newItems = collect($draft->cart_data);
        
        $unchangedItems = collect();
        $addedItems = collect();
        $cancelledItems = collect();
        
        $matchedOldIds = [];
        $totalPriceAdded = 0;
        $totalPriceCancelled = 0;
        $totalPriceUnchanged = 0;
        
        // 1. Identify Unchanged and Added
        foreach($newItems as $item) {
            $siteId = $item['id'];
            $start = $item['start_date'];
            $end = $item['end_date'];
            
            // Find an exact match in original items
            $match = $oldReservations->filter(function($old) use ($siteId, $start, $end, $matchedOldIds) {
                return !in_array($old->id, $matchedOldIds) &&
                       (string)$old->siteid == (string)$siteId &&
                       $old->cid->format('Y-m-d') == $start &&
                       $old->cod->format('Y-m-d') == $end;
            })->first();
            
            if ($match) {
                $matchedOldIds[] = $match->id;
                $totalPriceUnchanged += (float)$match->total;
                $unchangedItems->push([
                    'site' => $match->site->sitename ?? $match->site->name ?? $match->siteid,
                    'dates' => $start . ' to ' . $end,
                    'original_paid' => (float)$match->total,
                    'new_charge' => 0,
                    'status' => 'KEEPING'
                ]);
            } else {
                $base = (float)($item['base'] ?? 0);
                $lock = (float)($item['lock_fee_amount'] ?? 0);
                $itemPrice = $base + $lock;
                $totalPriceAdded += $itemPrice;
                
                $addedItems->push([
                    'site' => $item['name'] ?? 'Unknown Site',
                    'dates' => ($item['start_date'] ?? 'N/A') . ' to ' . ($item['end_date'] ?? 'N/A'),
                    'charge_amount' => $itemPrice,
                    'status' => 'NEW CHARGE'
                ]);
            }
        }
        
        // 2. Identify Cancelled (Originals not present in New Selection)
        foreach($oldReservations as $old) {
            if (!in_array($old->id, $matchedOldIds)) {
                $totalPriceCancelled += (float)$old->total;
                $cancelledItems->push([
                    'id' => $old->id,
                    'site' => $old->site->sitename ?? $old->site->name ?? $old->siteid,
                    'dates' => $old->cid->format('Y-m-d') . ' to ' . $old->cod->format('Y-m-d'),
                    'refund_due' => (float)$old->total,
                    'status' => 'TO BE REFUNDED'
                ]);
            }
        }

        return [
            'financial_summary' => [
                'total_refunds' => $totalPriceCancelled,
                'total_new_charges' => $totalPriceAdded,
                'total_unchanged' => $totalPriceUnchanged,
                'net_difference' => $totalPriceAdded - $totalPriceCancelled,
                'grand_total_cart' => $draft->grand_total,
                'total_credit_available' => $draft->credit_amount,
            ],
            'item_breakdown' => [
                'added_items' => $addedItems,
                'cancelled_items' => $cancelledItems,
                'unchanged_items' => $unchangedItems
            ],
            'meta' => [
                'customer' => ($user = User::find($draft->customer_id)) ? $user->full_name : 'Unknown',
                'original_cart_id' => $draft->original_cart_id,
                'draft_id' => $draft->draft_id
            ]
        ];
    }

    public function finalizeModification(Request $request, $draft_id)
    {
        $draft = ReservationDraft::where('draft_id', $draft_id)->firstOrFail();
        
        $originalResIds = json_decode($draft->original_reservation_ids ?? '[]', true);
        $oldReservations = Reservation::whereIn('id', $originalResIds)->with('site')->get();

        if ($oldReservations->isEmpty()) {
            return response()->json(['success' => false, 'message' => 'Original reservations not found.'], 404);
        }

        $totalPaidOld = (float)$draft->credit_amount; 
        $grandTotalNew = (float)($draft->grand_total ?? 0);
        $delta = round($grandTotalNew - $totalPaidOld, 2);

        if (!$draft->customer_id) {
            return response()->json(['success' => false, 'message' => 'Customer must be bound before finalization.'], 422);
        }

        $customer = User::findOrFail($draft->customer_id);

        DB::beginTransaction();
        try {
            // 1. Determine Payment Method
            $paymentMethod = $request->payment_method ?? 'Cash';
            $apiPaymentMethod = 'cash'; // default
            
            if (in_array($paymentMethod, ['CreditCard', 'Visa', 'MasterCard', 'Amex', 'Discover', 'Manual'])) {
                $apiPaymentMethod = 'card';
            } elseif ($paymentMethod === 'Check') {
                $apiPaymentMethod = 'ach';
            } elseif (in_array($paymentMethod, ['GiftCard', 'Gift Card'])) {
                $apiPaymentMethod = 'gift_card';
            }

            $gatewayResponse = null;
            $xRefNum = null;
            $paymentMethodLabel = $paymentMethod;
            $xAuthCode = 'MOD-' . strtoupper(Str::random(10));

            // 2. Handle Gateway Transaction (Sale or Refund)
            if ($apiPaymentMethod === 'card' && $delta != 0) {
                $apiKey = config('services.cardknox.api_key');
                

                if ($delta > 0) {
                    // SALE for the delta
                    $postData = [
                        'xKey'             => $apiKey,
                        'xVersion'         => '4.5.5',
                        'xCommand'         => 'cc:Sale',
                        'xAmount'          => number_format($delta, 2, '.', ''),
                        'xCardNum'         => $request->xCardNum,
                        'xExp'             => str_replace(['/', ' '], '', $request->xExp),
                        'xSoftwareVersion' => '1.0',
                        'xSoftwareName'    => 'KayutaLake',
                        'xInvoice'         => 'MOD-SALE-' . $draft->draft_id,
                    ];
                } else {
                    // REFUND for the delta
                    // Try to find an original gateway reference to refund against
                    $originalPayment = Payment::whereIn('cartid', $oldReservations->pluck('cartid'))
                        ->whereNotNull('x_ref_num')
                        ->orderBy('id', 'desc')
                        ->first();
                    
                    if ($originalPayment) {
                        $postData = [
                            'xKey'             => $apiKey,
                            'xVersion'         => '4.5.5',
                            'xCommand'         => 'cc:Refund',
                            'xAmount'          => number_format(abs($delta), 2, '.', ''),
                            'xRefNum'          => $originalPayment->x_ref_num,
                            'xSoftwareVersion' => '1.0',
                            'xSoftwareName'    => 'KayutaLake',
                            'xInvoice'         => 'MOD-REF-' . $draft->draft_id,
                        ];
                    }
                }

                if (isset($postData)) {
                    $ch = curl_init('https://x1.cardknox.com/gateway');
                    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
                    curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($postData));
                    curl_setopt($ch, CURLOPT_HTTPHEADER, [
                        'Content-type: application/x-www-form-urlencoded',
                        'X-Recurring-Api-Version: 1.0',
                    ]);
                    $response = curl_exec($ch);
                    if ($response === false) {
                        throw new \Exception("Payment gateway connection failed: " . curl_error($ch));
                    }
                    parse_str($response, $gatewayResponse);

                    if (($gatewayResponse['xStatus'] ?? '') !== 'Approved') {
                        throw new \Exception("Gateway Error: " . ($gatewayResponse['xError'] ?? 'Transaction failed'));
                    }
                    $xRefNum = $gatewayResponse['xRefNum'] ?? null;
                    $xAuthCode = $gatewayResponse['xAuthCode'] ?? $xAuthCode;
                    $paymentMethodLabel = $gatewayResponse['xCardType'] ?? $paymentMethod;
                }

            } elseif ($apiPaymentMethod === 'cash' && $delta > 0) {
                // Cash tendered check
                $cashTendered = (float)($request->cash_tendered ?? $request->amount ?? $request->xAmount ?? 0);
                if ($cashTendered < $delta) {
                    throw new \Exception("Insufficient cash tendered. Need $" . number_format($delta, 2) . " (Got $" . number_format($cashTendered, 2) . ")");
                }
            } elseif ($apiPaymentMethod === 'gift_card' && $delta > 0) {
                $giftCardCode = $request->xBarcode ?? $request->gift_card_code;
                $giftCard = GiftCard::where('barcode', $giftCardCode)->first();
                if (!$giftCard || $giftCard->amount < $delta) {
                    throw new \Exception("Invalid gift card or insufficient balance.");
                }
                $giftCard->decrement('amount', $delta);
            }

            // 3. Create Receipt
            $receipt = Receipt::create(['cartid' => $draft->draft_id]);

            // 4. Record Payment or Refund
            $newPayment = null;
            if ($delta > 0) {
                $newPayment = Payment::create([
                    'cartid' => $draft->draft_id,
                    'method' => $paymentMethodLabel,
                    'customernumber' => $customer->id,
                    'email' => $customer->email,
                    'payment' => $delta,
                    'transaction_type' => 'Modification Sale',
                    'x_ref_num' => $xRefNum,
                    'receipt' => $receipt->id
                ]);
            } elseif ($delta < 0) {
                Refund::create([
                    'cartid' => $draft->draft_id,
                    'amount' => abs($delta),
                    'method' => $paymentMethodLabel,
                    'reason' => 'Reservation Modification',
                    'x_ref_num' => $xRefNum,
                    'created_by' => auth()->id() ?? 0,
                    'reservations_id' => $oldReservations->first()->id ?? null
                ]);
            }

            // 5. Build New Reservations (Atomic Swap)
            $cartData = is_array($draft->cart_data) ? $draft->cart_data : json_decode($draft->cart_data, true);
            $newReservationIds = [];
            
            // Generate a shared code for the successor group
            $groupConfirmationCode = $oldReservations->first()->group_confirmation_code ?? ('MOD-' . strtoupper(Str::random(10)));

            foreach ($cartData as $item) {
                $siteId = $item['id'] ?? $item['siteid'];
                $start = $item['start_date'] ?? $item['cid'];
                $end = $item['end_date'] ?? $item['cod'];
                
                // Scheduled check-in/out times
                $site = Site::where('siteid', $siteId)->first();
                $rateTier = $site ? RateTier::where('tier', $site->hookup)->first() : null;
                
                $inTime = ($rateTier && !empty($rateTier->check_in)) ? Carbon::parse($rateTier->check_in)->format('H:i:s') : '15:00:00';
                $outTime = ($rateTier && !empty($rateTier->check_out)) ? Carbon::parse($rateTier->check_out)->format('H:i:s') : '11:00:00';
                
                $scheduledCid = Carbon::parse($start)->format('Y-m-d') . ' ' . $inTime;
                $scheduledCod = Carbon::parse($end)->format('Y-m-d') . ' ' . $outTime;

                // Availability check (ignore originals)
                $overlap = Reservation::where('siteid', $siteId)
                    ->whereIn('status', ['confirmed', 'checkedin', 'Paid', 'Confirmed']) // broader check for status
                    ->whereNotIn('id', $originalResIds)
                    ->where(function ($q) use ($start, $end) {
                        $q->where('cid', '<', $end)
                          ->where('cod', '>', $start);
                    })->exists();

                if ($overlap) {
                    throw new \Exception("Site {$siteId} is no longer available for the selected dates ($start to $end).");
                }

                $itemBase = (float)($item['base'] ?? 0);
                $itemLock = (float)($item['lock_fee_amount'] ?? $item['fee'] ?? $item['site_lock_fee'] ?? 0);
                $itemTotal = $itemBase + $itemLock;

                $newRes = Reservation::create([
                    'xconfnum' => $xAuthCode,
                    'cartid' => $draft->draft_id,
                    'siteid' => $siteId,
                    'customernumber' => $customer->id,
                    'fname' => $customer->f_name,
                    'lname' => $customer->l_name,
                    'email' => $customer->email,
                    'cid' => $scheduledCid,
                    'cod' => $scheduledCod,
                    'siteclass' => $site->siteclass ?? $item['siteclass'] ?? null,
                    'total' => $itemTotal,
                    'totalcharges' => $itemTotal,
                    'subtotal' => $itemBase,
                    'sitelock' => $itemLock,
                    'nights' => Carbon::parse($start)->diffInDays(Carbon::parse($end)),
                    'status' => 'confirmed',
                    'createdby' => 'Admin Modification',
                    'group_confirmation_code' => $groupConfirmationCode,
                    'payment_id' => $newPayment ? $newPayment->id : ($oldReservations->first()->payment_id ?? null),
                    'receipt' => $receipt->id
                ]);

                // Confirmation code
                $tries = 0;
                do {
                    $tries++;
                    $code = 'CONF-' . strtoupper(substr(md5(uniqid('', true)), 0, 10));
                    $exists = Reservation::where('confirmation_code', $code)->exists();
                } while ($exists && $tries < 5);
                $newRes->confirmation_code = $code;
                $newRes->save();

                $newReservationIds[] = $newRes->id;
            }

            // 6. Cancel Old Reservations
            $newResIdsStr = implode(', ', $newReservationIds);
            Reservation::whereIn('id', $originalResIds)->update([
                'status' => 'Cancelled',
                'reason' => "Modified. Successors: {$newResIdsStr} (Draft: {$draft->draft_id})"
            ]);

            // 7. Cards On File
            if ($apiPaymentMethod === 'card' && $gatewayResponse && isset($gatewayResponse['xToken'])) {
                CardsOnFile::create([
                    'customernumber' => $customer->id,
                    'method' => $paymentMethodLabel,
                    'cartid' => $draft->draft_id,
                    'email' => $customer->email,
                    'xmaskedcardnumber' => $gatewayResponse['xMaskedCardNumber'] ?? '',
                    'xtoken' => $gatewayResponse['xToken'],
                    'receipt' => $receipt->id,
                    'gateway_response' => json_encode($gatewayResponse)
                ]);
            }

            $draft->status = 'confirmed';
            $draft->save();

            // 8. Notify Customer
            try {
                $newReservationsForEmail = Reservation::whereIn('id', $newReservationIds)->get()->toArray();
                Mail::to($customer->email)->send(new ReservationModified(
                    $draft->original_cart_id, 
                    $draft->draft_id, 
                    $draft->credit_amount, 
                    $newReservationsForEmail
                ));
            } catch (\Exception $e) {
                Log::error("Failed to send modification email: " . $e->getMessage());
            }

            DB::commit();

            $redirectId = $newReservationIds[0] ?? $draft->draft_id;

            if ($request->ajax() || $request->wantsJson()) {
                return response()->json([
                    'success' => true,
                    'message' => 'Reservation modified successfully.',
                    'order_id' => $redirectId,
                    'redirect_url' => route('admin.reservations.show', ['id' => $redirectId])
                ]);
            }

            return redirect()->route('admin.reservations.show', ['id' => $redirectId])
                ->with('success', 'Reservation modified successfully.');

        } catch (\Exception $e) {
            DB::rollBack();
            Log::error("Modification Finalize Failed: " . $e->getMessage(), [
                'draft_id' => $draft_id,
                'trace' => $e->getTraceAsString()
            ]);

            if ($request->ajax() || $request->wantsJson()) {
                return response()->json(['success' => false, 'message' => $e->getMessage()], 400);
            }

            return back()->withInput()->with('error', 'Modification failed: ' . $e->getMessage());
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
}
