<?php

namespace Database\Seeders;

use App\Features\Auth\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Carbon;

class DatabaseSeeder extends Seeder
{
    /**
     * Seed the application's database.
     */
    public function run(): void
    {
        // Utilisateurs de test
        $utilisateurs = [
            [
                'nom'                 => 'Emmy',
                'prenom'              => 'Admin',
                'username_outil_cicd' => 'emmy-github',
                'mot_de_passe'        => Hash::make('Azerty123'),
                'role'                => 'administrateur',
                'date_inscription'    => Carbon::now(),
            ],
            [
                'nom'                 => 'EmmySecu',
                'prenom'              => 'Securite',
                'username_outil_cicd' => 'emmysecu-github',
                'mot_de_passe'        => Hash::make('Azerty1234'),
                'role'                => 'securite',
                'date_inscription'    => Carbon::now(),
            ],
            [
                'nom'                 => 'EmmyAdmin',
                'prenom'              => 'CloudDOI',
                'username_outil_cicd' => 'emmyadmin-github',
                'mot_de_passe'        => Hash::make('Azerty12345'),
                'role'                => 'administrateur_cloud_doi',
                'date_inscription'    => Carbon::now(),
            ],
        ];

        foreach ($utilisateurs as $data) {
            User::create($data);
        }
    }
}
