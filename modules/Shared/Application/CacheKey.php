<?php

declare(strict_types=1);

namespace Modules\Shared\Application;

use Illuminate\Support\Facades\Cache;

/**
 * Every application cache key is built here (ADR 0036 §4).
 *
 * **THE ACCEPTANCE CRITERION IS THAT A CACHE KEY CANNOT LEAK ONE USER'S DATA TO ANOTHER**, and the
 * way that fails is never a decision somebody makes. It is a key like `programs.list` written by
 * somebody caching what is genuinely a public catalogue — and then, six months later, the query
 * behind it gains a permission-dependent branch so an admin sees drafts. The key does not change,
 * because nothing about adding a branch to a query suggests revisiting a cache key, and from then
 * on whoever warms the cache decides what everybody sees.
 *
 * So this class offers exactly two ways to build a key and no third:
 *
 *  * {@see public()} — for data that is **identical for every caller**, including anonymous ones;
 *  * {@see forActor()} — for data that depends on who is asking, keyed by subject **and** by a
 *    hash of their effective authority.
 *
 * There is no `Cache::remember('whatever')` in a module. `CacheConventionsTest` fails the build if
 * one appears, because the discipline is worthless if it can be bypassed by whoever is in a hurry.
 *
 * ── WHY THE AUTHORITY HASH ────────────────────────────────────────────────────────────
 *
 * Keying by subject alone is not enough. A caseworker's barangay grant can be widened or withdrawn
 * mid-shift (ADR 0012), and a scope that narrowed at 10am must not still be serving 9am's rows at
 * 10:05. The hash covers permissions and scope, so a change to either produces a different key and
 * the old entry is simply never read again — which is a cheaper and more reliable invalidation than
 * finding and forgetting every key an actor might hold.
 */
final class CacheKey
{
    /** Everything this application caches is namespaced, so a flush can be scoped. */
    private const PREFIX = 'lguids';

    /**
     * A key for data that is the same for everybody.
     *
     * **USE THIS ONLY WHEN AN ANONYMOUS CALLER WOULD GET THE IDENTICAL ANSWER.** If the query
     * behind it branches on a permission — even to add one field — it is not public, and the right
     * key is {@see forActor()}. The published service catalogue is public; the same endpoint's
     * response for an admin, which includes drafts, is not.
     *
     * @param  list<string|int>  $parts
     */
    public static function public(string $namespace, array $parts = []): string
    {
        return implode(':', array_merge([self::PREFIX, 'public', $namespace], self::normalise($parts)));
    }

    /**
     * A key bound to one actor and to the authority they held when it was written.
     *
     * A guest gets a stable key of their own rather than sharing the public one, so an endpoint
     * that returns *something* to anonymous callers and *more* to authenticated ones cannot serve
     * the second answer to the first.
     *
     * @param  list<string|int>  $parts
     */
    public static function forActor(ActorContext $actor, string $namespace, array $parts = []): string
    {
        return implode(':', array_merge([
            self::PREFIX,
            'actor',
            $actor->subjectId ?? 'guest',
            /*
             * The authority fingerprint. A grant widened or withdrawn changes this, so every key
             * the actor previously held becomes unreachable — invalidation by construction rather
             * than by remembering to forget.
             */
            self::authorityFingerprint($actor),
            $namespace,
        ], self::normalise($parts)));
    }

    /**
     * A key derived from an opaque token the caller presents.
     *
     * THE THIRD SHAPE, and it exists because Identity's MFA challenge is neither of the other two:
     * it is not public, and it is not keyed by an actor — at the moment it is read **nobody is
     * authenticated yet**, which is the whole point of a challenge.
     *
     * The token is hashed, so the cache store never holds the value a caller presents. A store
     * holding it verbatim would mean anyone who could read the cache — an operator debugging, a
     * memory dump, a misconfigured Redis — could complete somebody else's second factor.
     */
    public static function forOpaqueToken(string $namespace, string $token): string
    {
        return implode(':', [self::PREFIX, 'token', $namespace, hash('sha256', $token)]);
    }

    /**
     * Caches a public value.
     *
     * @template T
     *
     * @param  list<string|int>  $parts
     * @param  \Closure(): T  $resolve
     * @return T
     */
    public static function rememberPublic(string $namespace, array $parts, int $seconds, \Closure $resolve): mixed
    {
        return Cache::remember(self::public($namespace, $parts), $seconds, $resolve);
    }

    /**
     * Caches a value that depends on who is asking.
     *
     * @template T
     *
     * @param  list<string|int>  $parts
     * @param  \Closure(): T  $resolve
     * @return T
     */
    public static function rememberForActor(
        ActorContext $actor,
        string $namespace,
        array $parts,
        int $seconds,
        \Closure $resolve,
    ): mixed {
        return Cache::remember(self::forActor($actor, $namespace, $parts), $seconds, $resolve);
    }

    /**
     * Drops every public entry in a namespace.
     *
     * A cache tag would be tidier and is not used: tags require a tag-aware store, and this
     * application must run on the `array` and `database` stores in tests and on a small
     * deployment. A convention that only works on Redis is a convention that breaks the day
     * somebody runs the test suite without it.
     */
    public static function flushPublic(string $namespace): void
    {
        Cache::forget(self::public($namespace));
    }

    /**
     * A short, stable fingerprint of what this actor may currently do.
     *
     * Sorted before hashing, because permission order is not significant and an unsorted list
     * would produce a different key for the same authority — every request a cache miss, which
     * looks like a performance problem rather than a correctness one and gets "fixed" by dropping
     * the fingerprint.
     */
    private static function authorityFingerprint(ActorContext $actor): string
    {
        $permissions = $actor->permissions;
        sort($permissions);

        $roles = $actor->roles;
        sort($roles);

        return substr(hash('sha256', (string) json_encode([
            'permissions' => $permissions,
            'roles' => $roles,
            'scope' => $actor->scope->forAudit(),
        ])), 0, 16);
    }

    /**
     * @param  list<string|int>  $parts
     * @return list<string>
     */
    private static function normalise(array $parts): array
    {
        return array_map(
            // Anything that could contain a separator is hashed, so a value holding a colon
            // cannot forge a different key by splitting one.
            static function (string|int $part): string {
                $value = (string) $part;

                return preg_match('/^[A-Za-z0-9_-]{1,64}$/', $value) === 1
                    ? $value
                    : substr(hash('sha256', $value), 0, 16);
            },
            $parts,
        );
    }
}
