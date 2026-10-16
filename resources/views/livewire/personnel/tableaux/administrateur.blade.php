@php
    use App\Enums\RoleCode;
    use App\Support\Affichage\Format;

    $icones = ['critique' => 'circle-alert', 'attention' => 'triangle-alert', 'information' => 'info'];
    $tons = ['critique' => 'text-danger', 'attention' => 'text-alerte', 'information' => 'text-info'];
@endphp
<div class="space-y-6">
    <div class="grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
        <x-stat libelle="Comptes actifs" :valeur="$d['comptes']['actifs']" :detail="$d['comptes']['inactifs'].' désactivé(s)'" href="/administration/utilisateurs" />
        <x-stat libelle="Établissements" :valeur="$d['organisation']['etablissements']" href="/administration/organisation"
            :detail="$d['organisation']['regions'].' région(s), '.$d['organisation']['complexes'].' complexe(s)'" />
        <x-stat libelle="Internats" :valeur="$d['organisation']['internats']" href="/administration/organisation" />
        <x-stat libelle="Règles à valider par l’OFPPT" :valeur="$d['parametres_a_valider']" detail="Paramètres provisoires" href="/administration/parametres"
            :ton="$d['parametres_a_valider'] > 0 ? 'alerte' : 'neutre'" />
    </div>

    <div class="grid gap-6 xl:grid-cols-2">
        <x-carte>
            <x-carte.entete titre="Cohérence des données" description="Contrôles calculés à chaque affichage." />
            @if ($d['alertes'] === [])
                <x-vide titre="Aucune anomalie détectée" icone="circle-check" class="py-8" />
            @else
                <ul>
                    @foreach ($d['alertes'] as $alerte)
                        <li class="flex items-start gap-3 border-b border-bord-doux px-5 py-3 last:border-0">
                            <x-icone :nom="$icones[$alerte['niveau']]" class="mt-0.5 size-4 shrink-0 {{ $tons[$alerte['niveau']] }}" />
                            <p class="flex-1 text-base text-encre">{{ $alerte['message'] }}</p>
                            @if ($alerte['lien'])<x-bouton variante="lien" taille="sm" :href="$alerte['lien']">Ouvrir</x-bouton>@endif
                        </li>
                    @endforeach
                </ul>
            @endif
        </x-carte>

        <x-carte>
            <x-carte.entete titre="Dernières actions tracées">
                <x-slot:actions><x-bouton variante="lien" taille="sm" href="/administration/audit">Journal complet</x-bouton></x-slot:actions>
            </x-carte.entete>
            @if ($d['audit_recent'] === [])
                <x-vide titre="Aucune action tracée pour l’instant" class="py-8" />
            @else
                <ul>
                    @foreach ($d['audit_recent'] as $entree)
                        <li class="flex items-baseline gap-3 border-b border-bord-doux px-5 py-2.5 text-sm last:border-0">
                            <span class="chiffres w-32 shrink-0 text-discret">{{ Format::dateHeure($entree['date_heure']) }}</span>
                            <code class="min-w-0 flex-1 truncate text-encre">{{ $entree['action'] }}</code>
                            <span class="shrink-0 text-discret">{{ $entree['auteur'] }}</span>
                        </li>
                    @endforeach
                </ul>
            @endif
        </x-carte>
    </div>

    <x-carte>
        <x-carte.entete titre="Comptes par rôle" />
        <x-carte.corps>
            <dl class="grid gap-x-6 gap-y-3 sm:grid-cols-2 lg:grid-cols-4">
                @foreach ($d['comptes']['par_role'] as $role => $total)
                    <div class="flex items-baseline justify-between gap-2 border-b border-bord-doux pb-2">
                        <dt class="text-sm text-discret">{{ RoleCode::tryFrom($role)?->libelle() ?? $role }}</dt>
                        <dd class="chiffres text-md font-semibold">{{ $total }}</dd>
                    </div>
                @endforeach
            </dl>
        </x-carte.corps>
    </x-carte>
</div>
