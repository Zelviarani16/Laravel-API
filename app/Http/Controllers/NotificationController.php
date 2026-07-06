<?php
namespace App\Http\Controllers;

use App\Models\Notification;
use App\Models\Ticket;
use Illuminate\Http\Request;

class NotificationController extends Controller
{
    // GET SEMUA NOTIFIKASI + JUMLAH UNREAD
    public function index(Request $request)
    {
        $notifications = Notification::where('user_id', $request->user()->id)
            ->orderBy('created_at', 'desc')
            ->get();

        $unreadCount = $notifications->where('is_read', false)->count();

        return response()->json([
            'notifications' => $notifications,
            'unread_count'  => $unreadCount
        ]);
    }

    // GET DETAIL NOTIFIKASI + TIKET + ACTION BUTTONS
    public function show(Request $request, $id)
    {
        $notification = Notification::where('id', $id)
            ->where('user_id', $request->user()->id)
            ->first();

        if (!$notification) {
            return response()->json(['message' => 'Notifikasi tidak ditemukan'], 404);
        }

        // Mark as read saat dibuka
        if (!$notification->is_read) {
            $notification->update(['is_read' => true]);
        }

        // Ambil data tiket lengkap
        $ticket = Ticket::with(['creator', 'assignee', 'comments.author'])
            ->find($notification->ticket_id);

        // Tentukan action buttons berdasarkan role
        $actions = [];

        if ($ticket) {
            if ($request->user()->role === 'helpdesk' && $ticket->assigned_to == $request->user()->id) {
                // Helpdesk yang handle tiket ini
                if ($ticket->status !== 'closed') {
                    $actions[] = ['type' => 'solve', 'label' => 'Selesaikan Tiket'];
                }
                $actions[] = ['type' => 'add_comment', 'label' => 'Tambah Komentar'];
            } elseif ($request->user()->role === 'admin') {
                // Admin bisa apa aja
                if ($ticket->status !== 'closed') {
                    $actions[] = ['type' => 'solve', 'label' => 'Selesaikan Tiket'];
                }
                $actions[] = ['type' => 'assign', 'label' => 'Assign Ulang'];
                $actions[] = ['type' => 'add_comment', 'label' => 'Tambah Komentar'];
            } elseif ($request->user()->role === 'user' && $ticket->user_id == $request->user()->id) {
                // User pemilik tiket
                $actions[] = ['type' => 'add_comment', 'label' => 'Tambah Komentar'];
            }
        }

        return response()->json([
            'notification' => $notification,
            'ticket'       => $ticket,
            'actions'      => $actions
        ]);
    }

    // MARK 1 NOTIFIKASI AS READ
    public function markAsRead(Request $request, $id)
    {
        $notification = Notification::where('id', $id)
            ->where('user_id', $request->user()->id)
            ->first();

        if (!$notification) {
            return response()->json(['message' => 'Notifikasi tidak ditemukan'], 404);
        }

        $notification->update(['is_read' => true]);

        return response()->json(['message' => 'Notifikasi ditandai sudah dibaca']);
    }

    // MARK SEMUA AS READ
    public function markAllAsRead(Request $request)
    {
        Notification::where('user_id', $request->user()->id)
            ->where('is_read', false)
            ->update(['is_read' => true]);

        return response()->json(['message' => 'Semua notifikasi sudah dibaca']);
    }
}