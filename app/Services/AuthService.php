<?php

namespace App\Services;

use App\Models\External\SphereUser;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class AuthService
{
    protected $beSphereUrl;

    public function __construct()
    {
        $this->beSphereUrl = config('app.be_sphere_url', env('BE_SPHERE_URL', 'http://127.0.0.1:8000'));
    }

    /**
     * Validate JWT token with be-sphere server
     */
    public function validateToken(string $token): ?array
    {
        try {
            Log::info('Validating token with be-sphere', [
                'url' => $this->beSphereUrl . '/api/auth/verify-token',
                'token_preview' => substr($token, 0, 20) . '...'
            ]);

            $response = Http::withHeaders([
                'Authorization' => 'Bearer ' . $token,
                'Accept' => 'application/json',
            ])->timeout(10)->get($this->beSphereUrl . '/api/auth/verify-token');

            Log::info('Token validation response', [
                'status' => $response->status(),
                'successful' => $response->successful(),
                'body' => $response->body()
            ]);

            if ($response->successful()) {
                $data = $response->json();
                Log::info('Token validation successful', ['user_data' => $data]);
                return $data['data']['user'] ?? null;
            }

            Log::warning('Token validation failed', [
                'status' => $response->status(),
                'response' => $response->body()
            ]);

            return null;
        } catch (\Exception $e) {
            Log::error('Token validation error', [
                'error' => $e->getMessage(),
                'url' => $this->beSphereUrl . '/api/auth/verify-token'
            ]);
            return null;
        }
    }

    /**
     * Get user from Sphere database by ID
     */
    public function getUserFromSphere(int $userId): ?SphereUser
    {
        try {
            $user = SphereUser::active()->find($userId);
            
            if (!$user) {
                Log::warning('User not found or inactive', ['user_id' => $userId]);
                return null;
            }

            // Load user role and department
            $user->load(['role', 'department']);

            return $user;
        } catch (\Exception $e) {
            Log::error('Error fetching user from Sphere', [
                'user_id' => $userId,
                'error' => $e->getMessage()
            ]);
            return null;
        }
    }

    /**
     * Generate JWT token for user
     */
    public function generateToken(SphereUser $user): string
    {
        $payload = [
            'iss' => config('app.url'), // Issuer
            'aud' => config('app.url'), // Audience
            'iat' => time(), // Issued at
            'exp' => time() + (24 * 60 * 60), // Expires in 24 hours
            'sub' => $user->id, // Subject (user ID)
            'user_id' => $user->id,
            'username' => $user->username,
            'email' => $user->email,
            'role' => $user->role ? $user->role->slug : null,
            'department' => $user->department ? $user->department->code : null,
        ];

        return JWT::encode($payload, $this->jwtKey, $this->jwtAlgorithm);
    }

    /**
     * Refresh JWT token
     */
    public function refreshToken(string $token): ?string
    {
        $user = $this->validateToken($token);
        
        if (!$user) {
            return null;
        }

        return $this->generateToken($user);
    }

    /**
     * Get user from request
     */
    public function getUserFromRequest($request): ?SphereUser
    {
        return $request->get('auth_user');
    }

    /**
     * Check if user has specific role
     */
    public function userHasRole($request, string $role): bool
    {
        $user = $this->getUserFromRequest($request);
        return $user ? $user->hasRole($role) : false;
    }

    /**
     * Check if user has any of the specified roles
     */
    public function userHasAnyRole($request, array $roles): bool
    {
        $user = $this->getUserFromRequest($request);
        return $user ? $user->hasAnyRole($roles) : false;
    }
}
