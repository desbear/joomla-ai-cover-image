# Security audit

AI Cover Image accepts requests from every user who may edit content, calls image services with a
stored key, writes files below `images`, and installs skill packages. This page records what has been
reviewed, what was wrong, and what has *not* been checked. To report something new, see
[SECURITY.md](SECURITY.md); please do it privately.

## What was audited

| | |
|---|---|
| Date | 1 October 2026 |
| Version reviewed | 1.4.0 |
| Version with the fixes | 1.5.0 (all nine findings); the checks have been kept up to date through 2.0.0 |
| Scope | The whole package: the com_ajax entry point and its permission checks, drafts and applied files, the usage limits, reference image handling, the FLEXIcontent adapter, uploaded skills, error reporting and time limits |

**This was an internal review, not a third-party penetration test.** It was carried out with the help
of AI agents. Every finding was reproduced before it was accepted, and every fix has a regression
check in [tests/security_test.php](tests/security_test.php). Treat the result as a careful first
pass, not as a clean bill of health.

Who was assumed to be an attacker:

| Who | Controls |
|---|---|
| A signed-in author or editor | the requests of the panel: draft names, file paths, item ids, uploaded reference images, how many requests are sent and how fast |
| Whoever may edit the plugin | uploaded skill packages and the settings, including the save and reference folders |
| The image service, or a server between | its answers: HTTP status, error text, file addresses, the bytes of an image |
| A public skill source on GitHub | the documents it publishes |

## Findings and fixes

| # | Finding | Fix in 1.5.0 |
|---|---|---|
| 1 | A draft could be applied more than once, so one generated image could be written to many places and the hourly limit counted it once | A draft is removed the moment it is applied, with the drafts of the same user that are not still shown in another panel |
| 2 | The hourly limit was kept in the session, so signing in again, or sending requests at the same moment, started over | The ledger lives in `#__user_profiles` under the user id, read with `SELECT … FOR UPDATE` inside a transaction. Only one image is generated per user at a time |
| 3 | Replacing the previous image of a field deleted whatever path the request named | Only a file that the same user applied within the last 24 hours, as recorded in the ledger, is deleted |
| 4 | A small reference image that unpacks to a huge bitmap could exhaust memory | Reference images are limited to 6 MB and 12 megapixels, the memory the bitmap needs is checked against the PHP limit first, and leftovers in the temporary folder are swept |
| 5 | The FLEXIcontent adapter accepted any string as the id of a new item and built a folder from it | Only the format FLEXIcontent itself generates for temporary items is accepted, with the date within three days |
| 6 | The text of an uploaded skill was stored in a PHP file and could carry a PHP open tag | The text is stored JSON-encoded with angle brackets escaped (`JSON_HEX_TAG`), behind an `_JEXEC` guard, and read back with `json_decode` |
| 7 | The save folder of the settings could point outside `images` | The folder must be `images` or a folder below it; anything else falls back to the default |
| 8 | The raw error text of the service reached every editor | Editors get a generic message by kind of problem; the raw text goes to the plugin's log file and is shown only to users who may edit plugins |
| 9 | Requests could run for as long as the service took | Language model calls are limited to 45 seconds; image calls to the configured timeout, at most 300 seconds |

Added since, with the same checks:

- Open WebUI (1.6.0): the key is sent only to the configured server, and a file address in the
  answer is fetched only when it is on that server. Addresses with credentials, query strings or
  other schemes are refused.
- Skill sources (1.7.0): each source is pinned to one commit and fetched file by file from
  `raw.githubusercontent.com`; no zip downloads and no arbitrary addresses.

## What has not been reviewed

- The browser side of the panel (`media/js/panel.js`, `native.js`, `flexi.js`) had a reading pass
  only. Everything it shows comes from the plugin's own JSON; text is inserted with `textContent`.
- The FLEXIcontent adapter was tested against FLEXIcontent's code and a local site, not against
  every image field mode in production.
- The Gemini and OpenAI providers were read for how they handle the answers of the service; neither
  has been fuzzed with malformed answers beyond the cases in the tests.
- No load test. The usage ledger was tested with 12 concurrent processes on one database.
