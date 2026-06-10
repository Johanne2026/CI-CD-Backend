<?php

namespace App\Features\Notifications\Models;

use App\Features\Auth\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Notification extends Model
{
    protected $table = 'notifications';

    public $timestamps = false;

    protected $fillable = [
        'utilisateur_id',
        'titre',
        'message',
        'type',
        'est_lu',
    ];

    protected function casts(): array
    {
        return [
            'est_lu'        => 'boolean',
            'date_creation' => 'datetime',
        ];
    }

    public function utilisateur(): BelongsTo
    {
        return $this->belongsTo(User::class, 'utilisateur_id');
    }
}
