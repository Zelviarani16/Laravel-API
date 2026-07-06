<?php

namespace App\Http\Controllers;

use App\Models\User;
use App\Models\PasswordReset;
use Firebase\JWT\JWT;
use Firebase\JWT\Key;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class AuthController extends Controller
{
    /**
     * REGISTER FULL - 1 langkah auto create di Supabase + Laravel
     *
     * Flutter flow:
     * POST /auth/register-full (email, password, name)
     * → User dibuat di BOTH auth.users (Supabase) + public.users (Laravel)
     */
    public function registerFull(Request $request)
    {
        $validated = $request->validate([
            'email'    => 'required|email',
            'password' => 'required|string|min:6',
            'name'     => 'required|string|max:255',
        ]);

        // Cek apakah email sudah ada di Laravel DB
        if (User::where('email', $validated['email'])->exists()) {
            return response()->json(['message' => 'Email sudah terdaftar'], 400);
        }

        // 1. Create user di Supabase Auth
        $supabaseUrl = config('services.supabase.url');
        $supabaseKey = config('services.supabase.key');

        $supabaseResponse = Http::withHeaders([
            'apikey' => $supabaseKey,
            'Content-Type' => 'application/json',
        ])->post($supabaseUrl . '/auth/v1/signup', [
            'email' => $validated['email'],
            'password' => $validated['password'],
        ]);

        if (!$supabaseResponse->successful()) {
            $error = $supabaseResponse->json();
            return response()->json([
                'message' => 'Gagal registrasi di Supabase: ' . ($error['msg'] ?? $error['message'] ?? 'Unknown error')
            ], 400);
        }

        // Ambil user_id dari Supabase
        $supabaseUser = $supabaseResponse->json();
        $userId = $supabaseUser['id'] ?? $supabaseUser['user']['id'] ?? null;

        if (!$userId) {
            return response()->json(['message' => 'Gagal mendapatkan ID user dari Supabase'], 400);
        }

        // 2. Insert ke Laravel DB
        $user = User::create([
            'id'       => $userId,
            'name'     => $validated['name'],
            'email'    => $validated['email'],
            'password' => bcrypt(bin2hex(random_bytes(16))),
            'role'     => 'user', // Default role untuk register mandiri
        ]);

        return response()->json([
            'message' => 'Registrasi berhasil! Silakan login.',
            'user'    => [
                'id'    => $user->id,
                'name'  => $user->name,
                'email' => $user->email,
                'role'  => $user->role,
            ]
        ], 201);
    }

    /**
     * REGISTER LAMA - Buat user di public.users setelah Supabase Auth signup
     *
     * Flutter flow:
     * 1. supabase.auth.signUp(email, password) → dapat JWT
     * 2. POST /auth/register dengan JWT sebagai Bearer token
     * 3. Laravel verify JWT, insert ke public.users
     */
    public function register(Request $request)
    {
        // Middleware 'supabase.auth' sudah verify JWT
        // Ambil user ID langsung dari token payload (bukan dari DB query)

        $token = $request->bearerToken();
        $tokenParts = explode('.', $token);
        $payload = json_decode(base64_decode(strtr($tokenParts[1], '-_', '+/')));
        $userId = $payload->sub ?? null;

        if (!$userId) {
            return response()->json([
                'message' => 'Token tidak valid (missing user ID).'
            ], 401);
        }

        // Validasi input
        $validated = $request->validate([
            'name'  => 'required|string|max:255',
            'role'  => 'in:user,helpdesk,admin'
        ]);

        // Cek apakah user sudah ada di public.users
        $user = User::find($userId);

        if ($user) {
            // User sudah ada, update name & role jika diperlukan
            $user->update([
                'name' => $validated['name'],
                'role' => $validated['role'] ?? 'user',
            ]);
            return response()->json([
                'message'   => 'Profil user berhasil diperbarui',
                'user'      => $user->fresh()
            ], 200);
        }

        // Buat user baru di public.users
        $user = User::create([
            'id'       => $userId, // UUID dari Supabase auth.users
            'name'     => $validated['name'],
            'email'    => $payload->email ?? null,
            'password' => bcrypt(bin2hex(random_bytes(16))), // Random password
            'role'     => $validated['role'] ?? 'user',
        ]);

        return response()->json([
            'message'   => 'Registrasi berhasil',
            'user'      => $user
        ], 201);
    }

    /**
     * FORGOT PASSWORD - Kirim reset code ke email
     *
     * Flutter flow:
     * 1. User input email
     * 2. Laravel generate code 6 digit + kirim email
     * 3. User buka Reset Password Page → input code + password baru
     */
    public function forgotPassword(Request $request)
    {
        $validated = $request->validate([
            'email' => 'required|email',
        ]);

        // Cek apakah email ada di database
        $user = User::where('email', $validated['email'])->first();
        if (!$user) {
            // Jangan reveal apakah email ada atau tidak (security)
            return response()->json([
                'message' => 'Jika email tersebut terdaftar, kode reset akan dikirim.'
            ]);
        }

        // Generate 6 digit code
        $code = str_pad(random_int(0, 999999), 6, '0', STR_PAD_LEFT);

        // Simpan ke password_resets table
        PasswordReset::updateOrCreate(
            ['email' => $validated['email']],
            [
                'email' => $validated['email'],
                'token' => $code,
                'expires_at' => now()->addMinutes(15),
            ]
        );

        // Kirim email (dengan SMTP config)
        $this->sendResetEmail($validated['email'], $code, $user->name);

        return response()->json([
            'message' => 'Jika email tersebut terdaftar, kode reset akan dikirim.'
        ]);
    }

    /**
     * VERIFY RESET CODE - Cek apakah code valid
     */
    public function verifyResetCode(Request $request)
    {
        $validated = $request->validate([
            'email' => 'required|email',
            'code' => 'required|string|size:6',
        ]);

        $reset = PasswordReset::where('email', $validated['email'])
            ->where('token', $validated['code'])
            ->where('expires_at', '>', now())
            ->first();

        if (!$reset) {
            return response()->json(['valid' => false], 200);
        }

        return response()->json(['valid' => true], 200);
    }

    /**
     * RESET PASSWORD - Update password dengan code
     */
    public function resetPassword(Request $request)
    {
        $validated = $request->validate([
            'email' => 'required|email',
            'code' => 'required|string|size:6',
            'password' => 'required|string|min:6|confirmed',
        ]);

        // Verifikasi code
        $reset = PasswordReset::where('email', $validated['email'])
            ->where('token', $validated['code'])
            ->where('expires_at', '>', now())
            ->first();

        if (!$reset) {
            return response()->json([
                'message' => 'Kode reset tidak valid atau sudah expired.'
            ], 400);
        }

        // Find user
        $user = User::where('email', $validated['email'])->first();
        if (!$user) {
            return response()->json([
                'message' => 'User tidak ditemukan.'
            ], 404);
        }

        // Update password di Laravel DB
        $user->update([
            'password' => bcrypt($validated['password'])
        ]);

        // Update password di Supabase menggunakan service_role_key
        $supabaseUrl = config('services.supabase.url');
        $serviceRoleKey = config('services.supabase.service_role_key');

        if ($serviceRoleKey && $serviceRoleKey !== 'placeholder_replace_with_real_service_role_key') {
            try {
                Http::withHeaders([
                    'apikey' => $serviceRoleKey,
                    'Authorization' => 'Bearer ' . $serviceRoleKey,
                    'Content-Type' => 'application/json',
                ])->put($supabaseUrl . '/auth/v1/admin/users/' . $user->id, [
                    'password' => $validated['password'],
                ]);
            } catch (\Exception $e) {
                // Log error tapi tetap return success karena Laravel DB sudah diupdate
                Log::error('Failed to update Supabase password: ' . $e->getMessage());
            }
        }

        // Hapus reset code
        PasswordReset::where('email', $validated['email'])->delete();

        return response()->json([
            'message' => 'Password berhasil direset. Silakan login dengan password baru.'
        ]);
    }

    /**
     * HELPERS - Send Reset Email
     */
    private function sendResetEmail($email, $code, $name)
    {
        $subject = 'Kode Reset Password - E-Ticketing Helpdesk';
        $body = "
        <h2>Halo, {$name}!</h2>
        <p>Anda meminta reset password untuk akun E-Ticketing Helpdesk.</p>
        <p><strong>Kode Reset Password Anda:</strong></p>
        <h1 style='font-size: 48px; letter-spacing: 10px; color: #4F46E5; text-align: center;'> {$code} </h1>
        <p>Kode ini akan expires dalam <strong>15 menit</strong>.</p>
        <p>Jika Anda tidak merasa meminta reset password, abaikan email ini.</p>
        <br>
        <p>Hormat kami,<br>E-Ticketing Helpdesk Team</p>
        ";

        try {
            Mail::html($body, function ($message) use ($email, $subject) {
                $message->to($email)
                    ->subject($subject);
            });
        } catch (\Exception $e) {
            // Log error but don't fail - kode tetap generated
            Log::error('Failed to send reset email: ' . $e->getMessage());
        }
    }

    /**
     * GET PROFILE - Ambil data user yang sedang login
     */
    public function profile(Request $request)
    {
        return response()->json([
            'user'  => $request->user()
        ]);
    }
}
