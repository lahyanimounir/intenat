<?php

namespace App\Jobs;

use App\Domain\Admissions\Classement;
use App\Models\Campagne;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Met à jour le classement d'une campagne après une décision d'instruction.
 * Unique par campagne : plusieurs décisions rapprochées ne lancent qu'un calcul.
 */
class RecalculerClassement implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public int $uniqueFor = 120;

    public function __construct(public readonly int $campagneId) {}

    public function uniqueId(): string
    {
        return (string) $this->campagneId;
    }

    public function handle(Classement $classement): void
    {
        $campagne = Campagne::find($this->campagneId);

        if ($campagne !== null && ! $campagne->estDeliberee()) {
            $classement->recalculer($campagne);
        }
    }
}
