<?php

namespace App\Livewire\Personnel;

use App\Domain\Catering\ListeDesRepas;
use App\Enums\TypeRepas;
use App\Livewire\PagePersonnel;
use Illuminate\Support\Carbon;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;

/**
 * Liste nominative J+1 : qui a réservé quel repas demain (ou un autre jour),
 * avec établissement, internat, régimes et allergies. Les lignes porteuses d'un
 * régime ou d'une allergie sont mises en évidence ; la liste s'imprime en PDF
 * avec le total par type de repas.
 */
#[Title('Liste J+1')]
class ListeRepasJ1 extends PagePersonnel
{
    private const CONSULTER = 'restauration.consulter';

    #[Url(except: '')]
    public string $jour = '';

    #[Url(except: '')]
    public string $internat = '';

    #[Url(except: '')]
    public string $repas = '';

    /** N'afficher que les lignes à surveiller (régime ou allergie). */
    #[Url(except: false)]
    public bool $attention = false;

    public function mount(): void
    {
        $this->exiger(self::CONSULTER);
        $this->jour = $this->date()->toDateString();
    }

    private function date(): Carbon
    {
        return rescue(fn () => Carbon::parse($this->jour ?: now()->addDay())->startOfDay(), now()->addDay()->startOfDay(), false);
    }

    public function choisir(string $jour): void
    {
        $this->jour = ($jour === 'demain' ? now()->addDay() : now())->toDateString();
    }

    /** @return array<string, string> paramètres de l'impression, alignés sur les filtres de l'écran */
    private function filtres(): array
    {
        return array_filter([
            'jour' => $this->date()->toDateString(),
            'internat' => $this->internat,
            'repas' => TypeRepas::tryFrom($this->repas)?->value,
            'attention' => $this->attention ? '1' : null,
        ]);
    }

    public function render(ListeDesRepas $liste)
    {
        $this->exiger(self::CONSULTER);

        if ($this->internat !== '') {
            $this->perimetre()->exigerInternat($this->utilisateur(), (int) $this->internat);
        }

        $date = $this->date();
        $toutes = $liste->lignes($this->utilisateur(), $date, $this->internat !== '' ? (int) $this->internat : null);
        $type = TypeRepas::tryFrom($this->repas);
        $lignes = $toutes
            ->when($type !== null, fn ($c) => $c->filter(fn (object $l) => $l->repas === $type))
            ->when($this->attention, fn ($c) => $c->where('attention', true))
            ->values();

        return view('livewire.personnel.liste-repas-j1', [
            'date' => $date,
            'lignes' => $lignes,
            // Les totaux par type de repas portent sur tout le jour : le filtre ne fait que choisir les lignes affichées.
            'totaux' => $liste->totaux($toutes),
            'internats' => $this->internatsDuPerimetre(),
            'impression' => route('restauration.liste-j1.imprimer', $this->filtres()),
        ]);
    }
}
