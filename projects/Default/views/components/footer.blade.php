{{-- Default theme footer: site name and description (SEO settings), the footer_menu group through the FooterMenu
     component (renders nothing when the group does not exist), copyright. --}}
<footer class="border-t border-slate-200 bg-slate-50">
    <div class="mx-auto flex max-w-6xl flex-col gap-8 px-4 py-12 sm:px-6 md:flex-row md:items-start md:justify-between">
        <div class="max-w-sm">
            <p class="text-base font-semibold text-slate-900">{{ iSeo('brand') }}</p>
            @if (filled(iSeo('description')))
                <p class="mt-2 text-sm/6 text-pretty text-slate-600">{{ iSeo('description') }}</p>
            @endif
        </div>
        <x-footer-menu template />
    </div>
    <div class="border-t border-slate-200">
        <p class="mx-auto max-w-6xl px-4 py-6 text-xs text-slate-500 sm:px-6">
            {{ __('default::theme.footer.copyright', ['year' => date('Y'), 'name' => iSeo('brand')]) }} · {{ __('default::theme.footer.powered') }}
        </p>
    </div>
</footer>
