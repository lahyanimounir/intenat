<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="utf-8">
    <title>{{ $titre }}</title>
    @include('pdf._style')
    <style>
        h2.internat { font-size: 11pt; color: #183153; margin: 4mm 0 1mm 0; }
        p.lieu { color: #64748B; font-size: 9pt; margin: 0 0 3mm 0; }
        h3.repas { font-size: 10pt; color: #183153; margin: 5mm 0 1.5mm 0; }
        h3.repas span { color: #64748B; font-weight: normal; }
        .provisoire { color: #A04E06; font-size: 8.5pt; margin: 0 0 1.5mm 0; }
        table.totaux { width: auto; margin-top: 6mm; }
        table.totaux td { padding: 1.4mm 6mm 1.4mm 0; font-size: 9pt; }
        table.totaux td.nombre { font-weight: bold; text-align: right; }
        .saut { page-break-before: always; }
    </style>
</head>
<body>
    @include('pdf._entete')

    <h1>{{ $titre }}</h1>
    <p class="sous-titre">{{ $jour }} — {{ $total }} absence(s) enregistrée(s)</p>

    @if ($internats->isEmpty())
        <p class="vide">Aucun repas validé pour cette journée.</p>
    @endif

    @foreach ($internats as $groupe)
        <div class="{{ $loop->first ? '' : 'saut' }}">
            <h2 class="internat">{{ $groupe->internat }}</h2>
            <p class="lieu">Établissement : {{ $groupe->etablissement }} · Internat : {{ $groupe->internat }} · Date : {{ $jour }}</p>

            @foreach ($groupe->repas as $section)
                <h3 class="repas">{{ $section->type->libelle() }} <span>— {{ $section->absents->count() }} absent(s)</span></h3>
                @if ($section->menu === null)
                    <p class="vide">Aucun menu validé pour ce repas.</p>
                @else
                    @unless ($section->clos)
                        <p class="provisoire">Service non clôturé : liste des absences non définitive.</p>
                    @endunless
                    @if ($section->absents->isEmpty())
                        <p class="vide">Aucun stagiaire absent pour ce repas.</p>
                    @else
                        <table class="donnees">
                            <thead><tr><th style="width: 8%">N°</th><th>Nom</th><th>Prénom</th><th style="width: 25%">CEF</th></tr></thead>
                            <tbody>
                                @foreach ($section->absents as $absent)
                                    <tr><td>{{ $loop->iteration }}</td><td>{{ $absent->nom }}</td><td>{{ $absent->prenom }}</td><td>{{ $absent->cef }}</td></tr>
                                @endforeach
                            </tbody>
                        </table>
                    @endif
                @endif
            @endforeach

            <table class="totaux">
                @foreach ($groupe->repas as $section)
                    <tr><td>{{ $section->type->libelle() }}</td><td class="nombre">{{ $section->absents->count() }}</td></tr>
                @endforeach
                <tr><td><strong>Total des absences de la journée</strong></td><td class="nombre">{{ $groupe->total }}</td></tr>
            </table>
        </div>
    @endforeach

    @if ($internats->count() > 1)
        <p class="sous-titre" style="margin-top: 6mm">Total général, tous internats : <strong>{{ $total }}</strong> absence(s).</p>
    @endif
    <p class="sous-titre" style="margin-top: 4mm">Un stagiaire absent à plusieurs repas compte une absence par repas.</p>
</body>
</html>
