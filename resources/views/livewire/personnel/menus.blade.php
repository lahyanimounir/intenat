@php
    use App\Enums\StatutMenu;
    use App\Enums\TypeRepas;
    use App\Support\Affichage\Format;

    $aujourdhui = now()->startOfDay();
@endphp
<div>
    <x-page.entete titre="Menus"
        description="Le responsable restauration compose les menus de la semaine, puis les transmet au chef de pôle, qui les vérifie et les valide. Seul un menu validé est diffusé aux stagiaires et se réserve.">
        <x-slot:actions>
            @if ($internats->count() > 1)
                <div class="w-56"><x-liste wire:model.live="internat" :options="$internats" vide="Tous les internats" aria-label="Internat" /></div>
            @endif
            <x-bouton icone="rotate-ccw-clock" wire:click="voirHistorique">Historique des validations</x-bouton>
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
        <div class="flex flex-wrap items-center gap-2">
            @if ($valides > 0)<x-badge ton="succes">{{ $valides }} validé(s)</x-badge>@endif
            @if ($enAttente > 0)<x-badge ton="marine">{{ $enAttente }} en attente de validation</x-badge>@endif
            @if ($aCorriger > 0)<x-badge ton="danger">{{ $aCorriger }} à corriger</x-badge>@endif
            @if ($aTransmettre > 0)<x-badge ton="alerte">{{ $aTransmettre }} à transmettre</x-badge>@endif
            @if ($gerer && $aTransmettre > 0)
                <x-bouton taille="sm" variante="primaire" icone="send" cible="transmettreSemaine"
                    x-on:click="$confirmer({ titre: 'Transmettre la semaine au chef de pôle ?', texte: 'Les {{ $aTransmettre }} menu(s) proposés de cette semaine lui seront soumis pour vérification et validation. Ils ne seront réservables qu’une fois validés.', libelle: 'Transmettre' }).then(() => $wire.transmettreSemaine())">
                    Transmettre la semaine au chef de pôle
                </x-bouton>
            @endif
            @if ($valider && $enAttente > 0)
                <x-bouton taille="sm" icone="undo-2" wire:click="preparerRenvoi('semaine')">Renvoyer pour correction</x-bouton>
                <x-bouton taille="sm" variante="primaire" icone="check" cible="validerSemaine"
                    x-on:click="$confirmer({ titre: 'Valider la semaine ?', texte: 'Les {{ $enAttente }} menu(s) transmis seront diffusés aux stagiaires et ouverts à la réservation. Un menu validé ne se modifie plus.', libelle: 'Valider la semaine' }).then(() => $wire.validerSemaine())">
                    Valider la semaine
                </x-bouton>
            @endif
        </div>
    </div>

    <div class="space-y-3" wire:loading.class="opacity-60" wire:target="decaler,cetteSemaine,internat">
        @foreach ($jours as $jour)
            @php
                $estAujourdhui = $jour->equalTo($aujourdhui);
                $passe = $jour->lessThan($aujourdhui);
            @endphp
            <section wire:key="jour-{{ $jour->toDateString() }}" @class(['rounded-lg border bg-surface shadow-xs', 'border-marine-200' => $estAujourdhui, 'border-bord' => ! $estAujourdhui])>
                <div class="flex flex-wrap items-center justify-between gap-2 border-b border-bord-doux px-5 py-2.5">
                    <h2 class="flex items-center gap-2 text-base font-semibold">
                        {{ Format::jour($jour) }}
                        @if ($estAujourdhui)<x-badge ton="marine">Aujourd’hui</x-badge>@endif
                    </h2>
                    @if ($valider && ! $passe && in_array($jour->toDateString(), $joursEnAttente, true))
                        <x-bouton taille="sm" variante="discret" icone="undo-2" wire:click="preparerRenvoi('journee', '{{ $jour->toDateString() }}')">Renvoyer la journée pour correction</x-bouton>
                    @endif
                </div>
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
                                                @if ($menu->statut === StatutMenu::Valide)
                                                    <x-badge ton="succes">Validé</x-badge>
                                                @elseif ($menu->transmis_le)
                                                    <x-badge ton="marine">Transmis · en attente de validation</x-badge>
                                                @elseif ($menu->aCorriger())
                                                    <x-badge ton="danger">À corriger · à retransmettre</x-badge>
                                                @else
                                                    <x-badge ton="alerte">En préparation · non transmis</x-badge>
                                                @endif
                                                <span class="text-xs text-discret">{{ preg_replace('/^Maison des Stagiaires /u', '', $menu->internat->nom) }} · {{ $menu->reserves }} réservation(s)</span>
                                            </div>
                                            @if ($menu->statut === StatutMenu::Valide && $menu->validateur)
                                                <p class="mt-1 text-xs text-discret">Validé par {{ $menu->validateur->nomComplet() }} le {{ Format::dateHeure($menu->date_validation) }}</p>
                                            @endif
                                            @if ($menu->aCorriger())
                                                <div class="mt-2 rounded-md border border-danger-bord bg-danger-fond px-3 py-2 text-sm">
                                                    <p class="text-xs text-discret">Correction demandée par {{ $menu->auteurCorrection?->nomComplet() ?? '—' }} le {{ Format::dateHeure($menu->correction_le) }}</p>
                                                    <p class="mt-0.5 whitespace-pre-line text-encre">{{ $menu->correction_motif }}</p>
                                                </div>
                                            @endif
                                            @if ($valider && $menu->enAttenteDeValidation() && ! $passe)
                                                <div class="mt-2 flex flex-wrap gap-1.5">
                                                    <x-bouton taille="sm" icone="check" wire:click="valider({{ $menu->id }})" cible="valider({{ $menu->id }})">Valider ce menu</x-bouton>
                                                    <x-bouton taille="sm" variante="discret" icone="undo-2" wire:click="preparerRenvoi('menu', '{{ $menu->id }}')">Renvoyer pour correction</x-bouton>
                                                </div>
                                            @endif
                                            @if ($gerer && $menu->statut === StatutMenu::Propose)
                                                <div class="mt-2 flex gap-1.5">
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

    @if ($dialogue === 'renvoi' && $renvoi)
        <x-dialogue :titre="$renvoi['titre']" :description="$renvoi['description']">
            <form wire:submit="confirmerRenvoi" id="form-renvoi" novalidate class="space-y-4">
                @if ($renvoi['internats']->isNotEmpty())
                    <x-liste libelle="Internat concerné" wire:model="renvoiInternat" :options="$renvoi['internats']" vide="Choisir l’internat…" requis
                        aide="Plusieurs internats ont des menus en attente ce jour-là : seuls ceux de l’internat choisi sont renvoyés." />
                @endif
                <x-zone-texte libelle="Modifications demandées" wire:model="commentaire" rows="4" maxlength="1000" requis autofocus
                    aide="Le responsable restauration est prévenu et voit ce commentaire sur les menus concernés. Il est conservé dans l’historique des validations." />
            </form>
            <x-slot:pied>
                <x-bouton wire:click="fermerDialogue">Annuler</x-bouton>
                <x-bouton type="submit" form="form-renvoi" variante="primaire" icone="undo-2" cible="confirmerRenvoi">Renvoyer pour correction</x-bouton>
            </x-slot:pied>
        </x-dialogue>
    @endif

    @if ($dialogue === 'historique')
        <x-dialogue titre="Historique des validations" description="Transmissions par la restauration, validations et renvois par le chef de pôle, du plus récent au plus ancien.">
            @if ($historique->isEmpty())
                <x-vide titre="Aucune validation enregistrée" class="py-8!">Les transmissions et validations des menus apparaîtront ici.</x-vide>
            @else
                <ol class="space-y-3 border-l border-bord pl-4">
                    @foreach ($historique as $trace)
                        @php
                            $details = $trace->details ?? [];
                            $semaineDu = isset($details['semaine']) ? Format::dateLongue($details['semaine']) : null;
                            $evenement = match ($trace->action) {
                                'menu.transmission_semaine' => 'Semaine transmise au chef de pôle',
                                'menu.validation_semaine' => 'Semaine validée',
                                'menu.renvoi_semaine' => 'Semaine renvoyée pour correction',
                                'menu.renvoi_journee' => 'Journée renvoyée pour correction',
                                'menu.renvoi' => 'Repas renvoyé pour correction',
                                default => 'Menu validé',
                            };
                            if (isset($details['jour'])) {
                                $semaineDu = null;
                            }
                        @endphp
                        <li wire:key="trace-{{ $trace->id }}">
                            <p class="text-sm font-medium text-encre">
                                {{ $evenement }}
                                @if ($semaineDu) — semaine du {{ $semaineDu }}@endif
                                @if (isset($details['jour'])) — {{ Format::jour($details['jour']) }}@endif
                                @if (isset($details['menus'])) · {{ $details['menus'] }} menu(s)@endif
                            </p>
                            <p class="text-xs text-discret">
                                {{ Format::dateHeure($trace->date_heure) }} · {{ $trace->utilisateur?->nomComplet() ?? '—' }}
                                @if ($trace->entite === 'internat' && $internats->has($trace->entite_id)) · {{ $internats->get($trace->entite_id) }}@endif
                                @if ($trace->entite === 'menu' && isset($details['date'])) · {{ TypeRepas::tryFrom((string) ($details['repas'] ?? ''))?->libelle() }} du {{ Format::dateLongue($details['date']) }}@endif
                            </p>
                            @if (! empty($details['motif']))
                                <p class="mt-1 rounded-md border border-bord bg-surface-2 px-3 py-2 text-sm whitespace-pre-line text-encre-2">{{ $details['motif'] }}</p>
                            @endif
                        </li>
                    @endforeach
                </ol>
            @endif
            <x-slot:pied><x-bouton wire:click="fermerDialogue">Fermer</x-bouton></x-slot:pied>
        </x-dialogue>
    @endif
</div>
