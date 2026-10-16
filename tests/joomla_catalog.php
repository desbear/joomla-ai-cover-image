<?php
/**
 * Installs every source on the list from the real GitHub, through the real Joomla HTTP client.
 *
 *   php tests_gssaiimage/joomla_catalog.php /path/to/joomla/site
 *
 * Nothing is written to the site: the skills go to a temporary folder.
 *
 * With an Open WebUI key in the environment, the language model also writes one image prompt by each
 * skill. That is text only and makes no image:
 *
 *   OPENWEBUI_API_KEY=... OPENWEBUI_TEXT_MODEL=... php tests_gssaiimage/joomla_catalog.php <site> https://open-webui-server
 */
$site   = rtrim($argv[1] ?? '', '/');
$server = $argv[2] ?? '';

if (!is_file($site . '/configuration.php')) {
    fwrite(STDERR, "Usage: php joomla_catalog.php /path/to/joomla/site [https://open-webui-server]\n");
    exit(2);
}

$_SERVER['HTTP_HOST'] = 'localhost'; $_SERVER['REQUEST_URI'] = '/administrator/index.php'; $_SERVER['SCRIPT_NAME'] = '/administrator/index.php'; $_SERVER['REQUEST_METHOD'] = 'GET';
define('_JEXEC', 1);
define('JPATH_BASE', $site . '/administrator');
require JPATH_BASE . '/includes/defines.php';
require JPATH_BASE . '/includes/framework.php';
error_reporting(E_ALL & ~E_DEPRECATED & ~E_USER_DEPRECATED);

use Gss\Plugin\System\GssAiImage\Provider\OpenWebUiProvider;
use Gss\Plugin\System\GssAiImage\Service\{HttpClient, PromptBuilder, Ratio, Settings, SkillCatalog, SkillPromptWriter, SkillStore};
use Joomla\CMS\Factory;
use Joomla\Registry\Registry;

$container = Factory::getContainer();
$container->alias('session', 'session.cli')->alias('JSession', 'session.cli')->alias(\Joomla\CMS\Session\Session::class, 'session.cli')
    ->alias(\Joomla\Session\Session::class, 'session.cli')->alias(\Joomla\Session\SessionInterface::class, 'session.cli');
$app = $container->get(\Joomla\CMS\Application\AdministratorApplication::class);
Factory::$application = $app;
if (method_exists($app, 'createExtensionNamespaceMap')) { $app->createExtensionNamespaceMap(); }
$lang = $container->get(\Joomla\CMS\Language\LanguageFactoryInterface::class)->createLanguage('zh-TW', false);
$app->loadLanguage($lang); Factory::$language = $lang;

$dev = dirname(__DIR__) . '/plg_system_gssaiimage';
spl_autoload_register(function ($class) use ($dev) {
    $prefix = 'Gss\\Plugin\\System\\GssAiImage\\';
    if (strncmp($class, $prefix, strlen($prefix)) === 0) {
        $file = $dev . '/src/' . str_replace('\\', '/', substr($class, strlen($prefix))) . '.php';
        if (is_file($file)) { require $file; }
    }
}, true, true);
$lang->load('plg_system_gssaiimage', $dev, 'zh-TW', true);

$root  = sys_get_temp_dir() . '/gssai-catalog-' . date('Ymd-His');
mkdir($root, 0755, true);
register_shutdown_function(fn () => exec('rm -rf ' . escapeshellarg($root)));
$store = new SkillStore($root);
$http  = new HttpClient(30);
$failures = 0;
$check = function (string $label, bool $ok, $detail = '') use (&$failures) { echo ($ok ? '  ok   ' : '  FAIL ') . $label . ($ok || $detail === '' ? '' : '  → ' . (is_string($detail) ? $detail : json_encode($detail, JSON_UNESCAPED_UNICODE))) . "\n"; if (!$ok) { $failures++; } };

echo 'Joomla ' . (new \Joomla\CMS\Version())->getShortVersion() . ', PHP ' . PHP_VERSION . "\n";
$key      = (string) getenv('OPENWEBUI_API_KEY');
$settings = new Settings(new Registry(['openwebui_base_url' => $server, 'openwebui_api_key' => $key, 'openwebui_text_model' => (string) getenv('OPENWEBUI_TEXT_MODEL')]));
$article  = ['title' => '用標籤整理知識庫文章', 'content' => '<p>知識庫的文章現在可以加上多個標籤。同一篇文章可以同時屬於幾個主題，讀者從任何一個主題都找得到它。</p>', 'tags' => ['知識庫', '常見問題'], 'category' => '文章'];

foreach (SkillCatalog::toPublic() as $entry) {
    echo "\n" . $entry['name'] . ' (' . $entry['author'] . ', ' . $entry['license'] . ', ' . $entry['kind'] . ")\n";
    $check('description is translated', !str_starts_with($entry['description'], 'PLG_') && !str_starts_with($entry['kind'], 'PLG_'));

    try {
        $t0    = microtime(true);
        $skill = SkillCatalog::install($entry['id'], $http, $store);
    } catch (Throwable $e) {
        $check('installs from GitHub', false, $e->getMessage() . ' ' . ($e->detail ?? ''));
        continue;
    }

    $total = array_sum(array_map('mb_strlen', $skill->documents));
    $guide = mb_strlen($skill->guide());
    $check('installs from GitHub: ' . count($skill->documents) . ' documents, ' . number_format($total) . ' characters, ' . round(microtime(true) - $t0, 1) . ' s', count($skill->documents) === $entry['documents'] && $total > 2000);
    $check('all of it reaches the language model (' . number_format($guide) . ' of at most 60,000 characters)', $guide >= $total && $guide <= 60000, [$guide, $total]);
    $check('no settings block left at the top, description read from it', !str_starts_with(array_values($skill->documents)[0], '---') && mb_strlen($skill->description) > 20, mb_substr($skill->description, 0, 80));

    if ($key !== '' && $server !== '') {
        $brief  = (new PromptBuilder($settings))->skillBrief($article, 'auto', 'none', Ratio::parse('16:9'), '', [], [], 0);
        $t0     = microtime(true);
        $answer = (new SkillPromptWriter())->write(new OpenWebUiProvider($settings, new HttpClient(120)), $skill, $brief, [], 0);
        $check('language model writes a prompt by it, ' . round(microtime(true) - $t0) . ' s', mb_strlen($answer['prompt']) > 80 && !str_contains($answer['prompt'], '?'), $answer);
        echo '       chose: ' . ($answer['layout_mode'] ?: '(nothing named)') . "\n";
        echo '       prompt: ' . mb_substr(preg_replace('/\s+/', ' ', $answer['prompt']), 0, 420) . (mb_strlen($answer['prompt']) > 420 ? '…' : '') . "\n";
    }
}

echo "\n" . ($failures ? $failures . ' FAILED' : 'ALL PASSED') . "\n";
exit($failures ? 1 : 0);
