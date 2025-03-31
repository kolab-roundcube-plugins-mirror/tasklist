<?php

/**
 * Database driver for the Tasklist plugin
 *
 * @version @package_version@
 * @author Thomas Bruederli <bruederli@kolabsys.com>
 *
 * Copyright (C) 2012-2015, Kolab Systems AG <contact@kolabsys.com>
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

class tasklist_database_driver extends tasklist_driver
{
    public const IS_COMPLETE_SQL = "(`status` = 'COMPLETED' OR (`complete` = 1 AND `status` = ''))";

    public $undelete    = true; // yes, we can
    public $sortable    = false;
    public $alarm_types = ['DISPLAY'];

    private $db;
    private $rc;
    private $plugin;
    private $lists    = [];
    private $tags     = [];
    private $list_ids = '';
    private $db_tasks = 'tasks';
    private $db_lists = 'tasklists';


    /**
     * Default constructor
     */
    public function __construct($plugin)
    {
        $this->rc     = $plugin->rc;
        $this->plugin = $plugin;

        // read database config
        $this->db = $this->rc->get_dbh();
        $this->db_lists = $this->rc->config->get('db_table_lists', $this->db->table_name($this->db_lists));
        $this->db_tasks = $this->rc->config->get('db_table_tasks', $this->db->table_name($this->db_tasks));

        $this->_read_lists();
    }

    /**
     * Read available calendars for the current user and store them internally
     */
    private function _read_lists()
    {
        $hidden = array_filter(explode(',', $this->rc->config->get('hidden_tasklists', '')));

        if (!empty($this->rc->user->ID)) {
            $list_ids = [];
            $result = $this->db->query(
                "SELECT *, `tasklist_id` AS id FROM " . $this->db_lists
                . " WHERE `user_id` = ?"
                . " ORDER BY CASE WHEN `name` = 'INBOX' THEN 0 ELSE 1 END, `name`",
                $this->rc->user->ID
            );

            while ($result && ($arr = $this->db->fetch_assoc($result))) {
                $arr['showalarms'] = intval($arr['showalarms']);
                $arr['active']     = !in_array($arr['id'], $hidden);
                $arr['name']       = html::quote($arr['name']);
                $arr['listname']   = html::quote($arr['name']);
                $arr['editable']   = true;
                $arr['rights']     = 'lrswikxtea';

                $this->lists[$arr['id']] = $arr;
                $list_ids[] = $this->db->quote($arr['id']);
            }

            $this->list_ids = implode(',', $list_ids);
        }
    }

    /**
     * Get a list of available tasks lists from this source
     */
    public function get_lists($filter = 0)
    {
        // attempt to create a default list for this user
        if (empty($this->lists)) {
            $prop = ['name' => 'Default', 'color' => '000000'];
            if ($this->create_list($prop)) {
                $this->_read_lists();
            }
        }

        return $this->lists;
    }

    /**
     * Create a new list assigned to the current user
     *
     * @param array $prop Hash array with list properties
     *
     * @return string|false ID of the new list on success, False on error
     * @see tasklist_driver::create_list()
     */
    public function create_list(&$prop)
    {
        $result = $this->db->query(
            "INSERT INTO " . $this->db_lists
             . " (`user_id`, `name`, `color`, `showalarms`)"
             . " VALUES (?, ?, ?, ?)",
            $this->rc->user->ID,
            strval($prop['name']),
            isset($prop['color']) ? strval($prop['color']) : '',
            !empty($prop['showalarms']) ? 1 : 0
        );

        if ($result) {
            $prop['rights'] = 'lrswikxtea';
            return $this->db->insert_id($this->db_lists);
        }

        return false;
    }

    /**
     * Update properties of an existing tasklist
     *
     * @param array $prop Hash array with list properties
     *
     * @return bool|string True on success, Fales on failure
     * @see tasklist_driver::edit_list()
     */
    public function edit_list(&$prop)
    {
        $query = $this->db->query(
            "UPDATE " . $this->db_lists . " SET `name` = ?, `color` = ?, `showalarms` = ?"
             . " WHERE `tasklist_id` = ? AND `user_id` = ?",
            strval($prop['name']),
            isset($prop['color']) ? strval($prop['color']) : '',
            !empty($prop['showalarms']) ? 1 : 0,
            $prop['id'],
            $this->rc->user->ID
        );

        return $this->db->affected_rows($query) > 0;
    }

    /**
     * Set active/subscribed state of a list
     *
     * @param array $prop Hash array with list properties
     *
     * @return bool True on success, Fales on failure
     * @see tasklist_driver::subscribe_list()
     */
    public function subscribe_list($prop)
    {
        $hidden = array_flip(explode(',', $this->rc->config->get('hidden_tasklists', '')));

        if (!empty($prop['active'])) {
            unset($hidden[$prop['id']]);
        } else {
            $hidden[$prop['id']] = 1;
        }

        return $this->rc->user->save_prefs(['hidden_tasklists' => implode(',', array_keys($hidden))]);
    }

    /**
     * Delete the given list with all its contents
     *
     * @param array $prop Hash array with list properties
     *
     * @return bool True on success, Fales on failure
     * @see tasklist_driver::delete_list()
     */
    public function delete_list($prop)
    {
        $list_id = $prop['id'];

        if ($this->lists[$list_id]) {
            $query = $this->db->query(
                "DELETE FROM " . $this->db_lists . " WHERE `tasklist_id` = ? AND `user_id` = ?",
                $list_id,
                $this->rc->user->ID
            );

            return $this->db->affected_rows($query);
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
        return [];
    }

    /**
     * Get a list of tags to assign tasks to
     *
     * @return array List of tags
     */
    public function get_tags()
    {
        return array_values(array_unique($this->tags, SORT_STRING));
    }

    /**
     * Get number of tasks matching the given filter
     *
     * @param array $lists List of lists to count tasks of
     *
     * @return array Hash array with counts grouped by status (all|flagged|today|tomorrow|overdue|nodate)
     * @see tasklist_driver::count_tasks()
     */
    public function count_tasks($lists = null)
    {
        if (empty($lists)) {
            $lists = array_keys($this->lists);
        } elseif (!is_array($lists)) {
            $lists = explode(',', (string) $lists);
        }

        // only allow to select from lists of this user
        $list_ids = array_map([$this->rc->db, 'quote'], array_intersect($lists, array_keys($this->lists)));

        $today_date    = new DateTime('now', $this->plugin->timezone);
        $today         = $today_date->format('Y-m-d');
        $tomorrow_date = new DateTime('now + 1 day', $this->plugin->timezone);
        $tomorrow      = $tomorrow_date->format('Y-m-d');

        $result = $this->db->query(sprintf(
            "SELECT `task_id`, `flagged`, `date` FROM " . $this->db_tasks
             . " WHERE `tasklist_id` IN (%s) AND `del` = 0 AND NOT " . self::IS_COMPLETE_SQL,
            implode(',', $list_ids)
        ));

        $counts = ['all' => 0, 'today' => 0, 'tomorrow' => 0, 'overdue' => 0, 'later' => 0];
        while ($result && ($rec = $this->db->fetch_assoc($result))) {
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

        return $counts;
    }

    /**
     * Get all task records matching the given filter
     *
     * @param array $filter Hash array wiht filter criterias
     * @param array $lists  List of lists to get tasks from
     *
     * @return array List of tasks records matchin the criteria
     * @see tasklist_driver::list_tasks()
     */
    public function list_tasks($filter, $lists = null)
    {
        if (empty($lists)) {
            $lists = array_keys($this->lists);
        } elseif (!is_array($lists)) {
            $lists = explode(',', (string) $lists);
        }

        // only allow to select from lists of this user
        $list_ids = array_map([$this->rc->db, 'quote'], array_intersect($lists, array_keys($this->lists)));
        $sql_add  = '';

        // add filter criteria
        if ($filter) {
            if (!empty($filter['from']) || ($filter['mask'] & tasklist::FILTER_MASK_TODAY)) {
                $sql_add .= " AND (`date` IS NULL OR `date` >= " . $this->db->quote($filter['from']) . ")";
            }

            if (!empty($filter['to'])) {
                if ($filter['mask'] & tasklist::FILTER_MASK_OVERDUE) {
                    $sql_add .= " AND (`date` IS NOT NULL AND `date` <= " . $this->db->quote($filter['to']) . ")";
                } else {
                    $sql_add .= " AND (`date` IS NULL OR `date` <= " . $this->db->quote($filter['to']) . ")";
                }
            }

            if ($filter['mask'] & tasklist::FILTER_MASK_NODATE) {
                $sql_add = " AND `date` IS NULL";
            }

            if ($filter['mask'] & tasklist::FILTER_MASK_COMPLETE) {
                $sql_add .= " AND " . self::IS_COMPLETE_SQL;
            } elseif (empty($filter['since'])) {
                // don't show complete tasks by default
                $sql_add .= " AND NOT " . self::IS_COMPLETE_SQL;
            }

            if ($filter['mask'] & tasklist::FILTER_MASK_FLAGGED) {
                $sql_add .= " AND `flagged` = 1";
            }

            // compose (slow) SQL query for searching
            // FIXME: improve searching using a dedicated col and normalized values
            if ($filter['search']) {
                $sql_query = [];
                foreach (['title', 'description', 'organizer', 'attendees'] as $col) {
                    $sql_query[] = $this->db->ilike($col, '%' . $filter['search'] . '%');
                }
                $sql_add = " AND (" . implode(" OR ", $sql_query) . ")";
            }

            if (!empty($filter['since']) && is_numeric($filter['since'])) {
                $sql_add .= " AND `changed` >= " . $this->db->quote(date('Y-m-d H:i:s', $filter['since']));
            }

            if (!empty($filter['uid'])) {
                $sql_add .= " AND `uid` IN (" . implode(',', array_map([$this->rc->db, 'quote'], $filter['uid'])) . ")";
            }
        }

        $tasks = [];
        if (!empty($list_ids)) {
            $result = $this->db->query(
                "SELECT * FROM " . $this->db_tasks
                . " WHERE `tasklist_id` IN (" . implode(',', $list_ids) . ")"
                    . " AND `del` = 0" . $sql_add
                . " ORDER BY `parent_id`, `task_id` ASC"
            );

            while ($result && ($rec = $this->db->fetch_assoc($result))) {
                $tasks[] = $this->_read_postprocess($rec);
            }
        }

        return $tasks;
    }

    /**
     * Return data of a specific task
     *
     * @param mixed $prop   Hash array with task properties or task UID
     * @param int   $filter Bitmask defining filter criterias.
     *                      See FILTER_* constants for possible values.
     *
     * @return array|false Hash array with task properties or false if not found
     */
    public function get_task($prop, $filter = 0)
    {
        if (is_string($prop)) {
            $prop = ['uid' => $prop];
        }

        $query_col = !empty($prop['id']) ? 'task_id' : 'uid';

        $result = $this->db->query(
            "SELECT * FROM " . $this->db_tasks
            . " WHERE `tasklist_id` IN (" . $this->list_ids . ")"
            . " AND `$query_col` = ? AND `del` = 0",
            !empty($prop['id']) ? $prop['id'] : $prop['uid']
        );

        if ($result && ($rec = $this->db->fetch_assoc($result))) {
            return $this->_read_postprocess($rec);
        }

        return false;
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
        // resolve UID first
        if (is_string($prop)) {
            $result = $this->db->query(
                "SELECT `task_id` AS id, `tasklist_id` AS list FROM " . $this->db_tasks
                . " WHERE `tasklist_id` IN (" . $this->list_ids . ")"
                    . " AND `uid` = ?",
                $prop
            );

            $prop = $this->db->fetch_assoc($result);
        }

        $childs   = [];
        $task_ids = [$prop['id']];

        // query for childs (recursively)
        while (!empty($task_ids)) {
            $result = $this->db->query(
                "SELECT `task_id` AS id FROM " . $this->db_tasks
                . " WHERE `tasklist_id` IN (" . $this->list_ids . ")"
                    . " AND `parent_id` IN (" . implode(',', array_map([$this->rc->db, 'quote'], $task_ids)) . ")"
                    . " AND `del` = 0"
            );

            $task_ids = [];
            while ($result && ($rec = $this->db->fetch_assoc($result))) {
                $childs[]   = $rec['id'];
                $task_ids[] = $rec['id'];
            }

            if (!$recursive) {
                break;
            }
        }

        return $childs;
    }

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
        if (empty($lists)) {
            $lists = array_keys($this->lists);
        } elseif (!is_array($lists)) {
            $lists = explode(',', (string) $lists);
        }

        // only allow to select from calendars with activated alarms
        $list_ids = [];
        foreach ($lists as $lid) {
            if ($this->lists[$lid] && $this->lists[$lid]['showalarms']) {
                $list_ids[] = $lid;
            }
        }
        $list_ids = array_map([$this->rc->db, 'quote'], $list_ids);

        $alarms = [];
        if (!empty($list_ids)) {
            $result = $this->db->query(
                "SELECT * FROM " . $this->db_tasks
                . " WHERE `tasklist_id` IN (" . implode(',', $list_ids) . ")"
                    . " AND `notify` <= " . $this->db->fromunixtime($time)
                    . " AND NOT " . self::IS_COMPLETE_SQL
            );

            while ($result && ($rec = $this->db->fetch_assoc($result))) {
                $alarms[] = $this->_read_postprocess($rec);
            }
        }

        return $alarms;
    }

    /**
     * Feedback after showing/sending an alarm notification
     *
     * @see tasklist_driver::dismiss_alarm()
     */
    public function dismiss_alarm($task_id, $snooze = 0)
    {
        // set new notifyat time or unset if not snoozed
        $notify_at = $snooze > 0 ? date('Y-m-d H:i:s', time() + $snooze) : null;

        $query = $this->db->query(
            "UPDATE " . $this->db_tasks
            . " SET `changed` = " . $this->db->now() . ", `notify` = ?"
            . " WHERE `task_id` = ? AND `tasklist_id` IN (" . $this->list_ids . ")",
            $notify_at,
            $task_id
        );

        return $this->db->affected_rows($query);
    }

    /**
     * Remove alarm dismissal or snooze state
     *
     * @param string $id Task identifier
     */
    public function clear_alarms($id)
    {
        // Nothing to do here. Alarms are reset in edit_task()
    }

    /**
     * Map some internal database values to match the generic "API"
     */
    private function _read_postprocess($rec)
    {
        $rec['id']      = $rec['task_id'];
        $rec['list']    = $rec['tasklist_id'];
        $rec['changed'] = new DateTime($rec['changed']);
        $rec['created'] = new DateTime($rec['created']);
        $rec['tags']    = array_filter(explode(',', $rec['tags']));

        if (!$rec['parent_id']) {
            unset($rec['parent_id']);
        }

        // decode serialized alarms
        if ($rec['alarms']) {
            $rec['valarms'] = $this->unserialize_alarms($rec['alarms']);
            unset($rec['alarms']);
        }

        // decode serialze recurrence rules
        if ($rec['recurrence']) {
            $rec['recurrence'] = $this->unserialize_recurrence($rec['recurrence']);
        }

        if (!empty($rec['tags'])) {
            $this->tags = array_merge($this->tags, (array)$rec['tags']);
        }

        unset($rec['task_id'], $rec['tasklist_id']);

        return $rec;
    }

    /**
     * Add a single task to the database
     *
     * @param array $prop Hash array with task properties (see header of this file)
     *
     * @return mixed New event ID on success, False on error
     * @see tasklist_driver::create_task()
     */
    public function create_task($prop)
    {
        // check list permissions
        $list_id = !empty($prop['list']) ? $prop['list'] : reset(array_keys($this->lists));
        if (empty($this->lists[$list_id]) || !empty($this->lists[$list_id]['readonly'])) {
            return false;
        }

        if (!empty($prop['valarms'])) {
            $prop['alarms'] = $this->serialize_alarms($prop['valarms']);
        }

        if (!empty($prop['recurrence'])) {
            $prop['recurrence'] = $this->serialize_recurrence($prop['recurrence']);
        }

        if (array_key_exists('complete', $prop) && !empty($prop['complete'])) {
            $prop['complete'] = number_format($prop['complete'], 2, '.', '');
        }

        foreach (['parent_id', 'date', 'time', 'startdate', 'starttime', 'alarms', 'recurrence', 'status', 'complete'] as $col) {
            if (empty($prop[$col])) {
                $prop[$col] = null;
            }
        }

        $notify_at = $this->_get_notification($prop);
        $now       = $this->db->now();

        $result = $this->db->query(
            "INSERT INTO " . $this->db_tasks
            . " (`tasklist_id`, `uid`, `parent_id`, `created`, `changed`, `title`, `date`, `time`,"
                . " `startdate`, `starttime`, `description`, `tags`, `flagged`, `complete`, `status`,"
                . " `alarms`, `recurrence`, `notify`)"
            . " VALUES (?, ?, ?, $now, $now, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)",
            $list_id,
            $prop['uid'],
            $prop['parent_id'],
            $prop['title'],
            $prop['date'],
            $prop['time'],
            $prop['startdate'],
            $prop['starttime'],
            isset($prop['description']) ? strval($prop['description']) : '',
            !empty($prop['tags']) ? implode(',', (array)$prop['tags']) : '',
            !empty($prop['flagged']) ? 1 : 0,
            $prop['complete'] ?: 0,
            strval($prop['status']),
            $prop['alarms'] ?? '',
            $prop['recurrence'] ?? '',
            $notify_at
        );

        if ($result) {
            return $this->db->insert_id($this->db_tasks);
        }

        return false;
    }

    /**
     * Update an task entry with the given data
     *
     * @param array $prop Hash array with task properties
     *
     * @return bool True on success, False on error
     * @see tasklist_driver::edit_task()
     */
    public function edit_task($prop)
    {
        if (isset($prop['valarms'])) {
            $prop['alarms'] = $this->serialize_alarms($prop['valarms']);
        }
        if (isset($prop['recurrence'])) {
            $prop['recurrence'] = $this->serialize_recurrence($prop['recurrence']);
        }
        if (array_key_exists('complete', $prop)) {
            $prop['complete'] = number_format($prop['complete'], 2, '.', '');
        }

        $sql_set = [];
        foreach (['title', 'description', 'flagged', 'complete'] as $col) {
            if (isset($prop[$col])) {
                $sql_set[] = $this->db->quote_identifier($col) . '=' . $this->db->quote($prop[$col]);
            }
        }
        foreach (['parent_id', 'date', 'time', 'startdate', 'starttime', 'alarms', 'recurrence'] as $col) {
            if (isset($prop[$col])) {
                $sql_set[] = $this->db->quote_identifier($col) . '=' . (empty($prop[$col]) ? 'NULL' : $this->db->quote($prop[$col]));
            }
        }
        if (isset($prop['status'])) {
            $sql_set[] = $this->db->quote_identifier('status') . '=' . $this->db->quote($prop['status']);
        }
        if (isset($prop['tags'])) {
            $sql_set[] = $this->db->quote_identifier('tags') . '=' . $this->db->quote(implode(',', (array)$prop['tags']));
        }

        if (isset($prop['date']) || isset($prop['time']) || isset($prop['alarms'])) {
            $notify_at = $this->_get_notification($prop);
            $sql_set[] = $this->db->quote_identifier('notify') . '=' . (empty($notify_at) ? 'NULL' : $this->db->quote($notify_at));
        }

        // moved from another list
        if (!empty($prop['_fromlist']) && ($newlist = $prop['list'])) {
            $sql_set[] = $this->db->quote_identifier('tasklist_id') . '=' . $this->db->quote($newlist);
        }

        $result = $this->db->query(
            "UPDATE " . $this->db_tasks
            . " SET `changed` = " . $this->db->now() . ($sql_set ? ', ' . implode(', ', $sql_set) : '')
            . " WHERE `task_id` = ? AND `tasklist_id` IN (" . $this->list_ids . ")",
            $prop['id']
        );

        return $this->db->affected_rows($result);
    }

    /**
     * Move a single task to another list
     *
     * @param array $prop Hash array with task properties
     *
     * @return bool True on success, False on error
     * @see tasklist_driver::move_task()
     */
    public function move_task($prop)
    {
        return $this->edit_task($prop);
    }

    /**
     * Remove a single task from the database
     *
     * @param array $prop  Hash array with task properties
     * @param bool  $force Remove record irreversible
     *
     * @return bool True on success, False on error
     * @see tasklist_driver::delete_task()
     */
    public function delete_task($prop, $force = true)
    {
        if (empty($prop['id'])) {
            return false;
        }

        $task_id = $prop['id'];

        if ($force) {
            $result = $this->db->query(
                "DELETE FROM " . $this->db_tasks
                . " WHERE `task_id` = ? AND `tasklist_id` IN (" . $this->list_ids . ")",
                $task_id
            );
        } else {
            $result = $this->db->query(
                "UPDATE " . $this->db_tasks
                . " SET `changed` = " . $this->db->now() . ", `del` = 1"
                . " WHERE `task_id` = ? AND `tasklist_id` IN (" . $this->list_ids . ")",
                $task_id
            );
        }

        return $this->db->affected_rows($result) > 0;
    }

    /**
     * Restores a single deleted task (if supported)
     *
     * @param array $prop Hash array with task properties
     *
     * @return bool True on success, False on error
     * @see tasklist_driver::undelete_task()
     */
    public function undelete_task($prop)
    {
        $result = $this->db->query(
            "UPDATE " . $this->db_tasks
            . " SET `changed` = " . $this->db->now() . ", `del` = 0"
            . " WHERE `task_id` = ? AND `tasklist_id` IN (" . $this->list_ids . ")",
            $prop['id']
        );

        return $this->db->affected_rows($result);
    }

    /**
     * Compute absolute time to notify the user
     */
    private function _get_notification($task)
    {
        if (!empty($task['valarms']) && !$this->is_complete($task)) {
            $alarm = libcalendaring::get_next_alarm($task, 'task');

            if (!empty($alarm['time']) && in_array($alarm['action'], $this->alarm_types)) {
                return date('Y-m-d H:i:s', $alarm['time']);
            }
        }
    }

    /**
     * Helper method to serialize the list of alarms into a string
     */
    private function serialize_alarms($valarms)
    {
        foreach ((array)$valarms as $i => $alarm) {
            if ($alarm['trigger'] instanceof DateTime) {
                $valarms[$i]['trigger'] = '@' . $alarm['trigger']->format('c');
            }
        }

        return $valarms ? json_encode($valarms) : null;
    }

    /**
     * Helper method to decode a serialized list of alarms
     */
    private function unserialize_alarms($alarms)
    {
        // decode json serialized alarms
        if ($alarms && $alarms[0] == '[') {
            $valarms = json_decode($alarms, true);
            foreach ($valarms as $i => $alarm) {
                if ($alarm['trigger'][0] == '@') {
                    try {
                        $valarms[$i]['trigger'] = new DateTime(substr($alarm['trigger'], 1));
                    } catch (Exception $e) {
                        unset($valarms[$i]);
                    }
                }
            }
        }
        // convert legacy alarms data
        elseif (strlen($alarms)) {
            [$trigger, $action] = explode(':', $alarms, 2);
            if ($trigger = libcalendaring::parse_alarm_value($trigger)) {
                $valarms = [['action' => $action, 'trigger' => $trigger[3] ?: $trigger[0]]];
            }
        }

        return $valarms ?? null;
    }

    /**
     * Helper method to serialize task recurrence properties
     */
    private function serialize_recurrence($recurrence)
    {
        foreach ((array)$recurrence as $k => $val) {
            if ($val instanceof DateTime) {
                $recurrence[$k] = '@' . $val->format('c');
            }
        }

        return $recurrence ? json_encode($recurrence) : null;
    }

    /**
     * Helper method to decode a serialized task recurrence struct
     */
    private function unserialize_recurrence($ser)
    {
        if (strlen($ser)) {
            $recurrence = json_decode($ser, true);
            foreach ((array)$recurrence as $k => $val) {
                if ($val[0] == '@') {
                    try {
                        $recurrence[$k] = new DateTime(substr($val, 1));
                    } catch (Exception $e) {
                        unset($recurrence[$k]);
                    }
                }
            }
        } else {
            $recurrence = '';
        }

        return $recurrence;
    }

    /**
     * Handler for user_delete plugin hook
     */
    public function user_delete($args)
    {
        $lists = $this->db->query("SELECT `tasklist_id` FROM " . $this->db_lists . " WHERE `user_id` = ?", $args['user']->ID);

        $list_ids = [];
        while ($row = $this->db->fetch_assoc($lists)) {
            $list_ids[] = $row['tasklist_id'];
        }

        if (!empty($list_ids)) {
            foreach ([$this->db_tasks, $this->db_lists] as $table) {
                $this->db->query(sprintf("DELETE FROM $table WHERE `tasklist_id` IN (%s)", implode(',', $list_ids)));
            }
        }

        return $args;
    }
}
