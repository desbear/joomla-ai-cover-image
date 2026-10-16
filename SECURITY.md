# Security Policy

AI Cover Image sends article content to an image service, stores API keys, writes files below the
site's `images` folder, and installs skill packages from uploads and from GitHub. Please report security
problems privately so they can be fixed before they are published.

**Do not open a public issue for a vulnerability.** Use GitHub's private vulnerability reporting
instead: on this repository, open the **Security** tab and choose **Report a vulnerability**.

Please include the version, your Joomla and PHP versions, and steps to reproduce. You will get a
response within a week.

## Supported versions

Only the latest release receives security fixes.

## Things to know before you install

- **Content leaves the site.** The title, text and tags of an article, and any reference images the
  editor adds, are sent to the image service you configure, for unpublished articles too. With Open
  WebUI they go to your own server first, which passes them on to the model behind it.
- **Everyone who may create or edit articles may generate images**, in the site frontend as well as
  the administrator. Set the hourly limit per user in the plugin; each image costs money.
- **API keys are stored in the plugin parameters** in plain text, as Joomla plugins do. Whoever may
  edit the plugin can read them. Keys are never sent to the browser.
- **Installed skills are text that a language model reads.** A skill from the list of sources is
  fetched file by file from one pinned commit on GitHub; an uploaded skill is read from the zip.
  Both are stored as escaped text inside a guarded PHP file and never executed, but what they say
  shapes the prompt. Install skills you have read.
- **The raw error text of a service** is written to `plg_system_gssaiimage.php` in the site's log
  folder and shown only to users who may edit plugins. Editors see the kind of problem only.

## Audit history

[SECURITY-AUDIT.md](SECURITY-AUDIT.md) records what has been reviewed and what has not.
