<?php

namespace App\Http\Controllers\Api\Mobile;

use App\Http\Controllers\Controller;
use App\Models\DeviceToken;
use App\Models\User;
use App\Services\LoginSecurityService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Laravel\Socialite\Facades\Socialite;

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

    public function googleLogin(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'id_token' => ['required', 'string'],
            'device_name' => ['required', 'string', 'max:100'],
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Validasi gagal',
                'errors' => $validator->errors(),
            ], 422);
        }

        // Verify Google ID token
        $response = Http::get('https://oauth2.googleapis.com/tokeninfo', [
            'id_token' => $request->string('id_token'),
        ]);

        if (!$response->successful()) {
            return response()->json([
                'success' => false,
                'message' => 'Token Google tidak valid.',
            ], 401);
        }

        $googleData = $response->json();
        $clientId = config('services.google.client_id');

        // Verify audience matches our client ID
        if (($googleData['aud'] ?? '') !== $clientId) {
            return response()->json([
                'success' => false,
                'message' => 'Token Google tidak valid untuk aplikasi ini.',
            ], 401);
        }

        $googleId = $googleData['sub'] ?? null;
        $email = $googleData['email'] ?? null;
        $name = $googleData['name'] ?? $email;

        if (!$email) {
            return response()->json([
                'success' => false,
                'message' => 'Email tidak tersedia dari akun Google.',
            ], 422);
        }

        // Match by google_id first, then email, or create new
        $user = User::where('google_id', $googleId)->first();

        if (!$user) {
            $user = User::where('email', $email)->first();
            if ($user) {
                // Link Google ID to existing account
                $user->update(['google_id' => $googleId]);
            } else {
                // Create new user
                $user = User::create([
                    'name' => $name,
                    'email' => $email,
                    'google_id' => $googleId,
                    'password' => Hash::make(Str::random(32)),
                    'role' => 'umkm',
                    'onboarding_complete' => false,
                ]);
            }
        }

        // Check lock status
        $lockStatus = $this->security->checkLockStatus($user);
        if ($lockStatus['locked']) {
            return response()->json([
                'success' => false,
                'message' => 'Akun sedang terkunci sementara.',
            ], 423);
        }

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

    /**
     * Redirect to Google OAuth for mobile app (web-based flow).
     */
    public function googleRedirect(Request $request)
    {
        $deviceName = $request->query('device_name', 'Flutter Mobile');

        session(['mobile_google_device_name' => $deviceName]);

        return Socialite::driver('google')
            ->redirectUrl(url('/mobile/auth/google/callback'))
            ->redirect();
    }

    /**
     * Handle Google OAuth callback for mobile app.
     * Creates Sanctum token and redirects to talkabiz:// deep link.
     */
    public function googleCallback(Request $request)
    {
        try {
            $googleUser = Socialite::driver('google')
                ->redirectUrl(url('/mobile/auth/google/callback'))
                ->user();
        } catch (\Exception $e) {
            return $this->mobileAuthRedirect('error', 'Login Google gagal.');
        }

        $googleId = $googleUser->getId();
        $email = $googleUser->getEmail();
        $name = $googleUser->getName() ?? $email;

        if (!$email) {
            return $this->mobileAuthRedirect('error', 'Email tidak tersedia dari akun Google.');
        }

        $user = User::where('google_id', $googleId)->first();

        if (!$user) {
            $user = User::where('email', $email)->first();
            if ($user) {
                $user->update(['google_id' => $googleId]);
            } else {
                $user = User::create([
                    'name' => $name,
                    'email' => $email,
                    'google_id' => $googleId,
                    'password' => Hash::make(Str::random(32)),
                    'role' => 'umkm',
                    'onboarding_complete' => false,
                ]);
            }
        }

        $lockStatus = $this->security->checkLockStatus($user);
        if ($lockStatus['locked']) {
            return $this->mobileAuthRedirect('error', 'Akun sedang terkunci sementara.');
        }

        $this->security->recordSuccessfulLogin($user, $request->ip());

        $deviceName = session('mobile_google_device_name', 'Flutter Mobile');
        $token = $user->createToken($deviceName)->plainTextToken;

        session()->forget('mobile_google_device_name');

        return $this->mobileAuthRedirect('success', null, $token);
    }

    private function mobileAuthRedirect(string $status, ?string $error = null, ?string $token = null)
    {
        $params = ['status' => $status];
        if ($token) {
            $params['token'] = $token;
        }
        if ($error) {
            $params['error'] = $error;
        }

        $deepLink = 'talkabiz://auth/google/callback?' . http_build_query($params);

        return response()->make(
            '<html><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">'
            . '<title>Mengalihkan...</title></head><body style="display:flex;align-items:center;justify-content:center;height:100vh;font-family:sans-serif">'
            . '<div style="text-align:center"><p>Mengalihkan ke aplikasi...</p></div>'
            . '<script>window.location.replace(' . json_encode($deepLink) . ');</script>'
            . '</body></html>',
            200,
            ['Content-Type' => 'text/html']
        );
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
