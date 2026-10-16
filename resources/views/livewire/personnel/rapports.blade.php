@php
    use App\Enums\StatutExport;
    use App\Support\Affichage\Format;
@endphp
<div>
    <x-page.entete titre="Rapports et exports"
        description="Les exports sont produits en arrière-plan et ne contiennent que les données de votre périmètre : vous n’exportez jamais une ligne que vous ne pouvez pas consulter à l’écran." />

    <div class="grid items-start gap-6 xl:grid-cols-[minmax(0,2fr)_minmax(0,3fr)]">
        <x-carte>
            <x-carte.entete titre="Nouvel export" />
            <x-carte.corps>
                @if ($types->isEmpty())
                    <x-alerte ton="neutre">Aucun export n’est disponible pour votre rôle.</x-alerte>
                @else
                    <form wire:submit="demander" novalidate class="space-y-4">
                        <x-liste libelle="Contenu" wire:model.live="type" :options="$types->map(fn ($t) => $t['libelle'])" :aide="$description" requis />

                        @if ($choisi && $choisi['campagne'])
                            <x-liste libelle="Campagne" wire:model="campagne" :options="$campagnes" :vide="$exigeCampagne ? 'Choisir une campagne…' : 'Toutes les campagnes'" :requis="$exigeCampagne" wire:key="campagne-{{ $type }}" />
                        @endif

                        @if ($avecMois)
                            <x-saisie libelle="Mois" type="month" wire:model="mois" />
                        @endif

                        <fieldset>
                            <legend class="mb-1.5 text-sm font-medium">Format</legend>
                            <div class="grid grid-cols-2 gap-2">
                                @foreach ([['pdf', 'PDF', 'Document à diffuser', 'file-text'], ['csv', 'CSV', 'Tableur (Excel)', 'file-spreadsheet']] as [$valeur, $libelle, $detail, $icone])
                                    <label class="flex cursor-pointer items-center gap-2.5 rounded-md border border-bord px-3 py-2.5 transition-colors hover:border-marine-200 has-checked:border-marine has-checked:bg-marine-50 has-focus-visible:outline-2 has-focus-visible:outline-offset-2 has-focus-visible:outline-bleu">
                                        <input type="radio" name="format" value="{{ $valeur }}" wire:model="format" class="sr-only" />
                                        <x-icone :nom="$icone" class="size-4 text-marine" trait="1.75" />
                                        <span>
                                            <span class="block text-base font-medium">{{ $libelle }}</span>
                                            <span class="block text-xs text-discret">{{ $detail }}</span>
                                        </span>
                                    </label>
                                @endforeach
                            </div>
                            @error('format')<p role="alert" class="mt-1.5 text-sm text-danger">{{ $message }}</p>@enderror
                        </fieldset>

                        <x-bouton type="submit" variante="primaire" class="w-full" cible="demander">Produire l’export</x-bouton>
                    </form>
                @endif
            </x-carte.corps>
        </x-carte>

        <div @if ($enPreparation) wire:poll.3s @endif>
        <x-carte>
            <x-carte.entete titre="Mes exports" description="Les fichiers restent disponibles sept jours." />
            @if ($exports->isEmpty())
                <x-vide titre="Aucun export pour l’instant" icone="file-chart-column-increasing" />
            @else
                <ul>
                    @foreach ($exports as $export)
                        <li wire:key="export-{{ $export->id }}" class="flex flex-wrap items-center gap-x-4 gap-y-1 border-b border-bord-doux px-5 py-3 last:border-0">
                            <div class="min-w-0 flex-1 basis-56">
                                <p class="text-base font-medium">{{ $libelles[$export->type] ?? $export->type }} <span class="font-normal text-discret uppercase">· {{ $export->format }}</span></p>
                                <p class="text-sm text-discret">
                                    Demandé le {{ Format::dateHeure($export->created_at) }}@if ($export->lignes !== null) · {{ Format::nombre($export->lignes) }} ligne(s)@endif @if ($export->erreur) · {{ $export->erreur }}@endif
                                </p>
                            </div>
                            <x-statut :etat="$export->statut" />
                            @if ($export->statut === StatutExport::Termine && $export->fichier !== null)
                                <x-bouton taille="sm" icone="download" :href="route('rapports.fichier', $export)" :navigation="false">Télécharger</x-bouton>
                            @elseif ($export->statut === StatutExport::Termine)
                                <span class="text-sm text-discret">Fichier expiré</span>
                            @endif
                        </li>
                    @endforeach
                </ul>
            @endif
            <x-pagination :lignes="$exports" />
        </x-carte>
        </div>
    </div>
</div>
