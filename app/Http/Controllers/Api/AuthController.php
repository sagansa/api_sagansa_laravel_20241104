<?php

namespace App\Http\Controllers\api;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\TokenManagementService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Auth;

class AuthController extends Controller
{
    protected TokenManagementService $tokenService;

    public function __construct(TokenManagementService $tokenService)
    {
        $this->tokenService = $tokenService;
    }

    public function login(Request $request)
    {
        $request->validate([
            'email' => 'required|string|email',
            'password' => 'required',
            'remember_me' => 'boolean'
        ]);

        $user = User::with('company')->where('email', $request->email)->first();
        if (!$user || ! Hash::check($request->password, $user->password)) {
            return response()->json([
                'success' => false,
                'data' => null,
                'message' => 'Invalid email or password'
            ], 422);
        }

        // Collect device information
        $deviceInfo = [
            'user_agent' => $request->userAgent(),
            'ip_address' => $request->ip(),
            'device_name' => $request->input('device_name', 'Unknown Device'),
        ];

        // Generate persistent token using Sanctum
        $persistentToken = $this->tokenService->generateToken($user, $deviceInfo);

        $userData = [
            'id' => $user->id,
            'name' => $user->name,
            'email' => $user->email,
            'roles' => $user->roles->pluck('name'),
            'company' => $user->company ? [
                'id' => $user->company->id,
                'name' => $user->company->name,
            ] : null,
        ];

        return response()->json([
            'success' => true,
            'data' => [
                'access_token' => $persistentToken,
                'token_type' => 'Bearer',
                'user' => $userData
            ],
            'message' => 'Login successful'
        ]);
    }

    public function logout(Request $request)
    {
        // Revoke current Sanctum token
        if ($request->user() && $request->user()->currentAccessToken()) {
            $request->user()->currentAccessToken()->delete();
        }

        return response()->json([
            'success' => true,
            'data' => null,
            'message' => 'Logout successful'
        ]);
    }

    public function validateToken(Request $request)
    {
        $request->validate([
            'token' => 'required|string'
        ]);

        $user = $this->tokenService->validateToken($request->token);

        if (!$user) {
            return response()->json([
                'success' => false,
                'message' => 'Invalid or expired token'
            ], 401);
        }

        $userData = [
            'id' => $user->id,
            'name' => $user->name,
            'email' => $user->email,
            'roles' => $user->roles->pluck('name'),
            'company' => $user->company ? [
                'id' => $user->company->id,
                'name' => $user->company->name,
            ] : null,
        ];

        return response()->json([
            'success' => true,
            'data' => [
                'user' => $userData,
                'token_valid' => true
            ],
            'message' => 'Token is valid'
        ]);
    }

    public function me(Request $request)
    {
        $user = $request->user()->load('company');

        return response()->json([
            'success' => true,
            'data' => [
                'id' => $user->id,
                'name' => $user->name,
                'email' => $user->email,
                'roles' => $user->roles->pluck('name'),
                'permissions' => $user->getAllPermissions()->pluck('name'),
                'company' => $user->company ? [
                    'id' => $user->company->id,
                    'name' => $user->company->name,
                ] : null,
            ],
            'message' => 'User data retrieved successfully'
        ]);
    }

    public function revokeAllTokens(Request $request)
    {
        $user = $request->user();
        $revokedCount = $this->tokenService->revokeAllUserTokens($user);

        // Tokens are already revoked by tokenService->revokeAllUserTokens

        return response()->json([
            'success' => true,
            'data' => [
                'revoked_tokens' => $revokedCount
            ],
            'message' => 'All tokens revoked successfully'
        ]);
    }
}
