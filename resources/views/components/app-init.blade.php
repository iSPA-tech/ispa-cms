<script src="https://unpkg.com/@popperjs/core@2"></script>
<script src="https://unpkg.com/tippy.js@6"></script>
<script>
    function appInit() {
        return {
            menuMobileState: false,
            toggleMobileMenu: function(status = undefined) {
                this.menuMobileState = status !== undefined ? !!status : !this.menuMobileState
            },
            //  comment modal
            commentModalState: false,
            toggleCommentModal: function(status = undefined) {
                this.commentModalState = status !== undefined ? !!status : !this.commentModalState
            },
        }
    }

    tippy('[data-tippy-content]')
</script>
