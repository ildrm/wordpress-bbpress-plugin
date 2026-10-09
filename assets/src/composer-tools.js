const escape = (text) => text.replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;').replace(/'/g, '&#39;');
const formats = { bold: ['<strong>', '</strong>'], italic: ['<em>', '</em>'], code: ['<code>', '</code>'], quote: ['\n<blockquote>', '</blockquote>\n'] };
export function formatSelection(text, start, end, kind, placeholder = 'text') {
  if (typeof text !== 'string' || typeof placeholder !== 'string' || !Object.hasOwn(formats, kind) || !Number.isInteger(start) || !Number.isInteger(end) || start < 0 || end < start || end > text.length) throw new Error('invalid_selection');
  const [before, after] = formats[kind];
  const selected = text.slice(start, end) || placeholder;
  const inner = kind === 'code' ? escape(selected) : selected;
  return { text: text.slice(0, start) + before + inner + after + text.slice(end), start: start + before.length, end: start + before.length + inner.length };
}
/** Native editors own editing/undo; the optional preview renders inert, currently authorized HTML. */
export function mountComposerTools({ config, action, target, type, form, anchor, textarea, editor, read, changed }) {
  if (!form || !textarea || !target?.value || !config.loggedIn || !config.draftOwner || !config.draftBlog) return;
  const labels = config.composerToolsLabels;
  const panel = document.createElement('section'); panel.className = 'fi-composer-tools'; panel.dataset.fiComposerTools = type;
  const toolbar = document.createElement('div'); toolbar.className = 'fi-composer-toolbar';
  toolbar.setAttribute('role', 'toolbar'); toolbar.setAttribute('aria-label', labels.toolbar); panel.append(toolbar);
  const status = document.createElement('p'); status.setAttribute('role', 'status'); status.setAttribute('aria-live', 'polite');
  let bookmark = null; const bound = new WeakSet();
  const bindEditor = (instance) => {
    if (!instance || instance.id !== textarea.id || bound.has(instance)) return;
    bound.add(instance);
    instance.on('blur', () => { if (instance.initialized && !instance.isHidden()) bookmark = instance.selection.getBookmark(2, true); });
    instance.on('change input undo redo SetContent', invalidate);
    instance.on('NodeChange SelectionChange show hide', updatePressed);
  };
  const buttons = [];
  const activeEditor = () => { const instance = editor(); return instance?.initialized && !instance.isHidden() ? instance : null; };
  let plainSelection = null;
  const rememberSelection = () => { if (!activeEditor()) plainSelection = { value: textarea.value, start: textarea.selectionStart, end: textarea.selectionEnd }; };
  textarea.addEventListener('select', rememberSelection);
  textarea.addEventListener('keyup', rememberSelection);
  textarea.addEventListener('mouseup', rememberSelection);
  textarea.addEventListener('keydown', (event) => { if (event.key === 'Tab') rememberSelection(); });
  for (const kind of ['bold', 'italic', 'code', 'quote']) {
    const button = document.createElement('button'); button.type = 'button'; button.textContent = labels[kind];
    button.dataset.fiFormat = kind; button.tabIndex = buttons.length ? -1 : 0; toolbar.append(button); buttons.push(button);
    button.addEventListener('pointerdown', rememberSelection, true);
    button.addEventListener('click', () => {
      try {
        const instance = activeEditor();
        if (instance) {
          instance.focus(); if (bookmark) instance.selection.moveToBookmark(bookmark);
          instance.execCommand('mceToggleFormat', false, kind === 'quote' ? 'blockquote' : kind); bookmark = null;
        } else {
          const original = textarea.value;
          const selection = plainSelection?.value === original ? plainSelection : { start: textarea.selectionStart, end: textarea.selectionEnd };
          const { start, end } = selection;
          const next = formatSelection(original, start, end, kind, labels.placeholder);
          textarea.focus();
          if (textarea.value !== original) throw new Error('selection_changed');
          textarea.setRangeText(next.text.slice(start, next.text.length - (original.length - end)), start, end, 'select');
          textarea.setSelectionRange(next.start, next.end); plainSelection = { value: textarea.value, start: next.start, end: next.end };
          textarea.dispatchEvent(new Event('input', { bubbles: true }));
        }
        changed(); invalidate(); updatePressed(); status.textContent = labels.formatted;
      } catch { status.textContent = labels.editUnavailable; }
    });
    button.addEventListener('focus', () => { for (const item of buttons) item.tabIndex = item === button ? 0 : -1; });
  }
  function updatePressed() {
    const instance = activeEditor();
    for (const button of buttons) {
      if (instance) button.setAttribute('aria-pressed', String(Boolean(instance.formatter.match(button.dataset.fiFormat === 'quote' ? 'blockquote' : button.dataset.fiFormat))));
      else button.removeAttribute('aria-pressed');
    }
  }
  toolbar.addEventListener('keydown', (event) => {
    if (!['ArrowLeft', 'ArrowRight', 'Home', 'End'].includes(event.key)) return;
    const current = buttons.indexOf(document.activeElement); if (current < 0) return;
    event.preventDefault(); const rtl = window.getComputedStyle(toolbar).direction === 'rtl';
    const delta = (event.key === 'ArrowRight' ? 1 : -1) * (rtl ? -1 : 1);
    const index = event.key === 'Home' ? 0 : event.key === 'End' ? buttons.length - 1 : (current + delta + buttons.length) % buttons.length;
    buttons[index].focus();
  });
  const preview = document.createElement('button'); preview.type = 'button'; preview.textContent = labels.preview;
  preview.dataset.fiPreview = type; preview.setAttribute('aria-expanded', 'false');
  const region = document.createElement('div'); region.id = 'fi-composer-preview-' + textarea.id; region.hidden = true;
  preview.setAttribute('aria-controls', region.id);
  const help = document.createElement('p'); help.textContent = labels.help; region.append(help);
  const frame = document.createElement('iframe'); frame.className = 'fi-composer-preview'; frame.title = labels.previewTitle; frame.setAttribute('sandbox', ''); frame.referrerPolicy = 'no-referrer';
  const close = document.createElement('button'); close.type = 'button'; close.textContent = labels.close; close.dataset.fiPreviewClose = type;
  region.append(frame, close); panel.append(preview, status, region); anchor.insertAdjacentElement('afterend', panel);
  let generation = 0; let busy = false;
  function invalidate() {
    ++generation;
    if (busy || !region.hidden) status.textContent = labels.changed;
    region.hidden = true; frame.removeAttribute('srcdoc'); preview.setAttribute('aria-expanded', 'false');
  }
  const identity = () => ({ object: Number(target.value), type, owner: Number(config.draftOwner), blog: Number(config.draftBlog) });
  const snapshot = (draft) => JSON.stringify([draft.title, draft.body]);
  preview.addEventListener('click', async () => {
    if (busy) return;
    const source = identity(); const draft = read(); const original = snapshot(draft); const ticket = ++generation;
    busy = true; preview.disabled = true; region.hidden = true; frame.removeAttribute('srcdoc'); preview.setAttribute('aria-expanded', 'false'); status.textContent = labels.loading;
    try {
      const result = await action('composer_preview', { ...source, ...draft });
      if (ticket !== generation || source.object !== Number(target.value) || snapshot(read()) !== original) {
        if (ticket === generation) status.textContent = labels.changed;
        return;
      }
      if (!result || result.owner !== source.owner || result.blog !== source.blog || result.object !== source.object || result.type !== type || typeof result.html !== 'string' || typeof result.title !== 'string') throw new Error('invalid_preview');
      // Strict server formatting allowlist plus sandbox/CSP prevents active media and navigation.
      frame.srcdoc = '<!doctype html><html lang="' + escape(document.documentElement.lang || 'en') + '" dir="' + (document.documentElement.dir === 'rtl' ? 'rtl' : 'ltr') + '"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width"><meta http-equiv="Content-Security-Policy" content="default-src \'none\'; style-src \'unsafe-inline\'; base-uri \'none\'; form-action \'none\'"><style>:root{color-scheme:light dark}body{font:1rem/1.5 system-ui;padding:1rem;margin:0;overflow-wrap:anywhere}pre,code{white-space:pre-wrap;overflow-wrap:anywhere}table{max-width:100%;border-collapse:collapse}th,td{border:1px solid;padding:.25rem}blockquote{margin-inline:1rem;padding-inline-start:1rem;border-inline-start:3px solid}h1{font-size:1.5rem}</style></head><body><main>' + (result.title ? '<h1>' + escape(result.title) + '</h1>' : '') + result.html + '</main></body></html>';
      region.hidden = false; preview.setAttribute('aria-expanded', 'true'); status.textContent = labels.ready;
    } catch (error) {
      if (ticket === generation) {
        let message = labels.unavailable;
        if (error.code === 'too_large') message = labels.tooLarge;
        else if (error.status === 429) message = labels.quota;
        else if ([401, 403, 404].includes(error.status)) message = labels.denied;
        status.textContent = message;
      }
    }
    finally { busy = false; preview.disabled = false; }
  });
  close.addEventListener('click', () => { invalidate(); status.textContent = ''; preview.focus(); });
  textarea.addEventListener('input', invalidate);
  textarea.addEventListener('fi:composer-change', invalidate);
  textarea.addEventListener('focus', updatePressed);
  form.querySelector('#bbp_topic_title')?.addEventListener('input', invalidate);
  target.addEventListener('change', () => { invalidate(); bookmark = null; plainSelection = null; status.textContent = ''; });
  form.addEventListener('submit', invalidate);
  document.addEventListener('visibilitychange', () => { if (document.hidden) { invalidate(); bookmark = null; } });
  bindEditor(editor()); updatePressed(); window.tinymce?.on('AddEditor', (event) => bindEditor(event.editor));
}
