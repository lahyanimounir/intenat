<header>
    <div class="institution">Office de la Formation Professionnelle et de la Promotion du Travail</div>
    <div class="service">Maisons des Stagiaires{{ isset($etablissement) && $etablissement ? ' — '.$etablissement : '' }}</div>
    <div class="reference">
        Réf. {{ $reference }}<br>
        Édité le {{ now()->format('d/m/Y à H:i') }}
    </div>
</header>
<footer>
    Document produit par l'application Maisons des Stagiaires - OFPPT{{ isset($auteur) && $auteur ? ' — édité par '.$auteur : '' }}
    <span class="droite page"></span>
</footer>
@if (config('internat.mode_demo'))
    <div class="demo">Démonstration — données synthétiques, document sans valeur administrative.</div>
@endif
