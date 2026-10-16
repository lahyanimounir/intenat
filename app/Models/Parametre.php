<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Règle de gestion configurable. `a_valider` signale une règle que la
 * documentation laisse en suspens et que l'OFPPT doit encore confirmer.
 */
class Parametre extends Model
{
    protected $table = 'parametre';

    protected $primaryKey = 'cle';

    protected $keyType = 'string';

    public $incrementing = false;

    protected $fillable = ['cle', 'valeur', 'libelle', 'a_valider', 'modifie_par'];

    protected function casts(): array
    {
        return ['valeur' => 'json', 'a_valider' => 'boolean'];
    }
}
