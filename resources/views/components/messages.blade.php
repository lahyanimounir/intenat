@php
    // Messages posés en session avant une redirection : affichés une fois à l'arrivée.
    $initiaux = collect(['succes', 'danger', 'info', 'alerte'])
        ->filter(fn (string $ton) => session()->has($ton))
        ->map(fn (string $ton) => ['ton' => $ton, 'texte' => (string) session($ton)])
        ->values();
@endphp
{{-- Messages éphémères, annoncés aux lecteurs d'écran. --}}
<div x-data="messages(@js($initiaux))" x-on:message.window="ajouter($event.detail)"
    class="non-imprime pointer-events-none fixed right-4 bottom-4 z-[70] flex w-[calc(100vw-2rem)] max-w-sm flex-col gap-2" aria-live="polite">
    <template x-for="message in liste" x-bind:key="message.id">
        <div x-bind:role="message.ton === 'danger' ? 'alert' : 'status'"
            class="pointer-events-auto flex animate-[apparition_140ms_ease-out] items-start gap-3 rounded-md border bg-surface px-3.5 py-3 text-sm shadow-md"
            x-bind:class="{
                'border-succes-bord': message.ton === 'succes',
                'border-danger-bord': message.ton === 'danger',
                'border-info-bord': message.ton === 'info',
                'border-alerte-bord': message.ton === 'alerte',
            }">
            <span class="mt-1 size-2 shrink-0 rounded-full" aria-hidden="true"
                x-bind:class="{
                    'bg-succes': message.ton === 'succes',
                    'bg-danger': message.ton === 'danger',
                    'bg-info': message.ton === 'info',
                    'bg-alerte': message.ton === 'alerte',
                }"></span>
            <p class="min-w-0 flex-1 text-encre" x-text="message.texte"></p>
            <button type="button" class="-mt-0.5 -mr-1 rounded-sm p-1 text-discret hover:text-encre" aria-label="Fermer le message" x-on:click="retirer(message.id)">
                <x-icone nom="x" class="size-3.5" />
            </button>
        </div>
    </template>
</div>
