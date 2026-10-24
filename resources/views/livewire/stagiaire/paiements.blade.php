@php
    use App\Enums\StatutPaiement;
    use App\Support\Affichage\Format;
@endphp
<div>
    <x-page.entete titre="Paiements"
        description="Frais d’internat mensuels : chaque mois d’hébergement fait l’objet d’un paiement. Réglez le montant de chaque mois puis déposez votre justificatif de paiement : il est vérifié par le gestionnaire de l’internat." />

    @if ($paiements->isEmpty())
        <x-carte>
            <x-vide titre="Aucun paiement à ce jour" icone="credit-card">
                La mensualité de votre premier mois apparaît ici dès l’affectation de votre lit, puis celle de chaque mois le 1er.
            </x-vide>
        </x-carte>
    @else
        <div class="space-y-4">
            @foreach ($paiements as $paiement)
                @php $depot = in_array($paiement->statut, \App\Domain\Payments\SuiviPaiements::A_REGLER, true); @endphp
                <x-carte wire:key="paiement-{{ $paiement->id }}">
                    <div class="flex flex-wrap items-start justify-between gap-3 px-5 py-4">
                        <div class="min-w-0">
                            <p class="text-md font-semibold text-encre">Frais d’internat — {{ Format::mois($paiement->periode) }}</p>
                            <p class="mt-0.5 text-sm text-discret">
                                Période : {{ Format::mois($paiement->periode) }} · Montant : <span class="chiffres font-medium text-encre">{{ Format::montant($paiement->montant) }}</span>
                                @if ($paiement->date_depot) · justificatif déposé le {{ Format::date($paiement->date_depot) }}@endif
                                @if ($paiement->date_validation) · validé le {{ Format::date($paiement->date_validation) }}@endif
                            </p>
                        </div>
                        <x-statut :etat="$paiement->statut" />
                    </div>

                    @if ($paiement->statut === StatutPaiement::Refuse && $paiement->motif_refus)
                        <div class="px-5 pb-3"><x-alerte ton="danger" titre="Justificatif rejeté">{{ $paiement->motif_refus }} Déposez un justificatif corrigé.</x-alerte></div>
                    @endif

                    <div class="flex flex-wrap items-center gap-2 border-t border-bord-doux bg-surface-2 px-5 py-3">
                        <x-bouton taille="sm" icone="download" :href="route('stagiaire.paiements.autorisation', $paiement)" :navigation="false">Avis de paiement</x-bouton>
                        @if ($paiement->recu_fichier)
                            <x-bouton taille="sm" variante="discret" icone="eye" wire:click="voirRecu({{ $paiement->id }})">Voir mon justificatif</x-bouton>
                        @endif
                        @if ($depot)
                            <x-fichier wire:model="recus.{{ $paiement->id }}" compact :libelle="$paiement->recu_fichier ? 'Déposer un justificatif corrigé' : 'Déposer mon justificatif de paiement'" />
                        @elseif ($paiement->statut === StatutPaiement::RecuDepose)
                            <span class="text-sm text-discret">Justificatif en cours de vérification par le gestionnaire.</span>
                        @endif
                    </div>
                </x-carte>
            @endforeach
        </div>
    @endif

    @if ($dialogue === 'recu' && $apercu)
        <x-apercu :chemin="route('stagiaire.paiements.recu', $apercu)" titre="Justificatif de paiement" />
    @endif
</div>
