@php
    use App\Support\Affichage\Format;
    use App\Support\Affichage\Graphique;
    use App\Support\Affichage\Tons;
@endphp
<div class="space-y-6">
    <div class="grid gap-6 xl:grid-cols-[minmax(0,5fr)_minmax(0,7fr)]">
        @include('livewire.personnel.tableaux.a-traiter', ['lignes' => [
            ['Inscriptions en formation à vérifier', $inscriptionsAVerifier, '/inscriptions'],
            ['Campagnes prêtes à délibérer', $d['a_traiter']['deliberations'], '/admissions/classement'],
            ['Places libres avec candidats en attente', $d['a_traiter']['places_a_pourvoir'], '/admissions/liste-attente'],
            ['Mensualités non réglées', $d['a_traiter']['mensualites_impayees'], '/paiements?statut=en_attente,autorise,refuse'],
            ['Recours sur la sélection', $d['a_traiter']['recours'], '/reclamations'],
        ]])

        <x-carte>
            <x-carte.entete titre="Capacité des internats" />
            <x-carte.corps>
                @if ($d['occupation'] === [])
                    <x-vide titre="Aucun internat dans votre périmètre" class="py-8" />
                @else
                    <x-graphique.barres empile
                        :donnees="collect($d['occupation'])->map(fn ($o) => ['nom' => preg_replace('/^Maison des Stagiaires /u', '', $o['internat']['nom']), 'titre' => $o['internat']['nom']] + $o)"
                        :series="[
                            ['cle' => 'occupes', 'nom' => 'Occupés', 'couleur' => Graphique::MARINE],
                            ['cle' => 'disponibles', 'nom' => 'Disponibles', 'couleur' => Graphique::BLEU_CLAIR],
                            ['cle' => 'hors_service', 'nom' => 'Hors service', 'couleur' => Graphique::GRIS],
                        ]" />
                @endif
            </x-carte.corps>
        </x-carte>
    </div>

    <x-carte class="overflow-hidden">
        <x-carte.entete titre="Campagnes en cours" description="Avancement de l’instruction et des admissions, par internat.">
            <x-slot:actions><x-bouton taille="sm" href="/campagnes">Toutes les campagnes</x-bouton></x-slot:actions>
        </x-carte.entete>
        @if ($d['campagnes'] === [])
            <x-vide titre="Aucune campagne en cours" icone="calendar-range">
                <x-slot:action><x-bouton variante="primaire" href="/campagnes">Créer une campagne</x-bouton></x-slot:action>
            </x-vide>
        @else
            <div class="defilement-fin overflow-x-auto">
                <table class="w-full text-left text-sm">
                    <caption class="sr-only">Campagnes en cours</caption>
                    <thead class="bg-surface-2 text-xs text-discret">
                        <tr>
                            @foreach (['Internat', 'Statut', 'Instruction', 'Déposés', 'Complets', 'Places', 'Admis', 'Confirmés', 'En attente', 'Places libres'] as $i => $colonne)
                                <th scope="col" @class(['border-b border-bord px-4 py-2.5 font-medium whitespace-nowrap', 'text-right' => $i > 2])>{{ $colonne }}</th>
                            @endforeach
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($d['campagnes'] as $c)
                            <tr class="border-b border-bord-doux last:border-0 hover:bg-marine-50/60">
                                <th scope="row" class="px-4 py-2.5 font-medium">
                                    <a href="/campagnes/{{ $c['id'] }}" wire:navigate class="text-encre hover:text-bleu hover:underline">{{ $c['internat']['nom'] }}</a>
                                    <span class="block text-xs font-normal text-discret">{{ $c['annee'] }} · clôture le {{ Format::date($c['date_cloture']) }}</span>
                                </th>
                                <td class="px-4 py-2.5">
                                    @if ($c['phase'] === 'deliberee')
                                        <x-badge ton="succes" point>Délibérée</x-badge>
                                    @else
                                        <x-badge :ton="Tons::de($c['statut']['valeur'])" point>{{ $c['statut']['libelle'] }}</x-badge>
                                    @endif
                                </td>
                                <td class="px-4 py-2.5">
                                    <div class="flex items-center gap-2">
                                        <div class="h-1.5 w-20 overflow-hidden rounded-full bg-marine-100" role="img" aria-label="{{ $c['progression_instruction'] }} % des dossiers instruits">
                                            <div class="h-full rounded-full bg-marine" style="width: {{ (int) $c['progression_instruction'] }}%"></div>
                                        </div>
                                        <span class="chiffres text-xs whitespace-nowrap text-discret">{{ (int) $c['progression_instruction'] }} %</span>
                                    </div>
                                </td>
                                @foreach (['deposes', 'complets', 'places', 'admis', 'confirmes', 'en_attente'] as $cle)
                                    <td class="chiffres px-4 py-2.5 text-right">{{ Format::nombre($c[$cle]) }}</td>
                                @endforeach
                                <td class="chiffres px-4 py-2.5 text-right">
                                    @if ($c['places_libres'] === null)
                                        —
                                    @else
                                        <x-badge :ton="$c['places_libres'] > 0 ? 'alerte' : 'neutre'">{{ $c['places_libres'] }}</x-badge>
                                    @endif
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @endif
    </x-carte>
</div>
