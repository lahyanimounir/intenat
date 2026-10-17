<?php

namespace App\Livewire\Stagiaire;

use App\Domain\Identity\ControleInscription;
use App\Domain\Identity\InscriptionStagiaire;
use App\Enums\AnneeFormation;
use App\Enums\Genre;
use App\Enums\NiveauFormation;
use App\Enums\StatutCampagne;
use App\Livewire\PageStagiaire;
use App\Models\Campagne;
use App\Models\Complexe;
use App\Models\Etablissement;
use App\Models\Province;
use App\Models\Region;
use App\Models\Stagiaire;
use App\Support\AnneeScolaire;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Features\SupportFileUploads\TemporaryUploadedFile;
use Livewire\WithFileUploads;

/**
 * « Mon inscription » : le stagiaire déclare son identité, puis sa formation, et
 * soumet le tout à la vérification de son établissement, avec un justificatif.
 *
 * L'écran sert aussi de suivi : état de la vérification, motif d'une correction
 * demandée ou d'un rejet, historique. Rien de ce qui est saisi ici n'ouvre de
 * droit par lui-même — c'est la décision d'un agent qui rend l'inscription
 * « vérifiée ».
 */
#[Title('Mon inscription')]
class MonInscription extends PageStagiaire
{
    use WithFileUploads;

    private const ETAPES = ['identite', 'formation', 'verification'];

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

    /** Première étape qui reste à faire. */
    private function etapeAttendue(Stagiaire $stagiaire): string
    {
        return match (true) {
            ! $stagiaire->identiteDeclaree() || blank($stagiaire->tel) || blank($stagiaire->ville) || blank($stagiaire->adresse) => 'identite',
            ! $stagiaire->profilComplet() => 'formation',
            default => 'verification',
        };
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
            $suivante = $this->etapeAttendue($this->stagiaire()->refresh());
            $this->etape = $suivante === 'identite' ? 'formation' : $suivante;
        }
    }

    public function enregistrerFormation(InscriptionStagiaire $inscription): void
    {
        $stagiaire = $this->stagiaire();

        // La campagne doit être une campagne ouverte de l'internat correspondant à
        // l'établissement NOUVELLEMENT choisi et au genre du candidat — jamais celui
        // d'un établissement précédemment enregistré.
        $internatVise = $stagiaire->genre === null ? null : \App\Models\Internat::query()
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

        if ($this->executer(fn () => $inscription->declarerFormation($stagiaire, $valides), 'Votre formation est enregistrée.')) {
            $this->etape = 'verification';
        }
    }

    public function soumettre(ControleInscription $controle): void
    {
        $this->validate([
            'justificatif' => [$controle->justificatifObligatoire() ? 'required' : 'nullable', 'file', 'max:'.config('internat.documents.taille_max_ko')],
        ], [
            'justificatif.required' => 'Joignez un justificatif d\'inscription : attestation d\'inscription ou reçu des frais de scolarité.',
            'justificatif.max' => 'Fichier trop volumineux (maximum '.round(config('internat.documents.taille_max_ko') / 1024, 1).' Mo).',
        ], ['justificatif' => 'justificatif']);

        if ($this->executer(fn () => $controle->soumettre($this->stagiaire(), $this->justificatif), 'Votre inscription est soumise à la vérification de votre établissement.')) {
            $this->reset('justificatif');
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

        return view('livewire.stagiaire.mon-inscription', [
            'stagiaire' => $stagiaire,
            'statut' => $statut,
            'modifiable' => $statut->modifiable(),
            'etapes' => ['Identité', 'Formation', 'Vérification'],
            'indice' => (int) array_search($this->etape, self::ETAPES, true),
            'regions' => Region::orderBy('nom')->pluck('nom', 'id'),
            'provinces' => $provinces,
            'complexes' => $complexes,
            'etablissements' => $etablissements,
            'campagnes' => $campagnes,
            'campagneRequise' => $this->formation['etablissement_id'] !== '',
            'efpChoisi' => $efpChoisi,
            'historique' => $stagiaire->verificationsInscription()->with('agent:id,nom,prenom', 'etablissement:id,nom_efp')->latest('id')->limit(10)->get(),
            'enAttente' => $stagiaire->verificationsInscription()->enAttente()->latest('id')->first(),
            'justificatifObligatoire' => app(ControleInscription::class)->justificatifObligatoire(),
            'anneesScolaires' => AnneeScolaire::options(),
        ]);
    }
}
