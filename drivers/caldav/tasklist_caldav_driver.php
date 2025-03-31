<?php

/**
 * CalDAV driver for the Tasklist plugin
 *
 * @author Aleksander Machniak <machniak@apheleia-it.ch>
 *
 * Copyright (C) 2012-2022, Apheleia IT AG <contact@apheleia-it.ch>
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

class tasklist_caldav_driver extends tasklist_driver
{
    // features supported by the backend
    public $alarms      = false;
    public $attachments = true;
    public $attendees   = true;
    public $undelete    = false; // task undelete action
    public $alarm_types = ['DISPLAY','AUDIO'];
    public $search_more_results;

    private $rc;
    private $plugin;
    private $storage;
    private $lists;
    private $folders = [];
    private $tasks   = [];
    private $tags    = [];
    private $bonnie_api = false;


    /**
     * Default constructor
     */
    public function __construct($plugin)
    {
        $this->rc     = $plugin->rc;
        $this->plugin = $plugin;

        // Initialize the CalDAV storage
        $url = $this->rc->config->get('tasklist_caldav_server', 'http://localhost');
        $this->storage = new kolab_storage_dav($url);

        // get configuration for the Bonnie API
        // $this->bonnie_api = libkolab::get_bonnie_api();

        // $this->plugin->register_action('folder-acl', [$this, 'folder_acl']);
    }

    /**
     * Read available calendars for the current user and store them internally
     */
    private function _read_lists($force = false)
    {
        // already read sources
        if (isset($this->lists) && !$force) {
            return $this->lists;
        }

        // get all folders that have type "task"
        $folders = $this->storage->get_folders('task');
        $this->lists = $this->folders = [];

        $prefs = $this->rc->config->get('kolab_tasklists', []);

        foreach ($folders as $folder) {
            $tasklist = $this->folder_props($folder, $prefs);

            $this->lists[$tasklist['id']] = $tasklist;
            $this->folders[$tasklist['id']] = $folder;
        }

        return $this->lists;
    }

    /**
     * Derive list properties from the given kolab_storage_folder object
     */
    protected function folder_props($folder, $prefs = [])
    {
        if ($folder->get_namespace() == 'personal') {
            $norename = false;
            $editable = true;
            $rights = 'lrswikxtea';
            $alarms = !isset($folder->attributes['alarms']) || $folder->attributes['alarms'];
        } else {
            $alarms = false;
            $editable = false;
            $rights = $folder->get_myrights();
            $editable = strpos($rights, 'i') !== false;
            $norename = strpos($rights, 'x') === false;

            if (!empty($folder->attributes['invitation'])) {
                $invitation = $folder->attributes['invitation'];
                $active = true;
            }
        }

        $list_id = $folder->id;

        return [
            'id' => $list_id,
            'name' => $folder->get_name(),
            'listname' => $folder->get_name(),
            'editname' => $folder->get_foldername(),
            'color' => $folder->get_color('0000CC'),
            'showalarms' => $prefs[$list_id]['showalarms'] ?? $alarms,
            'editable' => $editable,
            'rights'    => $rights,
            'norename' => $norename,
            'active' => $active ?? (!isset($prefs[$list_id]['active']) || !empty($prefs[$list_id]['active'])),
            'owner' => $folder->get_owner(),
            'parentfolder' => $folder->get_parent(),
            'default' => $folder->default,
            'virtual' => $folder instanceof kolab_storage_folder_virtual,
            'children' => true,  // TODO: determine if that folder indeed has child folders
            // 'subscribed' => (bool) $folder->is_subscribed(),
            'removable' => !$folder->default,
            'subtype'  => $folder->subtype,
            'group' => $folder->default ? 'default' : $folder->get_namespace(),
            'class' => trim($folder->get_namespace() . ($folder->default ? ' default' : '')),
            'caldavuid' => '', // $folder->get_uid(),
            'history' => !empty($this->bonnie_api),
            'share_invitation' => $invitation ?? null,
        ];
    }

    /**
     * Get a list of available task lists from this source
     *
     * @param int    $filter Bitmask defining filter criterias.
     *                       See FILTER_* constants for possible values.
     * @param ?array $tree
     *
     * @return array
     */
    public function get_lists($filter = 0, &$tree = null)
    {
        $this->_read_lists();

        $folders = $this->filter_folders($filter);

        $prefs = $this->rc->config->get('kolab_tasklists', []);
        $lists = [];

        foreach ($folders as $folder) {
            $parent_id = null;
            $list_id   = $folder->id;
            $fullname  = $folder->get_name();
            $listname  = $folder->get_foldername();

            // special handling for virtual folders
            if ($folder instanceof kolab_storage_folder_user) {
                $lists[$list_id] = [
                    'id'       => $list_id,
                    'name'     => $fullname,
                    'listname' => $listname,
                    'title'    => $folder->get_title(),
                    'virtual'  => true,
                    'editable' => false,
                    'rights'   => 'l',
                    'group'    => 'other virtual',
                    'class'    => 'user',
                    'parent'   => $parent_id,
                ];
            } elseif ($folder instanceof kolab_storage_folder_virtual) {
                $lists[$list_id] = [
                    'id'       => $list_id,
                    'name'     => $fullname,
                    'listname' => $listname,
                    'virtual'  => true,
                    'editable' => false,
                    'rights'   => 'l',
                    'group'    => $folder->get_namespace(),
                    'class'    => 'folder',
                    'parent'   => $parent_id,
                ];
            } else {
                if (empty($this->lists[$list_id])) {
                    $this->lists[$list_id] = $this->folder_props($folder, $prefs);
                    $this->folders[$list_id] = $folder;
                }

                // $this->lists[$list_id]['parent'] = $parent_id;
                $lists[$list_id] = $this->lists[$list_id];
            }
        }

        return $lists;
    }

    /**
     * Get list of folders according to specified filters
     *
     * @param int $filter Bitmask defining restrictions. See FILTER_* constants for possible values.
     *
     * @return array List of task folders
     */
    protected function filter_folders($filter)
    {
        $this->_read_lists();

        $folders = [];
        foreach ($this->lists as $id => $list) {
            if (!empty($this->folders[$id])) {
                $folder = $this->folders[$id];

                if ($folder->get_namespace() == 'personal') {
                    $folder->editable = true;
                } elseif ($rights = $folder->get_myrights()) {
                    if (strpos($rights, 't') !== false || strpos($rights, 'd') !== false) {
                        $folder->editable = strpos($rights, 'i') !== false;
                    }
                }

                $folders[] = $folder;
            }
        }

        $plugin = $this->rc->plugins->exec_hook('tasklist_list_filter', [
                'list'      => $folders,
                'filter'    => $filter,
                'tasklists' => $folders,
        ]);

        if ($plugin['abort'] || !$filter) {
            return $plugin['tasklists'] ?? [];
        }

        $personal = $filter & self::FILTER_PERSONAL;
        $shared   = $filter & self::FILTER_SHARED;

        $tasklists = [];
        foreach ($folders as $folder) {
            if (($filter & self::FILTER_WRITEABLE) && !$folder->editable) {
                continue;
            }
            /*
                        if (($filter & self::FILTER_INSERTABLE) && !$folder->insert) {
                            continue;
                        }
                        if (($filter & self::FILTER_ACTIVE) && !$folder->is_active()) {
                            continue;
                        }
                        if (($filter & self::FILTER_PRIVATE) && $folder->subtype != 'private') {
                            continue;
                        }
                        if (($filter & self::FILTER_CONFIDENTIAL) && $folder->subtype != 'confidential') {
                            continue;
                        }
            */
            if ($personal || $shared) {
                $ns = $folder->get_namespace();
                if (!(($personal && $ns == 'personal') || ($shared && $ns == 'shared'))) {
                    continue;
                }
            }

            $tasklists[$folder->id] = $folder;
        }

        return $tasklists;
    }

    /**
     * Get the kolab_calendar instance for the given calendar ID
     *
     * @param string $id List identifier (encoded imap folder name)
     *
     * @return ?kolab_storage_folder Object nor null if list doesn't exist
     */
    protected function get_folder($id)
    {
        $this->_read_lists();

        return $this->folders[$id] ?? null;
    }


    /**
     * Create a new list assigned to the current user
     *
     * @param array $prop Hash array with list properties
     *                    - name: List name
     *                    - color: The color of the list
     *                    - showalarms: True if alarms are enabled
     *
     * @return string|false ID of the new list on success, False on error
     */
    public function create_list(&$prop)
    {
        $prop['type'] = 'task';
        $prop['alarms'] = !empty($prop['showalarms']);

        $id = $this->storage->folder_update($prop);

        if ($id === false) {
            return false;
        }

        // force page reload to properly render folder hierarchy
        if (!empty($prop['parent'])) {
            $prop['_reload'] = true;
        } else {
            $prop += $this->_read_lists(true)[$id] ?? [];
            unset($prop['type'], $prop['alarms']);
        }

        return $id;
    }

    /**
     * Update properties of an existing tasklist
     *
     * @param array $prop Hash array with list properties
     *                    - id: List Identifier
     *                    - name: List name
     *                    - color: The color of the list
     *                    - showalarms: True if alarms are enabled (if supported)
     *
     * @return bool True on success, Fales on failure
     */
    public function edit_list(&$prop)
    {
        if (!empty($prop['id'])) {
            $id = $prop['id'];
            $prop['type'] = 'task';
            $prop['alarms'] = !empty($prop['showalarms']);

            if ($this->storage->folder_update($prop) !== false) {
                $prop += $this->_read_lists(true)[$id] ?? [];
                unset($prop['type'], $prop['alarms']);

                return true;
            }
        }

        return false;
    }

    /**
     * Set active/subscribed state of a list
     *
     * @param array $prop Hash array with list properties
     *                    - id: List Identifier
     *                    - active: True if list is active, false if not
     *                    - permanent: True if list is to be subscribed permanently
     *
     * @return bool True on success, Fales on failure
     */
    public function subscribe_list($prop)
    {
        if (!empty($prop['id'])) {
            $prefs['kolab_tasklists'] = $this->rc->config->get('kolab_tasklists', []);

            if (isset($prop['permanent'])) {
                $prefs['kolab_tasklists'][$prop['id']]['permanent'] = intval($prop['permanent']);
            }

            if (isset($prop['active'])) {
                $prefs['kolab_tasklists'][$prop['id']]['active'] = intval($prop['active']);
            }

            $this->rc->user->save_prefs($prefs);

            return true;
        }

        return false;
    }

    /**
     * Delete the given list with all its contents
     *
     * @param array $prop Hash array with list properties
     *                    - id: list Identifier
     *
     * @return bool True on success, Fales on failure
     */
    public function delete_list($prop)
    {
        if (!empty($prop['id'])) {
            if ($this->storage->folder_delete($prop['id'], 'task')) {
                // remove folder from user prefs
                $prefs['kolab_tasklists'] = $this->rc->config->get('kolab_tasklists', []);
                if (isset($prefs['kolab_tasklists'][$prop['id']])) {
                    unset($prefs['kolab_tasklists'][$prop['id']]);
                    $this->rc->user->save_prefs($prefs);
                }

                return true;
            }
        }

        return false;
    }

    /**
     * Search for shared or otherwise not listed tasklists the user has access
     *
     * @param string $query  Search string
     * @param string $source Section/source to search
     *
     * @return array List of tasklists
     */
    public function search_lists($query, $source)
    {
        $this->search_more_results = false;
        $this->lists = $this->folders = [];

        // find unsubscribed IMAP folders that have "event" type
        if ($source == 'folders') {
            foreach ((array) $this->storage->search_folders('task', $query, ['other']) as $folder) {
                $this->folders[$folder->id] = $folder;
                $this->lists[$folder->id] = $this->folder_props($folder, []);
            }
        }
        // find other user's calendars (invitations)
        elseif ($source == 'users') {
            // we have slightly more space, so display twice the number
            $limit = $this->rc->config->get('autocomplete_max', 15) * 2;

            foreach ($this->storage->get_share_invitations('task', $query) as $invitation) {
                $this->folders[$invitation->id] = $invitation;
                $this->lists[$invitation->id] = $this->folder_props($invitation, []);

                if (count($this->lists) > $limit) {
                    $this->search_more_results = true;
                }
            }
        }

        return $this->get_lists();
    }

    /**
     * Get a list of tags to assign tasks to
     *
     * @return array List of tags
     */
    public function get_tags()
    {
        return $this->tags;
    }

    /**
     * Get number of tasks matching the given filter
     *
     * @param array $lists List of lists to count tasks of
     *
     * @return array Hash array with counts grouped by status (all|flagged|completed|today|tomorrow|nodate)
     */
    public function count_tasks($lists = null)
    {
        if (empty($lists)) {
            $lists = $this->_read_lists();
            $lists = array_keys($lists);
        } elseif (is_string($lists)) {
            $lists = explode(',', $lists);
        }

        $today_date    = new DateTime('now', $this->plugin->timezone);
        $today         = $today_date->format('Y-m-d');
        $tomorrow_date = new DateTime('now + 1 day', $this->plugin->timezone);
        $tomorrow      = $tomorrow_date->format('Y-m-d');

        $counts = ['all' => 0, 'today' => 0, 'tomorrow' => 0, 'later' => 0, 'overdue'  => 0];

        foreach ($lists as $list_id) {
            if (!$folder = $this->get_folder($list_id)) {
                continue;
            }

            foreach ($folder->select([['tags', '!~', 'x-complete']], true) as $record) {
                $rec = $this->_to_rcube_task($record, $list_id, false);

                if ($this->is_complete($rec)) {  // don't count complete tasks
                    continue;
                }

                $counts['all']++;
                if (empty($rec['date'])) {
                    $counts['later']++;
                } elseif ($rec['date'] == $today) {
                    $counts['today']++;
                } elseif ($rec['date'] == $tomorrow) {
                    $counts['tomorrow']++;
                } elseif ($rec['date'] < $today) {
                    $counts['overdue']++;
                } elseif ($rec['date'] > $tomorrow) {
                    $counts['later']++;
                }
            }
        }

        return $counts;
    }

    /**
     * Get all task records matching the given filter
     *
     * @param array $filter Hash array with filter criterias:
     *                      - mask:  Bitmask representing the filter selection (check against tasklist::FILTER_MASK_* constants)
     *                      - from:  Date range start as string (Y-m-d)
     *                      - to:    Date range end as string (Y-m-d)
     *                      - search: Search query string
     *                      - uid:   Task UIDs
     * @param array $lists  List of lists to get tasks from
     *
     * @return array List of tasks records matchin the criteria
     */
    public function list_tasks($filter, $lists = null)
    {
        if (empty($lists)) {
            $lists = $this->_read_lists();
            $lists = array_keys($lists);
        } elseif (is_string($lists)) {
            $lists = explode(',', $lists);
        }

        $results = [];

        // query Kolab storage cache
        $query = [];
        if (isset($filter['mask']) && ($filter['mask'] & tasklist::FILTER_MASK_COMPLETE)) {
            $query[] = ['tags', '~', 'x-complete'];
        } elseif (empty($filter['since'])) {
            $query[] = ['tags', '!~', 'x-complete'];
        }

        // full text search (only works with cache enabled)
        if (!empty($filter['search'])) {
            $search = mb_strtolower($filter['search']);
            foreach (rcube_utils::normalize_string($search, true) as $word) {
                $query[] = ['words', '~', $word];
            }
        }

        if (!empty($filter['since'])) {
            $query[] = ['changed', '>=', $filter['since']];
        }

        if (!empty($filter['uid'])) {
            $query[] = ['uid', '=', (array) $filter['uid']];
        }

        foreach ($lists as $list_id) {
            if (!$folder = $this->get_folder($list_id)) {
                continue;
            }

            foreach ($folder->select($query) as $record) {
                // TODO: post-filter tasks returned from storage
                $record['list_id'] = $list_id;
                $results[] = $record;
            }
        }

        foreach (array_keys($results) as $idx) {
            $results[$idx] = $this->_to_rcube_task($results[$idx], $results[$idx]['list_id']);
        }

        return $results;
    }

    /**
     * Return data of a specific task
     *
     * @param mixed $prop   Hash array with task properties or task UID
     * @param int   $filter Bitmask defining filter criterias for folders.
     *                      See FILTER_* constants for possible values.
     *
     * @return array|false Hash array with task properties or false if not found
     */
    public function get_task($prop, $filter = 0)
    {
        $this->_parse_id($prop);

        $id      = $prop['uid'];
        $list_id = $prop['list'];
        $folders = $list_id ? [$list_id => $this->get_folder($list_id)] : $this->get_lists($filter);

        // find task in the available folders
        foreach ($folders as $list_id => $folder) {
            if (is_array($folder)) {
                $folder = $this->folders[$list_id];
            }
            if (is_numeric($list_id) || !$folder) {
                continue;
            }
            if (empty($this->tasks[$id]) && ($object = $folder->get_object($id))) {
                $this->tasks[$id] = $this->_to_rcube_task($object, $list_id);
                break;
            }
        }

        return $this->tasks[$id] ?? false;
    }

    /**
     * Get all decendents of the given task record
     *
     * @param mixed $prop      Hash array with task properties or task UID
     * @param bool  $recursive True if all childrens children should be fetched
     *
     * @return array List of all child task IDs
     */
    public function get_childs($prop, $recursive = false)
    {
        if (is_string($prop)) {
            $task = $this->get_task($prop);
            $prop = ['uid' => $task['uid'], 'list' => $task['list']];
        } else {
            $this->_parse_id($prop);
        }

        $childs   = [];
        $list_id  = $prop['list'];
        $task_ids = [$prop['uid']];
        $folder   = $this->get_folder($list_id);

        // query for childs (recursively)
        while ($folder && !empty($task_ids)) {
            $query_ids = [];
            foreach ($task_ids as $task_id) {
                $query = [['tags','=','x-parent:' . $task_id]];
                foreach ($folder->select($query) as $record) {
                    // don't rely on kolab_storage_folder filtering
                    if ($record['parent_id'] == $task_id) {
                        $childs[] = $list_id . ':' . $record['uid'];
                        $query_ids[] = $record['uid'];
                    }
                }
            }

            if (!$recursive) {
                break;
            }

            $task_ids = $query_ids;
        }

        return $childs;
    }

    /**
     * Provide a list of revisions for the given task
     *
     * @param array $prop Hash array with task properties
     *
     * @return array|false List of changes, each as a hash array
     * @see tasklist_driver::get_task_changelog()
     */
    public function get_task_changelog($prop)
    {
        if (empty($this->bonnie_api)) {
            return false;
        }
        /*
        list($uid, $mailbox, $msguid) = $this->_resolve_task_identity($prop);

        $result = $uid && $mailbox ? $this->bonnie_api->changelog('task', $uid, $mailbox, $msguid) : null;
        if (is_array($result) && $result['uid'] == $uid) {
            return $result['changes'];
        }
        */
        return false;
    }

    /**
     * Return full data of a specific revision of an event
     *
     * @param mixed $prop UID string or hash array with task properties
     * @param mixed $rev  Revision number
     *
     * @return array|false Task object as hash array
     * @see tasklist_driver::get_task_revision()
     */
    public function get_task_revison($prop, $rev)
    {
        if (empty($this->bonnie_api)) {
            return false;
        }
        /*
                $this->_parse_id($prop);
                $uid     = $prop['uid'];
                $list_id = $prop['list'];
                list($uid, $mailbox, $msguid) = $this->_resolve_task_identity($prop);

                // call Bonnie API
                $result = $this->bonnie_api->get('task', $uid, $rev, $mailbox, $msguid);
                if (is_array($result) && $result['uid'] == $uid && !empty($result['xml'])) {
                    $format = kolab_format::factory('task');
                    $format->load($result['xml']);
                    $rec = $format->to_array();
                    $format->get_attachments($rec, true);

                    if ($format->is_valid()) {
                        $rec = self::_to_rcube_task($rec, $list_id, false);
                        $rec['rev'] = $result['rev'];
                        return $rec;
                    }
                }
        */
        return false;
    }

    /**
     * Command the backend to restore a certain revision of a task.
     * This shall replace the current object with an older version.
     *
     * @param mixed $prop UID string or hash array with task properties
     * @param mixed $rev  Revision number
     *
     * @return bool True on success, False on failure
     * @see tasklist_driver::restore_task_revision()
     */
    public function restore_task_revision($prop, $rev)
    {
        if (empty($this->bonnie_api)) {
            return false;
        }
        /*
        $this->_parse_id($prop);
        $uid     = $prop['uid'];
        $list_id = $prop['list'];
        list($uid, $mailbox, $msguid) = $this->_resolve_task_identity($prop);

        $folder  = $this->get_folder($list_id);
        $success = false;

        if ($folder && ($raw_msg = $this->bonnie_api->rawdata('task', $uid, $rev, $mailbox))) {
            $imap = $this->rc->get_storage();

            // insert $raw_msg as new message
            if ($imap->save_message($folder->name, $raw_msg, null, false)) {
                $success = true;

                // delete old revision from imap and cache
                $imap->delete_message($msguid, $folder->name);
                $folder->cache->set($msguid, false);
            }
        }

        return $success;
        */
        return false;
    }

    /**
     * Get a list of property changes beteen two revisions of a task object
     *
     * @param array $prop Hash array with task properties
     * @param mixed $rev1 Revision: "from"
     * @param mixed $rev2 Revision: "to"
     *
     * @return array|false List of property changes, each as a hash array
     * @see tasklist_driver::get_task_diff()
     */
    public function get_task_diff($prop, $rev1, $rev2)
    {
        /*
                $this->_parse_id($prop);
                $uid     = $prop['uid'];
                $list_id = $prop['list'];
                list($uid, $mailbox, $msguid) = $this->_resolve_task_identity($prop);

                // call Bonnie API
                $result = $this->bonnie_api->diff('task', $uid, $rev1, $rev2, $mailbox, $msguid, $instance_id);
                if (is_array($result) && $result['uid'] == $uid) {
                    $result['rev1'] = $rev1;
                    $result['rev2'] = $rev2;

                    $keymap = array(
                        'start'    => 'start',
                        'due'      => 'date',
                        'dstamp'   => 'changed',
                        'summary'  => 'title',
                        'alarm'    => 'alarms',
                        'attendee' => 'attendees',
                        'attach'   => 'attachments',
                        'rrule'    => 'recurrence',
                        'related-to' => 'parent_id',
                        'percent-complete' => 'complete',
                        'lastmodified-date' => 'changed',
                    );
                    $prop_keymaps = array(
                        'attachments' => array('fmttype' => 'mimetype', 'label' => 'name'),
                        'attendees'   => array('partstat' => 'status'),
                    );
                    $special_changes = array();

                    // map kolab event properties to keys the client expects
                    array_walk($result['changes'], function(&$change, $i) use ($keymap, $prop_keymaps, $special_changes) {
                        if (array_key_exists($change['property'], $keymap)) {
                            $change['property'] = $keymap[$change['property']];
                        }
                        if ($change['property'] == 'priority') {
                            $change['property'] = 'flagged';
                            $change['old'] = $change['old'] == 1 ? $this->plugin->gettext('yes') : null;
                            $change['new'] = $change['new'] == 1 ? $this->plugin->gettext('yes') : null;
                        }
                        // map alarms trigger value
                        if ($change['property'] == 'alarms') {
                            if (is_array($change['old']) && is_array($change['old']['trigger']))
                                $change['old']['trigger'] = $change['old']['trigger']['value'];
                            if (is_array($change['new']) && is_array($change['new']['trigger']))
                                $change['new']['trigger'] = $change['new']['trigger']['value'];
                        }
                        // make all property keys uppercase
                        if ($change['property'] == 'recurrence') {
                            $special_changes['recurrence'] = $i;
                            foreach (array('old','new') as $m) {
                                if (is_array($change[$m])) {
                                    $props = array();
                                    foreach ($change[$m] as $k => $v) {
                                        $props[strtoupper($k)] = $v;
                                    }
                                    $change[$m] = $props;
                                }
                            }
                        }
                        // map property keys names
                        if (is_array($prop_keymaps[$change['property']])) {
                          foreach ($prop_keymaps[$change['property']] as $k => $dest) {
                            if (is_array($change['old']) && array_key_exists($k, $change['old'])) {
                                $change['old'][$dest] = $change['old'][$k];
                                unset($change['old'][$k]);
                            }
                            if (is_array($change['new']) && array_key_exists($k, $change['new'])) {
                                $change['new'][$dest] = $change['new'][$k];
                                unset($change['new'][$k]);
                            }
                          }
                        }

                        if ($change['property'] == 'exdate') {
                            $special_changes['exdate'] = $i;
                        }
                        else if ($change['property'] == 'rdate') {
                            $special_changes['rdate'] = $i;
                        }
                    });

                    // merge some recurrence changes
                    foreach (array('exdate','rdate') as $prop) {
                        if (array_key_exists($prop, $special_changes)) {
                            $exdate = $result['changes'][$special_changes[$prop]];
                            if (array_key_exists('recurrence', $special_changes)) {
                                $recurrence = &$result['changes'][$special_changes['recurrence']];
                            }
                            else {
                                $i = count($result['changes']);
                                $result['changes'][$i] = array('property' => 'recurrence', 'old' => array(), 'new' => array());
                                $recurrence = &$result['changes'][$i]['recurrence'];
                            }
                            $key = strtoupper($prop);
                            $recurrence['old'][$key] = $exdate['old'];
                            $recurrence['new'][$key] = $exdate['new'];
                            unset($result['changes'][$special_changes[$prop]]);
                        }
                    }

                    return $result;
                }
        */
        return false;
    }

    /**
     * Helper method to resolved the given task identifier into uid and folder
     *
     * @return array (uid,folder,msguid) tuple
     */
    /*
    private function _resolve_task_identity($prop)
    {
        $mailbox = $msguid = null;

        $this->_parse_id($prop);
        $uid     = $prop['uid'];
        $list_id = $prop['list'];

        if ($folder = $this->get_folder($list_id)) {
            $mailbox = $folder->get_mailbox_id();

            // get task object from storage in order to get the real object uid an msguid
            if ($rec = $folder->get_object($uid)) {
                $msguid = $rec['_msguid'];
                $uid = $rec['uid'];
            }
        }

        return array($uid, $mailbox, $msguid);
    }
    */

    /**
     * Get a list of pending alarms to be displayed to the user
     *
     * @param int   $time  Current time (unix timestamp)
     * @param mixed $lists List of list IDs to show alarms for (either as array or comma-separated string)
     *
     * @return array A list of alarms, each encoded as hash array with task properties
     * @see tasklist_driver::pending_alarms()
     */
    public function pending_alarms($time, $lists = null)
    {
        $interval = 300;
        $time -= $time % 60;

        $slot = $time;
        $slot -= $slot % $interval;

        $last = $time - max(60, $this->rc->config->get('refresh_interval', 0));
        $last -= $last % $interval;

        // only check for alerts once in 5 minutes
        if ($last == $slot) {
            return [];
        }

        if ($lists && is_string($lists)) {
            $lists = explode(',', $lists);
        }

        $time = $slot + $interval;

        $candidates = [];
        $query      = [
            ['tags', '=', 'x-has-alarms'],
            ['tags', '!=', 'x-complete'],
        ];

        $this->_read_lists();

        foreach ($this->lists as $lid => $list) {
            // skip lists with alarms disabled
            if (empty($list['showalarms']) || ($lists && !in_array($lid, $lists))) {
                continue;
            }

            $folder = $this->get_folder($lid);

            foreach ($folder->select($query) as $record) {
                if ((empty($record['valarms']) && empty($record['alarms']))
                    || $record['status'] == 'COMPLETED'
                    || $record['complete'] == 100
                ) {
                    // don't trust the query :-)
                    continue;
                }

                $task = $this->_to_rcube_task($record, $lid, false);

                // add to list if alarm is set
                $alarm = libcalendaring::get_next_alarm($task, 'task');
                if ($alarm && !empty($alarm['time']) && $alarm['time'] <= $time && in_array($alarm['action'], $this->alarm_types)) {
                    $id = $alarm['id'];  // use alarm-id as primary identifier
                    $candidates[$id] = [
                        'id'       => $id,
                        'title'    => $task['title'] ?? null,
                        'date'     => $task['date'] ?? null,
                        'time'     => $task['time'],
                        'notifyat' => $alarm['time'],
                        'action'   => $alarm['action'] ?? null,
                    ];
                }
            }
        }

        // get alarm information stored in local database
        if (!empty($candidates)) {
            $alarm_ids = array_map([$this->rc->db, 'quote'], array_keys($candidates));
            $result = $this->rc->db->query(
                "SELECT *"
                . " FROM " . $this->rc->db->table_name('kolab_alarms', true)
                . " WHERE `alarm_id` IN (" . implode(',', $alarm_ids) . ")"
                    . " AND `user_id` = ?",
                $this->rc->user->ID
            );

            while ($result && ($rec = $this->rc->db->fetch_assoc($result))) {
                $dbdata[$rec['alarm_id']] = $rec;
            }
        }

        $alarms = [];
        foreach ($candidates as $id => $task) {
            // skip dismissed
            if (!empty($dbdata[$id]['dismissed'])) {
                continue;
            }

            // snooze function may have shifted alarm time
            $notifyat = !empty($dbdata[$id]['notifyat']) ? strtotime($dbdata[$id]['notifyat']) : $task['notifyat'];
            if ($notifyat <= $time) {
                $alarms[] = $task;
            }
        }

        return $alarms;
    }

    /**
     * (User) feedback after showing an alarm notification
     * This should mark the alarm as 'shown' or snooze it for the given amount of time
     *
     * @param string $id     Task identifier
     * @param int    $snooze Suspend the alarm for this number of seconds
     */
    public function dismiss_alarm($id, $snooze = 0)
    {
        // delete old alarm entry
        $this->rc->db->query(
            "DELETE FROM " . $this->rc->db->table_name('kolab_alarms', true) . "
             WHERE `alarm_id` = ? AND `user_id` = ?",
            $id,
            $this->rc->user->ID
        );

        // set new notifyat time or unset if not snoozed
        $notifyat = $snooze > 0 ? date('Y-m-d H:i:s', time() + $snooze) : null;

        $query = $this->rc->db->query(
            "INSERT INTO " . $this->rc->db->table_name('kolab_alarms', true) . "
             (`alarm_id`, `user_id`, `dismissed`, `notifyat`)
             VALUES (?, ?, ?, ?)",
            $id,
            $this->rc->user->ID,
            $snooze > 0 ? 0 : 1,
            $notifyat
        );

        return $this->rc->db->affected_rows($query);
    }

    /**
     * Remove alarm dismissal or snooze state
     *
     * @param string $id Task identifier
     */
    public function clear_alarms($id)
    {
        // delete alarm entry
        $this->rc->db->query(
            "DELETE FROM " . $this->rc->db->table_name('kolab_alarms', true) . "
             WHERE `alarm_id` = ? AND `user_id` = ?",
            $id,
            $this->rc->user->ID
        );

        return true;
    }

    /**
     * Extract uid + list identifiers from the given input
     *
     * @param array|string $prop Array or a string with task identifier(s)
     */
    private function _parse_id(&$prop)
    {
        $id = null;
        if (is_array($prop)) {
            // 'uid' + 'list' available, nothing to be done
            if (!empty($prop['uid']) && !empty($prop['list'])) {
                return;
            }

            // 'id' is given
            if (!empty($prop['id'])) {
                if (!empty($prop['list'])) {
                    $list_id = !empty($prop['_fromlist']) ? $prop['_fromlist'] : $prop['list'];
                    if (strpos($prop['id'], $list_id . ':') === 0) {
                        $prop['uid'] = substr($prop['id'], strlen($list_id) + 1);
                    } else {
                        $prop['uid'] = $prop['id'];
                    }
                } else {
                    $id = $prop['id'];
                }
            }
        } else {
            $id = strval($prop);
            $prop = [];
        }

        // split 'id' into list + uid
        if (!empty($id)) {
            if (strpos($id, ':')) {
                [$list, $uid] = explode(':', $id, 2);
                $prop['uid'] = $uid;
                $prop['list'] = $list;
            } else {
                $prop['uid'] = $id;
            }
        }
    }

    /**
     * Convert from Kolab_Format to internal representation
     */
    private function _to_rcube_task($record, $list_id, $all = true)
    {
        $id_prefix = $list_id . ':';
        $task = [
            'id' => $id_prefix . $record['uid'],
            'uid' => $record['uid'],
            'title' => $record['title'] ?? '',
//            'location' => $record['location'],
            'description' => $record['description'] ?? '',
            'flagged' => !empty($record['priority']) && $record['priority'] == 1,
            'complete' => floatval(($record['complete'] ?? 0) / 100),
            'status' => $record['status'] ?? null,
            'parent_id' => !empty($record['parent_id']) ? $id_prefix . $record['parent_id'] : null,
            'recurrence' => $record['recurrence'] ?? [],
            'attendees' => $record['attendees'] ?? [],
            'organizer' => $record['organizer'] ?? null,
            'sequence' => $record['sequence'] ?? null,
            'list' => $list_id,
            'links' => [], // $record['links'],
        ];

        $task['tags'] = (array) ($record['categories'] ?? []);
        if (!empty($task['tags'])) {
            $this->tags = array_merge($this->tags, $task['tags']);
        }

        // convert from DateTime to internal date format
        if (isset($record['due']) && $record['due'] instanceof DateTimeInterface) {
            $due = $this->plugin->lib->adjust_timezone($record['due']);
            $task['date'] = $due->format('Y-m-d');
            if (empty($record['due']->_dateonly)) {
                $task['time'] = $due->format('H:i');
            }
        }
        // convert from DateTime to internal date format
        if (isset($record['start']) && $record['start'] instanceof DateTimeInterface) {
            $start = $this->plugin->lib->adjust_timezone($record['start']);
            $task['startdate'] = $start->format('Y-m-d');
            if (empty($record['start']->_dateonly)) {
                $task['starttime'] = $start->format('H:i');
            }
        }
        if (isset($record['changed']) && $record['changed'] instanceof DateTimeInterface) {
            $task['changed'] = $record['changed'];
        }
        if (isset($record['created']) && $record['created'] instanceof DateTimeInterface) {
            $task['created'] = $record['created'];
        }

        if (isset($record['valarms'])) {
            $task['valarms'] = $record['valarms'];
        } elseif (isset($record['alarms'])) {
            $task['alarms'] = $record['alarms'];
        }

        if (!empty($task['attendees'])) {
            foreach ((array) $task['attendees'] as $i => $attendee) {
                if (isset($attendee['delegated-from']) && is_array($attendee['delegated-from'])) {
                    $task['attendees'][$i]['delegated-from'] = implode(', ', $attendee['delegated-from']);
                }
                if (isset($attendee['delegated-to']) && is_array($attendee['delegated-to'])) {
                    $task['attendees'][$i]['delegated-to'] = implode(', ', $attendee['delegated-to']);
                }
            }
        }

        if (!empty($record['_attachments'])) {
            $attachments = [];
            foreach ($record['_attachments'] as $key => $attachment) {
                if ($attachment !== false) {
                    if (empty($attachment['name'])) {
                        $attachment['name'] = $key;
                    }
                    $attachments[] = $attachment;
                }
            }

            $task['attachments'] = $attachments;
        }

        return $task;
    }

    /**
     * Convert the given task record into a data structure that can be passed to kolab_storage backend for saving
     * (opposite of self::_to_rcube_event())
     */
    private function _from_rcube_task($task, $old = [])
    {
        $object    = $task;
        $id_prefix = $task['list'] . ':';

        $toDT = function ($date) {
            // Convert DateTime into libcalendaring_datetime
            return libcalendaring_datetime::createFromFormat(
                'Y-m-d\\TH:i:s',
                $date->format('Y-m-d\\TH:i:s'),
                $date->getTimezone()
            );
        };

        if (!empty($task['date'])) {
            $object['due'] = $toDT(rcube_utils::anytodatetime($task['date'] . ' ' . ($task['time'] ?? ''), $this->plugin->timezone));
            if (empty($task['time'])) {
                $object['due']->_dateonly = true;
            }
            unset($object['date']);
        }

        if (!empty($task['startdate'])) {
            $object['start'] = $toDT(rcube_utils::anytodatetime($task['startdate'] . ' ' . ($task['starttime'] ?? ''), $this->plugin->timezone));
            if (empty($task['starttime'])) {
                $object['start']->_dateonly = true;
            }
            unset($object['startdate']);
        }

        // as per RFC (and the Kolab schema validation), start and due dates need to be of the same type (#3614)
        // this should be catched in the client already but just make sure we don't write invalid objects
        if (!empty($object['start']) && !empty($object['due']) && $object['due']->_dateonly != $object['start']->_dateonly) {
            $object['start']->_dateonly = true;
            $object['due']->_dateonly = true;
        }

        $object['complete'] = $task['complete'] * 100;
        if ($task['complete'] == 1.0 && empty($task['complete'])) {
            $object['status'] = 'COMPLETED';
        }

        if (!empty($task['flagged'])) {
            $object['priority'] = 1;
        } else {
            $object['priority'] = isset($old['priority']) && $old['priority'] > 1 ? $old['priority'] : 0;
        }

        // remove list: prefix from parent_id
        if (!empty($task['parent_id']) && strpos($task['parent_id'], $id_prefix) === 0) {
            $object['parent_id'] = substr($task['parent_id'], strlen($id_prefix));
        }

        // copy meta data (starting with _) from old object
        foreach ((array) $old as $key => $val) {
            if (!isset($object[$key]) && $key[0] == '_') {
                $object[$key] = $val;
            }
        }

        // copy recurrence rules if the client didn't submit it (#2713)
        if (!array_key_exists('recurrence', $object) && !empty($old['recurrence'])) {
            $object['recurrence'] = $old['recurrence'];
        }

        unset($task['attachments']);
        kolab_format::merge_attachments($object, $old);

        // allow sequence increments if I'm the organizer
        if ($this->plugin->is_organizer($object) && empty($object['_method'])) {
            unset($object['sequence']);
        } elseif (isset($old['sequence']) && empty($object['_method'])) {
            $object['sequence'] = $old['sequence'];
        }

        $object['categories'] = (array) ($task['tags'] ?? []);

        unset($object['tempid'], $object['raw'], $object['list'], $object['flagged'], $object['created']);

        return $object;
    }

    /**
     * Add a single task to the database
     *
     * @param array $task Hash array with task properties (see header of tasklist_driver.php)
     *
     * @return mixed New task ID on success, False on error
     */
    public function create_task($task)
    {
        return $this->edit_task($task);
    }

    /**
     * Update a task entry with the given data
     *
     * @param array $task Hash array with task properties (see header of tasklist_driver.php)
     *
     * @return bool True on success, False on error
     */
    public function edit_task($task)
    {
        $this->_parse_id($task);

        if (empty($task['list']) || !($folder = $this->get_folder($task['list']))) {
            rcube::raise_error(
                [
                    'code' => 600, 'file' => __FILE__, 'line' => __LINE__,
                    'message' => "Invalid list identifer to save task: " . print_r($task['list'], true),
                ],
                true,
                false
            );

            return false;
        }

        // moved from another folder
        if (!empty($task['_fromlist']) && ($fromfolder = $this->get_folder($task['_fromlist']))) {
            if (!$fromfolder->move($task['uid'], $folder)) {
                return false;
            }

            unset($task['_fromlist']);
        }

        // load previous version of this task to merge
        if (!empty($task['id'])) {
            $old = $folder->get_object($task['uid']);
            if (!$old) {
                return false;
            }

            // merge existing properties if the update isn't complete
            if (!isset($task['title']) || !isset($task['complete'])) {
                $task += $this->_to_rcube_task($old, $task['list']);
            }
        }

        // generate new task object from RC input
        $object = $this->_from_rcube_task($task, $old ?? null);

        $object['created'] = $old['created'] ?? null;

        $saved = $folder->save($object, 'task', !empty($old) ? $task['uid'] : null);

        if (!$saved) {
            rcube::raise_error(
                [
                    'code' => 600, 'file' => __FILE__, 'line' => __LINE__,
                    'message' => "Error saving task object to Kolab server",
                ],
                true,
                false
            );

            return false;
        }

        $task = $this->_to_rcube_task($object, $task['list']);
        $this->tasks[$task['uid']] = $task;

        return true;
    }

    /**
     * Move a single task to another list
     *
     * @param array $task Hash array with task properties
     *
     * @return bool True on success, False on error
     * @see tasklist_driver::move_task()
     */
    public function move_task($task)
    {
        $this->_parse_id($task);

        if (empty($task['list']) || !($folder = $this->get_folder($task['list']))) {
            return false;
        }

        // execute move command
        if (!empty($task['_fromlist']) && ($fromfolder = $this->get_folder($task['_fromlist']))) {
            return $fromfolder->move($task['uid'], $folder);
        }

        return false;
    }

    /**
     * Remove a single task from the database
     *
     * @param array $task  Hash array with task properties:
     *                     id: Task identifier
     * @param bool  $force Remove record irreversible (mark as deleted otherwise, if supported by the backend)
     *
     * @return bool True on success, False on error
     */
    public function delete_task($task, $force = true)
    {
        $this->_parse_id($task);

        if (empty($task['list']) || !($folder = $this->get_folder($task['list']))) {
            return false;
        }

        $status = $folder->delete($task['uid'], $force);

        return $status;
    }

    /**
     * Restores a single deleted task (if supported)
     *
     * @param array $prop Hash array with task properties:
     *      id: Task identifier
     * @return bool True on success, False on error
     */
    public function undelete_task($prop)
    {
        // TODO: implement this
        return false;
    }

    /**
     * Accept an invitation to a shared folder
     *
     * @param string $href Invitation location href
     *
     * @return array|false
     */
    public function accept_share_invitation($href)
    {
        $folder = $this->storage->accept_share_invitation('task', $href);

        if ($folder === false) {
            return false;
        }

        // Activate the folder
        $prefs['kolab_tasklists'] = $this->rc->config->get('kolab_tasklists', []);
        $prefs['kolab_tasklists'][$folder->id]['active'] = true;

        $tasklist = $this->folder_props($folder, $prefs['kolab_tasklists']);

        $this->rc->user->save_prefs($prefs);

        return $tasklist;
    }

    /**
     * Get attachment properties
     *
     * @param string $id   Attachment identifier
     * @param array  $task Hash array with event properties:
     *         id: Task identifier
     *       list: List identifier
     *        rev: Revision (optional)
     *
     * @return array|null Hash array with attachment properties:
     *         id: Attachment identifier
     *       name: Attachment name
     *   mimetype: MIME content type of the attachment
     *       size: Attachment size
     */
    public function get_attachment($id, $task)
    {
        // get old revision of the object
        if (!empty($task['rev'])) {
            $task = $this->get_task_revison($task, $task['rev']);
        } else {
            $task = $this->get_task($task);
        }

        if ($task && !empty($task['attachments'])) {
            foreach ($task['attachments'] as $att) {
                if ($att['id'] == $id) {
                    if (!empty($att['data'])) {
                        // This way we don't have to call get_attachment_body() again
                        $att['body'] = &$att['data'];
                    }

                    return $att;
                }
            }
        }

        return null;
    }

    /**
     * Get attachment body
     *
     * @param string $id    Attachment identifier
     * @param array  $task  Hash array with event properties:
     *         id: Task identifier
     *       list: List identifier
     *        rev: Revision (optional)
     *
     * @return string|false Attachment body
     */
    public function get_attachment_body($id, $task)
    {
        $this->_parse_id($task);
        /*
                // get old revision of event
                if ($task['rev']) {
                    if (empty($this->bonnie_api)) {
                        return false;
                    }

                    $cid = substr($id, 4);

                    // call Bonnie API and get the raw mime message
                    list($uid, $mailbox, $msguid) = $this->_resolve_task_identity($task);
                    if ($msg_raw = $this->bonnie_api->rawdata('task', $uid, $task['rev'], $mailbox, $msguid)) {
                        // parse the message and find the part with the matching content-id
                        $message = rcube_mime::parse_message($msg_raw);
                        foreach ((array)$message->parts as $part) {
                            if ($part->headers['content-id'] && trim($part->headers['content-id'], '<>') == $cid) {
                                return $part->body;
                            }
                        }
                    }

                    return false;
                }
        */

        if ($storage = $this->get_folder($task['list'])) {
            return $storage->get_attachment($id, $task);
        }

        return false;
    }

    /**
     * Build a struct representing the given message reference
     *
     * @see tasklist_driver::get_message_reference()
     */
    public function get_message_reference($uri_or_headers, $folder = null)
    {
        return false;
    }

    /**
     * Find tasks assigned to a specified message
     *
     * @see tasklist_driver::get_message_related_tasks()
     */
    public function get_message_related_tasks($headers, $folder)
    {
        return [];
    }

    /**
     *
     */
    public function tasklist_edit_form($action, $list, $fieldprop)
    {
        $this->_read_lists();

        if (!empty($list['id']) && ($list = $this->lists[$list['id']])) {
            $folder = $this->get_folder($list['id']);
            $folder_name = $folder->name;
        } else {
            $folder_name = '';
        }

        $hidden_fields[] = ['name' => 'oldname', 'value' => $folder_name];

        // folder name (default field)
        $input_name = new html_inputfield(['name' => 'name', 'id' => 'taskedit-tasklistname', 'size' => 20]);
        $fieldprop['name']['value'] = $input_name->show($list['editname'] ?? '');

        // General tab
        $form = [
            'properties' => [
                'name'   => $this->rc->gettext('properties'),
                'fields' => [],
            ],
        ];

        foreach (['name', 'showalarms'] as $f) {
            $form['properties']['fields'][$f] = $fieldprop[$f];
        }

        return kolab_utils::folder_form($form, $folder ?? null, 'tasklist', $hidden_fields);
    }
}
