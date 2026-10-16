<div>
    <span class="flex size-11 items-center justify-center rounded-full bg-info-fond text-info">
        <x-icone nom="mail-check" class="size-6" trait="1.75" />
    </span>
    <h1 class="mt-4 text-xl font-semibold text-encre">Confirmez votre adresse e-mail</h1>
    <p class="mt-2 text-base text-encre-2">
        Un lien de confirmation a été envoyé à <strong class="font-medium break-all text-encre">{{ $email }}</strong>.
        Ouvrez-le pour poursuivre votre inscription : il est valable {{ \App\Notifications\VerificationAdresse::MINUTES }} minutes.
    </p>
    <p class="mt-3 text-sm text-discret">Rien reçu ? Vérifiez le dossier des courriers indésirables, puis demandez un nouvel envoi.</p>

    @if ($renvoye)
        <x-alerte ton="succes" class="mt-5">Un nouveau lien vient de vous être envoyé.</x-alerte>
    @endif
    @error('renvoi')
        <x-alerte ton="danger" class="mt-5">{{ $message }}</x-alerte>
    @enderror

    <div class="mt-5 flex flex-wrap items-center gap-2">
        <x-bouton variante="primaire" wire:click="renvoyer" cible="renvoyer" icone="send">Renvoyer le lien</x-bouton>
        <form method="post" action="{{ route('deconnexion') }}">
            @csrf
            <x-bouton type="submit" variante="discret">Se déconnecter</x-bouton>
        </form>
    </div>
</div>
