<?php

namespace App\Livewire\Concerns;

use App\Domain\Identity\ReinitialisationMotDePasse;
use App\Models\Stagiaire;
use App\Models\Utilisateur;
use Illuminate\Validation\Rules\Password;
use Livewire\Attributes\Locked;

/**
 * Réinitialisation d'un mot de passe depuis une liste de comptes (« Utilisateurs », « Comptes
 * stagiaires ») : fenêtre de confirmation, mot de passe provisoire choisi ou généré, affiché une seule
 * fois. Les droits et le périmètre sont revérifiés par le service à chaque étape.
 */
trait AvecReinitialisationMotDePasse
{
    #[Locked]
    public ?int $compteMdp = null;

    /** « genere » : l'application propose un mot de passe ; « saisi » : le responsable le choisit. */
    public string $modeMdp = 'genere';

    public string $mot_de_passe = '';

    public string $mot_de_passe_confirmation = '';

    /** Mot de passe provisoire à remettre au titulaire : montré une fois, jamais conservé. */
    #[Locked]
    public ?string $provisoire = null;

    /** Compte visé par la ligne : la sous-classe le cherche dans sa propre table. */
    abstract protected function compteDeLaLigne(int $id): Utilisateur|Stagiaire;

    public function preparerReinitialisation(ReinitialisationMotDePasse $service, int $id): void
    {
        $compte = $this->compteDeLaLigne($id);
        abort_unless($service->peutReinitialiser($this->utilisateur(), $compte), 403);

        $this->compteMdp = $compte->id;
        $this->reset('modeMdp', 'mot_de_passe', 'mot_de_passe_confirmation', 'provisoire');
        $this->ouvrirDialogue('mot-de-passe');
    }

    public function reinitialiserMotDePasse(ReinitialisationMotDePasse $service): void
    {
        abort_if($this->compteMdp === null, 404);
        $compte = $this->compteDeLaLigne($this->compteMdp);

        $saisi = $this->modeMdp === 'saisi';
        if ($saisi) {
            $this->validate(
                ['mot_de_passe' => ['required', 'string', 'max:200', 'confirmed', Password::defaults()]],
                ['mot_de_passe.confirmed' => 'Les deux mots de passe ne correspondent pas.'],
                ['mot_de_passe' => 'mot de passe provisoire'],
            );
        }

        $this->executer(function () use ($service, $compte, $saisi) {
            $this->provisoire = $service->reinitialiser($this->utilisateur(), $compte, $saisi ? $this->mot_de_passe : null);
            $this->reset('mot_de_passe', 'mot_de_passe_confirmation');
            $this->message('Mot de passe réinitialisé : remettez le mot de passe provisoire à son titulaire.');
        });
    }

    public function fermerReinitialisation(): void
    {
        $this->reset('compteMdp', 'modeMdp', 'mot_de_passe', 'mot_de_passe_confirmation', 'provisoire');
        $this->dialogue = null;
        $this->resetValidation();
    }

    /**
     * Identifiants des comptes de la page que le responsable peut réinitialiser.
     *
     * @param  iterable<Utilisateur|Stagiaire>  $comptes
     * @return list<int>
     */
    protected function reinitialisables(iterable $comptes): array
    {
        $service = app(ReinitialisationMotDePasse::class);
        $ids = [];
        foreach ($comptes as $compte) {
            if ($service->peutReinitialiser($this->utilisateur(), $compte)) {
                $ids[] = $compte->id;
            }
        }

        return $ids;
    }

    /** @return array{nom: string, role: string}|null */
    protected function compteEnReinitialisation(): ?array
    {
        if ($this->dialogue !== 'mot-de-passe' || $this->compteMdp === null) {
            return null;
        }

        $compte = $this->compteDeLaLigne($this->compteMdp);

        return [
            'nom' => trim($compte->prenom.' '.$compte->nom),
            'role' => $compte instanceof Stagiaire ? 'Stagiaire' : ($compte->roleCode()?->libelle() ?? 'Personnel'),
        ];
    }
}
