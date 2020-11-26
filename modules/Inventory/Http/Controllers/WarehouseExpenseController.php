<?php

namespace Modules\Inventory\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Modules\Inventory\Http\Resources\WarehouseExpenseResource;
use Modules\Inventory\Http\Resources\WarehouseExpenseCollection;
use Modules\Inventory\Models\WarehouseExpense;
use Modules\Inventory\Models\WarehouseExpenseItem;
use Modules\Inventory\Models\WarehouseExpenseReason;
use Modules\Purchase\Models\PurchaseOrder;
use Modules\Purchase\Models\PurchaseOrderItem;
use Modules\Inventory\Http\Requests\WarehouseExpenseRequest;
use App\Models\Tenant\Company;
use Modules\Transport\Models\WorkOrder;
use App\Models\Tenant\Catalogs\CurrencyType;
use App\CoreFacturalo\Requests\Inputs\Common\PersonInput;
use App\Models\Tenant\Person;
use App\Http\Controllers\Tenant\Api\ServiceController;
use Illuminate\Support\Str;
use App\Models\Tenant\Item;
use Modules\Inventory\Traits\{
    InventoryTrait,
    UtilityTrait,
};
use Barryvdh\DomPDF\Facade as PDF;
use Modules\Inventory\Models\Warehouse as ModuleWarehouse;
use Exception;
use Modules\Inventory\Models\WarehouseIncome;
use Modules\Inventory\Models\WarehouseIncomeItem;

class WarehouseExpenseController extends Controller
{

    use InventoryTrait, UtilityTrait;

    protected $warehouse_expense;


    public function index()
    {
        return view('inventory::warehouse-expense.index');
    }

    public function create()
    {
        return view('inventory::warehouse-expense.form');
    }

    public function columns()
    {
        return [
            'date_of_issue' => 'Fecha',
        ];
    }

    public function records(Request $request)
    {
        $records = WarehouseExpense::where($request->column, 'like', "%{$request->value}%")->latest();

        return new WarehouseExpenseCollection($records->paginate(config('tenant.items_per_page')));
    }


    public function record($id)
    {
        $record = WarehouseExpense::findOrFail($id);

        return new WarehouseExpenseResource($record);
    }


    public function tables()
    {
        return [
            'warehouses' => $this->optionsWarehouse(),
            //'purchase_orders' => $this->table('purchase_orders'),
            'warehouse_expense_reasons' => WarehouseExpenseReason::get(),
            'suppliers' => $this->table('suppliers'),
            'currency_types' => CurrencyType::whereActive()->get(),
            'work_orders' => WorkOrder::get(),
        ];
    }


    public function item_tables()
    {

        $items = $this->table('items');

        return compact('items');
    }


    public function getListPrice($item_id)
    {

        $item = WarehouseIncomeItem::where([['item_id', $item_id]])->latest('id')->first();
        $currentype = WarehouseIncome::where('id',$item->warehouse_income_id)->first();

        if($item){
            return [
                'list_price' => round($item->list_price, 2),
                'currentype'=>$currentype->currency_type_id,
            ];
        }

        return [
            'list_price' => 0
        ];
    }


    public function getExchangeRate($date_reference, $supplier_id)
    {

        $record = PurchaseOrder::where([['date_of_issue', $date_reference], ['supplier_id', $supplier_id]])->first();

        if($record){
            return [
                'success' => true,
                'message' => '',
                'exchange_rate_sale' => $record->exchange_rate_sale
            ];
        }

        $exchange_rate = app(ServiceController::class)->exchangeRateTest(date('Y-m-d'));

        return [
            'success' => false,
            'message' => 'No se encontró una O. Compra asociada al proveedor, se obtendra el T/C del día',
            'exchange_rate_sale' => (array_key_exists('sale', $exchange_rate)) ? $exchange_rate['sale'] : 1
        ];

    }


    public function getAdditionalValues($item_id)
    {

        $record = WarehouseExpenseItem::where('item_id', $item_id)
                                    ->whereHas('warehouse_income', function($q){
                                        $q->whereIn('warehouse_income_reason_id', ["103", "104"]);
                                    })
                                    ->latest('id')
                                    ->first();

        if($record){
            return [
                'last_purchase_price' => $record->list_price,
                'last_factor' => $record->sale_profit_factor,
            ];
        }

        return [
            'last_purchase_price' => 0,
            'last_factor' => 0,
        ];

    }


    public function table($table)
    {

        $data = [];

        switch ($table) {
            case 'purchase_orders':

                $data = PurchaseOrder::get()->transform(function($row) {
                                        return [
                                            'id' => $row->id,
                                            'number' => $row->id,
                                        ];
                                    });

                break;

            case 'suppliers':

                $data = Person::whereType('suppliers')->orderBy('name')->get()->transform(function($row) {
                                    return [
                                        'id' => $row->id,
                                        'description' => $row->number.' - '.$row->name,
                                        'name' => $row->name,
                                        'number' => $row->number,
                                        'email' => $row->email,
                                        'identity_document_type_id' => $row->identity_document_type_id,
                                    ];
                                });

                break;


            case 'items':

                $data = Item::orderBy('description')
                                ->whereNotIsSet()
                                ->whereIsActive()
                                ->get()
                                ->transform(function($row) {

                                    $full_description = ($row->internal_id)?$row->internal_id.' - '.$row->description:$row->description;
                                        return [
                                            'id' => $row->id,
                                            'full_description' => $full_description,
                                            'description' => $row->description,
                                            'currency_type_id' => $row->currency_type_id,
                                            'currency_type_symbol' => $row->currency_type->symbol,
                                            'sale_unit_price' => $row->sale_unit_price,
                                            'purchase_unit_price' => $row->purchase_unit_price,
                                            'unit_type_id' => $row->unit_type_id,
                                            'category_id' => $row->category_id,
                                            'family_id' => $row->family_id,
                                    ];

                                });

                break;
        }

        return $data;

    }


    public function store(WarehouseExpenseRequest $request)
    {

        // dd($request->all());
        $record = DB::connection('tenant')->transaction(function () use ($request) {

            $data = $this->mergeData($request);
            $this->warehouse_expense = WarehouseExpense::create($data);

            foreach ($data['items'] as $row) {
                $this->warehouse_expense->items()->create($row);
            }

            $this->setFilename($this->warehouse_expense);
            $this->createPdf($this->warehouse_expense, "a4", 'warehouse_expense');

            return  [
                'success' => true,
                'message' => 'Registro creado con éxito'
            ];

        });

        return $record;

    }


    public function mergeData($inputs)
    {

        $company = Company::active();

        $values = [
            'user_id' => auth()->id(),
            'soap_type_id' => $company->soap_type_id,
            'supplier' => PersonInput::set($inputs['supplier_id']),
            'external_id' => Str::uuid()->toString(),
            'number' =>  $this->newNumber(WarehouseExpense::class),
        ];

        $inputs->merge($values);

        return $inputs->all();
    }

    /*public function download($external_id, $template)
    {
       // $establishment_id = auth()->user()->establishment_id;
       // $warehouse = ModuleWarehouse::where('establishment_id', $establishment_id)->first();
        $company = Company::first();

        $record = WarehouseExpense::where('external_id', $external_id)->first();
        $view = "inventory::warehouse-income.report.{$template}";

        set_time_limit(0);

        $pdf = PDF::loadView($view, compact("record", "company"));
        $filename = "Reporte_{$template}";

        return $pdf->download($filename.'.pdf');
    }*/

    public function download($id) {
        $company = Company::first();

        $record = WarehouseExpense::find($id);
        if (!$record) throw new Exception("El indentificador {$id} es inválido, no se encontro el registro.");

        $view = "inventory::warehouse-expense.report.format";

        set_time_limit(0);

        $pdf = PDF::loadView($view, compact("record", "company"));
        $filename = "Reporte_Salida";

        return $pdf->download($filename.'.pdf');

        /*$row = WarehouseExpense::find($id);
        if (!$row) throw new Exception("El indentificador {$id} es inválido, no se encontro el registro.");
        return $this->downloadStorage($row->filename, 'warehouse_expense');*/
    }


}
