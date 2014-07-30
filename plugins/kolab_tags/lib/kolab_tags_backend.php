<?php

/**
 * Kolab Tags backend
 *
 * @author Aleksander Machniak <machniak@kolabsys.com>
 *
 * Copyright (C) 2014, Kolab Systems AG <contact@kolabsys.com>
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU Affero General Public License as
 * published by the Free Software Foundation, either version 3 of the
 * License, or (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See the
 * GNU Affero General Public License for more details.
 *
 * You should have received a copy of the GNU Affero General Public License
 * along with this program. If not, see <http://www.gnu.org/licenses/>.
 */

class kolab_tags_backend
{
    private $folders;
    private $tag_cols = array('name', 'category', 'color', 'parent', 'iconName', 'priority', 'members');

    const FOLDER_TYPE  = 'configuration';
    const OBJECT_TYPE  = 'relation';
    const CATEGORY     = 'tag';


    /**
     * Class constructor
     */
    public function __construct()
    {
    }

    /**
     * Initializes config object and dependencies
     */
    private function load()
    {
        // nothing to be done here
        if (isset($this->folders)) {
            return;
        }

        $this->folders = kolab_storage::get_folders(self::FOLDER_TYPE);

        foreach ($this->folders as $folder) {
            if ($folder->default) {
                $this->default = $folder;
                break;
            }
        }

        // if no folder is set as default, choose the first one
        if (!$this->default) {
            $this->default = reset($this->folders);
        }

        // check if configuration folder exist
        if ($this->default && $this->default->name) {
            $this->enabled = true;
        }
    }

    /**
     * Tags list
     *
     * @param array $filter Search filter
     *
     * @return array List of tags
     */
    public function list_tags($filter = array())
    {
        $this->load();

        $default  = true;
        $taglist  = array();
        $filter[] = array('type', '=', self::OBJECT_TYPE);

        foreach ($this->folders as $folder) {
            // we only want to read from default folder
            if ($default && !$folder->default) {
                continue;
            }

            foreach ($folder->select($filter) as $object) {
                if ($object['category'] == self::CATEGORY) {
                    // @TODO: we need uid, name, color and members only?
                    $taglist[] = $object;
                }
            }
        }

        return $taglist;
    }

    /**
     * Create tag object
     *
     * @param array $tag Tag data
     *
     * @return boolean|array Tag data on success, False on failure
     */
    public function create($tag)
    {
        $this->load();

        if (!$this->default) {
            return false;
        }

        $tag = array_intersect_key($tag, array_combine($this->tag_cols, $this->tag_cols));
        $tag['type']     = 'relation';
        $tag['category'] = self::CATEGORY;

        // Create the object
        $result = $this->default->save($tag, self::FOLDER_TYPE . '.' . self::OBJECT_TYPE);

        return $result ? $tag : false;
    }

    /**
     * Update tag object
     *
     * @param array $tag Tag data
     *
     * @return boolean|array Tag data on success, False on failure
     */
    public function update($tag)
    {
        // get tag object data, we need _mailbox
        $list    = $this->list_tags(array(array('uid', '=', $tag['uid'])));
        $old_tag = $list[0];

        if (!$old_tag) {
            return false;
        }

        $tag = array_intersect_key($tag, array_combine($this->tag_cols, $this->tag_cols));
        $tag = array_merge($old_tag, $tag);

        // find folder object
        foreach ($this->folders as $folder) {
            if ($folder->name == $tag['_mailbox']) {
                break;
            }
        }

        // Update the object
        $result = $folder->save($tag, self::FOLDER_TYPE . '.' . self::OBJECT_TYPE, $tag['uid']);

        return $result ? $tag : false;
    }

    /**
     * Remove tag object
     *
     * @param string $uid Object unique identifier
     *
     * @return boolean True on success, False on failure
     */
    public function remove($uid)
    {
        // get tag object data, we need _mailbox
        $list = $this->list_tags(array(array('uid', '=', $uid)));
        $tag  = $list[0];

        if (!$tag) {
            return false;
        }

        // find folder object
        foreach ($this->folders as $folder) {
            if ($folder->name == $tag['_mailbox']) {
                break;
            }
        }

        return $folder->delete($uid);
    }
}
