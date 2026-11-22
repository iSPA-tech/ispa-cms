document.addEventListener('alpine:init', () => {
  Alpine.data('itsLanguageModal', () => {
    return {
      openModal: false, error: false, success: false, loading: false,

      toggle() {
        //  Open/Close Modal
        this.openModal = ! this.openModal
      },
    }
  })
})
