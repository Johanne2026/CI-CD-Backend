<?php

namespace App\Features\Projets\Models;

use App\Features\Auth\Models\User;
use App\Features\Equipes\Models\Equipe;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Projet extends Model
{
    protected $table = 'Projets';

    public $timestamps = false;

    protected $fillable = [
        'equipe_id',
        'cree_par_id',
        'nom',
        'description',
        'stack_technologique',
        'actif',
        'duree_projet',
        'url_depot',
    ];

    protected function casts(): array
    {
        return [
            'stack_technologique' => 'array',
            'actif'               => 'boolean',
            'date_creation'       => 'datetime',
            'date_mise_a_jour'    => 'datetime',
        ];
    }

    /**
     * Indique si le projet est lié à un dépôt GitHub.
     * Retourné automatiquement dans toutes les réponses JSON.
     */
    public function getLieAUnDepotAttribute(): bool
    {
        return ! empty($this->url_depot);
    }

    /**
     * Inclut lie_a_un_depot dans la sérialisation JSON.
     */
    protected $appends = ['lie_a_un_depot'];

    /**
     * L'équipe à laquelle appartient le projet.
     */
    public function equipe(): BelongsTo
    {
        return $this->belongsTo(Equipe::class, 'equipe_id');
    }

    /**
     * L'utilisateur qui a créé le projet.
     */
    public function creePar(): BelongsTo
    {
        return $this->belongsTo(User::class, 'cree_par_id');
    }
}
