<?php

namespace App\Features\Logs\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class LogDetail extends Model
{
    protected $table = 'log_details';

    public $timestamps = false;

    protected $fillable = [
        'log_id',
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

    /**
     * Le résumé de logs auquel appartient cette ligne.
     */
    public function log(): BelongsTo
    {
        return $this->belongsTo(Log::class, 'log_id');
    }
}
