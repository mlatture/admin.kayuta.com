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
    public function indexPayments(Request $request)
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

        return view('admin.orders.index_payments', compact('checkouts'));
    }

    public function showOrder($confirmation_code)
    {
        $reservations = Reservation::where('group_confirmation_code', $confirmation_code)
            ->with(['site', 'user'])
            ->get();

        if ($reservations->isEmpty()) {
            abort(404, 'Order not found.');
        }

        $mainRes = $reservations->first();
        $cartIds = $reservations->pluck('cartid')->unique();

        // Financials
        $totalCharges = $reservations->sum('total');
        $payments = DB::table('payments')->whereIn('cartid', $cartIds)->get();
        $totalPayments = $payments->sum('payment');
        $refunds = DB::table('refunds')->whereIn('cartid', $cartIds)->get();
        $totalRefunds = $refunds->sum('amount');
        $balanceDue = $totalCharges - ($totalPayments - $totalRefunds);

        // Transaction History (Ledger)
        $ledger = collect();

        foreach ($reservations as $res) {
            $ledger->push([
                'date' => $res->created_at,
                'type' => 'charge',
                'description' => "Initial Reservation: Site {$res->siteid}",
                'amount' => $res->total,
                'ref' => $res->confirmation_code
            ]);
        }

        foreach ($payments as $p) {
            $ledger->push([
                'date' => $p->created_at,
                'type' => 'payment',
                'description' => "Payment via {$p->method}",
                'amount' => -$p->payment,
                'ref' => $p->x_ref_num ?? $p->receipt
            ]);
        }

        foreach ($refunds as $r) {
            $ledger->push([
                'date' => $r->created_at,
                'type' => 'refund',
                'description' => "Refund: {$r->reason}",
                'amount' => $r->amount,
                'ref' => $r->x_ref_num
            ]);
        }

        $ledger = $ledger->sortBy('date');

        return view('admin.orders.show_order', compact(
            'reservations',
            'mainRes',
            'totalCharges',
            'totalPayments',
            'totalRefunds',
            'balanceDue',
            'ledger'
        ));
    }
}
