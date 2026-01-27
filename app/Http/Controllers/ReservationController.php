<?php

namespace App\Http\Controllers;

use App\CPU\Helpers;
use App\Http\Requests\ReservationDateRequest;
use App\Http\Requests\ReservationSiteRequest;
use App\Models\CardsOnFile;
use App\Models\Payment;
use App\Models\Receipt;
use App\Models\Coupon;
use App\Models\Event;
use App\Models\RateTier;
use App\Models\CampingSeason;
use App\Models\Customer;
use App\Models\Reservation;
use App\Models\Site;
use App\Models\SiteClass;
use App\Models\SiteHookup;
use App\Models\User;
use App\Models\AdditionalPayment;
use App\Models\Refund;
use App\Services\CardKnoxService;
use Carbon\Carbon;
use DateTime;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Session;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;

class ReservationController extends Controller
{
    private $siteClass;
    private $site;
    protected $rateTier;
    protected $event;
    protected $reservation;
    protected $receipt;
    protected $cardsOnFile;
    protected $payment;

    public function __construct(SiteClass $siteClass, Site $site, RateTier $rateTier, Event $event, Reservation $reservation, Receipt $receipt, CardsOnFile $cardsOnFile, Payment $payment)
    {
        $this->middleware('admin_has_permission:' . config('constants.role_modules.reservation_management.value'));
        $this->siteClass = $siteClass;
        $this->site = $site;
        $this->rateTier = $rateTier;
        $this->event = $event;
        $this->reservation = $reservation;
        $this->receipt = $receipt;
        $this->cardsOnFile = $cardsOnFile;
        $this->payment = $payment;
    }

    // Optimized Logic
    public function index(Request $request)
    {
        $startDate = $request->startDate ? Carbon::parse($request->startDate) : Carbon::today();
        $endDate = $startDate->copy()->addDays(30);

        $filters = [
            'startDate' => $startDate->toDateString(),
            'endDate' => $endDate->toDateString(),
            'riglength' => $request->input('riglength', null),
        ];

        $query = $this->site::query();

        $query->where(function ($q) use ($request) {
            $filter = $request->input('seasonalFilter', 'short');

            if ($filter === 'short') {
                $q->where('seasonal', 0);
            } elseif ($filter === 'seasonal') {
                $q->where('seasonal', 1);
            } elseif ($filter === 'all') {
                // show all → no filter
            } else {
                $q->where('seasonal', 0);
            }
        });

        if (!empty($filters['riglength'])) {
            $query->where(function ($q) use ($filters) {
                $q->where('maxlength', '>=', $filters['riglength']);
            });
        }

        if ($request->has('siteclass') && is_array($request->siteclass) && count($request->siteclass) > 0) {
            $selectedClasses = $request->siteclass;

            $normalizedClasses = collect($selectedClasses)->map(function ($class) {
                return str_replace(' ', '_', $class);
            });

            $query->where(function ($q) use ($normalizedClasses) {
                foreach ($normalizedClasses as $class) {
                    $q->orWhere('siteclass', $class)
                        ->orWhere('siteclass', 'like', $class . ',%')
                        ->orWhere('siteclass', 'like', '%,' . $class)
                        ->orWhere('siteclass', 'like', '%,' . $class . ',%');
                }
            });
        }

        $site_classes = Cache::remember('site_classes_all', 3600, function () {
            return $this->siteClass::all();
        });

        $rate_tiers = Cache::remember('rate_tiers_all', 3600, function () {
            return $this->rateTier::all();
        });

        if ($request->siteid || $request->ratetier) {
            if ($request->has('ratetier') && is_array($request->ratetier) && count($request->ratetier) > 0) {
                $query->whereIn('ratetier', $request->ratetier);
            }

            if ($request->has('siteid')) {
                $siteIds = $request->input('siteid', []);

                if (is_array($siteIds) && count($siteIds) > 0) {
                    $query->whereIn('siteid', $siteIds);
                }
            }
        }

        $orderby = $request->input('orderby', 'siteid');
        $orderDirection = $request->input('orderdir', 'asc');

        $allowedColumns = ['siteid', 'siteclass', 'minlength', 'maxlength', 'ratetier'];
        $allowedDirections = ['asc', 'desc'];

        if (!in_array($orderby, $allowedColumns)) {
            $orderby = 'siteid';
        }

        if (!in_array(strtolower($orderDirection), $allowedDirections)) {
            $orderDirection = 'asc';
        }

        $query->orderBy($orderby, $orderDirection);

        $sites = $query
            ->with([
                'reservations' => function ($resQ) use ($filters) {
                    $resQ->where('cod', '>=', $filters['startDate'])
                        ->where('cid', '<=', $filters['endDate'])
                        ->where('status', '!=', 'cancelled')
                        ->orderBy('cid')
                        ->select('siteid', 'cid', 'cod', 'id', 'cartid', 'fname',
                                'lname', 'sitelock', 'source', 'createdby', 'status', 'checkedin',
                                'total', 'payment_id', 'group_confirmation_code', 'xconfnum',
                                DB::raw('DATEDIFF(cod, cid) as days'));

                },
                'reservations.payment',
            ])
            ->paginate(50);

        $events = Event::where('minimumstay', '>', 1)->get();
        $calendar = $this->generateSeasonCalendar($filters['startDate'], $filters['endDate'], $events);

        $calendarDates = collect($calendar)->pluck('date')->toArray();

        foreach ($sites as $site) {
            $site->totalDays = $site->reservations->sum('days');
            $site->isVacant = $site->reservations->isEmpty();

            $availability = array_fill_keys($calendarDates, null);

            foreach ($site->reservations as $res) {
                $relatedCartIds = Reservation::where(function($q) use ($res) {
                    if($res->payment_id) $q->orWhere('payment_id', $res->payment_id);
                    if($res->group_confirmation_code) $q->orWhere('group_confirmation_code', $res->group_confirmation_code);
                    $q->orWhere('cartid', $res->cartid);
                })->pluck('cartid');

                $groupTotalCharges = Reservation::whereIn('cartid', $relatedCartIds)->sum('total');

                $initialPayment = $res->payment ? (float) $res->payment->payment : 0;
                $extraPayments = AdditionalPayment::whereIn('cartid', $relatedCartIds)->sum('amount');

                $refunds =  Refund::whereIn('cartid', $relatedCartIds)->sum('amount');
                $cancelFees = Refund::whereIn('cartid', $relatedCartIds)->sum('cancellation_fee');

                $netBalance = ($groupTotalCharges + $cancelFees) - ($initialPayment + $extraPayments ) + $refunds;

                $res->balance_due = max(0, round($netBalance, 2));

                $resStart = Carbon::parse($res->cid);
                $resEnd = Carbon::parse($res->cod);

                foreach ($calendarDates as $date) {
                    $day = Carbon::parse($date);

                    if ($day->gte($resStart) && $day->lt($resEnd)) {
                        if ($date === $resStart->format('Y-m-d')) {
                            $availability[$date] = $res;
                        } else {
                            $availability[$date] = true;
                        }
                    }
                }
            }
            $site->availability = $availability;
        }

        return view('reservations.index', compact('site_classes', 'rate_tiers', 'sites', 'calendar', 'filters'));
    }

   

    private function generateSeasonCalendar($startDate, $endDate, $events = [])
    {
        $calendar = [];
        $currentDate = Carbon::parse($startDate);
        $endDate = Carbon::parse($endDate);

        $minStayEvents = [];

        foreach ($events as $event) {
            if ($event->minimumstay <= 1) {
                continue;
            }

            $start = Carbon::parse($event->eventstart);
            $end = Carbon::parse($event->eventend);

            while ($start <= $end) {
                $minStayEvents[$start->format('Y-m-d')] = [
                    'nights' => $event->minimumstay,
                    'title' => $event->eventname,
                ];
                $start->addDay();
            }
        }

        while ($currentDate <= $endDate) {
            $dateStr = $currentDate->format('Y-m-d');

            $calendar[] = [
                'date' => $dateStr,
                'event' => $minStayEvents[$dateStr] ?? null,
            ];

            $currentDate->addDay();
        }

        return $calendar;
    }

    private function getAvailableDatesForSite($siteId, $startDate, $endDate)
    {
        $start = Carbon::parse($startDate);
        $end = Carbon::parse($endDate);

        $reservations = Reservation::where('siteid', $siteId)
            ->where(function ($q) use ($start, $end) {
                $q->whereBetween('cid', [$start, $end])
                    ->orWhereBetween('cod', [$start, $end])
                    ->orWhere(function ($query) use ($start, $end) {
                        $query->where('cid', '<=', $start)->where('cod', '>=', $end);
                    });
            })
            ->get();

        $allDates = collect();
        $current = $start->copy();
        while ($current <= $end) {
            $allDates->push($current->format('Y-m-d'));
            $current->addDay();
        }

        foreach ($reservations as $res) {
            $resStart = Carbon::parse($res->cid);
            $resEnd = Carbon::parse($res->cod)->subDay(); // exclusive cod
            $range = [];
            while ($resStart <= $resEnd) {
                $range[] = $resStart->format('Y-m-d');
                $resStart->addDay();
            }

            $allDates = $allDates->diff($range);
        }

        return $allDates
            ->filter(function ($date) {
                return Carbon::parse($date)->gte(Carbon::today());
            })
            ->values();
    }

    public function details(Request $request)
    {
        $reservation = Reservation::with(['user', 'site'])->findOrFail($request->id);

        return response()->json([
            'cartid' => $reservation->cartid,
            'fname' => $reservation->user->f_name ?? ($reservation->fname ?? 'Guest'),
            'lname' => $reservation->user->l_name ?? ($reservation->lname ?? ' '),
            'cid' => $reservation->cid,
            'cod' => $reservation->cod,
            'siteid' => $reservation->site->siteid ?? 'N/A',
            'rigtype' => $reservation->rig_type ?? 'N/A',
            'riglength' => $reservation->rig_length ?? 'N/A',
            'siteclass' => $reservation->site->siteclass ?? 'N/A',
            'nights' => $reservation->nights,
            'comments' => $reservation->comments,
            'total' => $reservation->total ?? 0,
            'balance' => $reservation->balance ?? 0,
            'checkedin' => optional($reservation->checkedin)->format('F j, Y g:i A') ?? null,
            'checkedout' => optional($reservation->checkedout)->format('F j, Y g:i A') ?? null,
            'source' => $reservation->source ?? 'Walk-In',
            'status' => $reservation->status ?? 'Pending',
            'customerRecord' => [
                'email' => $reservation->user->email ?? ($reservation->email ?? 'N/A'),
                'phone' => $reservation->user->phone ?? ($reservation->phone ?? 'N/A'),
            ],
        ]);
    }

    public function create()
    {
        $data['customers'] = User::get();
        $data['sites'] = Site::get();
        $data['classes'] = SiteClass::get();
        $data['hookups'] = SiteHookup::get();
        return view('reservations.create', $data);
    }

    public function store(Request $request)
    {
        $siteclass = $request->siteclass;

        if ($siteclass == 'RV_Sites' || $siteclass == 'Deluxe_RV_Sites') {
            if (!$request->riglength) {
                return redirect()->back()->with('error', 'Rig Length is required!')->withInput();
            }
            $riglength = $request->riglength;
        } else {
            $riglength = null;
        }

        $checkinDate = $request->input('cid');
        $checkoutDate = $request->input('cod');
        $seasons = CampingSeason::all();
        $seasonMatch = null;

        foreach ($seasons as $season) {
            if ($checkinDate >= $season->opening_day && $checkoutDate <= $season->closing_day) {
                $seasonMatch = $season;
                break; // Break the loop if a matching season is found
            }
        }

        if (!$seasonMatch) {
            return redirect()->back()->with('error', 'Your selected dates are not in camping seasons.')->withInput();
        }
        $randomId = rand(9999, 99999);
        Session::put('booking_' . $randomId, [
            'cid' => $request->cid ?? '',
            'cod' => $request->cod ?? '',
            'hookup' => $request->hookup ?? '',
            'customer_id' => $request->customer_id ?? '',
            'riglength' => $request->riglength ?? '',
            'siteclass' => $request->siteclass ?? '',
        ]);
        return redirect()->route('reservations.book.site', [$randomId]);
    }

    public function bookSite($bookingId)
    {
        $booking = Session::get('booking_' . $bookingId);
        $items = Session::get('reservation_cart_items_' . $bookingId);
        if ($booking) {
            $hookup = $booking['hookup'];
            $siteclass = $booking['siteclass'];
            $sites = $this->site->checkAllSites($booking['cid'], $booking['cod'])->get();
            $cartIds = Session::get('cart_items') ?? [];
            if ($siteclass == 'RV_Sites' || $siteclass == 'Deluxe_RV_Sites') {
                if (!$booking['riglength']) {
                    return redirect()->route('reservations.index')->with('error', 'Rig Length is required!');
                }
                $riglength = $booking['riglength'];
            } else {
                $riglength = null;
            }
            return view('reservations.booking', compact('sites', 'items', 'hookup', 'riglength', 'siteclass', 'cartIds', 'booking', 'bookingId'));
        }
        return redirect()->route('reservations.create');
    }

    public function updateDates(Request $request, $id)
    {
        $reservation = Reservation::findOrFail($id);
        $reservation->cid = $request->input('start_date');
        $reservation->cod = $request->input('end_date');
        $reservation->save();

        return response()->json(['success' => true]);
    }

    public function updateSites(ReservationSiteRequest $request)
    {
        $reservation = Reservation::findOrFail($request->reservationId2);
        $reservation->update([
            'siteid' => $request->siteid,
            'siteclass' => $request->siteclass,
        ]);
        return response()->json(['status' => 'success', 'message' => 'Success, Reservation sites has been updated successfully.'], 200);
    }

    public function addToCart(Request $request)
    {
        $cartItems = Session::get('reservation_cart_items_' . $request->bookingId) ?? [];
        if (count($cartItems) > 0) {
            $copyArr = $cartItems;
            $isExist = false;
            foreach ($cartItems as $item) {
                if ($item['siteid'] == $request->siteid) {
                    $isExist = true;
                }
            }
            if (empty($isExist)) {
                array_push($copyArr, $request->all());
                Session::remove('reservation_cart_items_' . $request->bookingId);
                Session::put('reservation_cart_items_' . $request->bookingId, $copyArr);
            }
        } else {
            Session::put('reservation_cart_items_' . $request->bookingId, [$request->all()]);
        }
        return response()->json(['status' => 'success'], 200);
    }

    public function removeCart($bookingId, $cartId)
    {
        $cartItems = Session::get('reservation_cart_items_' . $bookingId);
        if (!empty($cartItems) && count($cartItems) > 0) {
            $copyArr = $cartItems;
            foreach ($copyArr as $k => $v) {
                if ($v['cartid'] == $cartId) {
                    unset($copyArr[$k]);
                }
            }
            Session::put('reservation_cart_items_' . $bookingId, $copyArr);
        }
        return redirect()->back();
    }

    public function checkout($bookingId)
    {
        $booking = Session::get('booking_' . $bookingId);
        $items = Session::get('reservation_cart_items_' . $bookingId) ?? [];
        if (!empty($booking)) {
            $customer = User::find($booking['customer_id']);
            if (count($items) > 0) {
                return view('reservations.checkout', compact('booking', 'items', 'customer', 'bookingId'));
            }
            return redirect()->route('reservations.book.site', [$bookingId]);
        }
        return redirect()->route('reservations.create');
    }

    public function siteDetail(Request $request, $siteId, $bookingId)
    {
        $booking = Session::get('booking_' . $bookingId);
        if (!empty($booking)) {
            $siteDetail = $this->site->whereFirst(['siteid' => $siteId]);
            $rateTier = $this->rateTier->whereFirst(['tier' => $siteDetail->ratetier]);
            $events = $this->event->getEventsByCidCod($booking['cid'], $booking['cod']);
            $dateDifference = Helpers::dateDifferenceOfTwoDates($booking['cod'], $booking['cid']);
            $lengthofStay = $dateDifference->days;
            $thissiteisavailable = true;
            $minimumstay = 1;
            $bookingmessage = '';
            $extracharge = 0;
            $extranightlycharge = 0;
            $eventname = '';
            $uscid = $booking['cid'];
            $uscod = $booking['cod'];
            $riglength = $booking['riglength'];
            $siteid = $siteId;
            $siteclass = $booking['siteclass'];
            $siteLock = 'On';
            $siteLockFee = 20;
            $avgnightlyrate = 0;
            $siteLockMessage =
                "
        While we guarantee your site type, our automated optimization system may change your site location.
        You can guarantee your chosen site with a lock.
        The site lock fee of $" .
                $siteLockFee .
                ' will be added to your cart';
            $workingtotal = 0;
            $base = 0;
            $rateadjustment = 0;

            if ($siteDetail) {
                if (!isset($rateTier)) {
                    $thissiteisavailable = false;
                } else {
                    if (isset($events)) {
                        foreach ($events as $event) {
                            if (is_int($event['minimumstay'])) {
                                $minimumstay = max($minimumstay, $event['minimumstay']);
                            }
                            if ($eventname != '') {
                                $eventname .= ', ';
                            }
                            $eventname .= $event['eventname'];
                            $extracharge += $event['extracharge'];
                            if (is_numeric($event['extranightlycharge'])) {
                                $overlap = $this->getOverlapDays($booking['cid'], $booking['cod'], $event['eventstart'], $event['eventend']);
                                $extranightlycharge = $event['extranightlycharge'] * $overlap;
                                $extracharge += $extranightlycharge;
                            }
                        }

                        if ($eventname != '') {
                            $bookingmessage .= 'This booking is during ' . $eventname . '. Sites booked during these events are subject to a surcharge of ' . \App\CPU\Helpers::format_currency_usd($extracharge) . '.';
                        }
                    }

                    if (isset($rateTier)) {
                        $minimumstay = max($minimumstay, $rateTier['minimumstay']);
                        $minimumstay = max($minimumstay, $siteDetail['minimumstay']);
                        if ($lengthofStay < $minimumstay) {
                            $thissiteisavailable = false;
                            $bookingmessage .= ' The minimum length of stay is ' . $minimumstay . ' nights.';
                        }
                        //===========================================
                        //              5> Marked as unavailable
                        //===========================================
                        if ($siteDetail['available'] != '1') {
                            $thissiteisavailable = false;
                            $bookingmessage .= ' This site is currently marked as unavailable.';
                        }
                        //===========================================
                        //              6> Marked as unavailable online
                        //===========================================
                        // if (($siteDetail['availableonline'] <> "1")&&(!isset($_SESSION['admin']))) {
                        if ($siteDetail['availableonline'] != '1') {
                            $thissiteisavailable = false;
                            $bookingmessage .= ' This site is currently marked as unavailable online.';
                        }
                        //===========================================
                        //              7> Marked as seasonal
                        //===========================================
                        //if (($siteDetail['seasonal'] == "1")&&(!isset($_SESSION['admin']))) {
                        if ($siteDetail['seasonal'] == '1') {
                            $thissiteisavailable = false;
                            $bookingmessage .= ' This seasonal site is not available online.';
                        }

                        if ($thissiteisavailable) {
                            //=====================================================
                            //   Passed all checks now calculate rate
                            //=====================================================
                            $rateadjustment = $extranightlycharge;
                            $avgnightlyrate = 0;
                            //===========================================
                            //   Use Dynamic pricing changes (occupancy)
                            //===========================================
                            if ($rateTier['usedynamic'] == '1') {
                                // $bookingmessage .= " We are using dynamic pricing.";
                                $bookings = $this->site->checkAvailable($booking['cid'], $booking['cod'], $rateTier['tier']);
                                $availablesites = Session::get('numrows');
                                $bookings = $this->site->checkBooked($booking['cid'], $booking['cod'], $rateTier['tier']);
                                $bookedsites = Session::get('numrows');
                                // $bookingmessage .= " There are " . $availablesites . " available sites and " . $bookedsites . " sites booked (for " . $rateTier['tier'] . ")";
                                $dynamicdecreasepercent = $rateTier['dynamicdecreasepercent'] ?? 0.1; // if not specified, use 10%
                                $dynamicincreasepercent = $rateTier['dynamicincreasepercent'] ?? 0.4; // if not specified, use 40%
                                if ($bookedsites / $availablesites < $dynamicdecreasepercent) {
                                    $rateadjustment -= $rateTier['dynamicdecrease'];
                                    // $bookingmessage .= " For (" . $rateTier['tier'] . ") there are low occupancy (<" . $dynamicdecreasepercent . ") rates of " . $rateTier['dynamicdecrease'] . "($" . $rateadjustment . ")";
                                }
                                if ($bookedsites / $availablesites > $dynamicincreasepercent) {
                                    $rateadjustment += $rateTier['dynamicincrease'];
                                    // $bookingmessage .= " For (" . $rateTier['tier'] . ") there are high occupancy (>" . $dynamicincreasepercent . ") rates of " . $rateTier['dynamicincrease'] . "($" . $rateadjustment . ")";
                                }
                                //===========================================
                                //   Use Dynamic pricing changes (last minute)
                                //===========================================
                                $targetDate = new DateTime($booking['cid']);
                                $currentDate = new DateTime();
                                $interval = $currentDate->diff($targetDate);
                                $lastminuteincreasedays = $rateTier['lastminuteincreasedays'] ?? 7; // if not specified, use 7 days
                                $earlybookingincreasedays = $rateTier['earlybookingincreasedays'] ?? 100; //if not specified, use 180 days
                                if ($interval->days < $lastminuteincreasedays) {
                                    $rateadjustment += $rateTier['lastminuteincrease'];
                                    // $bookingmessage .= " Sites booked last minute (<" . $lastminuteincreasedays . " days) subject to increase of (" . Helpers::format_currency_usd($rateadjustment) . ").";
                                }
                                if ($interval->days > $earlybookingincreasedays) {
                                    $rateadjustment += $rateTier['earlybookingincrease'];
                                    // $bookingmessage .= " Sites booked too far in advance (>" . $earlybookingincreasedays . " days) subject to increase of " . Helpers::format_currency_usd($rateTier['earlybookingincrease']) . " (Total:" . Helpers::format_currency_usd($rateadjustment) . ").";
                                }
                            }
                            $workingtotal = 0;
                            $base = 0; //Rate without adjustments
                            //===========================================
                            //  Use flat rate or nightly variable pricing
                            //===========================================
                            if ($rateTier['useflatrate'] == '1') {
                                $avgnightlyrate = $rateTier['flatrate'] + $rateadjustment;
                                $workingtotal = $avgnightlyrate * $lengthofStay + $extracharge;
                                // $bookingmessage .= " This rate tier uses fixed nightly pricing.";
                                $base = $rateTier['flatrate'] * $lengthofStay;
                                $rateadjustment = $rateadjustment * $lengthofStay;
                                //echo "Nightly rate is $".($rateTier['flatrate']+$rateadjustment);
                            } else {
                                // $bookingmessage .= " This rate tier uses variable nightly pricing.";
                                $currentDate = strtotime($booking['cid']); // Convert check-in date to timestamp
                                $endDate = strtotime($booking['cod']); // Convert check-out date to timestamp
                                //echo "outside the loop";
                                while ($currentDate <= $endDate) {
                                    switch (date('l', $currentDate)) {
                                        case 'Sunday':
                                            $workingtotal += $rateTier['sundayrate'];
                                            $base += $rateTier['sundayrate'];
                                            break;
                                        case 'Monday':
                                            $workingtotal += $rateTier['mondayrate'];
                                            $base += $rateTier['mondayrate'];
                                            break;
                                        case 'Tuesday':
                                            $workingtotal += $rateTier['tuesdayrate'];
                                            $base += $rateTier['tuesdayrate'];
                                            break;
                                        case 'Wednesday':
                                            $workingtotal += $rateTier['wednesdayrate'];
                                            $base += $rateTier['wednesdayrate'];
                                            break;
                                        case 'Thursday':
                                            $workingtotal += $rateTier['thursdayrate'];
                                            $base += $rateTier['thursdayrate'];
                                            break;
                                        case 'Friday':
                                            $workingtotal += $rateTier['fridayrate'];
                                            $base += $rateTier['fridayrate'];
                                            break;
                                        case 'Saturday':
                                            $workingtotal += $rateTier['saturdayrate'];
                                            $base += $rateTier['saturdayrate'];
                                            break;
                                    }
                                    $workingtotal += $rateadjustment;
                                    //echo format_currency_usd($workingtotal)  . ", ";
                                    //                            echo $currentDate . ": " . date('l', $currentDate) . '<br>'; // Output day of the week
                                    $currentDate += 86400; // Add one day (in seconds) to current date
                                }
                                $avgnightlyrate = $workingtotal / $lengthofStay + $extracharge;
                            }
                        }
                        // echo "<br>" . $lengthofStay, " and " . $rateTier['monthlyrate'];
                        if ($lengthofStay >= 30 && $rateTier['monthlyrate'] > 0) {
                            // $bookingmessage .= " Monthly rate applied.";
                            $firstmonth = $rateTier['monthlyrate'] + 30 * $rateadjustment;
                            //echo "<br>Firstmonth:" . $firstmonth;
                            $remainingstay = ($lengthofStay - 30) * $avgnightlyrate;
                            //echo "<br>Remaining stay(" . ($lengthofStay - 30) . "x$" . $avgnightlyrate . "):" . $remainingstay;
                            $workingtotal = $firstmonth + $remainingstay;
                            $avgnightlyrate = $workingtotal / $lengthofStay;
                        } else {
                            if ($lengthofStay >= 7 && $rateTier['weeklyrate'] > 0) {
                                // $bookingmessage .= " Weekly rate applied.";
                                $firstweek = $rateTier['weeklyrate'] + 7 * $rateadjustment;
                                //echo "<br>Firstmonth:" . $firstmonth;
                                $remainingstay = ($lengthofStay - 7) * $avgnightlyrate;
                                //echo "<br>Remaining stay(" . ($lengthofStay - 30) . "x$" . $avgnightlyrate . "):" . $remainingstay;
                                $workingtotal = $firstweek + $remainingstay;
                                $avgnightlyrate = $workingtotal / $lengthofStay;
                            }
                        }
                    }
                }

                $taxrate = $siteDetail['taxrate'] ?? 0;
            }
            return response()->json(['status' => 'success', 'content' => view('reservations.site-details', compact('siteclass', 'bookingId', 'siteid', 'riglength', 'extracharge', 'siteDetail', 'rateTier', 'uscid', 'uscod', 'lengthofStay', 'workingtotal', 'avgnightlyrate', 'bookingmessage', 'siteLock', 'siteLockFee', 'siteLockMessage', 'eventname', 'thissiteisavailable', 'base', 'taxrate', 'rateadjustment', 'minimumstay'))->render()], 200);
        }
        abort(404);
    }

    // public function getOverlapDays($start1, $end1, $start2, $end2)
    // {
    //     // Convert date strings to Unix timestamps
    //     $start1 = strtotime($start1);
    //     $end1 = strtotime($end1);
    //     $start2 = strtotime($start2);
    //     $end2 = strtotime($end2);

    //     // Calculate the overlapping days
    //     $overlapStart = max($start1, $start2);
    //     $overlapEnd = min($end1, $end2);
    //     $overlapDays = max(0, ($overlapEnd - $overlapStart) / 86400 + 1);

    //     return $overlapDays;
    // }

    // public function applyCoupon(Request $request)
    // {
    //     $request->validate(
    //         [
    //             'coupon_code' => 'required',
    //             'amount' => 'required',
    //         ],
    //         [
    //             'coupon_code.required' => 'Coupon Code is required!',
    //         ],
    //     );

    //     DB::beginTransaction();

    //     try {
    //         $coupon = Coupon::where(['code' => $request['coupon_code']])
    //             ->where('expire_date', '>=', date('Y-m-d'))
    //             ->where('start_date', '<=', date('Y-m-d'))
    //             ->first();
    //         $discount = 0;
    //         if ($coupon && $coupon->min_purchase < $request->amount) {
    //             if ($coupon->discount_type == 'amount') {
    //                 $discount = $coupon->discount;
    //             } elseif ($coupon->discount_type == 'percentage') {
    //                 $discount = ($coupon->discount * $request->amount) / 100;
    //                 if ($discount > $coupon->max_discount) {
    //                     $discount = $coupon->max_discount;
    //                 }
    //             }
    //             return response()->json(['code' => 1, 'message' => 'Coupon applied successfully!', 'data' => (object) ['coupon' => $coupon, 'discount' => Helpers::format_currency_usd($discount), 'discount_amount' => $discount]], 200);
    //         } else {
    //             return response()->json(['errors' => ['Coupon is not applicable']], 400);
    //         }
    //     } catch (\Exception $e) {
    //         return response()->json('error', $e->getMessage());
    //     }
    // }

    // public function doCheckout(Request $request, $bookingId)
    // {
    //     //        dd($request->all());
    //     try {
    //         DB::beginTransaction();
    //         $booking = Session::get('booking_' . $bookingId);
    //         $carts = Session::get('reservation_cart_items_' . $bookingId) ?? [];
    //         if (!empty($booking)) {
    //             $user = User::find($booking['customer_id']);
    //             if (count($carts) == 0) {
    //                 return redirect()->route('reservations.book.site', [$bookingId]);
    //             }
    //             $amount = $request->input('xAmount');
    //             $discount = 0;
    //             if ($request->applicable_coupon && !empty($request->applicable_coupon)) {
    //                 $coupon = Coupon::where(['code' => $request->applicable_coupon])
    //                     ->where('expire_date', '>=', date('Y-m-d'))
    //                     ->where('start_date', '<=', date('Y-m-d'))
    //                     ->first();
    //                 if ($coupon && $coupon->min_purchase < $amount) {
    //                     if ($coupon->discount_type == 'amount') {
    //                         $discount = $coupon->discount;
    //                     } elseif ($coupon->discount_type == 'percentage') {
    //                         $discount = ($coupon->discount * $amount) / 100;
    //                         if ($discount > $coupon->max_discount) {
    //                             $discount = $coupon->max_discount;
    //                         }
    //                     }
    //                     $amount = $amount - $discount;
    //                 }
    //             }
    //             $cardNumber = $request->input('xCardNum');
    //             $xExp = str_replace('/', '', $request->xExp);
    //             $cardKnoxService = new CardKnoxService();
    //             $payment = $cardKnoxService->sale($cardNumber, $amount, $xExp);
    //             if ($payment['success'] == true) {
    //                 if ($payment['data']['xStatus'] == 'Error') {
    //                     return redirect()->back()->with('error', $payment['data']['xError']);
    //                 } elseif ($payment['data']['xStatus'] == 'Approved') {
    //                     $xAuthCode = $payment['data']['xAuthCode'];
    //                     $xToken = $payment['data']['xToken'];
    //                     $reservationIds = [];
    //                     if (count($carts) > 0) {
    //                         //                            dd($carts);
    //                         foreach ($carts as $cart) {
    //                             $receipt = $this->receipt->storeReceipt(['cartid' => $cart['cartid']]);
    //                             $sitelockFee = isset($cart['sitelock']) && $cart['sitelock'] == 'on' ? 20 : 0;
    //                             $reservation = $this->reservation->storeReservation([
    //                                 'xconfnum' => $xAuthCode,
    //                                 'cartid' => $cart['cartid'],
    //                                 'source' => 'Online Booking',
    //                                 'createdby' => 'Customer',
    //                                 'fname' => $user->f_name,
    //                                 'lname' => $user->l_name,
    //                                 'customernumber' => $user->id,
    //                                 'siteid' => $cart['siteid'],
    //                                 'cid' => $cart['cid'],
    //                                 'cod' => $cart['cod'],
    //                                 'sitelock' => $sitelockFee,
    //                                 'siteclass' => $cart['siteclass'],
    //                                 'totalcharges' => $cart['subtotal'] + $cart['taxrate'] + $sitelockFee,
    //                                 'nights' => $cart['nights'],
    //                                 'base' => $cart['base'],
    //                                 'rateadjustment' => isset($cart['rateadjustment']) ? $cart['rateadjustment'] : null,
    //                                 'rigtype' => '',
    //                                 'riglength' => $cart['riglength'],
    //                                 'rid' => $cart['rid'],
    //                                 'receipt' => $receipt->id,
    //                                 'discountcode' => $request->applicable_coupon ?? '',
    //                                 'total' => $cart['subtotal'] + $cart['taxrate'] + $sitelockFee,
    //                                 'subtotal' => $cart['subtotal'],
    //                             ]);

    //                             array_push($reservationIds, $reservation->id);

    //                             $this->cardsOnFile->storeCards([
    //                                 'customernumber' => $user->id,
    //                                 'method' => $payment['data']['xCardType'],
    //                                 'cartid' => $cart['cartid'],
    //                                 'email' => $user->email,
    //                                 'xmaskedcardnumber' => $payment['data']['xMaskedCardNumber'],
    //                                 'xtoken' => $xToken,
    //                                 'receipt' => $receipt->id,
    //                                 'gateway_response' => json_encode($payment['data']),
    //                             ]);

    //                             $this->payment->storePayment([
    //                                 'customernumber' => $user->id,
    //                                 'method' => $payment['data']['xCardType'],
    //                                 'cartid' => $cart['cartid'],
    //                                 'email' => $user->email,
    //                                 'payment' => $payment['data']['xAuthAmount'],
    //                                 'receipt' => $receipt->id,
    //                             ]);
    //                         }
    //                         Session::remove('booking_' . $bookingId);
    //                         Session::remove('reservation_cart_items_' . $bookingId);
    //                         DB::commit();
    //                         return redirect()->route('reservations.index')->with('success', 'Reservation created successfully.');
    //                     }
    //                     return redirect()->route('reservations.book.site', [$bookingId]);
    //                 }
    //             }
    //             return redirect()->back()->with('error', 'Something went wrong!');
    //         }
    //         return redirect()->route('reservations.create');
    //     } catch (Exception $e) {
    //         DB::rollBack();
    //         return redirect()->back()->with('error', $e->getMessage());
    //     }
    // }
}
