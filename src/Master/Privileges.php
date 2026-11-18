<?php

declare(strict_types=1);

namespace PhpServer\Master;

use PhpServer\Exception\ProcessException;
use PhpServer\Log\LoggerInterface;

/**
 * Drops root down to the configured user and group.
 *
 * A process that is not root skips this. A configured name is not an error
 * in that case: the master still starts.
 *
 * @internal
 */
final class Privileges
{
    public static function drop(string $user, string $group, LoggerInterface $logger): void
    {
        if (posix_geteuid() !== 0) {
            if ($user !== '' || $group !== '') {
                $logger->info('skipping setuid because the process is not root');
            }

            return;
        }
        if ($group !== '') {
            $gid = self::groupId($group);
            if (!posix_setgid($gid)) {
                throw new ProcessException('setgid failed for group ' . $group);
            }
        }
        if ($user !== '') {
            $uid = self::userId($user);
            if (!posix_setuid($uid)) {
                throw new ProcessException('setuid failed for user ' . $user);
            }
        }
    }

    private static function userId(string $value): int
    {
        if (preg_match('/^(0|[1-9][0-9]*)$/', $value) === 1) {
            return (int) $value;
        }
        $info = posix_getpwnam($value);
        if ($info === false) {
            throw new ProcessException('unknown user ' . $value);
        }

        return $info['uid'];
    }

    private static function groupId(string $value): int
    {
        if (preg_match('/^(0|[1-9][0-9]*)$/', $value) === 1) {
            return (int) $value;
        }
        $info = posix_getgrnam($value);
        if ($info === false) {
            throw new ProcessException('unknown group ' . $value);
        }

        return $info['gid'];
    }
}
