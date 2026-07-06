<?php

namespace App\Http\Controllers;

use App\Models\Comment;
use App\Models\Notification;
use App\Models\Ticket;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class TicketController extends Controller
{
    // ============================================================
    // STATISTIK DASHBOARD
    // ============================================================
    public function stats(Request $request)
    {
        $user = $request->user();
        $query = Ticket::query();

        if ($user->role === 'user') {
            $query->where('user_id', $user->id);
        } elseif ($user->role === 'helpdesk') {
            $query->where('assigned_to', $user->id);
        }

        $tickets = $query->get();

        return response()->json([
            'stats' => [
                'total'       => $tickets->count(),
                'open'        => $tickets->where('status', 'open')->count(),
                'in_progress' => $tickets->where('status', 'in_progress')->count(),
                'closed'      => $tickets->where('status', 'closed')->count(),
            ]
        ]);
    }

    // ============================================================
    // GET SEMUA TIKET
    // ============================================================
    public function index(Request $request)
    {
        $user = $request->user();

        if ($user->role === 'user') {
            // User hanya lihat tiket miliknya
            $tickets = Ticket::with(['creator', 'assignee'])
                ->where('user_id', $user->id)
                ->latest()
                ->get();
        } elseif ($user->role === 'helpdesk') {
            // Helpdesk hanya lihat tiket yang diassign ke dia
            $tickets = Ticket::with(['creator', 'assignee'])
                ->where('assigned_to', $user->id)
                ->latest()
                ->get();
        } else {
            // Admin lihat semua tiket
            $tickets = Ticket::with(['creator', 'assignee'])
                ->latest()
                ->get();
        }

        return response()->json(['tickets' => $tickets]);
    }

    // ============================================================
    // BUAT TIKET BARU (SEMUA ROLE: USER, HELPDESK, ADMIN)
    // ============================================================
    public function store(Request $request)
    {
        $validated = $request->validate([
            'title'       => 'required|string|max:255',
            'description' => 'required|string',
            'category'    => 'required|string',
            'priority'    => 'nullable|string',
        ]);

        // Validasi priority - accept uppercase/lowercase
        $allowedPriorities = ['low', 'medium', 'high', 'critical'];
        $priority = strtolower($validated['priority'] ?? 'medium');
        if (!in_array($priority, $allowedPriorities)) {
            $priority = 'medium';
        }

        // Semua role (user, helpdesk, admin) bisa buat tiket
        $ticket = Ticket::create([
            'title'       => $validated['title'],
            'description' => $validated['description'],
            'category'    => $validated['category'],
            'priority'    => $priority,
            'user_id'     => $request->user()->id,
            'status'      => 'open',
        ]);

        // Kirim notifikasi ke ADMIN saja (helpdesk dapat notifikasi saat di-assign)
        $admins = User::where('role', 'admin')->get();
        foreach ($admins as $admin) {
            Notification::create([
                'user_id'   => $admin->id,
                'title'     => 'Tiket Baru',
                'message'   => 'Tiket baru dibuat: ' . $validated['title'],
                'ticket_id' => $ticket->id,
                'is_read'   => false,
            ]);
        }

        return response()->json([
            'message' => 'Tiket berhasil dibuat',
            'ticket'  => $ticket->load(['creator', 'assignee']),
        ], 201);
    }

    // ============================================================
    // GET DETAIL TIKET
    // ============================================================
    public function show(Request $request, $id)
    {
        $ticket = Ticket::with(['creator', 'assignee', 'comments.author'])
            ->find($id);

        if (!$ticket) {
            return response()->json(['message' => 'Tiket tidak ditemukan'], 404);
        }

        // Ambil history
        $histories = DB::table('ticket_histories')
            ->where('ticket_id', $id)
            ->join('users', 'ticket_histories.user_id', '=', 'users.id')
            ->select('ticket_histories.*', 'users.name as changed_by')
            ->orderBy('ticket_histories.created_at', 'asc')
            ->get();

        // Auto mark notifications untuk tiket ini sebagai read
        Notification::where('user_id', $request->user()->id)
            ->where('ticket_id', $id)
            ->where('is_read', false)
            ->update(['is_read' => true]);

        return response()->json([
            'ticket'    => $ticket,
            'histories' => $histories,
        ]);
    }

    // ============================================================
    // UPDATE TIKET (TITLE, DESC, PRIORITY, CATEGORY SAJA)
    // ============================================================
    public function update(Request $request, $id)
    {
        $ticket = Ticket::find($id);
        if (!$ticket) {
            return response()->json(['message' => 'Tiket tidak ditemukan'], 404);
        }

        // User hanya bisa update tiket miliknya
        if ($request->user()->role === 'user' && $ticket->user_id !== $request->user()->id) {
            return response()->json(['message' => 'Tidak ada akses'], 403);
        }

        $ticket->update($request->only([
            'title', 'description', 'priority', 'category',
        ]));

        return response()->json([
            'message' => 'Tiket berhasil diupdate',
            'ticket'  => $ticket->load(['creator', 'assignee']),
        ]);
    }

    // ============================================================
    // ASSIGN TIKET KE HELPDESK (ADMIN SAJA)
    // ============================================================
    public function assignTicket(Request $request, $id)
    {
        $ticket = Ticket::findOrFail($id);
        $oldAssignedTo = $ticket->assigned_to;
        $oldStatus = $ticket->status;

        $request->validate([
            'assigned_to' => 'required|exists:users,id',
        ]);

        // Validasi assigned_to harus helpdesk
        $assignee = User::find($request->assigned_to);
        if ($assignee->role !== 'helpdesk') {
            return response()->json([
                'message' => 'Tiket hanya bisa diassign ke helpdesk'
            ], 400);
        }

        DB::beginTransaction();
        try {
            $ticket->update(['assigned_to' => $request->assigned_to]);

            // Auto ubah status ke in_progress
            if ($ticket->status === 'open') {
                $ticket->update(['status' => 'in_progress']);
            }

            // Notifikasi ke helpdesk yang diassign
            Notification::create([
                'user_id'   => $request->assigned_to,
                'title'     => 'Tiket Diassign ke Kamu',
                'message'   => 'Tiket "' . $ticket->title . '" telah diassign kepada kamu',
                'ticket_id' => $ticket->id,
                'is_read'   => false,
            ]);

            // Simpan history
            DB::table('ticket_histories')->insert([
                'id'          => (string) \Illuminate\Support\Str::uuid(),
                'ticket_id'   => $id,
                'user_id'     => $request->user()->id,
                'from_status' => $oldStatus,
                'to_status'   => $ticket->status,
                'comment'     => 'Ditugaskan ke ' . $assignee->name,
                'created_at'  => now(),
            ]);

            DB::commit();

            return response()->json([
                'message' => 'Tiket berhasil diassign',
                'ticket'  => $ticket->load(['creator', 'assignee']),
            ]);
        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json([
                'message' => 'Gagal mengassign tiket: ' . $e->getMessage()
            ], 500);
        }
    }

    // ============================================================
    // FINISH TIKET (HELPDESK SAJA)
    // ============================================================
    public function finish(Request $request, $id)
    {
        $ticket = Ticket::findOrFail($id);

        // Hanya helpdesk yang diassign ke tiket ini yang bisa finish
        if ($request->user()->id !== $ticket->assigned_to) {
            return response()->json([
                'message' => 'Hanya helpdesk yang ditugaskan yang dapat menyelesaikan tiket'
            ], 403);
        }

        // Tiket harus dalam status in_progress
        if ($ticket->status !== 'in_progress') {
            return response()->json([
                'message' => 'Tiket harus dalam status in_progress'
            ], 400);
        }

        DB::beginTransaction();
        try {
            $oldStatus = $ticket->status;
            $ticket->update(['status' => 'closed']);

            // Notifikasi ke user yang bikin tiket
            Notification::create([
                'user_id'   => $ticket->user_id,
                'title'     => 'Tiket Selesai',
                'message'   => 'Tiket "' . $ticket->title . '" telah diselesaikan',
                'ticket_id' => $ticket->id,
                'is_read'   => false,
            ]);

            // Simpan history
            DB::table('ticket_histories')->insert([
                'id'          => (string) \Illuminate\Support\Str::uuid(),
                'ticket_id'   => $id,
                'user_id'     => $request->user()->id,
                'from_status' => $oldStatus,
                'to_status'   => 'closed',
                'comment'     => 'Tiket diselesaikan',
                'created_at'  => now(),
            ]);

            DB::commit();

            return response()->json([
                'message' => 'Tiket berhasil diselesaikan',
                'ticket'  => $ticket->load(['creator', 'assignee']),
            ]);
        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json([
                'message' => 'Gagal menyelesaikan tiket: ' . $e->getMessage()
            ], 500);
        }
    }

    // ============================================================
    // HAPUS TIKET (ADMIN SAJA)
    // ============================================================
    public function destroy(Request $request, $id)
    {
        if ($request->user()->role !== 'admin') {
            return response()->json(['message' => 'Hanya admin yang dapat menghapus tiket'], 403);
        }

        $ticket = Ticket::find($id);
        if (!$ticket) {
            return response()->json(['message' => 'Tiket tidak ditemukan'], 404);
        }

        $ticket->delete();

        return response()->json(['message' => 'Tiket berhasil dihapus']);
    }

    // ============================================================
    // TAMBAH KOMENTAR
    // ============================================================
    public function addComment(Request $request, $id)
    {
        $request->validate([
            'content' => 'required|string',
        ]);

        $comment = Comment::create([
            'ticket_id' => $id,
            'user_id'   => $request->user()->id,
            'content'   => $request->content,
        ]);

        $ticket = Ticket::find($id);

        // Notifikasi ke user/heldesk yang relevan
        if ($ticket) {
            $notifyUserId = null;

            if ($request->user()->role === 'user') {
                // User komentar → notify helpdesk
                $notifyUserId = $ticket->assigned_to;
            } else {
                // Helpdesk/Admin komentar → notify user (pembuat tiket)
                // Kecuali dia sendiri mengomentari tiketnya sendiri
                if ($request->user()->id !== $ticket->user_id) {
                    $notifyUserId = $ticket->user_id;
                }
            }

            if ($notifyUserId) {
                Notification::create([
                    'user_id'   => $notifyUserId,
                    'title'     => 'Komentar Baru',
                    'message'   => 'Ada balasan baru di tiket "' . $ticket->title . '"',
                    'ticket_id' => $id,
                    'is_read'   => false,
                ]);
            }
        }

        return response()->json([
            'message' => 'Komentar berhasil ditambahkan',
            'comment' => $comment->load('author'),
        ], 201);
    }

    // ============================================================
    // GET TICKET HISTORY
    // ============================================================
    public function getHistory($id)
    {
        $histories = DB::table('ticket_histories')
            ->where('ticket_id', $id)
            ->leftJoin('users', 'ticket_histories.user_id', '=', 'users.id')
            ->select(
                'ticket_histories.id',
                'ticket_histories.ticket_id',
                'ticket_histories.user_id',
                'users.name as user_name',
                'users.role',
                'ticket_histories.from_status',
                'ticket_histories.to_status',
                'ticket_histories.comment',
                'ticket_histories.created_at'
            )
            ->orderBy('ticket_histories.created_at', 'asc')
            ->get();

        return response()->json([
            'history' => $histories
        ]);
    }

    // ============================================================
    // GET TICKET COMMENTS
    // ============================================================
    public function getComments($id)
    {
        $comments = DB::table('comments')
            ->where('ticket_id', $id)
            ->leftJoin('users', 'comments.user_id', '=', 'users.id')
            ->select(
                'comments.id',
                'comments.ticket_id',
                'comments.user_id',
                'users.name as user_name',
                'users.role',
                'comments.content',
                'comments.created_at'
            )
            ->orderBy('comments.created_at', 'asc')
            ->get();

        return response()->json([
            'comments' => $comments
        ]);
    }
}
