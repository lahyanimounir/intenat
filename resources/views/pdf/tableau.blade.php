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
    <p class="sous-titre">{{ $sousTitre }} — {{ count($lignes) }} ligne(s)</p>

    @if (count($lignes) === 0)
        <p class="vide">Aucune donnée pour les critères retenus.</p>
    @else
        <table class="donnees">
            <thead>
                <tr>
                    @foreach ($colonnes as $colonne)
                        <th>{{ $colonne }}</th>
                    @endforeach
                </tr>
            </thead>
            <tbody>
                @foreach ($lignes as $ligne)
                    <tr>
                        @foreach ($ligne as $cellule)
                            <td>{{ $cellule }}</td>
                        @endforeach
                    </tr>
                @endforeach
            </tbody>
        </table>
    @endif
</body>
</html>
