<div wire:poll.60s>
    <x-menu largeur="w-88 max-w-[calc(100vw-1.5rem)]" class="p-0!">
        <x-slot:declencheur>
            <x-bouton variante="discret" taille="icone" class="relative" aria-haspopup="menu"
                :aria-label="$nonLues > 0 ? 'Notifications, '.$nonLues.' non lue(s)' : 'Notifications'">
                <x-icone nom="bell" />
                @if ($nonLues > 0)
                    <span aria-hidden="true" class="chiffres absolute -top-0.5 -right-0.5 flex h-4 min-w-4 items-center justify-center rounded-full bg-danger px-1 text-[10px] font-semibold text-white">{{ $nonLues > 99 ? '99+' : $nonLues }}</span>
                @endif
            </x-bouton>
        </x-slot:declencheur>

        <div class="flex items-center justify-between border-b border-bord-doux px-3.5 py-2.5">
            <p class="text-base font-semibold">Notifications</p>
            @if ($nonLues > 0)
                <x-bouton variante="lien" taille="sm" icone="check" wire:click="lire">Tout marquer comme lu</x-bouton>
            @endif
        </div>

        @if ($notifications->isEmpty())
            <p class="px-3.5 py-8 text-center text-sm text-discret">Aucune notification.</p>
        @else
            <ul class="max-h-96 overflow-y-auto">
                @foreach ($notifications as $notification)
                    <li wire:key="notification-{{ $notification->id }}">
                        <button type="button" role="menuitem" wire:click="ouvrir({{ $notification->id }})" x-on:click="ouvert = false"
                            class="flex w-full items-start gap-2.5 border-b border-bord-doux px-3.5 py-2.5 text-left last:border-0 hover:bg-marine-50 focus-visible:bg-marine-50">
                            <span aria-hidden="true" @class(['mt-1.5 size-1.5 shrink-0 rounded-full', 'bg-bleu' => ! $notification->lue, 'bg-transparent' => $notification->lue])></span>
                            <span class="min-w-0 flex-1">
                                <span @class(['block text-sm', 'font-medium' => ! $notification->lue])>{{ $notification->titre }}</span>
                                <span class="mt-0.5 line-clamp-2 block text-sm text-discret">{{ $notification->message }}</span>
                                <span class="mt-1 block text-xs text-discret">{{ \App\Support\Affichage\Format::dateHeure($notification->date_envoi) }}</span>
                            </span>
                        </button>
                    </li>
                @endforeach
            </ul>
        @endif

        <div class="border-t border-bord-doux p-1">
            <x-menu.element :href="url('/notifications')" class="justify-center text-sm text-bleu!">Voir toutes les notifications</x-menu.element>
        </div>
    </x-menu>
</div>
