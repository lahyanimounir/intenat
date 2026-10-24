@php
    use App\Enums\StatutMaintenance;
    use App\Enums\TypeIncident;
    use App\Support\Affichage\Format;
@endphp
<div>
    <x-page.entete titre="Incidents et maintenance" description="Consignez les incidents de la vie à l’internat et suivez les demandes de maintenance des chambres.">
        <x-slot:actions>
            @if ($onglet === 'incidents')
                <x-bouton variante="primaire" icone="plus" wire:click="preparerIncident">Déclarer un incident</x-bouton>
            @else
                <x-bouton variante="primaire" icone="plus" wire:click="preparerMaintenance">Signaler une maintenance</x-bouton>
            @endif
        </x-slot:actions>
    </x-page.entete>

    <x-onglets libelle="Sections">
        <x-onglet :actif="$onglet === 'incidents'" wire:click="voir('incidents')">Incidents</x-onglet>
        <x-onglet :actif="$onglet === 'maintenance'" wire:click="voir('maintenance')" :compteur="$aTraiter">Maintenance</x-onglet>
    </x-onglets>

    <div class="pt-5" wire:key="onglet-{{ $onglet }}">
        @if ($onglet === 'incidents')
            <x-tableau legende="Incidents" :lignes="$incidents" vide="Aucun incident enregistré">
                <x-slot:barre>
                    <x-filtre libelle="Type" wire:model.live="type" :options="TypeIncident::options()" :actif="$type !== ''" />
                </x-slot:barre>
                <x-slot:entete>
                    <x-tableau.colonne>Date</x-tableau.colonne>
                    <x-tableau.colonne>Résident</x-tableau.colonne>
                    <x-tableau.colonne>Type</x-tableau.colonne>
                    <x-tableau.colonne>Description</x-tableau.colonne>
                    <x-tableau.colonne>Saisi par</x-tableau.colonne>
                    <x-tableau.colonne><span class="sr-only">Rapport</span></x-tableau.colonne>
                </x-slot:entete>
                @foreach ($incidents as $ligneIncident)
                    <x-tableau.ligne wire:key="incident-{{ $ligneIncident->id }}">
                        <x-tableau.cellule class="chiffres whitespace-nowrap">{{ Format::dateHeure($ligneIncident->date_incident) }}</x-tableau.cellule>
                        <x-tableau.cellule>
                            <a href="{{ route('hebergement.residents.fiche', $ligneIncident->affectation_id) }}" wire:navigate class="font-medium text-marine hover:underline">
                                {{ $ligneIncident->affectation->demande->stagiaire->nom }} {{ $ligneIncident->affectation->demande->stagiaire->prenom }}
                            </a>
                            <p class="chiffres text-xs text-discret">Chambre {{ $ligneIncident->affectation->lit->chambre->numero }}</p>
                        </x-tableau.cellule>
                        <x-tableau.cellule><x-badge>{{ $ligneIncident->type->libelle() }}</x-badge></x-tableau.cellule>
                        <x-tableau.cellule class="max-w-md">{{ $ligneIncident->description }}</x-tableau.cellule>
                        <x-tableau.cellule class="text-encre-2">{{ $ligneIncident->auteur?->nomComplet() }}</x-tableau.cellule>
                        <x-tableau.cellule droite>
                            @if ($ligneIncident->rapport_fichier)
                                <x-bouton taille="sm" variante="discret" icone="paperclip" :href="route('vie-internat.incidents.rapport', $ligneIncident)" :navigation="false" target="_blank" rel="noopener">Rapport</x-bouton>
                            @endif
                        </x-tableau.cellule>
                    </x-tableau.ligne>
                @endforeach
            </x-tableau>
        @else
            <x-tableau legende="Demandes de maintenance" :lignes="$maintenances" vide="Aucune demande de maintenance">
                <x-slot:barre>
                    <x-filtre libelle="Statut" wire:model.live="statut" :options="StatutMaintenance::options()" :actif="$statut !== ''" />
                </x-slot:barre>
                <x-slot:entete>
                    <x-tableau.colonne>Signalée le</x-tableau.colonne>
                    <x-tableau.colonne>Chambre</x-tableau.colonne>
                    <x-tableau.colonne>Description</x-tableau.colonne>
                    <x-tableau.colonne>Statut</x-tableau.colonne>
                    <x-tableau.colonne><span class="sr-only">Actions</span></x-tableau.colonne>
                </x-slot:entete>
                @foreach ($maintenances as $demande)
                    <x-tableau.ligne wire:key="maintenance-{{ $demande->id }}">
                        <x-tableau.cellule class="chiffres whitespace-nowrap">
                            {{ Format::date($demande->date_demande) }}
                            <span class="block text-xs text-discret">{{ $demande->auteur?->nomComplet() }}</span>
                        </x-tableau.cellule>
                        <x-tableau.cellule>
                            <span class="chiffres font-medium">{{ $demande->chambre->numero }}</span>
                            <span class="block text-xs text-discret">Bâtiment {{ $demande->chambre->batiment->code }}</span>
                            @if ($demande->bloque_chambre && $demande->statut !== StatutMaintenance::Resolue)<x-badge ton="alerte" class="mt-1">Chambre bloquée</x-badge>@endif
                        </x-tableau.cellule>
                        <x-tableau.cellule class="max-w-md">
                            {{ $demande->description }}
                            @if ($demande->resolution)<p class="mt-1 text-sm text-discret">Résolution ({{ Format::date($demande->date_resolution) }}) : {{ $demande->resolution }}</p>@endif
                        </x-tableau.cellule>
                        <x-tableau.cellule><x-statut :etat="$demande->statut" /></x-tableau.cellule>
                        <x-tableau.cellule droite>
                            <div class="flex justify-end gap-1.5">
                                @if ($demande->statut === StatutMaintenance::Signalee)
                                    <x-bouton taille="sm" wire:click="prendreEnCharge({{ $demande->id }})" cible="prendreEnCharge({{ $demande->id }})">Prendre en charge</x-bouton>
                                @endif
                                @if ($demande->statut !== StatutMaintenance::Resolue)
                                    <x-bouton taille="sm" variante="primaire" icone="check" wire:click="preparerResolution({{ $demande->id }})">Résoudre</x-bouton>
                                @endif
                            </div>
                        </x-tableau.cellule>
                    </x-tableau.ligne>
                @endforeach
            </x-tableau>
        @endif
    </div>

    @if ($dialogue === 'incident')
        <x-dialogue titre="Déclarer un incident" description="L’incident est rattaché à l’hébergement du résident et consigné au journal.">
            <form wire:submit="enregistrerIncident" id="form-incident" novalidate class="grid gap-4 sm:grid-cols-2">
                <x-liste libelle="Résident" wire:model="incident.affectation_id" :options="$residents" vide="Choisir…" requis class="sm:col-span-2" />
                <x-liste libelle="Type" wire:model="incident.type" :options="TypeIncident::options()" vide="Choisir…" requis />
                <x-saisie libelle="Date et heure" type="datetime-local" wire:model="incident.date_incident" requis />
                <x-zone-texte libelle="Description" wire:model="incident.description" rows="4" requis class="sm:col-span-2" aide="Dix caractères au moins : les faits, sans appréciation." />
                <div class="sm:col-span-2">
                    <p class="mb-1.5 text-sm font-medium text-encre">Rapport (facultatif)</p>
                    <x-fichier wire:model="rapport" :fichier="$rapport" accept=".pdf" />
                </div>
            </form>
            <x-slot:pied>
                <x-bouton wire:click="fermerDialogue">Annuler</x-bouton>
                <x-bouton type="submit" form="form-incident" variante="primaire" cible="enregistrerIncident,rapport">Enregistrer</x-bouton>
            </x-slot:pied>
        </x-dialogue>
    @endif

    @if ($dialogue === 'maintenance')
        <x-dialogue titre="Signaler une maintenance">
            <form wire:submit="signaler" id="form-maintenance" novalidate class="space-y-4">
                <x-liste libelle="Chambre" wire:model="maintenance.chambre_id" :options="$chambres" vide="Choisir…" requis />
                <x-zone-texte libelle="Problème constaté" wire:model="maintenance.description" placeholder="Literie, mobilier, plomberie…" requis />
                <div class="flex items-start gap-3">
                    <span class="mt-0.5"><x-interrupteur libelle="Sortir la chambre de l’affectation" wire:model="maintenance.bloque_chambre" /></span>
                    <p aria-hidden="true">
                        <span class="block text-base font-medium">Sortir la chambre de l’affectation</span>
                        <span class="block text-sm text-discret">La chambre passe « En maintenance » : aucun lit ne peut y être attribué jusqu’à la résolution. Ses occupants actuels ne sont pas déplacés.</span>
                    </p>
                </div>
            </form>
            <x-slot:pied>
                <x-bouton wire:click="fermerDialogue">Annuler</x-bouton>
                <x-bouton type="submit" form="form-maintenance" variante="primaire" cible="signaler">Signaler</x-bouton>
            </x-slot:pied>
        </x-dialogue>
    @endif

    @if ($dialogue === 'resolution')
        <x-dialogue titre="Clore la demande de maintenance" description="Si la chambre était indisponible, elle redevient attribuable.">
            <form wire:submit="resoudre" id="form-resolution" novalidate>
                <x-zone-texte libelle="Résolution" wire:model="resolution" rows="4" requis autofocus aide="Ce qui a été fait." />
            </form>
            <x-slot:pied>
                <x-bouton wire:click="fermerDialogue">Annuler</x-bouton>
                <x-bouton type="submit" form="form-resolution" variante="primaire" cible="resoudre">Marquer résolue</x-bouton>
            </x-slot:pied>
        </x-dialogue>
    @endif
</div>
