<?php

declare(strict_types=1);

namespace Modules\Files\Providers;

use Illuminate\Support\ServiceProvider;

/**
 * Files owns stored objects, the documents presented against them, and access to both.
 *
 * IT HAS NO ROUTES, AND THAT IS THE DESIGN. Every other module in this system publishes
 * endpoints; this one publishes application services and nothing else, because it cannot answer
 * the only question that matters at an HTTP boundary — *may this caller see this document?*
 * That answer belongs to whichever module owns the record the document hangs off. Welfare knows
 * whether a caseworker is in the right barangay for a case; a file store knows only that it
 * holds bytes.
 *
 * So the shape is: the owning module authorises, then calls in. Calling
 * `DocumentAccess::issue()` IS the assertion that a check has already happened, which is why it
 * takes an actor rather than a permission. A module that forgets to check has written the
 * assertion falsely — and that is a reviewable line in its controller rather than a silent
 * absence somewhere else.
 *
 * The alternative — a generic `GET /api/v1/files/{id}` guarded by a permission — was rejected.
 * A single permission cannot express "this caseworker, this case, this barangay", so it would
 * inevitably resolve to "any staff member may read any document", which is precisely the
 * enumeration this system is built to prevent.
 *
 * Depends only on `Shared`. It must stay that way: every module above may store documents, and
 * a dependency in this direction would close a cycle with the first one that does.
 */
final class FilesServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        //
    }

    public function boot(): void
    {
        //
    }
}
