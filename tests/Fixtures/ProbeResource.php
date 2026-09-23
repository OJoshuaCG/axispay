<?php

declare(strict_types=1);

namespace Tests\Fixtures;

use App\Modules\Shared\Database\HasPrefixedId;
use App\Modules\Shared\Database\HasUlidPrimaryKey;
use App\Modules\Shared\Ids\ResourceType;
use Illuminate\Database\Eloquent\Model;

/**
 * Test-only model that exercises the ULID primary key and prefixed ID traits
 * against a throwaway table created by the test itself.
 *
 * @property string $id
 * @property string $label
 */
final class ProbeResource extends Model
{
    use HasPrefixedId;
    use HasUlidPrimaryKey;

    public const string TABLE = 'shared_probe_resources';

    protected $table = self::TABLE;

    protected $guarded = [];

    public static function resourceType(): ResourceType
    {
        return ResourceType::PaymentLink;
    }
}
