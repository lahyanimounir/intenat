@php use App\Support\Affichage\Format; @endphp
{{-- Fenêtre « Détail du score » : $score vient de AvecDetailScore::detailScore(). --}}
@if ($dialogue === 'score' && $score)
    @php $demande = $score['demande']; @endphp
    <x-dialogue titre="Détail du score" :description="$demande->numero_dossier.' — '.$demande->stagiaire->nom.' '.$demande->stagiaire->prenom" largeur="lg">
        <div class="space-y-5">
            <div class="grid gap-3 sm:grid-cols-3">
                <x-stat libelle="Score" :valeur="$demande->score === null ? '—' : $demande->score.' / '.$score['plafond']" />
                <x-stat libelle="Rang" :valeur="$demande->rang ?? '—'" :detail="$demande->liste?->libelle()" />
                <x-stat libelle="Grille appliquée" :valeur="'v'.($demande->version_grille ?? '—')"
                    :detail="$demande->date_calcul_score ? 'Calculé le '.Format::dateHeure($demande->date_calcul_score) : null" />
            </div>

            @if ($demande->version_grille !== null && $demande->version_grille !== $demande->campagne->version_grille)
                <x-alerte ton="alerte">
                    Ce score a été calculé avec la version {{ $demande->version_grille }} de la grille ; la version en vigueur est la {{ $demande->campagne->version_grille }}.
                </x-alerte>
            @endif
            @if ($demande->score !== null && $demande->score !== $score['recalcule'])
                <x-alerte ton="alerte">Recalculé aujourd’hui, ce dossier obtiendrait {{ $score['recalcule'] }} point(s) : une pièce ou un critère a changé depuis le calcul.</x-alerte>
            @endif

            <x-tableau legende="Détail du score par critère" :plafonne="false">
                <x-slot:entete>
                    <x-tableau.colonne>Critère</x-tableau.colonne>
                    <x-tableau.colonne>Déclaré</x-tableau.colonne>
                    <x-tableau.colonne>Pièce justificative</x-tableau.colonne>
                    <x-tableau.colonne droite>Points</x-tableau.colonne>
                </x-slot:entete>
                @foreach ($score['lignes'] as $ligne)
                    <x-tableau.ligne>
                        <x-tableau.cellule>
                            <p class="font-medium">{{ $ligne['critere']->condition_texte }}</p>
                            <p class="text-xs text-discret">{{ $ligne['critere']->categorie->libelle() }} · {{ $ligne['critere']->points }} point(s) possibles</p>
                        </x-tableau.cellule>
                        <x-tableau.cellule><x-oui-non :valeur="$ligne['declare']" /></x-tableau.cellule>
                        <x-tableau.cellule>
                            @if (! $ligne['piece_requise'])
                                <span class="text-discret">Aucune pièce exigée</span>
                            @else
                                {{ $ligne['critere']->typePiece?->libelle }}
                                @if ($ligne['declare'])
                                    <x-badge class="ml-1" :ton="$ligne['piece_acceptee'] ? 'succes' : 'alerte'">{{ $ligne['piece_acceptee'] ? 'Acceptée' : 'Non acceptée' }}</x-badge>
                                @endif
                            @endif
                        </x-tableau.cellule>
                        <x-tableau.cellule droite class="font-semibold">{{ $ligne['points'] }}</x-tableau.cellule>
                    </x-tableau.ligne>
                @endforeach
            </x-tableau>

            @if ($score['decisions']->isNotEmpty())
                <div>
                    <h3 class="mb-2 text-sm font-medium text-encre">Décisions d’admission</h3>
                    <ul class="divide-y divide-bord-doux rounded-md border border-bord">
                        @foreach ($score['decisions'] as $decision)
                            <li class="px-3.5 py-2.5">
                                <p class="text-sm text-encre">
                                    <span class="font-medium">{{ $decision->decision->libelle() }}</span>
                                    <span class="text-discret">· {{ $decision->origine->libelle() }} · {{ $decision->validateur?->nomComplet() }} · {{ Format::dateHeure($decision->date_validation) }}</span>
                                </p>
                                <p class="mt-0.5 text-xs text-discret">Score retenu : {{ $decision->score_retenu ?? '—' }} · rang retenu : {{ $decision->rang_retenu ?? '—' }}</p>
                                @if ($decision->motif)<p class="mt-0.5 text-sm text-encre-2">{{ $decision->motif }}</p>@endif
                            </li>
                        @endforeach
                    </ul>
                </div>
            @endif
        </div>
    </x-dialogue>
@endif
