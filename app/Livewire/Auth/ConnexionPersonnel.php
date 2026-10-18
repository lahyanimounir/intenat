<?php

namespace App\Livewire\Auth;

use App\Models\Utilisateur;
use Illuminate\Support\Collection;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;

/**
 * Entrée de l'espace personnel (backend, « /backend »), réservée aux comptes du personnel :
 * aucun lien vers l'espace stagiaire. Après connexion, chacun arrive sur le tableau de bord de son rôle.
 */
#[Layout('layouts.auth')]
#[Title('Connexion du personnel')]
class ConnexionPersonnel extends Connexion
{
    protected function espace(): string
    {
        return 'personnel';
    }

    /** Comptes de démonstration, affichés en mode démonstration uniquement. */
    private function comptesDeDemonstration(): Collection
    {
        if (! config('internat.mode_demo') || app()->isProduction()) {
            return collect();
        }

        return Utilisateur::query()->with('roles')->where('actif', true)->where('email', 'like', '%@internat.test')
            ->orderBy('id')->limit(12)->get()
            ->map(fn (Utilisateur $u) => ['role' => (string) $u->roleCode()?->libelle(), 'email' => $u->email]);
    }

    public function render()
    {
        return view('livewire.auth.connexion-personnel', ['demonstration' => $this->comptesDeDemonstration()]);
    }
}
