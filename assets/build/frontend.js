(() => {
  'use strict';
  const config = window.fiConfig;
  if (!config) return;
  const endpoint = (path) => {
    const url = new window.URL(config.rest, window.location.href);
    const separator = path.indexOf('?');
    const route = separator < 0 ? path : path.slice(0, separator);
    const query = separator < 0 ? '' : path.slice(separator + 1);
    if (url.searchParams.has('rest_route')) {
      url.searchParams.set('rest_route', url.searchParams.get('rest_route') + route);
    } else {
      url.pathname += route;
    }
    new window.URLSearchParams(query).forEach((value, key) => url.searchParams.set(key, value));
    return url.href;
  };
  const request = async (path, options = {}) => {
    const response = await fetch(endpoint(path), {
      credentials: 'same-origin',
      ...options,
      headers: { 'X-WP-Nonce': config.nonce, ...options.headers },
    });
    const raw = await response.text();
    const data = raw ? JSON.parse(raw) : null;
    if (!response.ok) { const error = new Error(data?.message || config.unavailable); error.code = data?.code; error.status = response.status; throw error; }
    return data;
  };
  const action = (name, data) => request('actions/' + name, {
    method: 'POST', headers: { 'Content-Type': 'application/json' },
    body: JSON.stringify({ data }),
  });
  const unreadIndicators = document.querySelectorAll('[data-fi-unread-label]');
  if (unreadIndicators.length && config.loggedIn) {
    let pending = false; let last = 0; let generation = 0;
    const refreshUnread = async () => {
      if (pending || document.hidden || Date.now() - last < 60000) return;
      pending = true; last = Date.now(); const current = ++generation;
      // Remove a previous count before rechecking current authorization.
      unreadIndicators.forEach((element) => { element.textContent = config.notifications; });
      try {
        const summary = await request('notification_summary', { cache: 'no-store' });
        if (!document.hidden && current === generation && summary && typeof summary.label === 'string') {
          unreadIndicators.forEach((element) => { element.textContent = summary.label; });
        }
      } catch { /* Keep the ordinary center link available with no stale count. */ }
      finally { pending = false; }
    };
    window.addEventListener('focus', refreshUnread);
    document.addEventListener('visibilitychange', () => {
      if (document.hidden) {
        ++generation; unreadIndicators.forEach((element) => { element.textContent = config.notifications; });
      }
      else refreshUnread();
    });
    refreshUnread();
  }
  const mentionBodies = [...document.querySelectorAll('#bbp_topic_content, #bbp_reply_content')];
  if (config.loggedIn && config.mentionsEnabled && mentionBodies.length) {
    if (config.mentionsManaged) {
      // Use BuddyPress's own lookup and notification ownership when enabled.
      mentionBodies.forEach((element) => element.classList.add('bp-suggestions'));
      const bindNative = () => {
        const native = window.jQuery;
        if (!native?.fn?.bp_mentions || !native.fn.atwho) return;
        mentionBodies.forEach((element) => {
          const target = native(element); if (!target.data('atwho')) target.bp_mentions(window.bp?.mentions?.users || []);
          const attachEditor = (instance) => {
            if (instance.id !== element.id) return;
            const bind = () => { const target = native(instance.getBody()); if (!target.data('atwho')) { target.atwho('setIframe', instance.iframeElement); target.bp_mentions(window.bp?.mentions?.users || []); } };
            if (instance.initialized) bind(); else instance.on('init', bind);
          };
          const tinymce = window.tinymce;
          const instance = tinymce?.get(element.id);
          if (instance) attachEditor(instance);
          tinymce?.on('AddEditor', (event) => attachEditor(event.editor));
        });
      };
      if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', bindNative, { once: true });
      else bindNative();
    } else {
      mentionBodies.forEach((element) => {
        const anchor = element.closest('.bbp-the-content-wrapper') || element.closest('.wp-editor-wrap') || element;
        const panel = document.createElement('div'); panel.className = 'fi-panel fi-mentions';
        const label = document.createElement('label'); label.textContent = config.mentionMember;
        const input = document.createElement('input'); input.type = 'search'; input.maxLength = 30;
        input.setAttribute('role', 'combobox'); input.setAttribute('aria-autocomplete', 'list'); input.setAttribute('aria-expanded', 'false');
        const list = document.createElement('ul'); list.id = 'fi-mentions-' + element.id; list.setAttribute('role', 'listbox'); list.hidden = true;
        input.setAttribute('aria-controls', list.id);
        const status = document.createElement('p'); status.id = list.id + '-status'; status.setAttribute('role', 'status'); status.setAttribute('aria-live', 'polite'); status.textContent = config.mentionHelp;
        input.setAttribute('aria-describedby', status.id);
        label.append(input); panel.append(label, list, status); anchor.insertAdjacentElement('afterend', panel);
        let timer; let controller; let generation = 0; let items = []; let selected = -1;
        const close = () => { list.replaceChildren(); list.hidden = true; items = []; selected = -1; input.removeAttribute('aria-activedescendant'); input.setAttribute('aria-expanded', 'false'); };
        const source = () => Number(element.id === 'bbp_reply_content' ? document.querySelector('#bbp_topic_id')?.value : (document.querySelector('[name="bbp_topic_id"]')?.value || document.querySelector('#bbp_forum_id')?.value));
        const choose = (index) => {
          const name = items[index]?.name;
          if (typeof name !== 'string' || !/^[A-Za-z0-9_.-]{1,60}$/.test(name)) return;
          const instance = window.tinymce?.get(element.id);
          if (instance && !instance.isHidden()) { instance.focus(); const range = instance.selection.getRng(); range.collapse(true); instance.selection.setRng(range); instance.execCommand('mceInsertContent', false, ' @' + name + ' '); }
          else {
            const start = element.selectionStart || 0; const end = element.selectionEnd || start;
            // Preserve selected/unsaved text: insert at the caret without replacing it.
            const prefix = start && /\S/.test(element.value[start - 1]) ? ' ' : '';
            element.setRangeText(prefix + '@' + name + ' ', start, start, 'end');
            if (end > start) element.selectionEnd = element.selectionStart;
            element.focus();
          }
          element.dispatchEvent(new Event('input', { bubbles: true }));
          close(); input.value = ''; status.textContent = config.mentionAdded;
        };
        const activate = (index) => {
          selected = index;
          [...list.children].forEach((option, current) => option.setAttribute('aria-selected', String(current === selected)));
          if (list.children[index]) input.setAttribute('aria-activedescendant', list.children[index].id);
        };
        const refresh = () => {
          clearTimeout(timer); controller?.abort(); const current = ++generation; close();
          const prefix = input.value.trim();
          if (prefix.length < 2 || !source()) { status.textContent = config.mentionHelp; return; }
          timer = setTimeout(async () => {
            controller = new AbortController(); status.textContent = config.mentionLoading;
            try {
              const data = await request('mentions?q=' + encodeURIComponent(prefix) + '&object=' + source(), { signal: controller.signal, cache: 'no-store' });
              if (current !== generation) return;
              items = data.items || [];
              items.forEach((item, index) => {
                const option = document.createElement('li'); option.id = list.id + '-' + index;
                option.setAttribute('role', 'option'); option.setAttribute('aria-selected', 'false');
                option.textContent = item.display_name + ' (@' + item.name + ')';
                option.addEventListener('mousedown', (event) => event.preventDefault());
                option.addEventListener('click', () => choose(index));
                list.append(option);
              });
              list.hidden = !items.length; input.setAttribute('aria-expanded', String(items.length > 0));
              status.textContent = items.length ? config.mentionHelp : config.mentionEmpty;
            } catch (error) { if (error.name !== 'AbortError' && current === generation) status.textContent = config.mentionUnavailable; }
          }, 350);
        };
        input.addEventListener('input', refresh);
        input.addEventListener('keydown', (event) => {
          if (event.key === 'Enter') { event.preventDefault(); if (selected >= 0) choose(selected); }
          else if (event.key === 'Escape') { ++generation; controller?.abort(); clearTimeout(timer); close(); }
          else if (items.length && ['ArrowDown', 'ArrowUp'].includes(event.key)) {
            event.preventDefault(); activate(selected < 0 ? (event.key === 'ArrowDown' ? 0 : items.length - 1) : (selected + (event.key === 'ArrowDown' ? 1 : -1) + items.length) % items.length);
          }
        });
        input.addEventListener('blur', () => { ++generation; controller?.abort(); clearTimeout(timer); close(); });
        document.querySelector('#bbp_forum_id')?.addEventListener('change', refresh);
      });
    }
  }
  const title = document.querySelector('#bbp_topic_title');
  const body = document.querySelector('#bbp_topic_content');
  const forum = document.querySelector('#bbp_forum_id');
  const composerAnchor = body?.closest('.bbp-the-content-wrapper') || body?.closest('.wp-editor-wrap') || body;
  const editor = () => { const instance = window.tinymce?.get('bbp_topic_content'); return instance?.initialized ? instance : null; };
  const bodyValue = () => editor() && !editor().isHidden() ? editor().getContent() : (body?.value || '');
  const setBody = (value, plain = false) => {
    if (!body) return;
    body.value = value;
    if (editor() && !editor().isHidden()) {
      const text = document.createElement('div'); text.textContent = value;
      editor().setContent(plain ? text.innerHTML.replace(/\n/g, '<br>') : value);
    }
    body.dispatchEvent(new Event('input', { bubbles: true }));
    body.dispatchEvent(new Event('fi:composer-change', { bubbles: true }));
  };
  if (body && forum && config.drafts && !Number(document.querySelector('[name="bbp_topic_id"]')?.value || 0)) {
    const labels = config.templateLabels;
    const axes = ['topic_type', 'product', 'category'];
    const container = document.createElement('div'); container.className = 'fi-panel'; container.dataset.fiTemplateControls = '';
    const choices = document.createElement('fieldset'); choices.className = 'fi-template-context'; choices.hidden = true;
    const legend = document.createElement('legend'); legend.textContent = labels.heading; choices.append(legend);
    const selectors = {};
    for (const axis of axes) {
      const label = document.createElement('label'); label.textContent = labels[axis] + ' ';
      const select = document.createElement('select'); select.dataset.fiTemplateAxis = axis; selectors[axis] = select;
      label.append(select); choices.append(label);
    }
    const preview = document.createElement('details'); preview.hidden = true;
    const summary = document.createElement('summary'); summary.textContent = labels.preview;
    const chosenLabel = document.createElement('strong');
    const text = document.createElement('pre'); preview.append(summary, chosenLabel, text);
    const button = document.createElement('button'); button.type = 'button'; button.textContent = config.useTemplate; button.hidden = true;
    const status = document.createElement('p'); status.setAttribute('role', 'status'); status.setAttribute('aria-live', 'polite');
    container.append(choices, preview, button, status); composerAnchor.insertAdjacentElement('afterend', container);
    let native = body.closest('form')?.querySelector('[data-fi-template-guidance]');
    if (!native) {
      native = document.createElement('details'); native.className = 'fi-panel'; native.dataset.fiTemplateGuidance = ''; native.hidden = true;
      composerAnchor.insertAdjacentElement('beforebegin', native);
    }
    const clearCatalog = () => {
      const heading = document.createElement('summary'); heading.textContent = labels.preview;
      native.replaceChildren(heading); native.hidden = true;
    };
    const catalog = (result) => {
      clearCatalog();
      if (!result.text && !result.templates.length) return;
      native.hidden = false;
      const help = document.createElement('p'); help.textContent = labels.help; native.append(help);
      if (result.text) {
        const title = document.createElement('strong'); title.textContent = result.label;
        const contents = document.createElement('pre'); contents.textContent = result.text; native.append(title, contents);
      }
      if (result.templates.length) {
        const list = document.createElement('details'); const heading = document.createElement('summary'); heading.textContent = labels.catalog; list.append(heading);
        for (const item of result.templates) {
          const details = document.createElement('details'); const title = document.createElement('summary'); title.textContent = item.label;
          const criteria = document.createElement('dl');
          for (const axis of axes) {
            if (item.criteria[axis]) {
              const term = document.createElement('dt'); term.textContent = labels[axis];
              const value = document.createElement('dd'); value.textContent = item.criteria[axis]; criteria.append(term, value);
            }
          }
          const contents = document.createElement('pre'); contents.textContent = item.text;
          details.append(title, criteria, contents); list.append(details);
        }
        native.append(list);
      }
    };
    const populate = (options, selection = {}) => {
      for (const axis of axes) {
        const select = selectors[axis]; select.replaceChildren();
        const any = document.createElement('option'); any.value = ''; any.textContent = labels.any; select.append(any);
        for (const value of options[axis] || []) {
          const option = document.createElement('option'); option.value = value; option.textContent = value; select.append(option);
        }
        select.value = selection[axis] || ''; select.closest('label').hidden = !(options[axis] || []).length;
      }
      choices.hidden = !axes.some(axis => (options[axis] || []).length);
    };
    let template = ''; let generation = 0;
    const refresh = async (changedSource = false) => {
      const current = ++generation; const object = Number(forum.value); button.hidden = true; template = ''; preview.hidden = true; text.textContent = '';
      if (changedSource) { populate({}); clearCatalog(); }
      if (!object) { status.textContent = ''; return; }
      const query = new URLSearchParams({ object: String(object) });
      for (const axis of axes) query.set(axis, selectors[axis].value || '');
      status.textContent = labels.loading;
      try {
        const result = await request('template?' + query.toString());
        if (current !== generation || Number(forum.value) !== object) return;
        if (Number(result.object) !== object || Number(result.owner) !== Number(config.draftOwner) || Number(result.blog) !== Number(config.draftBlog)) {
          clearCatalog(); populate({}); throw new Error('Template context changed');
        }
        populate(result.options, result.selection); catalog(result);
        template = result.text; button.hidden = !template; preview.hidden = !template;
        chosenLabel.textContent = result.label; text.textContent = template;
        status.textContent = template ? result.label : labels.empty;
      } catch (error) {
        if (current !== generation) return;
        if ([401, 403, 404].includes(error.status)) { clearCatalog(); populate({}); }
        status.textContent = labels.unavailable;
      }
    };
    button.addEventListener('click', () => {
      if (bodyValue().trim()) { status.textContent = config.templateOccupied; return; }
      setBody(template, true); status.textContent = config.templateUsed; button.hidden = true;
    });
    for (const axis of axes) selectors[axis].addEventListener('change', () => refresh());
    forum.addEventListener('change', () => refresh(true)); refresh();
  }
  const suggestions = document.querySelector('[data-fi-duplicates]');
  if (title && suggestions) {
    const status = suggestions.querySelector('[role="status"]');
    const list = suggestions.querySelector('ul');
    const labels = config.duplicateLabels;
    const dismiss = document.createElement('button'); dismiss.type = 'button'; dismiss.textContent = labels.dismiss; dismiss.hidden = true;
    const scoreHelp = document.createElement('p'); scoreHelp.textContent = labels.scoreHelp; scoreHelp.hidden = true;
    suggestions.append(scoreHelp, dismiss);
    let timer; let controller; let generation = 0; let currentOffer = '';
    const feedback = (offer, outcome, object = 0) => request('actions/duplicate_feedback', {
      method: 'POST', headers: { 'Content-Type': 'application/json' }, keepalive: true,
      body: JSON.stringify({ data: { offer, outcome, object } }),
    });
    dismiss.addEventListener('click', () => {
      const offer = currentOffer; currentOffer = ''; ++generation; controller?.abort(); clearTimeout(timer);
      list.replaceChildren(); scoreHelp.hidden = true; dismiss.hidden = true;
      status.textContent = labels.dismissed; title.focus();
      if (offer) feedback(offer, 'dismissed').catch(() => { /* Optional aggregate feedback never blocks native posting. */ });
    });
    const update = () => {
      clearTimeout(timer); controller?.abort(); const current = ++generation;
      currentOffer = ''; list.replaceChildren(); dismiss.hidden = true; scoreHelp.hidden = true;
      const query = title.value.trim();
      if (query.length < 12) { status.textContent = config.minimum; return; }
      status.textContent = config.loading;
      timer = setTimeout(async () => {
        controller = new AbortController();
        try {
          const fields = {};
          for (const field of document.querySelectorAll('[name^="fi_fields["]')) {
            const key = field.name.match(/^fi_fields\[([a-z][a-z0-9_]+)\]/)?.[1];
            if (key && !Array.isArray(field.value)) fields[key] = field.multiple ? Array.from(field.selectedOptions, (option) => option.value).slice(0, 20) : String(field.value).slice(0, 500);
          }
          const data = await request('duplicates', {
            method: 'POST', headers: { 'Content-Type': 'application/json' }, signal: controller.signal,
            body: JSON.stringify({ title: query.slice(0, 200), body: bodyValue().slice(0, 12000), forum: Number(forum?.value || 0), tags: (document.querySelector('#bbp_topic_tags')?.value || '').slice(0, 500), fields }),
          });
          if (current !== generation) return;
          const offer = config.loggedIn && typeof data.offer === 'string' ? data.offer : '';
          currentOffer = offer;
          for (const item of data.items) {
            const row = document.createElement('li');
            const link = document.createElement('a');
            link.href = item.url; link.textContent = item.title; link.target = '_blank'; link.rel = 'noopener noreferrer'; row.append(link);
            link.setAttribute('aria-label', item.title + ' (' + labels.newTab + ')');
            const notice = document.createElement('span'); notice.textContent = ' (' + labels.newTab + ')'; row.append(notice);
            const reportOpen = () => {
              if (offer && currentOffer === offer) feedback(offer, 'opened', Number(item.id)).catch(() => { /* Navigation remains available if feedback cannot be saved. */ });
            };
            link.addEventListener('click', reportOpen); link.addEventListener('auxclick', (event) => { if (event.button === 1) reportOpen(); });
            if (Number.isFinite(item.score)) {
              const score = document.createElement('p');
              score.textContent = labels.score + ': ' + new Intl.NumberFormat(document.documentElement.lang || undefined, { style: 'percent', maximumFractionDigits: 0 }).format(Math.max(0, Math.min(1, item.score)));
              row.append(score);
            }
            if (item.solved) { const label = document.createElement('strong'); label.textContent = ' ' + config.solved; row.append(label); }
            if (item.solution_preview) { const preview = document.createElement('p'); preview.textContent = item.solution_preview; row.append(preview); }
            list.append(row);
          }
          dismiss.hidden = !offer || !data.items.length; scoreHelp.hidden = !data.items.length;
          status.textContent = data.items.length ? config.matches : config.empty;
          if (offer && data.items.length && !document.hidden) feedback(offer, 'viewed').catch(() => { /* Aggregate acknowledgement is optional. */ });
        } catch (error) { if (error.name !== 'AbortError' && current === generation) status.textContent = config.unavailable; }
      }, 600);
    };
    title.addEventListener('input', update); body?.addEventListener('input', update); body?.addEventListener('fi:composer-change', update); forum?.addEventListener('change', update);
    document.querySelector('#bbp_topic_tags')?.addEventListener('input', update);
    for (const field of document.querySelectorAll('[name^="fi_fields["]')) { field.addEventListener('change', update); field.addEventListener('input', update); }
    const bindDuplicateEditor = (instance) => { if (instance?.id === 'bbp_topic_content') instance.on('change input undo redo', update); };
    bindDuplicateEditor(editor()); window.tinymce?.on('AddEditor', (event) => bindDuplicateEditor(event.editor));
    title.closest('form')?.addEventListener('submit', () => { clearTimeout(timer); ++generation; controller?.abort(); });
  }
  const bindDraft = (draftBody, draftTitle, target, type) => {
    if (!draftBody || !target || !config.loggedIn || !config.drafts) return;
    const formElement = draftBody.closest('form');
    const versionInput = formElement?.querySelector('[name="fi_draft_version"]');
    if (!versionInput) return;
    const anchor = draftBody.closest('.bbp-the-content-wrapper') || draftBody.closest('.wp-editor-wrap') || draftBody;
    const instance = () => window.tinymce?.get(draftBody.id);
    const value = () => { const editorInstance = instance(); return editorInstance?.initialized && !editorInstance.isHidden() ? editorInstance.getContent() : draftBody.value || ''; };
    const status = document.createElement('p');
    status.setAttribute('role', 'status'); status.setAttribute('aria-live', 'polite');
    status.dataset.fiDraftStatus = type; anchor.insertAdjacentElement('afterend', status);
    let timer; let generation = 0; let busy = false; let dirty = false; let blocked = false; let loaded = false;
    let saved = null; let version = String(versionInput.value || '0');
    const recover = document.createElement('button'); recover.type = 'button'; recover.textContent = config.recover; recover.hidden = true;
    status.insertAdjacentElement('afterend', recover);
    recover.addEventListener('click', () => {
      if (!saved) return;
      if (value().trim() || draftTitle?.value.trim()) { status.textContent = config.draftOccupied; return; }
      draftBody.value = saved.body || ''; instance()?.setContent(saved.body || '');
      if (draftTitle) draftTitle.value = saved.title || '';
      version = saved.version; versionInput.value = String(version); blocked = false; recover.hidden = true;
      status.textContent = config.recovered; draftBody.dispatchEvent(new Event('fi:composer-change'));
    });
    const refresh = async () => {
      clearTimeout(timer); const current = ++generation;
      loaded = false; blocked = false; saved = null; recover.hidden = true; status.textContent = '';
      // A different forum is a different draft; its baseline must be loaded before saving.
      if (!target.value) return;
      try {
        const draft = await request('draft?object=' + encodeURIComponent(target.value) + '&type=' + type);
        if (current !== generation) return;
        saved = draft; loaded = true;
        const serverVersion = String(draft?.version || '0');
        if (current === 1 && serverVersion !== version) {
          blocked = true; status.textContent = config.draftConflict;
        } else {
          version = serverVersion; versionInput.value = String(version);
        }
        recover.hidden = !draft;
        if (dirty && !blocked) autosave();
      } catch { if (current === generation) status.textContent = config.draftUnavailable; }
    };
    const autosave = () => {
      clearTimeout(timer); dirty = true;
      timer = setTimeout(async () => {
        if (busy || blocked || !loaded || !target.value || !(draftTitle?.value || value())) return;
        busy = true; dirty = false;
        const current = generation; const object = Number(target.value);
        try {
          const result = await action('draft', { object, type, version, title: draftTitle?.value || '', body: value() });
          if (current === generation) { version = result.version; versionInput.value = String(version); status.textContent = config.draftSaved; }
        } catch (error) {
          if (current === generation) {
            blocked = error.code === 'draft_conflict';
            status.textContent = blocked ? config.draftConflict : config.draftUnavailable;
            if (blocked) {
              try { const latest = await request('draft?object=' + object + '&type=' + type); if (current === generation) { saved = latest; recover.hidden = !saved; } } catch { /* Keep the unsaved editor text. */ }
            }
          }
        } finally { busy = false; if (dirty) autosave(); }
      }, 3000);
    };
    draftTitle?.addEventListener('input', autosave); draftBody.addEventListener('input', autosave);
    const bindEditor = (editorInstance) => {
      if (editorInstance?.id === draftBody.id) editorInstance.on('change input undo redo', autosave);
    };
    bindEditor(instance()); window.tinymce?.on('AddEditor', (event) => bindEditor(event.editor));
    target.addEventListener('change', refresh);
    formElement?.addEventListener('submit', () => { clearTimeout(timer); ++generation; loaded = false; });
    if (config.composerToolsModule) {
      import(config.composerToolsModule).then(({ mountComposerTools }) => mountComposerTools({
        config, action, target, type, form: formElement, anchor,
        textarea: draftBody, editor: instance,
        read: () => ({ title: draftTitle?.value || '', body: value() }),
        changed: autosave,
      })).catch(() => { /* Optional formatting/preview does not interrupt native posting. */ });
    }
    if (window.isSecureContext && window.crypto?.subtle && config.offlineDraftModule) {
      import(config.offlineDraftModule).then(({ mountOfflineDraft }) => mountOfflineDraft({
        config, action, target, type, form: formElement, anchor: recover,
        read: () => ({ title: draftTitle?.value || '', body: value() }),
        write: (draft) => {
          draftBody.value = draft.body; instance()?.setContent(draft.body);
          if (draftTitle) draftTitle.value = draft.title;
          draftBody.dispatchEvent(new Event('fi:composer-change')); autosave();
        },
      })).catch(() => { /* Optional browser copies never interrupt native posting. */ });
    }
    refresh();
  };
  if (!Number(document.querySelector('[name="bbp_topic_id"]')?.value || 0)) bindDraft(body, title, forum, 'topic');
  if (!Number(document.querySelector('[name="bbp_reply_id"]')?.value || 0)) {
    bindDraft(document.querySelector('#bbp_reply_content'), null, document.querySelector('[name="bbp_topic_id"]'), 'reply');
  }
  for (const conversation of document.querySelectorAll('[data-fi-conversation]')) {
    let cursor = Math.max(0, ...Array.from(conversation.querySelectorAll('[data-fi-message]'), (node) => Number(node.dataset.fiMessage)));
    let timer; let active = false;
    const poll = async () => {
      if (!conversation.open || document.hidden || active) return;
      active = true;
      try {
        const data = await request('chat?conversation=' + encodeURIComponent(conversation.dataset.fiConversation) + '&after=' + cursor);
        for (const message of data.items) {
          if (message.id <= cursor) continue;
          const node = document.createElement('p'); node.dataset.fiMessage = String(message.id); node.textContent = message.text;
          const form = conversation.querySelector('form'); if (form) form.before(node); else conversation.append(node);
          cursor = Math.max(cursor, message.id);
        }
      } catch { /* Retry on the next user-visible poll without exposing server details. */ }
      finally { active = false; }
    };
    conversation.addEventListener('toggle', () => {
      window.clearInterval(timer);
      if (conversation.open) { poll(); timer = window.setInterval(poll, 10000); }
    });
  }
  const workerOwned = (registration) => {
    const script = registration?.active?.scriptURL || registration?.waiting?.scriptURL || registration?.installing?.scriptURL;
    if (!script) return false;
    const url = new window.URL(script);
    const expected = new window.URL(config.worker, window.location.href);
    return url.origin === expected.origin && url.pathname === expected.pathname && url.searchParams.get('fi_worker') === '1';
  };
  const registerWorker = async () => {
    const existing = await navigator.serviceWorker.getRegistration(config.scope);
    if (existing && !workerOwned(existing)) throw new Error(config.workerConflict);
    return navigator.serviceWorker.register(config.worker, { scope: config.scope });
  };
  if (config.pwa && 'serviceWorker' in navigator && window.isSecureContext) {
    registerWorker().catch(() => { /* Existing worker remains managed by its owner. */ });
  }
  for (const button of document.querySelectorAll('[data-fi-unsubscribe]')) {
    button.addEventListener('click', async () => {
      const status = button.parentElement.querySelector('[role="status"]');
      try {
        const registration = await navigator.serviceWorker.getRegistration(config.scope);
        if (registration && !workerOwned(registration)) { status.textContent = config.workerConflict; return; }
        const subscription = await registration?.pushManager.getSubscription();
        if (subscription) { await action('push_unsubscribe', { endpoint: subscription.endpoint }); await subscription.unsubscribe(); }
        status.textContent = config.pushDisabled;
      } catch { status.textContent = config.unavailable; }
    });
  }
  for (const button of document.querySelectorAll('[data-fi-push]')) {
    button.addEventListener('click', async () => {
      const status = button.parentElement.querySelector('[role="status"]');
      if (!('serviceWorker' in navigator) || !('PushManager' in window) || !config.vapid) { status.textContent = config.unavailable; return; }
      try {
        const permission = await Notification.requestPermission();
        if (permission !== 'granted') { status.textContent = config.pushDenied; return; }
        const registration = await registerWorker();
        await navigator.serviceWorker.ready;
        const raw = atob(config.vapid.replace(/-/g, '+').replace(/_/g, '/'));
        const key = Uint8Array.from(raw, (character) => character.charCodeAt(0));
        const subscription = await registration.pushManager.subscribe({ userVisibleOnly: true, applicationServerKey: key });
        await action('push_subscribe', { subscription: subscription.toJSON() });
        status.textContent = config.pushEnabled;
      } catch (error) { status.textContent = error.message === config.workerConflict ? config.workerConflict : config.unavailable; }
    });
  }
})();
