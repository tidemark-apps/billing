// Tidemark Billing: the small amount of JavaScript the server-rendered UI needs. No build step, no dependencies.
(function () {
  'use strict';
  var root = document.documentElement;
  var $ = function (s, c) { return (c || document).querySelector(s); };
  var $$ = function (s, c) { return Array.prototype.slice.call((c || document).querySelectorAll(s)); };

  // ---- theme ------------------------------------------------------------------------------------------------
  function setTheme(t) {
    try { if (t === 'system') localStorage.removeItem('tidemark.theme'); else localStorage.setItem('tidemark.theme', t); } catch (e) {}
    if (t === 'system') delete root.dataset.theme; else root.dataset.theme = t;
    $$('.theme-seg button').forEach(function (b) { b.classList.toggle('on', b.dataset.theme === t); });
  }
  var stored = null; try { stored = localStorage.getItem('tidemark.theme'); } catch (e) {}
  $$('.theme-seg button').forEach(function (b) {
    b.classList.toggle('on', b.dataset.theme === (stored || 'system'));
    b.addEventListener('click', function () { setTheme(b.dataset.theme); });
  });

  // ---- popovers (account menu etc.) ---------------------------------------------------------------------------
  function closePops(except) {
    $$('[data-pop-trigger][aria-expanded="true"]').forEach(function (t) {
      if (t === except) return;
      t.setAttribute('aria-expanded', 'false');
      var p = t.parentElement.querySelector('.pop'); if (p) p.classList.add('hidden');
    });
  }
  $$('[data-pop-trigger]').forEach(function (t) {
    t.addEventListener('click', function (e) {
      e.stopPropagation();
      var p = t.parentElement.querySelector('.pop'); if (!p) return;
      var open = t.getAttribute('aria-expanded') === 'true';
      closePops(open ? null : t);
      t.setAttribute('aria-expanded', open ? 'false' : 'true');
      p.classList.toggle('hidden', open);
    });
  });
  document.addEventListener('click', function (e) { if (!e.target.closest('.pop')) closePops(); });
  document.addEventListener('keydown', function (e) { if (e.key === 'Escape') { closePops(); closeModal(); } });

  // ---- sidebar ------------------------------------------------------------------------------------------------
  var app = $('.app');
  if (app) {
    try { if (localStorage.getItem('tidemark.billing.side') === 'collapsed') app.classList.add('side-collapsed'); } catch (e) {}
    var collapse = $('.side-collapse');
    if (collapse) collapse.addEventListener('click', function () {
      var c = app.classList.toggle('side-collapsed');
      collapse.setAttribute('aria-pressed', c ? 'true' : 'false');
      try { localStorage.setItem('tidemark.billing.side', c ? 'collapsed' : 'open'); } catch (e) {}
    });
    var toggle = $('.side-toggle');
    if (toggle) toggle.addEventListener('click', function () { app.classList.toggle('side-open'); });
    var scrim = $('.side-scrim');
    if (scrim) scrim.addEventListener('click', function () { app.classList.remove('side-open'); });
  }

  // ---- confirm dialog (any form/button with data-confirm) -----------------------------------------------------
  var modal = null;
  function closeModal() { if (modal) { modal.remove(); modal = null; } }
  function confirmDialog(message, okLabel, danger, onOk) {
    closeModal();
    modal = document.createElement('div');
    modal.className = 'overlay';
    modal.innerHTML = '<div class="modal" role="alertdialog" aria-modal="true"><div class="modal-h"><h2></h2></div><div class="modal-b"><p class="muted"></p></div><div class="modal-f"><button type="button" class="btn" data-x>Cancel</button><button type="button" class="btn ' + (danger ? 'destructive' : 'primary') + '" data-ok></button></div></div>';
    $('h2', modal).textContent = 'Are you sure?';
    $('p', modal).textContent = message;
    $('[data-ok]', modal).textContent = okLabel || 'Confirm';
    modal.addEventListener('click', function (e) { if (e.target === modal || e.target.closest('[data-x]')) closeModal(); });
    $('[data-ok]', modal).addEventListener('click', function () { closeModal(); onOk(); });
    document.body.appendChild(modal);
    $('[data-ok]', modal).focus();
  }
  document.addEventListener('submit', function (e) {
    var f = e.target, m = f.dataset && f.dataset.confirm;
    if (!m || f.dataset.confirmed) return;
    e.preventDefault();
    confirmDialog(m, f.dataset.confirmLabel, f.hasAttribute('data-danger'), function () { f.dataset.confirmed = '1'; f.requestSubmit ? f.requestSubmit() : f.submit(); });
  });

  // ---- clipboard ----------------------------------------------------------------------------------------------
  document.addEventListener('click', function (e) {
    var b = e.target.closest('[data-copy]'); if (!b) return;
    var v = b.dataset.copy;
    (navigator.clipboard ? navigator.clipboard.writeText(v) : Promise.reject()).catch(function () {
      var t = document.createElement('textarea'); t.value = v; document.body.appendChild(t); t.select(); try { document.execCommand('copy'); } catch (x) {} t.remove();
    });
    var old = b.textContent; b.textContent = 'Copied'; setTimeout(function () { b.textContent = old; }, 1200);
  });

  // ---- toasts: flash messages fade away -------------------------------------------------------------------------
  $$('.toast[data-auto]').forEach(function (t) { setTimeout(function () { t.style.transition = 'opacity .3s'; t.style.opacity = '0'; setTimeout(function () { t.remove(); }, 320); }, 5000); });

  // ---- tabs: <div data-tabs> with <a data-tab="id"> and panels <div data-panel="id"> ----------------------------
  $$('[data-tabs]').forEach(function (wrap) {
    var tabs = $$('[data-tab]', wrap), panels = $$('[data-panel]', wrap.parentElement);
    function show(id) {
      tabs.forEach(function (t) { var on = t.dataset.tab === id; t.classList.toggle('on', on); t.setAttribute('aria-selected', on ? 'true' : 'false'); });
      panels.forEach(function (p) { p.classList.toggle('hidden', p.dataset.panel !== id); });
      try { history.replaceState(null, '', '#' + id); } catch (e) {}
    }
    tabs.forEach(function (t) { t.addEventListener('click', function (e) { e.preventDefault(); show(t.dataset.tab); }); });
    var initial = (location.hash || '').slice(1);
    show(tabs.some(function (t) { return t.dataset.tab === initial; }) ? initial : (tabs[0] && tabs[0].dataset.tab));
  });

  // ---- repeaters: add/remove rows. <div data-repeater data-next="3"><template>…__INDEX__…</template>…<button data-add> ----
  $$('[data-repeater]').forEach(function (rep) {
    var tpl = $('template', rep), list = $('[data-rows]', rep) || rep, next = parseInt(rep.dataset.next || '0', 10);
    function recalc() {
      var digits = parseInt(rep.dataset.digits || '2', 10), sub = 0, taxable = 0, rate = parseFloat(rep.dataset.taxRate || '0');
      $$('[data-row]', rep).forEach(function (row) {
        var q = parseFloat(($('[data-qty]', row) || {}).value || '0') || 0, p = parseFloat(($('[data-price]', row) || {}).value || '0') || 0;
        var line = Math.round(q * p * Math.pow(10, digits)) / Math.pow(10, digits);
        var out = $('[data-line-total]', row); if (out) out.textContent = line.toFixed(digits);
        sub += line; var tx = $('[data-taxable]', row); if (!tx || tx.checked) taxable += line;
      });
      var tax = Math.round(taxable * rate / 100 * Math.pow(10, digits)) / Math.pow(10, digits);
      var set = function (k, v) { var el = $('[data-sum="' + k + '"]', rep.parentElement || rep); if (el) el.textContent = v.toFixed(digits); };
      set('subtotal', sub); set('tax', tax); set('total', sub + tax);
    }
    var add = $('[data-add]', rep);
    if (add && tpl) add.addEventListener('click', function () {
      var wrap = document.createElement('div'); wrap.innerHTML = tpl.innerHTML.replace(/__INDEX__/g, next++);
      while (wrap.firstElementChild) list.appendChild(wrap.firstElementChild);
      recalc();
    });
    rep.addEventListener('click', function (e) {
      var r = e.target.closest('[data-remove]'); if (!r) return;
      var rows = $$('[data-row]', rep); if (rows.length > (parseInt(rep.dataset.min || '0', 10))) { r.closest('[data-row]').remove(); recalc(); }
    });
    rep.addEventListener('input', recalc);
    rep.addEventListener('change', recalc);
    recalc();
  });

  // ---- toggle visibility: <select data-show-when="module" data-target="#id"> ------------------------------------
  $$('[data-reveal]').forEach(function (sel) {
    function apply() { $$('[data-reveal-for]').forEach(function (el) { if (el.dataset.revealGroup !== sel.dataset.reveal) return; el.classList.toggle('hidden', el.dataset.revealFor !== sel.value); }); }
    sel.addEventListener('change', apply); apply();
  });

  // ---- forms: inline validation, dirty tracking and secret fields (shared by every area) -------------------------
  // <form data-validate> validates on blur and submit and fills #form-summary; <form data-dirty-form> + [data-savebar]
  // tracks unsaved changes and warns on leave; [data-secret] wraps a "Set / Replace / Keep current" secret input.
  (function () {
    var n = 0;
    function holder(el) { return el.closest('label') && !el.closest('.line-row') ? el.closest('label') : (el.closest('.lf') || el.parentElement); }
    function clear(el) { var h = holder(el), e = h.querySelector('.field-error.js'); if (e) e.remove(); el.removeAttribute('aria-invalid'); el.removeAttribute('aria-describedby'); }
    function show(el) {
      clear(el);
      var m = el.dataset.msg || (el.tagName === 'SELECT' ? 'Choose an option.' : (el.validity.valueMissing ? 'This field is required.' : el.validationMessage));
      var s = document.createElement('span'); s.className = 'field-error js'; s.id = 'fe-' + (++n); s.textContent = m;
      holder(el).appendChild(s); el.setAttribute('aria-invalid', 'true'); el.setAttribute('aria-describedby', s.id);
    }
    $$('form[data-validate]').forEach(function (f) {
      f.noValidate = true;
      $$('[aria-required="true"]', f).forEach(function (e) { e.required = true; });
      var src = f.querySelector('[data-sum="total"]'), out = f.querySelector('[data-bar-total]');
      if (src && out) { var sync = function () { out.textContent = src.textContent; }; new MutationObserver(sync).observe(src, { childList: true, characterData: true, subtree: true }); sync(); }
      f.addEventListener('focusout', function (e) { var el = e.target; if (!el.willValidate || el.type === 'hidden') return; if (el.dataset.touched || el.hasAttribute('aria-invalid')) { el.checkValidity() ? clear(el) : show(el); } });
      f.addEventListener('input', function (e) { var el = e.target; el.dataset.touched = '1'; if (el.hasAttribute('aria-invalid') && el.checkValidity()) clear(el); });
      f.addEventListener('change', function (e) { var el = e.target; if (el.hasAttribute('aria-invalid') && el.checkValidity()) clear(el); });
      f.addEventListener('submit', function (e) {
        var bad = Array.prototype.filter.call(f.querySelectorAll('input,select,textarea'), function (el) { return el.willValidate && !el.checkValidity(); });
        if (!bad.length) return;
        e.preventDefault(); bad.forEach(show);
        var box = document.getElementById('form-summary');
        if (box) { box.hidden = false; var c = box.querySelector('[data-count]'); if (c) c.textContent = bad.length + (bad.length === 1 ? ' field needs' : ' fields need'); }
        bad[0].focus();
      });
    });
    $$('[data-secret]').forEach(function (w) {
      var state = w.querySelector('[data-secret-state]'), box = w.querySelector('[data-secret-input]'); if (!state || !box) return;
      var input = box.querySelector('input');
      w.querySelector('[data-secret-replace]').addEventListener('click', function () { state.classList.add('hidden'); box.classList.remove('hidden'); input.disabled = false; input.focus(); input.dispatchEvent(new Event('input', { bubbles: true })); });
      w.querySelector('[data-secret-cancel]').addEventListener('click', function () { input.value = ''; input.disabled = true; box.classList.add('hidden'); state.classList.remove('hidden'); state.querySelector('button').focus(); input.dispatchEvent(new Event('input', { bubbles: true })); });
    });
    $$('[data-dirty-form]').forEach(function (form) {
      var bar = document.querySelector('[data-savebar]'); if (!bar) return;
      var text = bar.querySelector('[data-savebar-text]'), discard = bar.querySelector('[data-savebar-discard]'), saving = false;
      function snap() { var o = []; new FormData(form).forEach(function (v, k) { if (typeof v === 'string') o.push(k + '=' + v); }); return o.join('&'); }
      var base = snap();
      function sync() { var d = snap() !== base; bar.classList.toggle('dirty', d); if (text) text.textContent = d ? 'Unsaved changes' : 'No unsaved changes'; if (discard) discard.hidden = !d; return d; }
      form.addEventListener('input', sync); form.addEventListener('change', sync);
      form.addEventListener('submit', function () { saving = true; });
      if (discard) discard.addEventListener('click', function () { form.reset(); $$('[data-secret-cancel]', form).forEach(function (b) { if (!b.closest('[data-secret-input]').classList.contains('hidden')) b.click(); }); sync(); });
      window.addEventListener('beforeunload', function (e) { if (!saving && sync()) { e.preventDefault(); e.returnValue = ''; } });
      var first = document.querySelector('.has-error input, .has-error select, .has-error textarea'); if (first) first.focus();
    });
  })();

  // ---- small shared behaviours ---------------------------------------------------------------------------------
  // <select data-autosubmit> submits its form on change; <a data-focus="#id"> focuses that field after the jump;
  // [data-composer] holds a Reply / Internal note toggle ([data-mode] tabs, #panel-<mode> panels) and sends on Ctrl/Cmd+Enter.
  $$('[data-autosubmit]').forEach(function (s) { s.addEventListener('change', function () { if (s.form) s.form.requestSubmit(); }); });
  $$('[data-focus]').forEach(function (a) { a.addEventListener('click', function () { var t = document.querySelector(a.dataset.focus); if (t) setTimeout(function () { t.focus(); }, 50); }); });
  $$('[data-composer]').forEach(function (box) {
    function setMode(mode) {
      box.classList.toggle('is-note', mode === 'note');
      $$('[data-mode]', box).forEach(function (t) { var on = t.dataset.mode === mode; t.setAttribute('aria-selected', on ? 'true' : 'false'); t.classList.toggle('on', on); });
      $$('.panel', box).forEach(function (p) { p.hidden = p.id !== 'panel-' + mode; });
    }
    $$('[data-mode]', box).forEach(function (t) {
      t.addEventListener('click', function () { setMode(t.dataset.mode); var ta = box.querySelector('#panel-' + t.dataset.mode + ' textarea'); if (ta) ta.focus(); });
    });
    box.addEventListener('keydown', function (e) {
      if ((e.metaKey || e.ctrlKey) && e.key === 'Enter' && e.target.tagName === 'TEXTAREA') { e.preventDefault(); e.target.form.requestSubmit(); }
    });
  });

  // ---- submit buttons show a spinner and block double submits ----------------------------------------------------
  document.addEventListener('submit', function (e) {
    var f = e.target; if (e.defaultPrevented || f.hasAttribute('data-no-lock')) return;
    $$('button[type="submit"],button:not([type])', f).forEach(function (b) { setTimeout(function () { b.classList.add('loading'); b.disabled = true; }, 0); });
  });
  window.addEventListener('pageshow', function (e) { if (e.persisted) $$('button.loading').forEach(function (b) { b.classList.remove('loading'); b.disabled = false; }); });
})();
