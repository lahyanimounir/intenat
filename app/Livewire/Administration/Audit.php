<?php

namespace App\Livewire\Administration;

use App\Livewire\PagePersonnel;
use App\Models\JournalAudit;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\WithPagination;

/**
 * Journal d'audit : qui a fait quoi, quand, et sur quelle ligne. Lecture
 * seule : ni l'application ni la base n'acceptent d'y modifier ou d'y
 * supprimer une entrée. Aucun mot de passe ni contenu de document n'y figure.
 */
#[Title('Journal d’audit')]
class Audit extends PagePersonnel
{
    use WithPagination;

    private const PERMISSION = 'audit.consulter';

    /** Famille d'actions : le préfixe avant le point (« paiement », « stagiaire »…). */
    #[Url(except: '')]
    public string $action = '';

    #[Url(except: '')]
    public string $entite = '';

    #[Url(except: '')]
    public string $du = '';

    #[Url(except: '')]
    public string $au = '';

    /** Entrée dont le détail est ouvert. */
    #[Locked]
    public ?int $ligne = null;

    public function mount(): void
    {
        $this->exiger(self::PERMISSION);
    }

    public function updated(string $propriete): void
    {
        if (in_array($propriete, ['action', 'entite', 'du', 'au'], true)) {
            $this->resetPage();
        }
    }

    public function effacerFiltres(): void
    {
        $this->reset('action', 'entite', 'du', 'au');
        $this->resetPage();
    }

    public function detailler(int $id): void
    {
        $this->exiger(self::PERMISSION);
        $this->ligne = JournalAudit::findOrFail($id)->id;
        $this->ouvrirDialogue('detail');
    }

    public function fermerDialogue(): void
    {
        $this->ligne = null;
        $this->dialogue = null;
    }

    private static function jour(string $valeur): ?Carbon
    {
        return $valeur === '' ? null : rescue(fn () => Carbon::createFromFormat('Y-m-d', $valeur)->startOfDay(), null, false);
    }

    public function render()
    {
        $this->exiger(self::PERMISSION);
        [$du, $au] = [self::jour($this->du), self::jour($this->au)];

        $entrees = JournalAudit::query()->with('utilisateur:id,nom,prenom', 'stagiaire:id,nom,prenom')
            ->when($this->action !== '', fn (Builder $q) => $q->where('action', 'like', addcslashes($this->action, '%_\\').'.%'))
            ->when($this->entite !== '', fn (Builder $q) => $q->where('entite', $this->entite))
            ->when($du, fn (Builder $q) => $q->where('date_heure', '>=', $du))
            ->when($au, fn (Builder $q) => $q->where('date_heure', '<', $au->copy()->addDay()))
            ->orderByDesc('id')->paginate(50);

        return view('livewire.administration.audit', [
            'entrees' => $entrees,
            'familles' => JournalAudit::query()->distinct()->orderBy('action')->pluck('action')->map(fn (string $a) => explode('.', $a)[0])->unique()->values()
                ->mapWithKeys(fn (string $f) => [$f => $f]),
            'entites' => JournalAudit::query()->distinct()->orderBy('entite')->pluck('entite')->mapWithKeys(fn (string $e) => [$e => $e]),
            'filtresActifs' => $this->action !== '' || $this->entite !== '' || $this->du !== '' || $this->au !== '',
            'detail' => $this->dialogue === 'detail' && $this->ligne !== null ? JournalAudit::with('utilisateur:id,nom,prenom', 'stagiaire:id,nom,prenom')->find($this->ligne) : null,
        ]);
    }
}
