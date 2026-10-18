@php
    use App\Support\Affichage\Format;
    use App\Support\Affichage\Graphique;

    $h = $d['hebergement'];
@endphp
<div class="space-y-6">
    <div class="grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
        <x-stat libelle="Dossiers à instruire" :valeur="$d['instruction']['a_instruire']" :detail="$d['instruction']['en_rectification'].' en rectification'"
            :ton="$d['instruction']['a_instruire'] > 0 ? 'alerte' : 'neutre'" href="/dossiers?statut=recu" />
        <x-stat libelle="Résidents hébergés" :valeur="$h['residents']" :detail="Format::pourcentage($h['taux_occupation']).' d’occupation'" href="/hebergement/residents" />
        <x-stat libelle="Lits disponibles" :valeur="$h['disponibles']" :detail="'sur '.$h['lits'].' lits, '.$h['hors_service'].' hors service'" href="/hebergement/plan" />
        <x-stat libelle="Admis à héberger" :valeur="$h['a_heberger']" detail="Confirmés sans lit" :ton="$h['a_heberger'] > 0 ? 'alerte' : 'neutre'" href="/hebergement/affectations" />
    </div>

    <div class="grid gap-6 xl:grid-cols-[minmax(0,5fr)_minmax(0,7fr)]">
        @include('livewire.personnel.tableaux.a-traiter', ['lignes' => [
            ['Inscriptions en formation à vérifier', $inscriptionsAVerifier, '/inscriptions'],
            ['Dossiers reçus à instruire', $d['instruction']['a_instruire'], '/dossiers?statut=recu'],
            ['Admis confirmés à héberger', $h['a_heberger'], '/hebergement/affectations'],
            ['Arrivées attendues', $h['arrivees_attendues'], '/hebergement/residents?arrivee=attendue'],
            ['Départs à enregistrer après désistement', $h['departs_a_enregistrer'], '/hebergement/residents'],
            ['Reçus de paiement à contrôler', $d['paiements']['recus_a_controler'], '/paiements?statut=recu_depose'],
            ['Demandes de sortie à traiter', $d['quotidien']['sorties_a_traiter'], '/vie-internat/presences'],
            ['Absences non autorisées (7 derniers jours)', $d['quotidien']['absences_non_autorisees_7j'], '/vie-internat/presences?onglet=absences'],
            ['Demandes de maintenance ouvertes', $d['quotidien']['maintenances_ouvertes'], '/vie-internat/incidents?onglet=maintenance'],
            ['Réclamations d’hébergement ouvertes', $d['quotidien']['reclamations_ouvertes'], '/reclamations'],
        ]])

        <x-carte>
            <x-carte.entete titre="Occupation par bâtiment" :description="$h['occupes'].' lit(s) occupé(s) sur '.$h['lits']" />
            <x-carte.corps>
                @if ($d['occupation_par_batiment'] === [])
                    <x-vide titre="Référentiel vide" icone="building-complex">
                        Importez le fichier CSV des bâtiments, chambres et lits de votre internat.
                        <x-slot:action><x-bouton variante="primaire" href="/hebergement/import">Importer le référentiel</x-bouton></x-slot:action>
                    </x-vide>
                @else
                    <x-graphique.barres empile
                        :donnees="collect($d['occupation_par_batiment'])->map(fn ($b) => ['nom' => $b['batiment']] + $b)"
                        :series="[
                            ['cle' => 'occupes', 'nom' => 'Occupés', 'couleur' => Graphique::MARINE],
                            ['cle' => 'disponibles', 'nom' => 'Disponibles', 'couleur' => Graphique::BLEU_CLAIR],
                            ['cle' => 'hors_service', 'nom' => 'Hors service', 'couleur' => Graphique::GRIS],
                        ]" />
                @endif
            </x-carte.corps>
        </x-carte>
    </div>

    @if ($d['paiements']['recus_manquants'] > 0)
        <p class="text-sm text-discret">
            {{ $d['paiements']['recus_manquants'] }} résident(s) n’ont pas encore déposé leur reçu pour {{ Format::mois(now()->format('Y-m')) }}.
            <a href="/paiements?statut=autorise" wire:navigate class="text-bleu hover:underline">Voir les paiements</a>
        </p>
    @endif
</div>
