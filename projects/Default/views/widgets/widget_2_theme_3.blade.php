<!-- Blade template for widget: Instagram and project Default -->
<section aria-labelledby="perks-heading" class="border-t border-secondary-200 bg-secondary-50">
    <div class="py-12 lg:py-16">
        <h3 class="text-center text-xl lg:text-3xl">@{brand} in instagram</h3>

        <div class="embedsocial-hashtag" data-ref="{ref_id}"><a
                class="feed-powered-by-es feed-powered-by-es-slider-img es-widget-branding"
                href="https://embedsocial.com/social-media-aggregator/" target="_blank"
                title="Instagram widget"> <img src="https://embedsocial.com/cdn/icon/embedsocial-logo.webp"
                                               alt="EmbedSocial">
                <div class="es-widget-branding-text">Instagram widget</div>
            </a></div>
    </div>
</section>
@push('script')
<script> (function(d, s, id) {
                var js
                if (d.getElementById(id)) {
                    return
                }
                js = d.createElement(s)
                js.id = id
                js.src = 'https://embedsocial.com/cdn/ht.js'
                d.getElementsByTagName('head')[0].appendChild(js)
            }(document, 'script', 'EmbedSocialHashtagScript')) </script>
@endpush
