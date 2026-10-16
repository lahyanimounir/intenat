<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="utf-8">
    <title>{{ $titre }}</title>
    @include('pdf._style')
</head>
<body>
    @include('pdf._entete')

    <h1>{{ $titre }}</h1>
    <p class="sous-titre">{{ $sousTitre }}</p>

    @foreach ($blocs as $bloc)
        <div class="bloc">
            <h2>{{ $bloc['titre'] }}</h2>
            <table class="fiche">
                @foreach ($bloc['lignes'] as $libelle => $valeur)
                    <tr>
                        <td class="libelle">{{ $libelle }}</td>
                        <td class="valeur">{{ $valeur ?? '—' }}</td>
                    </tr>
                @endforeach
            </table>
        </div>
    @endforeach

    @foreach ($paragraphes ?? [] as $paragraphe)
        <p class="bloc">{{ $paragraphe }}</p>
    @endforeach

    @if (! empty($signataire))
        <div class="signature">
            {{ $signataire }}
            <div class="trait">Signature et cachet</div>
        </div>
    @endif
</body>
</html>
