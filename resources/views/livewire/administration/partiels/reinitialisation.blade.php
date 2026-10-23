{{-- Fenêtre « Réinitialiser le mot de passe », commune aux listes des comptes. Attend $cibleMdp = ['nom', 'role']. --}}
@if ($dialogue === 'mot-de-passe' && $cibleMdp)
    <x-dialogue titre="Réinitialiser le mot de passe" :description="$cibleMdp['nom'].' — '.$cibleMdp['role']" fermer="fermerReinitialisation()">
        @if ($provisoire)
            <div class="space-y-4">
                <x-alerte ton="succes">Le mot de passe a été réinitialisé. Les sessions ouvertes de ce compte ont été fermées.</x-alerte>
                <div>
                    <p class="mb-1.5 text-sm font-medium text-encre">Mot de passe provisoire</p>
                    <div class="flex items-center gap-2" x-data="{ copie: false }">
                        <code class="chiffres flex-1 rounded-md border border-bord bg-surface-2 px-3 py-2 text-lg tracking-wider text-encre select-all">{{ $provisoire }}</code>
                        <x-bouton taille="sm" icone="clipboard-check" x-on:click="navigator.clipboard.writeText(@js($provisoire)); copie = true">
                            <span x-text="copie ? 'Copié' : 'Copier'">Copier</span>
                        </x-bouton>
                    </div>
                </div>
                <p class="text-sm text-encre-2">
                    Remettez-le en main propre au titulaire du compte, sans l’envoyer par courriel ni messagerie. Il ne sera plus affiché après la fermeture
                    de cette fenêtre et n’est conservé nulle part en clair. À sa prochaine connexion, le titulaire devra le remplacer par un mot de passe personnel.
                </p>
            </div>
            <x-slot:pied><x-bouton variante="primaire" wire:click="fermerReinitialisation">J’ai noté le mot de passe, fermer</x-bouton></x-slot:pied>
        @else
            <form wire:submit="reinitialiserMotDePasse" id="form-mot-de-passe" novalidate class="space-y-4">
                <p class="text-base text-encre-2">
                    Le mot de passe actuel n’est ni visible ni récupérable : il sera remplacé par un mot de passe provisoire, que le titulaire devra changer
                    à sa prochaine connexion. Ses sessions en cours seront fermées.
                </p>
                <fieldset class="space-y-2">
                    <legend class="mb-1 text-sm font-medium text-encre">Mot de passe provisoire</legend>
                    <x-case type="radio" wire:model.live="modeMdp" value="genere" libelle="Générer automatiquement un mot de passe sûr (recommandé)" />
                    <x-case type="radio" wire:model.live="modeMdp" value="saisi" libelle="Le définir moi-même" />
                </fieldset>
                @if ($modeMdp === 'saisi')
                    <x-nouveau-mot-de-passe libelle="Mot de passe provisoire" />
                @endif
            </form>
            <x-slot:pied>
                <x-bouton wire:click="fermerReinitialisation">Annuler</x-bouton>
                <x-bouton type="submit" form="form-mot-de-passe" variante="primaire" icone="key-round" cible="reinitialiserMotDePasse">Réinitialiser le mot de passe</x-bouton>
            </x-slot:pied>
        @endif
    </x-dialogue>
@endif
