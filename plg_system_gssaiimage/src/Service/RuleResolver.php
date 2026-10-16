<?php

/**
 * @package     GSS AI Image
 * @subpackage  plg_system_gssaiimage
 *
 * @copyright   (C) 2026 GSS
 * @license     GNU General Public License version 2 or later; see LICENSE
 */

namespace Gss\Plugin\System\GssAiImage\Service;

\defined('_JEXEC') or die;

use Joomla\Database\DatabaseInterface;
use Joomla\Database\ParameterType;

/**
 * Works out the ratio, width, style and extra prompt for one image.
 *
 * Three layers, each one overriding the one before where it sets a value:
 *   1. the defaults of the plugin
 *   2. the settings of the image field (intro image, a custom field, a FLEXIcontent field)
 *   3. the rule of the article's category
 *
 * Extra prompts do not override each other: the field's and the category's both apply.
 *
 * For categories, the category itself wins over its parents. A parent's rule only
 * reaches its children when that rule says so.
 */
final class RuleResolver
{
    private const OVERRIDABLE = ['ratio', 'max_width', 'provider', 'layout_mode', 'text_mode'];

    public function __construct(private readonly Settings $settings, private readonly ?DatabaseInterface $db = null)
    {
    }

    /**
     * @return array{matched: bool, category_id: int, category_title: string, rule_category: string, category_prompt: string,
     *               field_key: string, field_matched: bool, field_prompt: string, prompt: string,
     *               ratio: string, max_width: int, provider: string, layout_mode: string, text_mode: string,
     *               sources: array<string, string>}
     */
    public function resolve(int $catid, string $fieldKey = ''): array
    {
        $fieldKey = preg_match('/^[A-Za-z0-9_\-]{1,100}$/', $fieldKey) ? $fieldKey : '';

        // Layer 1: the defaults
        $result = [
            'matched'         => false,
            'category_id'     => $catid,
            'category_title'  => '',
            'rule_category'   => '',
            'category_prompt' => '',
            'field_key'       => $fieldKey,
            'field_matched'   => false,
            'field_prompt'    => '',
            'prompt'          => '',
            'ratio'           => $this->settings->defaultRatio(),
            'max_width'       => $this->settings->defaultMaxWidth(),
            'provider'        => $this->settings->defaultProvider(),
            'layout_mode'     => $this->settings->layoutMode(),
            'text_mode'       => $this->settings->textMode(),
            'sources'         => array_fill_keys(self::OVERRIDABLE, 'default'),
        ];

        // Layer 2: the image field
        $fieldRule = $this->matchField($fieldKey, $this->settings->fieldRules());

        if ($fieldRule !== null) {
            $result['field_matched'] = true;
            $result['field_prompt']  = $fieldRule['prompt'];
            $result                  = self::overlay($result, $fieldRule, 'field');
        }

        // Layer 3: the category
        if ($catid > 0) {
            $path = $this->categoryPath($catid);

            if ($path) {
                $result['category_title'] = $path[0]['title'];
            }

            $rule = $this->match($path ?: [['id' => $catid, 'title' => '']], $this->settings->rules(), $fieldKey);

            if ($rule !== null) {
                $result['matched']         = true;
                $result['rule_category']   = $rule['_category_title'];
                $result['category_prompt'] = $rule['prompt'];
                $result                    = self::overlay($result, $rule, 'category');
            }
        }

        $result['prompt'] = implode("\n", array_filter([$result['field_prompt'], $result['category_prompt']]));

        // A layer may name a provider that has no API key yet
        if (!$this->settings->providerReady($result['provider'])) {
            $result['provider']            = $this->settings->defaultProvider();
            $result['sources']['provider'] = 'default';
        }

        return $result;
    }

    /**
     * @param  array<int, array<string, mixed>>  $fieldRules
     *
     * @return array<string, mixed>|null
     */
    public function matchField(string $fieldKey, array $fieldRules): ?array
    {
        if ($fieldKey === '') {
            return null;
        }

        foreach ($fieldRules as $rule) {
            if (\in_array(strtolower($fieldKey), array_map('strtolower', $rule['fields']), true)) {
                return $rule;
            }
        }

        return null;
    }

    /**
     * @param  array<int, array{id: int, title: string}>  $path      The category first, then each parent up to the root
     * @param  array<int, array<string, mixed>>           $rules
     * @param  string                                     $fieldKey  The image field, for rules that apply to some fields only
     *
     * @return array<string, mixed>|null
     */
    public function match(array $path, array $rules, string $fieldKey = ''): ?array
    {
        $fieldKey = strtolower($fieldKey);

        foreach ($path as $depth => $category) {
            $forAnyField = null;

            foreach ($rules as $rule) {
                if (!\in_array((int) $category['id'], $rule['catids'], true)) {
                    continue;
                }

                if ($depth > 0 && !$rule['include_children']) {
                    continue;
                }

                $rule['_category_title'] = (string) $category['title'];
                $fields                  = array_map('strtolower', $rule['fields'] ?? []);

                // A rule written for this field says more than one written for every field
                if ($fields && $fieldKey !== '' && \in_array($fieldKey, $fields, true)) {
                    return $rule;
                }

                if (!$fields && $forAnyField === null) {
                    $forAnyField = $rule;
                }
            }

            if ($forAnyField !== null) {
                return $forAnyField;
            }
        }

        return null;
    }

    /**
     * Take over the values a layer sets. What it leaves empty stays as it was.
     *
     * @param  array<string, mixed>  $result
     * @param  array<string, mixed>  $layer
     *
     * @return array<string, mixed>
     */
    private static function overlay(array $result, array $layer, string $source): array
    {
        foreach (self::OVERRIDABLE as $key) {
            if (!empty($layer[$key])) {
                $result[$key]            = $layer[$key];
                $result['sources'][$key] = $source;
            }
        }

        return $result;
    }

    /**
     * @return array<int, array{id: int, title: string}>
     */
    private function categoryPath(int $catid): array
    {
        if ($this->db === null) {
            return [];
        }

        try {
            $db    = $this->db;
            $query = $db->getQuery(true)
                ->select([$db->quoteName('parent.id'), $db->quoteName('parent.title')])
                ->from($db->quoteName('#__categories', 'node'))
                ->join(
                    'INNER',
                    $db->quoteName('#__categories', 'parent'),
                    $db->quoteName('node.lft') . ' BETWEEN ' . $db->quoteName('parent.lft') . ' AND ' . $db->quoteName('parent.rgt')
                )
                ->where($db->quoteName('node.id') . ' = :catid')
                ->where($db->quoteName('parent.id') . ' > 1')
                ->order($db->quoteName('parent.lft') . ' DESC')
                ->bind(':catid', $catid, ParameterType::INTEGER);

            $rows = $db->setQuery($query)->loadAssocList() ?: [];
        } catch (\Throwable $e) {
            return [];
        }

        return array_map(static fn ($row) => ['id' => (int) $row['id'], 'title' => (string) $row['title']], $rows);
    }
}
