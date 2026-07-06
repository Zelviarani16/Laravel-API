<?php
namespace App\Http\Controllers;

use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Http;

class UserController extends Controller
{
    /**
     * Ambil semua user
     * GET /api/users
     */
    public function index()
    {
        $users = User::select('id', 'name', 'email', 'role', 'avatar')->get();

        return response()->json(['users' => $users]);
    }

    /**
     * Ambil satu user
     * GET /api/users/{id}
     */
    public function show(string $id)
    {
        $user = User::select('id', 'name', 'email', 'role', 'avatar')->find($id);

        if (!$user) {
            return response()->json(['message' => 'User tidak ditemukan'], 404);
        }

        return response()->json(['user' => $user]);
    }

    /**
     * Buat user baru
     * POST /api/users
     */
    public function store(Request $request)
    {
        $validated = $request->validate([
            'name'     => 'required|string|max:255',
            'email'    => 'required|email|unique:users,email',
            'role'     => 'required|in:admin,helpdesk,user',
        ]);

        $user = User::create([
            'id'       => (string) \Illuminate\Support\Str::uuid(), // Generate UUID
            'name'     => $validated['name'],
            'email'    => $validated['email'],
            'password' => bcrypt(bin2hex(random_bytes(16))), // Random password - tidak dipakai
            'role'     => $validated['role'],
        ]);

        return response()->json([
            'message' => 'User berhasil dibuat',
            'user'    => [
                'id'      => $user->id,
                'name'    => $user->name,
                'email'   => $user->email,
                'role'    => $user->role,
                'avatar'  => $user->avatar,
            ]
        ], 201);
    }

    /**
     * Update user
     * PUT /api/users/{id}
     */
    public function update(Request $request, string $id)
    {
        $user = User::find($id);

        if (!$user) {
            return response()->json(['message' => 'User tidak ditemukan'], 404);
        }

        $validated = $request->validate([
            'name'  => 'sometimes|string|max:255',
            'email'  => 'sometimes|email|unique:users,email,' . $id,
        ]);

        if (isset($validated['name'])) {
            $user->name = $validated['name'];
        }

        if (isset($validated['email'])) {
            $user->email = $validated['email'];
        }

        $user->save();

        return response()->json([
            'message' => 'User berhasil diupdate',
            'user'    => [
                'id'     => $user->id,
                'name'   => $user->name,
                'email'  => $user->email,
                'role'   => $user->role,
                'avatar' => $user->avatar,
            ]
        ]);
    }

    /**
     * Hapus user
     * DELETE /api/users/{id}
     */
    public function destroy(string $id)
    {
        $user = User::find($id);

        if (!$user) {
            return response()->json(['message' => 'User tidak ditemukan'], 404);
        }

        // Cegah admin menghapus dirinya sendiri
        $currentUser = Auth::user();
        if ($currentUser && $currentUser->id === $user->id) {
            return response()->json(['message' => 'Tidak dapat menghapus akun sendiri'], 403);
        }

        $user->delete();

        return response()->json(['message' => 'User berhasil dihapus']);
    }

    /**
     * Update role user
     * PATCH /api/users/{id}/role
     */
    public function updateRole(Request $request, string $id)
    {
        $user = User::find($id);

        if (!$user) {
            return response()->json(['message' => 'User tidak ditemukan'], 404);
        }

        // Cegah admin mengubah role dirinya sendiri
        $currentUser = Auth::user();
        if ($currentUser && $currentUser->id === $user->id) {
            return response()->json(['message' => 'Tidak dapat mengubah role diri sendiri'], 403);
        }

        $validated = $request->validate([
            'role' => 'required|in:admin,helpdesk,user',
        ]);

        $user->role = $validated['role'];
        $user->save();

        return response()->json([
            'message' => 'Role user berhasil diupdate',
            'user'    => [
                'id'     => $user->id,
                'name'   => $user->name,
                'email'  => $user->email,
                'role'   => $user->role,
                'avatar' => $user->avatar,
            ]
        ]);
    }

    /**
     * Get helpdesk users (tanpa proteksi admin - boleh diakses user lain)
     */
    public function getHelpdesk()
    {
        $helpdesks = User::where('role', 'helpdesk')
            ->select('id', 'name', 'email')
            ->get();

        return response()->json(['helpdesks' => $helpdesks]);
    }

    /**
     * Create user dengan auth di Supabase + insert ke Laravel DB
     * POST /api/users/create-with-auth
     */
    public function createWithAuth(Request $request)
    {
        $validated = $request->validate([
            'name'     => 'required|string|max:255',
            'email'    => 'required|email',
            'password' => 'required|string|min:6',
            'role'     => 'required|in:admin,helpdesk,user',
        ]);

        // Cek apakah email sudah ada di Laravel DB
        if (User::where('email', $validated['email'])->exists()) {
            return response()->json(['message' => 'Email sudah terdaftar di sistem'], 400);
        }

        // Create user di Supabase Auth (pakai anon key)
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
                'message' => 'Gagal membuat user di Supabase: ' . ($error['msg'] ?? $error['message'] ?? 'Unknown error')
            ], 400);
        }

        // Ambil user_id dari Supabase response
        $supabaseUser = $supabaseResponse->json();
        $userId = $supabaseUser['id'] ?? $supabaseUser['user']['id'] ?? null;

        if (!$userId) {
            return response()->json(['message' => 'Gagal mendapatkan ID user dari Supabase'], 400);
        }

        // Insert ke Laravel DB
        $user = User::create([
            'id'       => $userId,
            'name'     => $validated['name'],
            'email'    => $validated['email'],
            'password' => bcrypt(bin2hex(random_bytes(16))),
            'role'     => $validated['role'],
        ]);

        return response()->json([
            'message' => 'User berhasil dibuat dan bisa login langsung',
            'user'    => [
                'id'    => $user->id,
                'name'  => $user->name,
                'email' => $user->email,
                'role'  => $user->role,
            ]
        ], 201);
    }
}
