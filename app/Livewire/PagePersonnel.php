<?php

namespace App\Livewire;

use App\Domain\Identity\Perimetre;
use App\Livewire\Concerns\AvecDialogue;
use App\Livewire\Concerns\AvecMessages;
use App\Models\Campagne;
use App\Models\Internat;
use App\Models\Utilisateur;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Layout;
use Livewire\Component;

/**
 * Écran de l'espace du personnel.
 *
 * La route exige déjà une session du personnel et la permission d'ouvrir
 * l'écran ; chaque action revérifie ensuite sa propre permission et le
 * périmètre de la ligne visée, car une action Livewire peut être appelée
 * directement, hors de l'interface.
 */
#[Layout('layouts.app')]
abstract class PagePersonnel extends Component
{
    use AvecDialogue, AvecMessages;

    protected function utilisateur(): Utilisateur
    {
        $utilisateur = auth('web')->user();
        abort_unless($utilisateur instanceof Utilisateur && $utilisateur->actif, 403);

        return $utilisateur;
    }

    protected function perimetre(): Perimetre
    {
        return app(Perimetre::class);
    }

    protected function peut(string ...$permissions): bool
    {
        return $this->utilisateur()->canAny($permissions);
    }

    /** Refuse l'action si le compte ne détient aucune des permissions. */
    protected function exiger(string ...$permissions): void
    {
        abort_unless($this->peut(...$permissions), 403);
    }

    /** Permission détenue ET ligne dans le périmètre du compte. */
    protected function autoriser(Model $modele, string $permission): void
    {
        Gate::forUser($this->utilisateur())->authorize('perimetre', [$modele, $permission]);
    }

    /**
     * Internats du périmètre, pour les filtres de liste.
     *
     * @return Collection<int, string> identifiant => nom
     */
    protected function internatsDuPerimetre(): Collection
    {
        return Internat::visiblesPar($this->utilisateur())->orderBy('nom')->pluck('nom', 'id');
    }

    /**
     * Internat sur lequel porte un écran « par internat ». Un compte attaché à un seul internat n'a pas à
     * choisir ; les autres choisissent dans leur périmètre, le premier étant proposé par défaut. Un
     * identifiant hors périmètre est refusé.
     */
    protected function internatChoisi(string|int|null $demande): ?Internat
    {
        $utilisateur = $this->utilisateur();

        if ($demande !== null && $demande !== '') {
            $this->perimetre()->exigerInternat($utilisateur, (int) $demande);

            return Internat::with('etablissement')->findOrFail((int) $demande);
        }

        return Internat::visiblesPar($utilisateur)->with('etablissement')->orderBy('nom')->first();
    }

    /**
     * Campagnes du périmètre, de la plus récente à la plus ancienne.
     *
     * @return Collection<int, string> identifiant => « année — internat »
     */
    protected function campagnesDuPerimetre(): Collection
    {
        return Campagne::visiblesPar($this->utilisateur())->with('internat:id,nom')->orderByDesc('annee')->orderByDesc('id')->get()
            ->mapWithKeys(fn (Campagne $c) => [$c->id => $c->annee.' — '.$c->internat->nom]);
    }

    /**
     * Motif écrit exigé avant une décision. Il est saisi dans la fenêtre commune de l'interface, qui n'est
     * qu'un confort : c'est ici qu'il est réellement contrôlé.
     */
    protected function motifExige(?string $texte, int $minimum = 5, int $maximum = 1000, ?string $message = null): string
    {
        $texte = trim((string) $texte);

        if (mb_strlen($texte) < $minimum || mb_strlen($texte) > $maximum) {
            $message ??= "Indiquez un motif ({$minimum} caractères au moins).";
            $this->message($message, 'danger');

            throw ValidationException::withMessages(['motif' => $message]);
        }

        return $texte;
    }
}
