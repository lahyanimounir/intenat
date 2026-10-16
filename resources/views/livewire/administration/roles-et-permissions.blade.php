<div>
    <x-page.entete titre="Rôles et permissions"
        description="Matrice de référence, appliquée par le serveur à chaque requête. Une permission ouvre une action ; elle ne dispense jamais du contrôle de périmètre." />

    <x-alerte ton="neutre" class="mb-5" titre="Deux contrôles s’additionnent">
        Le rôle détermine les actions possibles (cette matrice). Le périmètre du compte détermine sur quelles lignes elles s’exercent : un gestionnaire de
        l’internat filles n’accède jamais aux données de l’internat garçons. Le stagiaire est un acteur distinct, qui n’accède qu’à son propre dossier.
    </x-alerte>

    <x-carte class="overflow-hidden">
        <x-carte.entete titre="Matrice des permissions" description="Lecture seule : la matrice est définie dans le code et synchronisée à chaque déploiement." />
        <div class="defilement-fin max-h-[calc(100dvh-16rem)] overflow-auto">
            <table class="w-full border-collapse text-left text-sm">
                <caption class="sr-only">Permissions par rôle</caption>
                <thead class="sticky top-0 z-10 bg-surface-2">
                    <tr>
                        <th scope="col" class="min-w-72 border-b border-bord px-5 py-3 text-xs font-medium text-discret">Permission</th>
                        @foreach ($roles as $role)
                            <th scope="col" class="w-28 border-b border-l border-bord px-2 py-3 text-center align-bottom">
                                <span class="block text-xs leading-tight font-semibold text-encre">{{ $role['libelle'] }}</span>
                                <span class="mt-1 block text-2xs font-normal text-discret">{{ $role['perimetre'] }}</span>
                            </th>
                        @endforeach
                    </tr>
                </thead>
                @foreach ($sections as $section => $permissions)
                    <tbody>
                        <tr>
                            <th scope="colgroup" colspan="{{ $roles->count() + 1 }}" class="border-b border-bord-doux bg-marine-50 px-5 py-1.5 text-xs font-semibold tracking-wide text-marine uppercase">{{ $section }}</th>
                        </tr>
                        @foreach ($permissions as $permission)
                            <tr class="border-b border-bord-doux hover:bg-marine-50/40">
                                <th scope="row" class="px-5 py-2 font-normal">
                                    <span class="block text-encre">{{ $permission['libelle'] }}</span>
                                    <code class="text-2xs text-discret">{{ $permission['code'] }}</code>
                                </th>
                                @foreach ($roles as $role)
                                    @php $accordee = in_array($permission['code'], $role['permissions'], true); @endphp
                                    <td @class(['border-l border-bord-doux px-2 py-2 text-center', 'bg-succes-fond/50' => $accordee])>
                                        @if ($accordee)
                                            <x-icone nom="check" class="mx-auto size-4 text-succes" trait="2.5" />
                                            <span class="sr-only">Accordée</span>
                                        @else
                                            <span class="sr-only">Non accordée</span>
                                        @endif
                                    </td>
                                @endforeach
                            </tr>
                        @endforeach
                    </tbody>
                @endforeach
            </table>
        </div>
    </x-carte>
</div>
