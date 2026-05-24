<?php

namespace Database\Seeders;

use App\Features\Auth\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Carbon;

class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        $utilisateurs = [
            [
                'nom'              => 'Emmy',
                'prenom'           => 'Admin',
                'email'            => 'emmy@gmail.com',
                'mot_de_passe'     => Hash::make('Azerty123'),
                'role'             => 'administrateur',
                'date_inscription' => Carbon::now(),
            ],
            [
                'nom'              => 'EmmySecu',
                'prenom'           => 'Securite',
                'email'            => 'emmysecu@gmail.com',
                'mot_de_passe'     => Hash::make('Azerty1234'),
                'role'             => 'securite',
                'date_inscription' => Carbon::now(),
            ],
            [
                'nom'              => 'EmmyAdmin',
                'prenom'           => 'CloudDOI',
                'email'            => 'emmyadmin@gmail.com',
                'mot_de_passe'     => Hash::make('Azerty12345'),
                'role'             => 'administrateur_cloud_doi',
                'date_inscription' => Carbon::now(),
            ],
        ];

        foreach ($utilisateurs as $data) {
            User::create($data);
        }
    }
}
