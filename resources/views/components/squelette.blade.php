@props(['titre' => null])
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex, nofollow">
    <title>{{ $titre ? $titre.' — ' : '' }}Maisons des Stagiaires - OFPPT</title>
    <link rel="icon" href="data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 32 32'%3E%3Crect width='32' height='32' rx='6' fill='%23183153'/%3E%3Cpath fill='%23fff' d='M9 22V12.2L16 8l7 4.2V22h-4.6v-5.4h-4.8V22z'/%3E%3C/svg%3E">
    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>
<body {{ $attributes }}>
    {{ $slot }}
    <x-messages />
    <x-dialogues-communs />
</body>
</html>
