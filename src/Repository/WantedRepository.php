<?php

declare(strict_types=1);

/**
 * vim:set softtabstop=4 shiftwidth=4 expandtab:
 *
 * LICENSE: GNU Affero General Public License, version 3 (AGPL-3.0-or-later)
 * Copyright Ampache.org, 2001-2023
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU Affero General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or
 * (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU Affero General Public License for more details.
 *
 * You should have received a copy of the GNU Affero General Public License
 * along with this program.  If not, see <https://www.gnu.org/licenses/>.
 *
 */

namespace Ampache\Repository;

use Ampache\Module\Database\DatabaseConnectionInterface;
use Ampache\Repository\Model\database_object;
use Ampache\Repository\Model\User;

/**
 * Manages database access related to Wanted-items/recommendations
 *
 * Tables: `wanted`
 *
 * @phpstan-import-type DatabaseRow from WantedRepositoryInterface
 */
final class WantedRepository implements WantedRepositoryInterface
{
    private DatabaseConnectionInterface $connection;

    public function __construct(
        DatabaseConnectionInterface $connection
    ) {
        $this->connection = $connection;
    }

    /**
     * Get wanted list.
     *
     * @return list<int>
     */
    public function findAll(?User $user = null): array
    {
        $sql       = 'SELECT `id` FROM `wanted`';
        $params    = [];
        $wantedIds = [];

        if ($user !== null) {
            $sql .= ' WHERE `user` = ?';
            $params[] = $user->getId();
        }

        $result = $this->connection->query(
            $sql,
            $params
        );

        while ($rowId = $result->fetchColumn()) {
            $wantedIds[] = (int) $rowId;
        }

        return $wantedIds;
    }

    /**
     * Check if a release mbid is already marked as wanted
     */
    public function find(string $musicbrainzId, User $user): ?int
    {
        $wantedId = $this->connection->fetchOne(
            'SELECT `id` FROM `wanted` WHERE `mbid` = ? AND `user` = ? LIMIT 1',
            [$musicbrainzId, $user->getId()]
        );

        if ($wantedId === false) {
            return null;
        }

        return (int) $wantedId;
    }

    /**
     * Delete wanted release.
     */
    public function deleteByMusicbrainzId(
        string $musicbrainzId,
        ?User $user = null
    ): void {
        $sql    = 'DELETE FROM `wanted` WHERE `mbid` = ?';
        $params = [$musicbrainzId];

        if ($user !== null) {
            $sql .= ' AND `user` = ?';
            $params[] = $user->getId();
        }

        $this->connection->query(
            $sql,
            $params
        );
    }

    /**
     * Get accepted wanted release count.
     */
    public function getAcceptedCount(): int
    {
        return (int) $this->connection->fetchOne(
            'SELECT COUNT(`id`) AS `wanted_cnt` FROM `wanted` WHERE `accepted` = 1'
        );
    }

    /**
     * retrieves the info from the database and puts it in the cache
     *
     * @return null|DatabaseRow
     */
    public function getById(int $wantedId): ?array
    {
        if (database_object::is_cached('wanted', $wantedId)) {
            $row = database_object::get_from_cache('wanted', $wantedId);
        } else {
            $row = $this->connection->fetchRow(
                'SELECT * FROM `wanted` WHERE `id` = ?',
                [$wantedId]
            );

            if ($row === false) {
                return null;
            }

            database_object::add_to_cache('wanted', $wantedId, $row);
        }

        /** @var DatabaseRow $row */
        return $row;
    }
}
