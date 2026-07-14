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
     * List closing stores.
     * Staff can only view their own; admin/managers can view all stores.
     */
    public function index(Request $request)
    {
        $user = $request->user();
        $query = ClosingStore::with(['store', 'shiftStore', 'createdBy'])
            ->orderBy('date', 'desc')
            ->orderBy('created_at', 'desc');

        if ($user->hasRole('staff')) {
            $query->where('created_by_id', $user->id);
        }

        $list = $query->get();

        return response()->json([
            'success' => true,
            'data' => $list
        ]);
    }

    /**
     * Show details of a specific closing store.
     */
    public function show(Request $request, $id)
    {
        $user = $request->user();
        $closingStore = ClosingStore::with([
            'store',
            'shiftStore',
            'createdBy',
            'cashlesses.accountCashless.cashlessProvider',
            'cashlesses.accountCashless.storeCashless',
            'fuelServices',
            'dailySalaries.user',
            'invoicePurchases'
        ])->findOrFail($id);

        if ($user->hasRole('staff') && $closingStore->created_by_id !== $user->id) {
            return response()->json([
                'success' => false,
                'message' => 'Anda tidak memiliki akses ke laporan closing store ini.'
            ], 403);
        }

        $storeId = $closingStore->store_id;

        // Fetch unpaid/available transactions, including those already linked to this closing store
        $fuelServicesQuery = FuelService::where('payment_type_id', 2) // Cash
            ->where('store_id', $storeId)
            ->whereDate('date', '>=', Carbon::now()->subDays(10)->toDateString())
            ->where(function ($q) use ($closingStore) {
                $q->whereDoesntHave('closingStores')
                  ->orWhereHas('closingStores', function ($q2) use ($closingStore) {
                      $q2->where('closing_stores.id', $closingStore->id);
                  });
            });

        $dailySalariesQuery = DailySalary::where('payment_type_id', 2) // Cash
            ->where('store_id', $storeId)
            ->whereDate('date', '>=', Carbon::now()->subDays(15)->toDateString())
            ->where(function ($q) use ($closingStore) {
                $q->whereDoesntHave('closingStores')
                  ->orWhereHas('closingStores', function ($q2) use ($closingStore) {
                      $q2->where('closing_stores.id', $closingStore->id);
                  });
            });

        $invoicePurchasesQuery = InvoicePurchase::where('payment_type_id', 2) // Cash
            ->where('store_id', $storeId)
            ->whereDate('date', '>=', Carbon::now()->subDays(15)->toDateString())
            ->where(function ($q) use ($closingStore) {
                $q->whereDoesntHave('closingStores')
                  ->orWhereHas('closingStores', function ($q2) use ($closingStore) {
                      $q2->where('closing_stores.id', $closingStore->id);
                  });
            });

        if ($user->hasRole('staff')) {
            $fuelServicesQuery->where('created_by_id', $user->id);
            $dailySalariesQuery->where('created_by_id', $user->id);
            $invoicePurchasesQuery->where('created_by_id', $user->id);
        }

        $fuelServices = $fuelServicesQuery->orderBy('date', 'desc')->get();
        $dailySalaries = $dailySalariesQuery->orderBy('date', 'desc')->get();
        $invoicePurchases = $invoicePurchasesQuery->orderBy('date', 'desc')->get();

        return response()->json([
            'success' => true,
            'data' => [
                'closing_store' => $closingStore,
                'fuel_services' => $fuelServices,
                'daily_salaries' => $dailySalaries,
                'invoice_purchases' => $invoicePurchases
            ]
        ]);
    }

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
        $closingStoreQuery = ClosingStore::where('store_id', $storeId)
            ->where('shift_store_id', $shift->id)
            ->where('date', $today);
            
        if ($request->user()->hasRole('staff')) {
            $closingStoreQuery->where('created_by_id', $request->user()->id);
        }
        
        $closingStore = $closingStoreQuery->first();
            
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
        $fuelServicesQuery = FuelService::where('payment_type_id', 2) // Cash
            ->where('status', 1) // Unpaid
            ->where('store_id', $storeId)
            ->whereDate('date', '>=', Carbon::now()->subDays(10)->toDateString());
            
        // Unpaid daily salaries for this store
        $dailySalariesQuery = DailySalary::where('payment_type_id', 2) // Cash
            ->where('status', 1) // Unpaid
            ->where('store_id', $storeId)
            ->whereDate('date', '>=', Carbon::now()->subDays(15)->toDateString());
            
        // Unpaid invoice purchases for this store
        $invoicePurchasesQuery = InvoicePurchase::where('payment_type_id', 2) // Cash
            ->where('payment_status', '1') // Unpaid
            ->where('store_id', $storeId)
            ->whereDate('date', '>=', Carbon::now()->subDays(15)->toDateString());
            
        if ($request->user()->hasRole('staff')) {
            $fuelServicesQuery->where('created_by_id', $request->user()->id);
            $dailySalariesQuery->where('created_by_id', $request->user()->id);
            $invoicePurchasesQuery->where('created_by_id', $request->user()->id);
        }
        
        $fuelServices = $fuelServicesQuery->orderBy('date', 'desc')->get();
        $dailySalaries = $dailySalariesQuery->orderBy('date', 'desc')->get();
        $invoicePurchases = $invoicePurchasesQuery->orderBy('date', 'desc')->get();
            
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
        
        if ($request->user()->hasRole('staff') && $closingStore->status !== 1) {
            return response()->json([
                'success' => false,
                'message' => 'Laporan Closing Store sudah diperiksa oleh admin dan tidak dapat diedit lagi.'
            ], 403);
        }

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
     * Get list of fuel services for the active store of today's presence check-in.
     * Admin sees all, staff sees only their own store and their own records.
     */
    public function indexFuelServices(Request $request)
    {
        $user = $request->user();
        $today = Carbon::now()->toDateString();

        $query = FuelService::query()->with(['vehicle', 'supplier', 'createdBy']);

        if ($user->hasRole('staff')) {
            // Staff: filter by their store from presence and only their own records
            $presence = Presence::where('created_by_id', $user->id)
                ->whereDate('check_in', $today)
                ->first();

            if ($presence) {
                $query->where('store_id', $presence->store_id);
            }
            $query->where('created_by_id', $user->id);
        }
        // Admin/super_admin: no store or user filter, see all

        $fuelServices = $query->orderBy('date', 'desc')
            ->orderBy('created_at', 'desc')
            ->take(50)
            ->get();

        return response()->json([
            'success' => true,
            'data' => $fuelServices
        ]);
    }

    /**
     * Get fuel services for payment receipt (transfer type, unpaid, not linked to payment receipt)
     */
    public function fuelServicesForPayment(Request $request)
    {
        $user = $request->user();
        $query = FuelService::with(['vehicle', 'supplier', 'createdBy'])
            ->where('payment_type_id', 1) // Transfer
            ->where('status', 1) // Unpaid
            ->whereDoesntHave('paymentReceipts');

        // Staff sees only their own, admin sees all
        if ($user->hasRole('staff')) {
            $query->where('created_by_id', $user->id);
        }

        // Filter by user_id (admin only)
        if ($request->has('created_by_id') && $user->hasRole('admin')) {
            $query->where('created_by_id', $request->created_by_id);
        }

        $fuelServices = $query->orderBy('date', 'desc')->get();

        return response()->json([
            'success' => true,
            'data' => $fuelServices,
        ]);
    }

    /**
     * Create inline fuel service.
     */
    public function createFuelService(Request $request)
    {
        if (is_string($request->input('service_details'))) {
            $request->merge([
                'service_details' => json_decode($request->input('service_details'), true)
            ]);
        }

        $request->validate([
            'closing_store_id' => 'nullable|exists:closing_stores,id',
            'date' => 'required|date',
            'fuel_service' => 'required|in:1,2',
            'vehicle_id' => 'required|exists:vehicles,id',
            'supplier_id' => 'nullable|exists:suppliers,id',
            'payment_type_id' => 'nullable|exists:payment_types,id',
            'km' => 'nullable|numeric',
            'liter' => 'nullable|numeric',
            'amount' => 'required|numeric',
            'notes' => 'nullable|string',
            'service_details' => 'nullable|array',
            'image' => 'required|image|max:10240', // Max 10MB
        ]);
        
        $closingStoreId = $request->input('closing_store_id');
        $storeId = null;
        
        if ($closingStoreId) {
            $closingStore = ClosingStore::findOrFail($closingStoreId);
            $storeId = $closingStore->store_id;
        } else {
            $today = Carbon::now()->toDateString();
            $presence = Presence::where('created_by_id', $request->user()->id)
                ->whereDate('check_in', $today)
                ->first();
            if ($presence) {
                $storeId = $presence->store_id;
            } else {
                return response()->json([
                    'success' => false,
                    'message' => 'Anda harus melakukan check-in presensi terlebih dahulu.'
                ], 400);
            }
        }

        $imagePath = null;
        if ($request->hasFile('image')) {
            $imagePath = app(\App\Contracts\ImageStorageContract::class)->upload(
                $request->file('image'),
                'images/FuelService'
            );
        }
        
        $fuelService = FuelService::create([
            'store_id' => $storeId,
            'date' => $request->input('date'),
            'fuel_service' => $request->input('fuel_service'),
            'vehicle_id' => $request->input('vehicle_id'),
            'supplier_id' => $request->input('supplier_id'),
            'payment_type_id' => $request->input('payment_type_id', 2), // Cash/Tunai
            'km' => $request->input('km') ?? 0,
            'liter' => $request->input('liter') ?? 0,
            'amount' => $request->input('amount'),
            'notes' => $request->input('notes'),
            'status' => 1, // Pending/unpaid
            'created_by_id' => $request->user()->id,
            'service_details' => $request->input('service_details'),
            'image' => $imagePath,
        ]);
        
        // Automatically link it if closing_store_id was provided
        if ($closingStoreId) {
            $closingStore->fuelServices()->attach($fuelService->id);
        }
        
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
