<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\UtilityUsage;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class UtilityUsageController extends Controller
{
    public function index(Request $request)
    {
        $query = UtilityUsage::with([
            'utility.store:id,nickname',
            'utility.utilityProvider:id,name',
            'utility.unit:id,unit',
            'createdBy:id,name',
            'approvedBy:id,name',
        ]);

        if ($request->filled('store_id')) {
            $query->whereHas('utility', fn($q) => $q->where('store_id', $request->store_id));
        }

        if ($request->user()->hasRole('staff')) {
            $query->where('created_by_id', $request->user()->id);
        }

        if ($request->filled('category')) {
            $query->whereHas('utility', fn($q) => $q->where('category', $request->category));
        }

        if ($request->filled('utility_id')) {
            $query->where('utility_id', $request->utility_id);
        }

        if ($request->filled('status')) {
            $query->where('status', $request->status);
        }

        $usages = $query->orderBy('id', 'desc')->paginate(20);

        return response()->json([
            'success' => true,
            'data' => $usages->items(),
            'meta' => [
                'current_page' => $usages->currentPage(),
                'last_page' => $usages->lastPage(),
                'per_page' => $usages->perPage(),
                'total' => $usages->total(),
            ],
        ]);
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'utility_id' => 'required|exists:utilities,id',
            'result' => 'required|numeric',
            'notes' => 'nullable|string',
        ]);

        $usage = UtilityUsage::create([
            'utility_id' => $validated['utility_id'],
            'result' => $validated['result'],
            'notes' => $validated['notes'] ?? null,
            'created_by_id' => Auth::id(),
            'status' => UtilityUsage::STATUS_BELUM_DIPERIKSA,
        ]);

        $usage->load([
            'utility.store:id,nickname',
            'utility.utilityProvider:id,name',
            'utility.unit:id,unit',
            'createdBy:id,name',
            'approvedBy:id,name',
        ]);

        return response()->json([
            'success' => true,
            'data' => $usage,
        ], 201);
    }

    public function show(UtilityUsage $utilityUsage)
    {
        $utilityUsage->load([
            'utility.store:id,nickname',
            'utility.utilityProvider:id,name',
            'utility.unit:id,unit',
            'createdBy:id,name',
            'approvedBy:id,name',
        ]);

        return response()->json([
            'success' => true,
            'data' => $utilityUsage,
        ]);
    }

    public function update(Request $request, UtilityUsage $utilityUsage)
    {
        $validated = $request->validate([
            'utility_id' => 'required|exists:utilities,id',
            'result' => 'required|numeric',
            'notes' => 'nullable|string',
        ]);

        $utilityUsage->update([
            'utility_id' => $validated['utility_id'],
            'result' => $validated['result'],
            'notes' => $validated['notes'] ?? null,
        ]);

        $utilityUsage->load([
            'utility.store:id,nickname',
            'utility.utilityProvider:id,name',
            'utility.unit:id,unit',
            'createdBy:id,name',
            'approvedBy:id,name',
        ]);

        return response()->json([
            'success' => true,
            'data' => $utilityUsage,
        ]);
    }

    public function destroy(UtilityUsage $utilityUsage)
    {
        $utilityUsage->delete();

        return response()->json([
            'success' => true,
            'message' => 'Data berhasil dihapus.',
        ]);
    }

}
