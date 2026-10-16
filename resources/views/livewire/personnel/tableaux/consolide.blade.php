@php
    use App\Support\Affichage\Format;

    $etablissements = collect($d['etablissements']);
    $lits = $etablissements->sum('lits');
    $occupes = $etablissements->sum('occupes');
@endphp
{{-- Vue consolidée du directeur de complexe et de la direction régionale. --}}
<div class="space-y-6">
    <div class="grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
        <x-stat libelle="Capacité" :valeur="Format::nombre($lits)" :detail="$etablissements->count().' établissement(s)'" />
        <x-stat libelle="Lits occupés" :valeur="Format::nombre($occupes)" :detail="$lits > 0 ? Format::pourcentage(round($occupes * 100 / $lits, 1)).' de la capacité' : null" />
        <x-stat libelle="Dossiers déposés" :valeur="Format::nombre($etablissements->sum('deposes'))" :detail="Format::nombre($etablissements->sum('admis')).' admis'" />
    </div>

    <x-carte class="overflow-hidden">
        <x-carte.entete :titre="$intitule" description="Capacité, occupation et admissions des campagnes non archivées." />
        @if ($etablissements->isEmpty())
            <x-vide titre="Aucun établissement dans votre périmètre" />
        @else
            <div class="defilement-fin overflow-x-auto">
                <table class="w-full text-left text-sm">
                    <caption class="sr-only">Indicateurs par établissement</caption>
                    <thead class="bg-surface-2 text-xs text-discret">
                        <tr>
                            @foreach (['Établissement', 'Lits', 'Occupés', 'Taux', 'Dossiers déposés', 'Admis', 'Confirmés', 'Liste d’attente'] as $i => $colonne)
                                <th scope="col" @class(['border-b border-bord px-4 py-2.5 font-medium whitespace-nowrap', 'text-right' => $i > 0])>{{ $colonne }}</th>
                            @endforeach
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($etablissements as $e)
                            <tr class="border-b border-bord-doux last:border-0">
                                <th scope="row" class="px-4 py-2.5 font-medium text-encre">{{ $e['nom_efp'] }} <span class="ml-1 font-normal text-discret">{{ $e['code_efp'] }}</span></th>
                                <td class="chiffres px-4 py-2.5 text-right">{{ Format::nombre($e['lits']) }}</td>
                                <td class="chiffres px-4 py-2.5 text-right">{{ Format::nombre($e['occupes']) }}</td>
                                <td class="chiffres px-4 py-2.5 text-right"><x-badge :ton="$e['taux'] >= 95 ? 'alerte' : 'neutre'">{{ Format::pourcentage($e['taux']) }}</x-badge></td>
                                @foreach (['deposes', 'admis', 'confirmes', 'en_attente'] as $cle)
                                    <td class="chiffres px-4 py-2.5 text-right">{{ Format::nombre($e[$cle]) }}</td>
                                @endforeach
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @endif
    </x-carte>

    <x-carte>
        <x-carte.entete titre="Résidents hébergés" description="Effectif à la fin de chaque mois, sur six mois." />
        <x-carte.corps>
            <x-graphique.courbe serie="Résidents"
                :donnees="collect($d['tendance'])->map(fn ($t) => ['nom' => preg_replace('/ \d{4}$/', '', Format::mois($t['mois'])), 'titre' => Format::mois($t['mois']), 'valeur' => $t['residents']])" />
        </x-carte.corps>
    </x-carte>
</div>
