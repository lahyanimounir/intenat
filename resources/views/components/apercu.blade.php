@props(['chemin', 'titre', 'nom' => null, 'fermer' => 'fermerDialogue()'])
{{--
    Aperçu d'un document protégé. Le fichier est récupéré avec la session de la personne connectée, après contrôle
    d'accès par la route, puis affiché depuis la mémoire du navigateur : aucune adresse publique n'existe.
--}}
<x-dialogue :titre="$titre" :description="$nom" largeur="xl" :fermer="$fermer">
    <div x-data="{
            url: null, type: '', etat: 'chargement',
            async init() {
                try {
                    const reponse = await fetch(@js($chemin), { credentials: 'same-origin', headers: { Accept: '*/*' } })
                    if (! reponse.ok) throw new Error(reponse.status)
                    const contenu = await reponse.blob()
                    this.type = contenu.type
                    this.url = URL.createObjectURL(contenu)
                    this.etat = 'pret'
                } catch (erreur) {
                    this.etat = 'erreur'
                }
            },
            destroy() { if (this.url) URL.revokeObjectURL(this.url) },
        }">
        <div x-show="etat === 'chargement'"><x-chargement libelle="Chargement du document…" class="h-[60dvh]" /></div>
        <div x-show="etat === 'erreur'" x-cloak>
            <x-alerte ton="danger" titre="Le document n’a pas pu être chargé">Fermez cette fenêtre et réessayez. Si le problème persiste, le fichier n’est peut-être plus disponible.</x-alerte>
        </div>
        <template x-if="etat === 'pret' && type.startsWith('image/')">
            <img x-bind:src="url" alt="{{ $titre }}" class="mx-auto max-h-[68dvh] rounded-sm border border-bord" />
        </template>
        <template x-if="etat === 'pret' && type.includes('pdf')">
            <iframe x-bind:src="url" title="{{ $titre }}" class="h-[68dvh] w-full rounded-sm border border-bord"></iframe>
        </template>
        <template x-if="etat === 'pret' && ! type.startsWith('image/') && ! type.includes('pdf')">
            <x-alerte ton="neutre" titre="Aperçu indisponible pour ce type de fichier">Téléchargez le document pour le consulter.</x-alerte>
        </template>
    </div>
    <x-slot:pied>
        <x-bouton :href="$chemin.(str_contains($chemin, '?') ? '&' : '?').'telecharger=1'" :navigation="false" icone="download">Télécharger</x-bouton>
    </x-slot:pied>
</x-dialogue>
