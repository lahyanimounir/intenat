@php
    use App\Enums\StatutDemande;
    use App\Enums\StatutInscription;
    use App\Support\Affichage\Format;

    $statut = $demande?->statut;
    $inscription = $stagiaire->statut_inscription;
    $echeance = $statut === StatutDemande::Admis ? Format::tempsRestant($demande->date_limite_confirmation) : null;
    $raccourcis = [
        [route('stagiaire.inscription'), 'graduation-cap', 'Mon inscription', $inscription->libelle()],
        [route('stagiaire.dossier'), 'clipboard-check', 'Mon dossier', $demande ? $statut->libelle() : 'Aucune demande'],
        [url('/stagiaire/hebergement'), 'bed-double', 'Hébergement', $affectation ? 'Chambre '.$affectation->lit->chambre->numero.', lit '.$affectation->lit->numero : 'Aucun lit attribué'],
        [url('/stagiaire/paiements'), 'credit-card', 'Paiements', $paiementsARegler > 0 ? $paiementsARegler.' reçu(s) à déposer' : 'À jour'],
        [url('/stagiaire/repas'), 'utensils-crossed', 'Repas', $repasReserves.' repas réservé(s) à venir'],
    ];
@endphp
<div>
    <x-page.entete :titre="'Bonjour '.$stagiaire->prenom" description="Votre demande de place, votre séjour et vos démarches à la Maison des Stagiaires." />

    <div class="space-y-6">
        {{-- Ce qui attend une action du stagiaire, par ordre de priorité. --}}
        @if ($inscription === StatutInscription::Declaree)
            <x-alerte ton="alerte" titre="Faites vérifier votre inscription en formation">
                Elle est déclarée mais pas encore soumise : joignez votre justificatif pour que votre établissement puisse la vérifier.
                <x-slot:action><x-bouton variante="primaire" taille="sm" :href="route('stagiaire.inscription', ['etape' => 'verification'])">Soumettre</x-bouton></x-slot:action>
            </x-alerte>
        @elseif ($inscription === StatutInscription::ACorriger || $inscription === StatutInscription::Rejetee)
            <x-alerte :ton="$inscription === StatutInscription::Rejetee ? 'danger' : 'alerte'"
                :titre="$inscription === StatutInscription::Rejetee ? 'Votre inscription en formation a été rejetée' : 'Une correction est demandée sur votre inscription'">
                Consultez le motif, corrigez votre déclaration ou votre justificatif, puis soumettez de nouveau.
                <x-slot:action><x-bouton variante="primaire" taille="sm" :href="route('stagiaire.inscription', ['etape' => 'verification'])">Voir</x-bouton></x-slot:action>
            </x-alerte>
        @elseif ($inscription === StatutInscription::Soumise)
            <x-alerte ton="info" titre="Inscription en cours de vérification">
                Votre établissement contrôle votre justificatif. En attendant, vous pouvez préparer votre dossier d’hébergement.
            </x-alerte>
        @endif

        @if ($echeance && ! $echeance['depasse'])
            <x-alerte ton="succes" titre="Vous êtes admis : confirmez votre place">
                Avant le {{ Format::dateHeure($demande->date_limite_confirmation) }} — {{ mb_strtolower($echeance['texte']) }}.
                <x-slot:action><x-bouton variante="primaire" taille="sm" :href="route('stagiaire.dossier')">Confirmer</x-bouton></x-slot:action>
            </x-alerte>
        @endif

        @if ($statut === StatutDemande::RetourRectification)
            <x-alerte ton="alerte" titre="Votre dossier doit être rectifié">
                {{ $demande->motif_rectification }}
                <x-slot:action><x-bouton variante="primaire" taille="sm" :href="route('stagiaire.dossier')">Rectifier</x-bouton></x-slot:action>
            </x-alerte>
        @endif

        @if ($statut === StatutDemande::Brouillon && $piecesManquantes->isNotEmpty())
            <x-alerte ton="info" titre="Dossier en cours de préparation">
                Pièces encore attendues : {{ $piecesManquantes->implode(', ') }}.
                <x-slot:action><x-bouton taille="sm" :href="route('stagiaire.dossier')">Reprendre</x-bouton></x-slot:action>
            </x-alerte>
        @endif

        @if ($paiementsARegler > 0)
            <x-alerte ton="alerte" titre="Reçu de paiement de l’hébergement attendu">
                {{ $paiementsARegler }} période(s) autorisée(s) au paiement sans reçu déposé.
                <x-slot:action><x-bouton taille="sm" :href="url('/stagiaire/paiements')">Déposer mon reçu</x-bouton></x-slot:action>
            </x-alerte>
        @endif

        <div class="grid gap-6 xl:grid-cols-[minmax(0,3fr)_minmax(0,2fr)]">
            <div class="space-y-6">
                <x-carte>
                    <x-carte.entete titre="Ma demande">
                        @if ($demande)<x-slot:actions><x-statut :etat="$statut" /></x-slot:actions> @endif
                    </x-carte.entete>
                    @if ($demande)
                        <div class="px-5 py-4">
                            <p class="text-base">
                                @if ($demande->numero_dossier)
                                    Dossier <span class="chiffres font-medium">{{ $demande->numero_dossier }}</span>
                                @else
                                    Dossier non encore déposé
                                @endif
                                <span class="text-discret"> · {{ $demande->campagne->internat->nom }}</span>
                            </p>
                            @if ($demande->date_soumission)<p class="mt-0.5 text-sm text-discret">Déposé le {{ Format::dateHeure($demande->date_soumission) }}</p>@endif
                            <x-bouton class="mt-3.5" taille="sm" :href="route('stagiaire.dossier')" icone-fin="arrow-right">Ouvrir mon dossier</x-bouton>
                        </div>
                    @elseif ($campagneOuverte)
                        <x-vide titre="Une campagne est ouverte" class="py-8!">
                            {{ $campagneOuverte->internat->nom }} — jusqu’au {{ Format::dateLongue($campagneOuverte->date_cloture) }}.
                            <x-slot:action><x-bouton variante="primaire" :href="route('stagiaire.dossier')">Déposer une demande</x-bouton></x-slot:action>
                        </x-vide>
                    @else
                        <x-vide titre="Aucune campagne ouverte" class="py-8!">Vous serez informé à l’ouverture du dépôt des demandes de votre internat.</x-vide>
                    @endif
                </x-carte>

                <div class="grid gap-3 sm:grid-cols-2">
                    @foreach ($raccourcis as [$vers, $icone, $titre, $detail])
                        <a href="{{ $vers }}" wire:navigate
                            class="group flex items-center gap-3.5 rounded-lg border border-bord bg-surface px-4 py-3.5 shadow-xs transition-colors hover:border-marine-200 hover:bg-marine-50/50">
                            <span class="flex size-9 shrink-0 items-center justify-center rounded-md bg-marine-50 text-marine">
                                <x-icone :nom="$icone" class="size-4.5" trait="1.75" />
                            </span>
                            <span class="min-w-0 flex-1">
                                <span class="block text-base font-medium text-encre">{{ $titre }}</span>
                                <span class="block truncate text-sm text-discret">{{ $detail }}</span>
                            </span>
                            <x-icone nom="arrow-right" class="text-marine-200 transition-colors group-hover:text-marine" />
                        </a>
                    @endforeach
                </div>
            </div>

            <x-carte>
                <x-carte.entete titre="Notifications récentes">
                    <x-slot:actions><x-bouton variante="lien" taille="sm" :href="url('/notifications')">Tout voir</x-bouton></x-slot:actions>
                </x-carte.entete>
                @if ($notifications->isEmpty())
                    <x-vide titre="Aucune notification non lue" class="py-8!" />
                @else
                    <ul>
                        @foreach ($notifications as $notification)
                            <li class="border-b border-bord-doux px-5 py-3 last:border-0">
                                <p class="text-base font-medium">{{ $notification->titre }}</p>
                                <p class="mt-0.5 text-sm text-encre-2">{{ $notification->message }}</p>
                                <p class="mt-1 text-xs text-discret">{{ Format::dateHeure($notification->date_envoi) }}</p>
                            </li>
                        @endforeach
                    </ul>
                @endif
            </x-carte>
        </div>
    </div>
</div>
