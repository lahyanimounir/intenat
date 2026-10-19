@php
    use App\Enums\TypeRepas;
    use App\Support\Affichage\Format;

    $demain = now()->addDay()->toDateString();
    $aujourdhui = now()->toDateString();
@endphp
<div>
    <x-page.entete titre="Liste J+1"
        description="Liste nominative des repas réservés, établie sur les réservations réelles : stagiaire, établissement, internat, repas, régimes et allergies. Les lignes à surveiller sont mises en évidence.">
        <x-slot:actions>
            <x-bouton variante="primaire" icone="printer" :href="$impression" :navigation="false" target="_blank">Imprimer la liste J+1</x-bouton>
        </x-slot:actions>
    </x-page.entete>

    <div class="mb-4 flex flex-wrap items-center gap-2">
        <x-bouton taille="sm" :variante="$date->toDateString() === $aujourdhui ? 'primaire' : 'secondaire'" wire:click="choisir('aujourdhui')">Aujourd’hui</x-bouton>
        <x-bouton taille="sm" :variante="$date->toDateString() === $demain ? 'primaire' : 'secondaire'" wire:click="choisir('demain')">Demain (J+1)</x-bouton>
        <div class="w-40"><x-saisie type="date" taille="sm" aria-label="Autre jour" wire:model.live="jour" /></div>
        <p class="ml-2 text-base font-medium" aria-live="polite">{{ Format::jour($date) }}</p>
    </div>

    <dl class="mb-4 grid gap-3 sm:grid-cols-3">
        @foreach ($totaux as $total)
            <div class="rounded-lg border border-bord bg-surface px-4 py-3 shadow-xs" wire:key="total-{{ $total['repas']->value }}">
                <dt class="text-sm text-discret">{{ $total['repas']->libelle() }}</dt>
                <dd class="mt-0.5 flex items-baseline gap-2">
                    <span class="chiffres text-2xl font-semibold">{{ $total['total'] }}</span>
                    <span class="text-sm text-discret">réservation(s)</span>
                    @if ($total['attention'] > 0)<x-badge ton="alerte">{{ $total['attention'] }} à surveiller</x-badge>@endif
                </dd>
            </div>
        @endforeach
    </dl>

    <x-carte>
        <x-carte.entete :titre="$lignes->count().' repas réservé(s)'">
            <x-slot:actions>
                <div class="flex flex-wrap items-center gap-2">
                    <x-filtre libelle="Repas" wire:model.live="repas" :options="TypeRepas::options()" tous="tous" :actif="$repas !== ''" />
                    @if ($internats->count() > 1)
                        <x-filtre libelle="Internat" wire:model.live="internat" :options="$internats->all()" tous="tous" :actif="$internat !== ''" />
                    @endif
                    <x-case wire:model.live="attention" libelle="Régime ou allergie uniquement" />
                </div>
            </x-slot:actions>
        </x-carte.entete>

        @if ($lignes->isEmpty())
            <x-vide titre="Aucune réservation" icone="utensils-crossed">Aucun repas réservé ne correspond à ces critères pour ce jour.</x-vide>
        @else
            <div class="overflow-x-auto" wire:loading.class="opacity-60" wire:target="choisir,jour,internat,repas,attention">
                <table class="w-full text-left text-sm">
                    <thead class="border-b border-bord bg-surface-2 text-xs text-discret uppercase">
                        <tr>
                            <th class="px-4 py-2.5 font-medium">Nom</th>
                            <th class="px-4 py-2.5 font-medium">Prénom</th>
                            <th class="px-4 py-2.5 font-medium">CEF</th>
                            <th class="px-4 py-2.5 font-medium">Établissement</th>
                            <th class="px-4 py-2.5 font-medium">Internat</th>
                            <th class="px-4 py-2.5 font-medium">Date</th>
                            <th class="px-4 py-2.5 font-medium">Repas</th>
                            <th class="px-4 py-2.5 font-medium">Régime alimentaire</th>
                            <th class="px-4 py-2.5 font-medium">Allergie signalée</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-bord-doux">
                        @foreach ($lignes as $ligne)
                            <tr wire:key="ligne-{{ $ligne->reservation }}" @class(['bg-alerte-fond' => $ligne->attention])>
                                <td class="px-4 py-2 font-medium">
                                    @if ($ligne->attention)<x-icone nom="triangle-alert" class="mr-1 inline text-alerte" />@endif{{ $ligne->nom }}
                                </td>
                                <td class="px-4 py-2">{{ $ligne->prenom }}</td>
                                <td class="chiffres px-4 py-2">{{ $ligne->cef }}</td>
                                <td class="px-4 py-2">{{ $ligne->etablissement }}</td>
                                <td class="px-4 py-2">{{ preg_replace('/^Maison des Stagiaires /u', '', $ligne->internat) }}</td>
                                <td class="px-4 py-2 whitespace-nowrap">{{ $ligne->date->format('d/m/Y') }}</td>
                                <td class="px-4 py-2 whitespace-nowrap">{{ $ligne->repas->libelle() }}</td>
                                <td @class(['px-4 py-2', 'text-discret' => $ligne->sansRegime])>{{ $ligne->regimes }}</td>
                                <td @class(['px-4 py-2', 'font-medium text-alerte' => $ligne->allergie, 'text-discret' => ! $ligne->allergie])>{{ $ligne->allergie ?: 'Aucune' }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @endif
    </x-carte>
</div>
