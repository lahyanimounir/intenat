<?php

namespace App\Domain\Shared;

use App\Domain\Audit\Journal;
use App\Models\Parametre;
use App\Models\Utilisateur;
use Illuminate\Support\Collection;

/**
 * Règles de gestion paramétrables : celles que la documentation laisse ouvertes
 * ou ajustables. Le catalogue (clé, valeur initiale, libellé) vit dans
 * config/internat.php ; la table `parametre` ne porte que les valeurs modifiées.
 * `a_valider` signale une règle provisoire, que l'OFPPT doit encore arrêter.
 * Chaque modification est tracée au journal d'audit.
 */
class ReglagesMetier
{
    /** Valeurs admises pour les paramètres à choix fermé. */
    public const CHOIX = [
        'classement.departage' => ['arbitrage_chef_pole' => 'Arbitrage du chef de pôle (ex æquo signalés)', 'date_soumission' => 'Date de dépôt du dossier'],
        'restauration.critere_blocage' => ['recu_non_depose' => 'Reçu non déposé', 'recu_non_valide' => 'Reçu non validé', 'aucun' => 'Aucun blocage'],
        'absence.alerte_destinataires' => ['gestionnaire' => 'Gestionnaire seul', 'gestionnaire_et_chef_pole' => 'Gestionnaire et chef de pôle'],
    ];

    public function __construct(
        private readonly Parametres $parametres,
        private readonly Journal $journal,
    ) {}

    /**
     * Catalogue complet, valeurs en vigueur comprises.
     *
     * @return Collection<string, array{cle: string, libelle: string, valeur: mixed, defaut: mixed, type: string, choix: array<string, string>|null, a_valider: bool}>
     */
    public function catalogue(): Collection
    {
        $enBase = Parametre::all()->keyBy('cle');

        return collect(config('internat.parametres'))->map(function (array $definition, string $cle) use ($enBase) {
            [$defaut, $libelle, $aValider] = $definition;
            $ligne = $enBase->get($cle);

            return [
                'cle' => $cle,
                // Les valeurs admises sont proposées par la liste : l'énumération entre parenthèses du libellé est superflue à l'écran.
                'libelle' => isset(self::CHOIX[$cle]) ? trim(preg_replace('/\s*\([^)]*\|[^)]*\)\s*$/u', '', $libelle)) : $libelle,
                'valeur' => $ligne ? $ligne->valeur : $defaut,
                'defaut' => $defaut,
                'type' => self::type($cle, $defaut),
                'choix' => self::CHOIX[$cle] ?? null,
                'a_valider' => $ligne ? $ligne->a_valider : $aValider,
            ];
        });
    }

    /** @return list<mixed> règles de validation de la valeur d'un paramètre */
    public static function regles(string $cle): array
    {
        $defaut = config('internat.parametres')[$cle][0] ?? null;

        return match (self::type($cle, $defaut)) {
            'booleen' => ['required', 'boolean'],
            'entier' => ['required', 'integer', 'min:0', 'max:365'],
            'choix' => ['required', 'in:'.implode(',', array_keys(self::CHOIX[$cle]))],
            'heure' => ['required', 'regex:/^([01]\d|2[0-3]):[0-5]\d$/'],
            default => ['required', 'string', 'max:100'],
        };
    }

    public function modifier(string $cle, mixed $valeur, bool $aValider, Utilisateur $auteur): Parametre
    {
        // Les clés contiennent des points : lecture directe du tableau, sans notation pointée.
        $definition = config('internat.parametres')[$cle] ?? abort(404);
        [$defaut, $libelle] = $definition;

        $valeur = match (true) {
            is_bool($defaut) => (bool) $valeur,
            is_int($defaut) => (int) $valeur,
            default => (string) $valeur,
        };

        $ancienne = $this->parametres->lire($cle);

        $parametre = Parametre::updateOrCreate(['cle' => $cle], [
            'valeur' => $valeur, 'libelle' => $libelle, 'a_valider' => $aValider, 'modifie_par' => $auteur->id,
        ]);

        $this->parametres->oublier();
        $this->journal->consigner('parametre.modification', 'parametre', details: ['cle' => $cle, 'avant' => $ancienne, 'apres' => $valeur, 'a_valider' => $aValider], acteur: $auteur);

        return $parametre;
    }

    private static function type(string $cle, mixed $defaut): string
    {
        return match (true) {
            is_bool($defaut) => 'booleen',
            is_int($defaut) => 'entier',
            isset(self::CHOIX[$cle]) => 'choix',
            $cle === 'restauration.heure_limite_reservation' => 'heure',
            default => 'texte',
        };
    }
}
