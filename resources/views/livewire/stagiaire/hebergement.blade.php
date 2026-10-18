@php use App\Support\Affichage\Format; @endphp
<div>
    <x-page.entete titre="Hébergement" description="Votre chambre, votre lit et le détail de votre séjour.">
        @if ($affectation)
            <x-slot:actions>
                <x-bouton icone="download" :href="route('stagiaire.hebergement.attestation')" :navigation="false">Attestation d’affectation</x-bouton>
            </x-slot:actions>
        @endif
    </x-page.entete>

    @if ($affectation === null)
        <x-carte>
            <x-vide titre="Aucun lit ne vous est attribué" icone="bed-double">
                Un lit vous sera attribué par le gestionnaire de l’internat une fois votre place confirmée. Le paiement des frais n’est pas une condition de l’affectation.
            </x-vide>
        </x-carte>
    @else
        @php $chambre = $affectation->lit->chambre; @endphp
        <div class="grid items-start gap-6 xl:grid-cols-[minmax(0,3fr)_minmax(0,2fr)]">
            <div class="space-y-6">
                <x-carte>
                    <x-carte.entete titre="Mon affectation">
                        <x-slot:actions>
                            <x-badge :ton="$affectation->arriveeEnregistree() ? 'succes' : 'alerte'" point>{{ $affectation->arriveeEnregistree() ? 'Arrivée enregistrée' : 'Arrivée à enregistrer' }}</x-badge>
                        </x-slot:actions>
                    </x-carte.entete>
                    <x-carte.corps>
                        <x-definitions :colonnes="3">
                            <x-definition terme="Internat">{{ $chambre->batiment->internat->nom }}</x-definition>
                            <x-definition terme="Bâtiment">{{ $chambre->batiment->nom }}</x-definition>
                            <x-definition terme="Étage">{{ $chambre->etage }}</x-definition>
                            <x-definition terme="Chambre"><span class="chiffres font-medium">{{ $chambre->numero }}</span></x-definition>
                            <x-definition terme="Lit"><span class="chiffres font-medium">{{ $affectation->lit->numero }}</span></x-definition>
                            <x-definition terme="Type de chambre">{{ $chambre->type_chambre?->libelle() }}</x-definition>
                            <x-definition terme="Depuis le">{{ Format::date($affectation->date_debut) }}</x-definition>
                            <x-definition terme="Équipements de la chambre" class="sm:col-span-2">{{ $chambre->equipements }}</x-definition>
                        </x-definitions>
                    </x-carte.corps>
                </x-carte>
            </div>

            <x-carte>
                <x-carte.entete titre="Arrivées et départs" />
                @if ($affectation->mouvements->isEmpty())
                    <x-vide titre="Aucun mouvement enregistré" class="py-8!" />
                @else
                    <ul>
                        @foreach ($affectation->mouvements->sortByDesc('date_mouvement') as $mouvement)
                            <li class="flex items-center justify-between gap-3 border-b border-bord-doux px-5 py-2.5 last:border-0">
                                <span class="text-base">{{ $mouvement->type->libelle() }}</span>
                                <span class="chiffres text-sm text-discret">{{ Format::dateHeure($mouvement->date_mouvement) }}</span>
                            </li>
                        @endforeach
                    </ul>
                @endif
            </x-carte>
        </div>
    @endif
</div>
