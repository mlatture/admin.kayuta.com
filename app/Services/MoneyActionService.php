<?php

namespace App\Services;

use App\Models\Reservation;
use App\Models\Payment;
use App\Models\Refund;
use App\Models\RateTier;
use App\Models\Site;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Auth;
use App\Models\AdditionalPayment;
use App\Services\ReservationLogService;
use App\Services\CardKnoxService;

class MoneyActionService
{
    protected $gateway;
    protected $logService;

    public function __construct(ReservationLogService $logService, CardKnoxService $gateway)
    {
        $this->logService = $logService;
        $this->gateway = $gateway;
    }

    /**
     * Add an additional charge to a reservation.
     */
    public function addCharge(Reservation $reservation, float $amount, float $tax, string $comment, string $method = 'cash', ?string $token = null, ?string $registerId = null)
    {
        return DB::transaction(function () use ($reservation, $amount, $tax, $comment, $method, $token, $registerId) {
            $oldState = $reservation->toArray();
            
            $totalAmount = $amount + $tax;
            $gatewayResponse = null;

            if ($method === 'credit_card_on_file' || $method === 'credit_card') {
                if (!$token) {
                    throw new \Exception("Credit card token required for automated charge.");
                }
                
                // Use CardKnoxService to process sale
                $response = $this->gateway->saveSale($token, '000', $totalAmount, $reservation->fname . ' ' . $reservation->lname, $reservation->email);
                
                if (!$response['success']) {
                    throw new \Exception("Gateway Error: " . ($response['message'] ?? 'Unknown error'));
                }
                $gatewayResponse = $response['data'];
            }

            // Create Additional Payment Record
            $payment = AdditionalPayment::create([
                'cartid' => $reservation->cartid,
                'reservation_id' => $reservation->id,
                'method' => $method,
                'amount' => $amount,
                'tax' => $tax,
                'total' => $totalAmount,
                'x_ref_num' => $gatewayResponse['xRefNum'] ?? null,
                'receipt' => 'AC-' . time(),
                'comment' => $comment,
                'created_by' => Auth::user()->name ?? 'System',
                'register_id' => $registerId,
            ]);

            // Update Reservation Totals
            $reservation->subtotal += $amount;
            $reservation->totaltax += $tax;
            $reservation->total += $totalAmount;
            $reservation->save();

            $this->logService->log(
                $reservation->id,
                'add_charge',
                $oldState,
                $reservation->refresh()->toArray(),
                "Added charge of \${$amount} + \${$tax} tax via {$method}. Comment: {$comment}. Gateway Ref: " . ($payment->x_ref_num ?? 'N/A')
            );

            return $reservation;
        });
    }

    /**
     * Cancel specific reservations and issue refund.
     */
    public function cancel(Reservation $mainReservation, array $reservationIds, float $feePercent, string $reason, string $method, string $overrideReason = '', ?string $registerId = null)
    {
        return DB::transaction(function () use ($mainReservation, $reservationIds, $feePercent, $reason, $method, $overrideReason, $registerId) {
            $reservations = Reservation::whereIn('id', $reservationIds)->get();
            $totalPaidForSelected = $reservations->sum('total');
            $feeAmount = round($totalPaidForSelected * ($feePercent / 100), 2);
            $refundAmount = max(0, $totalPaidForSelected - $feeAmount);

            $results = [];
            
            if ($method === 'credit_card' && $refundAmount > 0) {
                 // Find original payment reference for the Cart
                $payment = Payment::where('cartid', $mainReservation->cartid)
                    ->whereNotNull('x_ref_num')
                    ->latest()
                    ->first();

                if (!$payment) {
                    throw new \Exception("Original credit card reference (xRefNum) not found for this cart.");
                }

                $gatewayResult = $this->gateway->refund($payment->x_ref_num, $refundAmount, $reason);

                if (($gatewayResult['xStatus'] ?? '') !== 'Approved') {
                    Log::error("Cardknox Refund Failed", ['response' => $gatewayResult, 'cartid' => $mainReservation->cartid]);
                    throw new \Exception("Refund Failed: " . ($gatewayResult['xError'] ?? 'Unknown gateway error'));
                }
                $results['gateway'] = $gatewayResult;
            }

            foreach ($reservations as $res) {
                $oldState = $res->toArray();
                $res->status = 'Cancelled';
                $res->reason = $reason . ($overrideReason ? " (Override: $overrideReason)" : "");
                $res->save();

                Refund::create([
                    'cartid' => $res->cartid,
                    'reservations_id' => $res->id,
                    'amount' => count($reservations) > 0 ? $refundAmount / count($reservations) : 0,
                    'cancellation_fee' => count($reservations) > 0 ? $feeAmount / count($reservations) : 0,
                    'method' => $method,
                    'reason' => $reason,
                    'override_reason' => $overrideReason,
                    'x_ref_num' => $results['gateway']['xRefNum'] ?? null,
                    'created_by' => Auth::user()->name ?? 'System',
                    'register_id' => $registerId,
                ]);

                $this->logService->log(
                    $res->id,
                    'cancelled',
                    $oldState,
                    $res->refresh()->toArray(),
                    "Cancelled via MoneyAction. Method: $method. Refund: \${$refundAmount}. Fee Percentage: {$feePercent}%. Fee Amount: \${$feeAmount}. Reason: $reason. " . 
                    ($overrideReason ? "Override Reason: $overrideReason. " : "") .
                    "Gateway Info: " . json_encode($results['gateway'] ?? 'N/A')
                );
            }

            return $results;
        });
    }

    public function moveOptions(Reservation $reservation)
    {
        $availableSites = Site::where('available', 1)->get()->filter(function ($site) use ($reservation) {
            if ($site->siteid == $reservation->siteid) return false;
            return $site->checkAvailable($reservation->cid, $reservation->cod, [$reservation->cartid]);
        });

        $options = [];
        $currentSite = Site::where('siteid', $reservation->siteid)->first();

        foreach ($availableSites as $site) {
            $isSameClass = $currentSite && ($currentSite->siteclass == $site->siteclass);
            $isSameTier = $currentSite && ($currentSite->ratetier == $site->ratetier);
            
            $newPrice = $this->calculatePriceForMove($reservation, $site);
            $priceDiff = $newPrice - $reservation->base;

            $label = $site->sitename;
            if ($isSameClass && $isSameTier) {
                $label .= " (Same Class/Tier - No Charge)";
                $priceDiff = 0;
            } else {
                $diffText = ($priceDiff >= 0) ? "+$" . number_format($priceDiff, 2) : "-$" . number_format(abs($priceDiff), 2);
                $label .= " ({$diffText})";
            }

            $options[] = [
                'siteid' => $site->siteid,
                'sitename' => $site->sitename,
                'price_diff' => $priceDiff,
                'is_same_category' => ($isSameClass && $isSameTier),
                'label' => $label
            ];
        }

        usort($options, function ($a, $b) {
            if ($a['is_same_category'] && !$b['is_same_category']) return -1;
            if (!$a['is_same_category'] && $b['is_same_category']) return 1;
            return $a['price_diff'] <=> $b['price_diff'];
        });

        return $options;
    }

    /**
     * Move a reservation to a different site.
     */
    public function moveSite(Reservation $reservation, string $newSiteId, ?float $overridePrice = null, string $comment = '')
    {
        return DB::transaction(function () use ($reservation, $newSiteId, $overridePrice, $comment) {
            $oldState = $reservation->toArray();
            $newSite = Site::where('siteid', $newSiteId)->firstOrFail();

            if (!$newSite->checkAvailable($reservation->cid, $reservation->cod, [$reservation->cartid])) {
                throw new \Exception("Site {$newSite->sitename} is no longer available.");
            }

            $reservation->siteid = $newSiteId;
            if (isset($reservation->site_id)) {
                $reservation->site_id = $newSite->id;
            }

            if ($overridePrice !== null) {
                $reservation->base = $overridePrice;
            } else {
                $reservation->base = $this->calculatePriceForMove($reservation, $newSite);
            }

            $reservation->subtotal = $reservation->base + ($reservation->sitelock ?? 0);
            $reservation->total = $reservation->subtotal + $reservation->totaltax;
            $reservation->save();

            $this->logService->log(
                $reservation->id,
                'move_site',
                $oldState,
                $reservation->refresh()->toArray(),
                "Moved to site {$newSite->sitename}. Price: \${$reservation->base}. Comment: {$comment}"
            );

            return $reservation;
        });
    }

    /**
     * Change dates of a reservation.
     */
    public function changeDates(Reservation $reservation, string $cid, string $cod, ?float $overridePrice = null, string $comment = '')
    {
        return DB::transaction(function () use ($reservation, $cid, $cod, $overridePrice, $comment) {
            $oldState = $reservation->toArray();
            
            $newCid = Carbon::parse($cid);
            $newCod = Carbon::parse($cod);
            $nights = $newCod->diffInDays($newCid);

            if ($nights <= 0) {
                throw new \Exception("Invalid duration: Checkout must be after Check-in.");
            }

            $reservation->cid = $newCid;
            $reservation->cod = $newCod;
            $reservation->nights = $nights;

            $newPrice = $overridePrice;
            if (is_null($newPrice)) {
                $newPrice = $this->calculatePriceForDates($reservation, $newCid, $newCod);
            }

            if (!is_null($newPrice)) {
                $diff = $newPrice - $reservation->base;
                $reservation->base = $newPrice;
                $taxRate = $reservation->taxrate ?: 0.0875;
                $reservation->subtotal += $diff;
                $reservation->totaltax = round($reservation->subtotal * $taxRate, 2);
                $reservation->total = $reservation->subtotal + $reservation->totaltax;
            }

            $reservation->save();

            $this->logService->log(
                $reservation->id,
                'change_dates',
                $oldState,
                $reservation->refresh()->toArray(),
                "Dates changed from {$oldState['cid']} - {$oldState['cod']} to $cid - $cod. Comment: $comment" . (is_null($overridePrice) ? "" : " (Price Override Applied)")
            );

            return $reservation;
        });
    }

    public function processCardknoxRefund(Reservation $reservation, float $amount, string $reason)
    {
        // 1. Try finding payment directly by payment_id
        $payment = null;
        if (!empty($reservation->payment_id)) {
            $payment = Payment::where('id', $reservation->payment_id)->whereNotNull('x_ref_num')->first();
        }

        // 2. Fallback to latest payment in cart if not found
        if (!$payment) {
            $payment = Payment::where('cartid', $reservation->cartid)
                ->whereNotNull('x_ref_num')
                ->latest()
                ->first();
        }

        if (!$payment) {
            throw new \Exception("Original credit card transaction reference (xRefNum) not found.");
        }

        $payload = [
            'xKey' => config('services.cardknox.api_key'),
            'xVersion' => '5.0.0',
            'xSoftwareName' => 'KayutaLake',
            'xSoftwareVersion' => '1.0',
            'xCommand' => 'cc:refund',
            'xRefNum' => $payment->x_ref_num,
            'xAmount' => round($amount, 2),
            'xAllowDuplicate' => 'true',
        ];

        $response = Http::asForm()->post('https://x1.cardknox.com/gateway', $payload);
        parse_str($response->body(), $responseArray);

        if (($responseArray['xStatus'] ?? '') !== 'Approved') {
            Log::error("Cardknox Refund Failed", ['response' => $responseArray, 'cartid' => $reservation->cartid]);
            throw new \Exception('Payment Gateway Refund Failed: ' . ($responseArray['xError'] ?? 'Unknown error'));
        }

        return $responseArray;
    }

    protected function calculatePriceForMove(Reservation $reservation, Site $newSite)
    {
        $rateTier = RateTier::where('tier', $newSite->ratetier)->first();
        if (!$rateTier) return null;

        $nights = $reservation->nights;
        return $this->applyNightsPricing($rateTier, $nights);
    }

    protected function calculatePriceForDates(Reservation $reservation, Carbon $cid, Carbon $cod)
    {
        $site = Site::where('siteid', $reservation->siteid)->first();
        if (!$site) return null;

        $rateTier = RateTier::where('tier', $site->ratetier)->first();
        if (!$rateTier) return null;

        $nights = $cod->diffInDays($cid);
        return $this->applyNightsPricing($rateTier, $nights);
    }

    protected function applyNightsPricing($rateTier, $nights)
    {
        if ($nights < 7) {
            return $rateTier->flatrate * $nights;
        } elseif ($nights == 7) {
            return $rateTier->weeklyrate;
        } else {
            $extraNights = $nights - 7;
            return $rateTier->weeklyrate + ($extraNights * $rateTier->flatrate);
        }
    }
}
