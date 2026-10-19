@php
    use App\Enums\ListeAdmission;
    use App\Enums\TypeDecision;
    use App\Support\Affichage\Format;

    $nombreAjustements = count($ajustements ?? []);
@endphp
<div>
    <x-page.entete titre="Classement et délibération"
        description="Le classement propose la liste principale et la liste d’attente d’un internat. Le directeur du complexe / CMC valide les admissions ; tout écart à la proposition est motivé. Le chef de pôle consulte et imprime la liste des candidats, sans délibérer ; le gestionnaire de l’internat la consulte en lecture seule.">
        @if ($choisie)
            <x-slot:actions>
                <x-bouton icone="printer" :href="route('admissions.classement.impression', $choisie)" :navigation="false" target="_blank" rel="noopener">Imprimer la liste des candidats</x-bouton>
                @if ($peutDeliberer)
                    <x-bouton icone="refresh-cw" wire:click="recalculer" cible="recalculer">Recalculer le classement</x-bouton>
                    <x-bouton variante="primaire" icone="gavel" :disabled="count($apercu['blocages']) > 0" wire:click="ouvrirDialogue('deliberation')">Valider les admissions</x-bouton>
                @endif
            </x-slot:actions>
        @endif
    </x-page.entete>

    @if ($choisie === null)
        <x-carte><x-vide titre="Aucune campagne ouverte ou clôturée" icone="list-ordered">Le classement apparaît dès qu’une campagne reçoit des dossiers complets.</x-vide></x-carte>
    @else
        <div class="mb-5 flex flex-wrap items-center gap-3">
            <div class="w-full max-w-md">
                <x-liste wire:model.live="campagne" :options="$campagnes" aria-label="Campagne" />
            </div>
            <x-statut :etat="$choisie->statut" />
            @if ($deliberee)<x-badge ton="marine">Délibérée le {{ Format::date($choisie->date_deliberation) }}</x-badge>@endif
        </div>

        <div class="mb-5 grid gap-3 sm:grid-cols-2 lg:grid-cols-5">
            <x-stat libelle="Places offertes" :valeur="$apercu['places']" :detail="$placesLibres !== null ? $placesLibres.' libre(s)' : null" />
            <x-stat libelle="Dossiers complets" :valeur="$deliberee ? $enPrincipale + $enAttente : $apercu['dossiers_complets']" :detail="$deliberee ? 'À la délibération' : 'Classés'" />
            <x-stat libelle="Liste principale" :valeur="$enPrincipale" />
            <x-stat libelle="Liste d’attente" :valeur="$enAttente" />
            <x-stat libelle="Score du dernier admis" :valeur="$scoreLimite ?? '—'" :ton="$exAequo ? 'alerte' : 'neutre'" :detail="$exAequo ? 'Égalité à la limite' : null" />
        </div>

        @if (count($apercu['blocages']) > 0)
            <x-alerte ton="alerte" class="mb-5" titre="La délibération n’est pas encore possible">
                <ul class="list-disc space-y-0.5 pl-4">@foreach ($apercu['blocages'] as $blocage)<li>{{ $blocage }}</li>@endforeach</ul>
            </x-alerte>
        @endif

        @if ($exAequo)
            <x-alerte ton="alerte" class="mb-5" titre="Égalité à la limite des places">
                Plusieurs candidats ont le score du dernier admis ({{ $scoreLimite }} points). Aucun départage automatique n’est appliqué : arbitrez par un ajustement motivé,
                ou confirmez expressément l’ordre proposé au moment de valider.
            </x-alerte>
        @endif

        @if ($nombreAjustements > 0)
            <x-alerte ton="info" class="mb-5" :titre="$nombreAjustements.' ajustement(s) en attente de validation'">
                Ils ne s’appliqueront qu’à la validation des admissions.
            </x-alerte>
        @endif

        <x-tableau legende="Classement de la campagne" :lignes="$dossiers" page="page"
            vide="Aucun dossier classé" vide-description="Un dossier entre au classement quand le gestionnaire le déclare complet et que l’inscription du stagiaire est vérifiée.">
            <x-slot:barre>
                <x-recherche placeholder="Nom, CEF ou n° de dossier" libelle="Rechercher un candidat" />
                <x-filtre libelle="Liste" wire:model.live="liste" :options="ListeAdmission::options()" tous="toutes" :actif="$liste !== ''" />
            </x-slot:barre>
            <x-slot:entete>
                <x-tableau.colonne>Rang</x-tableau.colonne>
                <x-tableau.colonne>N° dossier</x-tableau.colonne>
                <x-tableau.colonne>Candidat</x-tableau.colonne>
                <x-tableau.colonne droite>Score / {{ config('internat.score.bareme_maximum') }}</x-tableau.colonne>
                <x-tableau.colonne>{{ $deliberee ? 'Statut' : 'Proposition' }}</x-tableau.colonne>
                <x-tableau.colonne><span class="sr-only">Actions</span></x-tableau.colonne>
            </x-slot:entete>

            @foreach ($dossiers as $ligne)
                @php $ajustement = $ajustements[$ligne->id] ?? null; @endphp
                <x-tableau.ligne wire:key="classement-{{ $ligne->id }}">
                    <x-tableau.cellule class="chiffres font-semibold">{{ $ligne->rang }}</x-tableau.cellule>
                    <x-tableau.cellule class="chiffres">{{ $ligne->numero_dossier }}</x-tableau.cellule>
                    <x-tableau.cellule>
                        <p class="font-medium text-encre">{{ $ligne->stagiaire->nom }} {{ $ligne->stagiaire->prenom }}</p>
                        <p class="text-xs text-discret">{{ $ligne->stagiaire->nom_filiere }}</p>
                    </x-tableau.cellule>
                    <x-tableau.cellule droite>
                        <span @class(['text-md font-semibold', 'text-alerte' => $exAequo && $ligne->score === (int) $scoreLimite])>{{ $ligne->score }}</span>
                    </x-tableau.cellule>
                    <x-tableau.cellule>
                        @if ($deliberee)
                            <x-statut :etat="$ligne->statut" />
                        @elseif ($ajustement)
                            <x-badge ton="alerte">Ajusté : {{ TypeDecision::tryFrom($ajustement['decision'])?->libelle() }}</x-badge>
                        @else
                            <x-badge :ton="$ligne->liste === ListeAdmission::Principale ? 'succes' : 'neutre'">{{ $ligne->liste === ListeAdmission::Principale ? 'Admis' : 'Liste d’attente' }}</x-badge>
                        @endif
                    </x-tableau.cellule>
                    <x-tableau.cellule droite>
                        <div class="flex justify-end gap-1.5">
                            <x-bouton taille="sm" variante="discret" icone="calculator" wire:click="voirScore({{ $ligne->id }})">Score</x-bouton>
                            @if ($peutDeliberer)
                                @if ($ajustement)
                                    <x-bouton taille="sm" variante="discret" icone="undo-2" wire:click="retirerAjustement({{ $ligne->id }})">Annuler l’ajustement</x-bouton>
                                @else
                                    <x-bouton taille="sm" wire:click="preparerAjustement({{ $ligne->id }})">Ajuster</x-bouton>
                                @endif
                            @endif
                        </div>
                    </x-tableau.cellule>
                </x-tableau.ligne>
            @endforeach
        </x-tableau>

        @if ($dialogue === 'ajustement' && $dossierAjuste)
            <x-dialogue titre="Ajuster la proposition" :description="$dossierAjuste->numero_dossier.' — '.$dossierAjuste->stagiaire->nom.' '.$dossierAjuste->stagiaire->prenom.', rang '.$dossierAjuste->rang">
                <form wire:submit="enregistrerAjustement" id="form-ajustement" novalidate class="space-y-4">
                    <x-liste libelle="Décision" wire:model="decision" :options="TypeDecision::options()" vide="Choisir…" requis />
                    <x-zone-texte libelle="Motif" wire:model="motif" rows="4" requis autofocus aide="Il est conservé avec la décision et consultable dans le détail du score." />
                </form>
                <x-slot:pied>
                    <x-bouton wire:click="fermerDialogue">Annuler</x-bouton>
                    <x-bouton type="submit" form="form-ajustement" variante="primaire">Enregistrer l’ajustement</x-bouton>
                </x-slot:pied>
            </x-dialogue>
        @endif

        @if ($dialogue === 'deliberation')
            <x-dialogue titre="Valider les admissions" :description="'Campagne '.$choisie->libellePeriode().' — '.$choisie->internat->nom">
                <div class="space-y-4">
                    <x-definitions>
                        <x-definition terme="Places offertes"><span class="chiffres">{{ $apercu['places'] }}</span></x-definition>
                        <x-definition terme="Dossiers complets"><span class="chiffres">{{ $apercu['dossiers_complets'] }}</span></x-definition>
                        <x-definition terme="Ajustements"><span class="chiffres">{{ $nombreAjustements }}</span></x-definition>
                    </x-definitions>

                    <x-alerte ton="alerte" titre="Décision définitive">
                        Les résultats seront publiés et chaque candidat notifié. Aucune confirmation n’est demandée aux candidats ; la liste d’attente ne sera jamais promue automatiquement.
                    </x-alerte>

                    <x-zone-texte libelle="Message aux candidats (facultatif)" wire:model="messageDeliberation" rows="4"
                        aide="Identique pour tous les candidats de la campagne, il s’affiche dans leur compte avec les résultats." />

                    @if ($exAequo)
                        <x-case wire:model="arbitrage" libelle="Je confirme l’ordre proposé malgré l’égalité à la limite des places." />
                    @endif
                </div>
                <x-slot:pied>
                    <x-bouton wire:click="fermerDialogue">Annuler</x-bouton>
                    <x-bouton variante="primaire" icone="gavel" wire:click="deliberer" cible="deliberer">Valider les admissions</x-bouton>
                </x-slot:pied>
            </x-dialogue>
        @endif
    @endif

    @include('livewire.personnel.partiels.detail-score')
</div>
