<?php

declare(strict_types=1);

namespace Modules\Files\Contracts;

use Modules\Files\Domain\MediaVisibility;

/**
 * Whether the two buckets are actually two buckets — answered by the module that owns them.
 *
 * TAB 18's production checklist requires *"separate private and public object-storage keys"*, and
 * the operational preflight that reports it lives in `Audit`. It cannot resolve the disk names
 * itself: `PublicMediaHasOneWriterTest` forbids any file outside `Files` from naming the public
 * disk, and it is right to — a rule enforced at every call site holds only until somebody adds a
 * call site. So the question is asked of `Files` rather than about it.
 *
 * ## What separation buys, and why unset is not separated
 *
 * A shared credential collapses the blast radius distinction the two buckets exist to create: a
 * leaked publishing key would read every citizen document rather than some already-published
 * images. A shared bucket does the same one layer down.
 *
 * `null` means one or both are unconfigured, and it is deliberately not `true`. Two absent keys are
 * not two different keys, and reporting that as separation would print a verified guarantee on
 * exactly the deployment where the variables were forgotten.
 *
 * **No credential is returned or logged** — only whether two values the caller never sees are the
 * same. Article 5.6: secrets live in the environment, and a posture report is not an exception.
 */
final class StoragePosture
{
    /** True when they differ, false when identical, null when either is unconfigured. */
    public static function keysAreSeparate(): ?bool
    {
        return self::differ('key');
    }

    /** True when they differ, false when identical, null when either is unconfigured. */
    public static function bucketsAreSeparate(): ?bool
    {
        return self::differ('bucket');
    }

    /** The private disk must not carry a public base URL, which is what makes a document linkable. */
    public static function privateDiskHasNoPublicUrl(): bool
    {
        return self::setting(MediaVisibility::Private, 'url') === null;
    }

    public static function privateDiskIsPrivate(): bool
    {
        return self::setting(MediaVisibility::Private, 'visibility') === 'private';
    }

    private static function differ(string $key): ?bool
    {
        $private = self::setting(MediaVisibility::Private, $key);
        $public = self::setting(MediaVisibility::Public, $key);

        if ($private === null || $public === null || $private === '' || $public === '') {
            return null;
        }

        return $private !== $public;
    }

    private static function setting(MediaVisibility $visibility, string $key): ?string
    {
        $value = config('filesystems.disks.'.$visibility->disk().'.'.$key);

        return is_string($value) ? $value : null;
    }
}
