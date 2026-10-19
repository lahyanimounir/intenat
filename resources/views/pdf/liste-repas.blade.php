<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="utf-8">
    <title>{{ $titre }}</title>
    @include('pdf._style')
    <style>
        table.totaux { width: auto; margin-bottom: 6mm; }
        table.totaux td { padding: 1.6mm 6mm 1.6mm 0; font-size: 9pt; }
        table.totaux td.nombre { font-weight: bold; text-align: right; }
        table.donnees tr.attention td { background: #FFF7EC; }
        .alerte { color: #A04E06; font-weight: bold; }
        .discret { color: #64748B; }
        h2.repas { font-size: 10.5pt; color: #183153; margin: 5mm 0 2mm 0; }
    </style>
</head>
<body>
    @include('pdf._entete')

    <h1>{{ $titre }}</h1>
    <p class="sous-titre">{{ $sousTitre }}</p>

    <table class="totaux">
        @foreach ($totaux as $total)
            <tr>
                <td>{{ $total['repas']->libelle() }}</td>
                <td class="nombre">{{ $total['total'] }}</td>
                <td class="discret">{{ $total['attention'] > 0 ? $total['attention'].' avec régime ou allergie' : '' }}</td>
            </tr>
        @endforeach
        <tr>
            <td><strong>Total</strong></td>
            <td class="nombre">{{ collect($totaux)->sum('total') }}</td>
            <td></td>
        </tr>
    </table>

    @if ($lignes->isEmpty())
        <p class="vide">Aucun repas réservé pour les critères retenus.</p>
    @else
        @foreach ($parRepas as $type => $duRepas)
            <h2 class="repas">{{ $duRepas->first()->repas->libelle() }} — {{ $duRepas->count() }} repas</h2>
            <table class="donnees">
                <thead>
                    <tr>
                        <th>Nom</th><th>Prénom</th><th>CEF</th><th>Établissement</th><th>Internat</th>
                        <th>Date</th><th>Repas</th><th>Régime alimentaire</th><th>Allergie signalée</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($duRepas as $ligne)
                        <tr class="{{ $ligne->attention ? 'attention' : '' }}">
                            <td>{{ $ligne->nom }}</td>
                            <td>{{ $ligne->prenom }}</td>
                            <td>{{ $ligne->cef }}</td>
                            <td>{{ $ligne->etablissement }}</td>
                            <td>{{ $ligne->internat }}</td>
                            <td>{{ $ligne->date->format('d/m/Y') }}</td>
                            <td>{{ $ligne->repas->libelle() }}</td>
                            <td class="{{ $ligne->sansRegime ? 'discret' : '' }}">{{ $ligne->regimes }}</td>
                            <td class="{{ $ligne->allergie ? 'alerte' : 'discret' }}">{{ $ligne->allergie ?: 'Aucune' }}</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        @endforeach
    @endif
</body>
</html>
