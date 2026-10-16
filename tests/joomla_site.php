<?php
/**
 * Checks that need a real Joomla and its database: the settings form, and the usage ledger.
 *
 *   php tests_gssaiimage/joomla_site.php /path/to/joomla/site
 *
 * The code under test is the one in this repository, not the copy installed on the site.
 * It writes one row for a user id that does not exist and removes it again.
 */
$site  = rtrim($argv[1] ?? '', '/');
$child = ($argv[2] ?? '') === 'child';

if (!is_file($site . '/configuration.php')) {
    fwrite(STDERR, "Usage: php joomla_site.php /path/to/joomla/site\n");
    exit(2);
}

$_SERVER['HTTP_HOST'] = 'localhost'; $_SERVER['REQUEST_URI'] = '/administrator/index.php'; $_SERVER['SCRIPT_NAME'] = '/administrator/index.php'; $_SERVER['REQUEST_METHOD'] = 'GET';
define('_JEXEC', 1);
define('JPATH_BASE', $site . '/administrator');
require JPATH_BASE . '/includes/defines.php';
require JPATH_BASE . '/includes/framework.php';
error_reporting(E_ALL & ~E_DEPRECATED & ~E_USER_DEPRECATED);

use Gss\Plugin\System\GssAiImage\Helper\AjaxRequest;
use Gss\Plugin\System\GssAiImage\Service\{AiImageException, UsageLedger};
use Joomla\CMS\Factory;
use Joomla\CMS\Form\Form;
use Joomla\Database\DatabaseInterface;

$container = Factory::getContainer();
$container->alias('session', 'session.cli')->alias('JSession', 'session.cli')->alias(\Joomla\CMS\Session\Session::class, 'session.cli')
    ->alias(\Joomla\Session\Session::class, 'session.cli')->alias(\Joomla\Session\SessionInterface::class, 'session.cli');
$app = $container->get(\Joomla\CMS\Application\AdministratorApplication::class);
Factory::$application = $app;
if (method_exists($app, 'createExtensionNamespaceMap')) { $app->createExtensionNamespaceMap(); }
$lang = $container->get(\Joomla\CMS\Language\LanguageFactoryInterface::class)->createLanguage('zh-TW', false);
$app->loadLanguage($lang); Factory::$language = $lang; $app->loadDocument();

$dev = dirname(__DIR__) . '/plg_system_gssaiimage';
spl_autoload_register(function ($class) use ($dev) {
    $prefix = 'Gss\\Plugin\\System\\GssAiImage\\';
    if (strncmp($class, $prefix, strlen($prefix)) === 0) {
        $file = $dev . '/src/' . str_replace('\\', '/', substr($class, strlen($prefix))) . '.php';
        if (is_file($file)) { require $file; }
    }
}, true, true);
$lang->load('plg_system_gssaiimage', $dev, 'zh-TW', true);

$db     = $container->get(DatabaseInterface::class);
$userId = 2147480001;   // nobody
$clean  = fn () => $db->setQuery('DELETE FROM ' . $db->quoteName('#__user_profiles') . ' WHERE ' . $db->quoteName('user_id') . ' = ' . $userId)->execute();

if ($child) {
    // One of several requests of the same user arriving at once
    try { (new UsageLedger($db, $userId))->count('generate', 5); echo 'counted'; } catch (AiImageException $e) { echo $e->reason; }
    exit(0);
}

$failures = 0;
$check = function (string $label, bool $ok, $detail = '') use (&$failures) { echo ($ok ? '  ok   ' : '  FAIL ') . $label . ($ok || $detail === '' ? '' : '  → ' . (is_string($detail) ? $detail : json_encode($detail, JSON_UNESCAPED_UNICODE))) . "\n"; if (!$ok) { $failures++; } };
$refused = function (callable $fn): string { try { $fn(); return ''; } catch (AiImageException $e) { return $e->reason; } };

echo 'Joomla ' . (new \Joomla\CMS\Version())->getShortVersion() . ', PHP ' . PHP_VERSION . ', ' . $db->getServerType() . ' ' . $db->getVersion() . "\n";

echo "Settings form\n";
$copy = 'tmp/gssai-forms-' . getmypid();
@mkdir(JPATH_ROOT . '/' . $copy);
foreach (glob($dev . '/forms/*.xml') as $f) { copy($f, JPATH_ROOT . '/' . $copy . '/' . basename($f)); }
register_shutdown_function(function () use ($copy) { array_map('unlink', glob(JPATH_ROOT . '/' . $copy . '/*.xml') ?: []); @rmdir(JPATH_ROOT . '/' . $copy); });
$xml  = str_replace('formsource="plugins/system/gssaiimage/forms/', 'formsource="' . $copy . '/', file_get_contents($dev . '/gssaiimage.xml'));
$form = new Form('plg_system_gssaiimage', ['control' => 'jform']);
$form->load(simplexml_load_string($xml)->config, true, '//config');
$form->bind(['params' => ['rules' => ['rules0' => ['catid' => ['2'], 'fields' => 'og_image']], 'field_rules' => ['field_rules0' => ['fields' => 'og_image', 'ratio' => '1200x630']]]]);
$skill = $form->getField('style_skill', 'params');
$check('style skill field loads and renders', $skill instanceof \Gss\Plugin\System\GssAiImage\Field\SkillmanagerField && str_contains($skill->input, 'name="jform[params][style_skill]"') && str_contains($skill->input, 'data-gssai-skills='));
preg_match('/data-gssai-skills="([^"]*)"/', $skill->input, $m);
$opts = json_decode(html_entity_decode($m[1] ?? '', ENT_QUOTES, 'UTF-8'), true) ?: [];
$check('it lists the five sources, described in the site language, and where to find more', count($opts['catalog'] ?? []) === 5 && !array_filter($opts['catalog'], fn ($e) => str_starts_with($e['description'], 'PLG_') || str_starts_with($e['kind'], 'PLG_')) && str_starts_with($opts['more'] ?? '', 'https://github.com/'), array_column($opts['catalog'] ?? [], 'description'));
$check('every text of the screen is translated', !array_filter($opts['strings'] ?? ['x' => 'PLG_'], fn ($t) => str_starts_with($t, 'PLG_')), array_keys(array_filter($opts['strings'] ?? [], fn ($t) => str_starts_with($t, 'PLG_'))));
$check('editors switching style can be turned off', str_contains($form->getField('allow_style_switch', 'params')->input, 'name="jform[params][allow_style_switch]"'));
$check('API key fields take 500 characters', str_contains($form->getField('openai_api_key', 'params')->input, 'maxlength="500"') && str_contains($form->getField('gemini_api_key', 'params')->input, 'maxlength="500"'));
$check('Open WebUI fields render, and it can be chosen as the default service', str_contains($form->getField('openwebui_base_url', 'params')->input, 'name="jform[params][openwebui_base_url]"') && str_contains($form->getField('openwebui_api_key', 'params')->input, 'maxlength="500"') && str_contains($form->getField('openwebui_image_model', 'params')->input, 'gpt-image-1-mini') && str_contains($form->getField('provider_default', 'params')->input, 'value="openwebui"'));
$check('longest wait is capped at 300 in the form', str_contains($form->getField('timeout', 'params')->input, 'max="300"'));
$check('both repeatable settings render their rows', str_contains($form->getField('rules', 'params')->input, 'name="jform[params][rules][rules0][fields]"') && str_contains($form->getField('field_rules', 'params')->input, 'name="jform[params][field_rules][field_rules0][ratio]"'));

echo "Usage ledger in the database\n";
$clean();
$first = new UsageLedger($db, $userId);
for ($i = 0; $i < 3; $i++) { $first->count('generate', 3); }
$check('three counted, the fourth refused', $refused(fn () => $first->count('generate', 3)) === 'rate_limit');
$stored = $db->setQuery('SELECT ' . $db->quoteName('profile_value') . ' FROM ' . $db->quoteName('#__user_profiles') . ' WHERE ' . $db->quoteName('user_id') . ' = ' . $userId . ' AND ' . $db->quoteName('profile_key') . ' = ' . $db->quote(UsageLedger::KEY))->loadResult();
$check('kept as one row with the user', is_string($stored) && count(json_decode($stored, true)['generate']) === 3, $stored);
$again = new UsageLedger($db, $userId);   // a new sign-in: new object, no session carried over
$check('signing in again does not reset the count', $refused(fn () => $again->count('generate', 3)) === 'rate_limit');
$check('applying is counted on its own', $refused(fn () => $again->count('apply', 3)) === '');
$first->start(120);
$check('second request while one is generating: refused, also from another sign-in', $refused(fn () => $again->start(120)) === 'busy');
$first->finish();
$check('free again when the first finished', $refused(fn () => $again->start(120)) === '');
$again->finish();
$first->remember('images/ai-generated/2026/10/a-12345678.webp');
$check('applied files are remembered across sign-ins', $again->owns('images/ai-generated/2026/10/a-12345678.webp') && !$again->owns('images/ai-generated/2026/10/b-12345678.webp'));
$again->forget('images/ai-generated/2026/10/a-12345678.webp');
$check('and forgotten when replaced', !$first->owns('images/ai-generated/2026/10/a-12345678.webp'));
$check('odd characters in a path are stored safely', (function () use ($first, $again) { $p = "images/x'; DROP TABLE y; --\\\"/é.webp"; $first->remember($p); return $again->owns($p); })());

$clean();
$procs = []; $pipes = [];
for ($i = 0; $i < 12; $i++) { $procs[$i] = proc_open([PHP_BINARY, __FILE__, $site, 'child'], [1 => ['pipe', 'w'], 2 => ['file', '/dev/null', 'w']], $pipes[$i]); }
$results = [];
foreach ($procs as $i => $p) { $results[] = trim(stream_get_contents($pipes[$i][1])); proc_close($p); }
$counts = array_count_values($results);
$check('12 requests at the same moment, limit 5: exactly 5 get through', ($counts['counted'] ?? 0) === 5 && ($counts['rate_limit'] ?? 0) === 7, $counts);
$clean();

echo "Log file\n";
$logPath = rtrim((string) $app->get('log_path', JPATH_ADMINISTRATOR . '/logs'), '/');
$logFile = $logPath . '/plg_system_gssaiimage.php';
$had     = is_file($logFile);
$marker  = 'test entry ' . bin2hex(random_bytes(4));
$log = new ReflectionMethod(AjaxRequest::class, 'log'); $log->setAccessible(true);
$log->invoke(null, "invalid_api_key: OpenAI HTTP 401: " . $marker . "\nsecond line", \Joomla\CMS\Log\Log::WARNING);
$written = is_file($logFile) ? file_get_contents($logFile) : '';

if (!is_dir($logPath) || !is_writable($logPath)) {
    // The site's own setting points at a folder that is not there. What matters then is that nothing broke.
    echo '  skip the log folder of this site cannot be written: ' . $logPath . "\n";
    $check('a log that cannot be written does not break the request', true);
} else {
    $check('details go to plg_system_gssaiimage.php in the log folder, on one line', str_contains($written, $marker . ' second line'), $logFile);
}
if (!$had && is_file($logFile)) { unlink($logFile); } elseif (is_file($logFile)) { file_put_contents($logFile, implode("\n", array_filter(explode("\n", $written), fn ($l) => !str_contains($l, $marker)))); }

echo "\n" . ($failures ? $failures . ' FAILED' : 'ALL PASSED') . "\n";
exit($failures ? 1 : 0);
