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
        #$this->plugin->register_handler('plugin.detailview', array($this, 'detailview'));

        $this->rc->output->include_script('list.js');
        $this->plugin->include_script('notes.js');
        $this->plugin->include_script('jquery.tagedit.js');

        $this->plugin->include_stylesheet($this->plugin->local_skin_path() . '/tagedit.css');

        // TODO: load config options and user prefs relevant for the UI
        $settings = array();

        // TinyMCE uses two-letter lang codes, with exception of Chinese
        $lang = strtolower($_SESSION['language']);
        $lang = strpos($lang, 'zh_') === 0 ? str_replace('_', '-', $lang) : substr($lang, 0, 2);

        if (!file_exists(INSTALL_PATH . 'program/js/tiny_mce/langs/'.$lang.'.js')) {
            $lang = 'en';
        }

        $settings['editor'] = array(
            'lang'       => $lang,
            'editor_css' => $this->plugin->url() . $this->plugin->local_skin_path() . '/editor.css',
            'spellcheck' => intval($this->rc->config->get('enable_spellcheck')),
            'spelldict'  => intval($this->rc->config->get('spellcheck_dictionary'))
        );

        $this->rc->output->set_env('kolab_notes_settings', $settings);
    }

    public function folders($attrib)
    {
        $attrib += array('id' => 'rcmkolabnotebooks');

        $jsenv = array();
        $items = '';
        foreach ($this->plugin->get_lists() as $prop) {
            unset($prop['user_id']);
            $id = $prop['id'];

            if (!$prop['virtual'])
                $jsenv[$id] = $prop;

            $html_id = rcube_utils::html_identifier($id);
            $title = $prop['name'] != $prop['listname'] ? html_entity_decode($prop['name'], ENT_COMPAT, RCMAIL_CHARSET) : '';

            if ($prop['virtual'])
                $class .= ' virtual';
            else if (!$prop['editable'])
                $class .= ' readonly';
            if ($prop['class_name'])
                $class .= ' '.$prop['class_name'];

            $items .= html::tag('li', array('id' => 'rcmliknb' . $html_id, 'class' => trim($class)),
                html::span(array('class' => 'listname', 'title' => $title), Q($prop['listname'])) .
                html::span(array('class' => 'count'), '')
            );
        }

        $this->rc->output->set_env('kolab_notebooks', $jsenv);
        $this->rc->output->add_gui_object('notebooks', $attrib['id']);

        return html::tag('ul', $attrib, $items, html::$common_attrib);
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
}

