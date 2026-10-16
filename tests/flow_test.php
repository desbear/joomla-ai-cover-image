<?php
/**
 * How an image is made and applied, end to end, with a stand-in for the image service.
 * Covers the built-in style, an uploaded skill, the layers of settings, editor reference images and drafts.
 */
require __DIR__ . '/stubs.php';

use Gss\Plugin\System\GssAiImage\Provider\{GeneratedImage, ProviderInterface};
use Gss\Plugin\System\GssAiImage\Service\{ConceptWriter, DraftStore, Generator, ImageProcessor, PromptBuilder, Ratio, RuleResolver, Settings, SkillPromptWriter, SkillStore, UploadedReferences};
use Joomla\Registry\Registry;

$root = sys_get_temp_dir() . '/gssai-flow-' . bin2hex(random_bytes(4));
sampleImages($root . '/images/style-reference');
define('JPATH_ROOT', $root);
$img = imagecreatetruecolor(1536, 1024); imagefill($img, 0, 0, imagecolorallocate($img, 247, 250, 255)); ob_start(); imagepng($img); $png = ob_get_clean();

class FakeProvider implements ProviderInterface {
    public array $calls = []; public string $png; public string $reply = '';
    public function name(): string { return 'openai'; }
    public function nearestRatio(Ratio $r): Ratio { return new Ratio(3, 2); }
    public function generate(string $prompt, Ratio $ratio, array $refs = [], ?string $base = null): GeneratedImage { $this->calls[] = compact('prompt', 'refs', 'base'); return new GeneratedImage($this->png, 'image/png', 'fake'); }
    public function text(string $system, string $user): string { $this->calls[] = compact('system', 'user'); if ($this->reply === 'throw') { throw new RuntimeException('down'); } return $this->reply; }
}
$fake = new FakeProvider(); $fake->png = $png;
$store = new SkillStore($root);
$make = function (array $params) use ($root, $fake, $store) {
    $s = new Settings(new Registry(['openai_api_key' => 'k', 'max_reference_images' => 2] + $params));
    return [new Generator($s, new RuleResolver($s, null), new DraftStore($s, 42, $root), new PromptBuilder($s), new ImageProcessor(), new ConceptWriter(), fn ($n) => $fake, $root, $store, new SkillPromptWriter()), new DraftStore($s, 42, $root)];
};
$article = ['title' => '如何縮短合約審核的流程', 'content' => '<p>過去一份合約要經過四關。</p><script>x()</script>{loadmodule y}', 'tags' => ['合約管理'], 'instruction' => '多留白'];
$builtin = ['reference_folder' => 'images/style-reference', 'style_colors' => '#1A5BFF, #7C3AED, not-a-colour'];

echo "Built-in style\n";
$fake->reply = '```json' . "\n" . '{"topic":"faster contract approval","subject":"Contract sheets move through approval nodes.","layout_mode":"collage"}' . "\n```";
[$gen, $drafts] = $make($builtin + ['default_ratio' => '16:9', 'default_max_width' => 800]);
$r = $gen->generate($article);
check('language model asked first, then the image with two references from the reference folder', isset($fake->calls[0]['system']) && count($fake->calls[1]['refs']) === 2 && basename($fake->calls[1]['refs'][0]) === 'style-01.jpg');
check('article markup and scripts stay out of what the model reads', !str_contains($fake->calls[0]['user'], 'x()') && !str_contains($fake->calls[0]['user'], 'loadmodule') && str_contains($fake->calls[0]['user'], '過去一份合約要經過四關'));
check('prompt: the built-in style with the brand colours, the idea, the request, the crop note, no text', str_contains($r['prompt'], 'editorial illustration for an article') && str_contains($r['prompt'], '#1A5BFF, #7C3AED.') && !str_contains($r['prompt'], 'not-a-colour') && str_contains($r['prompt'], 'Contract sheets move through approval nodes') && str_contains($r['prompt'], '- 多留白') && str_contains($r['prompt'], 'center-cropped to 16:9') && str_contains($r['prompt'], 'absolutely no text'));
check('image cropped to 16:9 and limited to 800 wide, stored as a draft of this user', $r['width'] === 800 && $r['height'] === 450 && str_starts_with($r['path'], 'images/ai-generated/_drafts/u42/') && is_file($root . '/' . $r['path']) && $r['layout_mode'] === 'collage');
$fake->calls = [];
$rev = $gen->generate(['title' => 't', 'instruction' => '把手換成放大鏡', 'base' => $r['draft'], 'layout_mode' => $r['layout_mode']]);
check('revision sends the draft as the edit target, with no language model call', count($fake->calls) === 1 && $fake->calls[0]['base'] === $root . '/' . $r['path'] && $rev['revised'] && str_contains($rev['prompt'], 'Style guardrails: Editorial illustration') && str_contains($rev['prompt'], 'Brand colours: #1A5BFF, #7C3AED.'));
$fake->calls = []; $fake->reply = 'throw';
$r3 = $gen->generate(['title' => '只有標題', 'instructions' => ['舊需求'], 'instruction' => '新需求']);
check('language model down: the article brief goes to the image model', $r3['layout_mode'] === 'light' && str_contains($r3['prompt'], 'Article brief:') && str_contains($r3['prompt'], "- 舊需求\n- 新需求"));
check('nothing to draw from is refused', refused(fn () => $gen->generate(['title' => '', 'content' => '<p> </p>'])) === 'no_content');

echo "Layers of settings\n";
$fake->reply = '{"topic":"t","subject":"s","layout_mode":"light"}';
$layers = ['default_ratio' => '16:9', 'default_max_width' => 1200,
    'field_rules' => ['f0' => ['fields' => 'og_image', 'prompt' => '社群分享用', 'ratio' => '1200x630', 'text_mode' => 'title']],
    'rules' => ['r0' => ['catid' => ['12'], 'fields' => '', 'prompt' => '客戶故事', 'ratio' => '3:2', 'layout_mode' => 'collage'], 'r1' => ['catid' => ['12'], 'fields' => 'og_image', 'prompt' => '分享圖', 'layout_mode' => 'dark']]];
$s = new Settings(new Registry(['openai_api_key' => 'k'] + $layers)); $resolver = new RuleResolver($s, null);
$x = $resolver->resolve(12, 'hero_image');
check('category over defaults', $x['ratio'] === '3:2' && $x['max_width'] === 1200 && $x['layout_mode'] === 'collage' && $x['sources']['ratio'] === 'category' && $x['sources']['max_width'] === 'default');
$x = $resolver->resolve(12, 'og_image');
check('field keeps its ratio where the category rule for that field sets none', $x['ratio'] === '1200x630' && $x['layout_mode'] === 'dark' && $x['text_mode'] === 'title' && $x['prompt'] === "社群分享用\n分享圖");
[$lgen] = $make($layers);
$plan = $lgen->plan(['catid' => 12, 'field' => 'og_image', 'ratio' => '1:1', 'max_width' => 600]);
check('what the editor changes in the panel wins over every layer', $plan['ratio'] === '1:1' && $plan['max_width'] === 600 && $plan['layout_mode'] === 'dark');
[$locked] = $make($layers + ['allow_size_override' => 0]);
check('unless editors may not change the size', $locked->plan(['catid' => 12, 'field' => 'og_image', 'ratio' => '1:1', 'max_width' => 600])['ratio'] === '1200x630');

echo "Uploaded skill\n";
$zip = new ZipArchive(); $zf = $root . '/skill.zip'; $zip->open($zf, ZipArchive::CREATE);
$zip->addFromString('brand/SKILL.md', "---\nname: Warm Brand\ndescription: Hand-drawn warm style\n---\n# Warm Brand\n\nWarm palette, hand-drawn lines.");
$zip->addFromString('brand/references/modes.md', 'Two modes: sunny and dusk.');
$zip->addFromString('brand/assets/one.png', $png); $zip->addFromString('brand/assets/two.png', $png);
$zip->addFromString('brand/run.php', '<?php echo 1;'); $zip->addFromString('../escape.md', 'x'); $zip->close();
$skill = $store->install($zf, 'warm.zip');
check('package read: name, two documents, two images, nothing else', $skill->name === 'Warm Brand' && array_keys($skill->documents) === ['SKILL.md', 'references/modes.md'] && $skill->images === ['one.jpg', 'two.jpg'] && !file_exists($root . '/escape.md') && !glob($skill->folder . '/*.md') && !is_file($skill->folder . '/run.php'));
$fake->calls = []; $fake->reply = json_encode(['prompt' => 'A warm hand-drawn desk with contract sheets.', 'references' => ['two.jpg', 'made-up.jpg'], 'layout_mode' => 'sunny']);
[$sgen] = $make(['style_skill' => 'warm-brand']);
$r = $sgen->generate($article);
check('language model works by the skill', str_contains($fake->calls[0]['system'], '<skill name="Warm Brand">') && str_contains($fake->calls[0]['system'], 'Two modes: sunny and dusk') && !str_contains($fake->calls[0]['system'], 'paper collage'));
check('its prompt first, the rules that always hold after it, only offered images chosen', str_starts_with($r['prompt'], 'A warm hand-drawn desk') && str_contains($r['prompt'], 'Requirements that hold whatever is said above:') && str_contains($r['prompt'], '- 多留白') && array_map('basename', $fake->calls[1]['refs']) === ['two.jpg'] && $r['style'] === 'Warm Brand' && !str_contains($r['prompt'], 'Noto Sans TC'));
[$dgen] = $make(['style_skill' => 'gone']);
$fake->reply = '{"topic":"t","subject":"s","layout_mode":"light"}';
check('a chosen skill that was deleted falls back to the built-in style', str_contains($dgen->generate($article)['prompt'], 'editorial illustration for an article'));
[$plain] = $make(['style_skill' => 'gone']); $fake->calls = [];
$r = $plain->generate($article);
check('no reference folder and no colours: no references, and the model picks the palette', $fake->calls[1]['refs'] === [] && str_contains($r['prompt'], 'restrained palette') && !str_contains($r['prompt'], 'Brand colours:'));

echo "Editor reference images\n";
$tmp = $root . '/tmp'; mkdir($tmp); file_put_contents($tmp . '/a.png', $png);
$uploads = new UploadedReferences($tmp, fn ($p) => is_file($p));
$paths = $uploads->collect([['name' => 'a.png', 'tmp_name' => $tmp . '/a.png', 'error' => 0, 'size' => strlen($png)]]);
$fake->calls = [];
$r = $gen->generate($article + ['user_references' => $paths]);
check('editor image goes first, then the style references', $fake->calls[1]['refs'][0] === $paths[0] && count($fake->calls[1]['refs']) === 3 && $r['editor_references'] === 1 && str_contains($r['prompt'], 'the first image comes from the editor'));
$uploads->cleanup();
check('and is gone after the request', !is_file($paths[0]) && !glob($tmp . '/gssai-ref-*'));

echo "Reference sheet\n";
$refs  = glob($root . '/images/style-reference/*.jpg');
$wide  = (new ImageProcessor())->sheet([$refs[0], $refs[2]]);
$size  = getimagesizefromstring($wide['data']);
check('wide references are stacked, the sheet stays within 1600 px', $wide['layout'] === 'stacked' && $wide['count'] === 2 && $size['mime'] === 'image/jpeg' && max($size[0], $size[1]) <= 1600 && $size[1] > 300, [$wide['layout'], $size[0], $size[1]]);
$tall = imagecreatetruecolor(600, 900); imagejpeg($tall, $root . '/tall1.jpg'); imagejpeg($tall, $root . '/tall2.jpg');
$row  = (new ImageProcessor())->sheet([$root . '/tall1.jpg', $root . '/tall2.jpg', $root . '/missing.jpg']);
$size = getimagesizefromstring($row['data']);
check('upright references stand side by side, unreadable files are left out', $row['layout'] === 'row' && $row['count'] === 2 && $size[0] > $size[1]);
check('one readable image is not a sheet', (new ImageProcessor())->sheet([$refs[0], $root . '/missing.jpg']) === null);

echo "Drafts\n";
$a = $drafts->save($png, 'png'); $b = $drafts->save($png, 'png'); $c = $drafts->save($png, 'png');
check('applying removes the drafts but those another panel still shows', $drafts->purge([$b], $a) >= 2 && refused(fn () => $drafts->path($a)) === 'draft' && is_file($drafts->path($b)) && refused(fn () => $drafts->path($c)) === 'draft');
check("another editor cannot reach this editor's draft", refused(fn () => (new DraftStore($s, 7, $root))->path($b)) === 'draft');
check('odd draft names are refused', refused(fn () => $drafts->path('../../../configuration.php')) === 'draft' && refused(fn () => $drafts->path(str_repeat('a', 32) . '.php')) === 'draft');
$drafts->purge();
check('with nothing left the folder goes too', !is_dir($root . '/images/ai-generated/_drafts/u42'));

exec('rm -rf ' . escapeshellarg($root));
echo "\n" . ($failures ? $failures . ' FAILED' : 'ALL PASSED') . "\n";
exit($failures ? 1 : 0);
