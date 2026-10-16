# Changelog

## 2.0.0

First public release.

- **The built-in style belongs to no brand.** It is a general editorial illustration style: light
  or dark gradient fields, fine noise, a restrained palette, flat vector shapes and paper collage.
  A new **Brand colours** setting (up to 8 hex codes) tells the model which colours to use; the
  **Own reference folder** setting points at example images of your style. The reference images of
  the earlier built-in style are gone; the style of a brand now comes as a skill, uploaded as a zip
  or installed from the list of sources.
- Settings and documentation explain where to get an OpenAI or Gemini key and how to use a
  self-hosted Open WebUI server. Example model names and addresses are generic.
- Licensed under the GNU General Public License version 2 or later; `LICENSE` ships with each plugin.

### Earlier versions, before publication

- 1.7.0: the style settings split into a default style, a list of public skill sources on GitHub
  that install with one click (each pinned to one commit), and upload. Editors may choose a style
  per image when allowed.
- 1.6.x: Open WebUI as a third image service, including sending reference images as one sheet,
  revising an image through the edit route, and fetching and deleting the files on the server.
- 1.5.0: nine fixes from a security review; see [SECURITY-AUDIT.md](SECURITY-AUDIT.md).
- 1.4.0: styles as uploaded skills; a language model writes the prompt by the skill.
- 1.3.0: drafts are removed the moment an image is applied.
- 1.2.0: four layers of settings: defaults, image field, category rule, and the editor's changes in the panel.
- 1.1.0: editors can add up to three reference images per request; they are never stored.
- 1.0.0: cover images for Joomla and FLEXIcontent image fields from OpenAI and Gemini, with a panel for revisions.
