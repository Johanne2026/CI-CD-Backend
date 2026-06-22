<?php

namespace App\Features\Logs\Models;

use App\Features\Deploiement\Models\Deploiement;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Log extends Model
{
    protected $table = 'logs';

    public $timestamps = false;

    /**
     * Table logs — UNE LIGNE PAR DÉPLOIEMENT (par source CI ou CD).
     * Contrainte UNIQUE sur (deploiement_id, source).
     *
     * contenu_ci : tous les logs CI compilés en texte brut
     * contenu_cd : tous les logs CD compilés en texte brut
     * niveau     : niveau global (ERROR si au moins une ligne est ERROR)
     */
    protected $fillable = [
        'deploiement_id',
        'source',
        'niveau',
        'contenu_ci',
        'contenu_cd',
        'created_at',
    ];

    protected function casts(): array
    {
        return [
            'created_at' => 'datetime',
        ];
    }

    public function deploiement(): BelongsTo
    {
        return $this->belongsTo(Deploiement::class, 'deploiement_id');
    }

    /**
     * Les lignes individuelles de ce log (table log_details).
     */
    public function details(): HasMany
    {
        return $this->hasMany(LogDetail::class, 'log_id');
    }
}
