<?php

namespace App\Helpers;

use Illuminate\Support\Facades\Cache;

/**
 * Redis-compatible wrapper backed by Laravel Cache.
 * Replaces direct Redis:: calls so no Redis server is required.
 */
class RedisCache
{
    public static function get(string $key): mixed
    {
        return Cache::get($key);
    }

    /**
     * @param  mixed  ...$args  Supports: set(key, value) and set(key, value, 'EX', ttl)
     */
    public static function set(string $key, mixed $value, mixed $option = null, int $ttl = 300): bool
    {
        $seconds = (strtoupper((string) $option) === 'EX') ? $ttl : 300;

        Cache::put($key, $value, $seconds);

        return true;
    }

    public static function setex(string $key, int $ttl, mixed $value): bool
    {
        Cache::put($key, $value, $ttl);

        return true;
    }

    public static function del(string ...$keys): int
    {
        foreach ($keys as $key) {
            Cache::forget($key);
        }

        return count($keys);
    }
}
