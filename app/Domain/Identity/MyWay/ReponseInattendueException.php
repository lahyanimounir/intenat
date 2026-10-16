<?php

namespace App\Domain\Identity\MyWay;

use RuntimeException;

/**
 * Réponse du fournisseur incomplète ou incohérente (champ manquant, CEF invalide…).
 */
class ReponseInattendueException extends RuntimeException {}
