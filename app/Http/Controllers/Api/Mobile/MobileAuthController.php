<?php

namespace App\Http\Controllers\Api\Mobile;

use App\Http\Controllers\Controller;
use App\Models\DeviceToken;
use App\Models\User;
use App\Services\LoginSecurityService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Validator;

class MobileAuthController extends Controller
{
    public function __construct(private readonly LoginSecurityService $security)
    {
    }

    public function login(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'email' => ['required', 'email'],
            'password' => ['required', 'string'],
            'device_name' => ['required', 'string', 'max:100'],
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Validasi gagal',
                'errors' => $validator->errors(),
            ], 422);
        }

        $ipKey = 'mobile_login_ip:' . $request->ip();
        $rateLimit = config('auth_security.rate_limit_per_minute', 10);

        if (RateLimiter::tooManyAttempts($ipKey, $rateLimit)) {
            $seconds = RateLimiter::availableIn($ipKey);

            return response()->json([
                'success' => false,
                'message' => "Terlalu banyak request dari IP Anda. Coba lagi dalam {$seconds} detik.",
            ], 429);
        }

        RateLimiter::hit($ipKey, config('auth_security.rate_limit_decay_seconds', 60));

        /** @var User|null $user */
        $user = User::where('email', $request->string('email'))->first();

        if (!$user) {
            return response()->json([
                'success' => false,
                'message' => 'Email atau password salah.',
            ], 401);
        }

        $lockStatus = $this->security->checkLockStatus($user);
        if ($lockStatus['locked']) {
            return response()->json([
                'success' => false,
                'message' => 'Akun sedang terkunci sementara.',
                'data' => [
                    'locked_until' => $lockStatus['locked_until'],
                    'seconds_remaining' => $lockStatus['seconds_remaining'],
                ],
            ], 423);
        }

        if (!Hash::check($request->string('password'), $user->password)) {
            $result = $this->security->recordFailedAttempt($user, $request->ip());

            return response()->json([
                'success' => false,
                'message' => 'Email atau password salah.',
                'data' => [
                    'failed_attempts' => $result['attempts'],
                    'show_captcha' => $result['show_captcha'],
                    'locked_until' => $result['locked_until'],
                    'seconds_remaining' => $result['seconds_remaining'],
                ],
            ], $result['locked'] ? 423 : 401);
        }

        RateLimiter::clear($ipKey);
        $this->security->recordSuccessfulLogin($user, $request->ip());

        $token = $user->createToken($request->string('device_name'))->plainTextToken;

        return response()->json([
            'success' => true,
            'message' => 'Login berhasil',
            'data' => [
                'token' => $token,
                'token_type' => 'Bearer',
                'user' => $this->transformUser($user->fresh(['klien'])),
            ],
        ]);
    }

    public function me(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        return response()->json([
            'success' => true,
            'message' => 'OK',
            'data' => $this->transformUser($user->loadMissing('klien')),
        ]);
    }

    public function logout(Request $request): JsonResponse
    {
        $request->user()?->currentAccessToken()?->delete();

        return response()->json([
            'success' => true,
            'message' => 'Logout berhasil',
        ]);
    }

    private function transformUser(User $user): array
    {
        return [
            'id' => $user->id,
            'name' => $user->name,
            'email' => $user->email,
            'role' => $user->role ?? 'user',
            'klien_id' => $user->klien_id,
            'business_name' => $user->klien?->nama_perusahaan,
            'phone' => $user->phone ? (string) $user->phone : null,
            'onboarding_complete' => (bool) $user->onboarding_complete,
        ];
    }

    public function storeDeviceToken(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'token' => ['required', 'string', 'max:500'],
            'platform' => ['required', 'string', 'in:ios,android'],
        ]);

        if ($validator->fails()) {
            return response()->json(['success' => false, 'message' => 'Validasi gagal', 'errors' => $validator->errors()], 422);
        }

        DeviceToken::updateOrCreate(
            ['user_id' => $request->user()->id, 'token' => $request->string('token')],
            ['platform' => $request->string('platform')],
        );

        return response()->json(['success' => true, 'message' => 'Device token stored']);
    }
}
