<?php

namespace App\Features\Equipes\Models;

use App\Features\Auth\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class MembreEquipe extends Model
{
    protected $table = 'membre_equipe';

    public $timestamps = false;

    protected $fillable = [
        'utilisateur_id',
        'equipe_id',
        'role',
        'date_adhesion',
    ];

    protected function casts(): array
    {
        return [
            'date_adhesion' => 'datetime',
        ];
    }

    public function utilisateur(): BelongsTo
    {
        return $this->belongsTo(User::class, 'utilisateur_id');
    }

    public function equipe(): BelongsTo
    {
        return $this->belongsTo(Equipe::class, 'equipe_id');
    }
}
