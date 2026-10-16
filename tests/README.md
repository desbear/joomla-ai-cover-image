# Tests of AI Cover Image for Joomla

These files are not part of the installable packages.

| File | What it checks | Needs |
| --- | --- | --- |
| `flow_test.php` | Generating and applying an image: built-in style, uploaded skill, layers of settings, reference images, drafts | PHP with GD and zip |
| `security_test.php` | The nine findings of the security review of 2026-10-01 stay fixed | PHP with GD and zip |
| `openwebui_test.php` | The Open WebUI image service, against answers shaped like those of Open WebUI 0.11 | PHP with GD and zip |
| `catalog_test.php` | The list of skill sources, installing from it, and choosing a style for one image | PHP with GD and zip |
| `joomla_site.php` | The settings form and the usage ledger, against a real Joomla and its database | A local Joomla site |
| `joomla_catalog.php` | Installs every listed source from the real GitHub through the real Joomla HTTP client, into a temporary folder | A local Joomla site, GitHub |
| `joomla_openwebui.php` | A real Open WebUI server through the real Joomla HTTP client. Makes a real image only when a key is in the environment | A local Joomla site, the server |

```
php tests/flow_test.php
php tests/security_test.php
php tests/openwebui_test.php
php tests/catalog_test.php
php tests/joomla_site.php /path/to/joomla/site
php tests/joomla_catalog.php /path/to/joomla/site
php tests/joomla_openwebui.php /path/to/joomla/site https://open-webui-server
```

`joomla_site.php` uses the code of this repository, not the copy installed on the site. It writes one row for a
user id that does not exist and removes it again.

To make one real image and one real revision through Open WebUI, put the key in the environment. It is never printed.

```
OPENWEBUI_API_KEY=... OPENWEBUI_IMAGE_MODEL=gpt-image-1 OPENWEBUI_TEXT_MODEL=gpt-4o-mini \
  php tests/joomla_openwebui.php /path/to/joomla/site https://open-webui-server
```
