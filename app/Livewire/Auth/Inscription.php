<?php

namespace App\Livewire\Auth;

use App\Domain\Applications\DossierStagiaire;
use App\Domain\Identity\Connexion;
use App\Domain\Identity\ControleInscription;
use App\Domain\Identity\InscriptionStagiaire;
use App\Domain\Shared\Documents;
use App\Domain\Shared\RegleMetierException;
use App\Enums\AnneeFormation;
use App\Enums\Genre;
use App\Enums\NiveauFormation;
use App\Enums\StatutCampagne;
use App\Livewire\Concerns\AvecLimiteDeDebit;
use App\Models\Campagne;
use App\Models\Complexe;
use App\Models\Etablissement;
use App\Models\Internat;
use App\Models\Province;
use App\Models\Region;
use App\Models\Stagiaire;
use App\Models\TypePiece;
use App\Support\AnneeScolaire;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;
use Livewire\Features\SupportFileUploads\TemporaryUploadedFile;
use Livewire\WithFileUploads;

/**
 * Inscription d'un candidat, de bout en bout et SANS compte : il renseigne son
 * compte souhaité, son identité, sa formation, sa situation et ses pièces, le
 * tout conservé le temps de la session (état du composant). Le compte stagiaire
 * n'est créé qu'au clic « Soumettre ma candidature », qui enregistre alors la
 * candidature et ouvre la session.
 *
 * Aucune confirmation d'adresse e-mail n'est exigée : seuls le format et
 * l'unicité sont contrôlés.
 */
#[Layout('layouts.auth', ['large' => true])]
#[Title('Inscription')]
class Inscription extends Component
{
    use AvecLimiteDeDebit;
    use WithFileUploads;

    private const ETAPES = ['compte', 'identite', 'formation', 'situation', 'pieces', 'verification'];

    public int $etape = 0;

    /** Étape la plus avancée atteinte : on ne saute pas une étape non validée. */
    public int $etapeMax = 0;

    /** @var array<string, string> */
    public array $compte = ['email' => '', 'mot_de_passe' => '', 'mot_de_passe_confirmation' => ''];

    /** @var array<string, string|null> */
    public array $identite = [
        'cef' => '', 'nom' => '', 'prenom' => '', 'cni' => '', 'genre' => '', 'tel' => '', 'ville' => '', 'adresse' => '',
    ];

    /** @var array<string, string|int|null> */
    public array $formation = [
        'etablissement_id' => '', 'nom_filiere' => '', 'niveau_formation' => '', 'annee_formation' => '', 'annee_scolaire' => '',
    ];

    public string $regionId = '';

    public string $provinceId = '';

    public string $complexeId = '';

    public string $campagneId = '';

    /** @var array<string, mixed> */
    public array $situation = [
        'adresse_complete' => '', 'eloigne_ou_rural' => false, 'demuni' => false, 'orphelin_ou_divorce' => false,
        'besoins_specifiques' => false, 'precisions_besoins' => '',
    ];

    /** Pièces tenues comme fichiers temporaires, par identifiant de type de pièce. */
    public array $fichiers = [];

    /** @var TemporaryUploadedFile|null */
    public $justificatif = null;

    public function mount(): void
    {
        $this->formation['annee_scolaire'] = AnneeScolaire::courante();
    }

    public function allerA(int $indice): void
    {
        if ($indice >= 0 && $indice <= $this->etapeMax && $indice < count(self::ETAPES)) {
            $this->etape = $indice;
            $this->resetValidation();
        }
    }

    private function avancer(int $vers): void
    {
        $this->etape = $vers;
        $this->etapeMax = max($this->etapeMax, $vers);
    }

    // ------------------------------------------------------------- Étape Compte

    public function continuerCompte(): void
    {
        $this->compte['email'] = mb_strtolower(trim((string) $this->compte['email']));

        // Limite les tentatives d'inscription (et donc le sondage d'adresses) par e-mail et par poste.
        $this->limiter('inscription', $this->compte['email'], 'compte.email');

        $this->validate([
            'compte.email' => ['required', 'email:rfc', 'max:190'],
            'compte.mot_de_passe' => ['required', 'string', 'max:200', 'confirmed', Password::defaults()],
        ], [
            'compte.mot_de_passe.confirmed' => 'Les deux mots de passe ne correspondent pas.',
        ], [
            'compte.email' => 'adresse e-mail', 'compte.mot_de_passe' => 'mot de passe',
        ]);

        $local = Str::before($this->compte['email'], '@');

        if (mb_strlen($local) >= 4 && Str::contains(mb_strtolower((string) $this->compte['mot_de_passe']), $local)) {
            throw ValidationException::withMessages(['compte.mot_de_passe' => 'Le mot de passe ne doit pas contenir votre adresse e-mail.']);
        }

        if (Stagiaire::where('email', $this->compte['email'])->exists()) {
            throw ValidationException::withMessages(['compte.email' => 'Cette adresse e-mail est déjà utilisée.']);
        }

        $this->avancer(1);
    }

    // ----------------------------------------------------------- Étape Identité

    public function continuerIdentite(): void
    {
        $this->identite['cef'] = preg_replace('/\s+/', '', (string) $this->identite['cef']);
        $this->identite['tel'] = preg_replace('/[\s.\-]+/', '', (string) $this->identite['tel']);
        $this->identite['cni'] = mb_strtoupper(preg_replace('/\s+/', '', (string) $this->identite['cni']));

        $this->validate([
            'identite.cef' => ['required', 'string', 'regex:/^\d{13}$/', Rule::unique('stagiaire', 'cef')],
            'identite.nom' => ['required', 'string', 'max:100', 'regex:/^[\pL][\pL\s\'’\-]*$/u'],
            'identite.prenom' => ['required', 'string', 'max:100', 'regex:/^[\pL][\pL\s\'’\-]*$/u'],
            'identite.cni' => ['nullable', 'string', 'regex:/^[A-Z]{1,2}\d{4,8}$/'],
            'identite.genre' => ['required', Rule::enum(Genre::class)],
            'identite.tel' => ['required', 'string', 'regex:/^(?:\+212|0)[5-7]\d{8}$/'],
            'identite.ville' => ['required', 'string', 'min:2', 'max:100'],
            'identite.adresse' => ['required', 'string', 'min:5', 'max:255'],
        ], $this->messagesIdentite(), $this->attributsIdentite());

        if ($this->situation['adresse_complete'] === '') {
            $this->situation['adresse_complete'] = (string) $this->identite['adresse'];
        }

        $this->avancer(2);
    }

    // ---------------------------------------------------------- Étape Formation

    public function continuerFormation(): void
    {
        $internatVise = $this->identite['genre'] === '' ? null : Internat::query()
            ->where('etablissement_id', (int) ($this->formation['etablissement_id'] ?: 0))
            ->where('genre', $this->identite['genre'])
            ->value('id');

        $this->validate([
            'regionId' => ['required', 'integer', Rule::exists('region', 'id')],
            'provinceId' => ['required', 'integer', Rule::exists('province', 'id')->where('region_id', $this->regionId ?: 0)],
            'complexeId' => ['required', 'integer', Rule::exists('complexe', 'id')->where('province_id', $this->provinceId ?: 0)],
            'formation.etablissement_id' => ['required', 'integer', Rule::exists('etablissement', 'id')->where('complexe_id', $this->complexeId ?: 0)],
            'campagneId' => [
                'required', 'integer',
                Rule::exists('campagne', 'id')->where('statut', StatutCampagne::Ouverte->value)->where('internat_id', $internatVise ?? 0),
            ],
            'formation.nom_filiere' => ['required', 'string', 'min:3', 'max:190'],
            'formation.niveau_formation' => ['required', Rule::enum(NiveauFormation::class)],
            'formation.annee_formation' => ['required', Rule::enum(AnneeFormation::class)],
            'formation.annee_scolaire' => ['required', Rule::in(array_keys(AnneeScolaire::options()))],
        ], $this->messagesFormation(), $this->attributsFormation());

        $this->formation['nom_filiere'] = trim((string) $this->formation['nom_filiere']);

        $this->avancer(3);
    }

    // ---------------------------------------------------------- Étape Situation

    public function continuerSituation(): void
    {
        $this->validate([
            'situation.adresse_complete' => ['required', 'string', 'max:500'],
            'situation.eloigne_ou_rural' => ['boolean'],
            'situation.demuni' => ['boolean'],
            'situation.orphelin_ou_divorce' => ['boolean'],
            'situation.besoins_specifiques' => ['boolean'],
            'situation.precisions_besoins' => ['nullable', 'string', 'max:500'],
        ], attributes: ['situation.adresse_complete' => 'adresse complète', 'situation.precisions_besoins' => 'précisions']);

        if (! $this->situation['besoins_specifiques']) {
            $this->situation['precisions_besoins'] = '';
        }

        $this->avancer(4);
    }

    // ------------------------------------------------------------- Étape Pièces

    /** Un fichier choisi est contrôlé (type, taille) puis conservé temporairement. */
    public function updatedFichiers(mixed $fichier, string $cle): void
    {
        $type = TypePiece::find((int) $cle);

        if ($type === null || ! $fichier instanceof TemporaryUploadedFile) {
            unset($this->fichiers[$cle]);

            return;
        }

        $regles = app(Documents::class)->regles($type->formats_acceptes);

        try {
            $this->validate([
                "fichiers.{$cle}" => ['file', 'mimes:'.implode(',', $regles['extensions']), 'max:'.$regles['taille_max_ko']],
            ], [
                "fichiers.{$cle}.mimes" => 'Format accepté : '.mb_strtoupper(implode(', ', $regles['extensions'])).'.',
                "fichiers.{$cle}.max" => 'Fichier trop volumineux (maximum '.round($regles['taille_max_ko'] / 1024, 1).' Mo).',
            ], ["fichiers.{$cle}" => 'pièce']);
        } catch (ValidationException $e) {
            unset($this->fichiers[$cle]);

            throw $e;
        }
    }

    public function retirerPiece(string $cle): void
    {
        unset($this->fichiers[$cle]);
    }

    public function continuerPieces(): void
    {
        $manquantes = $this->piecesManquantes();

        if ($manquantes->isNotEmpty()) {
            $this->addError('pieces', 'Pièces obligatoires manquantes : '.$manquantes->implode(', ').'.');

            return;
        }

        $this->avancer(5);
    }

    // -------------------------------------------------------- Soumission finale

    public function soumettre(InscriptionStagiaire $inscription, ControleInscription $controle, DossierStagiaire $dossiers, Connexion $connexion): void
    {
        // Re-contrôle de l'ensemble (toutes les étapes), au cas où l'on soumet directement.
        $this->continuerCompte();
        $this->continuerIdentite();
        $this->continuerFormation();
        $this->continuerSituation();

        $this->validate([
            'justificatif' => [$controle->justificatifObligatoire() ? 'required' : 'nullable', 'file', 'mimes:pdf,jpg,jpeg,png', 'max:'.config('internat.documents.taille_max_ko')],
        ], [
            'justificatif.required' => 'Joignez un justificatif de scolarité : attestation d\'inscription ou reçu des frais de scolarité.',
            'justificatif.max' => 'Fichier trop volumineux (maximum '.round(config('internat.documents.taille_max_ko') / 1024, 1).' Mo).',
        ], ['justificatif' => 'justificatif']);

        if ($this->piecesManquantes()->isNotEmpty()) {
            $this->etape = 4;
            $this->addError('pieces', 'Pièces obligatoires manquantes : '.$this->piecesManquantes()->implode(', ').'.');

            return;
        }

        try {
            $stagiaire = DB::transaction(function () use ($inscription, $controle, $dossiers) {
                $stagiaire = $inscription->creerCompte($this->compte['email'], $this->compte['mot_de_passe'], adresseConfirmee: true);

                $stagiaire = $inscription->declarerIdentite($stagiaire, $this->identiteNormalisee());
                $stagiaire = $inscription->declarerFormation($stagiaire, collect($this->formation)->only(['etablissement_id', 'nom_filiere', 'niveau_formation', 'annee_formation', 'annee_scolaire'])->all());

                $demande = $dossiers->ouvrirBrouillon($stagiaire->fresh());
                $dossiers->enregistrer($demande, $this->situation);

                foreach ($this->fichiers as $typeId => $fichier) {
                    $type = TypePiece::find((int) $typeId);

                    if ($type !== null && $fichier instanceof TemporaryUploadedFile) {
                        $dossiers->deposerPiece($demande->fresh(), $type, $fichier);
                    }
                }

                $controle->soumettre($stagiaire->fresh(), $this->justificatif);
                $dossiers->soumettre($demande->fresh());

                return $stagiaire;
            });
        } catch (RegleMetierException $regle) {
            $this->signaler($regle);

            return;
        }

        $connexion->ouvrirPourStagiaire($stagiaire);
        session()->flash('message', ['ton' => 'succes', 'texte' => 'Votre candidature est transmise au gestionnaire de votre internat.']);
        $this->redirect(route('stagiaire.inscription'), navigate: false);
    }

    /** Oriente l'erreur métier vers l'étape et le champ concernés. */
    private function signaler(RegleMetierException $regle): void
    {
        [$etape, $champ] = match ($regle->codeRegle) {
            'adresse_deja_utilisee', 'adresse_indisponible' => [0, 'compte.email'],
            'cef_deja_utilise' => [1, 'identite.cef'],
            'aucune_campagne', 'hors_delai' => [2, 'campagneId'],
            'pieces_manquantes' => [4, 'pieces'],
            default => [$this->etape, 'soumission'],
        };

        $this->etape = $etape;
        $this->addError($champ, $regle->getMessage());
    }

    // --------------------------------------------------------------- Aides

    /** @return \Illuminate\Support\Collection<int, string> campagnes ouvertes de l'établissement, pour le genre déclaré */
    private function campagnesOuvertes(): \Illuminate\Support\Collection
    {
        $etablissementId = (int) ($this->formation['etablissement_id'] ?: 0);

        if ($etablissementId === 0 || $this->identite['genre'] === '') {
            return collect();
        }

        return Campagne::query()
            ->where('statut', StatutCampagne::Ouverte->value)
            ->whereDate('date_ouverture', '<=', now())
            ->whereDate('date_cloture', '>=', now())
            ->whereHas('internat', fn ($q) => $q->where('etablissement_id', $etablissementId)->where('genre', $this->identite['genre']))
            ->orderByDesc('date_ouverture')
            ->get()
            ->mapWithKeys(fn (Campagne $c) => [$c->id => 'Campagne '.$c->annee.' — clôture le '.$c->date_cloture->format('d/m/Y')]);
    }

    /** @return \Illuminate\Support\Collection<int, array{type: TypePiece, requise: bool, raison: string|null}> */
    private function piecesAttendues(): \Illuminate\Support\Collection
    {
        $campagne = $this->campagneId !== '' ? Campagne::find((int) $this->campagneId) : null;

        if ($campagne === null) {
            return collect();
        }

        $niveau = $this->formation['niveau_formation'] !== '' ? NiveauFormation::tryFrom((string) $this->formation['niveau_formation']) : null;

        return app(DossierStagiaire::class)->piecesRequises($campagne, [
            'eloigne_ou_rural' => (bool) $this->situation['eloigne_ou_rural'],
            'demuni' => (bool) $this->situation['demuni'],
            'orphelin_ou_divorce' => (bool) $this->situation['orphelin_ou_divorce'],
            'besoins_specifiques' => (bool) $this->situation['besoins_specifiques'],
        ], $niveau);
    }

    /** @return \Illuminate\Support\Collection<int, string> libellés des pièces obligatoires non fournies */
    private function piecesManquantes(): \Illuminate\Support\Collection
    {
        return $this->piecesAttendues()
            ->filter(fn (array $p) => $p['requise'] && ! isset($this->fichiers[(string) $p['type']->id]))
            ->map(fn (array $p) => $p['type']->libelle)
            ->values();
    }

    /** @return array<string, mixed> */
    private function identiteNormalisee(): array
    {
        $donnees = $this->identite;
        $donnees['cni'] = ($donnees['cni'] ?? '') === '' ? null : $donnees['cni'];

        return $donnees;
    }

    public function updatedRegionId(): void
    {
        $this->provinceId = '';
        $this->complexeId = '';
        $this->formation['etablissement_id'] = '';
        $this->campagneId = '';
    }

    public function updatedProvinceId(): void
    {
        $this->complexeId = '';
        $this->formation['etablissement_id'] = '';
        $this->campagneId = '';
    }

    public function updatedComplexeId(): void
    {
        $this->formation['etablissement_id'] = '';
        $this->campagneId = '';
    }

    public function updatedFormationEtablissementId(): void
    {
        $ouvertes = $this->campagnesOuvertes();
        $this->campagneId = $ouvertes->count() === 1 ? (string) $ouvertes->keys()->first() : '';
    }

    /** @return array<string, string> */
    private function messagesIdentite(): array
    {
        return [
            'identite.cef.regex' => 'Le CEF comporte exactement 13 chiffres.',
            'identite.cef.unique' => 'Ce CEF est déjà rattaché à un autre compte. Vérifiez votre saisie ; si le numéro est bien le vôtre, contactez l\'administration de votre établissement.',
            'identite.nom.regex' => 'Le nom ne peut contenir que des lettres, des espaces, des tirets et des apostrophes.',
            'identite.prenom.regex' => 'Le prénom ne peut contenir que des lettres, des espaces, des tirets et des apostrophes.',
            'identite.cni.regex' => 'Numéro de CNI invalide : une ou deux lettres suivies de chiffres, par exemple AB123456.',
            'identite.tel.regex' => 'Numéro de téléphone invalide : dix chiffres commençant par 05, 06 ou 07, ou le format +212.',
        ];
    }

    /** @return array<string, string> */
    private function attributsIdentite(): array
    {
        return [
            'identite.cef' => 'CEF', 'identite.nom' => 'nom', 'identite.prenom' => 'prénom', 'identite.cni' => 'CNI',
            'identite.genre' => 'genre', 'identite.tel' => 'téléphone', 'identite.ville' => 'ville', 'identite.adresse' => 'adresse',
        ];
    }

    /** @return array<string, string> */
    private function messagesFormation(): array
    {
        return [
            'regionId.required' => 'Choisissez votre région.',
            'provinceId.required' => 'Choisissez votre province.',
            'provinceId.exists' => 'La province doit appartenir à la région choisie.',
            'complexeId.required' => 'Choisissez votre complexe / CMC.',
            'complexeId.exists' => 'Le complexe doit appartenir à la province choisie.',
            'formation.etablissement_id.required' => 'Choisissez votre établissement dans la liste.',
            'formation.etablissement_id.exists' => 'L\'établissement doit appartenir au complexe choisi.',
            'campagneId.required' => 'Sélectionnez une campagne ouverte pour poursuivre votre inscription.',
            'campagneId.exists' => 'Cette campagne n\'est plus ouverte pour votre internat. Choisissez une campagne ouverte.',
        ];
    }

    /** @return array<string, string> */
    private function attributsFormation(): array
    {
        return [
            'regionId' => 'région', 'provinceId' => 'province', 'complexeId' => 'complexe',
            'formation.etablissement_id' => 'établissement', 'campagneId' => 'campagne',
            'formation.nom_filiere' => 'filière', 'formation.niveau_formation' => 'niveau de formation',
            'formation.annee_formation' => 'année de formation', 'formation.annee_scolaire' => 'année scolaire',
        ];
    }

    public function render()
    {
        $provinces = $this->regionId !== '' ? Province::where('region_id', $this->regionId)->orderBy('nom')->pluck('nom', 'id') : collect();
        $complexes = $this->provinceId !== '' ? Complexe::where('province_id', $this->provinceId)->orderBy('nom')->pluck('nom', 'id') : collect();
        $etablissements = $this->complexeId !== ''
            ? Etablissement::where('complexe_id', $this->complexeId)->orderBy('nom_efp')->get(['id', 'nom_efp', 'code_efp'])
                ->mapWithKeys(fn (Etablissement $e) => [$e->id => $e->nom_efp.' — '.$e->code_efp])
            : collect();

        $efpChoisi = $this->formation['etablissement_id'] !== '' ? Etablissement::find((int) $this->formation['etablissement_id'], ['id', 'nom_efp', 'code_efp']) : null;
        $pieces = $this->piecesAttendues();

        return view('livewire.auth.inscription', [
            'etapeCourante' => $this->etape,
            'etapeNom' => self::ETAPES[$this->etape],
            'etapes' => ['Compte', 'Identité', 'Formation', 'Situation', 'Pièces', 'Vérification'],
            'regions' => Region::orderBy('nom')->pluck('nom', 'id'),
            'provinces' => $provinces,
            'complexes' => $complexes,
            'etablissements' => $etablissements,
            'campagnes' => $this->campagnesOuvertes(),
            'campagneRequise' => $this->formation['etablissement_id'] !== '',
            'efpChoisi' => $efpChoisi,
            'pieces' => $pieces,
            'requises' => $pieces->filter(fn (array $p) => $p['requise']),
            'manquantes' => $this->piecesManquantes(),
            'justificatifObligatoire' => app(ControleInscription::class)->justificatifObligatoire(),
            'anneesScolaires' => AnneeScolaire::options(),
            'reglesPiece' => fn (TypePiece $type) => app(Documents::class)->regles($type->formats_acceptes),
        ]);
    }
}
