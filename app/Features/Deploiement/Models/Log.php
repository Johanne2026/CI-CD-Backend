<?php

namespace App\Features\Deploiement\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Log extends Model
{
    protected $table = 'logs';

    public $timestamps = false;  // on gère created_at manuellement

    protected $fillable = [
        'deploiement_id',
        'source',
        'niveau',
        'etape',
        'message',
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
}
