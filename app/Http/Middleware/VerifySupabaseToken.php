<?php

namespace App\Http\Middleware;

use App\Models\User;
use Closure;
use Firebase\JWT\JWT;
use Firebase\JWT\Key;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;

class VerifySupabaseToken
{
    /**
     * Handle an incoming request.
     *
     * Verify JWT token dari Supabase Auth dan inject user ke request.
     * Supabase menggunakan ES256 (ECDSA) untuk signing JWT.
     */
    public function handle(Request $request, Closure $next): Response
    {
        // 1. Ambil Bearer token dari header Authorization
        $authHeader = $request->header('Authorization');

        if (!$authHeader || !str_starts_with($authHeader, 'Bearer ')) {
            return response()->json([
                'message' => 'Token tidak ditemukan. Silakan login terlebih dahulu.'
            ], 401);
        }

        $token = substr($authHeader, 7);

        try {
            // 2. Decode JWT header untuk dapat kid
            $tokenParts = explode('.', $token);
            if (count($tokenParts) !== 3) {
                throw new \Exception('Format token tidak valid');
            }

            // kid ada di HEADER (index 0), bukan payload (index 1)
            $headerJson = base64_decode(strtr($tokenParts[0], '-_', '+/'));
            $header = json_decode($headerJson);
            $kid = $header->kid ?? null;

            // 3. Dapatkan public key dari JWKS endpoint
            $publicKey = $this->getPublicKey($kid);

            if (!$publicKey) {
                throw new \Exception('Tidak dapat mengambil public key dari Supabase');
            }

            // 4. Verify JWT dengan ES256
            $decoded = JWT::decode($token, new Key($publicKey, 'ES256'));

            // 5. Ambil 'sub' claim sebagai user ID
            $userId = $decoded->sub ?? null;

            if (!$userId) {
                return response()->json([
                    'message' => 'Token tidak valid (missing user ID).'
                ], 401);
            }

            // 6. Verify audience
            if (isset($decoded->aud) && $decoded->aud !== 'authenticated') {
                return response()->json([
                    'message' => 'Token tidak valid (invalid audience).'
                ], 401);
            }

            // 6. Query user dari database
            $user = User::find($userId);

            // Skip user check untuk endpoint register (user belum ada di DB)
            $isRegisterEndpoint = $request->is('api/auth/register') && $request->isMethod('POST');

            if (!$user && !$isRegisterEndpoint) {
                return response()->json([
                    'message' => 'User tidak ditemukan.'
                ], 401);
            }

            // 7. Inject user ke request
            if ($user) {
                $request->setUserResolver(function () use ($user) {
                    return $user;
                });
                $request->merge(['_supabase_user' => $user]);
            }

        } catch (\Firebase\JWT\ExpiredException $e) {
            return response()->json([
                'message' => 'Token sudah kadaluarsa. Silakan login kembali.'
            ], 401);
        } catch (\Firebase\JWT\SignatureInvalidException $e) {
            return response()->json([
                'message' => 'Token tidak valid (signature mismatch).'
            ], 401);
        } catch (\Firebase\JWT\BeforeValidException $e) {
            return response()->json([
                'message' => 'Token belum berlaku.'
            ], 401);
        } catch (\Exception $e) {
            return response()->json([
                'message' => 'Token tidak valid: ' . $e->getMessage()
            ], 401);
        }

        return $next($request);
    }

    /**
     * Ambil public key dari Supabase JWKS endpoint
     */
    protected function getPublicKey(?string $kid): ?string
    {
        $cacheKey = 'supabase_jwks' . ($kid ? '_' . $kid : '');

        return Cache::remember($cacheKey, 3600, function () use ($kid) {
            try {
                $supabaseUrl = config('services.supabase.url');
                $supabaseKey = config('services.supabase.key');
                $jwksUrl = rtrim($supabaseUrl, '/') . '/auth/v1/.well-known/jwks.json';

                $response = Http::timeout(5)
                    ->connectTimeout(3)
                    ->withHeaders([
                        'apikey' => $supabaseKey,
                    ])
                    ->get($jwksUrl);

                if (!$response->successful()) {
                    Log::warning('JWKS fetch failed: HTTP ' . $response->status());
                    return null;
                }

                $jwks = $response->json();

                if (!$jwks || !isset($jwks['keys'])) {
                    Log::warning('JWKS response tidak valid');
                    return null;
                }

                // Cari key dengan kid yang cocok
                foreach ($jwks['keys'] as $key) {
                    if ($kid && isset($key['kid']) && $key['kid'] === $kid) {
                        return $this->buildPublicKey($key);
                    }
                }

                // Fallback: pakai key pertama
                if (!empty($jwks['keys'])) {
                    return $this->buildPublicKey($jwks['keys'][0]);
                }

            } catch (\Exception $e) {
                Log::warning('JWKS fetch failed: ' . $e->getMessage());
                return null;
            }

            return null;
        });
    }

    /**
     * Build public key dari JWK format (P-256/EC)
     */
    protected function buildPublicKey(array $jwk): ?string
    {
        if (!isset($jwk['kty']) || $jwk['kty'] !== 'EC') {
            return null;
        }

        if (!isset($jwk['crv']) || $jwk['crv'] !== 'P-256') {
            return null;
        }

        if (!isset($jwk['x']) || !isset($jwk['y'])) {
            return null;
        }

        $x = $this->base64UrlDecode($jwk['x']);
        $y = $this->base64UrlDecode($jwk['y']);

        return $this->buildECPublicKeyPem($x, $y);
    }

    /**
     * Build EC public key dalam PEM format
     */
    protected function buildECPublicKeyPem(string $x, string $y): string
    {
        // P-256: x dan y selalu 32 bytes
        $x = str_pad($x, 32, "\x00", STR_PAD_LEFT);
        $y = str_pad($y, 32, "\x00", STR_PAD_LEFT);

        $point = "\x04" . $x . $y; // 65 bytes (uncompressed)

        // Fixed DER structure untuk P-256
        $der = "\x30\x59"                               // SEQUENCE 89 bytes
             . "\x30\x13"                               // SEQUENCE 19 bytes (algorithm)
             . "\x06\x07\x2a\x86\x48\xce\x3d\x02\x01" // OID ecPublicKey
             . "\x06\x08\x2a\x86\x48\xce\x3d\x03\x01\x07" // OID prime256v1
             . "\x03\x42\x00"                           // BIT STRING 66 bytes, 0 unused bits
             . $point;                                  // 65 bytes

        $pem  = "-----BEGIN PUBLIC KEY-----\n";
        $pem .= chunk_split(base64_encode($der), 64, "\n");
        $pem .= "-----END PUBLIC KEY-----";

        return $pem;
    }

    /**
     * Base64 URL decode
     */
    protected function base64UrlDecode(string $input): string
    {
        $remainder = strlen($input) % 4;
        if ($remainder) {
            $input .= str_repeat('=', 4 - $remainder);
        }
        return base64_decode(strtr($input, '-_', '+/'));
    }
}
