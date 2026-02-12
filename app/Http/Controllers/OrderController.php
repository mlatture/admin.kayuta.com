<?php

namespace App\Http\Controllers;

use Exception;
use App\Models\Order;
use App\Models\Product;
use App\Models\OrderItem;
use App\Models\PosPayment;
use App\Models\UpsellRate;
use App\Models\UpsellText;
use App\Models\Reservation;
use App\Models\UpsellOrder;
use App\Models\AdditionalPayment;
use Illuminate\Http\Request;

use App\Mail\OrderInvoiceMail;
use App\Jobs\SendOrderReceiptJob;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use App\Http\Requests\OrderStoreRequest;
use App\Models\CardsOnFile;

class OrderController extends Controller
{
    private $object;

    public function __construct()
    {
        $this->middleware('admin_has_permission:' . config('constants.role_modules.orders.value'));
        $this->object = new BaseController();
    }

    public function index(Request $request)
    {
        $orders = Order::query();

        if ($request->start_date) {
            $orders = $orders->where('created_at', '>=', $request->start_date);
        }

        if ($request->end_date) {
            $orders = $orders->where('created_at', '<=', $request->end_date . ' 23:59:59');
        }

        $orders = $orders
            ->with(['items', 'posPayments', 'customer'])
            ->latest()
            ->paginate(10);

        $total = $orders->sum(function ($order) {
            return is_numeric($order->formattedTotal()) ? $order->formattedTotal() : 0;
        });

        $receivedAmount = $orders->sum(function ($order) {
            return is_numeric($order->receivedAmount()) ? $order->receivedAmount() : 0;
        });

        return view('orders.index', compact('orders', 'total', 'receivedAmount'));
    }

    public function ordersToBeReturn(Request $request)
    {
        $order = Order::findOrFail($request->order_id);
        $orderItems = $order->orderItems()->with('product')->get();

        return response()->json($orderItems);
    }

    public function store(OrderStoreRequest $request)
    {
        try {
            DB::beginTransaction();
            $order = Order::create([
                'user_id' => $request->user()->id,
                'gift_card_id' => $request->gift_card_id ?? 0,
                'admin_id' => $request->user()->id,
                'amount' => $request->amount,
                'customer_id' => $request->customer_id,
                'gift_card_amount' => $request->gift_card_discount ?? 0,
            ]);

            $cart = $request->user()->cart()->get();
            foreach ($cart as $item) {
                $order->items()->create([
                    'price' => $item->price * $item->pivot->quantity + $item->pivot->tax - $item->pivot->discount,
                    'quantity' => $item->pivot->quantity,
                    'tax' => $item->pivot->tax,
                    'discount' => $item->pivot->discount,
                    'product_id' => $item->id,
                ]);
                $item->quantity = $item->quantity - $item->pivot->quantity;
                $item->save();
            }
            $request->user()->cart()->detach();

            $order->posPayments()->create([
                'amount' => $request->amount,
                'admin_id' => $request->user()->id,
                'payment_method' => $request->payment_method,
                'payment_acc_number' => $request->acc_number,
                'x_ref_num' => $request->x_ref_num,
            ]);

            $order = Order::orderFindById($order->id);

            // dispatch(new SendOrderReceiptJob($order));

            DB::commit();

            return response()->json(['success', 'Order Placed Successfully!', 'order_id' => $order->id]);
        } catch (Exception $e) {
            DB::rollBack();
            return $this->object->respondBadRequest(['error' => $e->getMessage()]);
        }
    }

    public function update(Request $request)
    {
        $order = PosPayment::where('order_id', $request->order_id)->first();
        $orderItem = OrderItem::where('order_id', $order->order_id)->first();
        if (!$order) {
            return response()->json(
                [
                    'error' => 'Order not found',
                    'message' => 'No PosPayment record found for order_id: ' . $request->order_id,
                ],
                404,
            );
        }

        $amount = floatval(preg_replace('/[^\d.]/', '', $request->amount));

        $order->amount += $amount;

        $order->payment_method = $order->payment_method ? $order->payment_method . ',' . $request->payment_method : $request->payment_method;

        $order->save();

        return response()->json(
            [
                'totalpayAmount' => $order,
                'OrderItem' => $orderItem,
            ],
            200,
        );
    }

    public function generateInvoice($id)
    {
        try {
            $order = Order::orderFindById($id);
            if (auth()->user()->organization_id == $order->organization_id || auth()->user()->admin_role_id == 1) {
                if (empty($order)) {
                    return redirect()->back()->with('error', 'Order not found.')->withInput();
                }

                return view('orders.invoice', compact('order'));
            }
            abort(403, 'Forbidden');
        } catch (\Exception $exception) {
            if ($exception->getMessage() == 'Forbidden') {
                abort(403);
            }
            return redirect()->back()->with('error', $exception->getMessage());
        }
    }

    public function sendInvoiceEmail(Request $request)
    {
        $order = Order::with('orderItems')->find($request->order_id);

        if (!$order) {
            return response()->json(
                [
                    'message' => 'Order Not Found',
                ],
                400,
            );
        }

        $logoUrl = session('receipt.logo') ? asset('storage/receipt_logos/' . session('receipt.logo')) : asset('images/default-logo.png');

        if ($order->amount >= $order->price) {
            $orderItems = $order->orderItems;

            Mail::send(
                'emails.orderEmail',
                [
                    'order' => $order,
                    'logoUrl' => $logoUrl,
                ],
                function ($message) use ($order, $request) {
                    $message->to($request->email)->subject('Your Invoice for Order #' . $request->order_id);
                },
            );

            return response()->json([
                'message' => 'Invoice Email sent successfully',
            ]);
        } else {
            return response()->json(
                [
                    'message' => 'Payment not completed',
                ],
                400,
            );
        }
    }

    public function insertCardsOnFiles(Request $request)
    {
        $validatedData = $request->validate([
            'name' => 'required|string|max:191',
            'customernumber' => 'required|string|max:255',
            'cartid' => 'required|string|max:255',
            'receipt' => 'required|string|max:255',
            'email' => 'nullable|email',
            'xmaskedcardnumber' => 'required|string',
            'method' => 'required|string',
            'xtoken' => 'required|string',
            'gateway_response' => 'required|json',
        ]);

        $card = CardsOnFile::create($validatedData);

        return response()->json(
            [
                'message' => 'Card stored successfully',
                'data' => $card,
            ],
            201,
        );
    }
    public function indexUnifiedBookings(Request $request)
    {
        $query = Reservation::query()
            ->whereNotNull('group_confirmation_code')
            ->select(
                'group_confirmation_code',
                DB::raw('MIN(cid) as min_cid'),
                DB::raw('MAX(cod) as max_cod'),
                DB::raw('SUM(total) as grand_total'),
                DB::raw('MAX(fname) as fname'),
                DB::raw('MAX(lname) as lname'),
                DB::raw('MAX(email) as email'),
                DB::raw('MAX(cartid) as cartid')
            )
            ->groupBy('group_confirmation_code');

        if ($request->search) {
            $search = $request->search;
            $query->where(function ($q) use ($search) {
                $q->where('group_confirmation_code', 'like', "%$search%")
                  ->orWhere('fname', 'like', "%$search%")
                  ->orWhere('lname', 'like', "%$search%")
                  ->orWhere('email', 'like', "%$search%");
            });
        }

        $checkouts = $query->latest('min_cid')->paginate(15);

        // For each checkout, we need to calculate total paid across all reservations in that group
        foreach ($checkouts as $checkout) {
            $cartIds = Reservation::where('group_confirmation_code', $checkout->group_confirmation_code)
                ->pluck('cartid')
                ->unique();
            
            $checkout->total_paid = DB::table('payments')
                ->whereIn('cartid', $cartIds)
                ->sum('payment');
            
            $checkout->total_refunds = DB::table('refunds')
                ->whereIn('cartid', $cartIds)
                ->sum('amount');
            
            $checkout->net_paid = $checkout->total_paid - $checkout->total_refunds;
            $checkout->balance = $checkout->grand_total - $checkout->net_paid;
        }

        return view('admin.orders.index_unified', compact('checkouts'));
    }

    public function showUnifiedBooking($confirmation_code)
    {
        $reservations = Reservation::where('group_confirmation_code', $confirmation_code)
            ->with(['site', 'user'])
            ->get();

        if ($reservations->isEmpty()) {
            abort(404, 'Booking not found.');
        }

        $mainRes = $reservations->first();
        $cartIds = $reservations->pluck('cartid')->unique();

        // Helper to parse addons_json
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
                $items = $addonsJson;
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

        // Build comprehensive ledger
        $ledger = collect();
        $seq = 0;

        // 1. Charges and Adjustments per reservation
    foreach ($reservations as $res) {
        $resAdditionalPayments = AdditionalPayment::where('reservation_id', $res->id)->get();
        $resRefunds = DB::table('refunds')->where('reservations_id', $res->id)->get();
        
        $additionsTotal = $resAdditionalPayments->sum('total');
        $reductionsTotal = $resRefunds->sum('amount');
        
        $currentTotal = (float)($res->total ?? 0);
        $originalTotal = $currentTotal - $additionsTotal + $reductionsTotal;
        
        $siteLock = (float)($res->sitelock ?? 0);
        $addons = $parseAddons($res->addons_json);
        $addonsTotal = $addons['addons_total'];
        
        // Derived Original Site Rental
        $originalSiteRental = round($originalTotal - $siteLock - $addonsTotal, 2);

        $stayLabel = '';
        try {
            if (!empty($res->cid) && !empty($res->cod)) {
                $stayLabel = ' (' .
                    \Carbon\Carbon::parse($res->cid)->format('M d') .
                    '–' .
                    \Carbon\Carbon::parse($res->cod)->format('M d') .
                    ')';
            }
        } catch (\Throwable $e) {}

        // Original Site Rental line
        if ($originalSiteRental != 0.0) {
            $ledger->push([
                'date' => $res->created_at,
                'description' => "Original Site Rental: {$res->siteid}{$stayLabel}",
                'type' => 'charge',
                'status' => 'Original',
                'amount' => $originalSiteRental,
                'ref' => $res->confirmation_code ?? $res->group_confirmation_code,
                'seq' => $seq++
            ]);
        }

        // Site Lock Fee line (assuming fixed for now)
        if ($siteLock > 0) {
            $ledger->push([
                'date' => $res->created_at,
                'description' => "Site Lock Fee ({$res->siteid})",
                'type' => 'charge',
                'status' => 'Original',
                'amount' => round($siteLock, 2),
                'ref' => null,
                'seq' => $seq++
            ]);
        }

        // Add-ons
        $addonItems = is_array($addons['items'] ?? null) ? $addons['items'] : [];
        foreach ($addonItems as $idx => $addon) {
            if (!is_array($addon)) continue;
            $rawName = (string)($addon['type'] ?? $addon['name'] ?? 'Addon');
            $qty = (int)($addon['qty'] ?? 1);
            $price = (float)($addon['total_price'] ?? $addon['price'] ?? 0);
            $belongsToSite = $addon['site_id'] ?? $res->siteid;
            $suffix = $qty > 1 ? " x{$qty}" : '';
            if ($price != 0.0) {
                $ledger->push([
                    'date' => $res->created_at,
                    'description' => "Add-on: {$rawName} ({$belongsToSite}){$suffix}",
                    'type' => 'charge',
                    'status' => 'Original',
                    'amount' => round($price, 2),
                    'ref' => null,
                    'seq' => $seq++
                ]);
            }
        }

        // Adjustments (Charges side)
        foreach ($resAdditionalPayments as $ap) {
            $ledger->push([
                'date' => $ap->created_at,
                'description' => "Adjustment: Upcharge - " . ($ap->comment ?? 'Date/Site Modification'),
                'type' => 'charge',
                'status' => 'Adjustment',
                'amount' => (float)$ap->total,
                'ref' => $ap->x_ref_num,
                'seq' => $seq++
            ]);
        }

        foreach ($resRefunds as $r) {
            $ledger->push([
                'date' => $r->created_at,
                'description' => "Adjustment: Stay Reduction" . ($r->reason ? " ({$r->reason})" : ''),
                'type' => 'charge',
                'status' => 'Adjustment',
                'amount' => -(float)$r->amount,
                'ref' => $r->x_ref_num,
                'seq' => $seq++
            ]);
        }
    }

    // 2. Payment lines
    $payments = DB::table('payments')->whereIn('cartid', $cartIds)->get();
    foreach ($payments as $p) {
        $ledger->push([
            'date' => $p->created_at,
            'description' => "Payment – {$p->method}",
            'type' => 'payment',
            'status' => 'Approved',
            'amount' => -abs((float)($p->payment ?? 0)),
            'ref' => $p->x_ref_num,
            'seq' => $seq++
        ]);
    }

    // 2.1 Additional Payments (Money side)
    $allAdditionalPayments = AdditionalPayment::whereIn('cartid', $cartIds)->get();
    foreach ($allAdditionalPayments as $ap) {
        $ledger->push([
            'date' => $ap->created_at,
            'description' => "Additional Payment - " . ($ap->method ?? 'Credit Card'),
            'type' => 'payment',
            'status' => 'Approved',
            'amount' => -abs((float)($ap->total ?? 0)),
            'ref' => $ap->x_ref_num,
            'seq' => $seq++
        ]);
    }

    // 3. Refund lines (Money side)
    $allRefunds = DB::table('refunds')->whereIn('cartid', $cartIds)->get();
    foreach ($allRefunds as $r) {
        $ledger->push([
            'date' => $r->created_at,
            'description' => "Refund Issued" . ($r->reason ? ": {$r->reason}" : ''),
            'type' => 'refund',
            'status' => 'Refunded',
            'amount' => (float)$r->amount,
            'ref' => $r->x_ref_num ?? $r->method,
            'seq' => $seq++
        ]);
    }

    // Sort ledger chronologically
    $ledger = $ledger->sortBy('date')->values();

    // Financials
    $totalCharges = $reservations->sum('total');
    $totalPayments = $payments->sum('payment') + $allAdditionalPayments->sum('total');
    $totalRefunds = $allRefunds->sum('amount');
    $balanceDue = $totalCharges - ($totalPayments - $totalRefunds);

        return view('admin.orders.show_unified', compact(
            'reservations',
            'mainRes',
            'totalCharges',
            'totalPayments',
            'totalRefunds',
            'balanceDue',
            'ledger',
            'refunds'
        ));
    }
}
