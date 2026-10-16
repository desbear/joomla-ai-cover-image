/**
 * GSS AI Image - style skills in the plugin settings.
 *
 * Lists the built-in style and the installed skills, lets an administrator choose the default one,
 * install a skill from the list of sources, upload a zip package, or delete a skill.
 *
 * @copyright  (C) 2026 GSS
 * @license    GNU General Public License version 2 or later; see LICENSE
 */
(function () {
  'use strict';

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

  function megabytes(bytes) {
    return Math.max(1, Math.floor(bytes / 1048576));
  }

  /** Only links to GitHub are shown as links. Everything else stays text. */
  function safeLink(url) {
    return /^https:\/\/github\.com\/[A-Za-z0-9_.\-\/]+$/.test(String(url || '')) ? url : null;
  }

  function setup(root) {
    let options;

    try {
      options = JSON.parse(root.getAttribute('data-gssai-skills') || '{}');
    } catch (e) {
      return;
    }

    const strings = options.strings || {};
    const input = root.querySelector('input[type="hidden"]');
    const list = root.querySelector('.gssai-skills-list');
    const upload = root.querySelector('.gssai-skills-upload');
    const message = root.querySelector('.gssai-skills-message');
    const saved = input.value;
    let skills = options.skills || [];
    let catalog = options.catalog || [];
    let busy = false;
    let installing = '';

    const t = (key, value) => String(strings[key] || key).replace('%s', value === undefined ? '' : String(value));

    function say(text, kind) {
      message.textContent = text || '';
      message.className = 'gssai-skills-message' + (text ? ' is-' + (kind || 'info') : '');
    }

    function choose(slug) {
      input.value = slug;
      input.dispatchEvent(new Event('change', { bubbles: true }));
      render();

      // The choice is part of the settings, so it counts once they are saved
      say(slug !== saved ? t('SAVE_REMINDER') : '', 'info');
    }

    function request(fields) {
      const body = new FormData();

      body.append(options.tokenName, '1');
      Object.keys(fields).forEach((name) => body.append(name, fields[name]));

      return fetch(options.ajaxUrl, { method: 'POST', body, credentials: 'same-origin', headers: { 'X-Requested-With': 'XMLHttpRequest' } })
        .then((response) => response.text().then((text) => {
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

          if (response.status === 413) {
            throw new Error(t('TOO_LARGE', megabytes(options.limit)));
          }

          // The plugin answers only while it is enabled
          throw new Error(json && Array.isArray(json.data) && !json.data.length ? t('NOT_ENABLED') : ((json && json.message) || t('ERROR')));
        }));
    }

    function took(data) {
      skills = data.skills || [];
      catalog = data.catalog || catalog;
    }

    function card(skill) {
      const slug = skill ? skill.slug : '';
      const chosen = input.value === slug;
      const images = skill ? skill.images : (options.builtin || []);
      const source = (skill && skill.source) || {};
      const id = input.id + '-' + (slug || 'builtin');
      const facts = skill
        ? [
          t('DOCUMENTS', skill.documents.length),
          images.length ? t('IMAGES', images.length) : t('NO_IMAGES'),
          source.repo ? t('SOURCE', source.repo) + (source.license ? ' · ' + source.license : '') : '',
          skill.uploaded ? t('UPLOADED', skill.uploaded.slice(0, 10)) : '',
          skill.package || '',
        ]
        : [images.length ? t('IMAGES', images.length) : ''];

      return h('div', { class: 'gssai-skill' + (chosen ? ' is-chosen' : '') }, [
        h('label', { class: 'gssai-skill-head', for: id }, [
          h('input', {
            type: 'radio', id, name: input.id + '-choice', value: slug, checked: chosen, disabled: busy, onchange: () => choose(slug),
          }),
          h('span', { class: 'gssai-skill-title' }, [
            h('strong', { text: skill ? skill.name : t('BUILTIN') }),
            chosen ? h('span', { class: 'gssai-skill-badge', text: t('IN_USE') }) : null,
          ]),
        ]),
        h('p', { class: 'gssai-skill-desc', text: skill ? skill.description : t('BUILTIN_DESC') }),
        h('p', { class: 'gssai-skill-facts', text: facts.filter(Boolean).join(' · ') }),
        images.length ? h('div', { class: 'gssai-skill-images' }, images.slice(0, 12).map((url) => h('img', { src: url, alt: '', loading: 'lazy' }))) : null,
        skill ? h('div', { class: 'gssai-skill-actions' }, [
          h('button', {
            type: 'button', class: 'btn btn-sm btn-outline-danger', disabled: busy, text: t('DELETE'),
            onclick: () => {
              if (window.confirm(t('DELETE_CONFIRM', skill.name))) {
                remove(skill);
              }
            },
          }),
        ]) : null,
      ]);
    }

    function sourceCard(entry) {
      const link = safeLink(entry.url);
      const working = installing === entry.id;

      return h('div', { class: 'gssai-source' + (entry.installed ? ' is-installed' : '') }, [
        h('div', { class: 'gssai-source-head' }, [
          h('strong', { text: entry.name }),
          h('span', { class: 'gssai-source-kind', text: entry.kind }),
          entry.installed ? h('span', { class: 'gssai-skill-badge is-quiet', text: t('INSTALLED') }) : null,
        ]),
        h('p', { class: 'gssai-source-desc', text: entry.description }),
        h('p', { class: 'gssai-skill-facts' }, [
          entry.author + ' · ' + entry.license + ' · ' + t('DOCUMENTS', entry.documents) + ' · ',
          link ? h('a', { href: link, target: '_blank', rel: 'noopener noreferrer', text: 'GitHub' }) : null,
        ]),
        h('button', {
          type: 'button', class: 'btn btn-sm ' + (entry.installed ? 'btn-outline-secondary' : 'btn-outline-primary'), disabled: busy,
          text: working ? t('INSTALLING') : (entry.installed ? t('REINSTALL') : t('INSTALL')),
          onclick: () => install(entry),
        }),
      ]);
    }

    function render() {
      list.textContent = '';
      list.appendChild(card(null));
      skills.forEach((skill) => list.appendChild(card(skill)));

      sources.textContent = '';
      catalog.forEach((entry) => sources.appendChild(sourceCard(entry)));
      sourcesBox.hidden = !catalog.length;

      picker.disabled = busy;
      button.disabled = busy;
      button.lastChild.textContent = busy && !installing ? t('UPLOADING') : t('UPLOAD');
    }

    function remove(skill) {
      busy = true;
      render();

      request({ action: 'skill_delete', slug: skill.slug }).then((data) => {
        took(data);
        say(t('DELETED', skill.name), 'ok');

        if (input.value === skill.slug) {
          input.value = '';
          input.dispatchEvent(new Event('change', { bubbles: true }));
          say(t('DELETED', skill.name) + ' ' + t('SAVE_REMINDER'), 'ok');
        }
      }).catch((error) => say(error.message, 'error')).then(() => {
        busy = false;
        render();
      });
    }

    function install(entry) {
      if (busy) {
        return;
      }

      busy = true;
      installing = entry.id;
      say('');
      render();

      // The built-in style stays the default. An installed source is one more style to choose.
      request({ action: 'skill_install', id: entry.id }).then((data) => {
        took(data);
        say(t(entry.installed ? 'REPLACED' : 'ADDED', entry.name), 'ok');
      }).catch((error) => say(error.message, 'error')).then(() => {
        busy = false;
        installing = '';
        render();
      });
    }

    function send(file) {
      if (!file || busy) {
        return;
      }

      if (!/\.zip$/i.test(file.name)) {
        say(t('NOT_ZIP'), 'error');

        return;
      }

      if (options.limit && file.size > options.limit) {
        say(t('TOO_LARGE', megabytes(options.limit)), 'error');

        return;
      }

      const known = skills.map((skill) => skill.slug);

      busy = true;
      say('');
      render();

      request({ action: 'skill_upload', package: file }).then((data) => {
        took(data);

        const skill = skills.filter((item) => item.slug === data.changed)[0];
        const name = skill ? skill.name : file.name;

        say(t(known.indexOf(data.changed) === -1 ? 'ADDED' : 'REPLACED', name) + ' ' + t('SAVE_REMINDER'), 'ok');

        // A skill is uploaded to be used
        if (skill) {
          input.value = skill.slug;
          input.dispatchEvent(new Event('change', { bubbles: true }));
        }
      }).catch((error) => say(error.message, 'error')).then(() => {
        busy = false;
        picker.value = '';
        render();
      });
    }

    const picker = h('input', { type: 'file', accept: '.zip,application/zip', hidden: true, tabindex: '-1', onchange: () => send(picker.files[0]) });
    const button = h('button', { type: 'button', class: 'btn btn-primary', onclick: () => picker.click() }, [
      h('span', { class: 'icon-upload', 'aria-hidden': 'true' }),
      ' ',
      document.createTextNode(''),
    ]);
    const more = safeLink(options.more);
    const sources = h('div', { class: 'gssai-sources' });
    const sourcesBox = h('div', { class: 'gssai-skills-section' }, [
      h('h4', { class: 'gssai-skills-heading', text: t('CATALOG_TITLE') }),
      h('p', { class: 'gssai-skills-hint' }, [
        t('CATALOG_HINT') + ' ',
        more ? h('a', { href: more, target: '_blank', rel: 'noopener noreferrer', text: t('CATALOG_MORE') }) : null,
      ]),
      sources,
    ]);

    list.insertAdjacentElement('beforebegin', h('h4', { class: 'gssai-skills-heading', text: t('CHOOSE_TITLE') }));
    upload.insertAdjacentElement('beforebegin', sourcesBox);
    upload.appendChild(h('h4', { class: 'gssai-skills-heading', text: t('UPLOAD_TITLE') }));
    upload.appendChild(button);
    upload.appendChild(picker);
    upload.appendChild(h('p', { class: 'gssai-skills-hint', text: t('UPLOAD_HINT', megabytes(options.limit || 0)) }));

    // A package can also be dropped on the list
    ['dragenter', 'dragover'].forEach((name) => root.addEventListener(name, (event) => {
      if (event.dataTransfer && Array.from(event.dataTransfer.types || []).indexOf('Files') !== -1) {
        event.preventDefault();
        root.classList.add('is-dropping');
      }
    }));
    root.addEventListener('dragleave', (event) => {
      if (!root.contains(event.relatedTarget)) {
        root.classList.remove('is-dropping');
      }
    });
    root.addEventListener('drop', (event) => {
      root.classList.remove('is-dropping');

      if (event.dataTransfer && event.dataTransfer.files.length) {
        event.preventDefault();
        send(event.dataTransfer.files[0]);
      }
    });

    // A skill that was chosen and then deleted elsewhere
    if (input.value && !skills.some((skill) => skill.slug === input.value)) {
      say(t('MISSING'), 'error');
      input.value = '';
    }

    render();
  }

  function start() {
    document.querySelectorAll('.gssai-skills[data-gssai-skills]').forEach((root) => {
      if (!root.__gssaiReady) {
        root.__gssaiReady = true;
        setup(root);
      }
    });
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', start);
  } else {
    start();
  }
}());
