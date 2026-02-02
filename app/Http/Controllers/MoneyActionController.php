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

            // 2. Calculate Credit Amount (Total Payments - Total Refunds)
            $totalPayments = Payment::where('cartid', $cartId)->sum('payment');
            $totalAdditional = \App\Models\AdditionalPayment::where('cartid', $cartId)->sum('total');
            $totalRefunds = Refund::where('cartid', $cartId)->sum('amount');
            
            $totalPaid = ($totalPayments + $totalAdditional) - $totalRefunds;

            // 3. Build Cart Data from existing reservations
            $cartData = [];
            $subtotal = 0;
            foreach ($reservations as $res) {
                 // Determine site lock fee status
                 $siteLockStatus = $res->sitelock > 0 ? 'on' : 'off';
                 $siteLockFeeAmount = (float) ($res->sitelock ?? 0);

                 // Safe Site Name
                 $siteName = $res->siteid;
                 if ($res->site) {
                     $siteName = $res->site->name ?? $res->site->sitename ?? $res->siteid;
                 }

                 $itemNights = (int) ($res->nights ?? 1);
                 $itemSubtotal = (float) $res->subtotal;
                 $itemTotal = (float) $res->total;
                 $itemBase = (float) ($res->base ?? 0);

                // If base is 0, we fallback to subtotal - lock fee
                if ($itemBase <= 0) {
                    $itemBase = $itemSubtotal - $siteLockFeeAmount;
                } else {
                    // Important: Multiply nightly base by nights for the cart UI
                    $itemBase = $itemBase * $itemNights;
                }

                 $cartData[] = [
                    'id' => (string) $res->siteid,
                    'name' => (string) $siteName,
                    'base' => $itemBase,
                    'fee' => 0, // platform fee
                    'lock_fee_amount' => $siteLockFeeAmount,
                    'start_date' => $res->cid instanceof \Carbon\Carbon ? $res->cid->format('Y-m-d') : $res->cid,
                    'end_date' => $res->cod instanceof \Carbon\Carbon ? $res->cod->format('Y-m-d') : $res->cod,
                    'occupants' => [
                        'adults' => $res->people ?? 2,
                        'children' => 0 // children not explicitly separated in Reservation table?
                    ],
                    'site_lock_fee' => $siteLockStatus
                 ];
                 $subtotal += ($itemBase + $siteLockFeeAmount);
            }

            // 4. We create a NEW draft for the replacement reservation
            $draftId = (string) \Illuminate\Support\Str::uuid();

            $draft = \App\Models\ReservationDraft::create([
                'draft_id' => $draftId,
                'cart_data' => $cartData,
                'subtotal' => $subtotal,
                'discount_total' => 0, 
                'estimated_tax' => 0,
                'platform_fee_total' => 0,
                'grand_total' => $subtotal,
                'discount_reason' => null,
                'status' => 'pending',
                'customer_id' => $mainRes->customernumber ?? null,
                'credit_amount' => $totalPaid,
                'is_modification' => true,
                'original_reservation_ids' => json_encode($reservations->pluck('id')->toArray())
            ]);

            // 5. Redirect to Step 1
            return redirect()->route('flow-reservation.step1', ['draft_id' => $draftId])
                             ->with('success', "Modification started for Reservation #$cartId. Credit of $" . number_format($totalPaid, 2) . " applied.");

        } catch (\Exception $e) {
            \Illuminate\Support\Facades\Log::error("Modification Start Failed: " . $e->getMessage());
            return redirect()->back()->with('error', 'Failed to start modification: ' . $e->getMessage());
        }
    }
}
