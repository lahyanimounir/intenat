@php
    use App\Support\Affichage\Format;
    use App\Support\Affichage\Graphique;
@endphp
<div>
    <x-page.entete titre="Statistiques de restauration"
        description="Repas réservés, confirmés et servis sur le mois, et conformité des services. La gestion des stocks et des coûts n’entre pas dans le périmètre de l’application.">
        <x-slot:actions>
            <label for="mois" class="text-sm text-discret">Mois</label>
            <div class="w-44"><x-saisie id="mois" type="month" wire:model.live="mois" /></div>
        </x-slot:actions>
    </x-page.entete>

    <div class="space-y-6" wire:loading.class="opacity-60" wire:target="mois">
        <div class="grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
            <x-stat libelle="Repas réservés" :valeur="Format::nombre($total['reserves'])" :detail="$total['repas'].' menu(s) validé(s)'" />
            <x-stat libelle="Repas confirmés (J+1)" :valeur="Format::nombre($total['confirmes'])" />
            <x-stat libelle="Repas servis" :valeur="Format::nombre($total['servis'])" :detail="$total['services_clos'].' service(s) clos'" />
            <x-stat libelle="Services conformes" detail="Servi égal au confirmé"
                :valeur="$total['services_clos'] > 0 ? $total['conformes'].' / '.$total['services_clos'] : '—'"
                :ton="$total['services_clos'] > $total['conformes'] ? 'alerte' : 'neutre'" />
        </div>

        <x-carte>
            <x-carte.entete titre="Par jour" :description="'Réservés, confirmés et servis — '.Format::mois($periode).'.'" />
            <x-carte.corps>
                @if ($par_jour->isEmpty())
                    <x-vide titre="Aucun menu validé sur ce mois" class="py-8" />
                @else
                    <x-graphique.barres :hauteur="260"
                        :donnees="$par_jour->map(fn ($j) => ['nom' => $j['jour']->format('d'), 'titre' => Format::jour($j['jour']), 'reserves' => $j['reserves'], 'confirmes' => $j['confirmes'], 'servis' => $j['servis']])"
                        :series="[
                            ['cle' => 'reserves', 'nom' => 'Réservés', 'couleur' => Graphique::PALE],
                            ['cle' => 'confirmes', 'nom' => 'Confirmés', 'couleur' => Graphique::BLEU],
                            ['cle' => 'servis', 'nom' => 'Servis', 'couleur' => Graphique::MARINE],
                        ]" />
                @endif
            </x-carte.corps>
        </x-carte>

        <div class="grid items-start gap-6 xl:grid-cols-2">
            <x-carte>
                <x-carte.entete titre="Par repas" />
                @if ($par_type->isEmpty())
                    <x-vide titre="Aucune donnée" class="py-8" />
                @else
                    <div class="overflow-x-auto">
                        <table class="w-full text-left text-sm">
                            <caption class="sr-only">Statistiques par type de repas</caption>
                            <thead class="bg-surface-2 text-xs text-discret">
                                <tr>
                                    @foreach (['Repas', 'Menus', 'Réservés', 'Confirmés', 'Servis', 'Conformes'] as $i => $titre)
                                        <th scope="col" @class(['border-b border-bord px-5 py-2.5 font-medium', 'text-right' => $i > 0])>{{ $titre }}</th>
                                    @endforeach
                                </tr>
                            </thead>
                            <tbody>
                                @foreach ($par_type as $ligneType)
                                    <tr class="border-b border-bord-doux last:border-0">
                                        <th scope="row" class="px-5 py-2.5 font-medium">{{ $ligneType['type_repas']->libelle() }}</th>
                                        @foreach (['repas', 'reserves', 'confirmes', 'servis'] as $cle)
                                            <td class="chiffres px-5 py-2.5 text-right">{{ Format::nombre($ligneType[$cle]) }}</td>
                                        @endforeach
                                        <td class="chiffres px-5 py-2.5 text-right">{{ $ligneType['conformes'] }} / {{ $ligneType['services_clos'] }}</td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                @endif
            </x-carte>

            <x-carte>
                <x-carte.entete titre="Régimes particuliers" description="Régimes déclarés par les résidents actuellement hébergés." />
                @if ($regimes->isEmpty())
                    <x-vide titre="Aucun régime déclaré" class="py-8" />
                @else
                    <ul class="flex flex-wrap gap-2 border-b border-bord-doux px-5 py-3">
                        @foreach ($regimes as $regime)
                            <li><x-badge ton="info">{{ $regime['type']->libelle() }} : {{ $regime['total'] }}</x-badge></li>
                        @endforeach
                    </ul>
                    @if ($nominatif)
                        <ul>
                            @foreach ($declares as $declare)
                                <li wire:key="regime-{{ $declare->id }}" class="flex flex-wrap items-center gap-3 border-b border-bord-doux px-5 py-2.5 last:border-0">
                                    <span class="min-w-0 flex-1 text-base">
                                        {{ $declare->stagiaire->nom }} {{ $declare->stagiaire->prenom }}
                                        <span class="block text-sm text-discret">{{ $declare->type->libelle() }}@if ($declare->precision_regime) — {{ $declare->precision_regime }}@endif</span>
                                    </span>
                                    @if ($declare->justificatif_fichier)
                                        <x-bouton taille="sm" variante="discret" icone="eye" wire:click="voirJustificatif({{ $declare->id }})">Justificatif</x-bouton>
                                    @endif
                                </li>
                            @endforeach
                        </ul>
                    @endif
                @endif
            </x-carte>
        </div>
    </div>

    @if ($dialogue === 'justificatif' && $ouvert)
        <x-apercu :chemin="route('restauration.regimes.justificatif', $ouvert)" :titre="'Justificatif — '.$ouvert->stagiaire->nom.' '.$ouvert->stagiaire->prenom" />
    @endif
</div>
