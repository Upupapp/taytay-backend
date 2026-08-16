<?php

declare(strict_types=1);

namespace Modules\ServiceCatalog\Infrastructure\Eloquent;

use Illuminate\Database\Eloquent\Model;

/**
 * One thing a provider actually does.
 *
 * Open vocabulary — no fixed list survives contact with what partner agencies really offer — but
 * a row, because "who does bill reduction" is the question the directory exists to answer, and
 * inside a JSON blob that is a table scan and a LIKE against punctuation.
 */
final class ServiceProviderService extends Model
{
    protected $table = 'service_provider_services';

    protected $guarded = ['id'];

    public $timestamps = false;
}
