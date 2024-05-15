<?php

declare(strict_types=0);

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

namespace Ampache\Repository\Model;

use Ampache\Config\AmpConfig;
use Ampache\Module\Database\Query\AlbumDiskQuery;
use Ampache\Module\Database\Query\AlbumQuery;
use Ampache\Module\Database\Query\ArtistQuery;
use Ampache\Module\Database\Query\BroadcastQuery;
use Ampache\Module\Database\Query\CatalogQuery;
use Ampache\Module\Database\Query\ClipQuery;
use Ampache\Module\Database\Query\DemocraticQuery;
use Ampache\Module\Database\Query\FollowerQuery;
use Ampache\Module\Database\Query\LabelQuery;
use Ampache\Module\Database\Query\LicenseQuery;
use Ampache\Module\Database\Query\LiveStreamQuery;
use Ampache\Module\Database\Query\MovieQuery;
use Ampache\Module\Database\Query\PersonalVideoQuery;
use Ampache\Module\Database\Query\PlaylistLocalplayQuery;
use Ampache\Module\Database\Query\PlaylistMediaQuery;
use Ampache\Module\Database\Query\PlaylistQuery;
use Ampache\Module\Database\Query\PlaylistSearchQuery;
use Ampache\Module\Database\Query\PodcastEpisodeQuery;
use Ampache\Module\Database\Query\PodcastQuery;
use Ampache\Module\Database\Query\PvmsgQuery;
use Ampache\Module\Database\Query\QueryInterface;
use Ampache\Module\Database\Query\ShareQuery;
use Ampache\Module\Database\Query\ShoutboxQuery;
use Ampache\Module\Database\Query\SmartplaylistQuery;
use Ampache\Module\Database\Query\SongPreviewQuery;
use Ampache\Module\Database\Query\SongQuery;
use Ampache\Module\Database\Query\TagQuery;
use Ampache\Module\Database\Query\TvshowEpisodeQuery;
use Ampache\Module\Database\Query\TvshowQuery;
use Ampache\Module\Database\Query\TvshowSeasonQuery;
use Ampache\Module\Database\Query\UserQuery;
use Ampache\Module\Database\Query\VideoQuery;
use Ampache\Module\Database\Query\WantedQuery;
use Ampache\Module\System\AmpError;
use Ampache\Module\System\Core;
use Ampache\Module\System\Dba;

/**
 * Query Class
 *
 * This handles all of the sql/filtering for the Ampache database
 * The Search and Query classes do the same thing different ways.
 * It would be good to merge the classes (may not be possible now)
 */
class Query
{
    private const SORT_STATE = [
        'year' => 'ASC',
        'original_year' => 'ASC',
        'last_update' => 'ASC',
        'rating' => 'ASC',
        'song_count' => 'ASC',
        'total_count' => 'ASC',
        'total_skip' => 'ASC',
    ];

    /**
     * @var int|string $id
     */
    public $id;

    /**
     * @var int $catalog
     */
    public $catalog;

    /** @var int|null $user_id */
    public $user_id = null;

    /**
     * @var array $_state
     */
    protected $_state = [
        'album_artist' => false,
        // Used by $browse->set_type() to filter artists
        'base' => null,
        'custom' => false,
        'extended_key_name' => null,
        'filter' => [],
        'grid_view' => true,
        'group' => [],
        // HAVING is not currently used in Query SQL
        'having' => '',
        'join' => null,
        'mashup' => null,
        'offset' => 0,
        'limit' => 0,
        // Used by $browse->set_type() to filter artists
        'song_artist' => null,
        'select' => [],
        'simple' => false,
        'show_header' => true,
        'sort' => [],
        'start' => 0,
        'static' => false,
        'threshold' => '',
        'title' => null,
        'total' => null,
        'type' => '',
        'update_session' => false,
        'use_alpha' => false,
        'use_filters' => true,
        // Used by $browse to hide the filter box in the sidebar
        'use_pages' => false,
    ];

    /** @var array $_cache */
    protected $_cache;

    /** @var QueryInterface|null $queryType */
    private $queryType = null; // generate sql for the object type (Ampache\Module\Database\Query\*)

    /**
     * constructor
     * This should be called
     * @param int|null $query_id
     * @param bool $cached
     */
    public function __construct($query_id = 0, $cached = true)
    {
        $sid = session_id();

        if (!$cached) {
            $this->id = 'nocache';

            return;
        }

        $this->user_id = (Core::get_global('user') instanceof User)
            ? Core::get_global('user')->id
            : null;

        if ($this->user_id === null) {
            return;
        }

        if ($query_id === 0) {
            $this->reset();
            $data = $this->_serialize($this->_state);

            $sql = 'INSERT INTO `tmp_browse` (`sid`, `data`) VALUES(?, ?)';
            Dba::write($sql, [$sid, $data]);
            $insert_id = Dba::insert_id();
            if (!$insert_id) {
                return;
            }

            $this->id = (int)$insert_id;

            return;
        } else {
            $sql = 'SELECT `data` FROM `tmp_browse` WHERE `id` = ? AND `sid` = ?';

            $db_results = Dba::read($sql, [$query_id, $sid]);
            if ($results = Dba::fetch_assoc($db_results)) {
                $this->id     = (int)$query_id;
                $this->_state = (array)$this->_unserialize($results['data']);

                return;
            }
        }

        AmpError::add('browse', T_('Browse was not found or expired, try reloading the page'));
    }

    /**
     * garbage_collection
     * This cleans old data out of the table
     */
    public static function garbage_collection(): void
    {
        $sql = 'DELETE FROM `tmp_browse` USING `tmp_browse` LEFT JOIN `session` ON `session`.`id` = `tmp_browse`.`sid` WHERE `session`.`id` IS NULL';
        Dba::write($sql);
    }

    /**
     * _serialize
     *
     * Attempts to produce a more compact representation for large result
     * sets by collapsing ranges.
     * @param array $data
     */
    private function _serialize($data): string
    {
        return json_encode($data) ?: '';
    }

    /**
     * _unserialize
     *
     * Reverses serialization.
     * @param string $data
     * @return mixed
     */
    private function _unserialize($data)
    {
        return json_decode((string)$data, true);
    }

    /**
     * set_filter
     * This saves the filter data we pass it.
     * @param string $key
     */
    public function set_filter($key, mixed $value): bool
    {
        switch ($key) {
            case 'tag':
                if (is_array($value)) {
                    $this->_state['filter'][$key] = $value;
                } elseif (is_numeric($value)) {
                    $this->_state['filter'][$key] = [$value];
                } else {
                    $this->_state['filter'][$key] = [];
                }
                break;
            case 'artist':
            case 'album_artist':
            case 'song_artist':
            case 'album_disk':
            case 'catalog':
            case 'podcast':
            case 'album':
            case 'disk':
            case 'hidden':
            case 'gather_type':
            case 'gather_types':
            case 'smartlist':
            case 'user_catalog':
                $this->_state['filter'][$key] = $value;
                break;
            case 'top50':
            case 'enabled':
            case 'disabled':
            case 'label':
            case 'license':
            case 'min_count':
            case 'unplayed':
            case 'rated':
            case 'add_lt':
            case 'add_gt':
            case 'update_lt':
            case 'update_gt':
            case 'catalog_enabled':
            case 'playlist_user':
            case 'year_lt':
            case 'year_lg':
            case 'year_eq':
            case 'season_lt':
            case 'season_lg':
            case 'season_eq':
            case 'user':
            case 'to_user':
                $this->_state['filter'][$key] = (int)($value);
                break;
            case 'exact_match':
            case 'alpha_match':
            case 'regex_match':
            case 'regex_not_match':
            case 'starts_with':
                if ($this->is_static_content()) {
                    return false;
                }

                $this->_state['filter'][$key] = $value;
                if ($key == 'regex_match') {
                    unset($this->_state['filter']['regex_not_match']);
                }

                if ($key == 'regex_not_match') {
                    unset($this->_state['filter']['regex_match']);
                }
                break;
            case 'playlist_type':
                if (isset($this->_state['filter']['playlist_type'])) {
                    $this->_state['filter'][$key] = ($this->_state['filter'][$key] == 1) ? 0 : 1;
                } else {
                    $this->_state['filter'][$key] = 1;
                }
                break;
            default:
                debug_event(self::class, 'IGNORED set_filter ' . $this->get_type() . ': ' . $key, 5);

                return false;
        }

        // ensure joins are set on $this->_state
        $this->_get_filter_sql();

        // If we've set a filter we need to reset the totals
        $this->reset_total();
        $this->set_start(0);

        return true;
    }

    /**
     * reset
     * Reset everything, this should only be called when we are starting
     * fresh
     */
    public function reset(): void
    {
        $this->reset_base();
        $this->reset_filters();
        $this->reset_total();
        $this->reset_join();
        $this->reset_select();
        $this->reset_having();
        $this->set_static_content(false);
        $this->set_is_simple(false);
        $this->set_start(0);
        $this->set_offset(AmpConfig::get('offset_limit', 50));
    }

    /**
     * reset_base
     * this resets the base string
     */
    public function reset_base(): void
    {
        $this->_state['base'] = null;
    }

    /**
     * reset_select
     * This resets the select fields that we've added so far
     */
    public function reset_select(): void
    {
        $this->_state['select'] = [];
    }

    /**
     * reset_having
     * Null out the having clause
     */
    public function reset_having(): void
    {
        $this->_state['having'] = '';
    }

    /**
     * reset_join
     * clears the joins if there are any
     */
    public function reset_join(): void
    {
        $this->_state['join'] = [];
    }

    /**
     * reset_filter
     * This is a wrapper function that resets the filters
     */
    public function reset_filters(): void
    {
        $this->_state['filter'] = [];
    }

    /**
     * reset_total
     * This resets the total for the browse type
     */
    public function reset_total(): void
    {
        $this->_state['total'] = null;
    }

    /**
     * get_filter
     * returns the specified filter value
     */
    public function get_filter(string $key): ?string
    {
        return $this->_state['filter'][$key] ?? null;
    }

    /**
     * get_start
     * This returns the current value of the start
     */
    public function get_start(): int
    {
        return $this->_state['start'];
    }

    /**
     * get_offset
     * This returns the current offset
     */
    public function get_offset(): int
    {
        return $this->_state['offset'] ?? 0;
    }

    /**
     * set_total
     * This sets the total number of objects
     * @param int $total
     */
    public function set_total($total): void
    {
        $this->_state['total'] = $total;
    }

    /**
     * get_total
     * This returns the total number of objects for this current sort type.
     * If it's already cached used it. if they pass us an array then use
     * that.
     * @param array $objects
     */
    public function get_total($objects = null): int
    {
        // If they pass something then just return that
        if (is_array($objects) && !$this->is_simple()) {
            return count($objects);
        }

        // See if we can find it in the cache
        if (is_int($this->_state['total'])) {
            return $this->_state['total'];
        }

        $db_results = Dba::read($this->get_sql(false));
        $num_rows   = Dba::num_rows($db_results);

        $this->_state['total'] = $num_rows;

        return $num_rows;
    }

    /**
     * get_allowed_filters
     * This returns an array of the allowed filters based on the type of
     * object we are working with, this is used to display the 'filter'
     * sidebar stuff.
     * @param string $type
     */
    public static function get_allowed_filters($type): array
    {
        switch ($type) {
            case 'album':
                return AlbumQuery::FILTERS;
            case 'album_disk':
                return AlbumDiskQuery::FILTERS;
            case 'artist':
                return ArtistQuery::FILTERS;
            case 'broadcast':
                return BroadcastQuery::FILTERS;
            case 'catalog':
                return CatalogQuery::FILTERS;
            case 'clip':
                return ClipQuery::FILTERS;
            case 'democratic':
                return DemocraticQuery::FILTERS;
            case 'follower':
                return FollowerQuery::FILTERS;
            case 'label':
                return LabelQuery::FILTERS;
            case 'license':
                return LicenseQuery::FILTERS;
            case 'live_stream':
                return LiveStreamQuery::FILTERS;
            case 'movie':
                return MovieQuery::FILTERS;
            case 'personal_video':
                return PersonalVideoQuery::FILTERS;
            case 'playlist_localplay':
                return PlaylistLocalplayQuery::FILTERS;
            case 'playlist_media':
                return PlaylistMediaQuery::FILTERS;
            case 'playlist_search':
                return PlaylistSearchQuery::FILTERS;
            case 'playlist':
                return PlaylistQuery::FILTERS;
            case 'podcast_episode':
                return PodcastEpisodeQuery::FILTERS;
            case 'podcast':
                return PodcastQuery::FILTERS;
            case 'pvmsg':
                return PvmsgQuery::FILTERS;
            case 'share':
                return ShareQuery::FILTERS;
            case 'shoutbox':
                return ShoutboxQuery::FILTERS;
            case 'smartplaylist':
                return SmartPlaylistQuery::FILTERS;
            case 'song_preview':
                return SongPreviewQuery::FILTERS;
            case 'song':
                return SongQuery::FILTERS;
            case 'tag_hidden':
            case 'tag':
                return TagQuery::FILTERS;
            case 'tvshow_episode':
                return TvshowEpisodeQuery::FILTERS;
            case 'tvshow_season':
                return TvshowSeasonQuery::FILTERS;
            case 'tvshow':
                return TvshowQuery::FILTERS;
            case 'user':
                return UserQuery::FILTERS;
            case 'video':
                return VideoQuery::FILTERS;
            case 'wanted':
                return WantedQuery::FILTERS;
        }

        return [];
    }

    /**
     * set_type
     * This sets the type of object that we want to browse by
     * we do this here so we only have to maintain a single whitelist
     * and if I want to change the location I only have to do it here
     * @param string $type
     * @param string $custom_base
     */
    public function set_type($type, $custom_base = ''): void
    {
        switch ($type) {
            case 'album':
                $this->queryType = new AlbumQuery();
                break;
            case 'album_disk':
                $this->queryType = new AlbumDiskQuery();
                break;
            case 'artist':
                $this->queryType = new ArtistQuery();
                break;
            case 'broadcast':
                $this->queryType = new BroadcastQuery();
                break;
            case 'catalog':
                $this->queryType = new CatalogQuery();
                break;
            case 'clip':
                $this->queryType = new ClipQuery();
                break;
            case 'democratic':
                $this->queryType = new DemocraticQuery();
                break;
            case 'follower':
                $this->queryType = new FollowerQuery();
                break;
            case 'label':
                $this->queryType = new LabelQuery();
                break;
            case 'license':
                $this->queryType = new LicenseQuery();
                break;
            case 'live_stream':
                $this->queryType = new LiveStreamQuery();
                break;
            case 'movie':
                $this->queryType = new MovieQuery();
                break;
            case 'personal_video':
                $this->queryType = new PersonalVideoQuery();
                break;
            case 'playlist_localplay':
                $this->queryType = new PlaylistLocalplayQuery();
                break;
            case 'playlist_media':
                $this->queryType = new PlaylistMediaQuery();
                break;
            case 'playlist_search':
                $this->queryType = new PlaylistSearchQuery();
                break;
            case 'playlist':
                $this->queryType = new PlaylistQuery();
                break;
            case 'podcast_episode':
                $this->queryType = new PodcastEpisodeQuery();
                break;
            case 'podcast':
                $this->queryType = new PodcastQuery();
                break;
            case 'pvmsg':
                $this->queryType = new PvmsgQuery();
                break;
            case 'share':
                $this->queryType = new ShareQuery();
                break;
            case 'shoutbox':
                $this->queryType = new ShoutboxQuery();
                break;
            case 'smartplaylist':
                $this->queryType = new SmartPlaylistQuery();
                break;
            case 'song_preview':
                $this->queryType = new SongPreviewQuery();
                break;
            case 'song':
                $this->queryType = new SongQuery();
                break;
            case 'tag_hidden':
            case 'tag':
                $this->queryType = new TagQuery();
                break;
            case 'tvshow_episode':
                $this->queryType = new TvshowEpisodeQuery();
                break;
            case 'tvshow_season':
                $this->queryType = new TvshowSeasonQuery();
                break;
            case 'tvshow':
                $this->queryType = new TvshowQuery();
                break;
            case 'user':
                $this->queryType = new UserQuery();
                break;
            case 'video':
                $this->queryType = new VideoQuery();
                break;
            case 'wanted':
                $this->queryType = new WantedQuery();
                break;
        }
        if ($this->queryType !== null) {
            // Set it
            $this->_state['type'] = $type;
            $this->_set_base_sql(true, $custom_base);
        }
    }

    /**
     * get_type
     * This returns the type of the browse we currently are using
     */
    public function get_type(): string
    {
        return $this->_state['type'];
    }

    /**
     * set_sort
     * This sets the current sort(s)
     * @param string $sort
     * @param string $order
     */
    public function set_sort($sort, $order = ''): void
    {
        // Don't allow pointless sorts
        if (
            !empty($this->get_type()) &&
            $this->queryType !== null &&
            !in_array($sort, $this->queryType->get_sorts())
        ) {
            debug_event(self::class, 'IGNORED set_sort ' . $this->get_type() . ': ' . $sort, 5);

            return;
        }

        // TODO WHY?!?!
        $this->reset_join();

        // ensure joins are set on $this->_state
        $this->_get_filter_sql();
        $this->_get_sort_sql();

        if ($sort === 'rand') {
            // reset any existing sorts before setting a new one
            $this->_state['sort']         = [];
            $this->_state['sort']['rand'] = $order;

            $this->_resort_objects();

            return;
        }
        if (!empty($order)) {
            $order = ($order == 'DESC')
                ? 'DESC'
                : 'ASC';
        } else {
            // if the sort already exists you want the reverse
            $state = (array_key_exists($sort, $this->_state['sort']))
                ? $this->_state['sort'][$sort]
                : self::SORT_STATE[$sort] ?? 'DESC';
            $order = ($state == 'ASC')
                ? 'DESC'
                : 'ASC';
        }

        // reset any existing sorts before setting a new one
        $this->_state['sort']        = [];
        $this->_state['sort'][$sort] = $order;

        $this->_resort_objects();
    }

    /**
     * set_offset
     * This sets the current offset of this query
     * @param int $offset
     */
    public function set_offset($offset): void
    {
        $this->_state['offset'] = abs($offset);
    }

    /**
     * set_limit
     * This sets the current offset of this query
     * @param int $limit
     */
    public function set_limit($limit): void
    {
        $this->_state['limit'] = abs($limit);
    }

    /**
     * set_catalog
     * @param int $catalog_number
     */
    public function set_catalog($catalog_number): void
    {
        $this->catalog = $catalog_number;
    }

    /**
     * set_select
     * This appends more information to the select part of the SQL
     * statement, we're going to move to the %%SELECT%% style queries, as I
     * think it's the only way to do this...
     * @param string $field
     */
    public function set_select($field): void
    {
        $this->_state['select'][] = $field;
    }

    /**
     * set_join
     * This sets the joins for the current browse object
     * @param string $type
     * @param string $table
     * @param string $source
     * @param string $dest
     * @param int $priority
     */
    public function set_join($type, $table, $source, $dest, $priority): void
    {
        $this->_state['join'][$priority][$table] = sprintf('%s JOIN %s ON %s = %s', $type, $table, $source, $dest);
    }

    /**
     * set_join_and
     * This sets the joins for the current browse object and a second option as well
     * @param string $type
     * @param string $table
     * @param string $source1
     * @param string $dest1
     * @param string $source2
     * @param string $dest2
     * @param int $priority
     */
    public function set_join_and($type, $table, $source1, $dest1, $source2, $dest2, $priority): void
    {
        $this->_state['join'][$priority][$table] = strtoupper((string)$type) . sprintf(' JOIN %s ON %s = %s AND %s = %s', $table, $source1, $dest1, $source2, $dest2);
    }

    /**
     * set_join_and_and
     * This sets the joins for the current browse object and a second option as well
     * @param string $type
     * @param string $table
     * @param string $source1
     * @param string $dest1
     * @param string $source2
     * @param string $dest2
     * @param string $source3
     * @param string $dest3
     * @param int $priority
     */
    public function set_join_and_and($type, $table, $source1, $dest1, $source2, $dest2, $source3, $dest3, $priority): void
    {
        $this->_state['join'][$priority][$table] = strtoupper((string)$type) . sprintf(' JOIN %s ON %s = %s AND %s = %s AND %s = %s', $table, $source1, $dest1, $source2, $dest2, $source3, $dest3);
    }

    /**
     * set_group
     * This sets the "GROUP" part of the query
     * @param string $column
     * @param string $value
     * @param int $priority
     */
    public function set_group($column, $value, $priority): void
    {
        $this->_state['group'][$priority][$column] = $value;
    }

    /**
     * set_having
     * This sets the "HAVING" part of the query, we can only have one.
     * @param string $condition
     */
    public function set_having($condition): void
    {
        $this->_state['having'] = $condition;
    }

    /**
     * set_start
     * This sets the start point for our show functions
     * We need to store this in the session so that it can be pulled
     * back, if they hit the back button
     */
    public function set_start(int $start): void
    {
        $this->_state['start'] = $start;
    }

    /**
     * set_is_simple
     * This sets the current browse object to a 'simple' browse method
     * which means use the base query provided and expand from there
     * @param bool $value
     */
    public function set_is_simple($value): void
    {
        $this->_state['simple'] = make_bool($value);
    }

    /**
     * set_static_content
     * This sets true/false if the content of this browse
     * should be static, if they are then content filtering/altering
     * methods will be skipped
     * @param bool $value
     */
    public function set_static_content($value): void
    {
        $this->_state['static'] = make_bool($value);
    }

    /**
     * is_static_content
     */
    public function is_static_content(): bool
    {
        return make_bool($this->_state['static']);
    }

    /**
     * is_simple
     * This returns whether or not the current browse type is set to static.
     */
    public function is_simple(): bool
    {
        return $this->_state['simple'];
    }

    /**
     * get_saved
     * This looks in the session for the saved stuff and returns what it finds.
     */
    public function get_saved(): array
    {
        // See if we have it in the local cache first
        if (!empty($this->_cache)) {
            return $this->_cache;
        }

        if (!$this->is_simple()) {
            $sql        = 'SELECT `object_data` FROM `tmp_browse` WHERE `sid` = ? AND `id` = ?';
            $db_results = Dba::read($sql, [session_id(), $this->id]);
            $results    = Dba::fetch_assoc($db_results);

            if (array_key_exists('object_data', $results)) {
                $this->_cache = (array)$this->_unserialize($results['object_data']);

                return $this->_cache;
            }

            return [];
        }

        return $this->get_objects();
    }

    /**
     * get_objects
     * This gets an array of the ids of the objects that we are
     * currently browsing by it applies the sql and logic based
     * filters
     */
    public function get_objects(): array
    {
        // First we need to get the SQL statement we are going to run. This has to run against any possible filters (dependent on type)
        $sql = $this->get_sql();
        //debug_event(self::class, 'get_objects query: ' . $sql, 5);

        $db_results = Dba::read($sql);
        $results    = [];
        while ($data = Dba::fetch_assoc($db_results)) {
            $results[] = $data;
        }

        $results  = $this->_post_process($results);
        $filtered = [];
        foreach ($results as $data) {
            // Make sure that this object passes the logic filter
            if (array_key_exists('id', $data)) {
                $filtered[] = $data['id'];
            }
        }

        // Save what we've found and then return it
        $this->save_objects($filtered);

        return $filtered;
    }

    /**
     * _set_base_sql
     * This saves the base sql statement we are going to use.
     * @param bool $force
     * @param string $custom_base
     */
    private function _set_base_sql($force = false, $custom_base = ''): void
    {
        // Only allow it to be set once
        if (!empty((string)$this->_state['base']) && !$force) {
            return;
        }

        // Custom sql base
        if ($force && !empty($custom_base)) {
            $this->_state['custom'] = true;
            $this->_state['base']   = $custom_base;
        } else {
            // TODO we should remove this default fallback and rely on set_type()
            if ($this->queryType === null) {
                $this->queryType = new SongQuery();
            }
            $this->_state['select'][] = $this->queryType->get_select();

            // tag state should be set as they aren't really separate objects
            if ($this->get_type() === 'tag_hidden') {
                $this->set_filter('hidden', 1);
            }
            if ($this->get_type() === 'tag') {
                $this->set_filter('hidden', 0);
            }

            $this->_state['base'] = $this->queryType->get_base_sql();
        }
    }

    /**
     * _get_select
     * This returns the selects in a format that is friendly for a sql
     * statement.
     */
    private function _get_select(): string
    {
        return implode(", ", $this->_state['select'] ?? []);
    }

    /**
     * _get_base_sql
     * This returns the base sql statement all parsed up, this should be
     * called after all set operations.
     */
    private function _get_base_sql(): string
    {
        return str_replace("%%SELECT%%", $this->_get_select(), ($this->_state['base'] ?? ''));
    }

    /**
     * _get_filter_sql
     * This returns the filter part of the sql statement
     */
    private function _get_filter_sql(): string
    {
        if (!is_array($this->_state['filter'])) {
            return '';
        }

        $type = $this->get_type();
        $sql  = "WHERE";

        foreach ($this->_state['filter'] as $key => $value) {
            $sql .= $this->_sql_filter($key, $value);
        }

        $dis = '';
        if (AmpConfig::get('catalog_disable') && in_array($type, ['artist', 'album', 'album_disk', 'song', 'video'])) {
            // Add catalog enabled filter
            $dis = Catalog::get_enable_filter($type, '`' . $type . '`.`id`');
        }

        $catalog_filter = AmpConfig::get('catalog_filter');
        if ($catalog_filter && $this->user_id > 0) {
            // Add catalog user filter
            switch ($type) {
                case 'video':
                case 'artist':
                case 'album':
                case 'album_disk':
                case 'song':
                case 'song_artist':
                case 'song_album':
                case 'podcast':
                case 'podcast_episode':
                case 'playlist':
                case 'label':
                case 'live_stream':
                case 'tag':
                case 'tvshow':
                case 'tvshow_season':
                case 'tvshow_episode':
                case 'movie':
                case 'personal_video':
                case 'clip':
                case 'share':
                    $dis = Catalog::get_user_filter($type, $this->user_id);
                    break;
            }
        }

        if ($dis !== '' && $dis !== '0') {
            $sql .= $dis . " AND ";
        }

        $sql = rtrim($sql, " AND ") . " ";

        return rtrim($sql, "WHERE ") . " ";
    }

    /**
     * _get_sort_sql
     * Returns the sort sql part
     */
    private function _get_sort_sql(): string
    {
        if (empty($this->_state['sort'])) {
            return '';
        }

        $sql = 'ORDER BY ';

        foreach ($this->_state['sort'] as $key => $value) {
            $sql .= $this->_sql_sort($key, $value);
        }

        $sql = rtrim($sql, 'ORDER BY ');

        return rtrim($sql, ', ');
    }

    /**
     * _get_limit_sql
     * This returns the limit part of the sql statement
     */
    private function _get_limit_sql(): string
    {
        $start  = $this->get_start();
        $offset = $this->get_offset();
        if ($this->_state['limit'] > 0) {
            if ($offset > 0) {
                return ' LIMIT ' . (string)($this->_state['limit']) . ', ' . (string)($offset);
            } else {
                return ' LIMIT ' . (string)($this->_state['limit']);
            }
        }
        if (!$this->is_simple() || $start < 0 || ($start == 0 && $offset == 0)) {
            return '';
        }

        return ' LIMIT ' . (string)($start) . ', ' . (string)($offset);
    }

    /**
     * _get_join_sql
     * This returns the joins that this browse may need to work correctly
     */
    private function _get_join_sql(): string
    {
        if (empty($this->_state['join']) || !is_array($this->_state['join'])) {
            return '';
        }

        $sql = '';

        foreach ($this->_state['join'] as $joins) {
            foreach ($joins as $join) {
                $sql .= $join . ' ';
            }
        }

        return $sql;
    }

    /**
     * _get_group_sql
     * This returns the joins that this browse may need to work correctly
     */
    private function _get_group_sql(): string
    {
        if (empty($this->_state['group']) || !is_array($this->_state['group'])) {
            return '';
        }

        $sql = '';
        foreach ($this->_state['group'] as $groups) {
            foreach ($groups as $group) {
                $sql .= $group . ', ';
            }
        }

        return rtrim($sql, ', ');
    }

    /**
     * _get_having_sql
     * this returns the having sql stuff, if we've got anything
     */
    private function _get_having_sql(): string
    {
        return $this->_state['having'];
    }

    /**
     * get_sql
     * This returns the sql statement we are going to use this has to be run
     * every time we get the objects because it depends on the filters and
     * the type of object we are currently browsing.
     * @param bool $limit
     */
    public function get_sql($limit = true): string
    {
        if ($this->_state['custom']) {
            // custom queries are set by base and should not be added to
            $final_sql = $this->_get_base_sql();
        } else {
            // filter and sort set joins as well as group so make sure you run those first
            $filter_sql = $this->_get_filter_sql();
            $sort_sql   = $this->_get_sort_sql();
            // regular queries need to be joined with all the other parts
            $final_sql = $this->_get_base_sql() .
                $this->_get_join_sql() .
                $filter_sql .
                $this->_get_having_sql();

            // allow forcing a group by
            if (!empty($this->_get_group_sql())) {
                $final_sql .= " GROUP BY " . $this->_get_group_sql() . " ";
            } elseif ($this->get_type() == 'artist' || $this->get_type() == 'album') {
                $final_sql .= " GROUP BY `" . $this->get_type() . "`.`name`, `" . $this->get_type() . "`.`id` ";
            }

            $final_sql .= $sort_sql;
        }

        // apply a limit/offset limit (if set)
        $limit_sql = $limit ? $this->_get_limit_sql() : '';

        $final_sql .= $limit_sql;
        //debug_event(self::class, "get_sql: " . $final_sql, 5);

        return $final_sql;
    }

    /**
     * _post_process
     * This does some additional work on the results that we've received
     * before returning them. TODO this is only for tags/genres? should do this in the select/return if possible
     * @param array $data
     */
    private function _post_process($data): array
    {
        $tags = $this->_state['filter']['tag'] ?? '';

        if (!is_array($tags) || count($tags) < 2) {
            return $data;
        }

        $tag_count = count($tags);
        $count     = [];

        foreach ($data as $row) {
            ++$count[$row['id']];
        }

        $results = [];

        foreach ($count as $key => $value) {
            if ($value >= $tag_count) {
                $results[] = ['id' => $key];
            }
        }

        return $results;
    }

    /**
     * _sql_filter
     * This takes a filter name and value and if it is possible
     * to filter by this name on this type returns the appropriate sql
     * if not returns nothing
     * @param string $filter
     */
    private function _sql_filter($filter, mixed $value): string
    {
        if ($this->queryType === null) {
            $this->set_type($this->_state['type']);
        }
        if ($this->queryType === null) {
            return '';
        }

        return $this->queryType->get_sql_filter($this, $filter, $value);
    }

    /**
     * _sql_sort
     * This builds any order bys we need to do
     * to sort the results as best we can, there is also
     * a logic based sort that will come later as that's
     * a lot more complicated
     * @param string $field
     * @param string $order
     */
    private function _sql_sort($field, $order): string
    {
        if ($order != 'DESC') {
            $order = 'ASC';
        }

        // random sorting
        if ($field === 'rand') {
            return "RAND()";
        }

        if ($this->queryType === null) {
            $this->set_type($this->_state['type']);
        }
        if ($this->queryType === null) {
            return '';
        }

        return $this->queryType->get_sql_sort($this, $field, $order);
    }

    /**
     * sql_sort_video
     */
    public function sql_sort_video(string $field, string $order, ?string $table = 'video'): string
    {
        $sql = "";
        switch ($field) {
            case 'addition_time':
            case 'catalog':
            case 'update_time':
            case 'title':
            case 'total_count':
            case 'total_skip':
                $sql = "`video`.`$field`";
                break;
            case 'resolution':
                $sql = "`video`.`resolution_x`";
                break;
            case 'length':
                $sql = "`video`.`time`";
                break;
            case 'codec':
                $sql = "`video`.`video_codec`";
                break;
            case 'release_date':
                $sql = "`video`.`release_date`";
                break;
            case 'rating':
                $sql = sprintf('`rating`.`rating` %s, `rating`.`id`', $order);
                $this->set_join_and_and('LEFT', "`rating`", "`rating`.`object_id`", "`video`.`id`", "`rating`.`object_type`", "'video'", "`rating`.`user`", (string)$this->user_id, 100);
                break;
            case 'user_flag':
                $sql = "`user_flag`.`date`";
                $this->set_join_and_and('LEFT', "`user_flag`", "`user_flag`.`object_id`", "`video`.`id`", "`user_flag`.`object_type`", "'video'", "`user_flag`.`user`", (string)$this->user_id, 100);
                break;
        }

        if (!($sql === '' || $sql === '0') && $table != 'video') {
            $this->set_join('LEFT', '`video`', '`' . $table . '`.`id`', '`video`.`id`', 100);
        }

        return $sql;
    }

    /**
     * _resort_objects
     * This takes the existing objects, looks at the current
     * sort method and then re-sorts them This is internally
     * called by the set_sort() function
     */
    private function _resort_objects(): bool
    {
        // There are two ways to do this.. the easy way...
        // and the vollmer way, hopefully we don't have to
        // do it the vollmer way
        if ($this->is_simple()) {
            $sql = $this->get_sql();
        } else {
            // FIXME: this is fragile for large browses
            // First pull the objects
            $objects = $this->get_saved();

            // If there's nothing there don't do anything
            if ($objects === [] || !is_array($objects)) {
                return false;
            }

            $type      = $this->get_type();
            $where_sql = sprintf('WHERE `%s`.`id` IN (', $type);

            foreach ($objects as $object_id) {
                $object_id = Dba::escape($object_id);
                $where_sql .= sprintf('\'%s\',', $object_id);
            }

            $where_sql = rtrim($where_sql, ', ');

            $where_sql .= ")";

            $sql = $this->_get_base_sql();

            $group_sql = " GROUP BY `" . $this->get_type() . '`.`id`';
            $order_sql = " ORDER BY ";

            // There should only be one of these in a browse
            foreach ($this->_state['sort'] as $key => $value) {
                $sql_sort = $this->_sql_sort($key, $value);
                $order_sql .= $sql_sort;
                $group_sql .= ", " . preg_replace('/(ASC,|DESC,|,|RAND\(\))$/', '', $sql_sort);
            }

            // Clean her up
            $order_sql = rtrim($order_sql, "ORDER BY ");
            $order_sql = rtrim($order_sql, ",");

            $sql = $sql . $this->_get_join_sql() . $where_sql . $group_sql . $order_sql;
        } // if not simple

        $db_results = Dba::read($sql);
        //debug_event(self::class, "_resort_objects: " . $sql, 5);

        $results = [];
        while ($row = Dba::fetch_assoc($db_results)) {
            $results[] = (int)$row['id'];
        }

        $this->save_objects($results);

        return true;
    }

    /**
     * store
     * This saves the current state to the database
     */
    public function store(): void
    {
        $browse_id = $this->id;
        if ($browse_id != 'nocache') {
            $data = $this->_serialize($this->_state);

            $sql = 'UPDATE `tmp_browse` SET `data` = ? WHERE `sid` = ? AND `id` = ?';
            Dba::write($sql, [$data, session_id(), $browse_id]);
        }
    }

    /**
     * save_objects
     * This takes the full array of object ids, often passed into show and
     * if necessary it saves them
     * @param array $object_ids
     */
    public function save_objects($object_ids): bool
    {
        // Saving these objects has two operations, one holds it in
        // a local variable and then second holds it in a row in the
        // tmp_browse table

        // Only do this if it's not a simple browse
        if (!$this->is_simple()) {
            $this->_cache = $object_ids;
            $this->set_total(count($object_ids));
            $browse_id = $this->id;
            if ($browse_id != 'nocache') {
                $data = $this->_serialize($this->_cache);

                $sql = 'UPDATE `tmp_browse` SET `object_data` = ? WHERE `sid` = ? AND `id` = ?';
                Dba::write($sql, [$data, session_id(), $browse_id]);
            }
        }

        return true;
    }

    /**
     * Get content div name
     */
    public function get_content_div(): string
    {
        $key = 'browse_content_' . $this->get_type();
        if (!empty($this->_state['extended_key_name'])) {
            $key .= '_' . $this->_state['extended_key_name'];
        }

        return $key . ('_' . $this->id);
    }

    /**
     * Set an additional content div key.
     * This is used to keep div names unique in the html
     * @param string|int $key
     */
    public function set_content_div_ak($key): void
    {
        $this->_state['extended_key_name'] = str_replace(", ", "_", (string)$key);
    }
}
