<?php

namespace Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\User>
 */
class UserFactory extends Factory
{
    /**
     * Default: row SSO-origin (teller biasa), password NULL (§4.2 --
     * teller gak butuh password buat masuk, selalu lewat SSO).
     * is_superadmin dan 5 kolom kapabilitas SENGAJA gak disentuh di
     * sini -- default false dari migration, dan kolom-kolom itu
     * emang gak boleh di-mass-assign (§3.4, di luar $fillable User).
     * Kalau butuh row dengan kapabilitas true buat testing, set
     * manual pakai forceFill() di test-nya, bukan lewat factory ini.
     */
    public function definition(): array
    {
        return [
            'user_id_sopp' => fake()->unique()->numerify('U#####'),
            'loket_id' => fake()->numberBetween(1, 10),
            'nama' => fake()->name(),
            'password' => null,
        ];
    }

    /**
     * State buat teller yang udah pernah set password sendiri lewat
     * Settings (§4.2, opsional -- bukan default).
     */
    public function denganPassword(string $password = 'password'): static
    {
        return $this->state(fn (array $attributes) => [
            'password' => $password, // di-hash otomatis lewat cast 'hashed' di model User
        ]);
    }
}