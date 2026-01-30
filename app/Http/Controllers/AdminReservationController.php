<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Models\Reservation;
use App\Models\User;
use App\Models\Payment;
use App\Models\SystemLog;
use Carbon\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Schema;
use App\Services\ReservationLogService;
use App\Models\ReservationLog;

class AdminReservationController extends Controller
{
    
public function show(Request $request, $id)
{
    /**
     * Goal:
     * - Clicking ANY booking should show ALL bookings under the SAME checkout/group
     * - Ledger shows all related site charges + one checkout payment + later fees + addons
     * - Running Balance behaves like a ledger (charges add, payments subtract)
     * - UI Running Balance never shows negative (clamp to 0.00)
     */

    // ---------------------------------------------------------
    // 1) Find clicked reservation (by cartid OR numeric id)
    // ---------------------------------------------------------
    $clickedReservation = Reservation::where('cartid', $id)->first();
    if (!$clickedReservation && is_numeric($id)) {
        $clickedReservation = Reservation::find((int)$id);
    }
    if (!$clickedReservation) {
        return redirect()->route('reservations.index')->with('error', 'Reservation not found.');
    }

    // ---------------------------------------------------------
    // 2) GROUPING RULE (best -> fallback)
    // ---------------------------------------------------------
    $paymentId = $clickedReservation->payment_id ?? null;
    $groupCode = $clickedReservation->group_confirmation_code ?? null;
    $xconfnum  = $clickedReservation->xconfnum ?? null;

    $reservationsQuery = Reservation::query();

    if (!empty($paymentId)) {
        // Best grouping: one payment record ties all reservations
        $reservationsQuery->where('payment_id', $paymentId);
    } elseif (!empty($groupCode)) {
        // Next: shared group code
        $reservationsQuery->where('group_confirmation_code', $groupCode);
    } elseif (!empty($xconfnum)) {
        // Next: gateway auth code
        $reservationsQuery->where('xconfnum', $xconfnum);
    } else {
        // Fallback: cartid
        $reservationsQuery->where('cartid', $clickedReservation->cartid);
    }

    $reservations = $reservationsQuery
        ->with(['payment', 'site', 'user'])
        ->get();

    if ($reservations->isEmpty()) {
        return redirect()->route('reservations.index')->with('error', 'Reservation not found.');
    }

    $mainReservation = $reservations->first();
    $user = User::find($mainReservation->customernumber);

    // ---------------------------------------------------------
    // 3) Collect cartIds for additional payments/refunds
    // ---------------------------------------------------------
    $cartIds = $reservations->pluck('cartid')->filter()->unique()->values();

    $payments = collect(); // kept for view compatibility
    $additionalPayments = \App\Models\AdditionalPayment::whereIn('cartid', $cartIds)->get();
    $refunds = \App\Models\Refund::where(function($query) use ($cartIds, $reservations) {
        $query->whereIn('cartid', $cartIds)
              ->orWhereIn('reservations_id', $reservations->pluck('id'));
    })->get();

    // Checkout payment (single record)
    $checkoutPayment = null;
    if (!empty($mainReservation->payment_id)) {
        $checkoutPayment = \App\Models\Payment::find($mainReservation->payment_id);
    } else {
        $checkoutPayment = $mainReservation->payment ?? null;
    }

    // ---------------------------------------------------------
    // 4) Logs (safe)
    // ---------------------------------------------------------
    if ($request->has('print') && (int)$request->input('print') === 1) {
        $this->logAction('Print', $id, $mainReservation);
    }

    $logs = ReservationLog::where('reservation_id', $mainReservation->id)
        ->orWhere('reservation_id', $id)
        ->orderBy('created_at', 'desc')
        ->take(50)
        ->get();

    $registers = \App\Models\StationRegisters::all();

    // ---------------------------------------------------------
    // 5) Ledger helpers
    // ---------------------------------------------------------
    $ledger = collect();
    $total_includes_tax = true;

    $parseAddons = function ($addonsJson) {
        if (empty($addonsJson)) return ['items' => [], 'addons_total' => 0.0];

        if (is_string($addonsJson)) {
            $decoded = json_decode($addonsJson, true);
            if (!is_array($decoded)) return ['items' => [], 'addons_total' => 0.0];
            $addonsJson = $decoded;
        }

        if (!is_array($addonsJson)) return ['items' => [], 'addons_total' => 0.0];

        $items = [];
        if (isset($addonsJson['items']) && is_array($addonsJson['items'])) {
            $items = $addonsJson['items'];
        } elseif (is_array($addonsJson)) {
            $items = $addonsJson; // legacy
        }

        $addonsTotal = 0.0;
        if (isset($addonsJson['addons_total'])) {
            $addonsTotal = (float)$addonsJson['addons_total'];
        } else {
            $addonsTotal = (float) array_sum(array_map(
                static fn($a) => (float)($a['total_price'] ?? $a['price'] ?? 0),
                array_filter($items, 'is_array')
            ));
        }

        return ['items' => $items, 'addons_total' => $addonsTotal];
    };

    $stayLabelFor = function ($res) {
        try {
            if (!empty($res->cid) && !empty($res->cod)) {
                return ' (' .
                    \Carbon\Carbon::parse($res->cid)->format('M d') .
                    '–' .
                    \Carbon\Carbon::parse($res->cod)->format('M d') .
                ')';
            }
        } catch (\Throwable $e) {}
        return '';
    };

    // ---------------------------------------------------------
    // 6) Charges per reservation (Site + SiteLock + Addons)
    // ---------------------------------------------------------
    foreach ($reservations as $res) {
        $finalTotal = (float)($res->total ?? 0);
        $siteLock   = (float)($res->sitelock ?? 0);
        $totalTax   = (float)($res->totaltax ?? 0);

        // Site charge excludes sitelock (addons added separately as lines)
        $siteCharge = round($finalTotal - ($siteLock > 0 ? $siteLock : 0), 2);

        $stayLabel = $stayLabelFor($res);

        if ($siteCharge != 0.0) {
            $ledger->push([
                'id'          => 'site-charge-' . $res->id,
                'date'        => $res->created_at,
                'description' => "Site Charge: {$res->siteid}{$stayLabel}",
                'type'        => 'charge',
                'amount'      => $siteCharge,
                'ref'         => $res->group_confirmation_code ?? $res->confirmation_code ?? $res->xconfnum ?? null,
                'raw_obj'     => $res,
                'seq'         => 10,
            ]);
        }

        // ✅ Site lock shows which site it belongs to
        if ($siteLock > 0) {
            $ledger->push([
                'id'          => 'site-lock-' . $res->id,
                'date'        => $res->created_at,
                'description' => "Site Lock Fee ({$res->siteid})",
                'type'        => 'charge',
                'amount'      => round($siteLock, 2),
                'ref'         => null,
                'raw_obj'     => $res,
                'seq'         => 20,
            ]);
        }

        // ✅ Addon lines (show which site they belong to)
        $addons = $parseAddons($res->addons_json);
        $addonItems = is_array($addons['items'] ?? null) ? $addons['items'] : [];

        foreach ($addonItems as $idx => $addon) {
            if (!is_array($addon)) continue;

            $rawName = (string)($addon['type'] ?? $addon['name'] ?? 'Addon');
            $qty     = (int)($addon['qty'] ?? 1);
            $price   = (float)($addon['total_price'] ?? $addon['price'] ?? 0);

            // Prefer addon site_id, else reservation siteid
            $belongsToSite = $addon['site_id'] ?? $res->siteid;

            $suffixParts = [];
            if ($qty > 1) $suffixParts[] = "x{$qty}";

            $suffix = '';
            if (!empty($suffixParts)) {
                $suffix = ' ' . implode(' ', $suffixParts);
            }

            if ($price != 0.0) {
                $ledger->push([
                    'id'          => "addon-{$res->id}-{$idx}",
                    'date'        => $res->created_at,
                    'description' => "Addon: {$rawName} ({$belongsToSite}){$suffix}",
                    'type'        => 'charge',
                    'amount'      => round($price, 2),
                    'ref'         => null,
                    'raw_obj'     => $addon,
                    'seq'         => 25,
                ]);
            }
        }

        // Optional tax line
        if (!$total_includes_tax && $totalTax > 0) {
            $ledger->push([
                'id'          => 'tax-' . $res->id,
                'date'        => $res->created_at,
                'description' => "Taxes",
                'type'        => 'charge',
                'amount'      => round($totalTax, 2),
                'ref'         => null,
                'raw_obj'     => $res,
                'seq'         => 30,
            ]);
        }
    }

    // ---------------------------------------------------------
    // 7) One checkout payment line
    // ---------------------------------------------------------
    if ($checkoutPayment) {
        $masked = null;
        try {
            $card = \App\Models\CardsOnFile::where('customernumber', $user->id)
                ->orderBy('created_at', 'desc')
                ->first();
            $masked = $card->xmaskedcardnumber ?? null;
        } catch (\Throwable $e) {}

        $method = $checkoutPayment->method ?? 'Card';
        $desc   = "Payment – {$method}";
        if (!empty($masked)) {
            $desc .= " {$masked}";
        }

        $ledger->push([
            'id'          => 'checkout-payment-' . $checkoutPayment->id,
            'date'        => $checkoutPayment->created_at ?? $mainReservation->created_at,
            'description' => $desc,
            'type'        => 'payment',
            'amount'      => -abs((float)($checkoutPayment->payment ?? 0)),
            'ref'         => $checkoutPayment->x_ref_num ?? null,
            'raw_obj'     => $checkoutPayment,
            'seq'         => 90,
        ]);
    }

    // ---------------------------------------------------------
    // 8) Additional payments / fees
    // ---------------------------------------------------------
    foreach ($additionalPayments as $ap) {
        $apAmount = (float)($ap->amount ?? 0);
        $apTax    = (float)($ap->tax ?? 0);
        $apTotal  = (float)($ap->total ?? 0);

        $looksLikePayment = !empty($ap->method) || !empty($ap->x_ref_num);

        if ($apAmount > 0) {
            $ledger->push([
                'id'          => 'add-charge-' . $ap->id,
                'date'        => $ap->created_at,
                'description' => ($ap->comment ?: 'Additional Charge'),
                'type'        => 'charge',
                'amount'      => round($apAmount, 2),
                'ref'         => $ap->x_ref_num,
                'raw_obj'     => $ap,
                'seq'         => 40,
            ]);
        }

        if ($apTax > 0) {
            $ledger->push([
                'id'          => 'add-tax-' . $ap->id,
                'date'        => $ap->created_at,
                'description' => 'Tax (Additional)',
                'type'        => 'charge',
                'amount'      => round($apTax, 2),
                'ref'         => null,
                'raw_obj'     => $ap,
                'seq'         => 50,
            ]);
        }

        if ($looksLikePayment && $apTotal > 0) {
            $ledger->push([
                'id'          => 'add-payment-' . $ap->id,
                'date'        => $ap->created_at,
                'description' => 'Payment – ' . ($ap->method ?? 'Card'),
                'type'        => 'payment',
                'amount'      => -abs($apTotal),
                'ref'         => $ap->x_ref_num,
                'raw_obj'     => $ap,
                'seq'         => 60,
            ]);
        }
    }

    // ---------------------------------------------------------
    // 9) Refunds
    // ---------------------------------------------------------
    foreach ($refunds as $rf) {
        if (($rf->cancellation_fee ?? 0) > 0) {
            $ledger->push([
                'id'          => 'cancel-fee-' . $rf->id,
                'date'        => $rf->created_at,
                'description' => 'Cancellation Fee',
                'type'        => 'charge',
                'amount'      => (float)$rf->cancellation_fee,
                'ref'         => null,
                'raw_obj'     => $rf,
                'seq'         => 70,
            ]);
        }

        if (($rf->amount ?? 0) > 0) {
            $ledger->push([
                'id'          => 'refund-' . $rf->id,
                'date'        => $rf->created_at,
                'description' => 'Refund Issued',
                'type'        => 'refund',
                'amount'      => (float)$rf->amount,
                'ref'         => $rf->method ?? null,
                'raw_obj'     => $rf,
                'seq'         => 80,
            ]);
        }
    }

    // ---------------------------------------------------------
    // 10) Sort stable
    // ---------------------------------------------------------
    $ledger = $ledger->sortBy([
        fn($a, $b) => $a['date'] <=> $b['date'],
        fn($a, $b) => ($a['seq'] ?? 0) <=> ($b['seq'] ?? 0),
        fn($a, $b) => strcmp($a['id'], $b['id']),
    ])->values();

    // ---------------------------------------------------------
    // 11) Running balance (ledger behavior), display clamp
    // ---------------------------------------------------------
    $running = 0.0;
    $ledger = $ledger->map(function ($row) use (&$running) {
        $running = round($running + (float)$row['amount'], 2);

        // True running can go negative (overpayment), but UI shows 0 min
        $row['running_balance'] = max(0, $running);
        return $row;
    });

    // Totals
    $totalCharges  = round($ledger->where('type', 'charge')->sum('amount'), 2);
    $totalPayments = round($ledger->where('type', 'payment')->sum('amount'), 2); // negative
    $totalRefunds  = round($ledger->where('type', 'refund')->sum('amount'), 2);

    $netTotal   = round($totalCharges + $totalPayments + $totalRefunds, 2);
    $balanceDue = (float)($ledger->last()['running_balance'] ?? 0);

    $cartTotal = $reservations->sum('total');
    $totalPaid = abs($totalPayments);

    return view('admin.reservations.show', compact(
        'reservations',
        'mainReservation',
        'user',
        'payments',
        'logs',
        'additionalPayments',
        'refunds',
        'registers',
        'ledger',
        'totalCharges',
        'totalPayments',
        'totalRefunds',
        'netTotal',
        'balanceDue',
        'cartTotal',
        'totalPaid'
    ));
}




    public function globalSearch(Request $request)
    {
        $q = trim($request->input('q'));

        if (strlen($q) < 3) {
             return redirect()->back()->with('error', 'Search query must be at least 3 characters.');
        }

        // 1. Exact Cart ID Match (Highest Priority)
        $exactCart = Reservation::where('cartid', $q)->first();
        if ($exactCart) {
            return redirect()->route('admin.reservations.show', ['id' => $exactCart->cartid]);
        }

        // 2. Broad Search
        $normalizedPhone = preg_replace('/[^0-9]/', '', $q);
        
        $userIds = User::query()
            ->where('email', 'like', "%{$q}%")
            ->orWhere('f_name', 'like', "%{$q}%")
            ->orWhere('l_name', 'like', "%{$q}%")
            ->when(!empty($normalizedPhone), function($query) use ($normalizedPhone) {
                 return $query->orWhere('phone', 'like', "%{$normalizedPhone}%");
            })
            ->pluck('id')
            ->toArray();

        // Search Reservations
        $reservations = Reservation::query()
            ->where('cartid', 'like', "%{$q}%")
            ->orWhere('fname', 'like', "%{$q}%")
            ->orWhere('lname', 'like', "%{$q}%")
            ->orWhereIn('customernumber', $userIds)
            ->with(['user', 'site'])
            ->orderBy('cid', 'desc')
            ->get();

        // Group by Cart ID to get unique "Bookings"
        $uniqueBookings = $reservations->groupBy('cartid');
        $count = $uniqueBookings->count();

        if ($count === 0) {
            return redirect()->back()->with('error', 'No reservations found for "' . $q . '".');
        }

        if ($count === 1) {
            $cartId = $uniqueBookings->keys()->first();
            return redirect()->route('admin.reservations.show', ['id' => $cartId]);
        }

        return view('admin.reservations.search_results', [
            'bookings' => $uniqueBookings, 
            'query' => $q
        ]);
    }



    public function checkin($id)
    {
        $reservations = Reservation::where('cartid', $id)->get();

        if ($reservations->isEmpty()) {
            return redirect()->back()->with('error', 'Reservation not found.');
        }

        $activeStatuses = ['Paid', 'Confirmed', 'Pending'];
        $now = Carbon::now();
        $today = Carbon::today();

        $updatedCount = 0;

        foreach ($reservations as $res) {
            if (in_array($res->status, $activeStatuses) && $today->gte(Carbon::parse($res->cid))) {
                if (is_null($res->checkedin)) {
                    $res->checkedin = $now;
                    $res->save();
                    $updatedCount++;
                }
            }
        }

        if ($updatedCount > 0) {
            $this->logAction('Check-In', $id, $reservations->first());
            return redirect()->back()->with('success', 'Checked In successfully.');
        }

        return redirect()->back()->with('error', 'No eligible reservations to check in (Check date or status).');
    }

    public function checkout($id)
    {
        $reservations = Reservation::where('cartid', $id)->get();

        if ($reservations->isEmpty()) {
            return redirect()->back()->with('error', 'Reservation not found.');
        }

        $updatedCount = 0;
        $now = Carbon::now();

        foreach ($reservations as $res) {
            if (!is_null($res->checkedin) || in_array($res->status, ['Paid', 'Confirmed', 'Pending'])) {
                if (is_null($res->checkedout)) {
                    $res->checkedout = $now;
                    $res->save();
                    $updatedCount++;
                }
            }
        }

        if ($updatedCount > 0) {
            $this->logAction('Check-Out', $id, $reservations->first());
            return redirect()->back()->with('success', 'Checked Out successfully.');
        }

        return redirect()->back()->with('error', 'No eligible reservations to check out.');
    }

    private function logAction($type, $cartId, $mainReservation)
    {
        try {
            // Mapping UI type to event_type
            $eventType = strtolower(str_replace('-', '_', $type));
            if ($type === 'Print') $eventType = 'print_invoice';

            app(ReservationLogService::class)->log(
                $mainReservation->id ?? $cartId,
                $eventType,
                null,
                null,
                "Reservation #$cartId - $type"
            );

            // Keep old logging for backward compatibility if table exists
            if (Schema::hasTable('system_logs')) {
                SystemLog::create([
                    'transaction_type' => $type,
                    'status' => 'Success',
                    'payment_type' => 'N/A',
                    'confirmation_number' => $cartId,
                    'customer_name' => trim(($mainReservation->fname ?? '') . ' ' . ($mainReservation->lname ?? '')),
                    'customer_email' => $mainReservation->email ?? '',
                    'user_id' => Auth::id(),
                    'description' => "Reservation #$cartId - $type",
                    'before' => null,
                    'after' => json_encode([
                        'cartid' => $cartId,
                        'action' => $type,
                        'timestamp' => Carbon::now()->toDateTimeString(),
                        'user' => Auth::user()->name ?? 'Unknown'
                    ]),
                    'created_at' => Carbon::now(),
                ]);
            }
        } catch (\Throwable $e) {
            // bypass silently
            return;
        }
    }
}
