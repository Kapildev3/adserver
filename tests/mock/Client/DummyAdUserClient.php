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

declare(strict_types = 1);

namespace Adshares\Mock\Client;

use Adshares\Common\Application\Dto\Taxonomy;
use Adshares\Common\Application\Factory\TaxonomyFactory;
use Adshares\Common\Application\Service\AdUser;
use Adshares\Supply\Application\Dto\ImpressionContext;
use Adshares\Supply\Application\Dto\UserContext;
use RuntimeException;
use function base_path;
use function file_get_contents;
use function GuzzleHttp\json_decode;

final class DummyAdUserClient implements AdUser
{
    public function fetchTargetingOptions(): Taxonomy
    {
        $path = base_path('tests/app/targeting_schema.json');
        $var = file_get_contents($path);
        $taxonomy = json_decode($var, true);

        return TaxonomyFactory::fromArray($taxonomy);
    }

    public function getUserContext(ImpressionContext $context): UserContext
    {
        throw new RuntimeException('Method getUserContext() not implemented');
    }
}
