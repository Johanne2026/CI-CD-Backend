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
        'token_github',
        'mot_de_passe',
        'api_token',
        'date_inscription',
        'role',
        'doit_changer_mot_de_passe',
    ];

    protected $hidden = [
        'mot_de_passe',
        'api_token',
        'token_github',  // jamais exposé dans les réponses JSON
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
            'mot_de_passe'              => 'hashed',
            'date_inscription'          => 'datetime',
            'doit_changer_mot_de_passe' => 'boolean',
            'token_github'              => 'encrypted',  // AES-256 via APP_KEY Laravel
        ];
    }

    /**
     * Indique si l'admin a configuré ses credentials GitHub.
     */
    public function aConfigureGithub(): bool
    {
        return ! empty($this->username_outil_cicd) && ! empty($this->token_github);
    }

    /**
     * Retourne le token GitHub à utiliser pour les appels API.
     * Priorité : token personnel de l'admin → GITHUB_TOKEN .env
     */
    public function tokenGithubEffectif(): string
    {
        return $this->token_github ?? config('services.github.token', '');
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
