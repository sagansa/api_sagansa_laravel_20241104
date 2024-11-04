<?php

namespace App\Http\Controllers\api;

use App\Http\Controllers\Controller;
use App\Models\SalaryRate;
use App\Models\Presence;
use App\Models\Salary;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Carbon\Carbon;
use App\Services\SalaryService;

class SalaryController extends Controller
{
    public function getMySalary(Request $request)
    {
        $request->validate([
            'start_date' => 'required|date',
            'end_date' => 'required|date|after_or_equal:start_date',
        ]);

        $user = Auth::user();
        $startDate = Carbon::parse($request->start_date)->startOfDay();
        $endDate = Carbon::parse($request->end_date)->endOfDay();

        try {
            // Hitung masa kerja
            $yearsOfService = Carbon::parse($user->join_date)->diffInYears(Carbon::now());

            // Ambil rate gaji yang berlaku
            $salaryRate = SalaryRate::where('effective_date', '<=', $endDate)
                ->latest('effective_date')
                ->first();

            if (!$salaryRate) {
                throw new \Exception('Rate gaji tidak ditemukan');
            }

            // Ambil rate per jam sesuai masa kerja
            $rateDetail = $salaryRate->salaryRateDetails()
                ->where('years_of_service', '<=', $yearsOfService)
                ->orderBy('years_of_service', 'desc')
                ->first();

            if (!$rateDetail) {
                throw new \Exception('Detail rate tidak ditemukan');
            }

            // Ambil semua presensi dalam periode
            $presences = Presence::where('created_by_id', $user->id)
                ->whereDate('check_in', '>=', $startDate)
                ->whereDate('check_in', '<=', $endDate)
                ->whereNotNull('check_out')
                ->get();

            // Hitung detail per hari
            $dailyDetails = $presences->map(function ($presence) {
                $checkIn = Carbon::parse($presence->check_in);
                $checkOut = Carbon::parse($presence->check_out);
                $hours = $checkOut->diffInHours($checkIn);

                return [
                    'date' => $checkIn->toDateString(),
                    'check_in' => $presence->check_in,
                    'check_out' => $presence->check_out,
                    'hours' => $hours,
                    'store' => $presence->store ? $presence->store->nickname : null,
                    'shift' => $presence->shiftStore ? $presence->shiftStore->name : null,
                ];
            });

            // Hitung total jam dan gaji
            $totalHours = $dailyDetails->sum('hours');
            $totalSalary = $totalHours * $rateDetail->rate_per_hour;

            return response()->json([
                'status' => 'success',
                'data' => [
                    'period' => [
                        'start_date' => $startDate->toDateString(),
                        'end_date' => $endDate->toDateString(),
                    ],
                    'employee' => [
                        'id' => $user->id,
                        'name' => $user->name,
                        'join_date' => $user->join_date,
                        'years_of_service' => $yearsOfService
                    ],
                    'salary_rate' => [
                        'name' => $salaryRate->name,
                        'effective_date' => $salaryRate->effective_date,
                        'rate_per_hour' => $rateDetail->rate_per_hour
                    ],
                    'calculation' => [
                        'total_hours' => $totalHours,
                        'rate_per_hour' => $rateDetail->rate_per_hour,
                        'total_salary' => $totalSalary
                    ],
                    'daily_details' => $dailyDetails
                ]
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'status' => 'error',
                'message' => $e->getMessage()
            ], 400);
        }
    }

    public function getCurrentRate()
    {
        $user = Auth::user();
        $yearsOfService = Carbon::parse($user->join_date)->diffInYears(Carbon::now());

        try {
            $currentRate = SalaryRate::where('effective_date', '<=', now())
                ->latest('effective_date')
                ->with(['salaryRateDetails' => function ($query) use ($yearsOfService) {
                    $query->where('years_of_service', '<=', $yearsOfService)
                        ->orderBy('years_of_service', 'desc');
                }])
                ->first();

            if (!$currentRate || $currentRate->salaryRateDetails->isEmpty()) {
                throw new \Exception('Rate gaji tidak ditemukan');
            }

            return response()->json([
                'status' => 'success',
                'data' => [
                    'employee' => [
                        'join_date' => $user->join_date,
                        'years_of_service' => $yearsOfService
                    ],
                    'rate' => [
                        'name' => $currentRate->name,
                        'effective_date' => $currentRate->effective_date,
                        'rate_per_hour' => $currentRate->salaryRateDetails->first()->rate_per_hour
                    ]
                ]
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'status' => 'error',
                'message' => $e->getMessage()
            ], 400);
        }
    }

    public function getMonthlySalaries(Request $request)
    {
        $request->validate([
            'year' => 'required|integer|min:2000|max:' . (date('Y') + 1),
            'month' => 'nullable|integer|min:1|max:12'
        ]);

        $user = Auth::user();
        $year = $request->year;
        $month = $request->month;

        try {
            // Jika bulan tidak diisi, ambil semua bulan dalam tahun tersebut
            if (!$month) {
                $months = collect(range(1, 12));
            } else {
                $months = collect([$month]);
            }

            $monthlySalaries = $months->map(function ($month) use ($user, $year) {
                $startDate = Carbon::create($year, $month, 1)->startOfMonth();
                $endDate = Carbon::create($year, $month, 1)->endOfMonth();

                // Skip bulan yang belum selesai
                if ($endDate->isFuture()) {
                    return null;
                }

                // Hitung masa kerja pada bulan tersebut
                $yearsOfService = Carbon::parse($user->join_date)->diffInYears($endDate);

                // Ambil rate gaji yang berlaku
                $salaryRate = SalaryRate::where('effective_date', '<=', $endDate)
                    ->latest('effective_date')
                    ->first();

                if (!$salaryRate) {
                    return null;
                }

                // Ambil rate per jam sesuai masa kerja
                $rateDetail = $salaryRate->salaryRateDetails()
                    ->where('years_of_service', '<=', $yearsOfService)
                    ->orderBy('years_of_service', 'desc')
                    ->first();

                if (!$rateDetail) {
                    return null;
                }

                // Ambil presensi bulan tersebut
                $presences = Presence::where('created_by_id', $user->id)
                    ->whereDate('check_in', '>=', $startDate)
                    ->whereDate('check_in', '<=', $endDate)
                    ->whereNotNull('check_out')
                    ->get();

                // Hitung total jam kerja
                $totalHours = $presences->sum(function ($presence) {
                    $checkIn = Carbon::parse($presence->check_in);
                    $checkOut = Carbon::parse($presence->check_out);
                    return $checkOut->diffInHours($checkIn);
                });

                // Hitung total hari kerja
                $totalWorkDays = $presences->count();

                // Hitung total gaji
                $totalSalary = $totalHours * $rateDetail->rate_per_hour;

                return [
                    'period' => [
                        'year' => $year,
                        'month' => $month,
                        'month_name' => Carbon::create($year, $month, 1)->format('F'),
                        'start_date' => $startDate->toDateString(),
                        'end_date' => $endDate->toDateString()
                    ],
                    'work_summary' => [
                        'total_days' => $totalWorkDays,
                        'total_hours' => $totalHours,
                        'rate_per_hour' => $rateDetail->rate_per_hour
                    ],
                    'salary_rate' => [
                        'name' => $salaryRate->name,
                        'effective_date' => $salaryRate->effective_date
                    ],
                    'calculation' => [
                        'base_salary' => $totalSalary,
                        'total_salary' => $totalSalary // Bisa ditambah komponen lain seperti bonus/potongan
                    ]
                ];
            })
                ->filter() // Hapus null values
                ->values(); // Reset array keys

            return response()->json([
                'status' => 'success',
                'data' => [
                    'employee' => [
                        'id' => $user->id,
                        'name' => $user->name,
                        'join_date' => $user->join_date
                    ],
                    'monthly_salaries' => $monthlySalaries
                ]
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'status' => 'error',
                'message' => $e->getMessage()
            ], 400);
        }
    }

    public function generateMonthlySalary(Request $request)
    {
        $request->validate([
            'year' => 'required|integer',
            'month' => 'required|integer|min:1|max:12'
        ]);

        try {
            $salary = app(SalaryService::class)->generateMonthlySalary(
                Auth::id(),
                $request->year,
                $request->month
            );

            return response()->json([
                'status' => 'success',
                'message' => 'Salary generated successfully',
                'data' => $salary
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'status' => 'error',
                'message' => $e->getMessage()
            ], 400);
        }
    }

    public function approveSalary($id)
    {
        $salary = Salary::findOrFail($id);

        if ($salary->status !== Salary::STATUS_DRAFT) {
            return response()->json([
                'status' => 'error',
                'message' => 'Only draft salaries can be approved'
            ], 400);
        }

        $salary->update([
            'status' => Salary::STATUS_APPROVED,
            'approved_by_id' => Auth::id(),
            'approved_at' => now()
        ]);

        return response()->json([
            'status' => 'success',
            'message' => 'Salary approved successfully',
            'data' => $salary
        ]);
    }

    public function markAsPaid(Request $request, $id)
    {
        $request->validate([
            'payment_method' => 'required|string',
            'payment_reference' => 'required|string',
            'notes' => 'nullable|string'
        ]);

        $salary = Salary::findOrFail($id);

        if ($salary->status !== Salary::STATUS_APPROVED) {
            return response()->json([
                'status' => 'error',
                'message' => 'Only approved salaries can be marked as paid'
            ], 400);
        }

        $salary->update([
            'status' => Salary::STATUS_PAID,
            'payment_method' => $request->payment_method,
            'payment_reference' => $request->payment_reference,
            'notes' => $request->notes,
            'paid_at' => now()
        ]);

        return response()->json([
            'status' => 'success',
            'message' => 'Salary marked as paid successfully',
            'data' => $salary
        ]);
    }
}
