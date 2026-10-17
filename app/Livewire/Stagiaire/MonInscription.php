<?php

namespace App\Livewire\Stagiaire;

use App\Domain\Applications\DossierStagiaire;
use App\Domain\Identity\ControleInscription;
use App\Domain\Identity\InscriptionStagiaire;
use App\Domain\Shared\Documents;
use App\Domain\Shared\RegleMetierException;
use App\Enums\AnneeFormation;
use App\Enums\Genre;
use App\Enums\NiveauFormation;
use App\Enums\StatutCampagne;
use App\Enums\StatutPiece;
use App\Livewire\PageStagiaire;
use App\Models\Campagne;
use App\Models\Complexe;
use App\Models\Demande;
use App\Models\Etablissement;
use App\Models\Internat;
use App\Models\PieceJointe;
use App\Models\Province;
use App\Models\Region;
use App\Models\Stagiaire;
use App\Models\TypePiece;
use App\Support\AnneeScolaire;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Features\SupportFileUploads\TemporaryUploadedFile;
use Livewire\WithFileUploads;

/**
 * « Mon inscription » : parcours unique de candidature. Le candidat déclare son
 * identité, sa formation et la campagne visée, puis sa situation et les pièces
 * justificatives des critères de sélection, et soumet le tout en une fois.
 *
 * La candidature (identité + formation + situation + pièces + justificatif de
 * scolarité) est transmise au gestionnaire de l'internat. L'écran sert ensuite
 * de suivi : état de la candidature, motif d'une rectification demandée ou d'un
 * rejet. Rien de ce qui est saisi ici n'ouvre de droit par lui-même : seule la
 * décision du gestionnaire rend la candidature valide et ouvre le séjour.
 */
#[Title('Mon inscription')]
class MonInscription extends PageStagiaire
{
    use WithFileUploads;

    private const ETAPES = ['identite', 'formation', 'situation', 'pieces', 'verification'];

    #[Url(as: 'etape', except: '')]
    public string $etape = '';

    /** @var array<string, string|null> */
    public array $identite = [
        'cef' => '', 'nom' => '', 'prenom' => '', 'cni' => '', 'genre' => '', 'tel' => '', 'ville' => '', 'adresse' => '',
    ];

    /** @var array<string, string|int|null> */
    public array $formation = [
        'etablissement_id' => '', 'nom_filiere' => '', 'niveau_formation' => '', 'annee_formation' => '', 'annee_scolaire' => '',
    ];

    /**
     * Descente hiérarchique obligatoire avant le choix de l'établissement :
     * Région → Province → Complexe → Établissement. On ne choisit jamais un
     * établissement sans passer par les niveaux supérieurs.
     */
    public string $regionId = '';

    public string $provinceId = '';

    public string $complexeId = '';

    /**
     * Campagne ouverte retenue : aucune inscription n'est possible sans campagne
     * ouverte valide, et le formulaire de formation reste verrouillé tant qu'elle
     * n'est pas choisie.
     */
    public string $campagneId = '';

    /**
     * Situation déclarée, reportée sur le dossier de candidature (critères de sélection).
     *
     * @var array<string, mixed>
     */
    public array $situation = [
        'adresse_complete' => '', 'eloigne_ou_rural' => false, 'demuni' => false, 'orphelin_ou_divorce' => false,
        'besoins_specifiques' => false, 'precisions_besoins' => '',
    ];

    /** Fichiers en cours de dépôt, par identifiant de type de pièce. */
    public array $fichiers = [];

    /** Pièce dont on affiche l'aperçu. */
    #[Locked]
    public ?int $apercu = null;

    /** Justificatif de scolarité joint à la soumission finale. */
    /** @var TemporaryUploadedFile|null */
    public $justificatif = null;

    public function mount(): void
    {
        $stagiaire = $this->stagiaire();

        foreach (array_keys($this->identite) as $champ) {
            $valeur = $stagiaire->getAttribute($champ);
            $this->identite[$champ] = $valeur instanceof \BackedEnum ? $valeur->value : (string) ($valeur ?? '');
        }

        foreach (array_keys($this->formation) as $champ) {
            $valeur = $stagiaire->getAttribute($champ);
            $this->formation[$champ] = $valeur instanceof \BackedEnum ? $valeur->value : ($valeur ?? '');
        }

        if ($this->formation['annee_scolaire'] === '') {
            $this->formation['annee_scolaire'] = AnneeScolaire::courante();
        }

        // Rétablir la cascade et la campagne à partir de l'établissement déjà déclaré.
        if ($this->formation['etablissement_id'] !== '' && $this->formation['etablissement_id'] !== null) {
            $etablissement = Etablissement::with('complexe.province')->find((int) $this->formation['etablissement_id']);

            if ($etablissement !== null) {
                $this->complexeId = (string) $etablissement->complexe_id;
                $this->provinceId = (string) ($etablissement->complexe?->province_id ?? '');
                $this->regionId = (string) ($etablissement->complexe?->province?->region_id ?? '');
                $this->campagneId = (string) ($this->campagnesOuvertes($stagiaire)->keys()->first() ?? '');
            }
        }

        $this->chargerSituation($this->demandeCourante());

        if (! in_array($this->etape, self::ETAPES, true)) {
            $this->etape = $this->etapeAttendue($stagiaire);
        }
    }

    /**
     * Campagnes ouvertes de l'établissement choisi, limitées à l'internat du
     * genre déclaré par le candidat. En pratique une au plus (un internat par
     * genre, une campagne ouverte par internat).
     *
     * @return \Illuminate\Support\Collection<int, string>
     */
    private function campagnesOuvertes(Stagiaire $stagiaire): \Illuminate\Support\Collection
    {
        $etablissementId = (int) ($this->formation['etablissement_id'] ?: 0);
        $genre = $stagiaire->genre;

        if ($etablissementId === 0 || $genre === null) {
            return collect();
        }

        return Campagne::query()
            ->where('statut', StatutCampagne::Ouverte->value)
            ->whereDate('date_ouverture', '<=', now())
            ->whereDate('date_cloture', '>=', now())
            ->whereHas('internat', fn ($q) => $q->where('etablissement_id', $etablissementId)->where('genre', $genre->value))
            ->orderByDesc('date_ouverture')
            ->get()
            ->mapWithKeys(fn (Campagne $c) => [$c->id => 'Campagne '.$c->annee.' — clôture le '.$c->date_cloture->format('d/m/Y')]);
    }

    /** Dossier de candidature courant du stagiaire (brouillon ou en instruction). */
    private function demandeCourante(): ?Demande
    {
        return $this->stagiaire()->demandeCourante();
    }

    private function chargerSituation(?Demande $demande): void
    {
        $this->situation = [
            'adresse_complete' => (string) ($demande?->adresse_complete ?: $this->stagiaire()->adresse),
            'eloigne_ou_rural' => (bool) $demande?->eloigne_ou_rural,
            'demuni' => (bool) $demande?->demuni,
            'orphelin_ou_divorce' => (bool) $demande?->orphelin_ou_divorce,
            'besoins_specifiques' => (bool) $demande?->besoins_specifiques,
            'precisions_besoins' => (string) ($demande?->precisions_besoins ?? ''),
        ];
    }

    /** Ouvre (ou récupère) le brouillon de candidature pour la campagne retenue. */
    private function demandeModifiable(DossierStagiaire $dossiers): ?Demande
    {
        $demande = $this->demandeCourante();

        if ($demande !== null && $demande->estModifiableParLeStagiaire()) {
            return $demande;
        }

        if ($demande !== null) {
            return null; // déjà soumise : plus modifiable.
        }

        try {
            return $dossiers->ouvrirBrouillon($this->stagiaire());
        } catch (RegleMetierException) {
            return null;
        }
    }

    /** Première étape qui reste à faire. */
    private function etapeAttendue(Stagiaire $stagiaire): string
    {
        if (! $stagiaire->identiteDeclaree() || blank($stagiaire->tel) || blank($stagiaire->ville) || blank($stagiaire->adresse)) {
            return 'identite';
        }

        if (! $stagiaire->profilComplet()) {
            return 'formation';
        }

        $demande = $this->demandeCourante();

        // Candidature déjà soumise (ou plus loin) : l'écran passe en suivi.
        if ($demande !== null && ! $demande->estModifiableParLeStagiaire()) {
            return 'verification';
        }

        if ($demande === null || blank($demande->adresse_complete)) {
            return 'situation';
        }

        return $this->piecesManquantes($demande)->isNotEmpty() ? 'pieces' : 'verification';
    }

    public function allerA(int $indice): void
    {
        $etape = self::ETAPES[$indice] ?? null;
        $attendue = array_search($this->etapeAttendue($this->stagiaire()), self::ETAPES, true);

        // On ne saute pas une étape non faite.
        if ($etape !== null && $indice <= $attendue) {
            $this->etape = $etape;
            $this->resetValidation();
        }
    }

    public function enregistrerIdentite(InscriptionStagiaire $inscription): void
    {
        $stagiaire = $this->stagiaire();
        $verrouillee = ! $stagiaire->statut_inscription->modifiable();

        $this->identite['cef'] = preg_replace('/\s+/', '', (string) $this->identite['cef']);
        $this->identite['tel'] = preg_replace('/[\s.\-]+/', '', (string) $this->identite['tel']);
        $this->identite['cni'] = mb_strtoupper(preg_replace('/\s+/', '', (string) $this->identite['cni']));

        $coordonnees = [
            'identite.tel' => ['required', 'string', 'regex:/^(?:\+212|0)[5-7]\d{8}$/'],
            'identite.ville' => ['required', 'string', 'min:2', 'max:100'],
            'identite.adresse' => ['required', 'string', 'min:5', 'max:255'],
        ];

        $etatCivil = $verrouillee ? [] : [
            'identite.cef' => ['required', 'string', 'regex:/^\d{13}$/', Rule::unique('stagiaire', 'cef')->ignore($stagiaire->id)],
            'identite.nom' => ['required', 'string', 'max:100', 'regex:/^[\pL][\pL\s\'’\-]*$/u'],
            'identite.prenom' => ['required', 'string', 'max:100', 'regex:/^[\pL][\pL\s\'’\-]*$/u'],
            'identite.cni' => ['nullable', 'string', 'regex:/^[A-Z]{1,2}\d{4,8}$/'],
            'identite.genre' => ['required', Rule::enum(Genre::class)],
        ];

        $valides = $this->validate($etatCivil + $coordonnees, [
            'identite.cef.regex' => 'Le CEF comporte exactement 13 chiffres.',
            'identite.cef.unique' => 'Ce CEF est déjà rattaché à un autre compte. Vérifiez votre saisie ; si le numéro est bien le vôtre, contactez l\'administration de votre établissement.',
            'identite.nom.regex' => 'Le nom ne peut contenir que des lettres, des espaces, des tirets et des apostrophes.',
            'identite.prenom.regex' => 'Le prénom ne peut contenir que des lettres, des espaces, des tirets et des apostrophes.',
            'identite.cni.regex' => 'Numéro de CNI invalide : une ou deux lettres suivies de chiffres, par exemple AB123456.',
            'identite.tel.regex' => 'Numéro de téléphone invalide : dix chiffres commençant par 05, 06 ou 07, ou le format +212.',
        ], [
            'identite.cef' => 'CEF', 'identite.nom' => 'nom', 'identite.prenom' => 'prénom', 'identite.cni' => 'CNI',
            'identite.genre' => 'genre', 'identite.tel' => 'téléphone', 'identite.ville' => 'ville', 'identite.adresse' => 'adresse',
        ])['identite'];

        if (array_key_exists('cni', $valides) && $valides['cni'] === '') {
            $valides['cni'] = null;
        }

        if ($this->executer(fn () => $inscription->declarerIdentite($stagiaire, $valides), 'Vos informations sont enregistrées.')) {
            $this->etape = 'formation';
        }
    }

    public function enregistrerFormation(InscriptionStagiaire $inscription, DossierStagiaire $dossiers): void
    {
        $stagiaire = $this->stagiaire();

        // La campagne doit être une campagne ouverte de l'internat correspondant à
        // l'établissement NOUVELLEMENT choisi et au genre du candidat — jamais celui
        // d'un établissement précédemment enregistré.
        $internatVise = $stagiaire->genre === null ? null : Internat::query()
            ->where('etablissement_id', (int) ($this->formation['etablissement_id'] ?: 0))
            ->where('genre', $stagiaire->genre->value)
            ->value('id');

        // L'établissement doit appartenir au complexe choisi (lui-même issu de la
        // province et de la région sélectionnées) : la hiérarchie est respectée de bout en bout.
        $donnees = $this->validate([
            'regionId' => ['required', 'integer', Rule::exists('region', 'id')],
            'provinceId' => ['required', 'integer', Rule::exists('province', 'id')->where('region_id', $this->regionId ?: 0)],
            'complexeId' => ['required', 'integer', Rule::exists('complexe', 'id')->where('province_id', $this->provinceId ?: 0)],
            'formation.etablissement_id' => ['required', 'integer', Rule::exists('etablissement', 'id')->where('complexe_id', $this->complexeId ?: 0)],
            'campagneId' => [
                'required', 'integer',
                Rule::exists('campagne', 'id')
                    ->where('statut', StatutCampagne::Ouverte->value)
                    ->where('internat_id', $internatVise ?? 0),
            ],
            'formation.nom_filiere' => ['required', 'string', 'min:3', 'max:190'],
            'formation.niveau_formation' => ['required', Rule::enum(NiveauFormation::class)],
            'formation.annee_formation' => ['required', Rule::enum(AnneeFormation::class)],
            'formation.annee_scolaire' => ['required', Rule::in(array_keys(AnneeScolaire::options()))],
        ], [
            'regionId.required' => 'Choisissez votre région.',
            'provinceId.required' => 'Choisissez votre province.',
            'provinceId.exists' => 'La province doit appartenir à la région choisie.',
            'complexeId.required' => 'Choisissez votre complexe / CMC.',
            'complexeId.exists' => 'Le complexe doit appartenir à la province choisie.',
            'formation.etablissement_id.required' => 'Choisissez votre établissement dans la liste.',
            'formation.etablissement_id.exists' => 'L\'établissement doit appartenir au complexe choisi.',
            'campagneId.required' => 'Sélectionnez une campagne ouverte pour poursuivre votre inscription.',
            'campagneId.exists' => 'Cette campagne n\'est plus ouverte pour votre internat. Choisissez une campagne ouverte.',
        ], [
            'regionId' => 'région', 'provinceId' => 'province', 'complexeId' => 'complexe',
            'formation.etablissement_id' => 'établissement', 'campagneId' => 'campagne',
            'formation.nom_filiere' => 'filière', 'formation.niveau_formation' => 'niveau de formation',
            'formation.annee_formation' => 'année de formation', 'formation.annee_scolaire' => 'année scolaire',
        ]);

        $valides = $donnees['formation'];
        $valides['nom_filiere'] = trim((string) $valides['nom_filiere']);

        if (! $this->executer(fn () => $inscription->declarerFormation($stagiaire, $valides), 'Votre formation est enregistrée.')) {
            return;
        }

        // La formation enregistrée fixe l'internat et la campagne : on ouvre le dossier de candidature.
        $demande = $this->demandeModifiable($dossiers);
        $this->chargerSituation($demande);
        $this->etape = 'situation';
    }

    public function enregistrerSituation(DossierStagiaire $dossiers): void
    {
        $valides = $this->validate([
            'situation.adresse_complete' => ['required', 'string', 'max:500'],
            'situation.eloigne_ou_rural' => ['boolean'],
            'situation.demuni' => ['boolean'],
            'situation.orphelin_ou_divorce' => ['boolean'],
            'situation.besoins_specifiques' => ['boolean'],
            'situation.precisions_besoins' => ['nullable', 'string', 'max:500'],
        ], attributes: ['situation.adresse_complete' => 'adresse complète', 'situation.precisions_besoins' => 'précisions'])['situation'];

        $demande = $this->demandeModifiable($dossiers);

        if ($demande === null) {
            $this->message('Aucune campagne ouverte ne permet d\'ouvrir votre dossier.', 'danger');

            return;
        }

        if ($this->executer(fn () => $dossiers->enregistrer($demande, $valides), 'Votre situation est enregistrée.')) {
            $this->etape = 'pieces';
        }
    }

    /** Dès qu'un fichier est choisi pour une pièce, il est contrôlé puis conservé. */
    public function updatedFichiers(mixed $fichier, string $cle): void
    {
        $type = TypePiece::find((int) $cle);
        $demande = $this->demandeModifiable(app(DossierStagiaire::class));

        if ($type === null || $fichier === null || $demande === null) {
            return;
        }

        $this->executer(fn () => app(DossierStagiaire::class)->deposerPiece($demande, $type, $fichier), "{$type->libelle} : fichier déposé.");

        unset($this->fichiers[$cle]);
    }

    public function retirerPiece(DossierStagiaire $dossiers, int $piece): void
    {
        $demande = $this->demandeCourante();
        abort_if($demande === null, 404);
        $ligne = PieceJointe::where('demande_id', $demande->id)->findOrFail($piece);

        $this->executer(fn () => $dossiers->retirerPiece($demande, $ligne), 'Pièce retirée.');
    }

    public function voirPiece(int $piece): void
    {
        $demande = $this->demandeCourante();
        abort_unless($demande !== null && PieceJointe::where('demande_id', $demande->id)->whereKey($piece)->exists(), 404);

        $this->apercu = $piece;
        $this->ouvrirDialogue('piece');
    }

    public function fermerDialogue(): void
    {
        $this->apercu = null;
        $this->dialogue = null;
        $this->resetValidation();
    }

    /**
     * Soumission unique de la candidature : l'inscription en formation (avec son
     * justificatif de scolarité) puis le dossier (situation + pièces) sont
     * transmis ensemble au gestionnaire.
     */
    public function soumettre(ControleInscription $controle, DossierStagiaire $dossiers): void
    {
        $this->validate([
            'justificatif' => [$controle->justificatifObligatoire() ? 'required' : 'nullable', 'file', 'max:'.config('internat.documents.taille_max_ko')],
        ], [
            'justificatif.required' => 'Joignez un justificatif de scolarité : attestation d\'inscription ou reçu des frais de scolarité.',
            'justificatif.max' => 'Fichier trop volumineux (maximum '.round(config('internat.documents.taille_max_ko') / 1024, 1).' Mo).',
        ], ['justificatif' => 'justificatif']);

        $demande = $this->demandeCourante();

        if ($demande === null || ! $demande->estModifiableParLeStagiaire()) {
            $this->message('Votre candidature a déjà été soumise.', 'danger');

            return;
        }

        // On s'assure que le dossier est complet avant de soumettre l'inscription,
        // pour que les deux soumissions aboutissent ensemble.
        if (blank($demande->adresse_complete)) {
            $this->message('Renseignez votre adresse à l\'étape « Ma situation ».', 'danger');
            $this->etape = 'situation';

            return;
        }

        $manquantes = $this->piecesManquantes($demande);

        if ($manquantes->isNotEmpty()) {
            $this->message('Pièces obligatoires manquantes : '.$manquantes->implode(', ').'.', 'danger');
            $this->etape = 'pieces';

            return;
        }

        $justificatif = $this->justificatif;

        $reussie = $this->executer(function () use ($controle, $dossiers, $demande, $justificatif) {
            DB::transaction(function () use ($controle, $dossiers, $demande, $justificatif) {
                $controle->soumettre($this->stagiaire()->refresh(), $justificatif);
                $dossiers->soumettre($demande->refresh());
            });
        }, 'Votre candidature est transmise au gestionnaire de l\'internat.');

        if ($reussie) {
            $this->reset('justificatif');
            $this->etape = 'verification';
        }
    }

    public function retirer(ControleInscription $controle): void
    {
        $this->executer(fn () => $controle->retirer($this->stagiaire()), 'Votre demande est retirée : vous pouvez corriger votre déclaration.');
    }

    /** Changer de région réinitialise toute la descente et la campagne. */
    public function updatedRegionId(): void
    {
        $this->provinceId = '';
        $this->complexeId = '';
        $this->formation['etablissement_id'] = '';
        $this->campagneId = '';
        $this->resetValidation(['formation.etablissement_id', 'campagneId']);
    }

    public function updatedProvinceId(): void
    {
        $this->complexeId = '';
        $this->formation['etablissement_id'] = '';
        $this->campagneId = '';
        $this->resetValidation(['formation.etablissement_id', 'campagneId']);
    }

    public function updatedComplexeId(): void
    {
        $this->formation['etablissement_id'] = '';
        $this->campagneId = '';
        $this->resetValidation(['formation.etablissement_id', 'campagneId']);
    }

    /** Un nouvel établissement remet à zéro la campagne ; s'il n'y en a qu'une, on la présélectionne. */
    public function updatedFormationEtablissementId(): void
    {
        $ouvertes = $this->campagnesOuvertes($this->stagiaire());
        $this->campagneId = $ouvertes->count() === 1 ? (string) $ouvertes->keys()->first() : '';
        $this->resetValidation(['formation.etablissement_id', 'campagneId']);
    }

    /**
     * Pièces obligatoires encore absentes ou à remplacer.
     *
     * @return \Illuminate\Support\Collection<int, string>
     */
    private function piecesManquantes(Demande $demande): \Illuminate\Support\Collection
    {
        return app(DossierStagiaire::class)->piecesAttendues($demande)
            ->filter(fn (array $p) => $p['requise'] && ($p['piece'] === null || $p['piece']->statut === StatutPiece::ARemplacer))
            ->map(fn (array $p) => $p['type']->libelle)
            ->values();
    }

    public function render()
    {
        $stagiaire = $this->stagiaire()->load('etablissement');
        $statut = $stagiaire->statut_inscription;

        // Descente hiérarchique : chaque niveau n'est peuplé qu'une fois le parent choisi.
        $provinces = $this->regionId !== '' ? Province::where('region_id', $this->regionId)->orderBy('nom')->pluck('nom', 'id') : collect();
        $complexes = $this->provinceId !== '' ? Complexe::where('province_id', $this->provinceId)->orderBy('nom')->pluck('nom', 'id') : collect();
        $etablissements = $this->complexeId !== ''
            ? Etablissement::where('complexe_id', $this->complexeId)->orderBy('nom_efp')->get(['id', 'nom_efp', 'code_efp'])
                ->mapWithKeys(fn (Etablissement $e) => [$e->id => $e->nom_efp.' — '.$e->code_efp])
            : collect();

        $campagnes = $this->campagnesOuvertes($stagiaire);
        $efpChoisi = $this->formation['etablissement_id'] !== '' ? Etablissement::find((int) $this->formation['etablissement_id'], ['id', 'nom_efp', 'code_efp']) : null;

        // Dossier de candidature et pièces attendues (situation / pièces / récapitulatif).
        $demande = $this->demandeCourante();
        $dossiers = app(DossierStagiaire::class);
        $pieces = $demande !== null ? $dossiers->piecesAttendues($demande) : collect();
        $requises = $pieces->filter(fn (array $p) => $p['requise']);
        $manquantes = $requises->filter(fn (array $p) => $p['piece'] === null || $p['piece']->statut === StatutPiece::ARemplacer);
        $pieceAffichee = $this->apercu !== null ? $pieces->pluck('piece')->filter()->firstWhere('id', $this->apercu) : null;

        return view('livewire.stagiaire.mon-inscription', [
            'stagiaire' => $stagiaire,
            'statut' => $statut,
            'modifiable' => $statut->modifiable(),
            'etapes' => ['Identité', 'Formation', 'Situation', 'Pièces', 'Vérification'],
            'indice' => (int) array_search($this->etape, self::ETAPES, true),
            'regions' => Region::orderBy('nom')->pluck('nom', 'id'),
            'provinces' => $provinces,
            'complexes' => $complexes,
            'etablissements' => $etablissements,
            'campagnes' => $campagnes,
            'campagneRequise' => $this->formation['etablissement_id'] !== '',
            'efpChoisi' => $efpChoisi,
            'demande' => $demande,
            'pieces' => $pieces,
            'requises' => $requises,
            'manquantes' => $manquantes,
            'pieceAffichee' => $pieceAffichee,
            'reglesPiece' => fn (TypePiece $type) => app(Documents::class)->regles($type->formats_acceptes),
            'historique' => $stagiaire->verificationsInscription()->with('agent:id,nom,prenom', 'etablissement:id,nom_efp')->latest('id')->limit(10)->get(),
            'enAttente' => $stagiaire->verificationsInscription()->enAttente()->latest('id')->first(),
            'justificatifObligatoire' => app(ControleInscription::class)->justificatifObligatoire(),
            'anneesScolaires' => AnneeScolaire::options(),
        ]);
    }
}
