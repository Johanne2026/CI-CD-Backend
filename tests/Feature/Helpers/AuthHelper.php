<?php

namespace Tests\Feature\Helpers;

use App\Features\Auth\Models\User;
use Illuminate\Support\Str;

trait AuthHelper
{
    /**
     * Crée un utilisateur avec un token API valide et retourne les headers.
     */
    protected function creerUtilisateurConnecte(array $attrs = []): array
    {
        $token = Str::random(60);
        $user  = User::factory()->create(array_merge([
            'api_token' => hash('sha256', $token),
        ], $attrs));

        return [
            'user'    => $user,
            'token'   => $token,
            'headers' => [
                'Authorization' => "Bearer {$token}",
                'Accept'        => 'application/json',
                'Content-Type'  => 'application/json',
            ],
        ];
    }

    protected function adminConnecte(): array
    {
        return $this->creerUtilisateurConnecte(['role' => 'administrateur']);
    }

    protected function cloudDoiConnecte(): array
    {
        return $this->creerUtilisateurConnecte(['role' => 'administrateur_cloud_doi']);
    }

    protected function securiteConnecte(): array
    {
        return $this->creerUtilisateurConnecte(['role' => 'securite']);
    }
}
