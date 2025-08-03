<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\PresenceValidation;
use App\Models\Presence;
use App\Models\User;
use App\Models\FaceEncoding;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Carbon\Carbon;

class AdminDashboardController extends Controller
{
    /**
     * Get face recognition accuracy statistics.
     */
    public function getFaceRecognitionStats(Request $request)
    {
        $request->validate([
            'start_date' => 'nullable|date',
            'end_date' => 'nullable|date|after_or_equal:start_date',
            'user_id' => 'nullable|exists:users,id',
        ]);

        $startDate = $request->input('start_date', Carbon::now()->subDays(30)->toDateString());
        $endDate = $request->input('end_date', Carbon::now()->toDateString());
        $userId = $request->input('user_id');

        $query = PresenceValidation::query()
            ->whereNotNull('face_confidence')
            ->whereBetween('created_at', [$startDate, $endDate]);

        if ($userId) {
            $query->whereHas('presence', function ($q) use ($userId) {
                $q->where('created_by_id', $userId);
            });
        }

        $validations = $query->get();

        // Calculate statistics
        $totalAttempts = $validations->count();
        $successfulAttempts = $validations->where('validation_status', PresenceValidation::STATUS_PASSED)->count();
        $failedAttempts = $validations->where('validation_status', PresenceValidation::STATUS_FAILED)->count();
        $retryRequiredAttempts = $validations->where('validation_status', PresenceValidation::STATUS_RETRY_REQUIRED)->count();

        $averageConfidence = $validations->avg('face_confidence');
        $highConfidenceAttempts = $validations->where('face_confidence', '>=', PresenceValidation::FACE_CONFIDENCE_HIGH)->count();
        $mediumConfidenceAttempts = $validations->whereBetween('face_confidence', [PresenceValidation::FACE_CONFIDENCE_MEDIUM, PresenceValidation::FACE_CONFIDENCE_HIGH])->count();
        $lowConfidenceAttempts = $validations->where('face_confidence', '<', PresenceValidation::FACE_CONFIDENCE_MEDIUM)->count();

        // Confidence distribution
        $confidenceDistribution = [
            'high' => $highConfidenceAttempts,
            'medium' => $mediumConfidenceAttempts,
            'low' => $lowConfidenceAttempts,
        ];

        // Daily statistics
        $dailyStats = $validations->groupBy(function ($item) {
            return $item->created_at->format('Y-m-d');
        })->map(function ($dayValidations) {
            return [
                'total_attempts' => $dayValidations->count(),
                'successful_attempts' => $dayValidations->where('validation_status', PresenceValidation::STATUS_PASSED)->count(),
                'failed_attempts' => $dayValidations->where('validation_status', PresenceValidation::STATUS_FAILED)->count(),
                'average_confidence' => $dayValidations->avg('face_confidence'),
            ];
        });

        return response()->json([
            'status' => 'success',
            'data' => [
                'period' => [
                    'start_date' => $startDate,
                    'end_date' => $endDate,
                ],
                'overall_stats' => [
                    'total_attempts' => $totalAttempts,
                    'successful_attempts' => $successfulAttempts,
                    'failed_attempts' => $failedAttempts,
                    'retry_required_attempts' => $retryRequiredAttempts,
                    'success_rate' => $totalAttempts > 0 ? round(($successfulAttempts / $totalAttempts) * 100, 2) : 0,
                    'average_confidence' => round($averageConfidence, 4),
                ],
                'confidence_distribution' => $confidenceDistribution,
                'daily_stats' => $dailyStats,
            ],
        ]);
    }

    /**
     * Get users with face recognition issues.
     */
    public function getUsersWithFaceIssues(Request $request)
    {
        $request->validate([
            'days' => 'nullable|integer|min:1|max:90',
            'min_failures' => 'nullable|integer|min:1',
        ]);

        $days = $request->input('days', 7);
        $minFailures = $request->input('min_failures', 3);
        $startDate = Carbon::now()->subDays($days);

        $usersWithIssues = User::select('users.id', 'users.name', 'users.email')
            ->join('presences', 'users.id', '=', 'presences.created_by_id')
            ->join('presence_validations', 'presences.id', '=', 'presence_validations.presence_id')
            ->where('presence_validations.created_at', '>=', $startDate)
            ->where('presence_validations.validation_status', PresenceValidation::STATUS_FAILED)
            ->whereNotNull('presence_validations.face_confidence')
            ->groupBy('users.id', 'users.name', 'users.email')
            ->havingRaw('COUNT(*) >= ?', [$minFailures])
            ->withCount([
                'presences as total_failed_attempts' => function ($query) use ($startDate) {
                    $query->join('presence_validations', 'presences.id', '=', 'presence_validations.presence_id')
                        ->where('presence_validations.created_at', '>=', $startDate)
                        ->where('presence_validations.validation_status', PresenceValidation::STATUS_FAILED)
                        ->whereNotNull('presence_validations.face_confidence');
                }
            ])
            ->get();

        // Get detailed stats for each user
        $usersWithDetails = $usersWithIssues->map(function ($user) use ($startDate) {
            $validations = PresenceValidation::whereHas('presence', function ($q) use ($user) {
                $q->where('created_by_id', $user->id);
            })
                ->where('created_at', '>=', $startDate)
                ->whereNotNull('face_confidence')
                ->get();

            $totalAttempts = $validations->count();
            $failedAttempts = $validations->where('validation_status', PresenceValidation::STATUS_FAILED)->count();
            $averageConfidence = $validations->avg('face_confidence');

            return [
                'id' => $user->id,
                'name' => $user->name,
                'email' => $user->email,
                'total_attempts' => $totalAttempts,
                'failed_attempts' => $failedAttempts,
                'success_rate' => $totalAttempts > 0 ? round((($totalAttempts - $failedAttempts) / $totalAttempts) * 100, 2) : 0,
                'average_confidence' => round($averageConfidence, 4),
                'last_failed_at' => $validations->where('validation_status', PresenceValidation::STATUS_FAILED)->max('created_at'),
            ];
        });

        return response()->json([
            'status' => 'success',
            'data' => [
                'period_days' => $days,
                'min_failures_threshold' => $minFailures,
                'users_count' => $usersWithDetails->count(),
                'users' => $usersWithDetails,
            ],
        ]);
    }

    /**
     * Get security flags summary.
     */
    public function getSecurityFlagsSummary(Request $request)
    {
        $request->validate([
            'days' => 'nullable|integer|min:1|max:90',
        ]);

        $days = $request->input('days', 30);
        $startDate = Carbon::now()->subDays($days);

        $validations = PresenceValidation::where('created_at', '>=', $startDate)
            ->whereNotNull('security_flags')
            ->get();

        // Count security flags
        $flagCounts = [];
        foreach ($validations as $validation) {
            $flags = $validation->security_flags ?? [];
            foreach ($flags as $flag) {
                $flagCounts[$flag] = ($flagCounts[$flag] ?? 0) + 1;
            }
        }

        // Sort by count descending
        arsort($flagCounts);

        return response()->json([
            'status' => 'success',
            'data' => [
                'period_days' => $days,
                'total_validations_with_flags' => $validations->count(),
                'security_flags' => $flagCounts,
            ],
        ]);
    }

    /**
     * Get face registration statistics.
     */
    public function getFaceRegistrationStats()
    {
        $totalUsers = User::count();
        $usersWithFace = FaceEncoding::where('is_active', true)->distinct('user_id')->count();
        $usersWithoutFace = $totalUsers - $usersWithFace;

        $recentRegistrations = FaceEncoding::where('is_active', true)
            ->where('registered_at', '>=', Carbon::now()->subDays(30))
            ->count();

        // Get average success rate for users with face registration
        $avgSuccessRate = 0;
        if ($usersWithFace > 0) {
            $successRates = FaceEncoding::where('is_active', true)
                ->with(['user.presences.validation'])
                ->get()
                ->map(function ($encoding) {
                    $validations = $encoding->user->presences()
                        ->with('validation')
                        ->whereHas('validation', function ($q) {
                            $q->whereNotNull('face_confidence');
                        })
                        ->get()
                        ->pluck('validation')
                        ->filter();

                    if ($validations->count() === 0) {
                        return 0;
                    }

                    $successful = $validations->where('validation_status', PresenceValidation::STATUS_PASSED)->count();
                    return ($successful / $validations->count()) * 100;
                });

            $avgSuccessRate = $successRates->avg();
        }

        return response()->json([
            'status' => 'success',
            'data' => [
                'total_users' => $totalUsers,
                'users_with_face' => $usersWithFace,
                'users_without_face' => $usersWithoutFace,
                'face_registration_rate' => $totalUsers > 0 ? round(($usersWithFace / $totalUsers) * 100, 2) : 0,
                'recent_registrations_30_days' => $recentRegistrations,
                'average_success_rate' => round($avgSuccessRate, 2),
            ],
        ]);
    }

    /**
     * Get detailed face recognition accuracy metrics.
     */
    public function getFaceAccuracyMetrics(Request $request)
    {
        $request->validate([
            'start_date' => 'nullable|date',
            'end_date' => 'nullable|date|after_or_equal:start_date',
        ]);

        $startDate = $request->input('start_date', Carbon::now()->subDays(30)->toDateString());
        $endDate = $request->input('end_date', Carbon::now()->toDateString());

        $validations = PresenceValidation::whereNotNull('face_confidence')
            ->whereBetween('created_at', [$startDate, $endDate])
            ->get();

        // Calculate accuracy metrics
        $totalAttempts = $validations->count();
        $highConfidenceAttempts = $validations->where('face_confidence', '>=', PresenceValidation::FACE_CONFIDENCE_HIGH)->count();
        $mediumConfidenceAttempts = $validations->whereBetween('face_confidence', [PresenceValidation::FACE_CONFIDENCE_MEDIUM, PresenceValidation::FACE_CONFIDENCE_HIGH])->count();
        $lowConfidenceAttempts = $validations->whereBetween('face_confidence', [PresenceValidation::FACE_CONFIDENCE_LOW, PresenceValidation::FACE_CONFIDENCE_MEDIUM])->count();
        $veryLowConfidenceAttempts = $validations->where('face_confidence', '<', PresenceValidation::FACE_CONFIDENCE_LOW)->count();

        // Calculate retry statistics
        $attemptsWithRetries = $validations->where('retry_count', '>', 0)->count();
        $maxRetriesExceeded = $validations->where('retry_count', '>=', PresenceValidation::MAX_RETRY_ATTEMPTS)->count();
        $avgRetryCount = $validations->avg('retry_count');

        // Calculate validation requiring admin review
        $requiresReview = $validations->filter(function ($validation) {
            return $validation->requiresAdminReview();
        })->count();

        // Time-based analysis
        $hourlyStats = $validations->groupBy(function ($item) {
            return $item->created_at->format('H');
        })->map(function ($hourValidations) {
            return [
                'total_attempts' => $hourValidations->count(),
                'avg_confidence' => round($hourValidations->avg('face_confidence'), 4),
                'success_rate' => $hourValidations->count() > 0 
                    ? round(($hourValidations->where('validation_status', PresenceValidation::STATUS_PASSED)->count() / $hourValidations->count()) * 100, 2)
                    : 0,
            ];
        });

        return response()->json([
            'status' => 'success',
            'data' => [
                'period' => [
                    'start_date' => $startDate,
                    'end_date' => $endDate,
                ],
                'accuracy_metrics' => [
                    'total_attempts' => $totalAttempts,
                    'high_confidence_rate' => $totalAttempts > 0 ? round(($highConfidenceAttempts / $totalAttempts) * 100, 2) : 0,
                    'medium_confidence_rate' => $totalAttempts > 0 ? round(($mediumConfidenceAttempts / $totalAttempts) * 100, 2) : 0,
                    'low_confidence_rate' => $totalAttempts > 0 ? round(($lowConfidenceAttempts / $totalAttempts) * 100, 2) : 0,
                    'very_low_confidence_rate' => $totalAttempts > 0 ? round(($veryLowConfidenceAttempts / $totalAttempts) * 100, 2) : 0,
                ],
                'retry_metrics' => [
                    'attempts_with_retries' => $attemptsWithRetries,
                    'retry_rate' => $totalAttempts > 0 ? round(($attemptsWithRetries / $totalAttempts) * 100, 2) : 0,
                    'max_retries_exceeded' => $maxRetriesExceeded,
                    'average_retry_count' => round($avgRetryCount, 2),
                ],
                'admin_review' => [
                    'requires_review_count' => $requiresReview,
                    'review_rate' => $totalAttempts > 0 ? round(($requiresReview / $totalAttempts) * 100, 2) : 0,
                ],
                'hourly_stats' => $hourlyStats,
            ],
        ]);
    }
}
