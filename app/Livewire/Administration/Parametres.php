<?php

namespace App\Livewire\Administration;

use App\Domain\Shared\ReglagesMetier;
use App\Livewire\PagePersonnel;
use Livewire\Attributes\Title;

/**
 * Règles de gestion que la documentation laisse ouvertes ou ajustables : elles
 * sont paramétrables plutôt que figées dans le code. Une règle « à valider »
 * est un choix par défaut prudent, appliqué tant que l'OFPPT n'a pas tranché.
 */
#[Title('Paramètres')]
class Parametres extends PagePersonnel
{
    private const PERMISSION = 'parametres.gerer';

    /** Préfixe de clé => [titre, description]. */
    private const SECTIONS = [
        'inscription' => ['Inscription en formation', 'Vérification administrative de l’inscription déclarée par le stagiaire.'],
        'confirmation' => ['Confirmation des admissions', 'Délais laissés aux candidats, contrôlés par le serveur.'],
        'dossier_physique' => ['Dossier physique', null],
        'classement' => ['Classement', 'Règle de départage des dossiers de même score.'],
        'places' => ['Places offertes', null],
        'instruction' => ['Instruction', null],
        'occupation' => ['Permission d’occuper', 'Conditions exigées avant qu’un gestionnaire autorise l’occupation d’un lit.'],
        'paiement' => ['Paiements', null],
        'restauration' => ['Restauration', null],
        'absence' => ['Absences', null],
    ];

    /** Valeur saisie de chaque paramètre. Les clés contiennent des points : ils sont remplacés par « __ ». */
    public array $valeurs = [];

    /** Règle confirmée par l'OFPPT (l'inverse de « à valider »). */
    public array $confirmees = [];

    public function mount(ReglagesMetier $reglages): void
    {
        $this->exiger(self::PERMISSION);
        $this->charger($reglages);
    }

    private static function champ(string $cle): string
    {
        return str_replace('.', '__', $cle);
    }

    private function charger(ReglagesMetier $reglages): void
    {
        foreach ($reglages->catalogue() as $parametre) {
            $this->valeurs[self::champ($parametre['cle'])] = $parametre['valeur'];
            $this->confirmees[self::champ($parametre['cle'])] = ! $parametre['a_valider'];
        }
    }

    public function enregistrer(ReglagesMetier $reglages, string $cle): void
    {
        $this->exiger(self::PERMISSION);
        abort_unless(isset(config('internat.parametres')[$cle]), 404);
        $champ = self::champ($cle);

        $valeur = $this->validate(
            ["valeurs.{$champ}" => ReglagesMetier::regles($cle)],
            ["valeurs.{$champ}.regex" => 'Heure attendue au format HH:MM.'],
            ["valeurs.{$champ}" => 'valeur'],
        )['valeurs'][$champ];

        if ($this->executer(fn () => $reglages->modifier($cle, $valeur, ! ($this->confirmees[$champ] ?? false), $this->utilisateur()),
            'Paramètre enregistré : la modification est tracée dans le journal d’audit.')) {
            $this->charger($reglages);
        }
    }

    public function render(): mixed
    {
        $this->exiger(self::PERMISSION);
        $catalogue = app(ReglagesMetier::class)->catalogue()->map(fn (array $p) => $p + ['champ' => self::champ($p['cle'])]);

        return view('livewire.administration.parametres', [
            'sections' => collect(self::SECTIONS)
                ->map(fn (array $section, string $prefixe) => [
                    'titre' => $section[0], 'description' => $section[1],
                    'lignes' => $catalogue->filter(fn (array $p) => str_starts_with($p['cle'], $prefixe.'.'))->values(),
                ])
                ->filter(fn (array $section) => $section['lignes']->isNotEmpty()),
            'enAttente' => $catalogue->where('a_valider', true)->count(),
        ]);
    }
}
