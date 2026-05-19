<?php

namespace App\Features\Auth\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;

class User extends Authenticatable
{
    /** @use HasFactory<\Database\Factories\UserFactory> */
    use HasFactory, Notifiable;

    protected $fillable = [
        'nom',
        'email',
        'mot_de_passe',
        'api_token',
    ];

    protected $hidden = [
        'mot_de_passe',
        'token_souvenir',
        'api_token',
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
            'email_verifie' => 'datetime',
            'mot_de_passe'  => 'hashed',
        ];
    }
}
