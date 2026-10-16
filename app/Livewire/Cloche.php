<?php

namespace App\Livewire;

use App\Models\NotificationInterne;
use App\Models\Stagiaire;
use Illuminate\Database\Eloquent\Builder;
use Livewire\Component;

/** Cloche de l'en-tête : dernières notifications du compte connecté, et nombre de non lues. */
class Cloche extends Component
{
    /** Notifications du compte connecté — jamais celles d'un autre. */
    public static function duCompte(): Builder
    {
        $compte = auth('stagiaire')->user() ?? auth('web')->user();
        abort_if($compte === null, 403);

        return NotificationInterne::query()->where($compte instanceof Stagiaire ? 'stagiaire_id' : 'utilisateur_id', $compte->getKey());
    }

    public function lire(?int $id = null): void
    {
        self::duCompte()
            ->where('lue', false)
            ->when($id !== null, fn (Builder $q) => $q->whereKey($id))
            ->update(['lue' => true, 'date_lecture' => now()]);
    }

    /** Marque la notification comme lue, puis ouvre l'écran qu'elle désigne. */
    public function ouvrir(int $id): void
    {
        $notification = self::duCompte()->findOrFail($id);
        $this->lire($id);

        // Seuls les liens internes à l'application sont suivis.
        if (is_string($notification->lien) && str_starts_with($notification->lien, '/') && ! str_starts_with($notification->lien, '//')) {
            $this->redirect($notification->lien, navigate: true);
        }
    }

    public function render()
    {
        return view('livewire.cloche', [
            'notifications' => self::duCompte()->latest('date_envoi')->latest('id')->limit(6)->get(),
            'nonLues' => self::duCompte()->where('lue', false)->count(),
        ]);
    }
}
