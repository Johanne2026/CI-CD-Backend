<?php

namespace App\Features\Notifications\Services;

use App\Features\Notifications\Models\Notification;

class NotificationService
{
    /**
     * Crée une notification pour un utilisateur.
     */
    public static function envoyer(
        int    $utilisateurId,
        string $titre,
        string $message,
        string $type = 'info'
    ): Notification {
        return Notification::create([
            'utilisateur_id' => $utilisateurId,
            'titre'          => $titre,
            'message'        => $message,
            'type'           => $type,
        ]);
    }

    public static function info(int $utilisateurId, string $titre, string $message): Notification
    {
        return self::envoyer($utilisateurId, $titre, $message, 'info');
    }

    public static function succes(int $utilisateurId, string $titre, string $message): Notification
    {
        return self::envoyer($utilisateurId, $titre, $message, 'succes');
    }

    public static function attention(int $utilisateurId, string $titre, string $message): Notification
    {
        return self::envoyer($utilisateurId, $titre, $message, 'attention');
    }

    public static function erreur(int $utilisateurId, string $titre, string $message): Notification
    {
        return self::envoyer($utilisateurId, $titre, $message, 'erreur');
    }
}
