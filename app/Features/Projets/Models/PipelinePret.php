<?php

namespace App\Features\Projets\Models;

use App\Features\Auth\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PipelinePret extends Model
{
    protected $table = 'pipeline_pret';

    protected $fillable = [
        'projet_id',
        'run_id',
        'branche',
        'commit_sha',
        'nom_workflow',
        'marque_par',
        'deploye',
    ];

    protected function casts(): array
    {
        return [
            'deploye' => 'boolean',
        ];
    }

    public function projet(): BelongsTo
    {
        return $this->belongsTo(Projet::class, 'projet_id');
    }

    public function marquePar(): BelongsTo
    {
        return $this->belongsTo(User::class, 'marque_par');
    }
}
