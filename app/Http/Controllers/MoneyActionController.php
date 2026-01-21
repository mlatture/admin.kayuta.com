<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Models\Reservation;
use App\Models\CartReservation;
use App\Models\Payment;
use App\Models\Refund;
use App\Models\AdditionalPayment;
use App\Services\MoneyActionService;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\DB;
use Carbon\Carbon;
use App\Services\ReservationLogService;

class MoneyActionController extends Controller
{
    protected $moneyService;

    public function __construct(MoneyActionService $moneyService)
    {
        $this->moneyService = $moneyService;
        $this->middleware('auth');
        // Add permission middleware if available, e.g.:
        // $this->middleware('admin_has_permission:reservation_management');
    }

    public function addCharge(Request $request, $id)
    {
        $request->validate([
            'amount' => 'required|numeric|min:0.01',
            'tax' => 'required|numeric|min:0',
            'comment' => 'required|string|max:500',
            'method' => 'nullable|string',
            'token' => 'nullable|string',
            'register_id' => 'nullable|string',
        ]);

        try {
            $reservation = Reservation::findOrFail($id);
            $this->moneyService->addCharge(
                $reservation, 
                $request->amount, 
                $request->tax, 
                $request->comment,
                $request->method ?? 'cash',
                $request->token,
                $request->register_id
            );

            return response()->json([
                'success' => true, 
                'message' => 'Charge added successfully.'
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false, 
                'message' => 'Failed to add charge: ' . $e->getMessage()
            ], 422);
        }
    }

    public function cancel(Request $request, $id)
    {
        $request->validate([
            'reservation_ids' => 'required|array',
            'reservation_ids.*' => 'exists:reservations,id',
            'refund_amount' => 'nullable|numeric|min:0',
            'fee_percent' => 'required|numeric|min:0',
            'reason' => 'required|string|max:500',
            'method' => 'required|in:credit_card,cash,other,account_credit,gift_card',
            'override_reason' => 'nullable|string|max:500',
            'register_id' => 'nullable|string',
        ]);

        try {
            $mainReservation = Reservation::where('cartid', $id)->firstOrFail();
            $this->moneyService->cancel(
                $mainReservation, 
                $request->reservation_ids, 
                $request->fee_percent, 
                $request->reason, 
                $request->method,
                $request->override_reason ?? '',
                $request->register_id
            );

            return response()->json([
                'success' => true, 
                'message' => 'Reservation(s) cancelled successfully.'
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false, 
                'message' => 'Cancellation failed: ' . $e->getMessage()
            ], 422);
        }
    }

    public function moveSite(Request $request, $id)
    {
        $request->validate([
            'new_site_id' => 'required|exists:sites,siteid',
            'override_price' => 'nullable|numeric|min:0',
            'comment' => 'required|string|max:500',
        ]);

        try {
            $reservation = Reservation::findOrFail($id);
            $this->moneyService->moveSite(
                $reservation, 
                $request->new_site_id, 
                $request->override_price, 
                $request->comment
            );

            return response()->json([
                'success' => true, 
                'message' => 'Site moved successfully.'
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false, 
                'message' => 'Move failed: ' . $e->getMessage()
            ], 422);
        }
    }

    public function moveOptions($id)
    {
        try {
            $reservation = Reservation::findOrFail($id);
            $options = $this->moneyService->moveOptions($reservation);
            return response()->json([
                'success' => true,
                'options' => $options
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to fetch move options: ' . $e->getMessage()
            ], 422);
        }
    }

    public function changeDates(Request $request, $id)
    {
        $request->validate([
            'cid' => 'required|date',
            'cod' => 'required|date|after:cid',
            'override_price' => 'nullable|numeric|min:0',
            'comment' => 'required|string|max:500',
        ]);

        try {
            $reservation = Reservation::findOrFail($id);
            $this->moneyService->changeDates(
                $reservation, 
                $request->cid, 
                $request->cod, 
                $request->override_price, 
                $request->comment
            );

            return response()->json([
                'success' => true, 
                'message' => 'Dates changed successfully.'
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false, 
                'message' => 'Date change failed: ' . $e->getMessage()
            ], 422);
        }
    }

    public function startModification(Request $request, $id)
    {
        try {
            // 1. Load existing reservation(s) by cartid
            $reservations = Reservation::where('cartid', $id)->with('site')->get();
            if ($reservations->isEmpty()) {
                // Try finding by single ID if cartid not found
                $singleRes = Reservation::find($id);
                if ($singleRes) {
                    $reservations = Reservation::where('cartid', $singleRes->cartid)->with('site')->get();
                }
            }

            if ($reservations->isEmpty()) {
                return redirect()->back()->with('error', 'Reservation not found.');
            }

            $mainRes = $reservations->first();
            $cartId = $mainRes->cartid;

            // 3. Build Cart Data from existing reservations
            $cartData = [];
            foreach ($reservations as $res) {
                // Determine site lock fee status
                $siteLockStatus = $res->sitelock ? 'on' : 'off';
                
                $siteLockFeeAmount = 0;
                if ($siteLockStatus === 'on') {
                     $siteLockFeeAmount = (float) (\App\Models\BusinessSettings::where('type', 'site_lock_fee')->value('value') ?? 0);
                }

                // Safe Site Name Retrieval
                $siteName = $res->siteid; // Default
                if ($res->site) {
                    $siteName = $res->site->sitename ?? $res->site->name ?? $res->siteid;
                }

                // Safe Base Price Retrieval
                $basePrice = (float) $res->base;
                if ($basePrice <= 0) {
                    $basePrice = (float) $res->subtotal;
                }
                if ($basePrice <= 0) {
                    $basePrice = (float) $res->total; 
                }

                $cartData[] = [
                    'id' => (string) $res->siteid,
                    'name' => (string) $siteName,
                    'base' => $basePrice,
                    'fee' => 0, 
                    'lock_fee_amount' => $siteLockFeeAmount,
                    'start_date' => $res->cid ? $res->cid->format('Y-m-d') : null,
                    'end_date' => $res->cod ? $res->cod->format('Y-m-d') : null,
                    'occupants' => [
                        'adults' => $res->adults ?? 2,
                        'children' => $res->children ?? 0,
                        'pets' => $res->pets ?? 0
                    ],
                    'site_lock_fee' => $siteLockStatus
                ];
            }
            // We use a new unique ID for the draft
            $draftId = (string) \Illuminate\Support\Str::uuid();

            // Calculate totals for the draft
            $newSubtotal = 0;
            foreach ($cartData as $item) {
                $newSubtotal += ($item['base'] + ($item['lock_fee_amount'] ?? 0));
            }

            // User requested NO automatic credit. Grand Total = Subtotal.
            $grandTotal = max(0, $newSubtotal);

            $draft = \App\Models\ReservationDraft::create([
                'draft_id' => $draftId,
                'cart_data' => $cartData,
                'subtotal' => $newSubtotal,
                'discount_total' => 0, 
                'estimated_tax' => 0,
                'platform_fee_total' => 0,
                'grand_total' => $grandTotal,
                'discount_reason' => null,
                'status' => 'pending',
                'customer_id' => $mainRes->customernumber ?? null
            ]);

            // 5. Redirect to Step 1
            return redirect()->route('flow-reservation.step1', ['draft_id' => $draftId])
                             ->with('success', "Modification started.");



        } catch (\Exception $e) {
            Log::error("Modification Start Failed: " . $e->getMessage());
            return redirect()->back()->with('error', 'Failed to start modification: ' . $e->getMessage());
        }
    }
}
