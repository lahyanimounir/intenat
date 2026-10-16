<?php

use App\Domain\Identity\Permissions;
use App\Enums\RoleCode;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Documentation générée
|--------------------------------------------------------------------------
|
| Les tables de docs/api/endpoints.md et de docs/permissions/matrice.md ne
| sont pas tenues à la main : elles sortent des routes et de la matrice
| centralisée, pour ne jamais diverger du code.
|
|   php artisan internat:docs routes
|   php artisan internat:docs permissions
|
*/

Artisan::command('internat:docs {table : routes ou permissions}', function () {
    if ($this->argument('table') === 'permissions') {
        $roles = RoleCode::cases();
        $matrice = Permissions::matrice();
        $codes = array_keys(Permissions::catalogue());
        sort($codes);

        $this->line('| Permission | '.implode(' | ', array_map(fn (RoleCode $r) => $r->value, $roles)).' |');
        $this->line('|---|'.str_repeat(':-:|', count($roles)));

        foreach ($codes as $code) {
            $cases = array_map(fn (RoleCode $r) => in_array($code, $matrice[$r->value], true) ? '●' : ' ', $roles);
            $this->line("| `{$code}` | ".implode(' | ', $cases).' |');
        }

        $this->newLine();

        foreach ($roles as $role) {
            $this->line("- **{$role->value}** — {$role->libelle()} : ".count($matrice[$role->value]).' permission(s)');
        }

        return 0;
    }

    $lignes = collect(Route::getRoutes()->getRoutes())
        ->filter(fn ($route) => str_starts_with($route->uri(), 'api/'))
        ->map(function ($route) {
            $intergiciels = $route->gatherMiddleware();
            $permission = collect($intergiciels)->first(fn ($m) => is_string($m) && str_starts_with($m, 'permission:'));

            $acces = match (true) {
                $permission !== null => '`'.substr($permission, strlen('permission:')).'`',
                in_array('stagiaire', $intergiciels, true) => 'stagiaire',
                in_array('personnel', $intergiciels, true) => 'personnel',
                in_array('auth:sanctum', $intergiciels, true) => 'connecté',
                default => 'public',
            };

            return [
                'uri' => '/'.$route->uri(),
                'methodes' => implode('|', array_diff($route->methods(), ['HEAD'])),
                'acces' => $acces,
                'action' => str_replace('App\\Http\\Controllers\\Api\\', '', $route->getActionName()),
            ];
        })
        ->sortBy(fn (array $l) => $l['uri'].' '.$l['methodes'])
        ->values();

    $this->line('| Méthode | Route | Accès | Contrôleur |');
    $this->line('|---|---|---|---|');

    foreach ($lignes as $l) {
        $this->line("| {$l['methodes']} | `{$l['uri']}` | {$l['acces']} | `{$l['action']}` |");
    }

    $this->newLine();
    $this->line($lignes->count().' routes.');

    return 0;
})->purpose('Produit les tables Markdown des routes de l\'API et de la matrice des permissions');
