/**
 * GSS AI Image - the panel that opens from an image field.
 *
 * Adapters (native.js, flexi.js) tell the panel where image fields are, what the
 * article says, and how to put a finished image into a field.
 *
 * @copyright  (C) 2026 GSS
 * @license    GNU General Public License version 2 or later; see LICENSE
 */
(function () {
  'use strict';

  if (window.GssAiImage) {
    return;
  }

  const options = (window.Joomla && Joomla.getOptions && Joomla.getOptions('plg_system_gssaiimage')) || {};
  const strings = options.strings || {};
  const adapters = [];
  const states = new WeakMap();
  const everyState = new Set();
  const SPARK = '<svg class="gssai-spark" viewBox="0 0 24 24" aria-hidden="true" focusable="false">'
    + '<path d="M12 2c.6 5.5 2.5 8.4 10 10-7.5 1.6-9.4 4.5-10 10-.6-5.5-2.5-8.4-10-10 7.5-1.6 9.4-4.5 10-10z" fill="currentColor"/></svg>';

  let panel = null;
  let ui = {};
  let active = null;
  let ticker = null;

  /* ---------------------------------------------------------------- helpers */

  function t(key) {
    let text = strings[key] || key;

    for (let i = 1; i < arguments.length; i += 1) {
      text = text.replace('%s', String(arguments[i]));
    }

    return text;
  }

  function h(tag, attrs, children) {
    const el = document.createElement(tag);

    Object.keys(attrs || {}).forEach((name) => {
      const value = attrs[name];

      if (value === false || value === null || value === undefined) {
        return;
      }

      if (name === 'class') {
        el.className = value;
      } else if (name === 'text') {
        el.textContent = value;
      } else if (name === 'html') {
        el.innerHTML = value;
      } else if (name.slice(0, 2) === 'on') {
        el.addEventListener(name.slice(2), value);
      } else {
        el.setAttribute(name, value === true ? '' : value);
      }
    });

    [].concat(children || []).forEach((child) => {
      if (child) {
        el.appendChild(typeof child === 'string' ? document.createTextNode(child) : child);
      }
    });

    return el;
  }

  function clean(text) {
    return String(text || '').replace(/\s+/g, ' ').trim();
  }

  const util = {
    clean,

    /** Text of the first editor or field that exists, by element id. */
    editorValue(ids) {
      const editors = (window.Joomla && Joomla.editors && Joomla.editors.instances) || {};

      for (let i = 0; i < ids.length; i += 1) {
        const id = ids[i];

        try {
          if (window.JoomlaEditor && typeof window.JoomlaEditor.get === 'function' && window.JoomlaEditor.get(id)) {
            return String(window.JoomlaEditor.get(id).getValue() || '');
          }

          if (editors[id] && typeof editors[id].getValue === 'function') {
            return String(editors[id].getValue() || '');
          }
        } catch (e) {
          // fall through to the plain field
        }

        const el = document.getElementById(id);

        if (el && 'value' in el && el.value) {
          return String(el.value);
        }
      }

      return '';
    },

    /** What a person has chosen or typed inside a part of the form, as readable text. */
    readValues(root) {
      const found = [];

      if (!root) {
        return found;
      }

      root.querySelectorAll('select').forEach((select) => {
        Array.from(select.selectedOptions || []).forEach((option) => {
          if (option.value !== '') {
            found.push(clean(option.textContent).replace(/^[-\s.|_]+/, ''));
          }
        });
      });

      root.querySelectorAll('input[type="checkbox"]:checked, input[type="radio"]:checked').forEach((input) => {
        const label = (input.id && root.querySelector('label[for="' + input.id.replace(/"/g, '') + '"]')) || input.closest('label');
        found.push(clean(input.getAttribute('data-tagname') || (label ? label.textContent : input.value)));
      });

      root.querySelectorAll('input[type="text"], input:not([type]), textarea').forEach((input) => {
        if (input.offsetParent !== null && !input.readOnly && !input.closest('.gssai-panel')) {
          found.push(clean(input.value).slice(0, 400));
        }
      });

      return found.filter((value, index) => value && found.indexOf(value) === index);
    },

    /** Name of an image field as used in the plugin settings: image_intro, or the name of a custom field. */
    mediaFieldKey(el) {
      const input = el.querySelector('input[name]');
      const name = input ? input.getAttribute('name') || '' : '';
      const custom = /\[com_fields\]\[([^\]]+)\]/.exec(name);
      const parts = (name.match(/\[([^\]]+)\]/g) || []).map((part) => part.slice(1, -1));

      return (custom ? custom[1] : (parts.pop() || '')).replace(/[^A-Za-z0-9_-]/g, '');
    },

    fieldValue(selector) {
      const el = document.querySelector(selector);

      return el && 'value' in el ? String(el.value || '') : '';
    },
  };

  function stateOf(adapter, target) {
    let state = states.get(target.host);

    if (!state) {
      state = {
        adapter,
        target,
        versions: [],
        current: -1,
        requests: [],
        working: false,
        applying: false,
        startedAt: 0,
        error: '',
        notice: '',
        plan: null,
        planKey: null,
        touched: {},
        form: {
          provider: options.defaultProvider || 'openai',
          ratio: (options.defaults && options.defaults.ratio) || '16:9',
          maxWidth: (options.defaults && options.defaults.maxWidth) || 1200,
          layoutMode: (options.defaults && options.defaults.layoutMode) || 'auto',
          textMode: (options.defaults && options.defaults.textMode) || 'none',
          style: options.defaultStyle || 'builtin',
          mode: 'revise',
          text: '',
        },
        applied: null,
        appliedPath: '',
        refs: [],
      };
      states.set(target.host, state);
      everyState.add(state);
    }

    state.target = target;

    return state;
  }

  /* -------------------------------------------------------------- transport */

  function post(plugin, action, fields) {
    const body = new FormData();

    body.append('action', action);
    body.append(options.tokenName || 'token', '1');

    Object.keys(fields || {}).forEach((name) => {
      const value = fields[name];

      if (Array.isArray(value)) {
        value.forEach((item) => {
          if (item instanceof Blob) {
            body.append(name + '[]', item, item.name || 'reference.jpg');
          } else {
            body.append(name + '[]', item);
          }
        });
      } else if (value !== undefined && value !== null) {
        body.append(name, value);
      }
    });

    return fetch((options.ajaxUrl || 'index.php?option=com_ajax&group=system&format=json') + '&plugin=' + encodeURIComponent(plugin), {
      method: 'POST',
      body,
      credentials: 'same-origin',
      headers: { 'X-Requested-With': 'XMLHttpRequest' },
    }).then((response) => response.text().then((text) => {
      let json = null;

      try {
        json = JSON.parse(text);
      } catch (e) {
        json = null;
      }

      const data = json && Array.isArray(json.data) ? json.data[0] : null;

      if (data && data.ok) {
        return data;
      }

      if (data && data.error) {
        throw new Error(data.error);
      }

      if ([502, 503, 504, 524].indexOf(response.status) !== -1) {
        throw new Error(t('ERROR_TIMEOUT'));
      }

      if (response.status === 401 || response.status === 403) {
        throw new Error(t('ERROR_SESSION'));
      }

      throw new Error((json && json.message) || t('ERROR_GENERIC'));
    }), () => {
      throw new Error(t('ERROR_TIMEOUT'));
    });
  }

  /* ------------------------------------------------------- reference images */

  const maxRefs = options.allowUserReferences ? (parseInt(options.maxUserReferences, 10) || 3) : 0;

  /** Redraw a picked file as a JPEG of modest size, so uploads stay small whatever the editor picks. */
  function shrink(file) {
    return new Promise((resolve, reject) => {
      const url = URL.createObjectURL(file);
      const image = new Image();

      image.onload = () => {
        const scale = Math.min(1, 1536 / Math.max(image.naturalWidth, image.naturalHeight));
        const canvas = document.createElement('canvas');

        canvas.width = Math.max(1, Math.round(image.naturalWidth * scale));
        canvas.height = Math.max(1, Math.round(image.naturalHeight * scale));

        const pen = canvas.getContext('2d');

        pen.fillStyle = '#fff';
        pen.fillRect(0, 0, canvas.width, canvas.height);
        pen.drawImage(image, 0, 0, canvas.width, canvas.height);
        URL.revokeObjectURL(url);
        canvas.toBlob((blob) => (blob ? resolve(blob) : reject(new Error('empty'))), 'image/jpeg', 0.9);
      };

      image.onerror = () => {
        URL.revokeObjectURL(url);
        reject(new Error('unreadable'));
      };

      image.src = url;
    });
  }

  function addRefs(files) {
    const state = active;
    const picked = Array.from(files || []).filter((file) => file && /^image\//.test(file.type || ''));

    if (!state || !maxRefs || state.working || state.applying) {
      return;
    }

    if (!picked.length) {
      if (files && files.length) {
        state.error = t('REFS_BAD');
        render();
      }

      return;
    }

    const room = maxRefs - state.refs.length;

    state.error = picked.length > room ? t('REFS_FULL', maxRefs) : '';

    // Hold the places now, so files picked in quick succession cannot pass the limit together
    const places = picked.slice(0, Math.max(0, room)).map((file) => {
      const slot = { file: null, url: '', name: file.name || '' };

      state.refs.push(slot);

      return shrink(file).then((blob) => {
        slot.file = new File([blob], 'reference.jpg', { type: 'image/jpeg' });
        slot.url = URL.createObjectURL(blob);
      }).catch(() => {
        if (state.refs.indexOf(slot) !== -1) {
          state.refs.splice(state.refs.indexOf(slot), 1);
        }

        state.error = t('REFS_BAD');
      });
    });

    render();

    Promise.all(places).then(() => {
      if (active === state) {
        render();
        place();
      }
    });
  }

  function clearRefs(state) {
    state.refs.forEach((ref) => ref.url && URL.revokeObjectURL(ref.url));
    state.refs = [];

    if (ui.refInput) {
      ui.refInput.value = '';
    }
  }

  /* ------------------------------------------------------------------ panel */

  function select(name, entries) {
    return h('select', { class: 'gssai-input', name }, entries.map((entry) => h('option', {
      value: entry[0],
      text: entry[1],
      disabled: entry[2] === false,
    })));
  }

  function field(label, control) {
    return h('label', { class: 'gssai-field' }, [h('span', { class: 'gssai-label', text: label }), control]);
  }

  function build() {
    const providers = (options.providers || []).map((provider) => [
      provider.id,
      provider.label + (provider.ready ? '' : ' (' + t('NO_PROVIDER') + ')'),
      provider.ready,
    ]);

    ui.title = h('h2', { class: 'gssai-title', id: 'gssai-title', html: SPARK + '<span></span>' });
    ui.title.lastChild.textContent = t('PANEL_TITLE');
    ui.close = h('button', { type: 'button', class: 'gssai-close', 'aria-label': t('CLOSE'), title: t('CLOSE'), html: '&times;', onclick: () => close() });

    ui.source = h('div', { class: 'gssai-facts' });
    ui.rule = h('div', { class: 'gssai-facts' });
    ui.fieldPrompt = h('p', { class: 'gssai-ruleprompt' });
    ui.rulePrompt = h('p', { class: 'gssai-ruleprompt' });

    ui.provider = select('provider', providers);
    ui.style = select('style', (options.styles || []).map((style) => [style.id, style.name]));
    ui.layout = select('layoutMode', [
      ['auto', t('LAYOUT_AUTO')], ['light', t('LAYOUT_LIGHT')], ['collage', t('LAYOUT_COLLAGE')],
      ['dark', t('LAYOUT_DARK')], ['geometric', t('LAYOUT_GEOMETRIC')],
    ]);
    ui.textMode = select('textMode', [['none', t('TEXT_NONE')], ['title', t('TEXT_TITLE')]]);
    ui.ratio = h('input', { class: 'gssai-input', name: 'ratio', type: 'text', list: 'gssai-ratios', autocomplete: 'off', inputmode: 'text' });
    ui.maxWidth = h('input', { class: 'gssai-input', name: 'maxWidth', type: 'number', min: '200', max: '4096', step: '10' });

    ui.options = h('details', { class: 'gssai-options' }, [
      h('summary', {}, [h('span', { text: t('OPTIONS') }), ui.optionsSummary = h('span', { class: 'gssai-options-now' })]),
      h('div', { class: 'gssai-grid' }, [
        ui.styleField = field(t('STYLE'), ui.style),
        ui.providerField = field(t('PROVIDER'), ui.provider),
        field(t('LAYOUT'), ui.layout),
        ui.ratioField = field(t('RATIO'), ui.ratio),
        ui.maxWidthField = field(t('MAX_WIDTH'), ui.maxWidth),
        field(t('TEXT_MODE'), ui.textMode),
      ]),
      h('datalist', { id: 'gssai-ratios' }, (options.ratios || []).map((ratio) => h('option', { value: ratio }))),
    ]);

    ui.image = h('img', { class: 'gssai-image', alt: '' });
    ui.stageText = h('p', { class: 'gssai-stage-text' });
    ui.stageTime = h('p', { class: 'gssai-stage-time' });
    ui.stage = h('div', { class: 'gssai-stage' }, [
      ui.image,
      h('div', { class: 'gssai-stage-cover' }, [h('span', { class: 'gssai-orbit', html: SPARK }), ui.stageText, ui.stageTime]),
    ]);
    ui.meta = h('p', { class: 'gssai-meta' });
    ui.versions = h('div', { class: 'gssai-versions', role: 'group', 'aria-label': t('VERSIONS') });

    ui.requests = h('ul', { class: 'gssai-requests', 'aria-label': t('HISTORY') });
    ui.textLabel = h('span', { class: 'gssai-label' });
    ui.text = h('textarea', { class: 'gssai-input gssai-text', name: 'text', rows: '3', maxlength: '1000' });
    ui.modeRevise = h('input', { type: 'radio', name: 'gssai-mode', value: 'revise' });
    ui.modeFresh = h('input', { type: 'radio', name: 'gssai-mode', value: 'fresh' });
    ui.modes = h('div', { class: 'gssai-modes', role: 'radiogroup' }, [
      h('label', { class: 'gssai-mode' }, [ui.modeRevise, h('span', {}, [h('strong', { text: t('MODE_REVISE') }), h('small', { text: t('MODE_REVISE_HINT') })])]),
      h('label', { class: 'gssai-mode' }, [ui.modeFresh, h('span', {}, [h('strong', { text: t('MODE_FRESH') }), h('small', { text: t('MODE_FRESH_HINT') })])]),
    ]);

    ui.refList = h('div', { class: 'gssai-refs-list' });
    ui.refInput = h('input', { type: 'file', accept: 'image/jpeg,image/png,image/webp', multiple: true, hidden: true, tabindex: '-1', 'aria-hidden': 'true' });
    ui.refAdd = h('button', {
      type: 'button', class: 'gssai-refs-add', onclick: () => ui.refInput.click(),
    }, [h('span', { class: 'gssai-refs-plus', 'aria-hidden': 'true', text: '+' }), h('span', { text: t('REFS_ADD') })]);
    ui.refs = h('div', { class: 'gssai-refs' }, [
      h('span', { class: 'gssai-label', text: t('REFS_LABEL', maxRefs) }),
      h('div', { class: 'gssai-refs-row' }, [ui.refList, ui.refAdd]),
      h('p', { class: 'gssai-refs-hint', text: t('REFS_HINT') }),
      ui.refInput,
    ]);

    ui.refInput.addEventListener('change', () => {
      addRefs(ui.refInput.files);
      ui.refInput.value = '';
    });

    ui.alert = h('div', { class: 'gssai-alert', role: 'alert' });
    ui.notice = h('div', { class: 'gssai-notice', role: 'status' });
    ui.generate = h('button', { type: 'button', class: 'gssai-btn gssai-btn-primary', onclick: () => generate() });
    ui.apply = h('button', { type: 'button', class: 'gssai-btn gssai-btn-secondary', text: t('APPLY'), onclick: () => apply() });
    ui.prompt = h('pre', { class: 'gssai-prompt' });
    ui.promptBox = h('details', { class: 'gssai-promptbox' }, [h('summary', { text: t('SHOW_PROMPT') }), ui.prompt]);

    panel = h('section', {
      class: 'gssai-panel', role: 'dialog', 'aria-labelledby': 'gssai-title', tabindex: '-1', hidden: true,
    }, [
      h('span', { class: 'gssai-arrow', 'aria-hidden': 'true' }),
      h('header', { class: 'gssai-head' }, [
        h('div', {}, [ui.title, ui.subtitle = h('p', { class: 'gssai-subtitle', text: t('PANEL_SUBTITLE', options.styleName || t('STYLE_BUILTIN')) })]),
        ui.close,
      ]),
      ui.body = h('div', { class: 'gssai-body' }, [
        ui.source,
        ui.rule,
        ui.fieldPrompt,
        ui.rulePrompt,
        ui.options,
        ui.stage,
        ui.meta,
        ui.versions,
        ui.requests,
        h('label', { class: 'gssai-field gssai-field-text' }, [ui.textLabel, ui.text]),
        ui.refs,
        ui.modes,
        ui.alert,
        ui.notice,
        ui.promptBox,
      ]),
      h('footer', { class: 'gssai-foot' }, [ui.apply, ui.generate]),
    ]);

    // Form controls write straight into the state of the field the panel is open for
    [['style', ui.style], ['provider', ui.provider], ['layoutMode', ui.layout], ['textMode', ui.textMode], ['ratio', ui.ratio], ['maxWidth', ui.maxWidth]].forEach((pair) => {
      pair[1].addEventListener('change', () => {
        if (active) {
          active.form[pair[0]] = pair[0] === 'maxWidth' ? parseInt(pair[1].value, 10) || 0 : pair[1].value;
          active.touched[pair[0]] = true;
          render();
        }
      });
    });

    ui.text.addEventListener('input', () => {
      if (active) {
        active.form.text = ui.text.value;
      }
    });

    ui.text.addEventListener('keydown', (event) => {
      if (event.key === 'Enter' && (event.metaKey || event.ctrlKey)) {
        event.preventDefault();
        generate();
      }
    });

    [ui.modeRevise, ui.modeFresh].forEach((radio) => radio.addEventListener('change', () => {
      if (active && radio.checked) {
        active.form.mode = radio.value;
        render();
      }
    }));

    // Reference images can also be dropped on the panel or pasted into it
    panel.addEventListener('dragover', (event) => {
      if (maxRefs && event.dataTransfer && Array.from(event.dataTransfer.types || []).indexOf('Files') !== -1) {
        event.preventDefault();
        panel.classList.add('is-dropping');
      }
    });
    panel.addEventListener('dragleave', (event) => {
      if (!panel.contains(event.relatedTarget)) {
        panel.classList.remove('is-dropping');
      }
    });
    panel.addEventListener('drop', (event) => {
      panel.classList.remove('is-dropping');

      if (maxRefs && event.dataTransfer && event.dataTransfer.files.length) {
        event.preventDefault();
        addRefs(event.dataTransfer.files);
      }
    });
    panel.addEventListener('paste', (event) => {
      const files = event.clipboardData ? event.clipboardData.files : [];

      if (maxRefs && files.length) {
        event.preventDefault();
        addRefs(files);
      }
    });

    // Keep key presses inside the panel away from the form underneath (Enter would submit it)
    panel.addEventListener('keydown', (event) => {
      if (event.key === 'Escape') {
        event.stopPropagation();
        close();
      } else if (event.key === 'Enter' && event.target.tagName === 'INPUT') {
        event.preventDefault();
      }
    });

    document.body.appendChild(panel);

    document.addEventListener('mousedown', (event) => {
      if (active && !panel.hidden && !panel.contains(event.target) && !event.target.closest('.gssai-trigger')) {
        close(false);
      }
    });
    window.addEventListener('resize', place);
    window.addEventListener('scroll', place, true);
  }

  function facts(container, label, items) {
    container.textContent = '';
    container.appendChild(h('span', { class: 'gssai-facts-label', text: label }));
    items.filter(Boolean).forEach((item) => container.appendChild(h('span', { class: 'gssai-chip' + (item.muted ? ' is-muted' : ''), text: item.text, title: item.title || null })));
  }

  function render() {
    const state = active;

    if (!state || !panel) {
      return;
    }

    const form = state.form;
    const version = state.versions[state.current] || null;
    const busy = state.working || state.applying;
    const ready = (options.providers || []).some((provider) => provider.ready);

    // What the image will be made from
    const context = state.context || {};
    const words = clean(String(context.content || '').replace(/<[^>]*>/g, ' ')).length;
    const labels = (context.tags || []).concat(Object.values(context.extra || {}).join('、').split('、'))
      .filter((label, index, all) => label && all.indexOf(label) === index);

    facts(ui.source, t('SOURCE_SUMMARY'), [
      { text: t('SOURCE_TITLE') + ' · ' + (clean(context.title) || t('SOURCE_NONE')), title: clean(context.title), muted: !clean(context.title) },
      { text: t('SOURCE_CONTENT') + ' · ' + (words ? t('CHARS', words.toLocaleString()) : t('SOURCE_NONE')), muted: !words },
      { text: t('SOURCE_TAGS') + ' · ' + (labels.join('、') || t('SOURCE_NONE')), title: labels.join('、'), muted: !labels.length },
    ]);

    // Which settings apply: defaults, then the image field, then the category, then what the editor changed here
    const rule = (state.plan && state.plan.rule) || {};
    const sources = rule.sources || {};
    const fieldKey = state.target.key || rule.field_key || '';
    const from = (name, formName) => (state.touched[formName] ? t('FROM_YOU') : t('FROM_' + String(sources[name] || 'default').toUpperCase()));
    let categoryName = rule.category_title || rule.rule_category || '';

    if (rule.matched && rule.rule_category && rule.rule_category !== rule.category_title) {
      categoryName += ' · ' + t('RULE_FROM', rule.rule_category);
    }

    facts(ui.rule, t('APPLIES'), [
      fieldKey ? { text: t('FIELD') + ' · ' + fieldKey, muted: !rule.field_matched, title: rule.field_matched ? t('FIELD_HINT') : t('FIELD_NONE') } : null,
      categoryName || rule.matched ? { text: t('CATEGORY') + ' · ' + categoryName, muted: !rule.matched, title: rule.matched ? null : t('CATEGORY_NONE') } : null,
      { text: form.ratio, title: from('ratio', 'ratio'), muted: false },
      { text: form.maxWidth + ' px', title: from('max_width', 'maxWidth'), muted: false },
    ]);

    ui.fieldPrompt.hidden = !rule.field_prompt;
    ui.fieldPrompt.textContent = rule.field_prompt ? t('FIELD_PROMPT') + '：' + rule.field_prompt : '';
    ui.rulePrompt.hidden = !rule.category_prompt;
    ui.rulePrompt.textContent = rule.category_prompt ? t('RULE_PROMPT') + '：' + rule.category_prompt : '';

    // Options
    ui.provider.value = form.provider;
    ui.style.value = form.style;

    // Editors choose a style only where they may, and only when there is more than one
    const styles = options.styles || [];
    const style = styles.filter((item) => item.id === form.style)[0] || styles[0] || { name: options.styleName || t('STYLE_BUILTIN') };

    ui.styleField.hidden = !options.allowStyleSwitch || styles.length < 2;
    ui.subtitle.textContent = t('PANEL_SUBTITLE', options.allowStyleSwitch ? style.name : (options.styleName || t('STYLE_BUILTIN')));
    ui.layout.value = form.layoutMode;
    ui.textMode.value = form.textMode;

    if (document.activeElement !== ui.ratio) {
      ui.ratio.value = form.ratio;
    }

    if (document.activeElement !== ui.maxWidth) {
      ui.maxWidth.value = form.maxWidth;
    }

    ui.providerField.hidden = !options.allowProviderSwitch;
    ui.ratioField.hidden = !options.allowSizeOverride;
    ui.maxWidthField.hidden = !options.allowSizeOverride;

    const provider = (options.providers || []).filter((item) => item.id === form.provider)[0];

    ui.optionsSummary.textContent = [
      options.allowStyleSwitch && styles.length > 1 ? style.name : '',
      provider ? provider.label : '',
      ui.layout.options[ui.layout.selectedIndex] ? ui.layout.options[ui.layout.selectedIndex].textContent : '',
      ui.textMode.options[ui.textMode.selectedIndex] ? ui.textMode.options[ui.textMode.selectedIndex].textContent : '',
    ].filter(Boolean).join(' · ');

    [ui.style, ui.provider, ui.layout, ui.textMode, ui.ratio, ui.maxWidth, ui.text, ui.modeRevise, ui.modeFresh].forEach((el) => { el.disabled = busy; });

    // Stage
    const parts = String(form.ratio).replace('x', ':').split(':');
    const ratio = parts.length === 2 && parseFloat(parts[0]) > 0 && parseFloat(parts[1]) > 0 ? parseFloat(parts[0]) / parseFloat(parts[1]) : 16 / 9;

    ui.stage.style.setProperty('--gssai-ratio', String(version ? version.width / version.height : ratio));
    ui.stage.classList.toggle('is-working', state.working);
    ui.stage.classList.toggle('is-empty', !version && !state.working);

    if (version) {
      if (ui.image.getAttribute('src') !== version.url) {
        ui.image.src = version.url;
      }

      ui.image.alt = t('VERSION', state.current + 1);
      ui.image.hidden = false;
    } else {
      ui.image.hidden = true;
      ui.image.removeAttribute('src');
    }

    if (!state.working) {
      ui.stageText.textContent = version ? '' : t('EMPTY_HINT');
      ui.stageTime.textContent = '';
    }

    ui.meta.hidden = !version;
    ui.meta.textContent = version
      ? [
        t('VERSION', state.current + 1), version.width + ' × ' + version.height + ' px', version.ratio, version.model,
        version.style || '',
        Math.max(1, Math.round(version.bytes / 1024)) + ' KB',
        version.editor_references ? t('REFS_USED', version.editor_references) : '',
      ].filter(Boolean).join(' · ')
      : '';

    // Versions
    ui.versions.hidden = state.versions.length < 2;
    ui.versions.textContent = '';
    state.versions.forEach((item, index) => {
      ui.versions.appendChild(h('button', {
        type: 'button',
        class: 'gssai-version' + (index === state.current ? ' is-current' : '') + (item.draft === state.applied ? ' is-applied' : ''),
        title: t('VERSION', index + 1) + (item.request ? '：' + item.request : ''),
        'aria-pressed': index === state.current ? 'true' : 'false',
        disabled: busy,
        onclick: () => { state.current = index; state.notice = ''; render(); },
      }, [h('img', { src: item.url, alt: t('VERSION', index + 1) }), h('span', { text: String(index + 1) })]));
    });

    // Requests made so far. They feed the next new composition, so they can be taken back.
    ui.requests.hidden = !state.requests.length;
    ui.requests.textContent = '';
    state.requests.forEach((request, index) => {
      ui.requests.appendChild(h('li', {}, [
        h('span', { text: request }),
        h('button', {
          type: 'button', 'aria-label': t('CLOSE'), html: '&times;', disabled: busy,
          onclick: () => { state.requests.splice(index, 1); render(); },
        }),
      ]));
    });

    // Request box
    ui.textLabel.textContent = version ? t('REVISE_LABEL') : t('REQUEST_LABEL');
    ui.text.placeholder = version ? t('REVISE_PLACEHOLDER') : t('REQUEST_PLACEHOLDER');

    if (ui.text.value !== form.text) {
      ui.text.value = form.text;
    }

    // Reference images of the editor, kept only while the panel is open
    ui.refs.hidden = !maxRefs;
    ui.refList.textContent = '';
    state.refs.forEach((ref, index) => {
      ui.refList.appendChild(h('span', { class: 'gssai-ref' + (ref.url ? '' : ' is-loading') }, [
        ref.url ? h('img', { src: ref.url, alt: t('REFS_ITEM', index + 1), title: ref.name || null }) : null,
        h('button', {
          type: 'button', 'aria-label': t('REFS_REMOVE'), title: t('REFS_REMOVE'), html: '&times;', disabled: busy || !ref.url,
          onclick: () => {
            if (ref.url) {
              URL.revokeObjectURL(ref.url);
            }

            state.refs.splice(state.refs.indexOf(ref), 1);
            state.error = '';
            render();
            place();
          },
        }),
      ]));
    });
    ui.refAdd.hidden = state.refs.length >= maxRefs;
    ui.refAdd.disabled = busy;

    ui.modes.hidden = !version;
    ui.modeRevise.checked = form.mode === 'revise';
    ui.modeFresh.checked = form.mode === 'fresh';

    // Messages
    const problem = state.error || (!ready ? t('NOT_CONFIGURED') : '');

    ui.alert.hidden = !problem;
    ui.alert.textContent = problem;
    ui.notice.hidden = !state.notice && !state.working;
    ui.notice.textContent = state.working ? t('KEEP_OPEN') : state.notice;

    // Prompt
    ui.promptBox.hidden = !version;
    ui.prompt.textContent = version ? version.prompt : '';

    // Buttons
    ui.generate.disabled = busy || !ready;
    ui.generate.innerHTML = SPARK + '<span></span>';
    ui.generate.lastChild.textContent = state.working ? t('WORKING') + '…' : (version ? t('REGENERATE') : t('GENERATE'));
    ui.apply.hidden = !version;
    ui.apply.disabled = busy;
    ui.apply.textContent = state.applying ? t('APPLYING') : t('APPLY');

    markTrigger(state);
  }

  function markTrigger(state) {
    const trigger = state.target.host.__gssaiTrigger;

    if (trigger) {
      trigger.classList.toggle('is-working', state.working);
      trigger.setAttribute('aria-expanded', active === state && panel && !panel.hidden ? 'true' : 'false');
    }
  }

  function tick() {
    if (!active || !active.working) {
      return;
    }

    const seconds = Math.round((Date.now() - active.startedAt) / 1000);
    const drawing = seconds >= 8 || (active.versions.length && active.form.mode === 'revise');

    ui.stageText.textContent = seconds > 75 ? t('WORKING_SLOW') : (drawing ? t('WORKING_DRAW') : t('WORKING_CONCEPT'));
    ui.stageTime.textContent = t('SECONDS', seconds);
  }

  function place() {
    if (!active || !panel || panel.hidden) {
      return;
    }

    const trigger = active.target.host.__gssaiTrigger;

    if (!trigger || !document.body.contains(trigger)) {
      close(false);

      return;
    }

    const gap = 12;
    const edge = 12;
    const rect = trigger.getBoundingClientRect();
    const vw = document.documentElement.clientWidth;
    const vh = window.innerHeight;
    const width = Math.min(620, vw - edge * 2);
    const below = vh - rect.bottom - gap - edge;
    const above = rect.top - gap - edge;
    const scrolled = ui.body.scrollTop;

    // Measure the panel at its natural height, without the limit set last time
    panel.style.width = width + 'px';
    panel.style.maxHeight = 'none';

    const wanted = Math.min(panel.offsetHeight, vh - edge * 2);

    panel.classList.remove('is-above', 'is-below', 'is-floating');

    let top;
    let height;

    const room = Math.max(below, above);
    const cramped = room < wanted && (room < 440 || vh - edge * 2 - room > 160);

    if (vw < 700 || cramped) {
      // Not enough room next to the button: float in the middle of the window
      height = Math.min(wanted, vh - edge * 2);
      top = Math.max(edge, (vh - height) / 2);
      panel.classList.add('is-floating');
      panel.style.left = Math.max(edge, (vw - width) / 2) + 'px';
    } else {
      const side = below >= wanted || below >= above ? 'below' : 'above';

      height = Math.min(wanted, side === 'below' ? below : above);
      top = side === 'below' ? rect.bottom + gap : rect.top - gap - height;
      panel.classList.add('is-' + side);

      const left = Math.min(Math.max(edge, rect.left + rect.width / 2 - width * 0.3), vw - width - edge);

      panel.style.left = left + 'px';
      panel.style.setProperty('--gssai-arrow', Math.min(Math.max(24, rect.left + rect.width / 2 - left), width - 24) + 'px');
    }

    panel.style.top = top + 'px';
    panel.style.maxHeight = height + 'px';
    ui.body.scrollTop = scrolled;
  }

  /* ---------------------------------------------------------------- actions */

  function open(adapter, target) {
    if (!panel) {
      build();
    }

    const state = stateOf(adapter, target);

    if (active && active !== state) {
      markTrigger(active);
    }

    active = state;
    state.context = adapter.context(target) || {};
    state.error = '';

    panel.hidden = false;
    render();
    place();
    panel.focus({ preventScroll: true });

    if (!ticker) {
      ticker = window.setInterval(tick, 500);
    }

    loadPlan(state);
  }

  function close(returnFocus) {
    if (!panel || panel.hidden) {
      return;
    }

    const state = active;

    panel.hidden = true;
    panel.classList.remove('is-dropping');

    if (state) {
      // Reference images belong to one sitting: closing the panel lets go of them
      clearRefs(state);
      markTrigger(state);

      if (returnFocus !== false && state.target.host.__gssaiTrigger) {
        state.target.host.__gssaiTrigger.focus({ preventScroll: true });
      }
    }
  }

  function loadPlan(state) {
    const key = String(state.context.catid || 0) + '|' + (state.target.key || '');

    if (state.planKey === key && state.plan) {
      return;
    }

    state.planKey = key;

    post('gssaiimage', 'plan', { catid: state.context.catid || 0, field: state.target.key || '' }).then((plan) => {
      state.plan = plan;

      // The rule sets the starting point. What the editor already changed stays.
      [['provider', 'provider'], ['ratio', 'ratio'], ['maxWidth', 'max_width'], ['layoutMode', 'layout_mode'], ['textMode', 'text_mode']].forEach((pair) => {
        if (!state.touched[pair[0]] && plan[pair[1]]) {
          state.form[pair[0]] = plan[pair[1]];
        }
      });

      if (active === state) {
        render();
        place();
      }
    }).catch(() => {
      state.planKey = null;
    });
  }

  function generate() {
    const state = active;

    if (!state || state.working || state.applying) {
      return;
    }

    const form = state.form;
    const version = state.versions[state.current] || null;
    const text = clean(form.text);
    const revise = Boolean(version) && form.mode === 'revise';

    state.context = state.adapter.context(state.target) || {};
    state.error = '';
    state.notice = '';

    if (!clean(state.context.title) && !clean(String(state.context.content || '').replace(/<[^>]*>/g, ' '))) {
      state.error = t('NEED_TITLE');
      render();

      return;
    }

    if (revise && !text) {
      state.error = t('NEED_REQUEST');
      render();
      ui.text.focus();

      return;
    }

    if (state.refs.some((ref) => !ref.file)) {
      // A picked image is still being prepared. It takes a moment at most.
      window.setTimeout(generate, 150);

      return;
    }

    state.working = true;
    state.startedAt = Date.now();
    render();
    tick();
    place();

    post('gssaiimage', 'generate', {
      refs: state.refs.map((ref) => ref.file),
      context: state.adapter.name,
      title: state.context.title || '',
      content: state.context.content || '',
      catid: state.context.catid || 0,
      field: state.target.key || '',
      tags: state.context.tags || [],
      extra: JSON.stringify(state.context.extra || {}),
      // Only what the editor changed here is sent as a wish. The rest is decided by the layers of settings.
      style: state.touched.style ? form.style : '',
      provider: state.touched.provider ? form.provider : '',
      ratio: state.touched.ratio ? form.ratio : '',
      max_width: state.touched.maxWidth ? form.maxWidth : '',
      layout_mode: revise ? (version.layout_mode || form.layoutMode) : (state.touched.layoutMode ? form.layoutMode : ''),
      text_mode: state.touched.textMode ? form.textMode : '',
      instruction: text,
      instructions: revise ? [] : state.requests,
      base: revise ? version.draft : '',
    }).then((result) => {
      result.request = text;
      state.notice = result.notice || '';
      state.versions.push(result);
      state.current = state.versions.length - 1;

      if (text) {
        state.requests.push(text);
      }

      form.text = '';
    }).catch((error) => {
      state.error = error.message || t('ERROR_GENERIC');
    }).then(() => {
      state.working = false;
      markTrigger(state);

      if (active === state) {
        render();
        place();
      }
    });
  }

  function apply() {
    const state = active;
    const version = state && state.versions[state.current];

    if (!version || state.working || state.applying) {
      return;
    }

    state.applying = true;
    state.error = '';
    render();

    // Applying removes the editor's drafts on the server. Drafts shown in the panel of another
    // field on this page are still being chosen from, so they are named and kept.
    const keep = [];

    everyState.forEach((other) => {
      if (other !== state && document.body.contains(other.target.host)) {
        other.versions.forEach((item) => keep.push(item.draft));
      }
    });

    const api = {
      post: (plugin, action, fields) => post(plugin, action, action === 'apply' ? Object.assign({ keep }, fields) : fields),
      state,
      context: state.adapter.context(state.target) || {},
    };

    Promise.resolve()
      .then(() => state.adapter.apply(state.target, version, api))
      .then((applied) => {
        // The versions of this field are gone with their drafts. The next image starts from the article again.
        state.versions = [];
        state.current = -1;
        state.requests = [];
        state.form.text = '';
        state.form.mode = 'revise';
        state.applied = null;
        state.appliedPath = (applied && applied.path) || '';
        state.notice = t('APPLIED');
        state.applying = false;
        render();
        close();
      })
      .catch((error) => {
        state.applying = false;
        state.error = (error && error.message) || t('ERROR_GENERIC');
        render();
      });
  }

  /* --------------------------------------------------------------- triggers */

  function attach(adapter, target) {
    if (!target || !target.host || target.host.__gssaiTrigger) {
      return;
    }

    const trigger = h('button', {
      type: 'button',
      class: 'btn gssai-trigger',
      title: t('BUTTON_TITLE'),
      'aria-haspopup': 'dialog',
      'aria-expanded': 'false',
      html: SPARK + '<span></span>',
      onclick: (event) => {
        event.preventDefault();
        event.stopPropagation();

        if (panel && !panel.hidden && active && active.target.host === target.host) {
          close();
        } else {
          open(adapter, target);
        }
      },
    });

    trigger.lastChild.textContent = t('BUTTON');
    target.host.__gssaiTrigger = trigger;
    target.place(trigger);
  }

  function scan(root) {
    adapters.forEach((adapter) => {
      let targets = [];

      try {
        targets = adapter.scan(root || document) || [];
      } catch (e) {
        targets = [];
      }

      targets.forEach((target) => attach(adapter, target));
    });
  }

  let pending = null;

  function watch() {
    scan(document);

    // Repeatable fields and tabs add image fields after the page has loaded
    new MutationObserver((mutations) => {
      const grown = mutations.some((mutation) => Array.from(mutation.addedNodes).some((node) => node.nodeType === 1 && !node.closest('.gssai-panel')));

      if (grown && !pending) {
        pending = window.setTimeout(() => {
          pending = null;
          scan(document);
        }, 250);
      }
    }).observe(document.body, { childList: true, subtree: true });
  }

  window.GssAiImage = {
    options,
    util,
    t,
    post,
    register(adapter) {
      adapters.push(adapter);

      if (document.readyState !== 'loading') {
        scan(document);
      }
    },
    scan,
    close,
  };

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', watch);
  } else {
    watch();
  }
}());
