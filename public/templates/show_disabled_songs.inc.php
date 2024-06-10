<?php

declare(strict_types=0);

/**
 * vim:set softtabstop=4 shiftwidth=4 expandtab:
 *
 * LICENSE: GNU Affero General Public License, version 3 (AGPL-3.0-or-later)
 * Copyright Ampache.org, 2001-2024
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

use Ampache\Config\AmpConfig;
use Ampache\Repository\Model\Song;

/** @var Iterator<Song> $songs */
?>
<br />
<form name="songs" method="post" action="<?php echo AmpConfig::get('web_path'); ?>/admin/catalog.php" enctype="multipart/form-data" style="Display:inline">
    <table class="tabledata striped-rows">
        <thead>
            <tr class="th-top">
                <th class="cel_select"><a href="#" onclick="check_select('song'); return false;"><?php echo T_('Select'); ?></a></th>
                <th class="cel_song"><?php echo T_('Title'); ?></th>
                <th class="cel_album"><?php echo T_('Album'); ?></th>
                <th class="cel_artist"><?php echo T_('Artist'); ?></th>
                <th class="cel_filename"><?php echo T_('Filename'); ?></th>
                <th class="cel_additiontime"><?php echo T_('Addition Time'); ?></th>
            </tr>
        </thead>
        <tbody>
        <?php if (!$songs->valid()) { ?>
            <tr>
                <td colspan="6" style="text-align: center"><span class="error"><?php echo T_('No records found'); ?></span></td>
            </tr>
        <?php } ?>
            <?php foreach ($songs as $song) { ?>
                <tr>
                    <td class="cel_select"><input type="checkbox" name="song[]" value="<?php echo $song->getId(); ?>" /></td>
                    <td class="cel_song"><?php echo $song->get_fullname(); ?></td>
                    <td class="cel_album"><?php echo $song->get_album_fullname(); ?></td>
                    <td class="cel_artist"><?php echo $song->get_artist_fullname(); ?></td>
                    <td class="cel_filename"><?php echo $song->getFile(); ?></td>
                    <td class="cel_additiontime"><?php echo get_datetime($song->getAdditionTime()); ?></td>
                </tr>
            <?php } ?>
        </tbody>
        <tfoot>
            <tr class="th-bottom">
                <th class="cel_select"><a href="#" onclick="check_select('song'); return false;"><?php echo T_('Select'); ?></a></th>
                <th class="cel_song"><?php echo T_('Title'); ?></th>
                <th class="cel_album"><?php echo T_('Album'); ?></th>
                <th class="cel_artist"><?php echo T_('Artist'); ?></th>
                <th class="cel_filename"><?php echo T_('Filename'); ?></th>
                <th class="cel_additiontime"><?php echo T_('Addition Time'); ?></th>
            </tr>
        </tfoot>
    </table>
    <div class="formValidation">
        <input class="button" type="submit" value="<?php echo T_('Enable'); ?>" />&nbsp;&nbsp;
        <input type="hidden" name="action" value="enable_disabled" />
    </div>
</form>
