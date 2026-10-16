@php
    use App\Enums\StatutPaiement;
    use App\Support\Affichage\Format;
@endphp
<div>
    <x-page.entete titre="Paiements"
        description="Frais mensuels d’hébergement. Pour chaque période autorisée, réglez le montant indiqué puis déposez votre reçu : il est contrôlé par le gestionnaire." />

    @if ($paiements->isEmpty())
        <x-carte>
            <x-vide titre="Aucun paiement à ce jour" icone="credit-card">
                Les autorisations de paiement sont délivrées par le chef de pôle après la confirmation de votre place.
            </x-vide>
        </x-carte>
    @else
        <div class="space-y-4">
            @foreach ($paiements as $paiement)
                @php $depot = in_array($paiement->statut, [StatutPaiement::Autorise, StatutPaiement::Refuse], true); @endphp
                <x-carte wire:key="paiement-{{ $paiement->id }}">
                    <div class="flex flex-wrap items-start justify-between gap-3 px-5 py-4">
                        <div class="min-w-0">
                            <p class="text-md font-semibold text-encre">{{ mb_convert_case(Format::mois($paiement->periode), MB_CASE_TITLE) }}</p>
                            <p class="mt-0.5 text-sm text-discret">
                                Montant : <span class="chiffres font-medium text-encre">{{ Format::montant($paiement->montant) }}</span>
                                @if ($paiement->date_autorisation) · autorisé le {{ Format::date($paiement->date_autorisation) }}@endif
                                @if ($paiement->date_depot) · reçu déposé le {{ Format::date($paiement->date_depot) }}@endif
                                @if ($paiement->date_validation) · validé le {{ Format::date($paiement->date_validation) }}@endif
                            </p>
                        </div>
                        <x-statut :etat="$paiement->statut" />
                    </div>

                    @if ($paiement->statut === StatutPaiement::Refuse && $paiement->motif_refus)
                        <div class="px-5 pb-3"><x-alerte ton="danger" titre="Reçu refusé">{{ $paiement->motif_refus }} Déposez un nouveau reçu.</x-alerte></div>
                    @endif

                    <div class="flex flex-wrap items-center gap-2 border-t border-bord-doux bg-surface-2 px-5 py-3">
                        @if ($paiement->date_autorisation)
                            <x-bouton taille="sm" icone="download" :href="route('stagiaire.paiements.autorisation', $paiement)" :navigation="false">Autorisation de paiement</x-bouton>
                        @endif
                        @if ($paiement->recu_fichier)
                            <x-bouton taille="sm" variante="discret" icone="eye" wire:click="voirRecu({{ $paiement->id }})">Voir mon reçu</x-bouton>
                        @endif
                        @if ($depot)
                            <x-fichier wire:model="recus.{{ $paiement->id }}" compact :libelle="$paiement->recu_fichier ? 'Remplacer le reçu' : 'Déposer mon reçu'" />
                        @elseif ($paiement->statut === StatutPaiement::EnAttente)
                            <span class="text-sm text-discret">En attente de l’autorisation du chef de pôle.</span>
                        @endif
                    </div>
                </x-carte>
            @endforeach
        </div>
    @endif

    @if ($dialogue === 'recu' && $apercu)
        <x-apercu :chemin="route('stagiaire.paiements.recu', $apercu)" titre="Reçu de paiement" />
    @endif
</div>
