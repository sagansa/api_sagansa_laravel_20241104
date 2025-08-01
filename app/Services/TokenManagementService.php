<?php

namespace App\Services;

use App\Models\User;
use Laravel\Sanctum\PersonalAccessToken;

class TokenManagementService
{
    /**
     * Generate a new persistent token for user
     */
    public function generateToken(User $user, array $deviceInfo = []): string
    {
        // Create token name with device info
        $tokenName = 'persistent-token';
        if (isset($deviceInfo['device_name'])) {
            $tokenName = $deviceInfo['device_name'];
        }

        // Create Sanctum token without expiration
        $token = $user->createToken($tokenName, ['*'], null);

        // Store device info in the token's name or abilities (we'll use name for simplicity)
        if (!empty($deviceInfo)) {
            $personalAccessToken = $token->accessToken;
            $personalAccessToken->name = $tokenName . ' - ' . json_encode($deviceInfo);
            $personalAccessToken->save();
        }

        return $token->plainTextToken;
    }

    /**
     * Validate a persistent token
     */
    public function validateToken(string $token): ?User
    {
        // Find the token in personal_access_tokens
        $personalAccessToken = PersonalAccessToken::findToken($token);

        if (!$personalAccessToken) {
            return null;
        }

        // Update last used timestamp
        $personalAccessToken->forceFill(['last_used_at' => now()])->save();

        return $personalAccessToken->tokenable;
    }

    /**
     * Revoke a specific token
     */
    public function revokeToken(string $token): bool
    {
        $personalAccessToken = PersonalAccessToken::findToken($token);

        if (!$personalAccessToken) {
            return false;
        }

        $personalAccessToken->delete();
        return true;
    }

    /**
     * Revoke all tokens for a user
     */
    public function revokeAllUserTokens(User $user): int
    {
        return $user->tokens()->delete();
    }

    /**
     * Clean up old tokens (optional cleanup method)
     */
    public function cleanupOldTokens(int $daysOld = 30): int
    {
        return PersonalAccessToken::where('last_used_at', '<', now()->subDays($daysOld))
            ->delete();
    }

    /**
     * Get all active tokens for a user
     */
    public function getUserActiveTokens(User $user): \Illuminate\Database\Eloquent\Collection
    {
        return $user->tokens()
            ->orderBy('last_used_at', 'desc')
            ->get();
    }
}