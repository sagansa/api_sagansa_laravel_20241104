<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Laravel\Sanctum\PersonalAccessToken;
use Tests\TestCase;

class PersistentTokenAuthTest extends TestCase
{
    use RefreshDatabase;

    protected User $user;

    protected function setUp(): void
    {
        parent::setUp();
        $this->user = User::factory()->create([
            'email' => 'test@example.com',
            'password' => Hash::make('password123'),
        ]);
    }

    public function test_user_can_login_and_receive_persistent_token(): void
    {
        $response = $this->postJson('/login', [
            'email' => 'test@example.com',
            'password' => 'password123',
            'remember_me' => true,
            'device_name' => 'Test Device',
        ]);

        $response->assertStatus(200)
            ->assertJsonStructure([
                'success',
                'data' => [
                    'access_token',
                    'token_type',
                    'user' => [
                        'id',
                        'name',
                        'email',
                        'roles',
                        'company',
                    ],
                ],
                'message',
            ]);

        // Check if persistent token was created
        $this->assertDatabaseHas('personal_access_tokens', [
            'tokenable_id' => $this->user->id,
            'tokenable_type' => User::class,
        ]);
    }

    public function test_user_cannot_login_with_invalid_credentials(): void
    {
        $response = $this->postJson('/login', [
            'email' => 'test@example.com',
            'password' => 'wrongpassword',
        ]);

        $response->assertStatus(422)
            ->assertJson([
                'success' => false,
                'message' => 'Invalid email or password',
            ]);
    }

    public function test_can_validate_persistent_token(): void
    {
        // Login to get token
        $loginResponse = $this->postJson('/login', [
            'email' => 'test@example.com',
            'password' => 'password123',
        ]);

        $token = $loginResponse->json('data.access_token');

        // Validate token
        $response = $this->postJson('/validate-token', [
            'token' => $token,
        ]);

        $response->assertStatus(200)
            ->assertJson([
                'success' => true,
                'data' => [
                    'token_valid' => true,
                ],
            ]);
    }

    public function test_cannot_validate_invalid_token(): void
    {
        $response = $this->postJson('/validate-token', [
            'token' => 'invalid-token',
        ]);

        $response->assertStatus(401)
            ->assertJson([
                'success' => false,
                'message' => 'Invalid or expired token',
            ]);
    }

    public function test_can_access_protected_route_with_persistent_token(): void
    {
        // Login to get token
        $loginResponse = $this->postJson('/login', [
            'email' => 'test@example.com',
            'password' => 'password123',
        ]);

        $token = $loginResponse->json('data.access_token');

        // Access protected route
        $response = $this->getJson('/persistent/me', [
            'Authorization' => 'Bearer ' . $token,
        ]);

        $response->assertStatus(200)
            ->assertJsonStructure([
                'success',
                'data' => [
                    'id',
                    'name',
                    'email',
                    'roles',
                    'permissions',
                    'company',
                ],
            ]);
    }

    public function test_cannot_access_protected_route_without_token(): void
    {
        $response = $this->getJson('/persistent/me');

        $response->assertStatus(401)
            ->assertJson([
                'message' => 'Unauthenticated.',
            ]);
    }

    public function test_cannot_access_protected_route_with_invalid_token(): void
    {
        $response = $this->getJson('/persistent/me', [
            'Authorization' => 'Bearer invalid-token',
        ]);

        $response->assertStatus(401)
            ->assertJson([
                'message' => 'Unauthenticated.',
            ]);
    }

    public function test_user_can_logout_and_token_is_revoked(): void
    {
        // Create token using Sanctum
        $token = $this->user->createToken('test-device')->plainTextToken;

        // Logout using the token
        $response = $this->postJson('/persistent/logout', [], [
            'Authorization' => 'Bearer ' . $token,
        ]);

        $response->assertStatus(200)
            ->assertJson([
                'success' => true,
                'message' => 'Logout successful',
            ]);

        // Check that token was deleted from database
        $tokenExists = PersonalAccessToken::findToken($token);
        $this->assertNull($tokenExists, 'Token should be deleted after logout');
    }

    public function test_user_can_revoke_all_tokens(): void
    {
        // Create multiple tokens using Sanctum
        for ($i = 1; $i <= 3; $i++) {
            $this->user->createToken('test-device-' . $i);
        }

        // Login to get a token for authentication
        $loginResponse = $this->postJson('/login', [
            'email' => 'test@example.com',
            'password' => 'password123',
        ]);

        $token = $loginResponse->json('data.access_token');

        // Revoke all tokens
        $response = $this->postJson('/persistent/revoke-all-tokens', [], [
            'Authorization' => 'Bearer ' . $token,
        ]);

        $response->assertStatus(200)
            ->assertJsonStructure([
                'success',
                'data' => [
                    'revoked_tokens',
                ],
            ]);

        // All tokens should be deleted
        $activeTokens = PersonalAccessToken::where('tokenable_id', $this->user->id)
            ->where('tokenable_type', User::class)
            ->count();

        $this->assertEquals(0, $activeTokens);
    }
}
