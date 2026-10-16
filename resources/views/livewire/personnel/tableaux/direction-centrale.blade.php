@php
    use App\Support\Affichage\Format;
    use App\Support\Affichage\Graphique;

    $n = $d['national'];
    $regions = collect($d['regions']);
@endphp
<div class="space-y-6">
    <div class="grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
        <x-stat libelle="Capacité nationale" :valeur="Format::nombre($n['lits'])" :detail="$regions->count().' région(s)'" />
        <x-stat libelle="Lits occupés" :valeur="Format::nombre($n['occupes'])" :detail="$n['lits'] > 0 ? Format::pourcentage(round($n['occupes'] * 100 / $n['lits'], 1)).' de la capacité' : null" />
        <x-stat libelle="Dossiers déposés" :valeur="Format::nombre($n['deposes'])" detail="Campagnes non archivées" />
        <x-stat libelle="Admis" :valeur="Format::nombre($n['admis'])" :detail="Format::nombre($n['en_attente']).' en liste d’attente'" />
    </div>

    <div class="grid gap-6 xl:grid-cols-2">
        <x-carte>
            <x-carte.entete titre="Comparaison régionale" description="Capacité et occupation par direction régionale." />
            <x-carte.corps>
                @if ($regions->isEmpty())
                    <x-vide titre="Aucune région" class="py-8" />
                @else
                    <x-graphique.barres
                        :donnees="$regions->map(fn ($r) => ['nom' => $r['code'], 'titre' => $r['nom'], 'lits' => $r['lits'], 'occupes' => $r['occupes']])"
                        :series="[['cle' => 'lits', 'nom' => 'Capacité', 'couleur' => Graphique::BLEU_CLAIR], ['cle' => 'occupes', 'nom' => 'Occupés', 'couleur' => Graphique::MARINE]]" />
                @endif
            </x-carte.corps>
        </x-carte>
        <x-carte>
            <x-carte.entete titre="Historique des campagnes" description="Dossiers déposés et admis par année de formation." />
            <x-carte.corps>
                @if ($d['historique'] === [])
                    <x-vide titre="Aucun dossier déposé" class="py-8" />
                @else
                    <x-graphique.barres
                        :donnees="collect($d['historique'])->map(fn ($h) => ['nom' => $h['annee'], 'deposes' => $h['deposes'], 'admis' => $h['admis']])"
                        :series="[['cle' => 'deposes', 'nom' => 'Déposés', 'couleur' => Graphique::BLEU_CLAIR], ['cle' => 'admis', 'nom' => 'Admis', 'couleur' => Graphique::MARINE]]" />
                @endif
            </x-carte.corps>
        </x-carte>
    </div>

    <x-carte class="overflow-hidden">
        <x-carte.entete titre="Régions" description="Synthèse des admissions et de l’occupation." />
        <div class="defilement-fin overflow-x-auto">
            <table class="w-full text-left text-sm">
                <caption class="sr-only">Indicateurs par région</caption>
                <thead class="bg-surface-2 text-xs text-discret">
                    <tr>
                        @foreach (['Région', 'Internats', 'Lits', 'Occupés', 'Taux', 'Déposés', 'Admis', 'Liste d’attente'] as $i => $colonne)
                            <th scope="col" @class(['border-b border-bord px-4 py-2.5 font-medium whitespace-nowrap', 'text-right' => $i > 0])>{{ $colonne }}</th>
                        @endforeach
                    </tr>
                </thead>
                <tbody>
                    @foreach ($regions as $r)
                        <tr class="border-b border-bord-doux last:border-0">
                            <th scope="row" class="px-4 py-2.5 font-medium">{{ $r['nom'] }} <span class="ml-1 font-normal text-discret">{{ $r['code'] }}</span></th>
                            @foreach (['internats', 'lits', 'occupes'] as $cle)
                                <td class="chiffres px-4 py-2.5 text-right">{{ Format::nombre($r[$cle]) }}</td>
                            @endforeach
                            <td class="chiffres px-4 py-2.5 text-right">{{ Format::pourcentage($r['taux']) }}</td>
                            @foreach (['deposes', 'admis', 'en_attente'] as $cle)
                                <td class="chiffres px-4 py-2.5 text-right">{{ Format::nombre($r[$cle]) }}</td>
                            @endforeach
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    </x-carte>

    <x-carte>
        <x-carte.entete titre="Résidents hébergés" description="Effectif national à la fin de chaque mois." />
        <x-carte.corps>
            <x-graphique.courbe serie="Résidents"
                :donnees="collect($d['tendance'])->map(fn ($t) => ['nom' => preg_replace('/ \d{4}$/', '', Format::mois($t['mois'])), 'titre' => Format::mois($t['mois']), 'valeur' => $t['residents']])" />
        </x-carte.corps>
    </x-carte>
</div>
