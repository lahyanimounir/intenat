<!doctype html>
<html lang="fr">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Liste des candidats — {{ $campagne->internat->nom }} {{ $campagne->annee }}</title>
    <style>
        :root { color-scheme: light; }
        * { box-sizing: border-box; }
        body { margin: 0; padding: 24px; font-family: "Segoe UI", system-ui, Arial, sans-serif; color: #16202f; font-size: 12px; line-height: 1.45; background: #fff; }
        .barre { display: flex; flex-wrap: wrap; gap: 10px; justify-content: space-between; align-items: center; margin-bottom: 18px; }
        .bouton { border: 1px solid #1d3f72; background: #1d3f72; color: #fff; font: inherit; font-weight: 600; padding: 8px 16px; border-radius: 7px; cursor: pointer; }
        .bouton:hover { background: #163257; }
        h1 { font-size: 18px; margin: 0 0 2px; }
        .meta { color: #425067; font-size: 12px; }
        .meta strong { color: #16202f; }
        table { width: 100%; border-collapse: collapse; margin-top: 14px; }
        thead th { text-align: left; font-size: 10.5px; text-transform: uppercase; letter-spacing: .04em; color: #425067; border-bottom: 2px solid #16202f; padding: 6px 8px; }
        tbody td { padding: 7px 8px; border-bottom: 1px solid #d8dfea; vertical-align: top; }
        tbody tr:nth-child(even) { background: #f6f8fb; }
        .rang { font-weight: 700; text-align: center; width: 36px; }
        .cef { font-variant-numeric: tabular-nums; white-space: nowrap; }
        .score { font-weight: 700; text-align: right; white-space: nowrap; font-variant-numeric: tabular-nums; }
        .notes { font-size: 11px; color: #425067; }
        .notes span { display: inline-block; }
        .statut { white-space: nowrap; font-weight: 600; }
        .vide { padding: 24px; text-align: center; color: #6b7890; border: 1px dashed #d8dfea; border-radius: 8px; margin-top: 14px; }
        .pied { margin-top: 18px; color: #6b7890; font-size: 10.5px; display: flex; justify-content: space-between; flex-wrap: wrap; gap: 8px; }
        .total { color: #6b7890; font-size: 12px; }
        @media print {
            body { padding: 0; font-size: 11px; }
            .non-imprime { display: none !important; }
            thead { display: table-header-group; }
            tbody tr { break-inside: avoid; }
            a[href]::after { content: ""; }
        }
    </style>
</head>
<body>
    <div class="barre non-imprime">
        <span class="total">{{ $lignes->count() }} candidat(s) · liste {{ $deliberee ? 'après délibération' : 'à délibérer' }}</span>
        <button type="button" class="bouton" onclick="window.print()">Imprimer</button>
    </div>

    <header>
        <h1>Liste des candidats {{ $deliberee ? '— résultats' : 'en vue de la délibération' }}</h1>
        <p class="meta">
            <strong>{{ $campagne->internat->nom }}</strong> · Campagne {{ $campagne->libellePeriode() }}<br>
            Établissement : <strong>{{ $campagne->internat->etablissement->nom_efp }}</strong> ({{ $campagne->internat->etablissement->code_efp }})
            · {{ $campagne->nombre_places }} place(s) offerte(s)
        </p>
    </header>

    @if ($lignes->isEmpty())
        <div class="vide">Aucun dossier classé pour cette campagne.</div>
    @else
        <table>
            <thead>
                <tr>
                    <th class="rang">Rang</th>
                    <th>Nom et prénom</th>
                    <th>CEF</th>
                    <th>Filière</th>
                    <th>Notes obtenues</th>
                    <th style="text-align:right">Score</th>
                    <th>Statut</th>
                </tr>
            </thead>
            <tbody>
                @foreach ($lignes as $ligne)
                    @php $d = $ligne['demande']; @endphp
                    <tr>
                        <td class="rang">{{ $d->rang }}</td>
                        <td><strong>{{ $d->stagiaire->nom }} {{ $d->stagiaire->prenom }}</strong><br><span class="notes">N° {{ $d->numero_dossier }}</span></td>
                        <td class="cef">{{ $d->stagiaire->cef }}</td>
                        <td>{{ $d->stagiaire->nom_filiere }}</td>
                        <td class="notes">
                            @forelse ($ligne['notes'] as $note)
                                <span>{{ $note['libelle'] }} : <strong>{{ $note['points'] }}</strong></span>@if (! $loop->last) · @endif
                            @empty
                                —
                            @endforelse
                        </td>
                        <td class="score">{{ $d->score }} / {{ $bareme }}</td>
                        <td class="statut">{{ $d->statut->libelle() }}</td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    @endif

    <div class="pied">
        <span>Imprimé le {{ now()->format('d/m/Y à H:i') }} par {{ $imprimePar->nomComplet() }}</span>
        <span>Document de travail — à usage interne de la délibération</span>
    </div>

    <script>
        // Ouvre directement la boîte d'impression à l'arrivée sur la page.
        window.addEventListener('load', () => setTimeout(() => window.print(), 250));
    </script>
</body>
</html>
