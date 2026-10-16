/*
 * Script de l'interface INTERNAT.
 *
 * Livewire fournit Alpine.js : rien d'autre n'est chargé. Ce fichier ne déclare
 * que les rares comportements communs qui n'ont pas leur place dans une vue :
 * messages éphémères, demande de confirmation et saisie d'un motif.
 */

document.addEventListener('alpine:init', () => {
  const Alpine = window.Alpine

  // Messages éphémères : émis par un composant Livewire (`message`) ou repris de la session après une redirection.
  Alpine.data('messages', (initiaux = []) => ({
    liste: [],
    suivant: 1,
    init() {
      initiaux.forEach((message) => this.ajouter(message))
    },
    ajouter({ ton = 'succes', texte = '' }) {
      if (!texte) return
      const id = this.suivant++
      this.liste.push({ id, ton, texte })
      // Une erreur reste affichée plus longtemps : elle demande souvent une action.
      window.setTimeout(() => this.retirer(id), ton === 'danger' ? 9000 : 5000)
    },
    retirer(id) {
      this.liste = this.liste.filter((message) => message.id !== id)
    },
  }))

  // Une fenêtre commune pose la question ; la promesse n'est tenue que si la personne confirme.
  const demander = (evenement) => (options = {}) =>
    new Promise((accepter) => window.dispatchEvent(new CustomEvent(evenement, { detail: { options, accepter } })))

  Alpine.magic('confirmer', () => demander('confirmer'))
  Alpine.magic('motif', () => demander('motif'))

  Alpine.data('confirmation', () => ({
    ouvert: false,
    options: {},
    accepter: null,
    ouvrir({ options, accepter }) {
      this.options = options
      this.accepter = accepter
      this.ouvert = true
    },
    annuler() {
      this.ouvert = false
    },
    valider() {
      this.ouvert = false
      this.accepter?.()
    },
  }))

  Alpine.data('motif', () => ({
    ouvert: false,
    options: {},
    accepter: null,
    texte: '',
    touche: false,
    get minimum() {
      return this.options.minimum ?? 5
    },
    get invalide() {
      return this.texte.trim().length < this.minimum
    },
    ouvrir({ options, accepter }) {
      this.options = options
      this.accepter = accepter
      this.texte = ''
      this.touche = false
      this.ouvert = true
      this.$nextTick(() => this.$refs.texte?.focus())
    },
    annuler() {
      this.ouvert = false
    },
    valider() {
      this.touche = true
      if (this.invalide) return
      this.ouvert = false
      this.accepter?.(this.texte.trim())
    },
  }))
})

// Session fermée, expirée, ou compte désactivé pendant que la page était ouverte : la page se recharge et le
// serveur ramène à la connexion avec l'explication, plutôt que d'afficher une fenêtre d'erreur technique.
document.addEventListener('livewire:init', () => {
  window.Livewire.interceptRequest(({ onError }) => {
    onError(({ response, preventDefault }) => {
      if (response.status === 401 || response.status === 419) {
        preventDefault()
        window.location.reload()
      }
    })
  })
})
