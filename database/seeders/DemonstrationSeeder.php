<?php

namespace Database\Seeders;

use App\Domain\Accommodation\AffectationLits;
use App\Domain\Admissions\ConfirmationAdmission;
use App\Domain\Admissions\Deliberation;
use App\Domain\Admissions\ListeAttente;
use App\Domain\Applications\DossierStagiaire;
use App\Domain\Applications\Instruction;
use App\Domain\Campaigns\GestionCampagne;
use App\Domain\Catering\Restauration;
use App\Domain\Payments\SuiviPaiements;
use App\Domain\Residence\VieInternat;
use App\Domain\Shared\Documents;
use App\Domain\Shared\RegleMetierException;
use App\Enums\AnneeFormation;
use App\Enums\CategorieCritere;
use App\Enums\DeclencheurCritere;
use App\Enums\DomaineReclamation;
use App\Enums\EtatCompte;
use App\Enums\Genre;
use App\Enums\NiveauFormation;
use App\Enums\RaisonDesistement;
use App\Enums\RoleCode;
use App\Enums\StatutBatiment;
use App\Enums\StatutCampagne;
use App\Enums\StatutDemande;
use App\Enums\StatutInscription;
use App\Enums\StatutLit;
use App\Enums\StatutMaintenance;
use App\Enums\StatutMenu;
use App\Enums\StatutPaiement;
use App\Enums\StatutPiece;
use App\Enums\StatutReclamation;
use App\Enums\TypeChambre;
use App\Enums\TypeDecision;
use App\Enums\TypeDocument;
use App\Enums\TypeMouvement;
use App\Enums\TypeRegime;
use App\Enums\TypeRepas;
use App\Models\Affectation;
use App\Models\Batiment;
use App\Models\Campagne;
use App\Models\Chambre;
use App\Models\Complexe;
use App\Models\Critere;
use App\Models\CritereReference;
use App\Models\Demande;
use App\Models\Document;
use App\Models\Etablissement;
use App\Models\Incident;
use App\Models\Internat;
use App\Models\Lit;
use App\Models\Menu;
use App\Models\Paiement;
use App\Models\PieceJointe;
use App\Models\RegimeAlimentaire;
use App\Models\Region;
use App\Models\Stagiaire;
use App\Models\TypePiece;
use App\Models\Utilisateur;
use Barryvdh\DomPDF\Facade\Pdf;
use Carbon\CarbonImmutable;
use Illuminate\Database\Seeder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * Jeu de DÉMONSTRATION — données entièrement synthétiques.
 *
 * Aucun nom, CEF, CNI ou téléphone ne correspond à une personne réelle. Le
 * barème semé ici est un barème d'illustration : ce n'est PAS une grille
 * officielle de l'OFPPT (voir docs/requirements/questions-ouvertes.md).
 *
 * Le jeu est construit en appelant les services métier dans l'ordre du
 * workflow, à des dates relatives à aujourd'hui : les statuts, l'historique,
 * les notifications et le journal d'audit sont donc cohérents entre eux.
 */
class DemonstrationSeeder extends Seeder
{
    private const PRENOMS = [
        'fille' => ['Salma', 'Imane', 'Khadija', 'Nour', 'Hajar', 'Meryem', 'Sara', 'Aya', 'Oumaima', 'Chaimae', 'Yasmine', 'Hiba',
            'Rania', 'Ikram', 'Soukaina', 'Asmae', 'Ghita', 'Kenza', 'Zineb', 'Hanae', 'Loubna', 'Najwa', 'Siham', 'Wiam', 'Dounia'],
        'garcon' => ['Youssef', 'Amine', 'Mehdi', 'Hamza', 'Anas', 'Omar', 'Ayoub', 'Othmane', 'Reda', 'Zakaria', 'Ilyas', 'Badr',
            'Soufiane', 'Marouane', 'Walid', 'Tarik', 'Nabil', 'Karim', 'Hicham', 'Younes', 'Adil', 'Ismail', 'Yassine', 'Achraf', 'Bilal'],
    ];

    private const NOMS = ['Alaoui', 'Bennani', 'El Idrissi', 'Tazi', 'Berrada', 'Chraibi', 'El Fassi', 'Lahlou', 'Benjelloun', 'Amrani',
        'Naciri', 'Ouazzani', 'Sebti', 'Kettani', 'Filali', 'Belhaj', 'Cherkaoui', 'El Mansouri', 'Haddad', 'Zeroual', 'Bouzidi',
        'Lamrani', 'Mekouar', 'Skalli', 'Rahmouni', 'Saidi', 'Touzani', 'Benali', 'Kabbaj', 'Daoudi'];

    private const VILLES = ['Settat', 'Berrechid', 'El Jadida', 'Khouribga', 'Safi', 'Benslimane', 'Azemmour', 'Oued Zem'];

    private const FILIERES = ['Développement digital', 'Gestion des entreprises', 'Électromécanique des systèmes automatisés',
        'Infrastructure digitale', 'Génie civil', 'Assistant administratif'];

    private CarbonImmutable $j;

    private string $motDePasse;

    private string $pdf;

    private int $sequence = 0;

    /** @var array<string, Utilisateur> */
    private array $comptes = [];

    /** @var array<string, Internat> */
    private array $internats = [];

    public function __construct(
        private readonly GestionCampagne $campagnes,
        private readonly DossierStagiaire $dossiers,
        private readonly Instruction $instruction,
        private readonly Deliberation $deliberation,
        private readonly ConfirmationAdmission $confirmation,
        private readonly ListeAttente $listeAttente,
        private readonly SuiviPaiements $paiements,
        private readonly AffectationLits $affectations,
        private readonly VieInternat $vie,
        private readonly Restauration $restauration,
        private readonly Documents $documents,
    ) {}

    public function run(): void
    {
        if (app()->isProduction()) {
            throw new RuntimeException('Le jeu de démonstration ne s\'installe jamais en production.');
        }

        $motDePasse = (string) config('internat.demo_mot_de_passe');

        if (strlen($motDePasse) < 10) {
            throw new RuntimeException('Définissez INTERNAT_DEMO_MOT_DE_PASSE (10 caractères au moins) dans .env.');
        }

        // Haché une seule fois : tous les comptes de démonstration partagent ce mot de passe.
        $this->motDePasse = Hash::make($motDePasse);

        // Pas de file d'attente ni de courriel réel pendant le semis.
        config(['queue.default' => 'sync', 'mail.default' => 'array']);
        mt_srand(20262027);

        $this->j = CarbonImmutable::now()->startOfDay();
        $this->pdf = Pdf::loadHTML('<html><body style="font-family: DejaVu Sans; font-size: 12pt; color: #172033">'
            .'<h2>Pièce de démonstration</h2><p>Document synthétique généré pour la démonstration de l\'application INTERNAT. '
            .'Il ne contient aucune donnée réelle et n\'a aucune valeur administrative.</p></body></html>')->output();

        try {
            $this->organisation();
            $this->comptes();
            $this->grilleDeReference();
            $this->referentiels();
            $this->campagneArchivee();
            $filles = $this->campagneFillesDeliberee();
            $this->campagneGarconsEnInstruction();
            $this->campagnesDesJardins();
            $this->vieQuotidienne($filles);
            $this->restaurationDeLaSemaine();
        } finally {
            Carbon::setTestNow();
            $this->agir(null);
        }
    }

    // ------------------------------------------------------------ Organisation

    private function organisation(): void
    {
        $atlantique = Region::create(['code' => 'DRDA', 'nom' => 'Direction régionale Démo Atlantique']);
        $centre = Region::create(['code' => 'DRDC', 'nom' => 'Direction régionale Démo Centre']);
        $cmc = Complexe::create(['region_id' => $atlantique->id, 'code' => 'CMC-DA', 'nom' => 'CMC Démo Atlantique']);

        $littoral = Etablissement::create(['region_id' => $atlantique->id, 'complexe_id' => $cmc->id, 'code_efp' => 'DL10', 'nom_efp' => 'ISTA Démo Littoral']);
        $jardins = Etablissement::create(['region_id' => $atlantique->id, 'complexe_id' => $cmc->id, 'code_efp' => 'DJ20', 'nom_efp' => 'ISTA Démo Les Jardins']);
        $plateau = Etablissement::create(['region_id' => $centre->id, 'code_efp' => 'DP30', 'nom_efp' => 'ISTA Démo Le Plateau']);

        $this->internats = [
            'littoral_filles' => Internat::create(['etablissement_id' => $littoral->id, 'genre' => Genre::Fille, 'nom' => 'Maison des Stagiaires Filles — Littoral']),
            'littoral_garcons' => Internat::create(['etablissement_id' => $littoral->id, 'genre' => Genre::Garcon, 'nom' => 'Maison des Stagiaires Garçons — Littoral']),
            'jardins_filles' => Internat::create(['etablissement_id' => $jardins->id, 'genre' => Genre::Fille, 'nom' => 'Maison des Stagiaires Filles — Les Jardins']),
            'jardins_garcons' => Internat::create(['etablissement_id' => $jardins->id, 'genre' => Genre::Garcon, 'nom' => 'Maison des Stagiaires Garçons — Les Jardins']),
            'plateau_filles' => Internat::create(['etablissement_id' => $plateau->id, 'genre' => Genre::Fille, 'nom' => 'Maison des Stagiaires Filles — Le Plateau']),
        ];
    }

    private function comptes(): void
    {
        $littoral = $this->internats['littoral_filles']->etablissement;
        $jardins = $this->internats['jardins_filles']->etablissement;

        $definitions = [
            'admin' => ['Administrateur', 'Démo', 'admin@internat.test', RoleCode::Admin, []],
            'gestionnaire_filles' => ['Idrissi', 'Nadia', 'gestionnaire.filles@internat.test', RoleCode::Gestionnaire, ['internat_id' => $this->internats['littoral_filles']->id]],
            'gestionnaire_garcons' => ['Berrada', 'Karim', 'gestionnaire.garcons@internat.test', RoleCode::Gestionnaire, ['internat_id' => $this->internats['littoral_garcons']->id]],
            'chef_pole' => ['Lamrani', 'Samira', 'chef.pole@internat.test', RoleCode::ChefPole, ['etablissement_id' => $littoral->id]],
            'restauration' => ['Skalli', 'Rachid', 'restauration@internat.test', RoleCode::Restauration, ['etablissement_id' => $littoral->id]],
            'directeur' => ['Kettani', 'Hassan', 'directeur.cmc@internat.test', RoleCode::Directeur, ['complexe_id' => $littoral->complexe_id]],
            'direction_regionale' => ['Sebti', 'Latifa', 'direction.regionale@internat.test', RoleCode::DirRegionale, ['region_id' => $littoral->region_id]],
            'direction_centrale' => ['Ouazzani', 'Mohamed', 'direction.centrale@internat.test', RoleCode::DirCentrale, []],
            'gestionnaire_jardins' => ['Cherkaoui', 'Houda', 'gestionnaire.jardins@internat.test', RoleCode::Gestionnaire, ['internat_id' => $this->internats['jardins_filles']->id]],
            'chef_pole_jardins' => ['Haddad', 'Driss', 'chef.pole.jardins@internat.test', RoleCode::ChefPole, ['etablissement_id' => $jardins->id]],
        ];

        foreach ($definitions as $cle => [$nom, $prenom, $email, $role, $perimetre]) {
            $compte = Utilisateur::create(['nom' => $nom, 'prenom' => $prenom, 'email' => $email, 'mot_de_passe_hash' => $this->motDePasse, 'actif' => true] + $perimetre);
            $compte->syncRoles([$role->value]);
            $this->comptes[$cle] = $compte;
        }
    }

    /** Barème d'ILLUSTRATION totalisant 80 points — à remplacer par la grille validée par l'OFPPT. */
    private function grilleDeReference(): void
    {
        $lignes = [
            [CategorieCritere::Geographique, DeclencheurCritere::EloigneOuRural, '', 'Milieu rural ou éloignement de plus de 10 km', 25, 'Certificat de résidence'],
            [CategorieCritere::Social, DeclencheurCritere::Demuni, '', 'Stagiaire démuni', 20, 'Attestation de situation sociale'],
            [CategorieCritere::Familial, DeclencheurCritere::OrphelinOuDivorce, '', 'Orphelin ou parents divorcés', 15, 'Justificatif de situation familiale'],
            [CategorieCritere::Physique, DeclencheurCritere::BesoinsSpecifiques, '', 'Stagiaire à besoins spécifiques', 10, 'Certificat médical'],
            [CategorieCritere::Formation, DeclencheurCritere::NiveauFormation, NiveauFormation::TechnicienSpecialise->value, 'Niveau Technicien Spécialisé', 10, null],
            [CategorieCritere::Formation, DeclencheurCritere::NiveauFormation, NiveauFormation::Technicien->value, 'Niveau Technicien', 8, null],
            [CategorieCritere::Formation, DeclencheurCritere::NiveauFormation, NiveauFormation::Qualification->value, 'Niveau Qualification', 6, null],
            [CategorieCritere::Formation, DeclencheurCritere::NiveauFormation, NiveauFormation::Specialisation->value, 'Niveau Spécialisation', 4, null],
        ];

        foreach ($lignes as [$categorie, $declencheur, $valeur, $condition, $points, $piece]) {
            CritereReference::create([
                'categorie' => $categorie, 'declencheur' => $declencheur, 'valeur_attendue' => $valeur,
                'condition_texte' => $condition, 'points' => $points, 'piece_libelle' => $piece, 'actif' => true,
            ]);
        }
    }

    private function referentiels(): void
    {
        $this->batiment('littoral_filles', 'A', 'Bloc A', 2, [
            0 => [['A-001', TypeChambre::Pmr, 1], ['A-002', TypeChambre::Infirmerie, 2], ['A-003', TypeChambre::Standard, 2], ['A-004', TypeChambre::Standard, 2], ['A-005', TypeChambre::Standard, 2]],
            1 => $this->chambresStandard('A-1', 5, 2),
            2 => $this->chambresStandard('A-2', 5, 2),
        ], 'Salle d\'étude, buanderie, accès PMR au rez-de-chaussée');
        $this->batiment('littoral_filles', 'B', 'Bloc B', 1, [
            0 => array_merge([['B-001', TypeChambre::Isolement, 1]], $this->chambresStandard('B-0', 4, 3, 2)),
            1 => $this->chambresStandard('B-1', 4, 3),
        ], 'Douches collectives, salle commune');

        $this->batiment('littoral_garcons', 'C', 'Bloc C', 2, [
            0 => array_merge([['C-001', TypeChambre::Pmr, 1]], $this->chambresStandard('C-0', 4, 2, 2)),
            1 => $this->chambresStandard('C-1', 4, 2),
            2 => $this->chambresStandard('C-2', 4, 2),
        ], 'Salle d\'étude, terrain de sport attenant');

        $this->batiment('jardins_filles', 'J', 'Pavillon Jasmin', 1, [
            0 => $this->chambresStandard('J-0', 3, 2),
            1 => $this->chambresStandard('J-1', 3, 2),
        ], 'Salle d\'étude');

    }

    // --------------------------------------------------------------- Campagnes

    /** Campagne de l'année précédente, close et archivée : alimente l'historique. */
    private function campagneArchivee(): void
    {
        $internat = $this->internats['littoral_filles'];
        $debut = $this->j->subMonths(13);

        $campagne = Campagne::create([
            'internat_id' => $internat->id, 'annee' => $this->annee($debut), 'date_ouverture' => $debut->toDateString(),
            'date_cloture' => $debut->addDays(30)->toDateString(), 'nombre_places' => 14, 'frais_mensuels' => 150,
            'statut' => StatutCampagne::Cloturee, 'cree_par' => $this->comptes['chef_pole']->id,
            'date_deliberation' => $debut->addDays(36), 'delibere_par' => $this->comptes['chef_pole']->id,
            'date_publication' => $debut->addDays(36), 'archivee_le' => $this->j->subMonths(3), 'compteur_dossiers' => 16,
        ]);

        $lits = Lit::whereHas('chambre.batiment', fn ($q) => $q->where('internat_id', $internat->id))->orderBy('id')->limit(12)->get();
        $statuts = array_merge(array_fill(0, 12, StatutDemande::SejourTermine), [StatutDemande::Desiste, StatutDemande::Desiste, StatutDemande::Refuse, StatutDemande::Refuse]);

        foreach ($statuts as $i => $statut) {
            $stagiaire = $this->stagiaire($internat, $debut->addDays(3));
            $demande = Demande::create([
                'campagne_id' => $campagne->id, 'stagiaire_id' => $stagiaire->id, 'statut' => $statut,
                'numero_dossier' => sprintf('INT-%s-DL10-F-%05d', substr($campagne->annee, 0, 4), $i + 1),
                'date_soumission' => $debut->addDays(5 + $i), 'adresse_complete' => 'Adresse de démonstration',
                'score' => 70 - $i * 3, 'rang' => $i + 1, 'version_grille' => 1,
            ]);

            if ($statut === StatutDemande::SejourTermine) {
                Affectation::create([
                    'demande_id' => $demande->id, 'lit_id' => $lits[$i]->id, 'date_debut' => $debut->addDays(45)->toDateString(),
                    'date_fin' => $this->j->subMonths(3)->addDays(4)->toDateString(), 'affecte_par' => $this->comptes['gestionnaire_filles']->id,
                    'cloture_par' => $this->comptes['gestionnaire_filles']->id,
                ]);
            }
        }
    }

    /** Internat filles du Littoral : campagne délibérée, résidentes installées, liste d'attente active. */
    private function campagneFillesDeliberee(): Collection
    {
        $internat = $this->internats['littoral_filles'];
        $gestionnaire = $this->comptes['gestionnaire_filles'];
        $chef = $this->comptes['chef_pole'];

        $campagne = $this->ouvrirCampagne($internat, $chef, $this->j->subDays(40), $this->j->subDays(12), 24, 150);

        $profils = [];
        for ($i = 0; $i < 38; $i++) {
            $profils[] = [mt_rand(0, 9) < 6, mt_rand(0, 9) < 4, mt_rand(0, 9) < 3, mt_rand(0, 19) === 0, $this->niveau()];
        }

        $demandes = collect($profils)->map(fn (array $p, int $i) => $this->deposer($campagne, $internat, $this->j->subDays(38 - intdiv($i, 2)), $p));

        $this->cloturer($campagne, $this->j->subDays(11));

        foreach ($demandes as $i => $demande) {
            $issue = $i >= 35 ? 'rejet' : 'complet';
            $this->instruire($demande, $gestionnaire, $this->j->subDays(10)->addHours(9 + $i % 8), $issue, 'Pièces illisibles et non remplacées dans le délai de la campagne.');
        }

        // Délibération : le dernier classé est refusé par décision motivée du chef de pôle.
        $this->agir($chef, $this->j->subDays(6)->addHours(10));
        $dernier = Demande::where('campagne_id', $campagne->id)->where('statut', StatutDemande::Complet->value)->orderByDesc('rang')->orderByDesc('id')->first();
        $this->deliberation->valider($campagne->fresh(), $chef, [
            $dernier->id => ['decision' => TypeDecision::Refuse->value, 'motif' => 'Le stagiaire a obtenu une place dans un autre établissement d\'accueil.'],
        ], true);

        $admis = Demande::with('stagiaire')->where('campagne_id', $campagne->id)->where('statut', StatutDemande::Admis->value)->orderBy('rang')->orderBy('id')->get()->values();
        $attente = Demande::where('campagne_id', $campagne->id)->where('statut', StatutDemande::EnAttente->value)->orderBy('rang')->orderBy('id')->get()->values();

        // Confirmations : 14 admises confirment dans le délai.
        foreach ($admis->take(14) as $i => $demande) {
            $this->agir($demande->stagiaire, $this->j->subDays(5)->addHours(8 + $i));
            $this->confirmation->confirmer($demande);
        }

        // Deux désistements motivés libèrent deux places.
        foreach ([[20, RaisonDesistement::LogementAilleurs, 'J\'ai trouvé une colocation proche de l\'établissement.'],
            [21, RaisonDesistement::Eloignement, 'Je poursuis finalement ma formation dans ma ville d\'origine.']] as [$index, $raison, $texte]) {
            $this->agir($admis[$index]->stagiaire, $this->j->subDays(5)->addHours(15));
            $this->confirmation->desister($admis[$index], $raison, $texte);
        }

        // Deux candidates sont appelées de la liste d'attente.
        $this->agir($chef, $this->j->subDays(4)->addHours(9));
        $this->listeAttente->promouvoir($attente[0], $chef, null);
        $this->agir($chef, $this->j->subDays(1)->addHours(11));
        $this->listeAttente->promouvoir($attente[1], $chef, null);

        // Paiements : autorisation du chef de pôle pour 12 confirmées, deux restent à autoriser.
        $confirmees = $admis->take(14)->map->fresh();
        $this->agir($chef, $this->j->subDays(4)->addHours(14));
        foreach ($confirmees->take(12) as $demande) {
            $this->paiements->autoriser($demande->paiements()->first(), $chef);
        }

        // Reçus : sept validés, un à contrôler ; les autres n'ont pas encore payé.
        foreach ($confirmees->take(8)->values() as $i => $demande) {
            $this->agir($demande->stagiaire, $this->j->subDays(3)->addHours(9 + $i));
            $this->deposerRecu($demande->paiements()->first());
        }
        $this->agir($gestionnaire, $this->j->subDays(3)->addHours(17));
        foreach ($confirmees->take(7) as $demande) {
            $this->paiements->validerRecu($demande->paiements()->first(), $gestionnaire);
        }
        // Une confirmée a payé et attend encore l'autorisation d'occuper.
        $this->agir($confirmees[10]->stagiaire, $this->j->subDays(2)->addHours(9));
        $this->deposerRecu($confirmees[10]->paiements()->first());
        $this->agir($gestionnaire, $this->j->subDays(2)->addHours(12));
        $this->paiements->validerRecu($confirmees[10]->paiements()->first(), $gestionnaire);

        // Dossiers physiques reçus pour neuf confirmées.
        foreach ($confirmees->take(9) as $demande) {
            $demande->forceFill([
                'dossier_physique_depose' => true, 'date_depot_physique' => $this->j->subDays(3)->addHours(10),
                'dossier_physique_valide_par' => $gestionnaire->id,
            ])->save();
        }

        // Occupation autorisée et lits attribués à dix confirmées, dont deux sans reçu (paiement non bloquant).
        $lits = Lit::where('statut', StatutLit::Disponible->value)
            ->whereHas('chambre', fn ($q) => $q->where('type_chambre', TypeChambre::Standard->value)
                ->whereHas('batiment', fn ($b) => $b->where('internat_id', $internat->id)))
            ->orderBy('id')->get()->values();

        $this->agir($gestionnaire, $this->j->subDays(3)->addHours(18));
        $residentes = collect();
        foreach ($confirmees->take(10)->values() as $i => $demande) {
            $this->affectations->autoriserOccupation($demande, $gestionnaire);
            $residentes->push($this->affectations->affecter($demande->fresh(), $lits[$i], $this->j->subDays(2), $gestionnaire));
        }

        // Huit arrivées enregistrées, deux encore attendues.
        $this->agir($gestionnaire, $this->j->subDays(2)->addHours(16));
        foreach ($residentes->take(8) as $affectation) {
            $this->affectations->enregistrerArrivee($affectation, $gestionnaire);
        }

        // Suivi mensuel : autorisations du mois courant pour les résidentes.
        $this->agir(null, $this->j->addHours(6));
        $this->paiements->genererAutorisationsMensuelles();

        return $residentes->take(8)->values();
    }

    /** Internat garçons du Littoral : dépôts clos, instruction presque terminée, délibération à venir. */
    private function campagneGarconsEnInstruction(): void
    {
        $internat = $this->internats['littoral_garcons'];
        $gestionnaire = $this->comptes['gestionnaire_garcons'];
        $chef = $this->comptes['chef_pole'];

        $campagne = $this->ouvrirCampagne($internat, $chef, $this->j->subDays(32), $this->j->subDays(3), 20, 150);

        $ts = NiveauFormation::TechnicienSpecialise;
        $t = NiveauFormation::Technicien;
        $q = NiveauFormation::Qualification;

        // 26 dossiers complets ; les 18e à 21e ont le même score : égalité à la limite des 20 places.
        $complets = [
            [1, 1, 1, 1, $ts], [1, 1, 1, 0, $ts], [1, 1, 1, 0, $t], [1, 1, 0, 1, $ts], [1, 0, 1, 1, $ts], [1, 1, 0, 0, $ts], [0, 1, 1, 1, $ts],
            [1, 1, 0, 0, $t], [1, 0, 1, 0, $ts], [1, 0, 1, 0, $q], [0, 1, 1, 0, $ts], [0, 1, 1, 0, $t], [1, 0, 0, 1, $t], [0, 1, 0, 1, $t],
            [1, 0, 0, 0, $ts], [1, 0, 0, 0, $ts], [1, 0, 0, 0, $ts], [1, 0, 0, 0, $t], [1, 0, 0, 0, $t], [1, 0, 0, 0, $t], [1, 0, 0, 0, $t],
            [0, 1, 0, 0, $ts], [0, 1, 0, 0, $t], [0, 0, 1, 0, $ts], [0, 0, 0, 1, $t], [0, 0, 0, 0, $ts],
        ];

        $autres = [[1, 0, 0, 0, $ts], [0, 1, 0, 0, $t], [1, 1, 0, 0, $q], [0, 0, 1, 0, $t], [1, 0, 1, 0, $ts], [0, 1, 0, 0, $q], [1, 0, 0, 0, $t]];

        $deposes = collect(array_merge($complets, $autres))
            ->map(fn (array $p, int $i) => $this->deposer($campagne, $internat, $this->j->subDays(30 - intdiv($i, 2)), array_map(fn ($v) => is_int($v) ? (bool) $v : $v, $p)));

        // Deux brouillons jamais déposés.
        foreach ([1, 2] as $n) {
            $stagiaire = $this->stagiaire($internat, $this->j->subDays(8));
            $this->agir($stagiaire, $this->j->subDays(8)->addHours($n));
            $this->dossiers->ouvrirBrouillon($stagiaire);
        }

        $this->cloturer($campagne, $this->j->subDays(2));

        foreach ($deposes as $i => $demande) {
            $quand = $this->j->subDays(2)->addHours(9 + $i % 8);
            match (true) {
                $i < 26 => $this->instruire($demande, $gestionnaire, $quand, 'complet'),
                $i < 28 => $this->instruire($demande, $gestionnaire, $quand, 'rejet', 'Le stagiaire n\'est pas inscrit dans une filière de l\'établissement pour cette année.'),
                $i < 30 => $this->instruire($demande, $gestionnaire, $quand, 'rectification', 'La copie de la CIN est illisible : merci de déposer un nouveau scan.'),
                default => null, // trois dossiers restent à instruire
            };
        }
    }

    /** Second établissement du complexe : une campagne en cours de dépôt, une campagne en préparation. */
    private function campagnesDesJardins(): void
    {
        $chef = $this->comptes['chef_pole_jardins'];
        $filles = $this->internats['jardins_filles'];

        $campagne = $this->ouvrirCampagne($filles, $chef, $this->j->subDays(15), $this->j->addDays(14), 10, 120);

        foreach (range(0, 5) as $i) {
            $this->deposer($campagne, $filles, $this->j->subDays(12 - $i), [mt_rand(0, 1) === 1, mt_rand(0, 2) === 0, mt_rand(0, 3) === 0, false, $this->niveau()]);
        }

        foreach ([1, 2] as $n) {
            $stagiaire = $this->stagiaire($filles, $this->j->subDays(2));
            $this->agir($stagiaire, $this->j->subDays(2)->addHours($n));
            $this->dossiers->ouvrirBrouillon($stagiaire);
        }

        // Campagne garçons en préparation : le référentiel n'est pas encore importé, l'ouverture est bloquée.
        $this->agir($chef, $this->j->subDays(1)->addHours(9));
        $this->campagnes->creer($chef, [
            'annee' => $this->annee($this->j), 'date_ouverture' => $this->j->addDays(5)->toDateString(),
            'date_cloture' => $this->j->addDays(35)->toDateString(), 'frais_mensuels' => null,
            'internats' => [['internat_id' => $this->internats['jardins_garcons']->id, 'nombre_places' => 12]],
        ]);
    }

    // --------------------------------------------------------- Vie quotidienne

    /** @param Collection<int, Affectation> $residentes */
    private function vieQuotidienne(Collection $residentes): void
    {
        $internat = $this->internats['littoral_filles'];
        $gestionnaire = $this->comptes['gestionnaire_filles'];
        $stagiaire = fn (int $i): Stagiaire => $residentes[$i]->demande->stagiaire;

        // Sorties : une acceptée couvrant hier, une en attente, une refusée.
        $this->agir($stagiaire(1), $this->j->subDays(3)->addHours(12));
        $acceptee = $this->vie->deposerSortie($stagiaire(1), [
            'date_depart' => $this->j->subDays(2)->addHours(18), 'date_retour' => $this->j->addHours(8), 'motif' => 'Retour en famille pour un événement familial.',
        ]);
        $this->agir($stagiaire(3), $this->j->subDays(3)->addHours(13));
        $refusee = $this->vie->deposerSortie($stagiaire(3), [
            'date_depart' => $this->j->subDays(2)->addHours(20), 'date_retour' => $this->j->subDays(1)->addHours(23), 'motif' => 'Sortie entre amies.',
        ]);
        $this->agir($gestionnaire, $this->j->subDays(3)->addHours(16));
        $this->vie->traiterSortie($acceptee, true, null, $gestionnaire);
        $this->vie->traiterSortie($refusee, false, 'Sortie en semaine non justifiée par un motif recevable.', $gestionnaire);
        $this->agir($stagiaire(2), $this->j->subHours(3));
        $this->vie->deposerSortie($stagiaire(2), [
            'date_depart' => $this->j->addDays(2)->addHours(17), 'date_retour' => $this->j->addDays(4)->addHours(20), 'motif' => 'Rendez-vous médical dans ma ville d\'origine.',
        ]);

        // Absences d'hier : une couverte par la sortie acceptée, une non autorisée (alerte).
        $this->agir($gestionnaire, $this->j->subDays(1)->addHours(22));
        $this->vie->saisirAbsences($internat->id, $this->j->subDays(1), [$residentes[1]->id, $residentes[4]->id], $gestionnaire);

        // Incident.
        Incident::create([
            'affectation_id' => $residentes[5]->id, 'type' => 'cohabitation',
            'description' => 'Différend répété entre colocataires au sujet du bruit après 22 heures. Entretien mené avec les deux résidentes.',
            'date_incident' => $this->j->subDays(2)->addHours(22), 'saisi_par' => $gestionnaire->id,
        ]);

        // Maintenance : une chambre libre sortie de l'affectation, une demande résolue ; un lit hors service.
        $libres = Chambre::whereHas('batiment', fn ($q) => $q->where('internat_id', $internat->id)->where('code', 'B'))
            ->where('type_chambre', TypeChambre::Standard->value)->whereDoesntHave('lits', fn ($q) => $q->where('statut', StatutLit::Occupe->value))
            ->orderByDesc('id')->get();
        $this->agir($gestionnaire, $this->j->subDays(3)->addHours(9));
        $fuite = $this->vie->signalerMaintenance($libres[0], 'Fuite d\'eau au plafond, côté fenêtre.', true, $gestionnaire);
        $serrure = $this->vie->signalerMaintenance($libres[1], 'Serrure de la porte bloquée.', false, $gestionnaire);
        $this->agir($gestionnaire, $this->j->subDays(2)->addHours(9));
        $this->vie->avancerMaintenance($fuite, StatutMaintenance::EnCours, null, $gestionnaire);
        $this->vie->avancerMaintenance($serrure, StatutMaintenance::Resolue, 'Barillet remplacé par le service technique.', $gestionnaire);
        $libres[2]->lits()->orderBy('id')->first()->forceFill(['statut' => StatutLit::HorsService])->save();

        // Réclamations dans les trois domaines.
        $this->agir($stagiaire(0), $this->j->subDays(1)->addHours(8));
        $this->vie->deposerReclamation($stagiaire(0), DomaineReclamation::Hebergement, 'Pas d\'eau chaude dans les douches du premier étage depuis deux jours.');
        $this->agir($stagiaire(2), $this->j->subDays(1)->addHours(13));
        $this->vie->deposerReclamation($stagiaire(2), DomaineReclamation::Restauration, 'Le dîner est servi trop tôt pour les stagiaires dont les cours finissent à 19 heures.');
        $enAttente = Demande::where('statut', StatutDemande::EnAttente->value)
            ->whereHas('campagne', fn ($q) => $q->where('internat_id', $internat->id))->orderByDesc('rang')->first();
        $this->agir($enAttente->stagiaire, $this->j->subDays(4)->addHours(10));
        $recours = $this->vie->deposerReclamation($enAttente->stagiaire, DomaineReclamation::Selection, 'Je souhaite connaître le détail de mon classement : ma situation sociale ne semble pas avoir été prise en compte.');
        $this->agir($this->comptes['chef_pole'], $this->j->subDays(3)->addHours(10));
        $this->vie->traiterReclamation($recours, StatutReclamation::EnCours, null, $this->comptes['chef_pole']);

        // Documents utiles et régimes alimentaires.
        foreach ([['Règlement intérieur de la Maison des Stagiaires', TypeDocument::ReglementInterieur], ['Dates et modalités d\'arrivée', TypeDocument::DatesArrivee],
            ['Note d\'information — horaires du restaurant', TypeDocument::NoteInformation]] as [$titre, $type]) {
            $chemin = "documents/{$internat->id}/".Str::uuid().'.pdf';
            $this->documents->disque()->put($chemin, $this->pdf);
            Document::create([
                'internat_id' => $internat->id, 'titre' => $titre, 'type' => $type, 'fichier' => $chemin, 'nom_original' => Str::slug($titre).'.pdf',
                'mime' => 'application/pdf', 'date_publication' => $this->j->subDays(8), 'publie_par' => $gestionnaire->id,
            ]);
        }

        $justificatif = "regimes/{$stagiaire(3)->id}/".Str::uuid().'.pdf';
        $this->documents->disque()->put($justificatif, $this->pdf);
        RegimeAlimentaire::create(['stagiaire_id' => $stagiaire(3)->id, 'type' => TypeRegime::Allergie, 'precision_regime' => 'Fruits à coque', 'justificatif_fichier' => $justificatif]);
        RegimeAlimentaire::create(['stagiaire_id' => $stagiaire(6)->id, 'type' => TypeRegime::RestrictionReligieuse, 'precision_regime' => null]);
    }

    private function restaurationDeLaSemaine(): void
    {
        $internat = $this->internats['littoral_filles'];
        $responsable = $this->comptes['restauration'];

        $cartes = [
            TypeRepas::PetitDejeuner->value => ['Pain, huile d\'olive, fromage, thé à la menthe', 'Msemen, miel, lait chaud, fruit de saison', 'Baghrir, confiture, yaourt, thé'],
            TypeRepas::Dejeuner->value => ['Tajine de poulet aux olives, salade marocaine, fruit', 'Couscous aux sept légumes, lben', 'Lentilles, kefta grillée, salade, yaourt', 'Poisson au four, riz, crudités, fruit'],
            TypeRepas::Diner->value => ['Harira, dattes, œuf dur', 'Soupe de légumes, omelette, pain', 'Pâtes à la sauce tomate, salade verte, compote'],
        ];

        $menus = [];
        foreach (range(-3, 7) as $decalage) {
            $date = $this->j->addDays($decalage);
            foreach (TypeRepas::cases() as $repas) {
                $choix = $cartes[$repas->value];
                $menus[$decalage][$repas->value] = Menu::create([
                    'internat_id' => $internat->id, 'date_repas' => $date->toDateString(), 'type_repas' => $repas,
                    'description' => $choix[abs($decalage + 3) % count($choix)], 'statut' => StatutMenu::Propose, 'cree_par' => $responsable->id,
                ]);
            }
        }

        // Menus validés jusqu'à J+3 ; les suivants restent à valider.
        $this->agir($responsable, $this->j->subDays(5)->addHours(11));
        foreach (range(-3, 3) as $decalage) {
            foreach ($menus[$decalage] as $menu) {
                $this->restauration->validerMenu($menu, $responsable);
            }
        }

        $residentes = Affectation::actives()->whereHas('demande.campagne', fn ($q) => $q->where('internat_id', $internat->id))
            ->whereHas('mouvements', fn ($q) => $q->where('type', TypeMouvement::Arrivee->value))->with('demande.stagiaire')->get();

        // Réservations faites à l'avance pour hier, aujourd'hui, demain et après-demain.
        foreach ([-1 => 0, 0 => 1, 1 => 2, 2 => 4] as $decalage => $absentes) {
            foreach ($menus[$decalage] as $menu) {
                foreach ($residentes->slice($absentes) as $affectation) {
                    $this->agir($affectation->demande->stagiaire, $this->j->addDays($decalage - 1)->subHours(8));

                    try {
                        $this->restauration->reserver($affectation->demande->stagiaire, $menu->fresh());
                    } catch (RegleMetierException) {
                        // Résidente non éligible à ce repas (reçu manquant en début de mois) : pas de réservation.
                    }
                }
            }
        }

        // Hier : effectifs confirmés la veille puis service clos avec pointage (une absente au dîner).
        $this->agir($responsable, $this->j->subDays(2)->addHours(19));
        foreach ($menus[-1] as $menu) {
            $this->restauration->confirmerEffectif($menu, null, $responsable);
        }
        $this->agir($responsable, $this->j->subDays(1)->addHours(21));
        foreach ($menus[-1] as $type => $menu) {
            $pointage = $menu->reservations()->pluck('id')->mapWithKeys(fn ($id) => [$id => 'servi'])->all();
            if ($type === TypeRepas::Diner->value) {
                $pointage[array_key_first($pointage)] = 'absent';
            }
            $this->restauration->cloturerService($menu, $pointage, null, $type === TypeRepas::Diner->value ? 'Une réservation non honorée.' : null, $responsable);
        }

        // Aujourd'hui : effectifs confirmés hier soir, petit déjeuner déjà servi. Demain reste à confirmer.
        $this->agir($responsable, $this->j->subDays(1)->addHours(19));
        foreach ($menus[0] as $menu) {
            $this->restauration->confirmerEffectif($menu, null, $responsable);
        }
        $this->agir($responsable, $this->j->addHours(9));
        $matin = $menus[0][TypeRepas::PetitDejeuner->value];
        $this->restauration->cloturerService($matin, $matin->reservations()->pluck('id')->mapWithKeys(fn ($id) => [$id => 'servi'])->all(), null, null, $responsable);
    }

    // ----------------------------------------------------------------- Outils

    /** Bascule l'acteur courant (journal, historique) et l'horloge. */
    private function agir(Utilisateur|Stagiaire|null $acteur, ?CarbonImmutable $quand = null): void
    {
        Auth::guard('web')->forgetUser();
        Auth::guard('stagiaire')->forgetUser();

        if ($acteur instanceof Utilisateur) {
            Auth::guard('web')->setUser($acteur);
        } elseif ($acteur instanceof Stagiaire) {
            Auth::guard('stagiaire')->setUser($acteur);
        }

        if ($quand !== null) {
            // L'horloge simulée ne dépasse jamais l'heure réelle.
            $reel = CarbonImmutable::createFromTimestamp(time(), config('app.timezone'));
            Carbon::setTestNow($quand->greaterThan($reel) ? $reel : $quand);
        }
    }

    private function ouvrirCampagne(Internat $internat, Utilisateur $chef, CarbonImmutable $ouverture, CarbonImmutable $cloture, int $places, float $frais): Campagne
    {
        $this->agir($chef, $ouverture->subDays(4)->addHours(10));

        [$campagne] = $this->campagnes->creer($chef, [
            'annee' => $this->annee($ouverture), 'date_ouverture' => $ouverture->toDateString(), 'date_cloture' => $cloture->toDateString(),
            'frais_mensuels' => $frais, 'internats' => [['internat_id' => $internat->id, 'nombre_places' => $places]],
        ]);

        foreach (['Copie de la CIN', 'Attestation d\'inscription'] as $libelle) {
            TypePiece::create(['campagne_id' => $campagne->id, 'libelle' => $libelle, 'obligatoire' => true, 'formats_acceptes' => 'PDF, JPG, PNG, 5 Mo']);
        }

        $this->agir($chef, $ouverture->addHours(8));

        return $this->campagnes->ouvrir($campagne);
    }

    private function cloturer(Campagne $campagne, CarbonImmutable $quand): void
    {
        $this->agir(null, $quand->addMinutes(10));
        $this->campagnes->cloturer($campagne, automatique: true);
    }

    /** @param array{0: bool, 1: bool, 2: bool, 3: bool, 4: NiveauFormation} $profil */
    private function deposer(Campagne $campagne, Internat $internat, CarbonImmutable $quand, array $profil): Demande
    {
        [$eloigne, $demuni, $famille, $besoins, $niveau] = $profil;
        $stagiaire = $this->stagiaire($internat, $quand->subDay(), $niveau);

        $this->agir($stagiaire, $quand->addHours(mt_rand(8, 20))->addMinutes(mt_rand(0, 59)));
        $demande = $this->dossiers->ouvrirBrouillon($stagiaire);

        $this->dossiers->enregistrer($demande, [
            'adresse_complete' => mt_rand(1, 180).', rue de démonstration, '.$stagiaire->ville,
            'eloigne_ou_rural' => $eloigne, 'demuni' => $demuni, 'orphelin_ou_divorce' => $famille, 'besoins_specifiques' => $besoins,
            'precisions_besoins' => $besoins ? 'Mobilité réduite : chambre au rez-de-chaussée souhaitée.' : null,
        ]);

        foreach ($this->dossiers->piecesAttendues($demande->fresh()) as $attendue) {
            if ($attendue['requise']) {
                $chemin = "pieces/{$demande->id}/".Str::uuid().'.pdf';
                $this->documents->disque()->put($chemin, $this->pdf);
                PieceJointe::create([
                    'demande_id' => $demande->id, 'type_piece_id' => $attendue['type']->id, 'fichier' => $chemin, 'statut' => StatutPiece::Deposee,
                    'date_depot' => now(), 'nom_original' => Str::slug($attendue['type']->libelle).'.pdf', 'mime' => 'application/pdf', 'taille' => strlen($this->pdf),
                ]);
            }
        }

        return $this->dossiers->soumettre($demande->fresh());
    }

    private function instruire(Demande $demande, Utilisateur $gestionnaire, CarbonImmutable $quand, string $issue, ?string $motif = null): void
    {
        $this->agir($gestionnaire, $quand);
        $pieces = $demande->pieces()->get();

        foreach ($pieces as $i => $piece) {
            if ($issue === 'rectification' && $i === 0) {
                $this->instruction->controlerPiece($piece, StatutPiece::ARemplacer, 'Document illisible.', $gestionnaire);
            } elseif ($issue !== 'rejet') {
                $this->instruction->controlerPiece($piece, StatutPiece::Acceptee, null, $gestionnaire);
            }
        }

        match ($issue) {
            'complet' => $this->instruction->declarerComplet($demande->fresh(), $gestionnaire),
            'rectification' => $this->instruction->renvoyerPourRectification($demande->fresh(), (string) $motif, $gestionnaire),
            'rejet' => $this->instruction->rejeter($demande->fresh(), (string) $motif, $gestionnaire),
        };
    }

    private function deposerRecu(Paiement $paiement): void
    {
        $chemin = "recus/{$paiement->demande_id}/".Str::uuid().'.pdf';
        $this->documents->disque()->put($chemin, $this->pdf);

        $paiement->forceFill([
            'recu_fichier' => $chemin, 'recu_nom_original' => 'recu-'.$paiement->periode.'.pdf', 'recu_mime' => 'application/pdf',
            'statut' => StatutPaiement::RecuDepose, 'date_depot' => now(),
        ])->save();
    }

    private function stagiaire(Internat $internat, CarbonImmutable $importe, ?NiveauFormation $niveau = null): Stagiaire
    {
        $n = ++$this->sequence;
        $genre = $internat->genre->value;
        $prenom = self::PRENOMS[$genre][mt_rand(0, count(self::PRENOMS[$genre]) - 1)];
        $nom = self::NOMS[mt_rand(0, count(self::NOMS) - 1)];

        $stagiaire = Stagiaire::create([
            'etablissement_id' => $internat->etablissement_id,
            'cef' => sprintf('2026%09d', 100000 + $n),
            'nom' => $nom, 'prenom' => $prenom,
            'email' => Str::slug($prenom.' '.$nom, '.').$n.'@stagiaire.internat.test',
            'mot_de_passe_hash' => $this->motDePasse,
            'cni' => sprintf('ZZ%06d', $n), 'tel' => sprintf('06000%05d', $n),
            'ville' => self::VILLES[mt_rand(0, count(self::VILLES) - 1)],
            'adresse' => mt_rand(1, 180).', rue de démonstration',
            'nom_filiere' => self::FILIERES[mt_rand(0, count(self::FILIERES) - 1)],
            'annee_formation' => AnneeFormation::cases()[mt_rand(0, 2)],
            'annee_scolaire' => $this->annee($importe),
            'niveau_formation' => $niveau ?? $this->niveau(), 'genre' => $internat->genre,
        ]);

        // Compte actif et inscription en formation déjà vérifiée (profil complet).
        $stagiaire->forceFill([
            'compte_active_le' => $importe, 'mot_de_passe_modifie_le' => $importe, 'etat_compte' => EtatCompte::Actif,
            'inscrit_le' => $importe, 'email_verifie_le' => $importe, 'profil_complete_le' => $importe,
            'statut_inscription' => StatutInscription::Verifiee, 'inscription_verifiee_le' => $importe,
        ])->save();

        return $stagiaire;
    }

    private function niveau(): NiveauFormation
    {
        return NiveauFormation::cases()[[0, 0, 0, 1, 1, 2, 3][mt_rand(0, 6)]];
    }

    private function annee(CarbonImmutable $date): string
    {
        $debut = $date->month >= 7 ? $date->year : $date->year - 1;

        return $debut.'-'.($debut + 1);
    }

    /**
     * @param  array<int, list<array{0: string, 1: TypeChambre, 2: int}>>  $etages
     */
    private function batiment(string $internat, string $code, string $nom, int $nombreEtages, array $etages, string $communs): void
    {
        $batiment = Batiment::create([
            'internat_id' => $this->internats[$internat]->id, 'code' => $code, 'nom' => $nom, 'nombre_etages' => $nombreEtages,
            'statut' => StatutBatiment::Ouvert, 'equipements_communs' => $communs,
        ]);

        foreach ($etages as $etage => $chambres) {
            foreach ($chambres as [$numero, $type, $capacite]) {
                $chambre = Chambre::create([
                    'batiment_id' => $batiment->id, 'numero' => $numero, 'etage' => $etage, 'type_chambre' => $type, 'capacite' => $capacite,
                    'equipements' => $type === TypeChambre::Pmr ? 'Salle de bain adaptée, barres d\'appui' : null,
                ]);

                foreach (range(1, $capacite) as $lit) {
                    Lit::create(['chambre_id' => $chambre->id, 'numero' => (string) $lit]);
                }
            }
        }
    }

    /** @return list<array{0: string, 1: TypeChambre, 2: int}> */
    private function chambresStandard(string $prefixe, int $nombre, int $capacite, int $premier = 1): array
    {
        return array_map(
            fn (int $i) => [sprintf('%s%02d', $prefixe, $i), TypeChambre::Standard, $capacite],
            range($premier, $premier + $nombre - 1),
        );
    }
}
