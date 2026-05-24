<?php

namespace App\Features\Auth\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;

class User extends Authenticatable
{
    /** @use HasFactory<\Database\Factories\UserFactory> */
    use HasFactory, Notifiable;

    /**
     * Nom de la table en base de données.
     */
    protected $table = 'Utilisateurs';

    /**
     * Les rôles disponibles pour un utilisateur.
     */
    const ROLES = [
        'administrateur'           => 'Administrateur',
        'administrateur_cloud_doi' => 'Administrateur Cloud DOI',
        'securite'                 => 'Sécurité',
    ];

    protected $fillable = [
        'nom',
        'prenom',
        'username_outil_cicd',
        'mot_de_passe',
        'api_token',
        'token_outil_cicd',
        'date_inscription',
        'role',
    ];

    protected $hidden = [
        'mot_de_passe',
        'api_token',
        'token_outil_cicd',
    ];

    /**
     * Indique à Laravel quel champ correspond au mot de passe.
     */
    public function getAuthPassword(): string
    {
        return $this->getAttributes()['mot_de_passe'] ?? '';
    }

    public function getAuthPasswordName(): string
    {
        return 'mot_de_passe';
    }

    protected function casts(): array
    {
        return [
            'mot_de_passe'     => 'hashed',
            'date_inscription' => 'datetime',
        ];
    }
}
