<?php

declare(strict_types=1);

namespace App\Support\Widget;

/**
 * Renders the inline editor-bridge <script> tag injected into a signed
 * Widget preview response minted WITH an editor nonce (the admin Placements
 * editor's clickable-preview mode). Root-owned on purpose: the injection
 * point ({@see \App\Http\Middleware\PrepareAnonymousWidgetPreviewRequest})
 * may not import Widget-module classes (D-040), so the script builder lives
 * beside it at the root.
 *
 * Protocol (one channel, both directions verified by the SAME opaque
 * editor nonce the admin chose at mint time — never a credential, purely a
 * message-pairing token):
 *  - iframe -> admin ({source:'wpe-layout-preview', nonce, type, ...}):
 *      'ready'            {keys: string[], areas: string[]} once markers are scanned;
 *      'placement-click'  {key, slotKey} on a click inside a marker;
 *      'area-add'         {slotKey} on a click on an area's add button.
 *  - admin -> iframe ({source:'wpe-layout-admin', nonce, type, ...}):
 *      'select' {key} highlight + scroll a marker into view;
 *      'clear'  {} drop the current highlight;
 *      'areas'  {labels: {slotKey: string}, addLabel: string} draw a labelled bar with an add
 *               button at the top of each area (text is set as text, never as HTML);
 *      'focus-area' {slotKey} scroll an area into view.
 *
 * Area contract (kept by the Widget module's slot runtime when it renders areas): in an editor
 * preview every area, also an empty one, is wrapped in a `display:contents` div carrying
 * `data-wpe-area-key` = the slot key.
 *
 * Marker contract (kept by the same slot runtime):
 * each rendered placement is wrapped in a `display:contents` div carrying
 * `data-wpe-placement-key` = `slot_key::scope_identity::widget_instance_uuid`
 * — the exact composite identity base-admin's placement-canvas-utils
 * derives client-side, so the two sides agree by construction. Because a
 * `display:contents` wrapper cannot paint, hover/selection outlines are
 * applied to the wrapper's element CHILDREN.
 *
 * In editor mode every non-marker link click is suppressed: navigating the
 * iframe away would abandon the single-use preview session and confuse the
 * editor. The script never reads cookies/storage (the sandbox has no
 * same-origin powers anyway) and posts no page content — identity keys only.
 */
final class WidgetPreviewEditorScript
{
    public const IFRAME_MESSAGE_SOURCE = 'wpe-layout-preview';

    public const ADMIN_MESSAGE_SOURCE = 'wpe-layout-admin';

    public const PLACEMENT_KEY_ATTRIBUTE = 'data-wpe-placement-key';

    public const SLOT_KEY_ATTRIBUTE = 'data-wpe-slot-key';

    public const AREA_KEY_ATTRIBUTE = 'data-wpe-area-key';

    /** Builds the full `<script nonce="...">...</script>` tag. */
    public static function tag(string $editorNonce, string $cspNonce): string
    {
        $nonceJson = json_encode($editorNonce, JSON_THROW_ON_ERROR | JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT);
        $cspNonceAttr = htmlspecialchars($cspNonce, ENT_QUOTES);

        $js = <<<JS
(function () {
    'use strict';
    var NONCE = {$nonceJson};
    var SOURCE = 'wpe-layout-preview';
    var ADMIN = 'wpe-layout-admin';
    var KEY_ATTR = 'data-wpe-placement-key';
    var SLOT_ATTR = 'data-wpe-slot-key';
    var AREA_ATTR = 'data-wpe-area-key';
    var BAR_ATTR = 'data-wpe-area-bar';

    var style = document.createElement('style');
    style.textContent = '[data-wpe-hover]{outline:2px dashed #6366f1 !important;outline-offset:2px}' +
        '[data-wpe-selected]{outline:2px solid #4f46e5 !important;outline-offset:2px}' +
        '[data-wpe-area-bar]{all:initial;display:flex !important;align-items:center;justify-content:space-between;gap:8px;' +
        'box-sizing:border-box;width:100%;margin:4px 0;padding:6px 10px;border:2px dashed #0891b2;border-radius:8px;' +
        'background:#ecfeff;color:#155e75;font:600 13px/1.4 system-ui,sans-serif}' +
        '[data-wpe-area-bar] button{all:initial;cursor:pointer;padding:4px 12px;border-radius:6px;background:#0891b2;' +
        'color:#fff;font:600 13px/1.4 system-ui,sans-serif;white-space:nowrap}' +
        '[data-wpe-area-bar] span{overflow:hidden;text-overflow:ellipsis;white-space:nowrap}';
    document.head.appendChild(style);

    function markers() {
        return Array.prototype.slice.call(document.querySelectorAll('[' + KEY_ATTR + ']'));
    }

    function post(type, payload) {
        var message = { source: SOURCE, nonce: NONCE, type: type };
        if (payload) { for (var k in payload) { message[k] = payload[k]; } }
        try { window.parent.postMessage(message, '*'); } catch (e) { /* no parent */ }
    }

    // display:contents wrappers cannot paint an outline; apply the visual
    // state to the wrapper's element children instead.
    function setState(wrapper, attr, on) {
        Array.prototype.forEach.call(wrapper.children, function (child) {
            if (on) { child.setAttribute(attr, '1'); } else { child.removeAttribute(attr); }
        });
    }

    function wrapperFor(node) {
        return node && node.closest ? node.closest('[' + KEY_ATTR + ']') : null;
    }

    function clearSelected() {
        markers().forEach(function (w) { setState(w, 'data-wpe-selected', false); });
    }

    function selectKey(key) {
        clearSelected();
        if (!key) { return; }
        var match = markers().filter(function (w) { return w.getAttribute(KEY_ATTR) === key; })[0];
        if (!match) { return; }
        setState(match, 'data-wpe-selected', true);
        var first = match.children[0];
        if (first && first.scrollIntoView) { first.scrollIntoView({ block: 'center', behavior: 'smooth' }); }
    }

    function areas() {
        return Array.prototype.slice.call(document.querySelectorAll('[' + AREA_ATTR + ']'));
    }

    // One labelled bar with an add button at the top of each area. Labels come from the admin and
    // are written with textContent only.
    function drawAreaBars(labels, addLabel) {
        Array.prototype.forEach.call(document.querySelectorAll('[' + BAR_ATTR + ']'), function (bar) {
            bar.parentNode.removeChild(bar);
        });
        areas().forEach(function (area) {
            var slotKey = area.getAttribute(AREA_ATTR);
            var label = labels && typeof labels[slotKey] === 'string' ? labels[slotKey] : '';
            if (!label) { return; }
            var bar = document.createElement('div');
            bar.setAttribute(BAR_ATTR, slotKey);
            var name = document.createElement('span');
            name.textContent = label;
            var add = document.createElement('button');
            add.type = 'button';
            add.textContent = typeof addLabel === 'string' && addLabel ? addLabel : '+';
            bar.appendChild(name);
            bar.appendChild(add);
            area.insertBefore(bar, area.firstChild);
        });
    }

    function focusArea(slotKey) {
        var bar = document.querySelector('[' + BAR_ATTR + '="' + String(slotKey).replace(/["\\\\]/g, '') + '"]');
        if (bar && bar.scrollIntoView) { bar.scrollIntoView({ block: 'center', behavior: 'smooth' }); }
    }

    document.addEventListener('click', function (event) {
        var bar = event.target && event.target.closest ? event.target.closest('[' + BAR_ATTR + ']') : null;
        if (bar) {
            event.preventDefault();
            event.stopPropagation();
            if (event.target.closest('button')) { post('area-add', { slotKey: bar.getAttribute(BAR_ATTR) }); }
            return;
        }
        var wrapper = wrapperFor(event.target);
        if (wrapper) {
            event.preventDefault();
            event.stopPropagation();
            var key = wrapper.getAttribute(KEY_ATTR);
            selectKey(key);
            post('placement-click', { key: key, slotKey: wrapper.getAttribute(SLOT_ATTR) });
            return;
        }
        // Editor mode: suppress ordinary navigation so the single-use
        // preview session is never abandoned by a stray link click.
        var anchor = event.target && event.target.closest ? event.target.closest('a') : null;
        if (anchor) { event.preventDefault(); }
    }, true);

    document.addEventListener('mouseover', function (event) {
        var wrapper = wrapperFor(event.target);
        if (wrapper) { setState(wrapper, 'data-wpe-hover', true); }
    }, true);

    document.addEventListener('mouseout', function (event) {
        var wrapper = wrapperFor(event.target);
        if (wrapper) { setState(wrapper, 'data-wpe-hover', false); }
    }, true);

    window.addEventListener('message', function (event) {
        var data = event.data || {};
        if (data.source !== ADMIN || data.nonce !== NONCE) { return; }
        if (data.type === 'select') { selectKey(data.key || null); }
        if (data.type === 'clear') { clearSelected(); }
        if (data.type === 'areas') { drawAreaBars(data.labels || {}, data.addLabel); }
        if (data.type === 'focus-area') { focusArea(data.slotKey); }
    });

    function announceReady() {
        post('ready', {
            keys: markers().map(function (w) { return w.getAttribute(KEY_ATTR); }),
            areas: areas().map(function (a) { return a.getAttribute(AREA_ATTR); })
        });
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', announceReady);
    } else {
        announceReady();
    }
})();
JS;

        return '<script nonce="' . $cspNonceAttr . '">' . $js . '</script>';
    }
}
