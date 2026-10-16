{{--
    Page d'erreur commune. Elle ne dépend ni de la session, ni de la base, ni des fichiers compilés :
    elle doit s'afficher même quand l'un d'eux fait défaut. Les couleurs reprennent les jetons de l'interface.
--}}
@php
    $accueil = url('/');
    $connexion = \Illuminate\Support\Facades\Route::has('connexion') ? route('connexion') : $accueil;
@endphp
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="utf-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1" />
    <meta name="robots" content="noindex, nofollow" />
    <title>{{ $titre }} — INTERNAT</title>
    <style>
        *, *::before, *::after { box-sizing: border-box; }
        body { margin: 0; min-height: 100dvh; display: grid; place-items: center; padding: 1.5rem; background: #f5f7fa; color: #172033;
            font-family: 'Inter Variable', ui-sans-serif, system-ui, -apple-system, 'Segoe UI', sans-serif; font-size: 0.9375rem; line-height: 1.55; }
        main { width: 100%; max-width: 30rem; background: #fff; border: 1px solid #e1e6ed; border-radius: 0.5rem; padding: 2rem; box-shadow: 0 1px 2px rgb(16 24 40 / 0.05); }
        .marque { margin: 0 0 1.5rem; font-size: 0.8125rem; font-weight: 600; letter-spacing: 0.06em; color: #183153; }
        .code { margin: 0; font-size: 0.8125rem; font-variant-numeric: tabular-nums; color: #5b6678; }
        h1 { margin: 0.25rem 0 0.75rem; font-size: 1.25rem; line-height: 1.3; font-weight: 600; }
        p { margin: 0; color: #36425a; }
        .actions { display: flex; flex-wrap: wrap; gap: 0.5rem; margin-top: 1.5rem; }
        a.bouton { display: inline-flex; align-items: center; height: 2.25rem; padding: 0 0.875rem; border-radius: 0.375rem; font-weight: 500; text-decoration: none; border: 1px solid #e1e6ed; color: #172033; background: #fff; }
        a.bouton:hover { background: #f3f6fa; border-color: #c9d5e5; }
        a.bouton.primaire { background: #183153; border-color: #183153; color: #fff; }
        a.bouton.primaire:hover { background: #11253f; }
        a.bouton:focus-visible { outline: 2px solid #2563a8; outline-offset: 2px; }
    </style>
</head>
<body>
    <main>
        <p class="marque">INTERNAT</p>
        <p class="code">Erreur {{ $code }}</p>
        <h1>{{ $titre }}</h1>
        <p>{{ $message }}</p>
        <div class="actions">
            @if ($action === 'connexion')
                <a class="bouton primaire" href="{{ $connexion }}">Se connecter</a>
            @elseif ($action === 'recharger')
                <a class="bouton primaire" href="{{ url()->current() }}">Recharger la page</a>
                <a class="bouton" href="{{ $accueil }}">Retour à l’accueil</a>
            @else
                <a class="bouton primaire" href="{{ $accueil }}">Retour à l’accueil</a>
            @endif
        </div>
    </main>
</body>
</html>
