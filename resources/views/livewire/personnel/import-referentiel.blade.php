@php
    use App\Enums\StatutImport;
    use App\Support\Affichage\Format;

    $anomalies = collect($rapport['anomalies'] ?? []);
    $apercu = collect($rapport['apercu'] ?? []);
    $gravites = ['erreur' => ['Erreur', 'danger'], 'avertissement' => ['Avertissement', 'alerte'], 'information' => ['Information', 'neutre']];
    $etatsLigne = ['nouveau' => ['À créer', 'succes'], 'existant' => ['Déjà présent', 'neutre'], 'erreur' => ['En erreur', 'danger'], 'hors_internat' => ['Autre internat', 'alerte']];
@endphp
<div>
    <x-page.entete titre="Import du référentiel" :fil="[['Plan d’occupation', url('/hebergement/plan')], ['Import du référentiel', null]]"
        description="Importez les bâtiments, chambres et lits de votre internat depuis un fichier CSV. Le fichier est d’abord analysé : rien n’est écrit tant que vous n’avez pas confirmé.">
        <x-slot:actions>
            <x-bouton icone="download" :href="route('hebergement.import.modele')" :navigation="false">Modèle CSV</x-bouton>
        </x-slot:actions>
    </x-page.entete>

    <div class="grid items-start gap-6 xl:grid-cols-[minmax(0,3fr)_minmax(0,2fr)]">
        <div class="space-y-6">
            @if ($analyse === null)
                <x-carte>
                    <x-carte.entete titre="1. Analyser un fichier" :description="$choisi ? 'Internat visé : '.$choisi->nom.'. Il vient de votre compte, jamais du fichier.' : null" />
                    <x-carte.corps>
                        <form wire:submit="analyser" novalidate class="space-y-4">
                            @if ($internats->count() > 1)
                                <x-liste libelle="Internat" wire:model.live="internat" :options="$internats" requis />
                            @endif
                            <x-fichier wire:model="fichier" :fichier="$fichier" accept=".csv,.txt" libelle="Choisir le fichier CSV" indication="CSV · 2 Mo maximum · UTF-8 ou Windows-1252, séparateur ; ou ," />
                            <div class="flex justify-end">
                                <x-bouton type="submit" variante="primaire" icone="search" cible="analyser,fichier">Analyser le fichier</x-bouton>
                            </div>
                        </form>
                    </x-carte.corps>
                </x-carte>
            @else
                <x-carte>
                    <x-carte.entete :titre="$analyse->nom_original" :description="$analyse->internat->nom.' · analysé le '.Format::dateHeure($analyse->created_at)">
                        <x-slot:actions>
                            <x-statut :etat="$analyse->statut" />
                            <x-bouton taille="sm" variante="discret" icone="x" wire:click="fermerAnalyse">Fermer</x-bouton>
                        </x-slot:actions>
                    </x-carte.entete>
                    <x-carte.corps class="space-y-5">
                        <div class="grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
                            <x-stat libelle="Lignes lues" :valeur="Format::nombre($analyse->total_lignes)" />
                            <x-stat libelle="Lits à créer" :valeur="Format::nombre($analyse->lignes_valides)" ton="succes"
                                :detail="isset($rapport['synthese']) ? $rapport['synthese']['batiments'].' bâtiment(s), '.$rapport['synthese']['chambres'].' chambre(s)' : null" />
                            <x-stat libelle="Lignes en erreur" :valeur="Format::nombre($analyse->lignes_erreur)" :ton="$analyse->lignes_erreur > 0 ? 'danger' : 'neutre'" />
                            <x-stat libelle="Déjà présentes" :valeur="Format::nombre($rapport['lignes_existantes'] ?? 0)"
                                :detail="($rapport['lignes_hors_internat'] ?? 0) > 0 ? $rapport['lignes_hors_internat'].' ligne(s) de l’autre internat' : null" />
                        </div>

                        @if (($rapport['erreurs_fichier'] ?? []) !== [])
                            <x-alerte ton="danger" titre="Le fichier ne peut pas être importé">
                                <ul class="list-disc space-y-0.5 pl-4">@foreach ($rapport['erreurs_fichier'] as $erreur)<li>{{ $erreur }}</li>@endforeach</ul>
                            </x-alerte>
                        @elseif ($analyse->statut === StatutImport::Importe)
                            <x-alerte ton="succes" titre="Référentiel importé">
                                @isset($rapport['resultat'])
                                    {{ $rapport['resultat']['batiments'] }} bâtiment(s), {{ $rapport['resultat']['chambres'] }} chambre(s) et {{ $rapport['resultat']['lits'] }} lit(s) ajoutés au référentiel.
                                @endisset
                                <x-slot:action><x-bouton taille="sm" :href="url('/hebergement/plan')">Voir le plan</x-bouton></x-slot:action>
                            </x-alerte>
                        @elseif ($analyse->statut === StatutImport::Annule)
                            <x-alerte ton="neutre" titre="Import annulé">Rien n’a été écrit dans le référentiel.</x-alerte>
                        @elseif ($analyse->lignes_erreur > 0)
                            <x-alerte ton="danger" titre="Des lignes sont en erreur">
                                Corrigez le fichier puis analysez-le de nouveau : un fichier n’est importé que s’il ne contient aucune erreur.
                                <x-slot:action><x-bouton taille="sm" icone="download" :href="route('hebergement.import.rapport', $analyse)" :navigation="false">Rapport d’erreurs</x-bouton></x-slot:action>
                            </x-alerte>
                        @elseif ($importable)
                            <x-alerte ton="succes" titre="Le fichier est importable">Vérifiez l’aperçu, puis confirmez : l’import est fait en une seule opération, tout ou rien.</x-alerte>
                        @else
                            <x-alerte ton="neutre" titre="Rien à importer">Toutes les lignes sont déjà présentes dans le référentiel, ou relèvent de l’autre internat.</x-alerte>
                        @endif

                        @if (($rapport['colonnes_ignorees'] ?? []) !== [])
                            <p class="text-sm text-discret">Colonnes non reconnues, ignorées : {{ implode(', ', $rapport['colonnes_ignorees']) }}.</p>
                        @endif

                        @if ($anomalies->isNotEmpty())
                            <div>
                                <h3 class="mb-2 text-sm font-medium text-encre">Anomalies relevées @if ($anomalies->count() > 200)<span class="font-normal text-discret">(200 premières)</span>@endif</h3>
                                <x-tableau legende="Anomalies relevées dans le fichier" :plafonne="false" class="max-h-96 overflow-auto!">
                                    <x-slot:entete>
                                        <x-tableau.colonne droite>Ligne</x-tableau.colonne>
                                        <x-tableau.colonne>Gravité</x-tableau.colonne>
                                        <x-tableau.colonne>Colonne</x-tableau.colonne>
                                        <x-tableau.colonne>Message</x-tableau.colonne>
                                    </x-slot:entete>
                                    @foreach ($anomalies->take(200) as $anomalie)
                                        <x-tableau.ligne>
                                            <x-tableau.cellule droite dense>{{ $anomalie['ligne'] }}</x-tableau.cellule>
                                            <x-tableau.cellule dense><x-badge :ton="$gravites[$anomalie['gravite']][1] ?? 'neutre'">{{ $gravites[$anomalie['gravite']][0] ?? $anomalie['gravite'] }}</x-badge></x-tableau.cellule>
                                            <x-tableau.cellule dense><code class="text-xs">{{ $anomalie['colonne'] }}</code></x-tableau.cellule>
                                            <x-tableau.cellule dense>{{ $anomalie['message'] }}</x-tableau.cellule>
                                        </x-tableau.ligne>
                                    @endforeach
                                </x-tableau>
                            </div>
                        @endif

                        @if ($apercu->isNotEmpty())
                            @php $colonnesApercu = array_keys($apercu->first()['donnees'] ?? []); @endphp
                            <div>
                                <h3 class="mb-2 text-sm font-medium text-encre">Aperçu des premières lignes</h3>
                                <x-tableau legende="Aperçu des lignes du fichier" :plafonne="false">
                                    <x-slot:entete>
                                        <x-tableau.colonne droite>Ligne</x-tableau.colonne>
                                        <x-tableau.colonne>État</x-tableau.colonne>
                                        @foreach ($colonnesApercu as $colonne)<x-tableau.colonne>{{ $colonne }}</x-tableau.colonne>@endforeach
                                    </x-slot:entete>
                                    @foreach ($apercu as $ligneApercu)
                                        <x-tableau.ligne>
                                            <x-tableau.cellule droite dense>{{ $ligneApercu['numero'] }}</x-tableau.cellule>
                                            <x-tableau.cellule dense><x-badge :ton="$etatsLigne[$ligneApercu['etat']][1] ?? 'neutre'">{{ $etatsLigne[$ligneApercu['etat']][0] ?? $ligneApercu['etat'] }}</x-badge></x-tableau.cellule>
                                            @foreach ($colonnesApercu as $colonne)
                                                <x-tableau.cellule dense class="whitespace-nowrap">{{ $ligneApercu['donnees'][$colonne] ?? '' }}</x-tableau.cellule>
                                            @endforeach
                                        </x-tableau.ligne>
                                    @endforeach
                                </x-tableau>
                            </div>
                        @endif
                    </x-carte.corps>

                    @if ($analyse->statut === StatutImport::Analyse)
                        <footer class="flex flex-wrap items-center justify-end gap-2 border-t border-bord-doux bg-surface-2 px-5 py-3">
                            <x-bouton wire:click="annuler" cible="annuler">Annuler cet import</x-bouton>
                            <x-bouton variante="primaire" icone="check" :disabled="! $importable" cible="confirmer"
                                x-on:click="$confirmer({ titre: 'Importer le référentiel ?', texte: {{ Illuminate\Support\Js::from($analyse->lignes_valides.' lit(s) seront créés dans « '.$analyse->internat->nom.' ». L’import est fait en une seule opération.') }}, libelle: 'Importer' }).then(() => $wire.confirmer())">
                                2. Confirmer l’import
                            </x-bouton>
                        </footer>
                    @endif
                </x-carte>
            @endif
        </div>

        <x-carte>
            <x-carte.entete titre="Imports précédents" />
            @if ($historique->isEmpty())
                <x-vide titre="Aucun import" class="py-8!" />
            @else
                <ul>
                    @foreach ($historique as $ligneImport)
                        <li class="border-b border-bord-doux last:border-0" wire:key="import-{{ $ligneImport->id }}">
                            <button type="button" wire:click="rouvrir({{ $ligneImport->id }})" class="flex w-full items-start justify-between gap-3 px-5 py-3 text-left hover:bg-marine-50/60">
                                <span class="min-w-0">
                                    <span class="block truncate text-base font-medium text-encre">{{ $ligneImport->nom_original }}</span>
                                    <span class="block text-sm text-discret">{{ $ligneImport->internat->nom }} · {{ $ligneImport->auteur?->nomComplet() }} · {{ Format::dateHeure($ligneImport->created_at) }}</span>
                                    <span class="block text-sm text-discret">
                                        @if (isset($ligneImport->rapport['resultat']))
                                            {{ $ligneImport->rapport['resultat']['lits'] }} lit(s) créés
                                        @else
                                            {{ $ligneImport->lignes_valides }} valide(s), {{ $ligneImport->lignes_erreur }} en erreur
                                        @endif
                                    </span>
                                </span>
                                <x-statut :etat="$ligneImport->statut" />
                            </button>
                        </li>
                    @endforeach
                </ul>
                @if ($historique->hasPages())
                    <div class="flex items-center justify-end gap-1 border-t border-bord-doux bg-surface-2 px-3 py-2 text-sm text-discret">
                        <x-bouton taille="sm" variante="discret" icone="chevron-left" :disabled="$historique->onFirstPage()" wire:click="previousPage">Précédent</x-bouton>
                        <x-bouton taille="sm" variante="discret" icone-fin="chevron-right" :disabled="! $historique->hasMorePages()" wire:click="nextPage">Suivant</x-bouton>
                    </div>
                @endif
            @endif
        </x-carte>
    </div>
</div>
