<?php

namespace App\Services;

use GuzzleHttp\Client;
use Illuminate\Support\Facades\Log;

class SupabaseService
{
    protected $client;
    protected $url;
    protected $key;

    public function __construct()
    {
        $this->url = env('SUPABASE_URL');
        $this->key = env('SUPABASE_KEY');

        $this->client = new Client([
            'base_uri' => rtrim($this->url, '/') . '/rest/v1/',
            'headers' => [
                'apikey' => $this->key,
                'Authorization' => 'Bearer ' . $this->key,
                'Content-Type' => 'application/json',
                'Prefer' => 'return=representation',
            ],
        ]);
    }

    /**
     * Insert satu notification
     */
    public function insertNotification($userId, $title, $message, $ticketId = null, $role = null)
    {
        try {
            $data = [
                'title' => $title,
                'message' => $message,
                'is_read' => false,
            ];

            if ($userId) {
                $data['user_id'] = $userId;
            }

            if ($role) {
                $data['role'] = $role;
            }

            if ($ticketId) {
                $data['ticket_id'] = $ticketId;
            }

            $this->client->post('', [
                'body' => json_encode($data),
            ]);

            return true;
        } catch (\Exception $e) {
            Log::error('Supabase Error: ' . $e->getMessage());
            return false;
        }
    }

    /**
     * Insert MULTIPLE notifications sekaligus
     */
    public function insertBulkNotifications(array $notifications)
    {
        try {
            $this->client->post('', [
                'body' => json_encode($notifications),
            ]);
            return true;
        } catch (\Exception $e) {
            Log::error('Supabase Bulk Error: ' . $e->getMessage());
            return false;
        }
    }

    /**
     * =============================================
     * HELPER METHODS UNTUK TRIGGER NOTIFICATION
     * =============================================
     */

    /**
     * Saat tiket baru dibuat
     */
    public function notifyTicketCreated($ticket, $userName)
    {
        $notifications = [];

        // Ke user (pembuat)
        $notifications[] = [
            'user_id' => $ticket->user_id,
            'title' => 'Tiket Berhasil Dibuat',
            'message' => "Tiket #{$ticket->id} - {$ticket->title} berhasil dibuat",
            'ticket_id' => $ticket->id,
            'is_read' => false,
        ];

        // Ke semua helpdesk
        $notifications[] = [
            'role' => 'helpdesk',
            'title' => 'Tiket Baru Masuk',
            'message' => "{$userName} membuat tiket baru: {$ticket->title}",
            'ticket_id' => $ticket->id,
            'is_read' => false,
        ];

        $this->insertBulkNotifications($notifications);
    }

    /**
     * Saat tiket di-assign ke helpdesk
     */
    public function notifyTicketAssigned($ticket, $assigneeName, $assignerName)
    {
        $notifications = [];

        // Ke helpdesk yang ditugaskan
        if ($ticket->assigned_to) {
            $notifications[] = [
                'user_id' => $ticket->assigned_to,
                'title' => 'Tiket Ditugaskan',
                'message' => "Tiket #{$ticket->id} - {$ticket->title} ditugaskan ke Anda",
                'ticket_id' => $ticket->id,
                'is_read' => false,
            ];
        }

        // Ke user (pembuat tiket)
        $notifications[] = [
            'user_id' => $ticket->user_id,
            'title' => 'Tiket Sedang Diproses',
            'message' => "Tiket #{$ticket->id} sedang dikerjakan oleh {$assigneeName}",
            'ticket_id' => $ticket->id,
            'is_read' => false,
        ];

        $this->insertBulkNotifications($notifications);
    }

    /**
     * Saat status berubah
     */
    public function notifyStatusChanged($ticket, $oldStatus, $newStatus, $changedByName)
    {
        $statusLabels = [
            'open' => 'Open',
            'in_progress' => 'Sedang Diproses',
            'resolved' => 'Selesai',
            'closed' => 'Ditutup',
        ];

        $oldLabel = $statusLabels[$oldStatus] ?? $oldStatus;
        $newLabel = $statusLabels[$newStatus] ?? $newStatus;

        $notifications = [];

        // Ke user
        $notifications[] = [
            'user_id' => $ticket->user_id,
            'title' => 'Status Diubah',
            'message' => "Tiket #{$ticket->id} berubah dari '{$oldLabel}' menjadi '{$newLabel}' oleh {$changedByName}",
            'ticket_id' => $ticket->id,
            'is_read' => false,
        ];

        // Ke helpdesk yang ditugaskan
        if ($ticket->assigned_to) {
            $notifications[] = [
                'user_id' => $ticket->assigned_to,
                'title' => 'Status Diubah',
                'message' => "Tiket #{$ticket->id} berubah dari '{$oldLabel}' menjadi '{$newLabel}'",
                'ticket_id' => $ticket->id,
                'is_read' => false,
            ];
        }

        $this->insertBulkNotifications($notifications);
    }
}
