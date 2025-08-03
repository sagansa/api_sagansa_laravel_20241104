<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PresenceValidation extends Model
{
    protected $fillable = [
        'presence_id',
        'face_confidence',
        'gps_accuracy',
        'location_source',
        'validation_status',
        'security_flags',
        'retry_count',
        'validated_at',
    ];

    protected $casts = [
        'face_confidence' => 'decimal:4',
        'gps_accuracy' => 'decimal:2',
        'security_flags' => 'array',
        'retry_count' => 'integer',
        'validated_at' => 'datetime',
    ];

    // Validation status constants
    const STATUS_PENDING = 'pending';
    const STATUS_PASSED = 'passed';
    const STATUS_FAILED = 'failed';
    const STATUS_RETRY_REQUIRED = 'retry_required';

    // Face confidence thresholds
    const FACE_CONFIDENCE_HIGH = 0.8;
    const FACE_CONFIDENCE_MEDIUM = 0.6;
    const FACE_CONFIDENCE_LOW = 0.4;
    const FACE_CONFIDENCE_MINIMUM = 0.3;

    // Maximum retry attempts
    const MAX_RETRY_ATTEMPTS = 3;

    /**
     * Get the presence that owns this validation.
     */
    public function presence(): BelongsTo
    {
        return $this->belongsTo(Presence::class);
    }

    /**
     * Check if face confidence meets the minimum threshold.
     */
    public function hasSufficientFaceConfidence(float $threshold = self::FACE_CONFIDENCE_MEDIUM): bool
    {
        return $this->face_confidence !== null && $this->face_confidence >= $threshold;
    }

    /**
     * Check if validation can be retried.
     */
    public function canRetry(): bool
    {
        return $this->retry_count < self::MAX_RETRY_ATTEMPTS;
    }

    /**
     * Increment retry count.
     */
    public function incrementRetryCount(): void
    {
        $this->increment('retry_count');
    }

    /**
     * Mark validation as passed.
     */
    public function markAsPassed(): void
    {
        $this->update([
            'validation_status' => self::STATUS_PASSED,
            'validated_at' => now(),
        ]);
    }

    /**
     * Mark validation as failed.
     */
    public function markAsFailed(array $securityFlags = []): void
    {
        $this->update([
            'validation_status' => self::STATUS_FAILED,
            'security_flags' => array_merge($this->security_flags ?? [], $securityFlags),
            'validated_at' => now(),
        ]);
    }

    /**
     * Mark validation as requiring retry.
     */
    public function markAsRetryRequired(array $securityFlags = []): void
    {
        $this->update([
            'validation_status' => self::STATUS_RETRY_REQUIRED,
            'security_flags' => array_merge($this->security_flags ?? [], $securityFlags),
        ]);
    }

    /**
     * Add security flag.
     */
    public function addSecurityFlag(string $flag): void
    {
        $flags = $this->security_flags ?? [];
        if (!in_array($flag, $flags)) {
            $flags[] = $flag;
            $this->update(['security_flags' => $flags]);
        }
    }

    /**
     * Get face confidence level description.
     */
    public function getFaceConfidenceLevelAttribute(): string
    {
        if ($this->face_confidence === null) {
            return 'unknown';
        }

        if ($this->face_confidence >= self::FACE_CONFIDENCE_HIGH) {
            return 'high';
        } elseif ($this->face_confidence >= self::FACE_CONFIDENCE_MEDIUM) {
            return 'medium';
        } elseif ($this->face_confidence >= self::FACE_CONFIDENCE_LOW) {
            return 'low';
        } elseif ($this->face_confidence >= self::FACE_CONFIDENCE_MINIMUM) {
            return 'very_low';
        }

        return 'insufficient';
    }

    /**
     * Check if face confidence meets minimum security threshold.
     */
    public function meetsMinimumSecurityThreshold(): bool
    {
        return $this->face_confidence !== null && $this->face_confidence >= self::FACE_CONFIDENCE_MINIMUM;
    }

    /**
     * Get confidence score as percentage.
     */
    public function getConfidencePercentage(): float
    {
        return $this->face_confidence ? round($this->face_confidence * 100, 2) : 0.0;
    }

    /**
     * Determine if validation requires admin review based on confidence and flags.
     */
    public function requiresAdminReview(): bool
    {
        // Require review if confidence is very low
        if ($this->face_confidence !== null && $this->face_confidence < self::FACE_CONFIDENCE_LOW) {
            return true;
        }

        // Require review if there are critical security flags
        $criticalFlags = [
            'max_retry_attempts_exceeded',
            'suspicious_activity',
            'multiple_failed_attempts',
            'fake_gps_detected'
        ];

        $securityFlags = $this->security_flags ?? [];
        return !empty(array_intersect($criticalFlags, $securityFlags));
    }
}
