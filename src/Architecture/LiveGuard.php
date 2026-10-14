<?php

declare(strict_types=1);

namespace App\Architecture;

final class LiveGuard
{
    /** @param list<string> $arguments */
    public static function requested(array $arguments): bool
    {
        foreach ($arguments as $index => $argument) {
            if (preg_match('/^--(?:testsuite|group)=(.*)$/D', $argument, $match)) {
                $value = $match[1];
            } elseif (in_array($argument, ['--testsuite','--group'], true)) {
                $value = $arguments[$index + 1] ?? '';
            } else {
                continue;
            }
            if (in_array('live', explode(',', $value), true)) {
                return true;
            }
        }
        return false;
    }
    /** @param array<string,string|false> $environment */
    public static function assertAllowed(array $environment): void
    {
        foreach ($environment as $name => $value) {
            if (str_ends_with($name, '_TARGET') && $value === 'prod') {
                throw new \RuntimeException('Live tests refuse a prod target.');
            }
        }
        if (($environment['CONNECTORS_ENABLED'] ?? '0') !== '1') {
            throw new \RuntimeException('Live tests require CONNECTORS_ENABLED=1.');
        }
    }
}
