@php
    use App\Enums\StatutMenu;
    use App\Enums\TypeRepas;
    use App\Support\Affichage\Format;

    $aujourdhui = now()->startOfDay();
@endphp
<div>
    <x-page.entete titre="Menus"
        description="Un menu est proposé, puis validé par le responsable restauration : seul un menu validé est diffusé aux stagiaires, qui réservent sur cette base.">
        <x-slot:actions>
            @if ($internats->count() > 1)
                <div class="w-56"><x-liste wire:model.live="internat" :options="$internats" vide="Tous les internats" aria-label="Internat" /></div>
            @endif
            @if ($gerer)
                <x-bouton variante="primaire" icone="plus" wire:click="proposer">Proposer un menu</x-bouton>
            @endif
        </x-slot:actions>
    </x-page.entete>

    <div class="mb-4 flex flex-wrap items-center justify-between gap-3">
        <div class="flex items-center gap-1">
            <x-bouton taille="icone" icone="chevron-left" aria-label="Semaine précédente" wire:click="decaler(-1)" />
            <x-bouton taille="sm" wire:click="cetteSemaine" :disabled="$semaine === 0">Cette semaine</x-bouton>
            <x-bouton taille="icone" icone="chevron-right" aria-label="Semaine suivante" wire:click="decaler(1)" />
            <p class="ml-3 text-base font-medium" aria-live="polite">Semaine du {{ Format::dateLongue($jours->first()) }}</p>
        </div>
        @if ($gerer && $aValider > 0)<x-badge ton="alerte">{{ $aValider }} menu(s) à valider cette semaine</x-badge>@endif
    </div>

    <div class="space-y-3" wire:loading.class="opacity-60" wire:target="decaler,cetteSemaine,internat">
        @foreach ($jours as $jour)
            @php
                $estAujourdhui = $jour->equalTo($aujourdhui);
                $passe = $jour->lessThan($aujourdhui);
            @endphp
            <section wire:key="jour-{{ $jour->toDateString() }}" @class(['rounded-lg border bg-surface shadow-xs', 'border-marine-200' => $estAujourdhui, 'border-bord' => ! $estAujourdhui])>
                <h2 class="flex items-center gap-2 border-b border-bord-doux px-5 py-2.5 text-base font-semibold">
                    {{ Format::jour($jour) }}
                    @if ($estAujourdhui)<x-badge ton="marine">Aujourd’hui</x-badge>@endif
                </h2>
                <div class="grid divide-y divide-bord-doux md:grid-cols-3 md:divide-x md:divide-y-0">
                    @foreach (TypeRepas::cases() as $type)
                        @php $repas = $parRepas->get($jour->toDateString().'|'.$type->value, collect()); @endphp
                        <div class="min-h-24 px-5 py-3">
                            <p class="mb-1.5 text-xs font-medium tracking-wide text-discret uppercase">{{ $type->libelle() }}</p>
                            @if ($repas->isEmpty())
                                @if ($gerer && ! $passe)
                                    <x-bouton variante="lien" taille="sm" icone="plus" wire:click="proposer('{{ $jour->toDateString() }}', '{{ $type->value }}')">Proposer</x-bouton>
                                @else
                                    <p class="text-sm text-discret">Aucun menu</p>
                                @endif
                            @else
                                <ul class="space-y-3">
                                    @foreach ($repas as $menu)
                                        <li wire:key="menu-{{ $menu->id }}">
                                            <p class="text-base whitespace-pre-line text-encre">{{ $menu->description }}</p>
                                            <div class="mt-1.5 flex flex-wrap items-center gap-x-2 gap-y-1">
                                                <x-badge :ton="$menu->statut === StatutMenu::Valide ? 'succes' : 'alerte'">{{ $menu->statut->libelle() }}</x-badge>
                                                <span class="text-xs text-discret">{{ preg_replace('/^Maison des Stagiaires /u', '', $menu->internat->nom) }} · {{ $menu->reserves }} réservation(s)</span>
                                            </div>
                                            @if ($gerer && $menu->statut === StatutMenu::Propose)
                                                <div class="mt-2 flex gap-1.5">
                                                    <x-bouton taille="sm" variante="primaire" icone="check" wire:click="valider({{ $menu->id }})" cible="valider({{ $menu->id }})">Valider</x-bouton>
                                                    <x-bouton taille="icone" variante="discret" icone="pencil" aria-label="Modifier le menu" wire:click="modifier({{ $menu->id }})" />
                                                    <x-bouton taille="icone" variante="discret" icone="trash" aria-label="Supprimer le menu"
                                                        x-on:click="$confirmer({ titre: 'Supprimer ce menu proposé ?', texte: {{ Js::from($menu->description) }}, libelle: 'Supprimer', destructif: true }).then(() => $wire.supprimer({{ $menu->id }}))" />
                                                </div>
                                            @endif
                                        </li>
                                    @endforeach
                                </ul>
                            @endif
                        </div>
                    @endforeach
                </div>
            </section>
        @endforeach
    </div>

    @if ($dialogue === 'menu')
        <x-dialogue :titre="$edite ? 'Modifier le menu' : 'Proposer un menu'"
            :description="$edite ? $edite->type_repas->libelle().' du '.Format::dateLongue($edite->date_repas).' — '.$edite->internat->nom : null">
            <form wire:submit="enregistrer" id="form-menu" novalidate class="space-y-4">
                @if (! $edite)
                    <div class="grid gap-4 sm:grid-cols-2">
                        <x-saisie libelle="Jour" type="date" min="{{ now()->toDateString() }}" wire:model="saisie.jour" requis />
                        <x-liste libelle="Repas" wire:model="saisie.repas" :options="TypeRepas::options()" requis />
                    </div>
                    @if ($internats->count() > 1)
                        <fieldset>
                            <legend class="mb-1.5 text-sm font-medium">Internats servis</legend>
                            <div class="flex flex-col gap-1.5">
                                @foreach ($internats as $id => $nom)
                                    <x-case wire:model="saisie.internats" value="{{ $id }}" :libelle="$nom" wire:key="internat-servi-{{ $id }}" />
                                @endforeach
                            </div>
                            @error('saisie.internats')<p role="alert" class="mt-1.5 text-sm text-danger">{{ $message }}</p>@enderror
                        </fieldset>
                    @endif
                @endif
                <x-zone-texte libelle="Composition du repas" wire:model="saisie.description" rows="3" maxlength="1000" requis autofocus />
            </form>
            <x-slot:pied>
                <x-bouton wire:click="fermerDialogue">Annuler</x-bouton>
                <x-bouton type="submit" form="form-menu" variante="primaire" cible="enregistrer">Enregistrer</x-bouton>
            </x-slot:pied>
        </x-dialogue>
    @endif
</div>
