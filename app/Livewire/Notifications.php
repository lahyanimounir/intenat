<?php

namespace App\Livewire;

use Illuminate\Database\Eloquent\Builder;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

/** Toutes les notifications du compte connecté, personnel ou stagiaire. */
#[Layout('layouts.app')]
#[Title('Notifications')]
class Notifications extends Component
{
    use WithPagination;

    #[Url(as: 'non-lues', except: false)]
    public bool $nonLues = false;

    public function updatedNonLues(): void
    {
        $this->resetPage();
    }

    public function lire(?int $id = null): void
    {
        Cloche::duCompte()
            ->where('lue', false)
            ->when($id !== null, fn (Builder $q) => $q->whereKey($id))
            ->update(['lue' => true, 'date_lecture' => now()]);
    }

    public function ouvrir(int $id): void
    {
        $notification = Cloche::duCompte()->findOrFail($id);
        $this->lire($id);

        // Seuls les liens internes à l'application sont suivis.
        if (is_string($notification->lien) && str_starts_with($notification->lien, '/') && ! str_starts_with($notification->lien, '//')) {
            $this->redirect($notification->lien, navigate: true);
        }
    }

    public function render()
    {
        return view('livewire.notifications', [
            'notifications' => Cloche::duCompte()
                ->when($this->nonLues, fn (Builder $q) => $q->where('lue', false))
                ->latest('date_envoi')->latest('id')->paginate(20),
            'total' => Cloche::duCompte()->where('lue', false)->count(),
        ]);
    }
}
