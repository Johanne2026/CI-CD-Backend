<?php

namespace App\Features\Notifications\Controllers\Api;

use App\Features\Notifications\Models\Notification;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ApiNotificationController extends Controller
{
    /**
     * Liste les notifications de l'utilisateur connecté.
     * Les non lues apparaissent en premier.
     *
     * GET /api/notifications
     * Query param : ?non_lues=1  → uniquement les non lues
     */
    public function index(Request $request): JsonResponse
    {
        $query = Notification::where('utilisateur_id', $request->user()->id)
            ->orderBy('est_lu')
            ->orderByDesc('date_creation');

        if ($request->boolean('non_lues')) {
            $query->where('est_lu', false);
        }

        $notifications = $query->get();

        return response()->json([
            'notifications' => $notifications,
            'total'         => $notifications->count(),
            'non_lues'      => $notifications->where('est_lu', false)->count(),
        ]);
    }

    /**
     * Marque une notification comme lue.
     *
     * PATCH /api/notifications/{id}/lire
     */
    public function marquerLue(Request $request, int $id): JsonResponse
    {
        $notification = Notification::where('id', $id)
            ->where('utilisateur_id', $request->user()->id)
            ->firstOrFail();

        $notification->update(['est_lu' => true]);

        return response()->json(['message' => 'Notification marquée comme lue.']);
    }

    /**
     * Marque toutes les notifications de l'utilisateur comme lues.
     *
     * PATCH /api/notifications/lire-toutes
     */
    public function marquerToutesLues(Request $request): JsonResponse
    {
        $count = Notification::where('utilisateur_id', $request->user()->id)
            ->where('est_lu', false)
            ->update(['est_lu' => true]);

        return response()->json([
            'message' => "{$count} notification(s) marquée(s) comme lues.",
            'count'   => $count,
        ]);
    }

    /**
     * Supprime une notification.
     *
     * DELETE /api/notifications/{id}
     */
    public function destroy(Request $request, int $id): JsonResponse
    {
        $notification = Notification::where('id', $id)
            ->where('utilisateur_id', $request->user()->id)
            ->firstOrFail();

        $notification->delete();

        return response()->json(['message' => 'Notification supprimée.']);
    }

    /**
     * Supprime toutes les notifications lues de l'utilisateur.
     *
     * DELETE /api/notifications/lues
     */
    public function supprimerLues(Request $request): JsonResponse
    {
        $count = Notification::where('utilisateur_id', $request->user()->id)
            ->where('est_lu', true)
            ->delete();

        return response()->json([
            'message' => "{$count} notification(s) supprimée(s).",
            'count'   => $count,
        ]);
    }
}
