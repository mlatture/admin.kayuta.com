<?php

namespace App\Models;

use App\Models\OrderItem;
use App\Models\Payment;
use App\Models\User;
use App\Models\CardsOnFile;
use Carbon\Carbon;
use App\Models\RateTier;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Reservation extends Model
{
    use HasFactory;

    protected $table = 'reservations';

    protected $dates = ['cid', 'cod', 'checkedin', 'checkedout'];

    protected $fillable = [
       'xconfnum', 'cartid', 'siteid', 'customernumber', 'cid', 'cod', 'siteclass',  'total',
       'fname', 'lname', 'email', 'status', 'reason', 'nights', 'createdby', 'subtotal', 'taxrate', 'totaltax', 
       'base', 'sitelock', 'riglength', 'rigtype', 'checkedin', 'checkedout' ,'payment_id','group_confirmation_code',
       'totalcharges', 'receipt'
    ];

    protected $casts = [
        'cid' => 'date',
        'cod' => 'date',
    ];


    protected $guarded = [];



    public function rateTier()
    {
        return $this->belongsTo(RateTier::class);
    }


    public function user()
    {
        return $this->hasOne(User::class, 'id', 'customernumber');
    }

  

    public function payment()
    {
        return $this->hasOne(Payment::class, 'cartid', 'cartid');
    }

    public function cardOnFile()
    {
        return $this->hasOne(CardsOnFile::class, 'customernumber', 'customernumber');
    }

    public function payments()
    {
        return $this->hasMany(Payment::class, 'cartid', 'cartid');
    }

    public function refunds()
    {
        return $this->hasMany(Refund::class, 'reservations_id', 'id');
    }
    public function cart_reservation()
    {
        return $this->hasMany(CartReservation::class, 'cartid', 'cartid');
    }

    public function site()
    {
        return $this->hasOne(Site::class, 'siteid', 'siteid');
    }

    public function sites()
    {
        return $this->belongsTo(Site::class, 'siteid', 'siteid');
    }

    public function siteForSeasonal()
    {
        return $this->belongsTo(Site::class, 'siteid', 'siteid');
    }

    public function getAllPaginate()
    {
        return self::with(['user'])->orderBy('id', 'DESC')->paginate(10);
    }

    public function getAll()
    {
        return self::with(['user'])->orderBy('cid', 'DESC')->get();
    }

    public function getAllReservationsByReport($where = [], $filters = [])
    {
        return self::with(['user', 'site'])->where($where)
            ->when(count($filters) > 0, function ($query) use ($filters) {
                $query->when(isset($filters['date']) && !empty($filters['date']), function ($query) use ($filters) {
                    $filters['date'] = explode('-', $filters['date']);
                    $query->where('cid', '>', date('Y-m-d', strtotime($filters['date'][0])))->where('cod', '<', date('Y-m-d', strtotime($filters['date'][1])));

                    // $query->whereBetween(
                    //     'created_at',
                    //     [
                    //         date('Y-m-d', strtotime($filters['date'][0])),
                    //         date('Y-m-d', strtotime($filters['date'][1]))
                    //     ]
                    // );
                });
            });
    }

    public function findReservation($id)
    {
        return self::find($id);
    }

    public function storeReservation($data = [])
    {
        return self::create($data);
    }

    public function getWhereInIds($ids = [])
    {
        return self::whereIn('id', $ids)->get();
    }

    public function whereGet($where = [])
    {
        return self::where($where)->get();
    }

    public function getCustomerName()
    {
        return $this->first_name . ' ' . $this->last_name;
    }

    public function formattedTotal()
    {
        return number_format($this->total, 2);
    }

    public function receivedAmount()
    {
        return $this->payment->payment ?? 0;
    }

  
}
