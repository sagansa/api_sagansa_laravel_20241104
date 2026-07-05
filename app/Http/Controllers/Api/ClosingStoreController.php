<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\ClosingStore;
use App\Models\Cashless;
use App\Models\FuelService;
use App\Models\DailySalary;
use App\Models\InvoicePurchase;
use App\Models\Vehicle;
use App\Models\Supplier;
use App\Models\Presence;
use App\Models\ShiftStore;
use Illuminate\Http\Request;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

class ClosingStoreController extends Controller
{
    /**
     * Get today's active draft or create it.
     */
    public function activeDraft(Request $request)
    {
        $today = Carbon::now()->toDateString();
        
        // Find user's store from today's presence check-in
        $presence = Presence::where('created_by_id', $request->user()->id)
            ->whereDate('check_in', $today)
            ->first();
            
        if (!$presence) {
            return response()->json([
                'success' => false,
                'message' => 'Anda harus melakukan check-in presensi terlebih dahulu.'
            ], 400);
        }
        
        $storeId = $presence->store_id;
        $shift = ShiftStore::first();
        if (!$shift) {
            return response()->json([
                'success' => false,
                'message' => 'Shift toko belum dikonfigurasi.'
            ], 400);
        }
        
        // Find or create closing store draft
        $closingStore = ClosingStore::where('store_id', $storeId)
            ->where('shift_store_id', $shift->id)
            ->where('date', $today)
            ->first();
            
        if (!$closingStore) {
            $cashFromYesterday = ClosingStore::where('store_id', $storeId)
                ->latest('created_at')
                ->first()?->cash_for_tomorrow ?? 0;
                
            $closingStore = ClosingStore::create([
                'store_id' => $storeId,
                'shift_store_id' => $shift->id,
                'date' => $today,
                'cash_from_yesterday' => $cashFromYesterday,
                'cash_for_tomorrow' => 0,
                'total_cash_transfer' => 0,
                'status' => 1, // belum diperiksa
                'created_by_id' => $request->user()->id,
            ]);
        }
        
        // Pre-populate cashlesses if any
        $accounts = \App\Models\AccountCashless::where('store_id', $storeId)->get();
        foreach ($accounts as $account) {
            $closingStore->cashlesses()->firstOrCreate(
                ['account_cashless_id' => $account->id],
                ['bruto_apl' => 0]
            );
        }
        
        $closingStore->load([
            'store',
            'shiftStore',
            'cashlesses.accountCashless.cashlessProvider',
            'cashlesses.accountCashless.storeCashless',
            'fuelServices',
            'dailySalaries',
            'invoicePurchases'
        ]);
        
        return response()->json([
            'success' => true,
            'data' => $closingStore
        ]);
    }

    /**
     * Get list of unpaid cash transactions for this store.
     */
    public function unpaidTransactions(Request $request)
    {
        $today = Carbon::now()->toDateString();
        $presence = Presence::where('created_by_id', $request->user()->id)
            ->whereDate('check_in', $today)
            ->first();
            
        if (!$presence) {
            return response()->json([
                'success' => false,
                'message' => 'Anda harus melakukan check-in presensi terlebih dahulu.'
            ], 400);
        }
        
        $storeId = $presence->store_id;
        
        // Unpaid fuel services for this store
        $fuelServices = FuelService::where('payment_type_id', 2) // Cash
            ->where('status', 1) // Unpaid
            ->where('store_id', $storeId)
            ->whereDate('date', '>=', Carbon::now()->subDays(10)->toDateString())
            ->orderBy('date', 'desc')
            ->get();
            
        // Unpaid daily salaries for this store
        $dailySalaries = DailySalary::where('payment_type_id', 2) // Cash
            ->where('status', 1) // Unpaid
            ->where('store_id', $storeId)
            ->whereDate('date', '>=', Carbon::now()->subDays(15)->toDateString())
            ->orderBy('date', 'desc')
            ->get();
            
        // Unpaid invoice purchases for this store
        $invoicePurchases = InvoicePurchase::where('payment_type_id', 2) // Cash
            ->where('status', 1) // Unpaid
            ->where('store_id', $storeId)
            ->whereDate('date', '>=', Carbon::now()->subDays(15)->toDateString())
            ->orderBy('date', 'desc')
            ->get();
            
        return response()->json([
            'success' => true,
            'data' => [
                'fuel_services' => $fuelServices,
                'daily_salaries' => $dailySalaries,
                'invoice_purchases' => $invoicePurchases
            ]
        ]);
    }

    /**
     * Save/update closing store values.
     */
    public function save(Request $request)
    {
        $request->validate([
            'id' => 'required|exists:closing_stores,id',
            'cash_for_tomorrow' => 'required|numeric',
            'total_cash_transfer' => 'required|numeric',
            'notes' => 'nullable|string',
            'cashlesses' => 'nullable|array',
            'fuel_service_ids' => 'nullable|array',
            'daily_salary_ids' => 'nullable|array',
            'invoice_purchase_ids' => 'nullable|array',
        ]);
        
        $closingStore = ClosingStore::findOrFail($request->input('id'));
        
        $closingStore->update([
            'cash_for_tomorrow' => $request->input('cash_for_tomorrow'),
            'total_cash_transfer' => $request->input('total_cash_transfer'),
            'notes' => $request->input('notes'),
        ]);
        
        // Save cashlesses
        if ($request->has('cashlesses')) {
            foreach ($request->input('cashlesses') as $c) {
                Cashless::where('id', $c['id'])->update([
                    'bruto_apl' => $c['bruto_apl']
                ]);
            }
        }
        
        // Sync relationships
        $closingStore->fuelServices()->sync($request->input('fuel_service_ids', []));
        $closingStore->dailySalaries()->sync($request->input('daily_salary_ids', []));
        $closingStore->invoicePurchases()->sync($request->input('invoice_purchase_ids', []));
        
        return response()->json([
            'success' => true,
            'message' => 'Laporan Closing Store berhasil disimpan.'
        ]);
    }

    /**
     * Create inline fuel service.
     */
    public function createFuelService(Request $request)
    {
        $request->validate([
            'closing_store_id' => 'required|exists:closing_stores,id',
            'date' => 'required|date',
            'fuel_service' => 'required|in:1,2',
            'vehicle_id' => 'required|exists:vehicles,id',
            'supplier_id' => 'nullable|exists:suppliers,id',
            'km' => 'nullable|numeric',
            'liter' => 'nullable|numeric',
            'amount' => 'required|numeric',
            'notes' => 'nullable|string',
            'service_details' => 'nullable|array',
        ]);
        
        $closingStore = ClosingStore::findOrFail($request->input('closing_store_id'));
        
        $fuelService = FuelService::create([
            'store_id' => $closingStore->store_id,
            'date' => $request->input('date'),
            'fuel_service' => $request->input('fuel_service'),
            'vehicle_id' => $request->input('vehicle_id'),
            'supplier_id' => $request->input('supplier_id'),
            'payment_type_id' => 2, // Cash/Tunai
            'km' => $request->input('km') ?? 0,
            'liter' => $request->input('liter') ?? 0,
            'amount' => $request->input('amount'),
            'notes' => $request->input('notes'),
            'status' => 1, // Pending/unpaid
            'created_by_id' => $request->user()->id,
            'service_details' => $request->input('service_details'),
        ]);
        
        // Automatically link it
        $closingStore->fuelServices()->attach($fuelService->id);
        
        return response()->json([
            'success' => true,
            'data' => $fuelService
        ]);
    }

    /**
     * Get active vehicles.
     */
    public function vehicles()
    {
        $vehicles = Vehicle::where('status', 1)->get();
        return response()->json([
            'success' => true,
            'data' => $vehicles
        ]);
    }

    /**
     * Get suppliers.
     */
    public function suppliers()
    {
        $suppliers = Supplier::where('status', 1)->get();
        return response()->json([
            'success' => true,
            'data' => $suppliers
        ]);
    }
}
