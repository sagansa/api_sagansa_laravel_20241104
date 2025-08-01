<?php

namespace Tests\Unit;

use App\Models\User;
use App\Services\TokenManagementService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\PersonalAccessToken;
use Tests\TestCase;

class TokenManagementServiceTest extends TestCase
{
    use RefreshDatabase;

    protected TokenManagementService $tokenService;
    protected User $user;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tokenService = new TokenManagementService();
        $this->user = User::factory()->create();
    }

    public function test_can_generate_token_for_user(): void
    {
        $deviceInfo = ['device' => 'test-device', 'ip' => '127.0.0.1'];
        $token = $this->tokenService->generateToken($this->user, $deviceInfo);

        $this->assertIsString($token);
        $this->assertGreaterThan(40, strlen($token)); // Sanctum tokens are longer than 40 chars

        // Check if token was stored in database
        $this->assertDatabaseHas('personal_access_tokens', [
            'tokenable_id' => $this->user->id,
            'tokenable_type' => User::class,
        ]);
    }

    public function test_can_validate_valid_token(): void
    {
        $token = $this->tokenService->generateToken($this->user);
        $validatedUser = $this->tokenService->validateToken($token);

        $this->assertNotNull($validatedUser);
        $this->assertEquals($this->user->id, $validatedUser->id);
    }

    public function test_cannot_validate_invalid_token(): void
    {
        $invalidToken = 'invalid-token-string';
        $validatedUser = $this->tokenService->validateToken($invalidToken);

        $this->assertNull($validatedUser);
    }

    public function test_cannot_validate_inactive_token(): void
    {
        $token = $this->tokenService->generateToken($this->user);
        
        // Deactivate the token
        $this->tokenService->revokeToken($token);
        
        $validatedUser = $this->tokenService->validateToken($token);

        $this->assertNull($validatedUser);
    }

    public function test_can_revoke_specific_token(): void
    {
        $token = $this->tokenService->generateToken($this->user);
        
        $result = $this->tokenService->revokeToken($token);
        
        $this->assertTrue($result);
        $this->assertDatabaseMissing('personal_access_tokens', [
            'tokenable_id' => $this->user->id,
            'tokenable_type' => User::class,
        ]);
    }

    public function test_cannot_revoke_nonexistent_token(): void
    {
        $result = $this->tokenService->revokeToken('nonexistent-token');
        
        $this->assertFalse($result);
    }

    public function test_can_revoke_all_user_tokens(): void
    {
        // Generate multiple tokens
        $token1 = $this->tokenService->generateToken($this->user);
        $token2 = $this->tokenService->generateToken($this->user);
        $token3 = $this->tokenService->generateToken($this->user);

        $revokedCount = $this->tokenService->revokeAllUserTokens($this->user);

        $this->assertEquals(3, $revokedCount);
        
        // All tokens should be deleted
        $activeTokens = PersonalAccessToken::where('tokenable_id', $this->user->id)
            ->where('tokenable_type', User::class)
            ->count();
        
        $this->assertEquals(0, $activeTokens);
    }

    public function test_can_get_user_active_tokens(): void
    {
        // Generate multiple tokens
        $this->tokenService->generateToken($this->user);
        $this->tokenService->generateToken($this->user);
        
        // Generate and revoke one token
        $token3 = $this->tokenService->generateToken($this->user);
        $this->tokenService->revokeToken($token3);

        $activeTokens = $this->tokenService->getUserActiveTokens($this->user);

        $this->assertCount(2, $activeTokens);
        
        foreach ($activeTokens as $token) {
            $this->assertEquals($this->user->id, $token->tokenable_id);
            $this->assertEquals(User::class, $token->tokenable_type);
        }
    }

    public function test_token_validation_updates_last_used_timestamp(): void
    {
        $token = $this->tokenService->generateToken($this->user);
        
        // Get initial timestamp
        $personalAccessToken = PersonalAccessToken::where('tokenable_id', $this->user->id)->first();
        $initialTimestamp = $personalAccessToken->last_used_at;

        // Wait a moment and validate token
        sleep(1);
        $this->tokenService->validateToken($token);

        // Check if timestamp was updated
        $personalAccessToken->refresh();
        $this->assertGreaterThan($initialTimestamp, $personalAccessToken->last_used_at);
    }

    public function test_can_cleanup_old_tokens(): void
    {
        // Create some old tokens using Sanctum
        $oldDate = now()->subDays(35);
        $recentDate = now()->subDays(25);
        
        // Create old token that should be deleted
        $oldToken = $this->user->createToken('old-token')->accessToken;
        $oldToken->last_used_at = $oldDate;
        $oldToken->save();

        // Create recent token that should not be deleted
        $recentToken = $this->user->createToken('recent-token')->accessToken;
        $recentToken->last_used_at = $recentDate;
        $recentToken->save();

        $deletedCount = $this->tokenService->cleanupOldTokens(30);

        $this->assertEquals(1, $deletedCount);
    }
}
