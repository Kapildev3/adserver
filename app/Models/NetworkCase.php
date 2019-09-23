<?php
/**
 * Copyright (c) 2018-2019 Adshares sp. z o.o.
 *
 * This file is part of AdServer
 *
 * AdServer is free software: you can redistribute and/or modify it
 * under the terms of the GNU General Public License as published
 * by the Free Software Foundation, either version 3 of the License,
 * or (at your option) any later version.
 *
 * AdServer is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty
 * of MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.
 * See the GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License
 * along with AdServer. If not, see <https://www.gnu.org/licenses/>
 */

namespace Adshares\Adserver\Models;

use Adshares\Adserver\Models\Traits\AutomateMutators;
use Adshares\Adserver\Models\Traits\BinHex;
use Adshares\Adserver\Models\Traits\JsonValue;
use Adshares\Adserver\Utilities\DomainReader;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Query\JoinClause;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use function array_map;
use function hex2bin;

/**
 * @property int id
 * @property Carbon created_at
 * @property string case_id
 * @property int network_impression_id
 * @property string publisher_id
 * @property string site_id
 * @property string zone_id
 * @property string domain
 * @property string campaign_id
 * @property string banner_id
 * @property NetworkCaseClick|null networkCaseClick
 * @property Collection networkCasePayments
 * @property NetworkImpression networkImpression
 * @mixin Builder
 */
class NetworkCase extends Model
{
    use AutomateMutators;
    use BinHex;
    use JsonValue;

    /** @var array */
    protected $fillable = [
        'case_id',
        'publisher_id',
        'site_id',
        'zone_id',
        'domain',
        'campaign_id',
        'banner_id',
    ];

    /** @var array */
    protected $visible = [];

    /**
     * The attributes that use some Models\Traits with mutator settings automation
     *
     * @var array
     */
    protected $traitAutomate = [
        'case_id' => 'BinHex',
        'publisher_id' => 'BinHex',
        'site_id' => 'BinHex',
        'zone_id' => 'BinHex',
        'campaign_id' => 'BinHex',
        'banner_id' => 'BinHex',

        /** Mutators from @see NetworkImpression::class */
        'impression_id' => 'BinHex',
        'tracking_id' => 'BinHex',
        'user_id' => 'BinHex',
        'context' => 'JsonValue',
        'user_data' => 'JsonValue',
    ];

    public static function create(
        string $caseId,
        string $publisherId,
        string $siteId,
        string $zoneId,
        string $bannerId
    ): ?NetworkCase {
        if (self::fetchByCaseId($caseId)) {
            return null;
        }

        $banner = NetworkBanner::fetchByPublicIdWithCampaign($bannerId);
        if (!$banner) {
            return null;
        }

        $campaign = $banner->getAttribute('campaign');
        if (!$campaign) {
            return null;
        }

        return new self(
            [
                'case_id' => $caseId,
                'publisher_id' => $publisherId,
                'site_id' => $siteId,
                'zone_id' => $zoneId,
                'domain' => DomainReader::domain($campaign->landing_url ?? ''),
                'campaign_id' => $campaign->uuid ?? null,
                'banner_id' => $bannerId,
            ]
        );
    }

    public static function fetchByCaseId(string $caseId): ?NetworkCase
    {
        return self::where('case_id', hex2bin($caseId))->first();
    }

    public static function fetchByCaseIds(array $caseIds): Collection
    {
        $binCaseIds = array_map(
            function (string $item) {
                return hex2bin($item);
            },
            $caseIds
        );

        return self::whereIn('case_id', $binCaseIds)->get()->keyBy('case_id');
    }

    public static function fetchCasesToExport(
        int $idFrom,
        int $impressionIdMax,
        int $limit,
        int $offset
    ): Collection {
        return NetworkCase::from('network_cases AS c')->select(
            [
                'c.id AS id',
                'c.created_at AS created_at',
                'case_id',
                'publisher_id',
                'zone_id',
                'campaign_id',
                'banner_id',
                'impression_id',
                'tracking_id',
                'user_id',
                'context',
            ]
        )->join(
            'network_impressions AS i',
            function (JoinClause $join) {
                $join->on('c.network_impression_id', '=', 'i.id');
            }
        )->where('c.id', '>=', $idFrom)->where('i.id', '<=', $impressionIdMax)->take($limit)->skip($offset)->get();
    }

    public function networkCaseClick(): HasOne
    {
        return $this->hasOne(NetworkCaseClick::class);
    }

    public function networkCasePayments(): HasMany
    {
        return $this->hasMany(NetworkCasePayment::class);
    }

    public function networkImpression(): BelongsTo
    {
        return $this->belongsTo(NetworkImpression::class);
    }
}