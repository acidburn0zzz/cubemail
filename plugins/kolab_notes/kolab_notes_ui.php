<?php

class kolab_notes_ui
{
    private $rc;
    private $plugin;
    private $ready = false;

    function __construct($plugin)
    {
        $this->plugin = $plugin;
        $this->rc = $plugin->rc;
    }

    /**
    * Calendar UI initialization and requests handlers
    */
    public function init()
    {
        if ($this->ready)  // already done
            return;

        // add taskbar button
        $this->plugin->add_button(array(
            'command' => 'notes',
            'class'   => 'button-notes',
            'classsel' => 'button-notes button-selected',
            'innerclass' => 'button-inner',
            'label'   => 'kolab_notes.navtitle',
        ), 'taskbar');

        $this->plugin->include_stylesheet($this->plugin->local_skin_path() . '/notes.css');

        $this->plugin->register_action('print', array($this, 'print_template'));
        $this->plugin->register_action('folder-acl', array($this, 'folder_acl'));

        $this->ready = true;
  }

    /**
    * Register handler methods for the template engine
    */
    public function init_templates()
    {
        $this->plugin->register_handler('plugin.tagslist', array($this, 'tagslist'));
        $this->plugin->register_handler('plugin.notebooks', array($this, 'folders'));
        #$this->plugin->register_handler('plugin.folders_select', array($this, 'folders_select'));
        $this->plugin->register_handler('plugin.searchform', array($this->rc->output, 'search_form'));
        $this->plugin->register_handler('plugin.listing', array($this, 'listing'));
        $this->plugin->register_handler('plugin.editform', array($this, 'editform'));
        $this->plugin->register_handler('plugin.notetitle', array($this, 'notetitle'));
        $this->plugin->register_handler('plugin.detailview', array($this, 'detailview'));
        $this->plugin->register_handler('plugin.attachments_list', array($this, 'attachments_list'));

        $this->rc->output->include_script('list.js');
        $this->rc->output->include_script('treelist.js');
        $this->plugin->include_script('notes.js');
        $this->plugin->include_script('jquery.tagedit.js');

        $this->plugin->include_stylesheet($this->plugin->local_skin_path() . '/tagedit.css');

        // load config options and user prefs relevant for the UI
        $settings = array(
            'sort_col' => $this->rc->config->get('kolab_notes_sort_col', 'changed'),
            'print_template' => $this->rc->url('print'),
        );

        if ($list = rcube_utils::get_input_value('_list', RCUBE_INPUT_GPC)) {
            $settings['selected_list'] = $list;
        }
        if ($uid = rcube_utils::get_input_value('_id', RCUBE_INPUT_GPC)) {
            $settings['selected_uid'] = $uid;
        }

        // TinyMCE uses two-letter lang codes, with exception of Chinese
        $lang = strtolower($_SESSION['language']);
        $lang = strpos($lang, 'zh_') === 0 ? str_replace('_', '-', $lang) : substr($lang, 0, 2);

        if (!file_exists(INSTALL_PATH . 'program/js/tiny_mce/langs/'.$lang.'.js')) {
            $lang = 'en';
        }

        $settings['editor'] = array(
            'lang'       => $lang,
            'editor_css' => $this->plugin->url($this->plugin->local_skin_path() . '/editor.css'),
            'spellcheck' => intval($this->rc->config->get('enable_spellcheck')),
            'spelldict'  => intval($this->rc->config->get('spellcheck_dictionary'))
        );

        $this->rc->output->set_env('kolab_notes_settings', $settings);

        $this->rc->output->add_label('save','cancel');
    }

    public function folders($attrib)
    {
        $attrib += array('id' => 'rcmkolabnotebooks');

        if ($attrib['type'] == 'select') {
            $select = new html_select($attrib);
        }

        $jsenv = array();
        $items = '';
        foreach ($this->plugin->get_lists() as $prop) {
            unset($prop['user_id']);
            $id = $prop['id'];
            $class = '';

            if (!$prop['virtual'])
                $jsenv[$id] = $prop;

            if ($attrib['type'] == 'select') {
                if ($prop['editable']) {
                    $select->add($prop['name'], $prop['id']);
                }
            }
            else {
                $html_id = rcube_utils::html_identifier($id);
                $title = $prop['name'] != $prop['listname'] ? html_entity_decode($prop['name'], ENT_COMPAT, RCMAIL_CHARSET) : '';

                if ($prop['virtual'])
                    $class .= ' virtual';
                else if (!$prop['editable'])
                    $class .= ' readonly';
                if ($prop['class_name'])
                    $class .= ' '.$prop['class_name'];

                $items .= html::tag('li', array('id' => 'rcmliknb' . $html_id, 'class' => trim($class)),
                    html::span(array('class' => 'listname', 'title' => $title), $prop['listname']) .
                    html::span(array('class' => 'count'), '')
                );
            }
        }

        $this->rc->output->set_env('kolab_notebooks', $jsenv);
        $this->rc->output->add_gui_object('notebooks', $attrib['id']);

        return $attrib['type'] == 'select' ? $select->show() : html::tag('ul', $attrib, $items, html::$common_attrib);
    }

    public function listing($attrib)
    {
        $attrib += array('id' => 'rcmkolabnoteslist');
        $this->rc->output->add_gui_object('noteslist', $attrib['id']);
        return html::tag('ul', $attrib, '', html::$common_attrib);
    }

    public function tagslist($attrib)
    {
        $attrib += array('id' => 'rcmkolabnotestagslist');
        $this->rc->output->add_gui_object('notestagslist', $attrib['id']);
        return html::tag('ul', $attrib, '', html::$common_attrib);
    }

    public function editform($attrib)
    {
        $attrib += array('action' => '#', 'id' => 'rcmkolabnoteseditform');

        $this->rc->output->add_gui_object('noteseditform', $attrib['id']);
        $this->rc->output->include_script('tiny_mce/tiny_mce.js');

        $textarea = new html_textarea(array('name' => 'content', 'id' => 'notecontent', 'cols' => 60, 'rows' => 20, 'tabindex' => 3));
        return html::tag('form', $attrib, $textarea->show(), array_merge(html::$common_attrib, array('action')));
    }

    public function detailview($attrib)
    {
        $attrib += array('id' => 'rcmkolabnotesdetailview');
        $this->rc->output->add_gui_object('notesdetailview', $attrib['id']);
        return html::div($attrib, '');
    }

    public function notetitle($attrib)
    {
        $attrib += array('id' => 'rcmkolabnotestitle');
        $this->rc->output->add_gui_object('noteviewtitle', $attrib['id']);

        $summary = new html_inputfield(array('name' => 'summary', 'class' => 'notetitle inline-edit', 'size' => 60, 'tabindex' => 1));

        $html = $summary->show();
        $html .= html::div(array('class' => 'tagline tagedit', 'style' => 'display:none'), '&nbsp;');
        $html .= html::div(array('class' => 'dates', 'style' => 'display:none'),
            html::label(array(), $this->plugin->gettext('created')) .
            html::span('notecreated', '') .
            html::label(array(), $this->plugin->gettext('changed')) .
            html::span('notechanged', '')
        );

        return html::div($attrib, $html);
    }

    public function attachments_list($attrib)
    {
        $attrib += array('id' => 'rcmkolabnotesattachmentslist');
        $this->rc->output->add_gui_object('notesattachmentslist', $attrib['id']);
        return html::tag('ul', $attrib, '', html::$common_attrib);
    }

    /**
     * Render edit for notes lists (folders)
     */
    public function list_editform($action, $list, $folder)
    {
        if (is_object($folder)) {
            $folder_name = $folder->name; // UTF7
        }
        else {
            $folder_name = '';
        }

        $hidden_fields[] = array('name' => 'oldname', 'value' => $folder_name);

        $storage = $this->rc->get_storage();
        $delim   = $storage->get_hierarchy_delimiter();
        $form   = array();

        if (strlen($folder_name)) {
            $options = $storage->folder_info($folder_name);

            $path_imap = explode($delim, $folder_name);
            array_pop($path_imap);  // pop off name part
            $path_imap = implode($path_imap, $delim);
        }
        else {
            $path_imap = '';
            $options = array();
        }

        // General tab
        $form['properties'] = array(
            'name' => $this->rc->gettext('properties'),
            'fields' => array(),
        );

        // folder name (default field)
        $input_name = new html_inputfield(array('name' => 'name', 'id' => 'noteslist-name', 'size' => 20));
        $form['properties']['fields']['name'] = array(
            'label' => $this->plugin->gettext('listname'),
            'value' => $input_name->show($list['editname'], array('disabled' => ($options['norename'] || $options['protected']))),
            'id' => 'folder-name',
        );

        // prevent user from moving folder
        if (!empty($options) && ($options['norename'] || $options['protected'])) {
            $hidden_fields[] = array('name' => 'parent', 'value' => $path_imap);
        }
        else {
            $select = kolab_storage::folder_selector('note', array('name' => 'parent'), $folder_name);
            $form['properties']['fields']['path'] = array(
                'label' => $this->plugin->gettext('parentfolder'),
                'value' => $select->show(strlen($folder_name) ? $path_imap : ''),
            );
        }

        // add folder ACL tab
        if ($action != 'form-new') {
            $form['sharing'] = array(
                'name'    => Q($this->plugin->gettext('tabsharing')),
                'content' => html::tag('iframe', array(
                    'src' => $this->rc->url(array('_action' => 'folder-acl', '_folder' => $folder_name, 'framed' => 1)),
                    'width' => '100%',
                    'height' => 280,
                    'border' => 0,
                    'style' => 'border:0'),
                '')
            );
        }

        $form_html = '';
        if (is_array($hidden_fields)) {
            foreach ($hidden_fields as $field) {
                $hiddenfield = new html_hiddenfield($field);
                $form_html .= $hiddenfield->show() . "\n";
            }
        }

        // create form output
        foreach ($form as $tab) {
            if (is_array($tab['fields']) && empty($tab['content'])) {
                $table = new html_table(array('cols' => 2));
                foreach ($tab['fields'] as $col => $colprop) {
                    $colprop['id'] = '_'.$col;
                    $label = !empty($colprop['label']) ? $colprop['label'] : $this->plugin->gettext($col);

                    $table->add('title', html::label($colprop['id'], Q($label)));
                    $table->add(null, $colprop['value']);
                }
                $content = $table->show();
            }
            else {
                $content = $tab['content'];
            }

            if (!empty($content)) {
                $form_html .= html::tag('fieldset', null, html::tag('legend', null, Q($tab['name'])) . $content) . "\n";
            }
        }

        return html::tag('form', array('action' => "#", 'method' => "post", 'id' => "noteslistpropform"), $form_html);
    }

    /**
     * Handler to render ACL form for a notes folder
     */
    public function folder_acl()
    {
        $this->plugin->require_plugin('acl');
        $this->rc->output->add_handler('folderacl', array($this, 'folder_acl_form'));
        $this->rc->output->send('kolab_notes.kolabacl');
    }

    /**
     * Handler for ACL form template object
     */
    public function folder_acl_form()
    {
        $folder = rcube_utils::get_input_value('_folder', RCUBE_INPUT_GPC);

        if (strlen($folder)) {
            $storage = $this->rc->get_storage();
            $options = $storage->folder_info($folder);

            // get sharing UI from acl plugin
            $acl = $this->rc->plugins->exec_hook('folder_form',
                array('form' => array(), 'options' => $options, 'name' => $folder));
        }

        return $acl['form']['sharing']['content'] ?: html::div('hint', $this->plugin->gettext('aclnorights'));
    }

    /**
     * Render the template for printing with placeholders
     */
    public function print_template()
    {
        $this->rc->output->reset(true);
        echo $this->rc->output->parse('kolab_notes.print', false, false);
        exit;
    }

}

