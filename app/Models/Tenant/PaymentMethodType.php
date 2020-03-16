<?php

namespace App\Models\Tenant;

use App\Models\Tenant\{
    DocumentPayment,
    SaleNotePayment,
    PurchasePayment
};

class PaymentMethodType extends ModelTenant
{
    public $incrementing = false;
    public $timestamps = false;

    protected $fillable = [
        'description',
        'has_card',
        'charge',
        'number_days',
    ];


    public function document_payments()
    {
        return $this->hasMany(DocumentPayment::class,  'payment_method_type_id');
    }
    
    public function sale_note_payments()
    {
        return $this->hasMany(SaleNotePayment::class,  'payment_method_type_id');
    }
    
    public function purchase_payments()
    {
        return $this->hasMany(PurchasePayment::class,  'payment_method_type_id');
    }

    public function scopeWhereFilterPayments($query, $params)
    {

        return $query->with(['document_payments' => function($q) use($params){
                    $q->whereBetween('date_of_payment', [$params->date_start, $params->date_end])
                        ->whereHas('associated_record_payment', function($p){
                            $p->whereStateTypeAccepted();
                        });
                },
                'sale_note_payments' => function($q) use($params){
                    $q->whereBetween('date_of_payment', [$params->date_start, $params->date_end])
                        ->whereHas('associated_record_payment', function($p){
                            $p->whereStateTypeAccepted()
                                ->whereNotChanged();
                        });
                },
                'purchase_payments' => function($q) use($params){
                    $q->whereBetween('date_of_payment', [$params->date_start, $params->date_end])
                        ->whereHas('associated_record_payment', function($p){
                            $p->whereStateTypeAccepted();
                        });
                }
                ]);

    }
}