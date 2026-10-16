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

use Joomla\CMS\Language\Text;
use Joomla\Database\DatabaseInterface;
use Joomla\Session\SessionInterface;

/**
 * What one editor has done lately: how many images they generated and applied, whether
 * one is being generated right now, and which files they applied.
 *
 * It is kept with the user in the database, not in the session. Signing in again or
 * sending several requests at once therefore does not get around the limits.
 */
final class UsageLedger
{
    public const KEY = 'gssaiimage.usage';

    private const HOUR         = 3600;
    private const DAY          = 86400;
    private const MAX_APPLIED  = 60;
    private const SESSION_SLOT = 'plg_system_gssaiimage.usage';

    public function __construct(
        private readonly DatabaseInterface $db,
        private readonly int $userId,
        private readonly ?SessionInterface $session = null
    ) {
    }

    /**
     * Count one action against the hourly limit, or refuse it.
     *
     * @param  string  $bucket  "generate" or "apply"
     * @param  int     $limit   Actions per hour, 0 for no limit
     */
    public function count(string $bucket, int $limit): void
    {
        if ($limit <= 0) {
            return;
        }

        $this->mutate(static function (array &$state) use ($bucket, $limit): void {
            if (\count($state[$bucket]) >= $limit) {
                throw new AiImageException(Text::sprintf('PLG_SYSTEM_GSSAIIMAGE_ERR_RATE_LIMIT', $limit), 'rate_limit');
            }

            $state[$bucket][] = time();
        });
    }

    /**
     * Mark that an image is being generated. One editor generates one image at a time.
     *
     * @param  int  $expires  Seconds after which a mark left behind by a crashed request no longer counts
     */
    public function start(int $expires): void
    {
        $this->mutate(static function (array &$state) use ($expires): void {
            if ($state['running'] > time()) {
                throw new AiImageException(Text::_('PLG_SYSTEM_GSSAIIMAGE_ERR_BUSY'), 'busy');
            }

            $state['running'] = time() + max(30, $expires);
        });
    }

    public function finish(): void
    {
        try {
            $this->mutate(static function (array &$state): void {
                $state['running'] = 0;
            });
        } catch (\Throwable $e) {
            // The mark expires on its own
        }
    }

    /**
     * Note a file this editor applied to a field.
     */
    public function remember(string $path): void
    {
        if ($path === '') {
            return;
        }

        $this->mutate(static function (array &$state) use ($path): void {
            $state['applied'][$path] = time();
        });
    }

    /**
     * Did this editor apply the file, within the last day?
     */
    public function owns(string $path): bool
    {
        return $path !== '' && (bool) $this->mutate(static fn (array &$state): bool => isset($state['applied'][$path]));
    }

    public function forget(string $path): void
    {
        $this->mutate(static function (array &$state) use ($path): void {
            unset($state['applied'][$path]);
        });
    }

    /**
     * Read the record, change it, and write it back as one step.
     *
     * @param  callable(array<string, mixed>): mixed  $change  Receives the record by reference
     */
    private function mutate(callable $change): mixed
    {
        for ($attempt = 1; $attempt <= 2; $attempt++) {
            try {
                return $this->inDatabase($change);
            } catch (AiImageException $e) {
                throw $e;
            } catch (\Throwable $e) {
                // Two first requests of one user can collide on the insert. The second try finds the row.
            }
        }

        // No database record to be had: the session is weaker, but better than no limit
        return $this->inSession($change);
    }

    private function inDatabase(callable $change): mixed
    {
        $db    = $this->db;
        $table = $db->quoteName('#__user_profiles');
        $where = ' WHERE ' . $db->quoteName('user_id') . ' = ' . $this->userId
            . ' AND ' . $db->quoteName('profile_key') . ' = ' . $db->quote(self::KEY);

        $db->transactionStart();

        try {
            // The lock makes requests of the same user wait for each other here
            $raw    = $db->setQuery('SELECT ' . $db->quoteName('profile_value') . ' FROM ' . $table . $where . ' FOR UPDATE')->loadResult();
            $state  = self::tidy(\is_string($raw) ? json_decode($raw, true) : null);
            $result = $change($state);
            $json   = $db->quote((string) json_encode(self::tidy($state), JSON_UNESCAPED_SLASHES));

            if ($raw === null) {
                $db->setQuery(
                    'INSERT INTO ' . $table . ' (' . $db->quoteName('user_id') . ', ' . $db->quoteName('profile_key') . ', '
                    . $db->quoteName('profile_value') . ', ' . $db->quoteName('ordering') . ') VALUES ('
                    . $this->userId . ', ' . $db->quote(self::KEY) . ', ' . $json . ', 0)'
                )->execute();
            } else {
                $db->setQuery('UPDATE ' . $table . ' SET ' . $db->quoteName('profile_value') . ' = ' . $json . $where)->execute();
            }

            $db->transactionCommit();

            return $result;
        } catch (\Throwable $e) {
            $db->transactionRollback();

            throw $e;
        }
    }

    private function inSession(callable $change): mixed
    {
        if ($this->session === null) {
            $state = self::tidy(null);

            return $change($state);
        }

        $state  = self::tidy($this->session->get(self::SESSION_SLOT));
        $result = $change($state);

        $this->session->set(self::SESSION_SLOT, self::tidy($state));

        return $result;
    }

    /**
     * The record in its expected shape, without what has expired.
     *
     * @param  mixed  $state
     *
     * @return array{generate: int[], apply: int[], running: int, applied: array<string, int>}
     */
    private static function tidy($state): array
    {
        $state = \is_array($state) ? $state : [];
        $now   = time();
        $clean = ['generate' => [], 'apply' => [], 'running' => 0, 'applied' => []];

        foreach (['generate', 'apply'] as $bucket) {
            foreach ((array) ($state[$bucket] ?? []) as $time) {
                if ((int) $time > $now - self::HOUR) {
                    $clean[$bucket][] = (int) $time;
                }
            }
        }

        $clean['running'] = (int) ($state['running'] ?? 0) > $now ? (int) $state['running'] : 0;

        foreach ((array) ($state['applied'] ?? []) as $path => $time) {
            if ((int) $time > $now - self::DAY && \is_string($path)) {
                $clean['applied'][$path] = (int) $time;
            }
        }

        // Newest last. Keep the record small.
        asort($clean['applied']);
        $clean['applied'] = \array_slice($clean['applied'], -self::MAX_APPLIED, null, true);

        return $clean;
    }
}
