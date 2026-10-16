<?php

namespace App\Jobs;

use App\Domain\Reporting\Exports;
use App\Enums\StatutExport;
use App\Models\Export;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/** Produit le fichier d'un export hors de la requête HTTP. */
class GenererExport implements ShouldQueue
{
    use Queueable;

    public int $timeout = 300;

    public int $tries = 1;

    public function __construct(public readonly int $exportId) {}

    public function handle(Exports $exports): void
    {
        $export = Export::with('utilisateur.roles')->find($this->exportId);

        if ($export !== null && $export->statut === StatutExport::EnAttente) {
            $exports->generer($export);
        }
    }
}
