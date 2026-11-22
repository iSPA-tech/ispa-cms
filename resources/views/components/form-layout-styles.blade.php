<style>
    .form-layout-grid{display:grid;grid-template-columns:repeat(var(--form-cols-mobile,1),minmax(0,1fr));gap:20px;align-items:start}
    .form-layout-grid>.form-layout-item{grid-column:span var(--form-span-mobile,1);min-width:0}
    .form-layout-grid>.form-layout-section{grid-column:1/-1}
    .form-layout-grid--legacy{gap:16px}
    @media(min-width:768px){.form-layout-grid{grid-template-columns:repeat(var(--form-cols-tablet,1),minmax(0,1fr))}.form-layout-grid>.form-layout-item{grid-column:span var(--form-span-tablet,1)}}
    @media(min-width:1024px){.form-layout-grid{grid-template-columns:repeat(var(--form-cols-desktop,1),minmax(0,1fr))}.form-layout-grid>.form-layout-item{grid-column:span var(--form-span-desktop,1)}}
</style>
