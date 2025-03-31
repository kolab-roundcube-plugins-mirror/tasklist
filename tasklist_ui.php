<?php

/**
 * User Interface class for the Tasklist plugin
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

class tasklist_ui
{
    private $rc;
    private $plugin;
    private $ready = false;
    private $action;
    private $list;
    private $gui_objects = [];

    public function __construct($plugin)
    {
        $this->plugin = $plugin;
        $this->rc     = $plugin->rc;
    }

    /**
     * Calendar UI initialization and requests handlers
     */
    public function init()
    {
        if ($this->ready) {
            return;
        }

        // add taskbar button
        $this->plugin->add_button([
            'command'    => 'tasks',
            'class'      => 'button-tasklist',
            'classsel'   => 'button-tasklist button-selected',
            'innerclass' => 'button-inner',
            'label'      => 'tasklist.navtitle',
            'type'       => 'link',
        ], 'taskbar');

        $this->plugin->include_stylesheet($this->plugin->local_skin_path() . '/tasklist.css');

        if ($this->rc->task == 'mail' || $this->rc->task == 'tasks') {
            $this->plugin->include_script('tasklist_base.js');

            // copy config to client
            $this->rc->output->set_env('tasklist_settings', $this->load_settings());

            // initialize attendees autocompletion
            $this->rc->autocomplete_init();
        }

        $this->ready = true;
    }

    /**
     *
     */
    public function load_settings()
    {
        $settings = [];

        $settings['invite_shared'] = (int)$this->rc->config->get('calendar_allow_invite_shared', 0);
        $settings['itip_notify']   = (int)$this->rc->config->get('calendar_itip_send_option', 3);
        $settings['sort_col']      = $this->rc->config->get('tasklist_sort_col', '');
        $settings['sort_order']    = $this->rc->config->get('tasklist_sort_order', 'asc');

        // get user identity to create default attendee
        foreach ($this->rc->user->list_emails() as $rec) {
            if (empty($identity)) {
                $identity = $rec;
            }

            $identity['emails'][] = $rec['email'];
            $settings['identities'][$rec['identity_id']] = $rec['email'];
        }

        $identity['emails'][] = $this->rc->user->get_username();
        $settings['identity'] = [
            'name'   => $identity['name'],
            'email'  => strtolower($identity['email']),
            'emails' => ';' . strtolower(implode(';', $identity['emails'])),
        ];

        if ($list = rcube_utils::get_input_value('_list', rcube_utils::INPUT_GPC)) {
            $settings['selected_list'] = $list;
        }
        if ($list && ($id = rcube_utils::get_input_value('_id', rcube_utils::INPUT_GPC))) {
            $settings['selected_id'] = $id;

            // check if the referenced task is completed
            $task = $this->plugin->driver->get_task(['id' => $id, 'list' => $list]);
            if ($task && $this->plugin->driver->is_complete($task)) {
                $settings['selected_filter'] = 'complete';
            }
        } elseif ($filter = rcube_utils::get_input_value('_filter', rcube_utils::INPUT_GPC)) {
            $settings['selected_filter'] = $filter;
        }

        return $settings;
    }

    /**
     * Render a HTML select box for user identity selection
     */
    public function identity_select($attrib = [])
    {
        $attrib['name'] = 'identity';
        $select         = new html_select($attrib);
        $identities     = $this->rc->user->list_emails();

        foreach ($identities as $ident) {
            $select->add(format_email_recipient($ident['email'], $ident['name']), $ident['identity_id']);
        }

        return $select->show(null);
    }

    /**
    * Register handler methods for the template engine
    */
    public function init_templates()
    {
        $this->plugin->register_handler('plugin.tasklists', [$this, 'tasklists']);
        $this->plugin->register_handler('plugin.tasklist_select', [$this, 'tasklist_select']);
        $this->plugin->register_handler('plugin.status_select', [$this, 'status_select']);
        $this->plugin->register_handler('plugin.searchform', [$this->rc->output, 'search_form']);
        $this->plugin->register_handler('plugin.quickaddform', [$this, 'quickadd_form']);
        $this->plugin->register_handler('plugin.tasks', [$this, 'tasks_resultview']);
        $this->plugin->register_handler('plugin.tags_editline', [$this, 'tags_editline']);
        $this->plugin->register_handler('plugin.alarm_select', [$this, 'alarm_select']);
        $this->plugin->register_handler('plugin.recurrence_form', [$this->plugin->lib, 'recurrence_form']);
        $this->plugin->register_handler('plugin.attendees_list', [$this, 'attendees_list']);
        $this->plugin->register_handler('plugin.attendees_form', [$this, 'attendees_form']);
        $this->plugin->register_handler('plugin.identity_select', [$this, 'identity_select']);
        $this->plugin->register_handler('plugin.edit_attendees_notify', [$this, 'edit_attendees_notify']);
        $this->plugin->register_handler('plugin.task_rsvp_buttons', [$this->plugin->itip, 'itip_rsvp_buttons']);
        $this->plugin->register_handler('plugin.object_changelog_table', ['libkolab', 'object_changelog_table']);
        $this->plugin->register_handler('plugin.tasks_export_form', [$this, 'tasks_export_form']);
        $this->plugin->register_handler('plugin.tasks_import_form', [$this, 'tasks_import_form']);

        kolab_attachments_handler::ui();

        $this->plugin->include_script('tasklist.js');
        $this->plugin->api->include_script('libkolab/libkolab.js');
    }

    /**
     *
     */
    public function tasklists($attrib = [])
    {
        $tree  = true;
        $jsenv = [];
        $lists = $this->plugin->driver->get_lists(0, $tree);

        if (empty($attrib['id'])) {
            $attrib['id'] = 'rcmtasklistslist';
        }

        // walk folder tree
        // @phpstan-ignore-next-line FIXME: does it make sense?
        if (is_object($tree)) {
            $html = $this->list_tree_html($tree, $lists, $jsenv, $attrib);
        } else {
            // fall-back to flat folder listing
            $attrib['class'] = ($attrib['class'] ?? '') . ' flat';
            $html = '';

            foreach ((array) $lists as $id => $prop) {
                if (!empty($attrib['activeonly']) && empty($prop['active'])) {
                    continue;
                }

                $html .= html::tag(
                    'li',
                    [
                        'id' => 'rcmlitasklist' . rcube_utils::html_identifier($id),
                        'class' => $prop['group'] ?? null,
                    ],
                    $this->tasklist_list_item($id, $prop, $jsenv, !empty($attrib['activeonly']))
                );
            }
        }

        $this->rc->output->include_script('treelist.js');

        $this->rc->output->set_env('source', rcube_utils::get_input_value('source', rcube_utils::INPUT_GET));
        $this->rc->output->set_env('tasklists', $jsenv);
        $this->register_gui_object('tasklistslist', $attrib['id']);

        return html::tag('ul', $attrib, $html, html::$common_attrib);
    }

    /**
     * Return html for a structured list <ul> for the folder tree
     */
    public function list_tree_html($node, $data, &$jsenv, $attrib)
    {
        $out = '';
        foreach ($node->children as $folder) {
            $id = $folder->id;
            $prop = $data[$id];
            $is_collapsed = false; // TODO: determine this somehow?

            $content = $this->tasklist_list_item($id, $prop, $jsenv, !empty($attrib['activeonly']));

            if (!empty($folder->children)) {
                $content .= html::tag(
                    'ul',
                    ['style' => ($is_collapsed ? "display:none;" : null)], // @phpstan-ignore-line
                    $this->list_tree_html($folder, $data, $jsenv, $attrib)
                );
            }

            if (strlen($content)) {
                $out .= html::tag(
                    'li',
                    [
                      'id' => 'rcmlitasklist' . rcube_utils::html_identifier($id),
                      'class' => $prop['group'] . ($prop['virtual'] ? ' virtual' : ''),
                    ],
                    $content
                );
            }
        }

        return $out;
    }

    /**
     * Helper method to build a tasklist item (HTML content and js data)
     */
    public function tasklist_list_item($id, $prop, &$jsenv, $activeonly = false)
    {
        // enrich list properties with settings from the driver
        if (empty($prop['virtual'])) {
            unset($prop['user_id']);
            $prop['alarms']      = $this->plugin->driver->alarms;
            $prop['undelete']    = $this->plugin->driver->undelete;
            $prop['sortable']    = $this->plugin->driver->sortable;
            $prop['attachments'] = $this->plugin->driver->attachments;
            $prop['attendees']   = $this->plugin->driver->attendees;
            $prop['caldavurl']   = $this->plugin->driver->tasklist_caldav_url($prop);
            $jsenv[$id] = $prop;
        }

        $classes = ['tasklist'];
        $title   = '';

        if (!empty($prop['title'])) {
            $title = $prop['title'];
        } elseif (empty($prop['listname']) || $prop['name'] != $prop['listname'] || strlen($prop['name']) > 25) {
            $title = html_entity_decode($prop['name'], ENT_COMPAT, RCUBE_CHARSET);
        }

        if (!empty($prop['virtual'])) {
            $classes[] = 'virtual';
        } elseif (strpos($prop['rights'], 'i') === false && strpos($prop['rights'], 'w') === false) {
            $classes[] = 'readonly';
        }
        if (!empty($prop['subscribed'])) {
            $classes[] = 'subscribed';
        }
        if (!empty($prop['class'])) {
            $classes[] = $prop['class'];
        }

        if (!$activeonly || !empty($prop['active'])) {
            $label_id = 'tl:' . $id;
            $listname = !empty($prop['listname']) ? $prop['listname'] : $prop['name'];
            $actions = '';

            $chbox = html::tag('input', [
                    'type'    => 'checkbox',
                    'name'    => '_list[]',
                    'value'   => $id,
                    'checked' => !empty($prop['active']) && empty($prop['share_invitation']),
                    'title'   => $this->plugin->gettext('activate'),
                    'aria-labelledby' => $label_id,
            ]);

            if (!empty($prop['removable'])) {
                $actions .= html::a(['href' => '#', 'class' => 'remove', 'title' => $this->plugin->gettext('removelist')], ' ');
            }

            $actions .= html::a(
                [
                    'href' => '#',
                    'class' => 'quickview',
                    'title' => $this->plugin->gettext('focusview'),
                    'role' => 'checkbox',
                    'aria-checked' => 'false',
                    'style' => !empty($prop['share_invitation']) ? 'display:none' : null,
                ],
                ' '
            );

            if (isset($prop['subscribed'])) {
                $actions .= html::a(
                    [
                        'href' => '#',
                        'class' => 'subscribed',
                        'title' => $this->plugin->gettext('tasklistsubscribe'),
                        'role' => 'checkbox',
                        'aria-checked' => $prop['subscribed'] ? 'true' : 'false',
                    ],
                    ' '
                );
            }

            return html::div(
                implode(' ', $classes),
                html::a(['class' => 'listname', 'title' => $title, 'href' => '#', 'id' => $label_id], $listname)
                    . (!empty($prop['virtual']) ? '' : $chbox . html::span('actions', $actions))
            );
        }

        return '';
    }

    /**
     * Render HTML form for task status selector
     */
    public function status_select($attrib = [])
    {
        $attrib['name'] = 'status';
        $select = new html_select($attrib);
        $select->add('---', '');
        $select->add($this->plugin->gettext('status-needs-action'), 'NEEDS-ACTION');
        $select->add($this->plugin->gettext('status-in-process'), 'IN-PROCESS');
        $select->add($this->plugin->gettext('status-completed'), 'COMPLETED');
        $select->add($this->plugin->gettext('status-cancelled'), 'CANCELLED');

        return $select->show(null);
    }

    /**
     * Render a HTML select box for list selection
     */
    public function tasklist_select($attrib = [])
    {
        if (empty($attrib['name'])) {
            $attrib['name'] = 'list';
        }

        $attrib['is_escaped'] = true;
        $select = new html_select($attrib);
        $default = null;

        if (!empty($attrib['extra'])) {
            foreach ((array) $attrib['extra'] as $id => $name) {
                $select->add($name, $id);
            }
        }

        foreach ((array) $this->plugin->driver->get_lists() as $id => $prop) {
            if (!empty($prop['rights']) && strpos($prop['rights'], 'i') !== false) {
                $select->add($prop['name'], $id);
                if (!$default || !empty($prop['default'])) {
                    $default = $id;
                }
            }
        }

        return $select->show($default);
    }

    public function tasklist_editform($action, $list = [])
    {
        $this->action = $action;
        $this->list   = $list;

        $this->rc->output->set_env('pagetitle', $this->plugin->gettext('arialabeltasklistform'));
        $this->rc->output->add_handler('folderform', [$this, 'tasklistform']);
        $this->rc->output->send('libkolab.folderform');
    }

    public function tasklistform($attrib)
    {
        $fields = [
            'name' => [
                'id'    => 'taskedit-tasklistname',
                'label' => $this->plugin->gettext('listname'),
                'value' => html::tag('input', ['id' => 'taskedit-tasklistname', 'name' => 'name', 'type' => 'text', 'class' => 'text', 'size' => 40]),
            ],
/*
            'color' => array(
                'id'    => 'taskedit-color',
                'label' => $this->plugin->gettext('color'),
                'value' => html::tag('input', array('id' => 'taskedit-color', 'name' => 'color', 'type' => 'text', 'class' => 'text colorpicker', 'size' => 6)),
            ),
*/
            'showalarms' => [
                'id'    => 'taskedit-showalarms',
                'label' => $this->plugin->gettext('showalarms'),
                'value' => html::tag('input', ['id' => 'taskedit-showalarms', 'name' => 'showalarms', 'type' => 'checkbox', 'value' => 1]),
            ],
        ];

        return html::tag(
            'form',
            $attrib + ['action' => "#", 'method' => "post", 'id' => 'tasklisteditform'],
            $this->plugin->driver->tasklist_edit_form($this->action, $this->list, $fields)
        );
    }

    /**
     * Render HTML form for alarm configuration
     */
    public function alarm_select($attrib = [])
    {
        $attrib['_type'] = 'task';
        return $this->plugin->lib->alarm_select($attrib, $this->plugin->driver->alarm_types, $this->plugin->driver->alarm_absolute);
    }

    /**
     *
     */
    public function quickadd_form($attrib)
    {
        $attrib += ['action' => $this->rc->url('add'), 'method' => 'post', 'id' => 'quickaddform'];

        $label = html::label(['for' => 'quickaddinput', 'class' => 'voice'], $this->plugin->gettext('quickaddinput'));
        $input = new html_inputfield(['name' => 'text', 'id' => 'quickaddinput']);
        $button = html::tag('input', [
                'type'  => 'submit',
                'value' => '+',
                'title' => $this->plugin->gettext('createtask'),
                'class' => 'button mainaction',
        ]);

        $this->register_gui_object('quickaddform', $attrib['id']);
        return html::tag('form', $attrib, $label . $input->show() . $button);
    }

    /**
     * The result view
     */
    public function tasks_resultview($attrib)
    {
        $attrib += ['id' => 'rcmtaskslist'];

        $this->register_gui_object('resultlist', $attrib['id']);

        unset($attrib['name']);
        return html::tag('ul', $attrib, '');
    }

    /**
     * Interactive UI element to add/remove tags
     */
    public function tags_editline($attrib)
    {
        $attrib += ['id' => 'rcmtasktagsedit'];
        $this->register_gui_object('edittagline', $attrib['id']);

        $input = new html_inputfield([
                'name' => 'tags[]',
                'class' => 'tag',
                'size' => !empty($attrib['size']) ? $attrib['size'] : null,
                'tabindex' => $attrib['tabindex'] ?? null,
        ]);
        unset($attrib['tabindex']);
        return html::div($attrib, $input->show(''));
    }

    /**
     *
     */
    public function attendees_list($attrib = [])
    {
        // add "noreply" checkbox to attendees table only
        $invitations = strpos($attrib['id'], 'attend') !== false;

        $invite = new html_checkbox(['value' => 1, 'id' => 'edit-attendees-invite']);
        $table  = new html_table(['cols' => 4 + intval($invitations), 'border' => 0, 'cellpadding' => 0, 'class' => 'rectable']);

        //      $table->add_header('role', $this->plugin->gettext('role'));
        $table->add_header('name', $this->plugin->gettext($attrib['coltitle'] ?: 'attendee'));
        $table->add_header('confirmstate', $this->plugin->gettext('confirmstate'));
        if ($invitations) {
            $table->add_header(
                ['class' => 'invite', 'title' => $this->plugin->gettext('sendinvitations')],
                $invite->show(1) . html::label('edit-attendees-invite', html::span('inner', $this->plugin->gettext('sendinvitations')))
            );
        }
        $table->add_header('options', '');

        // hide invite column if disabled by config
        $itip_notify = (int)$this->rc->config->get('calendar_itip_send_option', 3);
        if ($invitations && !($itip_notify & 2)) {
            $css = sprintf('#%s td.invite, #%s th.invite { display:none !important }', $attrib['id'], $attrib['id']);
            $this->rc->output->add_footer(html::tag('style', ['type' => 'text/css'], $css));
        }

        return $table->show($attrib);
    }

    /**
     *
     */
    public function attendees_form($attrib = [])
    {
        $input = new html_inputfield([
                'name' => 'participant',
                'id' => 'edit-attendee-name',
                'size' => !empty($attrib['size']) ? $attrib['size'] : null,
                'class' => 'form-control',
        ]);

        $textarea = new html_textarea([
                'name' => 'comment',
                'id' => 'edit-attendees-comment',
                'rows' => 4,
                'cols' => 55,
                'title' => $this->plugin->gettext('itipcommenttitle'),
                'class' => 'form-control',
        ]);

        return html::div(
            $attrib,
            html::div(
                'form-searchbar',
                $input->show() . " " .
                html::tag('input', ['type' => 'button', 'class' => 'button', 'id' => 'edit-attendee-add', 'value' => $this->plugin->gettext('addattendee')])
                // . " " . html::tag('input', array('type' => 'button', 'class' => 'button', 'id' => 'edit-attendee-schedule', 'value' => $this->plugin->gettext('scheduletime').'...'))
            ) .
            html::p('attendees-commentbox', html::label('edit-attendees-comment', $this->plugin->gettext('itipcomment')) . $textarea->show())
        );
    }

    /**
     *
     */
    public function edit_attendees_notify($attrib = [])
    {
        $checkbox = new html_checkbox(['name' => '_notify', 'id' => 'edit-attendees-donotify', 'value' => 1, 'class' => 'pretty-checkbox']);
        return html::div($attrib, html::label(null, $checkbox->show(1) . ' ' . $this->plugin->gettext('sendnotifications')));
    }

    /**
     * Form for uploading and importing tasks
     */
    public function tasks_import_form($attrib = [])
    {
        if (empty($attrib['id'])) {
            $attrib['id'] = 'rcmImportForm';
        }

        // Get max filesize, enable upload progress bar
        $max_filesize = $this->rc->upload_init();
        $accept       = '.ics, text/calendar, text/x-vcalendar, application/ics';
        if (class_exists('ZipArchive', false)) {
            $accept .= ', .zip, application/zip';
        }

        $input = new html_inputfield([
                'id'     => 'importfile',
                'type'   => 'file',
                'name'   => '_data',
                'size'   => !empty($attrib['uploadfieldsize']) ? $attrib['uploadfieldsize'] : null,
                'accept' => $accept,
        ]);

        $html = html::div(
            'form-section form-group row',
            html::label(['class' => 'col-sm-4 col-form-label', 'for' => 'importfile'], rcube::Q($this->rc->gettext('importfromfile')))
            . html::div('col-sm-8', $input->show()
                . html::div('hint', $this->rc->gettext(['name' => 'maxuploadsize', 'vars' => ['size' => $max_filesize]])))
        );

        $html .= html::div(
            'form-section form-group row',
            html::label(['for' => 'task-import-list', 'class' => 'col-sm-4 col-form-label'], $this->plugin->gettext('list'))
            . html::div('col-sm-8', $this->tasklist_select(['name' => 'source', 'id' => 'task-import-list', 'editable' => true]))
        );

        $this->rc->output->add_gui_object('importform', $attrib['id']);
        $this->rc->output->add_label('import', 'importerror');

        return html::tag('p', null, $this->plugin->gettext('importtext'))
            . html::tag(
                'form',
                [
                'action'  => $this->rc->url(['task' => 'tasklist', 'action' => 'import']),
                'method'  => 'post',
                'enctype' => 'multipart/form-data',
                'id'      => $attrib['id'],
            ],
                $html
            );
    }

    /**
     * Form to select options for exporting tasks
     */
    public function tasks_export_form($attrib = [])
    {
        if (empty($attrib['id'])) {
            $attrib['id'] = 'rcmTaskExportForm';
        }

        $html = html::div(
            'form-section form-group row',
            html::label(['for' => 'task-export-list', 'class' => 'col-sm-4 col-form-label'], $this->plugin->gettext('list'))
            . html::div('col-sm-8', $this->tasklist_select([
                        'name'  => 'source',
                        'id'    => 'task-export-list',
                        'extra' => ['' => '- ' . $this->plugin->gettext('currentview') . ' -'],
                ]))
        );

        $checkbox = new html_checkbox(['name' => 'attachments', 'id' => 'task-export-attachments', 'value' => 1, 'class' => 'form-check-input pretty-checkbox']);
        $html .= html::div(
            'form-section row form-check',
            html::label(['for' => 'task-export-attachments', 'class' => 'col-sm-4 col-form-label'], $this->plugin->gettext('exportattachments'))
            . html::div('col-sm-8', $checkbox->show(1))
        );

        $this->register_gui_object('exportform', $attrib['id']);

        return html::tag(
            'form',
            [
                'action' => $this->rc->url(['task' => 'tasklist', 'action' => 'export']),
                'method' => 'post', 'id' => $attrib['id'],
            ],
            $html
        );
    }

    /**
     * Wrapper for rcube_output_html::add_gui_object()
     */
    public function register_gui_object($name, $id)
    {
        $this->gui_objects[$name] = $id;
        $this->rc->output->add_gui_object($name, $id);
    }

    /**
     * Getter for registered gui objects.
     * (for manual registration when loading the inline UI)
     */
    public function get_gui_objects()
    {
        return $this->gui_objects;
    }
}
