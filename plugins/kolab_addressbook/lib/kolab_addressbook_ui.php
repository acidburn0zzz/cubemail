<?php

/**
 * Kolab address book UI
 *
 * @author Aleksander Machniak <machniak@kolabsys.com>
 *
 * Copyright (C) 2012, Kolab Systems AG <contact@kolabsys.com>
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
class kolab_addressbook_ui
{
    private $plugin;
    private $rc;

    /**
     * Class constructor
     *
     * @param kolab_addressbook $plugin Plugin object
     */
    public function __construct($plugin)
    {
        $this->rc     = rcube::get_instance();
        $this->plugin = $plugin;

        $this->init_ui();
    }

    /**
     * Adds folders management functionality to Addressbook UI
     */
    private function init_ui()
    {
        if (!empty($this->rc->action) && !preg_match('/^plugin\.book/', $this->rc->action) && $this->rc->action != 'show') {
            return;
        }

        // Include script
        $this->plugin->include_script('kolab_addressbook.js');

        if (empty($this->rc->action)) {
            // Include stylesheet (for directorylist)
            $this->plugin->include_stylesheet($this->plugin->local_skin_path().'/kolab_addressbook.css');

            // include kolab folderlist widget if available
            if (in_array('libkolab', $this->plugin->api->loaded_plugins())) {
                $this->plugin->api->include_script('libkolab/libkolab.js');
            }

            $this->rc->output->add_footer($this->rc->output->parse('kolab_addressbook.search_addon', false, false));

            // Add actions on address books
            $options = array('book-create', 'book-edit', 'book-delete', 'book-remove');
            $idx     = 0;

            if ($dav_url = $this->rc->config->get('kolab_addressbook_carddav_url')) {
              $options[] = 'book-showurl';
              $this->rc->output->set_env('kolab_addressbook_carddav_url', true);

              // set CardDAV URI for specified ldap addressbook
              if ($ldap_abook = $this->rc->config->get('kolab_addressbook_carddav_ldap')) {
                $dav_ldap_url = strtr($dav_url, array(
                    '%h' => $_SERVER['HTTP_HOST'],
                    '%u' => urlencode($this->rc->get_user_name()),
                    '%i' => 'ldap-directory',
                    '%n' => '',
                ));
                $this->rc->output->set_env('kolab_addressbook_carddav_ldap', $ldap_abook);
                $this->rc->output->set_env('kolab_addressbook_carddav_ldap_url', $dav_ldap_url);
              }
            }

            foreach ($options as $command) {
                $content = html::tag('li', $idx ? null : array('class' => 'separator_above'),
                    $this->plugin->api->output->button(array(
                        'label'    => 'kolab_addressbook.'.str_replace('-', '', $command),
                        'domain'   => $this->ID,
                        'class'    => str_replace('-', ' ', $command) . ' disabled',
                        'classact' => str_replace('-', ' ', $command) . ' active',
                        'command'  => $command,
                        'type'     => 'link'
                )));
                $this->plugin->api->add_content($content, 'groupoptions');
                $idx++;
            }

            // Link to Settings/Folders
            $content = html::tag('li', array('class' => 'separator_above'),
                $this->plugin->api->output->button(array(
                    'label'    => 'managefolders',
                    'type'     => 'link',
                    'class'    => 'folders disabled',
                    'classact' => 'folders active',
                    'command'  => 'folders',
                    'task'     => 'settings',
            )));
            $this->plugin->api->add_content($content, 'groupoptions');

            $this->rc->output->add_label('kolab_addressbook.bookdeleteconfirm',
                'kolab_addressbook.bookdeleting', 'kolab_addressbook.bookshowurl',
                'kolab_addressbook.carddavurldescription',
                'kolab_addressbook.bookedit',
                'kolab_addressbook.bookdelete',
                'kolab_addressbook.bookshowurl',
                'kolab_addressbook.findaddressbooks',
                'kolab_addressbook.searchterms',
                'kolab_addressbook.foldersearchform',
                'kolab_addressbook.listsearchresults',
                'kolab_addressbook.nraddressbooksfound',
                'kolab_addressbook.noaddressbooksfound',
                'kolab_addressbook.foldersubscribe',
                'kolab_addressbook.bookedit',
                'kolab_addressbook.bookcreate',
                'kolab_addressbook.nobooknamewarning',
                'kolab_addressbook.booksaving',
                'resetsearch'
            );

            if ($this->plugin->bonnie_api) {
                $this->rc->output->set_env('kolab_audit_trail', true);
                $this->plugin->api->include_script('libkolab/libkolab.js');

                $this->rc->output->add_label(
                    'kolab_addressbook.showhistory',
                    'kolab_addressbook.objectchangelog',
                    'kolab_addressbook.objectdiff',
                    'kolab_addressbook.objectdiffnotavailable',
                    'kolab_addressbook.objectchangelognotavailable',
                    'kolab_addressbook.revisionrestoreconfirm'
                );

                $this->plugin->add_hook('render_page', array($this, 'render_audittrail_page'));
                $this->plugin->register_handler('plugin.object_changelog_table', array('libkolab', 'object_changelog_table'));
            }
        }
        // include stylesheet for audit trail
        else if ($this->rc->action == 'show' && $this->plugin->bonnie_api) {
            $this->plugin->include_stylesheet($this->plugin->local_skin_path().'/kolab_addressbook.css', true);
            $this->rc->output->add_label('kolab_addressbook.showhistory');
        }
    }

    /**
     * Handler for address book create/edit action
     */
    public function book_edit()
    {
        $this->rc->output->set_env('pagetitle', $this->plugin->gettext('bookproperties'));
        $this->rc->output->add_handler('folderform', array($this, 'book_form'));
        $this->rc->output->send('libkolab.folderform');
    }

    /**
     * Handler for 'bookdetails' object returning form content for book create/edit
     *
     * @param array $attr Object attributes
     *
     * @return string HTML output
     */
    public function book_form($attrib)
    {
        $action = trim(rcube_utils::get_input_value('_act', rcube_utils::INPUT_GPC));
        $folder = trim(rcube_utils::get_input_value('_source', rcube_utils::INPUT_GPC, true)); // UTF8

        $hidden_fields[] = array('name' => '_source', 'value' => $folder);

        $folder  = rcube_charset::convert($folder, RCUBE_CHARSET, 'UTF7-IMAP');
        $storage = $this->rc->get_storage();
        $delim   = $storage->get_hierarchy_delimiter();

        if ($action == 'edit') {
            $path_imap = explode($delim, $folder);
            $name      = rcube_charset::convert(array_pop($path_imap), 'UTF7-IMAP');
            $path_imap = implode($path_imap, $delim);
        }
        else { // create
            $path_imap = $folder;
            $name      = '';
            $folder    = '';
        }

        // Store old name, get folder options
        if (strlen($folder)) {
            $hidden_fields[] = array('name' => '_oldname', 'value' => $folder);

            $options = $storage->folder_info($folder);
        }

        $form = array();

        // General tab
        $form['properties'] = array(
            'name'   => $this->rc->gettext('properties'),
            'fields' => array(),
        );

        if (!empty($options) && ($options['norename'] || $options['protected'])) {
            $foldername = rcube::Q(str_replace($delim, ' &raquo; ', kolab_storage::object_name($folder)));
        }
        else {
            $foldername = new html_inputfield(array('name' => '_name', 'id' => '_name', 'size' => 30));
            $foldername = $foldername->show($name);
        }

        $form['properties']['fields']['name'] = array(
            'label' => $this->plugin->gettext('bookname'),
            'value' => $foldername,
            'id'    => '_name',
        );

        if (!empty($options) && ($options['norename'] || $options['protected'])) {
            // prevent user from moving folder
            $hidden_fields[] = array('name' => '_parent', 'value' => $path_imap);
        }
        else {
            $prop   = array('name' => '_parent', 'id' => '_parent');
            $select = kolab_storage::folder_selector('contact', $prop, $folder);

            $form['properties']['fields']['parent'] = array(
                'label' => $this->plugin->gettext('parentbook'),
                'value' => $select->show(strlen($folder) ? $path_imap : ''),
                'id'    => '_parent',
            );
        }

        $form_html = kolab_utils::folder_form($form, $folder, 'calendar', $hidden_fields);

        return html::tag('form', $attrib + array('action' => 'plugin.book-save', 'method' => 'post', 'id' => 'bookpropform'), $form_html);
    }

    /**
     *
     */
    public function render_audittrail_page($p)
    {
        // append audit trail UI elements to contact page
        if ($p['template'] === 'addressbook' && !$p['kolab-audittrail']) {
            $this->rc->output->add_footer($this->rc->output->parse('kolab_addressbook.audittrail', false, false));
            $p['kolab-audittrail'] = true;
        }

        return $p;
    }
}
