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
                    'site_lock_fee' => $siteLockStatus,
                    'original_reservation_id' => $res->id
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
                'original_cart_id' => $mainRes->cartid,
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

public function refundSingle(Request $request, $id)
{
    $request->validate([
        'refund_amount'     => 'required|numeric|min:0.01',
        'reason'            => 'required|string|max:500',
        'method'            => 'required|in:credit_card,cash,other,account_credit,gift_card',
        'cancellation_fee'  => 'nullable|numeric|min:0',
        'override_reason'   => 'nullable|string|max:500',
    ]);

    $refundAmount    = (float) $request->refund_amount;
    $refundMethod    = $request->method;
    $refundReason    = $request->reason;
    $cancellationFee = $request->filled('cancellation_fee') ? (float) $request->cancellation_fee : null;
    $overrideReason  = $request->input('override_reason');

    try {
        return DB::transaction(function () use (
            $id,
            $refundAmount,
            $refundMethod,
            $refundReason,
            $cancellationFee,
            $overrideReason
        ) {
            // Lock reservation to avoid double refund race
            $reservation = Reservation::query()
                ->lockForUpdate()
                ->findOrFail($id);

            if (empty($reservation->payment_id)) {
                return response()->json([
                    'success' => false,
                    'message' => 'Refund failed: reservation is missing payment_id.',
                ], 422);
            }

            // Lock the payment row referenced by this reservation
            $payment = Payment::query()
                ->lockForUpdate()
                ->find($reservation->payment_id);

            if (!$payment) {
                return response()->json([
                    'success' => false,
                    'message' => 'Refund failed: payment not found for reservation payment_id.',
                ], 422);
            }

            // Your payment total column is $payment->payment
            $paymentChargedAmount = (float) $payment->payment;

            if ($paymentChargedAmount <= 0) {
                return response()->json([
                    'success' => false,
                    'message' => 'Refund failed: payment.payment is not valid.',
                ], 422);
            }

            // Determine cartid grouping (shared transaction group)
            $cartid = $payment->cartid ?? $reservation->cartid;

            if (empty($cartid)) {
                return response()->json([
                    'success' => false,
                    'message' => 'Refund failed: cannot determine cartid for shared payment group.',
                ], 422);
            }

            // Prevent double refunds on the same reservation
            $alreadyRefundedForReservation = (float) Refund::where('reservations_id', $reservation->id)->sum('amount');
            $reservationRefundable = max(0, (float) $reservation->total - $alreadyRefundedForReservation);

            if ($refundAmount > $reservationRefundable) {
                return response()->json([
                    'success' => false,
                    'message' =>
                        'Refund amount exceeds refundable balance for this reservation. ' .
                        'Refundable: $' . number_format($reservationRefundable, 2) .
                        ', already refunded: $' . number_format($alreadyRefundedForReservation, 2) .
                        ', reservation total: $' . number_format((float) $reservation->total, 2),
                ], 422);
            }

            // Prevent exceeding the shared payment capture across all reservations (cartid group)
            $alreadyRefundedForCart = (float) Refund::where('cartid', $cartid)->sum('amount');
            $paymentRefundable = max(0, $paymentChargedAmount - $alreadyRefundedForCart);

            if ($refundAmount > $paymentRefundable) {
                return response()->json([
                    'success' => false,
                    'message' =>
                        'Refund amount exceeds refundable balance on the shared payment. ' .
                        'Refundable: $' . number_format($paymentRefundable, 2) .
                        ', already refunded on cart: $' . number_format($alreadyRefundedForCart, 2) .
                        ', payment charged: $' . number_format($paymentChargedAmount, 2),
                ], 422);
            }

            $xRefNum = null;

            // Gateway refund only when method is credit_card
            if ($refundMethod === 'credit_card') {
                if (empty($payment->x_ref_num)) {
                    return response()->json([
                        'success' => false,
                        'message' => 'Cannot process credit card refund: no x_ref_num on payment. Use another method.',
                    ], 422);
                }

                $postData = [
                    'xKey'             => config('services.cardknox.api_key'),
                    'xVersion'         => '4.5.5',
                    'xCommand'         => 'cc:Refund',
                    'xAmount'          => number_format($refundAmount, 2, '.', ''),
                    'xRefNum'          => $payment->x_ref_num,
                    'xSoftwareVersion' => '1.0',
                    'xSoftwareName'    => 'KayutaLake',
                    'xInvoice'         => 'REFUND-' . $reservation->id . '-' . time(),
                ];

                $ch = curl_init('https://x1.cardknox.com/gateway');
                curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
                curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($postData));
                curl_setopt($ch, CURLOPT_HTTPHEADER, [
                    'Content-type: application/x-www-form-urlencoded',
                    'X-Recurring-Api-Version: 1.0',
                ]);

                $raw = curl_exec($ch);
                if ($raw === false) {
                    $error = curl_error($ch);
                    curl_close($ch);
                    throw new \Exception('Payment gateway connection failed: ' . $error);
                }
                curl_close($ch);

                parse_str($raw, $gatewayResponse);

                if (($gatewayResponse['xStatus'] ?? '') !== 'Approved') {
                    return response()->json([
                        'success' => false,
                        'message' => 'Gateway refund failed: ' . ($gatewayResponse['xError'] ?? 'Transaction declined'),
                    ], 422);
                }

                $xRefNum = $gatewayResponse['xRefNum'] ?? null;
            }

            // Create Refund record (matches your refunds table)
            Refund::create([
                'cartid'           => $cartid,
                'amount'           => $refundAmount,
                'cancellation_fee' => $cancellationFee,
                'reservations_id'  => $reservation->id,
                'reason'           => $refundReason,
                'override_reason'  => $overrideReason,
                'created_by'       => auth()->user()->name ?? 'System',
                'method'           => $refundMethod,
                'x_ref_num'        => $xRefNum,
            ]);

            // Cancel reservation only if fully refunded
            $newRefundedForReservation = $alreadyRefundedForReservation + $refundAmount;
            $isFullyRefunded = $newRefundedForReservation >= ((float) $reservation->total - 0.00001);

            if ($isFullyRefunded) {
                $reservation->update([
                    'status' => 'Cancelled',
                    'reason' => 'Refunded: ' . $refundReason,
                ]);
            }

            return response()->json([
                'success' => true,
                'message' => 'Refund of $' . number_format($refundAmount, 2) . ' processed successfully.',
                'refund_amount' => $refundAmount,
                'reservation_id' => $reservation->id,
                'payment_id' => $payment->id,
                'cartid' => $cartid,
                'gateway_ref' => $xRefNum,
                'reservation_refundable_before' => $reservationRefundable,
                'payment_refundable_before' => $paymentRefundable,
                'refunded_on_cart_total_after' => $alreadyRefundedForCart + $refundAmount,
                'payment_charged_amount' => $paymentChargedAmount,
            ]);
        });

    } catch (\Exception $e) {
        DB::rollBack();
        Log::error("Refund failed for reservation {$id}: " . $e->getMessage());

        return response()->json([
            'success' => false,
            'message' => 'Refund failed: ' . $e->getMessage(),
        ], 422);
    }
}



}
