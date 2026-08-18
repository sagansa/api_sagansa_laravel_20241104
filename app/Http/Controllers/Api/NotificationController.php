<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Notification;
use Illuminate\Http\Request;

class NotificationController extends Controller
{
    /**
     * List notifikasi milik user login, terbaru lebih dulu.
     * Filter ?unread=1 untuk hanya yang belum dibaca.
     */
    public function index(Request $request)
    {
        $userId = $request->user()->id;
        $perPage = $request->integer('per_page', 20);

        $query = Notification::where('user_id', $userId)
            ->orderBy('created_at', 'desc')
            ->orderBy('id', 'desc');

        if ($request->boolean('unread')) {
            $query->whereNull('read_at');
        }

        $items = $query->paginate($perPage);

        $data = collect($items->items())->map(fn ($n) => $this->format($n));

        return response()->json([
            'status' => 'success',
            'data' => $data,
            'pagination' => [
                'current_page' => $items->currentPage(),
                'last_page' => $items->lastPage(),
                'per_page' => $items->perPage(),
                'total' => $items->total(),
            ],
        ]);
    }

    /**
     * Jumlah notifikasi belum dibaca (untuk badge bell).
     */
    public function unreadCount(Request $request)
    {
        $userId = $request->user()->id;

        $count = Notification::where('user_id', $userId)
            ->whereNull('read_at')
            ->count();

        return response()->json([
            'status' => 'success',
            'data' => ['count' => $count],
        ]);
    }

    /**
     * Tandai satu notifikasi sudah dibaca.
     */
    public function markRead(Request $request, $id)
    {
        $userId = $request->user()->id;

        $notification = Notification::where('user_id', $userId)
            ->findOrFail($id);

        $notification->markAsRead();

        return response()->json([
            'status' => 'success',
            'message' => 'Notifikasi ditandai dibaca.',
            'data' => $this->format($notification),
        ]);
    }

    /**
     * Tandai semua notifikasi user sudah dibaca.
     */
    public function markAllRead(Request $request)
    {
        $userId = $request->user()->id;

        Notification::where('user_id', $userId)
            ->whereNull('read_at')
            ->update(['read_at' => now()]);

        return response()->json([
            'status' => 'success',
            'message' => 'Semua notifikasi ditandai dibaca.',
        ]);
    }

    /**
     * Hapus satu notifikasi milik user login. Id milik user lain → 404.
     */
    public function destroy(Request $request, $id)
    {
        $userId = $request->user()->id;

        $notification = Notification::where('user_id', $userId)
            ->findOrFail($id);

        $notification->delete();

        return response()->json([
            'status' => 'success',
            'message' => 'Notifikasi dihapus.',
        ]);
    }

    /**
     * Hapus semua notifikasi milik user login. Mengembalikan jumlah terhapus.
     */
    public function clearAll(Request $request)
    {
        $userId = $request->user()->id;

        $deleted = Notification::where('user_id', $userId)->delete();

        return response()->json([
            'status' => 'success',
            'message' => 'Semua notifikasi dihapus.',
            'data' => ['deleted' => $deleted],
        ]);
    }

    private function format(Notification $n): array
    {
        return [
            'id' => $n->id,
            'type' => $n->type,
            'title' => $n->title,
            'body' => $n->body,
            'data' => $n->data,
            'read_at' => $n->read_at,
            'created_at' => $n->created_at,
        ];
    }
}
