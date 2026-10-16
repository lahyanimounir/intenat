{{--
    Deux fenêtres communes à tous les écrans, ouvertes depuis une vue par :
      $confirmer({ titre, texte, libelle, destructif }).then(() => $wire.action())
      $motif({ titre, description, texte, libelle, libelleAction, minimum, destructif }).then((motif) => $wire.action(motif))
    Le serveur revalide toujours le motif : ces fenêtres ne sont qu'un confort de saisie.
--}}
<div x-data="confirmation" x-on:confirmer.window="ouvrir($event.detail)" x-show="ouvert" x-cloak class="fixed inset-0 z-[60]" x-on:keydown.escape.window="ouvert && annuler()">
    <div class="absolute inset-0 bg-encre/35" x-on:click="annuler()" aria-hidden="true"></div>
    <div role="alertdialog" aria-modal="true" aria-labelledby="confirmation-titre" aria-describedby="confirmation-texte" x-trap.noscroll.inert="ouvert"
        class="absolute top-1/2 left-1/2 flex w-[calc(100vw-2rem)] max-w-md -translate-x-1/2 -translate-y-1/2 flex-col rounded-lg border border-bord bg-surface shadow-md">
        <div class="px-5 pt-5 pb-4">
            <h2 id="confirmation-titre" class="text-md font-semibold text-encre" x-text="options.titre"></h2>
            <p id="confirmation-texte" class="mt-2 text-base whitespace-pre-line text-encre-2" x-text="options.texte"></p>
        </div>
        <footer class="flex justify-end gap-2 border-t border-bord-doux bg-surface-2 px-5 py-3">
            <x-bouton x-on:click="annuler()">Annuler</x-bouton>
            <button type="button" x-on:click="valider()" x-text="options.libelle || 'Confirmer'"
                class="inline-flex h-9 shrink-0 items-center justify-center rounded-md px-3.5 text-base font-medium whitespace-nowrap text-white transition-colors"
                x-bind:class="options.destructif ? 'bg-danger hover:bg-[#93190f]' : 'bg-marine hover:bg-marine-fonce'"></button>
        </footer>
    </div>
</div>

<div x-data="motif" x-on:motif.window="ouvrir($event.detail)" x-show="ouvert" x-cloak class="fixed inset-0 z-[60]" x-on:keydown.escape.window="ouvert && annuler()">
    <div class="absolute inset-0 bg-encre/35" x-on:click="annuler()" aria-hidden="true"></div>
    <div role="dialog" aria-modal="true" aria-labelledby="motif-titre" x-trap.noscroll.inert="ouvert"
        class="absolute top-1/2 left-1/2 flex w-[calc(100vw-2rem)] max-w-xl -translate-x-1/2 -translate-y-1/2 flex-col rounded-lg border border-bord bg-surface shadow-md">
        <header class="flex items-start gap-4 border-b border-bord-doux px-5 py-4">
            <div class="min-w-0 flex-1">
                <h2 id="motif-titre" class="text-md font-semibold text-encre" x-text="options.titre"></h2>
                <p class="mt-0.5 text-sm text-discret" x-show="options.description" x-text="options.description"></p>
            </div>
            <x-bouton variante="discret" taille="icone" aria-label="Fermer" class="-mt-1 -mr-2" x-on:click="annuler()"><x-icone nom="x" /></x-bouton>
        </header>
        <div class="px-5 py-4">
            <p class="mb-4 text-base text-encre-2" x-show="options.texte" x-text="options.texte"></p>
            <div class="flex flex-col gap-1.5">
                <label for="motif-texte" class="text-sm font-medium text-encre">
                    <span x-text="options.libelle || 'Motif'"></span><span aria-hidden="true" class="ml-0.5 text-danger" x-show="minimum > 0">*</span>
                </label>
                <textarea id="motif-texte" rows="4" x-ref="texte" x-model="texte" autofocus x-on:blur="touche = true" maxlength="1000"
                    x-bind:aria-invalid="(touche && invalide).toString()" class="champ min-h-20 py-2 leading-relaxed"></textarea>
                <p role="alert" class="text-sm text-danger" x-show="touche && invalide"
                    x-text="texte.trim() === '' ? 'Le motif est obligatoire.' : `Précisez davantage (${minimum} caractères au moins).`"></p>
            </div>
        </div>
        <footer class="flex flex-wrap items-center justify-end gap-2 border-t border-bord-doux bg-surface-2 px-5 py-3">
            <x-bouton x-on:click="annuler()">Annuler</x-bouton>
            <button type="button" x-on:click="valider()" x-text="options.libelleAction || 'Valider'"
                class="inline-flex h-9 shrink-0 items-center justify-center rounded-md px-3.5 text-base font-medium whitespace-nowrap text-white transition-colors"
                x-bind:class="options.destructif ? 'bg-danger hover:bg-[#93190f]' : 'bg-marine hover:bg-marine-fonce'"></button>
        </footer>
    </div>
</div>
