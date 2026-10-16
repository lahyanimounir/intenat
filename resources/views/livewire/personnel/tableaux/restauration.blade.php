@php use App\Support\Affichage\Format; @endphp
<div class="space-y-6">
    <div class="grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
        <x-stat libelle="Résidents hébergés" :valeur="$d['residents_heberges']" detail="Peuvent réserver leurs repas" />
        <x-stat libelle="Repas réservés ce mois" :valeur="Format::nombre($d['mois']['reserves'])" href="/restauration/statistiques" />
        <x-stat libelle="Repas servis ce mois" :valeur="Format::nombre($d['mois']['servis'])" :detail="$d['mois']['services_clos'].' service(s) clos'" href="/restauration/statistiques" />
        <x-stat libelle="Services avec écart" :valeur="$d['mois']['non_conformes']" detail="Servi différent du confirmé" href="/restauration/statistiques"
            :ton="$d['mois']['non_conformes'] > 0 ? 'alerte' : 'neutre'" />
    </div>

    <div class="grid gap-6 xl:grid-cols-[minmax(0,5fr)_minmax(0,7fr)]">
        @include('livewire.personnel.tableaux.a-traiter', ['lignes' => [
            ['Effectifs de demain à confirmer', $d['a_traiter']['effectifs_a_confirmer'], '/restauration/service?jour='.now()->addDay()->toDateString()],
            ['Services à clore', $d['a_traiter']['services_a_clore'], '/restauration/service'],
            ['Menus proposés à valider', $d['a_traiter']['menus_a_valider'], '/restauration/menus'],
            ['Réclamations de restauration', $d['a_traiter']['reclamations'], '/reclamations'],
        ]])

        <div class="space-y-6">
            @foreach ([
                ['Aujourd’hui', Format::jour(now()), $d['aujourdhui'], 'Aucun menu pour aujourd’hui', '/restauration/service'],
                ['Demain', 'Effectifs prévisionnels (J+1)', $d['demain'], 'Aucun menu pour demain', '/restauration/service?jour='.now()->addDay()->toDateString()],
            ] as [$intitule, $precision, $repas, $vide, $adresse])
                <x-carte>
                    <x-carte.entete :titre="$intitule" :description="$precision">
                        <x-slot:actions><x-bouton taille="sm" :href="$adresse">Service et effectifs</x-bouton></x-slot:actions>
                    </x-carte.entete>
                    @if ($repas === [])
                        <x-vide :titre="$vide" icone="utensils-crossed" class="py-8" />
                    @else
                        <ul>
                            @foreach ($repas as $r)
                                <li class="flex flex-wrap items-center gap-x-4 gap-y-1 border-b border-bord-doux px-5 py-3 last:border-0">
                                    <div class="min-w-0 flex-1 basis-56">
                                        <p class="text-base font-medium text-encre">{{ $r['type_repas']['libelle'] }} <span class="font-normal text-discret">· {{ $r['internat']['nom'] }}</span></p>
                                        <p class="truncate text-sm text-discret">{{ $r['description'] }}</p>
                                    </div>
                                    <dl class="chiffres flex gap-5 text-sm">
                                        @foreach (['Réservés' => $r['reserves'], 'Confirmés' => $r['confirmes'], 'Servis' => $r['servis']] as $libelle => $valeur)
                                            <div class="text-right">
                                                <dt class="text-xs text-discret">{{ $libelle }}</dt>
                                                <dd class="text-md font-semibold text-encre">{{ $valeur ?? '—' }}</dd>
                                            </div>
                                        @endforeach
                                    </dl>
                                    @if ($r['statut']['valeur'] === 'propose')
                                        <x-badge ton="alerte" point>{{ $r['statut']['libelle'] }}</x-badge>
                                    @elseif ($r['conforme'] === false)
                                        <x-badge ton="alerte">Écart</x-badge>
                                    @endif
                                </li>
                            @endforeach
                        </ul>
                    @endif
                </x-carte>
            @endforeach
        </div>
    </div>
</div>
