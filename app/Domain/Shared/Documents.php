<?php

namespace App\Domain\Shared;

use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Stockage privé des fichiers (pièces, reçus, rapports, documents, exports).
 *
 * Les fichiers ne sont jamais exposés par une URL publique : chaque lecture
 * passe par un contrôleur qui vérifie l'autorisation puis appelle `servir()`.
 */
class Documents
{
    private const MIME_PAR_EXTENSION = [
        'pdf' => ['application/pdf'],
        'jpg' => ['image/jpeg'],
        'jpeg' => ['image/jpeg'],
        'png' => ['image/png'],
        'csv' => ['text/csv', 'text/plain', 'application/csv', 'application/vnd.ms-excel'],
    ];

    public function disque(): Filesystem
    {
        return Storage::disk(config('internat.documents.disque'));
    }

    /**
     * Lit « PDF, JPG, 5 Mo » : extensions autorisées et taille maximale, bornées
     * par la configuration globale.
     *
     * @return array{extensions: list<string>, taille_max_ko: int}
     */
    public function regles(?string $formatsAcceptes): array
    {
        $globales = config('internat.documents.extensions');
        $tailleGlobale = (int) config('internat.documents.taille_max_ko');
        $texte = mb_strtolower((string) $formatsAcceptes);

        $extensions = array_values(array_filter(
            $globales,
            fn (string $ext) => preg_match('/\b'.preg_quote($ext, '/').'\b/', $texte) === 1
        ));

        if (in_array('jpg', $extensions, true) && ! in_array('jpeg', $extensions, true)) {
            $extensions[] = 'jpeg';
        }

        $taille = $tailleGlobale;

        if (preg_match('/(\d+(?:[.,]\d+)?)\s*mo/', $texte, $m) === 1) {
            $taille = min($tailleGlobale, (int) round((float) str_replace(',', '.', $m[1]) * 1024));
        }

        return [
            'extensions' => $extensions === [] ? $globales : $extensions,
            'taille_max_ko' => max(1, $taille),
        ];
    }

    /**
     * Contrôle l'extension, le type réel (détecté sur le contenu) et la taille,
     * puis enregistre le fichier sous un nom aléatoire.
     *
     * @param  list<string>  $extensions
     * @return array{chemin: string, nom_original: string, mime: string, taille: int}
     */
    public function enregistrer(UploadedFile $fichier, string $dossier, array $extensions, int $tailleMaxKo): array
    {
        if (! $fichier->isValid()) {
            throw new RegleMetierException('Le téléversement a échoué. Réessayez.', 'fichier_invalide');
        }

        $extension = mb_strtolower($fichier->getClientOriginalExtension());
        $mime = (string) $fichier->getMimeType();

        if (! in_array($extension, $extensions, true)
            || ! in_array($mime, self::MIME_PAR_EXTENSION[$extension] ?? [], true)) {
            throw new RegleMetierException(
                'Format non accepté. Formats autorisés : '.mb_strtoupper(implode(', ', $extensions)).'.',
                'format_refuse',
            );
        }

        if ($fichier->getSize() > $tailleMaxKo * 1024) {
            throw new RegleMetierException(
                'Fichier trop volumineux (maximum '.round($tailleMaxKo / 1024, 1).' Mo).',
                'taille_depassee',
            );
        }

        $chemin = $fichier->storeAs($dossier, Str::uuid()->toString().'.'.$extension, [
            'disk' => config('internat.documents.disque'),
        ]);

        return [
            'chemin' => $chemin,
            'nom_original' => mb_substr(basename($fichier->getClientOriginalName()), 0, 255),
            'mime' => $mime,
            'taille' => (int) $fichier->getSize(),
        ];
    }

    public function supprimer(?string $chemin): void
    {
        if ($chemin !== null && $this->disque()->exists($chemin)) {
            $this->disque()->delete($chemin);
        }
    }

    /** Diffuse un fichier privé après contrôle d'accès par l'appelant. */
    public function servir(string $chemin, string $nom, ?string $mime = null, bool $enLigne = true): StreamedResponse
    {
        abort_unless($this->disque()->exists($chemin), 404, 'Fichier introuvable.');

        $disposition = $enLigne && in_array($mime, ['application/pdf', 'image/jpeg', 'image/png'], true)
            ? 'inline'
            : 'attachment';

        return $this->disque()->response($chemin, $nom, [
            'Content-Type' => $mime ?? 'application/octet-stream',
            'X-Content-Type-Options' => 'nosniff',
            'Cache-Control' => 'private, no-store',
            'Content-Security-Policy' => "default-src 'none'; style-src 'unsafe-inline'; sandbox",
        ], $disposition);
    }
}
