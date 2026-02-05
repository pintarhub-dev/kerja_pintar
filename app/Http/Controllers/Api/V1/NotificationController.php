<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;

class NotificationController extends Controller
{
    // Ambil List Notifikasi
    public function index(Request $request)
    {
        $user = $request->user();

        // Ambil notifikasi user, urutkan dari yang terbaru
        $notifications = $user->notifications()
            ->latest()
            ->take(20) // Batasi 20 terakhir biar ringan
            ->get()
            ->map(function ($n) {
                return [
                    'id' => $n->id,
                    'title' => $n->data['title'] ?? 'Notifikasi',
                    'body' => $n->data['body'] ?? '',
                    'data' => [ // Data buat navigasi
                        'type' => $n->data['type'] ?? '',
                        'id' => $n->data['target_id'] ?? '',
                    ],
                    'is_read' => $n->read_at !== null,
                    'created_at' => $n->created_at->diffForHumans(), // Contoh: "2 menit yang lalu"
                ];
            });

        return response()->json([
            'success' => true,
            'data' => $notifications
        ]);
    }

    // Tandai sudah dibaca
    public function markAsRead($id, Request $request)
    {
        $user = $request->user();
        $notification = $user->notifications()->where('id', $id)->first();

        if ($notification) {
            $notification->markAsRead(); // Isi kolom read_at dengan jam sekarang
        }

        return response()->json(['success' => true]);
    }

    // Tandai SEMUA sudah dibaca (Opsional, tombol "Mark All Read")
    public function markAllRead(Request $request)
    {
        $request->user()->unreadNotifications->markAsRead();
        return response()->json(['success' => true]);
    }

    public function unreadCount(Request $request)
    {
        // Fitur bawaan Laravel: unreadNotifications()
        $count = $request->user()->unreadNotifications()->count();

        return response()->json([
            'success' => true,
            'count'   => $count
        ]);
    }
}
