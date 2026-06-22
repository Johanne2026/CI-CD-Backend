<?php

namespace App\Features\Equipes\Models;

use App\Features\Auth\Models\User;
use App\Features\Projets\Models\Projet;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class Equipe extends Model
{
    use HasFactory;
    protected $table = 'Equipes';

    /**
     * Pas de timestamps automatiques — on gère date_creation et date_mise_a_jour manuellement.
     */
    public $timestamps = false;

    protected $fillable = [
        'proprietaire_id',
        'nom',
        'description',
    ];

    protected function casts(): array
    {
        return [
            'date_creation'    => 'datetime',
            'date_mise_a_jour' => 'datetime',
        ];
    }

    /**
     * L'utilisateur propriétaire de l'équipe.
     */
    public function proprietaire(): BelongsTo
    {
        return $this->belongsTo(User::class, 'proprietaire_id');
    }

    /**
     * Tous les membres de l'équipe via la table pivot membre_equipe.
     */
    public function membres(): BelongsToMany
    {
        return $this->belongsToMany(
            User::class,
            'membre_equipe',
            'equipe_id',
            'utilisateur_id'
        )->withPivot('role', 'date_adhesion');
    }

    /**
     * Entrées détaillées de la table membre_equipe.
     */
    public function membresEquipe(): HasMany
    {
        return $this->hasMany(MembreEquipe::class, 'equipe_id');
    }

    /**
     * Le projet associé à cette équipe (une équipe = un seul projet).
     */
    public function projet(): HasOne
    {
        return $this->hasOne(Projet::class, 'equipe_id');
    }
}
