<?php

namespace App\Livewire\Stagiaire;

use App\Domain\Identity\ControleInscription;
use App\Domain\Identity\InscriptionStagiaire;
use App\Enums\AnneeFormation;
use App\Enums\Genre;
use App\Enums\NiveauFormation;
use App\Livewire\PageStagiaire;
use App\Models\Etablissement;
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

    /** Recherche dans le référentiel des établissements (nom ou code EFP). */
    public string $rechercheEfp = '';

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

        if (! in_array($this->etape, self::ETAPES, true)) {
            $this->etape = $this->etapeAttendue($stagiaire);
        }
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
        $valides = $this->validate([
            'formation.etablissement_id' => ['required', 'integer', Rule::exists('etablissement', 'id')],
            'formation.nom_filiere' => ['required', 'string', 'min:3', 'max:190'],
            'formation.niveau_formation' => ['required', Rule::enum(NiveauFormation::class)],
            'formation.annee_formation' => ['required', Rule::enum(AnneeFormation::class)],
            'formation.annee_scolaire' => ['required', Rule::in(array_keys(AnneeScolaire::options()))],
        ], [
            'formation.etablissement_id.required' => 'Choisissez votre établissement dans la liste.',
            'formation.etablissement_id.exists' => 'Choisissez votre établissement dans la liste.',
        ], [
            'formation.etablissement_id' => 'établissement', 'formation.nom_filiere' => 'filière', 'formation.niveau_formation' => 'niveau de formation',
            'formation.annee_formation' => 'année de formation', 'formation.annee_scolaire' => 'année scolaire',
        ])['formation'];

        $valides['nom_filiere'] = trim((string) $valides['nom_filiere']);

        if ($this->executer(fn () => $inscription->declarerFormation($this->stagiaire(), $valides), 'Votre formation est enregistrée.')) {
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

    public function updatedRechercheEfp(): void
    {
        // Une nouvelle recherche ne garde pas un choix qui n'est plus affiché.
        $this->resetValidation('formation.etablissement_id');
    }

    public function render()
    {
        $stagiaire = $this->stagiaire()->load('etablissement');
        $statut = $stagiaire->statut_inscription;

        $terme = trim($this->rechercheEfp);
        $etablissements = Etablissement::query()
            ->when($terme !== '', fn ($q) => $q->where(fn ($s) => $s
                ->where('nom_efp', 'like', '%'.addcslashes($terme, '%_\\').'%')
                ->orWhere('code_efp', 'like', addcslashes($terme, '%_\\').'%')))
            ->when($terme === '' && $this->formation['etablissement_id'] === '', fn ($q) => $q->limit(50))
            ->orderBy('nom_efp')
            ->limit(80)
            ->get(['id', 'nom_efp', 'code_efp']);

        // L'établissement déjà choisi reste proposé même s'il ne correspond plus à la recherche.
        $choisi = $this->formation['etablissement_id'] !== '' ? Etablissement::find((int) $this->formation['etablissement_id'], ['id', 'nom_efp', 'code_efp']) : null;

        if ($choisi !== null && ! $etablissements->contains('id', $choisi->id)) {
            $etablissements->prepend($choisi);
        }

        return view('livewire.stagiaire.mon-inscription', [
            'stagiaire' => $stagiaire,
            'statut' => $statut,
            'modifiable' => $statut->modifiable(),
            'etapes' => ['Identité', 'Formation', 'Vérification'],
            'indice' => (int) array_search($this->etape, self::ETAPES, true),
            'etablissements' => $etablissements,
            'efpChoisi' => $choisi,
            'historique' => $stagiaire->verificationsInscription()->with('agent:id,nom,prenom', 'etablissement:id,nom_efp')->latest('id')->limit(10)->get(),
            'enAttente' => $stagiaire->verificationsInscription()->enAttente()->latest('id')->first(),
            'justificatifObligatoire' => app(ControleInscription::class)->justificatifObligatoire(),
            'anneesScolaires' => AnneeScolaire::options(),
        ]);
    }
}
