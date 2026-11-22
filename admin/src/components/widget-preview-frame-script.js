// Frame-side editing layer for the widget live preview.
// FRAME_SCRIPT runs inside a sandboxed iframe (opaque origin): plain ES2019, no imports.

// Look of the approved mockup: thin cyan hover outline, cyan focus ring with a soft halo, a teal
// label chip above the field, dashed "+ label" slots for empty fields. The focus ring pairs the
// cyan line with a dark teal one so it stays visible on light and dark themes alike; the empty
// image slot blends with whatever is behind it for the same reason.
// The site stylesheet sets html,body{min-height:100vh}. The parent sizes this frame to its
// scrollHeight, so a viewport-tall body plus a leading margin that collapses through it grows the
// frame on every report until the 6000px cap. Size the frame to the content instead.
export const FRAME_STYLE = `
html,body{min-height:0!important}
ispa-field{display:inline;position:relative;cursor:text;border-radius:4px}
ispa-field[data-type=textarea],ispa-field[data-type=image],img[data-type=image]{cursor:pointer}
ispa-field[data-type=textarea],ispa-field[data-type=image]{display:block}
ispa-field:hover,img[data-field]:hover{outline:1px solid rgba(60,195,216,.8);outline-offset:4px}
ispa-field:focus,img[data-field]:focus,[data-field][data-editing]{outline:2px solid #3cc3d8;outline-offset:4px;box-shadow:0 0 0 2px #0b6f7f,0 0 0 9px rgba(60,195,216,.22)}
ispa-field:hover::after,ispa-field:focus::after,ispa-field[data-editing]::after,ispa-chip{content:attr(data-label);position:absolute;left:-6px;top:-30px;z-index:2147483647;padding:0 7px;font:normal 600 11px/20px Inter,system-ui,sans-serif;letter-spacing:0;text-transform:none;text-align:left;color:#fff;-webkit-text-fill-color:#fff;background:#0b6f7f;border-radius:3px;white-space:nowrap;pointer-events:none}
ispa-field[data-typing][contenteditable]:focus::after{content:attr(data-label) " — " attr(data-typing)}
ispa-chip{display:none}
ispa-field:empty,ispa-field[data-empty]{display:inline-block;min-width:3em;min-height:22px;padding:0 8px;border:1px dashed;border-radius:4px;color:color-mix(in srgb,currentColor 62%,transparent);font:13px/20px system-ui,sans-serif;letter-spacing:0;text-transform:none}
ispa-field:empty:hover,ispa-field:empty:focus,ispa-field[data-editing]:empty{color:inherit}
ispa-field:empty::before,ispa-field[data-empty]:empty::before{content:"+ " attr(data-label)}
ispa-field[contenteditable]:empty:focus{min-width:9em;border-color:transparent}
ispa-field[contenteditable]:empty:focus::before{content:""}
ispa-field[data-type=image]:empty{display:block;width:100%;height:100%;min-height:44px;padding:0;border:0;border-radius:inherit}
ispa-field[data-type=image]:empty::before{content:"+";position:absolute;inset:0;display:flex;align-items:center;justify-content:center;border:1px dashed #fff;border-radius:inherit;font-size:18px;color:#fff;mix-blend-mode:difference;opacity:.75}
[data-repeat]:hover,[data-repeat]:focus-within,[data-repeat][data-item-active],[data-repeat][data-lifted]{outline:2px dashed #17a2b8;outline-offset:8px}
[data-repeat]:focus-visible{box-shadow:0 0 0 2px #0b6f7f}
[data-repeat][data-lifted]{transform:rotate(1.5deg);box-shadow:0 20px 44px rgba(15,42,34,.3);opacity:.96;z-index:5}
ispa-item-bar{display:none;position:absolute;left:0;top:0;width:0;height:0;z-index:2147483646;font:13px/1 Inter,system-ui,sans-serif;letter-spacing:0;text-transform:none;text-align:left}
ispa-item-chip{position:absolute;pointer-events:none;padding:0 8px;font:600 11px/22px Inter,system-ui,sans-serif;color:#fff;-webkit-text-fill-color:#fff;background:#0b6f7f;border-radius:3px;white-space:nowrap}
ispa-item-tools{position:absolute;display:flex;background:#fff;border:1px solid #17a2b8;border-radius:6px;box-shadow:0 6px 18px rgba(15,23,32,.2);overflow:hidden}
ispa-item-tools button{all:unset;box-sizing:border-box;display:flex;align-items:center;gap:6px;min-height:34px;padding:0 10px;border-left:1px solid #e5e7eb;background:#fff;color:#1f2933;-webkit-text-fill-color:currentColor;font:500 13px/1 Inter,system-ui,sans-serif;white-space:nowrap;cursor:pointer}
ispa-item-tools button:first-child{border-left:0;color:#0b6f7f;font-weight:600;cursor:grab;touch-action:none}
ispa-item-tools button[data-action=remove]{color:#b42318}
ispa-item-tools button:hover{background:#eef1f4}
ispa-item-tools button:focus-visible{outline:2px solid #0b6f7f;outline-offset:-3px}
ispa-item-tools button[aria-disabled=true]{color:#6b7280;background:#f3f4f6;cursor:not-allowed}
ispa-item-tools svg{width:14px;height:14px;flex:none}
ispa-add-tile{display:flex;align-items:center;justify-content:center;box-sizing:border-box;min-height:44px;padding:16px;border:1.5px dashed #17a2b8;border-radius:6px;background:rgba(255,255,255,.85);color:#0b6f7f;-webkit-text-fill-color:#0b6f7f;font:600 15px/20px Inter,system-ui,sans-serif;letter-spacing:0;text-transform:none;cursor:pointer}
ispa-add-tile:hover{background:#e6f5f8}
ispa-add-tile:focus-visible{outline:2px solid #0b6f7f;outline-offset:2px}
ispa-drop{position:absolute;z-index:2147483646;background:#17a2b8;border-radius:2px;box-shadow:0 0 0 3px rgba(23,162,184,.25);pointer-events:none}
@media (pointer:coarse){ispa-item-tools button{min-height:44px;min-width:44px}}
@media (max-width:767px){ispa-item-tools,ispa-add-tile{display:none!important}}
`

export const FRAME_SCRIPT = `(function () {
  var SOURCE = 'ispa-widget-edit';
  var labels = window.__ISPA_LABELS__ || {};
  var strings = window.__ISPA_STRINGS__ || {};
  var repeaters = window.__ISPA_REPEATERS__ || {};
  // Phones edit an item in the parent's bottom sheet: tapping an item opens it, nothing is typed here.
  var NARROW = (window.innerWidth || 1024) < 768;
  function post(msg) {
    msg.source = SOURCE;
    try { window.parent.postMessage(msg, '*'); } catch (e) {}
  }
  // Probe once: Firefox < 136 does not support contenteditable="plaintext-only".
  var probe = document.createElement('div');
  probe.setAttribute('contenteditable', 'plaintext-only');
  var PLAIN = probe.contentEditable === 'plaintext-only';
  function fieldOf(el) {
    return el && el.closest ? el.closest('ispa-field[data-field], img[data-field]') : null;
  }
  function same(name) {
    return Array.prototype.slice.call(document.querySelectorAll('ispa-field[data-field]')).filter(function (f) {
      return f.getAttribute('data-field') === name && f.getAttribute('data-type') === 'text';
    });
  }
  function rectOf(el) {
    var r = el.getBoundingClientRect();
    return { x: r.x, y: r.y, width: r.width, height: r.height };
  }
  function setup(el) {
    var name = el.getAttribute('data-field');
    var type = el.getAttribute('data-type');
    if (labels[name]) {
      el.setAttribute('data-label', labels[name]);
      el.setAttribute('aria-label', labels[name]);
    }
    el.setAttribute('tabindex', '0');
    if (type === 'text' && !(NARROW && rootOf(el))) {
      el.setAttribute('contenteditable', PLAIN ? 'plaintext-only' : 'true');
      if (strings.typing) el.setAttribute('data-typing', strings.typing);
      el.setAttribute('role', 'textbox');
    } else {
      el.setAttribute('role', 'button');
    }
  }
  Array.prototype.slice.call(document.querySelectorAll('ispa-field[data-field], img[data-field]')).forEach(setup);

  // An <img> cannot carry the CSS label chip (no pseudo-elements): float one element over it.
  var chip = null;
  function showChip(el) {
    var label = el && el.tagName === 'IMG' ? el.getAttribute('data-label') : '';
    if (!label) { hideChip(); return; }
    if (!chip) { chip = document.createElement('ispa-chip'); document.body.appendChild(chip); }
    var r = el.getBoundingClientRect();
    chip.textContent = label;
    chip.style.left = (r.left + (window.scrollX || 0) - 6) + 'px';
    chip.style.top = ((r.top < 34 ? r.bottom + 10 : r.top - 30) + (window.scrollY || 0)) + 'px';
    chip.style.display = 'block';
  }
  function hideChip() { if (chip) chip.style.display = 'none'; }
  function editingField() { return document.querySelector('[data-field][data-editing]'); }
  document.addEventListener('mouseover', function (e) { showChip(fieldOf(e.target) || editingField()); }, true);
  document.addEventListener('focusin', function (e) { showChip(fieldOf(e.target) || editingField()); }, true);

  function insertText(el, text) {
    var done = false;
    if (typeof document.execCommand === 'function') {
      try { done = document.execCommand('insertText', false, text); } catch (e) { done = false; }
    }
    if (!done) {
      el.textContent = (el.textContent || '') + text;
      el.dispatchEvent(new Event('input', { bubbles: true }));
    }
  }

  document.addEventListener('paste', function (e) {
    var el = fieldOf(e.target);
    if (!el || el.getAttribute('data-type') !== 'text') return;
    e.preventDefault();
    var text = e.clipboardData ? e.clipboardData.getData('text/plain') : '';
    text = String(text).replace(/[\\r\\n]+/g, ' ');
    if (text) insertText(el, text);
  }, true);

  document.addEventListener('drop', function (e) {
    var el = fieldOf(e.target);
    if (!el || el.getAttribute('data-type') !== 'text') return;
    e.preventDefault();
    var text = e.dataTransfer ? e.dataTransfer.getData('text/plain') : '';
    text = String(text).replace(/[\\r\\n]+/g, ' ');
    if (text) insertText(el, text);
  }, true);

  if (!PLAIN) {
    // Fallback: block formatting and line breaks so no markup is injected.
    document.addEventListener('beforeinput', function (e) {
      var el = fieldOf(e.target);
      if (!el || el.getAttribute('data-type') !== 'text') return;
      var t = e.inputType || '';
      if (t.indexOf('format') === 0 || t === 'insertParagraph' || t === 'insertLineBreak') e.preventDefault();
    }, true);
  }

  document.addEventListener('keydown', function (e) {
    if (e.key === 'Escape') {
      endDrag();
      if (inBar(e.target) && active) active.focus();
      post({ type: 'escape' });
      return;
    }
    if (itemKey(e)) return;
    var el = fieldOf(e.target);
    if (!el) return;
    var type = el.getAttribute('data-type');
    if (type === 'text') {
      if (e.key === 'Enter') e.preventDefault();
    } else if (e.key === 'Enter' || e.key === ' ') {
      e.preventDefault();
      post({ type: 'field-open', name: el.getAttribute('data-field'), rect: rectOf(el) });
    }
  }, true);

  document.addEventListener('input', function (e) {
    var el = fieldOf(e.target);
    if (!el || el.getAttribute('data-type') !== 'text') return;
    var name = el.getAttribute('data-field');
    // contenteditable inserts U+00A0 for trailing/double spaces: store normal spaces.
    var value = (el.textContent || '').replace(/\u00a0/g, ' ');
    same(name).forEach(function (other) {
      if (other !== el && other.textContent !== value) other.textContent = value;
    });
    post({ type: 'field-input', name: name, value: value });
  }, true);

  document.addEventListener('focusout', function (e) {
    var el = fieldOf(e.target);
    if (el && el.getAttribute('data-type') === 'text') {
      post({ type: 'field-commit', name: el.getAttribute('data-field') });
    }
  }, true);

  document.addEventListener('click', function (e) {
    if (itemClick(e)) return;
    var el = fieldOf(e.target);
    if (el && el.getAttribute('data-type') !== 'text') {
      e.preventDefault();
      post({ type: 'field-open', name: el.getAttribute('data-field'), rect: rectOf(el) });
      return;
    }
    var a = e.target && e.target.closest ? e.target.closest('a') : null;
    if (a) e.preventDefault();
  }, true);

  document.addEventListener('submit', function (e) { e.preventDefault(); }, true);

  window.addEventListener('scroll', function () {
    post({ type: 'scroll', y: window.scrollY || 0 });
  }, { passive: true });

  window.addEventListener('message', function (e) {
    var d = e.data;
    if (!d || d.source !== SOURCE) return;
    if (d.type === 'restore-scroll') window.scrollTo(0, Number(d.y) || 0);
    else if (d.type === 'focus-field') {
      var target = Array.prototype.slice.call(document.querySelectorAll('[data-field]')).filter(function (f) {
        return f.getAttribute('data-field') === d.name;
      })[0];
      if (target) target.focus();
    } else if (d.type === 'focus-item') {
      var item = rootsOf(d.repeater).filter(function (r) { return r.getAttribute('data-item-id') === d.id; })[0];
      if (item) item.focus();
    } else if (d.type === 'mark-field') {
      // The parent has this field open in its popover or sheet: outline it like a focused field.
      Array.prototype.slice.call(document.querySelectorAll('[data-editing]')).forEach(function (f) {
        f.removeAttribute('data-editing');
      });
      var open = d.name ? Array.prototype.slice.call(document.querySelectorAll('[data-field]')).filter(function (f) {
        return f.getAttribute('data-field') === d.name;
      })[0] : null;
      if (open) open.setAttribute('data-editing', '');
      showChip(open);
    }
  });

  // Repeater items: outline, chip "<Item> i / n" and toolbar on each item root, "+ Thêm" tile after the last one.
  function rootOf(el) { return el && el.closest ? el.closest('[data-repeat]') : null; }
  function rootsOf(name) {
    return Array.prototype.slice.call(document.querySelectorAll('[data-repeat]')).filter(function (r) {
      return r.getAttribute('data-repeat') === name;
    });
  }
  function itemMsg(type, root, toIndex) {
    var m = { type: type, repeater: root.getAttribute('data-repeat'), id: root.getAttribute('data-item-id') };
    if (toIndex !== undefined) m.toIndex = toIndex;
    post(m);
  }
  var ICONS = {
    drag: '<svg viewBox="0 0 16 16" fill="currentColor" aria-hidden="true"><circle cx="5.5" cy="3.5" r="1.3"/><circle cx="10.5" cy="3.5" r="1.3"/><circle cx="5.5" cy="8" r="1.3"/><circle cx="10.5" cy="8" r="1.3"/><circle cx="5.5" cy="12.5" r="1.3"/><circle cx="10.5" cy="12.5" r="1.3"/></svg>',
    duplicate: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" aria-hidden="true"><rect x="8.5" y="8.5" width="11" height="11" rx="1.5"/><path d="M15.5 8.5v-3a1 1 0 0 0-1-1h-9a1 1 0 0 0-1 1v9a1 1 0 0 0 1 1h3"/></svg>',
    remove: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" aria-hidden="true"><path d="M4 7h16M9.5 7V4.5h5V7M6.5 7l1 12.5h9l1-12.5"/></svg>'
  };
  function tool(action, text, disabled) {
    var b = document.createElement('button');
    b.type = 'button';
    b.setAttribute('data-action', action);
    b.innerHTML = ICONS[action];
    // The drag handle is icon-only so chip and toolbar fit side by side above narrow cards.
    if (action === 'drag') { b.setAttribute('aria-label', text || ''); b.title = text || ''; }
    else b.appendChild(document.createTextNode(text || ''));
    if (disabled) b.setAttribute('aria-disabled', 'true');
    return b;
  }
  Object.keys(repeaters).forEach(function (name) {
    var def = repeaters[name] || {};
    var roots = rootsOf(name);
    var n = roots.length;
    var label = String(def.item_label || '');
    var cap = label.charAt(0).toUpperCase() + label.slice(1);
    var full = Number(def.max) > 0 && n >= Number(def.max);
    roots.forEach(function (root, i) {
      if (root.tabIndex < 0) root.setAttribute('tabindex', '0');
      root.__ispaItem = { chip: cap + ' ' + (i + 1) + ' / ' + n, full: full };
    });
    if (!n || full) return;
    var last = roots[n - 1];
    var tile = document.createElement('ispa-add-tile');
    tile.setAttribute('role', 'button');
    tile.setAttribute('tabindex', '0');
    tile.setAttribute('data-repeat-add', name);
    tile.textContent = String(strings.add || '+ {item}').split('{item}').join(label);
    tile.style.minHeight = Math.max(44, last.offsetHeight || 0) + 'px';
    last.parentNode.insertBefore(tile, last.nextSibling);
  });

  // One overlay toolbar in <body> for the active item, placed from the item's rect, so no item CSS
  // (overflow:hidden, transforms) can clip it. The dashed outline stays on the item root itself.
  var bar = null, itemChip = null, tools = null, active = null;
  function inBar(el) { return !!(bar && el && el.nodeType === 1 && bar.contains(el)); }
  function placeBar() {
    if (!active) return;
    var r = active.getBoundingClientRect(), sx = window.scrollX || 0, sy = window.scrollY || 0;
    itemChip.style.left = (r.left + sx - 8) + 'px';
    itemChip.style.top = (r.top + sy - 36) + 'px';
    tools.style.left = (r.right + sx + 8 - tools.offsetWidth) + 'px';
    tools.style.top = (r.top + sy - 46) + 'px';
  }
  function showBar(root) {
    if (drag) return;
    if (!root || !root.__ispaItem) return hideBar();
    if (!bar) {
      bar = document.createElement('ispa-item-bar');
      itemChip = document.createElement('ispa-item-chip');
      tools = document.createElement('ispa-item-tools');
      tools.setAttribute('role', 'toolbar');
      tools.appendChild(tool('drag', strings.drag, false));
      tools.appendChild(tool('duplicate', strings.duplicate, false));
      tools.appendChild(tool('remove', strings.remove, false));
      bar.appendChild(itemChip);
      bar.appendChild(tools);
      document.body.appendChild(bar);
    }
    if (active && active !== root) active.removeAttribute('data-item-active');
    active = root;
    root.setAttribute('data-item-active', '');
    itemChip.textContent = root.__ispaItem.chip;
    tools.setAttribute('aria-label', root.__ispaItem.chip);
    var dup = tools.querySelector('[data-action=duplicate]');
    if (root.__ispaItem.full) dup.setAttribute('aria-disabled', 'true'); else dup.removeAttribute('aria-disabled');
    bar.style.display = 'block';
    placeBar();
  }
  function hideBar() {
    if (drag) return;
    if (active) active.removeAttribute('data-item-active');
    active = null;
    if (bar) bar.style.display = 'none';
  }
  function focusHere() {
    var f = document.activeElement;
    return !!active && (active.contains(f) || inBar(f));
  }
  document.addEventListener('mouseover', function (e) {
    if (inBar(e.target)) return;
    var root = rootOf(e.target);
    if (root) showBar(root);
    else if (!focusHere()) hideBar();
  }, true);
  document.addEventListener('focusin', function (e) {
    if (!inBar(e.target)) showBar(rootOf(e.target));
  }, true);
  // The pointer left the preview (no relatedTarget): drop hover chrome unless keyboard focus holds it.
  document.addEventListener('mouseout', function (e) {
    if (!e.relatedTarget && !focusHere()) hideBar();
  }, true);
  window.addEventListener('scroll', placeBar, { passive: true });
  window.addEventListener('resize', placeBar);
  // Tab order stays root → toolbar → the item's own content, as if the toolbar sat inside the item.
  function focusables() {
    return Array.prototype.slice.call(document.querySelectorAll('a[href],button,input,select,textarea,[tabindex]')).filter(function (el) {
      return el.tabIndex >= 0 && !el.disabled && !inBar(el);
    });
  }
  function tabKey(e) {
    if (e.key !== 'Tab' || !active || !bar || bar.style.display === 'none') return false;
    var buttons = tools.querySelectorAll('button');
    var next = null;
    if (e.target === active && !e.shiftKey) next = buttons[0];
    else if (inBar(e.target) && e.shiftKey && e.target === buttons[0]) next = active;
    else if (inBar(e.target) && !e.shiftKey && e.target === buttons[buttons.length - 1]) {
      var list = focusables();
      next = list[list.indexOf(active) + 1] || null;
    }
    if (!next) return false;
    e.preventDefault();
    next.focus();
    return true;
  }
  function toolOf(el) { return el && el.closest ? el.closest('ispa-item-tools button, ispa-add-tile') : null; }
  function activate(t) {
    if (t.tagName === 'ISPA-ADD-TILE') { post({ type: 'item-add', repeater: t.getAttribute('data-repeat-add') }); return; }
    var action = t.getAttribute('data-action');
    var root = inBar(t) ? active : rootOf(t);
    if (root && action !== 'drag' && t.getAttribute('aria-disabled') !== 'true') itemMsg('item-' + action, root);
  }
  function itemClick(e) {
    var t = toolOf(e.target);
    if (t) { e.preventDefault(); activate(t); return true; }
    var root = NARROW ? rootOf(e.target) : null;
    if (!root) return false;
    e.preventDefault();
    itemMsg('item-open', root);
    return true;
  }
  function itemKey(e) {
    if (tabKey(e)) return true;
    var t = toolOf(e.target);
    var press = e.key === 'Enter' || e.key === ' ';
    if (t && press) { e.preventDefault(); activate(t); return true; }
    var root = inBar(e.target) ? active : rootOf(e.target);
    if (!root) return false;
    if (NARROW && press) { e.preventDefault(); itemMsg('item-open', root); return true; }
    if (!e.altKey || (e.key !== 'ArrowUp' && e.key !== 'ArrowDown')) return false;
    // Only on the item itself or its toolbar: inside a text field Option+arrows move the caret (macOS).
    if (e.target !== root && !inBar(e.target)) return false;
    e.preventDefault();
    var roots = rootsOf(root.getAttribute('data-repeat'));
    var to = roots.indexOf(root) + (e.key === 'ArrowUp' ? -1 : 1);
    if (to >= 0 && to < roots.length) itemMsg('item-move', root, to);
    return true;
  }

  // Pointer drag from the toolbar handle: the item lifts and follows, a bar marks the drop slot.
  var drag = null;
  function endDrag() {
    if (!drag) return;
    drag.root.removeAttribute('data-lifted');
    drag.root.style.translate = '';
    if (drag.line.parentNode) drag.line.parentNode.removeChild(drag.line);
    drag = null;
    placeBar();
  }
  document.addEventListener('pointerdown', function (e) {
    var t = toolOf(e.target);
    var root = t && t.getAttribute('data-action') === 'drag' ? active : null;
    if (!root || e.button > 0) return;
    e.preventDefault();
    endDrag();
    var roots = rootsOf(root.getAttribute('data-repeat'));
    var a = roots[0].getBoundingClientRect();
    var b = roots[1] ? roots[1].getBoundingClientRect() : null;
    drag = {
      root: root, roots: roots, from: roots.indexOf(root), x: e.clientX, y: e.clientY, to: -1,
      row: !!b && Math.abs(a.top - b.top) < a.height / 2, line: document.createElement('ispa-drop')
    };
    root.setAttribute('data-lifted', '');
    try { t.setPointerCapture(e.pointerId); } catch (err) {}
  }, true);
  document.addEventListener('pointermove', function (e) {
    if (!drag) return;
    drag.root.style.translate = (e.clientX - drag.x) + 'px ' + (e.clientY - drag.y) + 'px';
    placeBar();
    var best = -1, dist = Infinity;
    drag.roots.forEach(function (r, i) {
      if (r === drag.root) return;
      var c = r.getBoundingClientRect();
      var d = Math.pow(c.left + c.width / 2 - e.clientX, 2) + Math.pow(c.top + c.height / 2 - e.clientY, 2);
      if (d < dist) { dist = d; best = i; }
    });
    if (best < 0) return;
    var r = drag.roots[best].getBoundingClientRect();
    var before = drag.row ? e.clientX < r.left + r.width / 2 : e.clientY < r.top + r.height / 2;
    var slot = before ? best : best + 1;
    drag.to = slot > drag.from ? slot - 1 : slot;
    var sx = window.scrollX || 0, sy = window.scrollY || 0, st = drag.line.style;
    if (drag.to === drag.from) { st.display = 'none'; return; }
    st.display = 'block';
    if (drag.row) {
      st.left = ((before ? r.left - 10 : r.right + 6) + sx) + 'px'; st.top = (r.top + sy) + 'px';
      st.width = '4px'; st.height = r.height + 'px';
    } else {
      st.left = (r.left + sx) + 'px'; st.top = ((before ? r.top - 10 : r.bottom + 6) + sy) + 'px';
      st.width = r.width + 'px'; st.height = '4px';
    }
    if (!drag.line.parentNode) document.body.appendChild(drag.line);
  }, true);
  document.addEventListener('pointerup', function () {
    if (!drag) return;
    var root = drag.root, to = drag.to, from = drag.from;
    endDrag();
    if (to >= 0 && to !== from) itemMsg('item-move', root, to);
  }, true);
  document.addEventListener('pointercancel', endDrag, true);

  function reportSize() {
    post({ type: 'size', height: Math.min(document.documentElement.scrollHeight, 6000) });
  }
  if (typeof ResizeObserver === 'function') {
    try { new ResizeObserver(reportSize).observe(document.documentElement); } catch (e) {}
  }
  reportSize();
  // Fields the HTML parser dropped (e.g. inside <option> or <svg>) are missing here.
  post({
    type: 'fields-present',
    names: Array.prototype.slice.call(document.querySelectorAll('ispa-field[data-field], img[data-field]')).map(function (f) {
      return f.getAttribute('data-field');
    }).concat(Object.keys(repeaters).filter(function (n) { return rootsOf(n).length > 0; }))
  });
})();`
