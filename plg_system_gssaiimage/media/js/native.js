/**
 * GSS AI Image - Joomla's own image fields.
 *
 * Covers the intro image, the full article image and custom fields of type Media.
 *
 * @copyright  (C) 2026 GSS
 * @license    GNU General Public License version 2 or later; see LICENSE
 */
(function () {
  'use strict';

  const core = window.GssAiImage;

  if (!core) {
    return;
  }

  const util = core.util;

  function isImageField(el) {
    const types = (el.getAttribute('types') || 'images').toLowerCase();

    return types.indexOf('images') !== -1 && !el.closest('.gssai-panel');
  }

  function customField(name) {
    // A custom field may be one control or several (checkboxes), and may sit in a subform
    const controls = document.querySelectorAll('[name^="jform[com_fields][' + name + ']"]');
    const roots = [];

    controls.forEach((control) => {
      const root = control.closest('.control-group') || control.parentElement;

      if (root && roots.indexOf(root) === -1) {
        roots.push(root);
      }
    });

    return roots.map((root) => util.readValues(root).join('、')).filter(Boolean).join('、');
  }

  core.register({
    name: 'native',

    scan(root) {
      return Array.from(root.querySelectorAll('joomla-field-media')).filter(isImageField).map((el) => ({
        host: el,
        key: util.mediaFieldKey(el),
        place(button) {
          const select = el.querySelector('.button-select');
          const group = el.querySelector('.input-group');

          if (select && select.parentElement) {
            select.insertAdjacentElement('afterend', button);
          } else if (group) {
            group.appendChild(button);
          } else {
            el.appendChild(button);
          }
        },
      }));
    },

    context() {
      const tags = document.querySelector('#jform_tags, select[name="jform[tags][]"]');
      const extra = {};

      (core.options.extraFields || []).forEach((name) => {
        const value = customField(name);

        if (value) {
          extra[name] = value;
        }
      });

      return {
        title: util.fieldValue('#jform_title'),
        content: util.editorValue(['jform_articletext']),
        catid: parseInt(util.fieldValue('#jform_catid'), 10) || 0,
        tags: tags ? Array.from(tags.selectedOptions || []).map((option) => util.clean(option.textContent).replace(/^[-\s.|_]+/, '')).filter(Boolean) : [],
        extra,
      };
    },

    apply(target, version, api) {
      return api.post('gssaiimage', 'apply', {
        draft: version.draft,
        title: api.context.title || '',
        replace: api.state.appliedPath || '',
      }).then((result) => {
        // The media field reads the file type from here when it is set
        window.Joomla = window.Joomla || {};
        window.Joomla.selectedMediaFile = window.Joomla.selectedMediaFile || {};

        if (typeof target.host.setValue === 'function') {
          target.host.setValue(result.value);
        } else {
          const input = target.host.querySelector('input.field-media-input, input[type="text"]');

          input.value = result.value;
          input.dispatchEvent(new Event('change', { bubbles: true }));
        }

        return result;
      });
    },
  });
}());
