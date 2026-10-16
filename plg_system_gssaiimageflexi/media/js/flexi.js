/**
 * GSS AI Image - FLEXIcontent image fields.
 *
 * Covers image fields in DB mode, folder mode and media manager mode, and the
 * intro and full image fields that FLEXIcontent shows from Joomla.
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
  const mine = (core.options.adapters && core.options.adapters.flexi) || {};

  /** Name of the FLEXIcontent field a value box belongs to, read from custom[name][0][originalname]. */
  function fieldName(box) {
    const input = box.querySelector('input.originalname, input.existingname, select.existingname');
    const match = input && /^custom\[([^\]]+)\]/.exec(input.getAttribute('name') || '');

    return match ? match[1] : '';
  }

  /** Field id and item id, which FLEXIcontent writes into the link of its own file picker. */
  function pickerInfo(box) {
    const link = box.querySelector('.fc-files-modal-link[data-href]');
    const href = link ? link.getAttribute('data-href') : '';
    const field = /[?&]field=(\d+)/.exec(href);
    const item = /[?&]u_item_id=([^&]+)/.exec(href);

    return { field: field ? field[1] : '', item: item ? decodeURIComponent(item[1]) : '' };
  }

  function itemRef(box) {
    const fromPicker = pickerInfo(box).item;

    if (fromPicker) {
      return fromPicker;
    }

    const id = parseInt(util.fieldValue('input[name="jform[id]"]') || util.fieldValue('input[name="id"]'), 10) || 0;

    return id ? String(id) : util.fieldValue('input[name="unique_tmp_itemid"]');
  }

  /** FLEXIcontent counts the values of a field in a hidden input, which its required check reads. */
  function countValue(box, name) {
    const flag = box.querySelector('.hasvalue');
    const counter = document.getElementById('custom_' + name.replace(/-/g, '_'));

    if (flag && !flag.value) {
      flag.value = '1';

      if (counter) {
        counter.value = counter.value === '' ? '1' : String((parseInt(counter.value, 10) || 0) + 1);
      }
    }
  }

  function fieldBox(name) {
    const counter = document.getElementById('custom_' + name.replace(/-/g, '_'));
    const input = document.querySelector('[name^="custom[' + name + ']"]');
    const start = counter || input;

    return start ? (start.closest('.fcfield_box, .container_fcfield, .controls, .control-group') || start.parentElement) : null;
  }

  function tags() {
    const found = [];

    document.querySelectorAll('#ultagbox li.tagitem > span').forEach((span) => found.push(util.clean(span.textContent)));
    document.querySelectorAll('input[name="jform[tag][]"][type="checkbox"]:checked').forEach((input) => {
      found.push(util.clean(input.getAttribute('data-tagname') || ''));
    });
    document.querySelectorAll('select[name="jform[tag][]"] option:checked, select[name="jform[tags][]"] option:checked').forEach((option) => {
      found.push(util.clean(option.textContent));
    });

    return found.filter((value, index) => value && found.indexOf(value) === index);
  }

  function applyToMediaField(media, value) {
    window.Joomla = window.Joomla || {};
    window.Joomla.selectedMediaFile = window.Joomla.selectedMediaFile || {};

    if (typeof media.setValue === 'function') {
      media.setValue(value);
    } else {
      const input = media.querySelector('input.field-media-input, input[type="text"]');

      input.value = value;
      input.dispatchEvent(new Event('change', { bubbles: true }));
    }
  }

  core.register({
    name: 'flexi',

    scan(root) {
      const targets = [];

      // Image fields of FLEXIcontent: one value box per image
      root.querySelectorAll('.fcfieldval_container').forEach((box) => {
        const name = fieldName(box);

        if (!name || !box.querySelector('.originalname')) {
          return;
        }

        targets.push({
          host: box,
          kind: 'field',
          name,
          key: name,
          place(button) {
            const media = box.querySelector('joomla-field-media');
            const select = media && media.querySelector('.button-select');
            const buttons = box.querySelector('.fc-field-props-box .fc-xpended-btns') || box.querySelector('.fc-xpended-btns');

            if (select && select.parentElement) {
              select.insertAdjacentElement('afterend', button);
            } else if (buttons) {
              buttons.appendChild(button);
            } else {
              const row = document.createElement('div');

              row.className = 'gssai-trigger-row';
              row.appendChild(button);
              (box.querySelector('.fc-field-props-box') || box).insertAdjacentElement('afterbegin', row);
            }
          },
        });
      });

      // Intro and full image, which are Joomla media fields inside the FLEXIcontent form
      root.querySelectorAll('joomla-field-media').forEach((media) => {
        const types = (media.getAttribute('types') || 'images').toLowerCase();

        if (media.closest('.fcfieldval_container') || media.closest('.gssai-panel') || types.indexOf('images') === -1) {
          return;
        }

        targets.push({
          host: media,
          kind: 'media',
          key: util.mediaFieldKey(media),
          place(button) {
            const select = media.querySelector('.button-select');

            if (select && select.parentElement) {
              select.insertAdjacentElement('afterend', button);
            } else {
              media.appendChild(button);
            }
          },
        });
      });

      return targets;
    },

    context() {
      const extra = {};

      (mine.extraFields || []).forEach((name) => {
        const value = util.readValues(fieldBox(name)).join('、');

        if (value) {
          extra[name] = value;
        }
      });

      return {
        title: util.fieldValue('#jform_title'),
        content: util.editorValue((mine.contentIds || []).concat(['jform_text', 'jform_articletext'])),
        catid: parseInt(util.fieldValue('#jform_catid'), 10) || 0,
        tags: tags(),
        extra,
      };
    },

    apply(target, version, api) {
      if (target.kind === 'media') {
        return api.post('gssaiimage', 'apply', {
          draft: version.draft,
          title: api.context.title || '',
          replace: api.state.appliedPath || '',
        }).then((result) => {
          applyToMediaField(target.host, result.value);

          return result;
        });
      }

      const box = target.host;
      const picker = pickerInfo(box);

      return api.post('gssaiimageflexi', 'apply', {
        draft: version.draft,
        field: picker.field || target.name,
        item: itemRef(box),
        title: api.context.title || '',
        replace: api.state.appliedPath || '',
      }).then((result) => {
        const existing = box.querySelector('.existingname');
        const original = box.querySelector('.originalname');
        const media = box.querySelector('joomla-field-media');
        const config = target.name.replace(/-/g, '_');

        if (result.mode === 'media' && media) {
          applyToMediaField(media, result.value);
          countValue(box, target.name);

          return result;
        }

        const assign = window['fcfield_assignImage' + result.field_id];

        if (existing && existing.id && typeof assign === 'function') {
          // FLEXIcontent's own way of taking a picked file: sets the value, the preview and the counters
          assign(existing.id, result.value, result.preview || result.url, 1, result.filename || result.value);
        } else if (existing && existing.id && window.fcfield_image && typeof window.fcfield_image.assignImage === 'function') {
          window.fcfield_image.assignImage(existing.id, result.value, result.preview || result.url, 1, result.filename || result.value, config);
        } else {
          if (existing) {
            existing.value = result.value;
          }

          if (original) {
            original.value = existing ? '' : result.value;
          }

          const preview = box.querySelector('.fc_preview_thumb');

          if (preview) {
            preview.src = result.preview || result.url;
          }

          countValue(box, target.name);
        }

        return result;
      });
    },
  });
}());
