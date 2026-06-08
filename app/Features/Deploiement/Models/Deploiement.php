<?php

namespace App\Features\Deploiement\Models;

use App\Features\Auth\Models\User;
use App\Features\Projets\Models\Projet;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Deploiement extends Model
{
    protected $table = 'deploiements';

    protected $fillable = [
        'projet_id',
        'lance_par',
        'app',
        'version',
        'statut',
        'logs',
    ];

    /**
     * Le projet associé à ce déploiement.
     */
    public function projet(): BelongsTo
    {
        return $this->belongsTo(Projet::class, 'projet_id');
    }

    /**
     * L'utilisateur qui a déclenché ce déploiement.
     */
    public function lancePar(): BelongsTo
    {
        return $this->belongsTo(User::class, 'lance_par');
    }
}
