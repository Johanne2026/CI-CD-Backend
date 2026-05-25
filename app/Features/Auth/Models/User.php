<?php

namespace App\Features\Auth\Models;

use App\Features\Equipes\Models\Equipe;
use App\Features\Projets\Models\Projet;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
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
        'email',
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

    /**
     * Les équipes auxquelles l'utilisateur appartient.
     */
    public function equipes(): BelongsToMany
    {
        return $this->belongsToMany(
            Equipe::class,
            'membre_equipe',
            'utilisateur_id',
            'equipe_id'
        )->withPivot('role', 'date_adhesion');
    }

    /**
     * Les équipes dont l'utilisateur est propriétaire.
     */
    public function equipesProprietaire(): HasMany
    {
        return $this->hasMany(Equipe::class, 'proprietaire_id');
    }

    /**
     * Les projets créés par cet utilisateur.
     */
    public function projets(): HasMany
    {
        return $this->hasMany(Projet::class, 'cree_par_id');
    }
}
