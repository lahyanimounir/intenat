<?php

namespace App\Domain\Identity\MyWay;

use RuntimeException;

/**
 * Fournisseur injoignable, délai dépassé, ou intégration officielle non configurée.
 */
class FournisseurIndisponibleException extends RuntimeException {}
