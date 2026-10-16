<div>
    <x-page.entete titre="Paramètres"
        description="Règles de gestion que la documentation laisse ouvertes ou ajustables. Elles sont paramétrables plutôt que figées dans le code, et chaque modification est tracée." />

    @if ($enAttente > 0)
        <x-alerte ton="alerte" class="mb-5" :titre="$enAttente.' règle(s) provisoire(s)'">
            Ces valeurs sont des choix par défaut prudents, appliqués tant que l’OFPPT n’a pas arrêté la règle. Cochez « Confirmée » une fois la décision
            institutionnelle prise.
        </x-alerte>
    @endif

    <div class="space-y-6">
        @foreach ($sections as $prefixe => $section)
            <x-carte wire:key="section-{{ $prefixe }}">
                <x-carte.entete :titre="$section['titre']" :description="$section['description']" />
                <ul>
                    @foreach ($section['lignes'] as $parametre)
                        @php
                            $champ = $parametre['champ'];
                            $id = 'parametre-'.$champ;
                            $affichee = is_bool($parametre['defaut']) ? ($parametre['defaut'] ? 'oui' : 'non') : ($parametre['choix'][$parametre['defaut']] ?? $parametre['defaut']);
                        @endphp
                        {{-- La clé de la ligne porte sa valeur en vigueur : après un enregistrement, l'état « modifié » repart de la nouvelle valeur. --}}
                        <li wire:key="{{ $id }}-{{ md5(json_encode([$parametre['valeur'], $parametre['a_valider']])) }}"
                            class="flex flex-wrap items-center gap-x-5 gap-y-2.5 border-b border-bord-doux px-5 py-3.5 last:border-0"
                            x-data="{
                                valeur: $wire.entangle('valeurs.{{ $champ }}'), confirmee: $wire.entangle('confirmees.{{ $champ }}'),
                                initiale: {{ Js::from($parametre['valeur']) }}, confirmeeInitiale: {{ $parametre['a_valider'] ? 'false' : 'true' }},
                                get modifie() { return String(this.valeur) !== String(this.initiale) || Boolean(this.confirmee) !== this.confirmeeInitiale },
                            }">
                            <div class="min-w-0 flex-1 basis-80">
                                <label for="{{ $id }}" class="block text-base text-encre">{{ $parametre['libelle'] }}</label>
                                <p class="mt-0.5 flex flex-wrap items-center gap-2 text-xs text-discret">
                                    <code>{{ $parametre['cle'] }}</code>
                                    @if ($parametre['valeur'] !== $parametre['defaut'])<span>· valeur initiale : {{ $affichee }}</span>@endif
                                    @if ($parametre['a_valider'])
                                        <x-badge ton="alerte">À valider par l’OFPPT</x-badge>
                                    @else
                                        <x-badge ton="succes">Règle confirmée</x-badge>
                                    @endif
                                </p>
                                @error('valeurs.'.$champ)<p role="alert" class="mt-1 text-sm text-danger">{{ $message }}</p>@enderror
                            </div>

                            <div class="flex flex-wrap items-center gap-3">
                                @if ($parametre['type'] === 'booleen')
                                    <x-interrupteur :libelle="$parametre['libelle']" id="{{ $id }}" x-model="valeur" />
                                @elseif ($parametre['type'] === 'choix')
                                    <div class="w-72"><x-liste id="{{ $id }}" :options="$parametre['choix']" x-model="valeur" /></div>
                                @elseif ($parametre['type'] === 'entier')
                                    <div class="w-24"><x-saisie id="{{ $id }}" type="number" min="0" max="365" inputmode="numeric" x-model="valeur" /></div>
                                @elseif ($parametre['type'] === 'heure')
                                    <div class="w-32"><x-saisie id="{{ $id }}" type="time" x-model="valeur" /></div>
                                @else
                                    <div class="w-32"><x-saisie id="{{ $id }}" maxlength="100" x-model="valeur" /></div>
                                @endif

                                <label class="flex cursor-pointer items-center gap-2 text-sm text-encre-2">
                                    <input type="checkbox" class="case" x-model="confirmee" /> Confirmée
                                </label>

                                <x-bouton taille="sm" variante="primaire" wire:click="enregistrer('{{ $parametre['cle'] }}')" cible="enregistrer('{{ $parametre['cle'] }}')" x-bind:disabled="! modifie">Enregistrer</x-bouton>
                            </div>
                        </li>
                    @endforeach
                </ul>
            </x-carte>
        @endforeach
    </div>
</div>
