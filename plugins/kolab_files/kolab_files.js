/**
 * Kolab files plugin
 *
 * @author Aleksander Machniak <machniak@kolabsys.com>
 *
 * @licstart  The following is the entire license notice for the
 * JavaScript code in this file.
 *
 * Copyright (C) 2011-2015, Kolab Systems AG <contact@kolabsys.com>
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
 *
 * @licend  The above is the entire license notice
 * for the JavaScript code in this file.
 */

window.rcmail && window.files_api && rcmail.addEventListener('init', function() {
  if (rcmail.task == 'mail') {
    // mail compose
    if (rcmail.env.action == 'compose') {
      kolab_files_from_cloud_widget($('#compose-attachments > div'));

      // register some commands to skip warning message on compose page
      $.merge(rcmail.env.compose_commands, ['files-list', 'files-sort', 'files-search', 'files-search-reset']);
    }
    // mail preview
    else if (rcmail.env.action == 'show' || rcmail.env.action == 'preview') {
      var attachment_list = $('#attachment-list');

      if ($('li', attachment_list).length) {
        var link = $('<a href="#" class="button filesaveall">')
          .text(rcmail.gettext('kolab_files.saveall'))
          .click(function() { kolab_directory_selector_dialog(); })
          .insertAfter($('.header-content > .header-links').length ? $('.header-links > a:last') : attachment_list);
      }

      rcmail.addEventListener('menu-open', kolab_files_attach_menu_open);
      rcmail.enable_command('folder-create', true);
    }
    // attachment preview
    else if (rcmail.env.action == 'get') {
      rcmail.enable_command('folder-create', true);
    }

    if (!rcmail.env.action || rcmail.env.action == 'show' || rcmail.env.action == 'preview') {
      // add "attach from cloud" button for event/task dialog in mail
      rcmail.addEventListener('plugin.mail2event_dialog', function() {
        if (!$('#calendar-attachment-form a.fromcloud').length)
          kolab_files_from_cloud_widget($('#calendar-attachment-form > div.buttons'));
      });
    }
  }
  else if (rcmail.task == 'calendar') {
    // add "attach from cloud" button for event dialog
    if (!rcmail.env.action)
      kolab_files_from_cloud_widget($('#calendar-attachment-form > div.buttons, #edit-attachments-form'));
  }
  else if (rcmail.task == 'tasks') {
    // add "attach from cloud" button for task dialog
    if (!rcmail.env.action)
      kolab_files_from_cloud_widget($('#taskedit-attachment-form > div.buttons, #taskedit-attachments-form'));
  }
  else if (rcmail.task == 'files') {
    if (rcmail.gui_objects.fileslist) {
      rcmail.fileslist = new rcube_list_widget(rcmail.gui_objects.fileslist, {
        multiselect: true,
        draggable: true,
        keyboard: true,
        column_movable: rcmail.env.files_col_movable,
        dblclick_time: rcmail.dblclick_time
      });

      rcmail.fileslist.addEventListener('dblclick', function(o) { kolab_files_list_dblclick(o); })
        .addEventListener('select', function(o) { kolab_files_list_select(o); })
        .addEventListener('keypress', function(o) { kolab_files_list_keypress(o); })
        .addEventListener('dragstart', function(e) { kolab_files_drag_start(e); })
        .addEventListener('dragmove', function(e) { kolab_files_drag_move(e); })
        .addEventListener('dragend', function(e) { kolab_files_drag_end(e); })
        .addEventListener('column_replace', function(e) { kolab_files_set_coltypes(e, 'files'); });

      rcmail.enable_command('menu-open', 'menu-save', 'files-sort', 'files-search', 'files-search-reset', 'folder-create', true);

      rcmail.fileslist.init();
      kolab_files_list_coltypes('files');
      kolab_files_drag_drop_init($(rcmail.gui_objects.fileslist).parents('.droptarget'));
    }

    if (rcmail.gui_objects.sessionslist) {
      rcmail.sessionslist = new rcube_list_widget(rcmail.gui_objects.sessionslist, {
        keyboard: true,
        column_movable: rcmail.env.sessions_col_movable,
        dblclick_time: rcmail.dblclick_time
      });

      rcmail.sessionslist.addEventListener('dblclick', function(o) { kolab_files_sessions_list_dblclick(o); })
        .addEventListener('select', function(o) { kolab_files_sessions_list_select(o); })
        .addEventListener('keypress', function(o) { kolab_files_sessions_list_keypress(o); })
        .addEventListener('column_replace', function(e) { kolab_files_set_coltypes(e, 'sessions'); });

      rcmail.sessionslist.init();
      kolab_files_list_coltypes('sessions');
    }

    // "one file only" commands
    rcmail.env.file_commands = ['files-get', 'files-rename'];
    // "one or more file" commands
    rcmail.env.file_commands_all = ['files-delete', 'files-move', 'files-copy'];

    if (rcmail.env.action == 'open' || rcmail.env.action == 'edit') {
      rcmail.enable_command('files-get', true);
      rcmail.enable_command('files-delete', rcmail.env.file_data.writable);
    }
    else {
      rcmail.enable_command('folder-mount', rcmail.env.external_sources);
    }
  }

  kolab_files_init();
});


/**********************************************************/
/*********          Shared functionality         **********/
/**********************************************************/

// Initializes API object
function kolab_files_init()
{
  if (window.file_api)
    return;

  var editor_config = {};

  // Initialize application object (don't change var name!)
  file_api = $.extend(new files_api(), new kolab_files_ui());

  file_api.set_env({
    token: kolab_files_token(),
    url: rcmail.env.files_url,
    sort_col: 'name',
    sort_reverse: false,
    search_threads: rcmail.env.search_threads,
    resources_dir: rcmail.env.files_url.replace(/\/api\/?$/, '/resources'),
    caps: rcmail.env.files_caps,
    supported_mimetypes: rcmail.env.file_mimetypes
  });

  file_api.translations = rcmail.labels;

  if (rcmail.task == 'files') {
    if (rcmail.env.action == 'edit' && rcmail.env.editor_type) {
      // Extract the domain here, it can't be done by Chwala
      // when using WOPI, which does not set iframe src attribute
      var domain, href = rcmail.env.file_data.viewer.href;
      if (href && /^(https?:\/\/[^/]+)/i.test(href))
        domain = RegExp.$1;

      editor_config = {
        // UI elements
        iframe: $('#fileframe').get(0),
        domain: domain,
        export_menu: rcmail.gui_objects.exportmenu ? $('ul', rcmail.gui_objects.exportmenu).get(0) : null,
        title_input: $('#document-title').get(0),
        members_list: $('#members').get(0),
        photo_url: '?_task=addressbook&_action=photo&_error=1&_email=%email',
        photo_default_url: rcmail.env.photo_placeholder,
        // events
        ready: function(data) { document_editor_init(); },
        sessionClosed: function(data) { return document_editor_close(); }
      };

      if (rcmail.env.file_data.writable)
        editor_config.documentChanged = function(data) { rcmail.enable_command('document-save', true); };
    }
    else if (rcmail.env.action == 'open') {
      // initialize folders list (for dialogs)
      // file_api.folder_list();

      // get ongoing sessions
      file_api.request('folder_info', {folder: file_api.file_path(rcmail.env.file), sessions: 1}, 'folder_info_response');
    }
    else if (rcmail.env.action == 'share') {
      kolab_files_share_form_init();
    }
    else {
      file_api.env.init_folder = rcmail.env.folder;
      file_api.env.init_collection = rcmail.env.collection;
      file_api.folder_list();
      file_api.browser_capabilities_check();

      if (rcmail.env.contextmenu) {
        rcmail.env.folders_cm = rcmail.contextmenu.init({menu_name: 'foldercontextmenu', menu_source: '#folderoptions > ul', list_object: 'folder_list'}, {
          addmenuitem: function(p) {
            // don't add Mount option to the menu
            var str = $(p.el).children('a').first().attr('onclick');
            if (str && str.match(/folder-mount/))
              return {result: false, abort: true};
          },
          activate: function(p) {
            var folder = rcmail.env.context_menu_source_id;
            switch (p.command) {
              case 'files-folder-delete':
              case 'folder-rename':
                return !folder.match(/^folder-collection-(.*)$/);
              case 'folder-share':
                return !folder.match(/^folder-collection-(.*)$/) && file_api.is_shareable(folder);
              case 'folder-create':
              case 'folder-mount':
                return true;
            }
          },
          beforecommand: function(e) {
            rcmail.env.file_api_context = [file_api.env.folder, file_api.env.collection];
            file_api.env.folder = rcmail.env.context_menu_source_id;
          },
          aftercommand: function(e) {
            file_api.env.folder = rcmail.env.file_api_context[0];
            file_api.env.collection = rcmail.env.file_api_context[1];
          }
        });
      }
    }
  }

  if (rcmail.env.files_caps && !rcmail.env.framed && rcmail.env.files_caps.DOCEDIT)
    $.extend(editor_config, {
      // invitation notifications
      api: file_api,
      owner: rcmail.env.files_user,
      interval: rcmail.env.files_interval || 60,
      invitationMore: true,
      invitationChange: document_editor_invitation_handler
    });

  $.extend(editor_config, {
    // notifications/alerts
    gettext: function(label) { return rcmail.get_label(label, 'kolab_files'); },
    set_busy: function(state, message) { return rcmail.set_busy(state, message ? 'kolab_files.' + message : ''); },
    hide_message: function(id) { return rcmail.hide_message(id); },
    display_message: function(label, type, is_txt, timeout) {
      if (!is_txt)
        label = 'kolab_files.' + label;
      return rcmail.display_message(label, type, timeout * 1000);
    }
  });

  if (window.document_editor_api)
    document_editor = new document_editor_api(editor_config);
  else
    document_editor = new manticore_api(editor_config);

  rcmail.addEventListener('responseafterreset', function(o) {
    // update caps/mountpoints on reset
    file_api.set_env({caps: rcmail.env.files_caps});
  });
};

// returns API authorization token
function kolab_files_token()
{
  // consider the token from parent window more reliable (fresher) than in framed window
  // it's because keep-alive is not requested in frames
  return rcmail.is_framed() && parent.rcmail.env.files_token ? parent.rcmail.env.files_token : rcmail.env.files_token;
};

function kolab_files_from_cloud_widget(elem)
{
  $('<a class="button btn btn-secondary fromcloud">')
      .attr('tabindex', $('button,input', elem).first().attr('tabindex') || 0)
      .text(rcmail.gettext('kolab_files.fromcloud'))
      .click(function() { kolab_files_selector_dialog(); })
      .appendTo(elem);

  if (rcmail.gui_objects.fileslist) {
    rcmail.fileslist = new rcube_list_widget(rcmail.gui_objects.fileslist, {
      multiselect: true,
      keyboard: true,
      column_movable: false,
      dblclick_time: rcmail.dblclick_time
    });
    rcmail.fileslist.addEventListener('select', function(o) { kolab_files_list_select(o); });

    rcmail.enable_command('files-sort', 'files-search', 'files-search-reset', true);

    rcmail.fileslist.init();
    kolab_files_list_coltypes();
  }
}

// folder selection dialog
function kolab_directory_selector_dialog(id)
{
  var dialog = $('#files-dialog'),
    input = $('#file-save-as-input'),
    form = $('#file-save-as'),
    list = $('#folderlistbox'),
    buttons = {}, label = 'saveto',
    win = window, fn;

  // attachment is specified
  if (id) {
    var attach = $('#attach' + id + '> a').first(),
      filename = attach.attr('title');

    if (!filename) {
      attach = attach.clone();
      $('.attachment-size', attach).remove();
      filename = $.trim(attach.text());
    }

    form.show();
    dialog.addClass('saveas');
    input.val(filename);
  }
  // attachment preview page
  else if (rcmail.env.action == 'get') {
    id = rcmail.env.part;
    form.show();
    dialog.addClass('saveas');
    input.val(rcmail.env.filename);
  }
  else {
    form.hide();
    dialog.removeClass('saveas');
    label = 'saveall';
  }

  $('#foldercreatelink').attr('tabindex', 0);

  buttons[rcmail.gettext('kolab_files.save')] = function () {
    if (!file_api.env.folder)
      return;

    var lock = rcmail.set_busy(true, 'saving'),
      request = {
        act: 'save-file',
        source: rcmail.env.mailbox,
        uid: rcmail.env.uid,
        dest: file_api.env.folder
      };

    if (id) {
      request.id = id;
      request.name = input.val();
    }

    rcmail.http_post('plugin.kolab_files', request, lock);
    kolab_dialog_close(this);
  };

  buttons[rcmail.gettext('kolab_files.cancel')] = function () {
    kolab_dialog_close(this);
  };

  if (!rcmail.env.folders_loaded) {
    fn = function() {
      rcmail.env.folder_list_selector = '#files-dialog #files-folder-list';
      rcmail.env.folder_search_selector = '#files-dialog #foldersearch';
      file_api.folder_list({writable: 1});
      rcmail.env.folders_loaded = true;
    };
  }

  // show dialog window
  kolab_dialog_show(dialog, {
    title: rcmail.gettext('kolab_files.' + label),
    buttons: buttons,
    button_classes: ['mainaction save', 'cancel'],
    classes: {'ui-dialog': 'selection-dialog files-dialog'},
    minWidth: 250,
    minHeight: 300,
    height: 400,
    width: 300
  }, fn);

  // add link for "more options" drop-down
  if (!dialog.find('foldercreatelink').length)
    $('<a>')
      .attr({href: '#', 'class': 'btn btn-link options add-folder'})
      .text(rcmail.gettext('kolab_files.addfolder'))
      .click(function(e) { rcmail.command('folder-create', '', this, e); })
      .prependTo(dialog.parent().parent().find('.ui-dialog-buttonset'));

  // "enable" folder creation when dialog is displayed in parent window
  if (rcmail.is_framed()) {
    parent.rcmail.enable_command('folder-create', true);
    parent.rcmail.folder_create = function() {
      win.kolab_files_folder_create_dialog();
    };
  }
};

// file selection dialog
function kolab_files_selector_dialog()
{
  var dialog = $('#files-compose-dialog'), buttons = {};

  buttons[rcmail.gettext('kolab_files.attachsel')] = function () {
    var list = [];
    $('#filelist tr.selected').each(function() {
      list.push($(this).data('file'));
    });

    kolab_dialog_close(this);

    if (list.length) {
      // display upload indicator and cancel button
      var content = '<span>' + rcmail.get_label('kolab_files.attaching') + '</span>',
        id = new Date().getTime();

      rcmail.add2attachment_list(id, {name:'', html:content, classname:'uploading', complete:false});

      // send request
      rcmail.http_post('plugin.kolab_files', {
        act: 'attach-file',
        files: list,
        id: rcmail.env.compose_id,
        uploadid: id
      });
    }
  };

  buttons[rcmail.gettext('kolab_files.cancel')] = function () {
    kolab_dialog_close(this);
  };

  // show dialog window
  kolab_dialog_show(dialog, {
    title: rcmail.gettext('kolab_files.selectfiles'),
    buttons: buttons,
    button_classes: ['mainaction save', 'cancel'],
    classes: {'ui-dialog': 'selection-dialog files-dialog'},
    minWidth: 500,
    minHeight: 300,
    width: 700,
    height: 500
  }, function() { rcmail.fileslist.resize(); });

  if (!rcmail.env.files_loaded) {
    rcmail.env.folder_list_selector = '#files-compose-dialog #files-folder-list';
    rcmail.env.folder_search_selector = '#files-compose-dialog #foldersearch';
    file_api.folder_list();
    rcmail.env.files_loaded = true;
  }
  else {
    rcmail.fileslist.clear_selection();
  }
};

function kolab_files_attach_menu_open(p)
{
  if (!p || !p.props || p.props.menu != 'attachmentmenu')
    return;

  var id = p.props.id;

  $('#attachmenusaveas').unbind('click').attr('onclick', '').click(function(e) {
    return kolab_directory_selector_dialog(id);
  });
};

// folder creation dialog
function kolab_files_folder_create_dialog()
{
  var dialog = $('#files-folder-create-dialog'),
    buttons = {},
    select = $('select[name="parent"]', dialog).html(''),
    input = $('input[name="name"]', dialog).val('');

  buttons[rcmail.gettext('kolab_files.create')] = function () {
    var folder = '', name = input.val(), parent = select.val();

    if (!name)
      return;

    if (parent)
      folder = parent + file_api.env.directory_separator;

    folder += name;

    file_api.folder_create(folder);
    kolab_dialog_close(this);
  };

  buttons[rcmail.gettext('kolab_files.cancel')] = function () {
    kolab_dialog_close(this);
  };

  // show dialog window
  kolab_dialog_show(dialog, {
    title: rcmail.gettext('kolab_files.foldercreate'),
    buttons: buttons,
    button_classes: ['mainaction save', 'cancel'],
    height: 200
  });

  // Fix submitting form with Enter
  $('form', dialog).submit(kolab_dialog_submit_handler);

  // build parent selector
  file_api.folder_select_element(select, {empty: !rcmail.env.files_caps.NOROOT, writable: true});
};

// folder edit dialog
function kolab_files_folder_edit_dialog()
{
  var dialog = $('#files-folder-edit-dialog'),
    buttons = {},
    separator = file_api.env.directory_separator,
    current_folder = file_api.env.folder,
    arr = current_folder.split(separator),
    folder = arr.pop(),
    path = arr.join(separator),
    select = $('select[name="parent"]', dialog).html(''),
    input = $('input[name="name"]', dialog).val(folder);

  buttons[rcmail.gettext('kolab_files.save')] = function () {
    var folder = '', name = input.val(), parent = select.val();

    if (!name)
      return;

    if (parent)
      folder = parent + separator;

    folder += name;

    file_api.folder_rename(current_folder, folder);
    kolab_dialog_close(this);
  };

  buttons[rcmail.gettext('kolab_files.cancel')] = function () {
    kolab_dialog_close(this);
  };

  // show dialog window
  kolab_dialog_show(dialog, {
    title: rcmail.gettext('kolab_files.folderedit'),
    buttons: buttons,
    button_classes: ['mainaction save', 'cancel'],
    height: 200
  });

  // Fix submitting form with Enter
  $('form', dialog).submit(kolab_dialog_submit_handler);

  // build parent selector
  file_api.folder_select_element(select, {selected: path, empty: !rcmail.env.files_caps.NOROOT});
};

// folder sharing dialog
function kolab_files_folder_share_dialog()
{
  var dialog = $('<iframe>').attr('src', rcmail.url('share', {_folder: file_api.env.folder, _framed: 1}));

  rcmail.simple_dialog(dialog, rcmail.gettext('kolab_files.foldershare'), null, {
    cancel_button: 'close',
    width: 600,
    height: 500
  });
};

// folder mounting dialog
function kolab_files_folder_mount_dialog()
{
  var args = {buttons: {}, title: rcmail.gettext('kolab_files.foldermount')},
    dialog = $('#files-folder-mount-dialog'),
    input = $('#folder-mount-name').val('');

  args.buttons[rcmail.gettext('kolab_files.save')] = function () {
    var args = {}, folder = input.val(),
      driver = $('input[name="driver"]:checked', dialog).val();

    if (!folder || !driver)
      return;

    args.folder = folder;
    args.driver = driver;

    $('#source-' + driver + ' input').each(function() {
      if (this.name.startsWith(driver + '[')) {
        args[this.name.substring(driver.length + 1, this.name.length - 1)] = this.value;
      }
    });

    $('.auth-options input', dialog).each(function() {
      args[this.name] = this.type == 'checkbox' && !this.checked ? '' : this.value;
    });

    file_api.folder_mount(args);
    kolab_dialog_close(this);
  };

  args.buttons[rcmail.gettext('kolab_files.cancel')] = function () {
    kolab_dialog_close(this);
  };

  // initialize drivers list
  if (!rcmail.drivers_list_initialized) {
    rcmail.drivers_list_initialized = true;

    $('td.source', dialog).each(function() {
      var td = $(this),
        id = td.attr('id').replace('source-', ''),
        meta = rcmail.env.external_sources[id];

      $.each(meta.form_values || [], function(i, v) {
        td.find('#source-' + id + '-' + i).val(v);
      });

      td.click(function() {
        $('td.selected', dialog).removeClass('selected');
        dialog.find('.driverform').hide();
        $(this).addClass('selected').find('.driverform').show();
        $('input[type="radio"]', this).prop('checked', true);
      });
    });
  }

  args.button_classes = ['mainaction save', 'cancel'];

  // show dialog window
  kolab_dialog_show(dialog, args, function() {
    $('td.source:first', dialog).click();
    input.focus();
  });
};

// file edit dialog
function kolab_files_file_edit_dialog(file, sessions, readonly)
{
  var content = [], items = [], height = 300,
    dialog = $('#files-file-edit-dialog'),
    buttons = {}, name = file_api.file_name(file),
    title = rcmail.gettext('kolab_files.editfiledialog'),
    mainaction = rcmail.gettext('kolab_files.select'),
    item_fn = function(id, txt, classes) {
        return $('<label>').attr('class', 'session' + (classes ? ' ' + classes : ''))
          .append($('<input>').attr({name: 'opt', value: id, type: 'radio'})).append($('<span>').text(txt));
      },
    select_fn = function(dlg) {
      var session, input = $('input:checked', dialog), id = input.val();

      if (dlg)
        kolab_dialog_close(dlg);

      if (id && input.parent().is('.session.request')) {
        document_editor.invitation_request({session_id: id});
        return;
      }

      if (readonly && (id == 0 || !input.length))
        return kolab_files_file_create_dialog(file);

      rcmail.files_edit(id ? id : true);
    };

  // Create sessions selection
  if (sessions && sessions.length) {
    items.push($('<div>').text(rcmail.gettext('kolab_files.editfilesessions')));

    // first display owned sessions, then invited, other at the end
    $.each(sessions, function() {
      if (this.is_owner) {
        var txt = rcmail.gettext('kolab_files.ownedsession');
        items.push(item_fn(this.id, txt, 'owner'));
      }
    });

    if (items.length == 1)
      items.push(item_fn(0, rcmail.gettext('kolab_files.newsession' + (readonly ? 'ro' : ''))));

    $.each(sessions, function() {
      if (this.is_invited) {
        var txt = rcmail.gettext('kolab_files.invitedsession')
          .replace('$user', this.owner_name ? this.owner_name : this.owner);
        items.push(item_fn(this.id, txt, 'invited'));
      }
    });

    $.each(sessions, function() {
      if (!this.is_owner && !this.is_invited) {
        var txt = rcmail.gettext('kolab_files.joinsession')
          .replace('$user', this.owner_name ? this.owner_name : this.owner);
        items.push(item_fn(this.id, txt, 'request'));
      }
    });

    // check the first option
    $('input', items[1]).attr('checked', true);

    $('div', dialog).html(items);

    // if there's only one session and it's owned, skip the dialog
    if (!readonly && items.length == 2 && $('input:checked', dialog).parent().is('.owner'))
      return select_fn();
  }
  // no ongoing session, folder is readonly warning
  else {
    title = rcmail.gettext('kolab_files.editfilerotitle');
    height = 150;
    $('div', dialog).text(rcmail.gettext('kolab_files.editfilero'));
    mainaction = rcmail.gettext('kolab_files.create');
  }

  buttons[mainaction] = function() { select_fn(this); };
  buttons[rcmail.gettext('kolab_files.cancel')] = function () {
    kolab_dialog_close(this);
  };

  // show dialog window
  kolab_dialog_show(dialog, {
    title: title,
    buttons: buttons,
    button_classes: ['mainaction save', 'cancel'],
    minHeight: height - 100,
    height: height
  });
};

// file rename dialog
function kolab_files_file_rename_dialog(file)
{
  var dialog = $('#files-file-rename-dialog'),
    buttons = {}, name = file_api.file_name(file)
    input = $('input[name="name"]', dialog).val(name);

  buttons[rcmail.gettext('kolab_files.save')] = function() {
    var folder = file_api.file_path(file), name = input.val();

    if (!name)
      return;

    name = folder + file_api.env.directory_separator + name;

    if (name != file)
      file_api.file_rename(file, name);

    kolab_dialog_close(this);
  };
  buttons[rcmail.gettext('kolab_files.cancel')] = function() {
    kolab_dialog_close(this);
  };

  // Fix submitting form with Enter
  $('form', dialog).submit(kolab_dialog_submit_handler);

  // show dialog window
  kolab_dialog_show(dialog, {
    title: rcmail.gettext('kolab_files.renamefile'),
    buttons: buttons,
    button_classes: ['mainaction save', 'cancel'],
    minHeight: 100,
    height: 100
  });
};

// file creation (or cloning) dialog
function kolab_files_file_create_dialog(file)
{
  var buttons = {}, action = file ? 'copy' : 'create',
    button_classes = ['mainaction save edit'],
    dialog = $('#files-file-create-dialog'),
    type_select = $('select[name="type"]', dialog),
    select = $('select[name="parent"]', dialog).html(''),
    input = $('input[name="name"]', dialog).val(''),
    create_func = function(dialog, editaction) {
      var sel, folder = select.val(), type = type_select.val(), name = input.val();

      if (!name || !folder || !file_api.is_writable(folder))
        return;

      if (!/\.[a-z0-9]{1,5}$/.test(name)) {
        name += '.' + rcmail.env.file_extensions[type];
      }

      name = folder + file_api.env.directory_separator + name;

      // get type of cloned file
      if (file) {
        if (rcmail.env.file_data)
          type = rcmail.env.file_data.type;
        else {
          sel = rcmail.fileslist.get_selection();
          type = $('#rcmrow' + sel[0]).data('type');
        }
      }

      file_api.file_create(name, type, editaction, file);
      kolab_dialog_close(dialog);
  };

  buttons[rcmail.gettext('kolab_files.' + action + 'andedit')] = function() {
    create_func(this, true);
  };

  if (action == 'create') {
    button_classes.push('save');
    buttons[rcmail.gettext('kolab_files.create')] = function() {
      create_func(this);
    };
    type_select.parent('tr').show();
  }
  else {
    input.val(file_api.file_name(file));
    type_select.parent('tr').hide();
  }

  button_classes.push('cancel');
  buttons[rcmail.gettext('kolab_files.cancel')] = function() {
    kolab_dialog_close(this);
  };

  // Fix submitting form with Enter
  $('form', dialog).submit(kolab_dialog_submit_handler);

  // show dialog window
  kolab_dialog_show(dialog, {
    title: rcmail.gettext('kolab_files.' + action + 'file'),
    buttons: buttons,
    button_classes: button_classes,
    minHeight: 150,
    height: 250
  });

  // build folder selector
  file_api.folder_select_element(select, {writable: true});
};

// file session dialog
function kolab_files_session_dialog(session)
{
  var buttons = {}, button_classes = [],
    dialog = $('#files-session-dialog'),
    filename = file_api.file_name(session.file),
    owner = session.owner_name || session.owner,
    title = rcmail.gettext('kolab_files.sessiondialog'),
    content = rcmail.gettext('kolab_files.sessiondialogcontent'),
    join_session = function(id) {
      var viewer = file_api.file_type_supported('application/vnd.oasis.opendocument.text', rcmail.env.files_caps);
        params = {action: 'edit', session: id};

      file_api.file_open('', viewer, params);
    };

  content = content.replace('$file', filename).replace('$owner', owner);
  $('div', dialog).text(content);

  if (session.is_owner) {
    buttons[rcmail.gettext('kolab_files.open')] = function() {
      kolab_dialog_close(this);
      join_session(session.id);
    };
    buttons[rcmail.gettext('kolab_files.close')] = function() {
      kolab_dialog_close(this);
      file_api.document_delete(session.id);
    };
    button_classes = ['mainaction edit', 'delete'];
  }
  else if (session.is_invited) {
    button_classes = ['mainaction edit'];
    // @TODO: check if not-accepted and provide "Decline invitation" button
    // @TODO: Add "Accept button", add comment field to the dialog
    buttons[rcmail.gettext('kolab_files.join')] = function() {
      kolab_dialog_close(this);
      join_session(session.id);
    };
  }
  else {
    button_classes = ['mainaction session request'];
    buttons[rcmail.gettext('kolab_files.request')] = function() {
      kolab_dialog_close(this);
      // @TODO: Add comment field to the dialog
      document_editor.invitation_request({session_id: session.id});
    };
  }

  button_classes.push('cancel');
  buttons[rcmail.gettext('kolab_files.cancel')] = function() {
    kolab_dialog_close(this);
  };

  // show dialog window
  kolab_dialog_show(dialog, {
    title: title,
    buttons: buttons,
    button_classes: button_classes,
    minHeight: 100,
    height: 150
  });
};

function kolab_dialog_show(content, params, onopen)
{
  params = $.extend({
    modal: true,
    resizable: true,
    minWidth: 400,
    minHeight: 300,
    width: 500,
    height: 400
  }, params || {});

  // dialog close handler
  params.close = function(e, ui) {
    var elem, stack = rcmail.dialog_stack;

    content.appendTo(document.body);
    content.hide(); // for Larry's dialogs
    $(this).parent().remove(); // remove dialog

    // focus previously focused element (guessed)
    stack.pop();
    if (stack.length) {
      elem = stack[stack.length-1].find('input[type!="hidden"]:not(:hidden):first');
      if (!elem.length)
        elem = stack[stack.length-1].parent().find('a[role="button"], .ui-dialog-buttonpane button').first();
    }

    (elem && elem.length ? elem : window).focus();

    rcmail.ksearch_blur();
  };

  // This is required for Larry's dialogs
  params.create = function() { content.show(); };

  // display it as popup
  var dialog = rcmail.show_popup_dialog(content, params.title, params.buttons, params);

  if (onopen) onopen(content);

  // save dialog reference, to handle focus when closing one of opened dialogs
  if (!rcmail.dialog_stack)
    rcmail.dialog_stack = [];

  rcmail.dialog_stack.push(dialog);
};

// Handle form submit with Enter key, click first dialog button instead
function kolab_dialog_submit_handler()
{
  $(this).parents('.ui-dialog').find('.ui-dialog-buttonpane button').first().click();
  return false;
};

// Hides dialog
function kolab_dialog_close(dialog, destroy)
{
  (rcmail.is_framed() ? window.parent : window).$(dialog).dialog(destroy ? 'destroy' : 'close');
};

// smart upload button
function kolab_files_upload_input(button)
{
  var link = $(button),
    file = $('<input>'),
    offset = link.offset();

  function move_file_input(e) {
    file.css({top: (e.pageY - offset.top - 10) + 'px', left: (e.pageX - offset.left - 10) + 'px'});
  }

  file.attr({name: 'file[]', type: 'file', multiple: 'multiple', size: 5, title: link.attr('title'), tabindex: "-1"})
    .change(function() { rcmail.files_upload('#filesuploadform'); })
    .click(function() { setTimeout(function() { link.mouseleave(); }, 20); })
    // opacity:0 does the trick, display/visibility doesn't work
    .css({opacity: 0, cursor: 'pointer', outline: 'none', position: 'absolute'});

  // In FF and IE we need to move the browser file-input's button under the cursor
  // Thanks to the size attribute above we know the length of the input field
  if (bw.mz || bw.ie)
    file.css({marginLeft: '-80px'});

  // Note: now, I observe problem with cursor style on FF < 4 only
  // Need position: relative (Bug #2615)
  link.css({overflow: 'hidden', cursor: 'pointer', position: 'relative'})
    .mouseenter(function() { this.__active = true; })
    // place button under the cursor
    .mousemove(function(e) {
      if (rcmail.commands['files-upload'] && this.__active)
        move_file_input(e);
      // move the input away if button is disabled
      else
        $(this).mouseleave();
    })
    .mouseleave(function() {
      file.css({top: '-10000px', left: '-10000px'});
      this.__active = false;
    })
    .attr('onclick', '') // remove default button action
    .click(function(e) {
      // forward click if mouse-enter event was missed
      if (rcmail.commands['files-upload'] && !this.__active) {
        this.__active = true;
        move_file_input(e);
        file.trigger(e);
      }
    })
    .mouseleave() // initially disable/hide input
    .append(file);
};


/***********************************************************/
/**********          Main functionality           **********/
/***********************************************************/

// for reordering column array (Konqueror workaround)
// and for setting some files/sessions list global variables
function kolab_files_list_coltypes(type)
{
  if (!type) type = 'files';

  var n, list = rcmail[type + 'list'];

  rcmail.env.subject_col = null;

  if ((n = $.inArray('name', rcmail.env[type + '_coltypes'])) >= 0) {
    rcmail.env.subject_col = n;
    list.subject_col = n;
  }

  list.init_header();
};

function kolab_files_set_list_options(cols, sort_col, sort_order, type)
{
  var update = 0, i, idx, name, newcols = [], oldcols = rcmail.env[type + '_coltypes'];

  if (sort_col === undefined)
    sort_col = rcmail.env[type + '_sort_col'];
  if (!sort_order)
    sort_order = rcmail.env[type + '_sort_order'];

  if (rcmail.env[type + '_sort_col'] != sort_col || rcmail.env[type + '_sort_order'] != sort_order) {
    update = 1;
    // set table header class
    kolab_files_set_list_sorting(sort_col, sort_order, type);
  }

  if (cols && cols.length) {
    // make sure new columns are added at the end of the list
    for (i=0; i<oldcols.length; i++) {
      name = oldcols[i];
      idx = $.inArray(name, cols);
      if (idx != -1) {
        newcols.push(name);
        delete cols[idx];
      }
    }
    for (i=0; i<cols.length; i++)
      if (cols[i])
        newcols.push(cols[i]);

    if (newcols.join() != oldcols.join()) {
      update += 2;
      oldcols = newcols;
    }
  }

  if (update == 1)
    rcmail.command(type + '-list', {sort: sort_col, reverse: sort_order == 'DESC'});
  else if (update) {
    rcmail.http_post('files/prefs', {
      type: type,
      kolab_files_list_cols: oldcols,
      kolab_files_sort_col: sort_col,
      kolab_files_sort_order: sort_order
      }, rcmail.set_busy(true, 'loading'));
  }
};

function kolab_files_set_list_sorting(sort_col, sort_order, type)
{
  // set table header class
  var old_col = rcmail.env[type + '_sort_col'],
    old_sort = rcmail.env[type + '_sort_order'];

  $('#rcm' + old_col).removeClass('sortedASC sortedDESC');
  $('#rcm' + sort_col).addClass('sorted' + sort_order);

  rcmail.env[type + '_sort_col'] = sort_col;
  rcmail.env[type + '_sort_order'] = sort_order;
};

function kolab_files_set_coltypes(list, type)
{
  var i, found, name, cols = list.list.tHead.rows[0].cells;

  rcmail.env[type + '_coltypes'] = [];

  for (i=0; i<cols.length; i++)
    if (cols[i].id && cols[i].id.match(/^rcm/)) {
      name = cols[i].id.replace(/^rcm/, '');
      rcmail.env[type + '_coltypes'].push(name);
    }

//  if ((found = $.inArray('name', rcmail.env.files_coltypes)) >= 0)
//    rcmail.env.subject_col = found;
  rcmail.env.subject_col = list.subject_col;

  rcmail.http_post('files/prefs', {kolab_files_list_cols: rcmail.env[type + '_coltypes'], type: type});
};

function kolab_files_sessions_list_dblclick(list)
{
  rcmail.command('sessions-open');
};

function kolab_files_sessions_list_select(list)
{
  var selected = list.selection.length;

  rcmail.enable_command('sessions-open', selected == 1);
};

function kolab_files_sessions_list_keypress(list)
{
  if (list.modkey == CONTROL_KEY)
    return;

  if (list.key_pressed == list.ENTER_KEY) {
    // use setTimeout(), otherwise the opened dialog will be immediately closed
    setTimeout(function() { rcmail.command('sessions-open'); }, 50);
  }
//  else if (list.key_pressed == list.DELETE_KEY || list.key_pressed == list.BACKSPACE_KEY)
//    rcmail.command('sessions-delete');
};

function kolab_files_list_dblclick(list)
{
  rcmail.command('files-open');
};

function kolab_files_list_select(list)
{
  var selected = list.selection.length;

  rcmail.enable_command(rcmail.env.file_commands_all, selected);
  rcmail.enable_command(rcmail.env.file_commands, selected == 1);

    // reset all-pages-selection
//  if (list.selection.length && list.selection.length != list.rowcount)
//    rcmail.select_all_mode = false;

  if (selected == 1) {
    // get file mimetype
    var elem = $('tr.selected', list.list),
      type = elem.data('type'),
      folder = file_api.file_path(elem.data('file'));

    rcmail.env.viewer = file_api.file_type_supported(type, rcmail.env.files_caps);

    if (!file_api.is_writable(folder))
      rcmail.enable_command('files-delete', 'files-rename', false);
  }
  else
    rcmail.env.viewer = 0;

  rcmail.enable_command('files-edit', (rcmail.env.viewer & 4) == 4);
  rcmail.enable_command('files-open', rcmail.env.viewer);
};

function kolab_files_list_keypress(list)
{
  if (list.modkey == CONTROL_KEY)
    return;

  if (list.key_pressed == list.ENTER_KEY)
    rcmail.command('files-open');
  else if (list.key_pressed == list.DELETE_KEY || list.key_pressed == list.BACKSPACE_KEY)
    rcmail.command('files-delete');
};

function kolab_files_drag_start(e)
{
  rcmail.env.drag_target = null;

  if (rcmail.folder_list)
    rcmail.folder_list.drag_start();
};

function kolab_files_drag_end(e)
{
  if (rcmail.folder_list) {
    rcmail.folder_list.drag_end();

    if (rcmail.env.drag_target) {
      var modkey = rcube_event.get_modifier(e),
        menu = rcmail.gui_objects.file_dragmenu;

      rcmail.fileslist.draglayer.hide();

      if (menu && modkey == SHIFT_KEY && rcmail.commands['files-copy']) {
        var pos = rcube_event.get_mouse_pos(e);
        rcmail.show_menu(menu.id, true, e);
        $(menu).css({top: (pos.y-10)+'px', left: (pos.x-10)+'px'});
        return;
      }

      rcmail.command('files-move', rcmail.env.drag_target);
      rcmail.env.drag_target = null;
    }
  }
};

function kolab_files_drag_move(e)
{
  if (rcmail.folder_list) {
    var mouse = rcube_event.get_mouse_pos(e);

    rcmail.env.drag_target = rcmail.folder_list.intersects(mouse, true);
  }
};

function kolab_files_drag_menu_action(command)
{
  var menu = rcmail.gui_objects.file_dragmenu;

  if (menu)
    $(menu).hide();

  rcmail.command(command, rcmail.env.drag_target);
};

function kolab_files_selected()
{
  var files = [];
  $.each(rcmail.fileslist.get_selection(), function(i, v) {
    var name, row = $('#rcmrow'+v);

    if (row.length == 1 && (name = row.data('file')))
      files.push(name);
  });

  return files;
};

function kolab_files_frame_load(frame)
{
  var win = frame.contentWindow,
    info = rcmail.env.file_data;

  try {
    rcmail.file_editor = win.file_editor;
  }
  catch (e) {};

  rcmail.enable_command('files-edit', (rcmail.file_editor && rcmail.file_editor.editable)
    || rcmail.env.editor_type
    || (file_api.file_type_supported(rcmail.env.file_data.type, rcmail.env.files_caps) & 4));

  rcmail.enable_command('files-print', (rcmail.file_editor && rcmail.file_editor.printable)
    || (info && /^image\//i.test(info.type)));

  // Enable image tools
  rcmail.enable_command('image-scale', 'image-rotate', info && !!/^image\//.test(info.type));
  rcmail.gui_objects.messagepartframe = frame;

  // on edit page switch immediately to edit mode
  if (rcmail.file_editor && rcmail.file_editor.editable && rcmail.env.action == 'edit')
    rcmail.files_edit();

  // detect Print button and check if it can be accessed
  try {
    if ($('#fileframe').contents().find('#print').length)
      rcmail.enable_command('files-print', true);
  }
  catch(e) {};
};

// activate html5 file drop feature (if browser supports it)
function kolab_files_drag_drop_init(container)
{
  if (!window.FormData && !(window.XMLHttpRequest && XMLHttpRequest.prototype && XMLHttpRequest.prototype.sendAsBinary)) {
    return;
  }

  if (!container.length)
    return;

  $(document.body).bind('dragover dragleave drop', function(e) {
    if (!file_api.is_writable())
      return;

    e.preventDefault();
    container[e.type == 'dragover' ? 'addClass' : 'removeClass']('active');
  });

  container.bind('dragover dragleave', function(e) {
    return kolab_files_drag_hover(e);
  })
  container.children('div').bind('dragover dragleave', function(e) {
    return kolab_files_drag_hover(e);
  })
  container.get(0).addEventListener('drop', function(e) {
      // abort event and reset UI
      kolab_files_drag_hover(e);
      return file_api.file_drop(e);
    }, false);
};

// handler for drag/drop on element
function kolab_files_drag_hover(e)
{
  if (!file_api.is_writable())
    return;

  e.preventDefault();
  e.stopPropagation();

  var elem = $(e.target);

  if (!elem.hasClass('droptarget'))
    elem = elem.parents('.droptarget');

  elem[e.type == 'dragover' ? 'addClass' : 'removeClass']('hover');
};

// returns localized file size
function kolab_files_file_size(size)
{
  var i, units = ['GB', 'MB', 'KB', 'B'];

  size = file_api.file_size(size);

  for (i = 0; i < units.length; i++)
    if (size.toUpperCase().indexOf(units[i]) > 0)
      return size.replace(units[i], rcmail.gettext(units[i]));

  return size;
};

function kolab_files_progress_str(param)
{
  var current, total = file_api.file_size(param.total).toUpperCase();

  if (total.indexOf('GB') > 0)
    current = parseFloat(param.current/1073741824).toFixed(1);
  else if (total.indexOf('MB') > 0)
    current = parseFloat(param.current/1048576).toFixed(1);
  else if (total.indexOf('KB') > 0)
    current = parseInt(param.current/1024);
  else
    current = param.current;

  total = kolab_files_file_size(param.total);

  return rcmail.gettext('uploadprogress')
    .replace(/\$percent/, param.percent + '%')
    .replace(/\$current/, current)
    .replace(/\$total/, total);
};

function kolab_files_share_form_init()
{
  $('fieldset > table', rcmail.gui_objects.shareform).each(function() {
    var cnt = 0,
      mode = $(this).data('mode'),
      single = $(this).data('single');

    $('tbody > tr', this).each(function(i, row) {
      if (!i) {
        $('button.submit', row).on('click', function() { file_api.sharing_submit(rcmail.env.folder, row, mode); });
        $('input[data-autocomplete]').each(function() {
          var input = $(this), req_name = 'filesac-' + mode;
          kolab_files_autocomplete(input, req_name, function(e) {
            $(row).find('input[type=hidden]').each(function() {
              $(this).val(e.data[this.name] || '');
            });
          });
        });
      }
      else {
        cnt++;
        $('button.delete', row).on('click', function() { file_api.sharing_delete(rcmail.env.folder, row, mode); });
        $('select,input[type=text]', row).on('change', function() { file_api.sharing_update(rcmail.env.folder, row, mode); });
      }
    });

    if (single && cnt) {
      $('tbody > tr:first', this).find('button.submit, input, select').prop('disabled', true);
    }
  });
};

function kolab_files_autocomplete(input, action, insert_callback)
{
  rcmail.init_address_input_events(input, {action: action});

  // "Redirect" autocomplete requests to Chwala API
  if (rcmail.env.files_api_version > 3) {
    rcmail.addEventListener('request' + action, function(post) {
      var params = {search: post._search, mode: input.data('autocomplete') || 'user', folder: rcmail.env.folder},
        callback = function(response) {
          var result = response.result || [];
          $.each(result, function() {
            // add fake email to skip Roundcube's group expanding code
            if (this.type == 'group' && !this.email)
              this.email = 1;
            });

            rcmail.ksearch_query_results(result, post._search, post._reqid);
        },
        error_callback = function(o, status, err, data) {
          file_api.http_error(o, status, err, data);
          rcmail.ksearch_query_results([], post._search, post._reqid);
        };

      return file_api.get('autocomplete', params, callback, error_callback);
    });
  }

  // Update hidden fields on selection
  rcmail.addEventListener('autocomplete_insert', function(e) {
    if (e.field == input[0]) {
      input.val(e.insert.replace(/[, ]+$/, ''));
    }
    if (insert_callback)
      insert_callback(e);
  });
};


/**********************************************************/
/*********     document editor functionality     **********/
/**********************************************************/

// Initialize document toolbar functionality
function document_editor_init()
{
  var info = rcmail.env.file_data;

  rcmail.enable_command('document-export', 'document-print', true);

  if (info && info.session && info.session.is_owner)
    rcmail.enable_command('document-close', 'document-editors', true);
};

// executed on editing session termination
function document_editor_close()
{
  var dialog = $('<div>').addClass('popupdialog').attr('role', 'alertdialog')
    .html($('<span>').text(rcmail.gettext('kolab_files.sessionterminated')));

  rcmail.alert_dialog(dialog, function() { window.close(); }, {
      title: rcmail.gettext('kolab_files.sessionterminatedtitle')
  });

  return false; // skip Chwala's error message
};

rcube_webmail.prototype.document_save = function()
{
  document_editor.save(function(data) {
    rcmail.enable_command('document-save', false);
  });
};

rcube_webmail.prototype.document_export = function(type)
{
  document_editor.export(type || 'odt');
};

rcube_webmail.prototype.document_print = function()
{
  document_editor.print();
};

rcube_webmail.prototype.document_editors = function()
{
  kolab_files_editors_dialog();
};

// close editing session
rcube_webmail.prototype.document_close = function()
{
  var delete_fn = function() {
    document_editor.terminate(function() {
      var win = window.opener || window.parent;

      if (win && win.rcmail && win.file_api)
        win.file_api.document_delete(rcmail.env.file_data.session.id);

      // For Elastic: hide the parent dialog
      if (rcmail.is_framed()) {
        parent.$('.ui-dialog:visible button.cancel').click();
      }

      window.close();
    });
  };

  // check document "unsaved changes" state and display a warning
  // skip the warning for WOPI, Collabora will save the document anyway on session close
  if (this.commands['document-save'] && (!this.env.file_data.viewer || !this.env.file_data.viewer.wopi))
    this.confirm_dialog(this.gettext('kolab_files.unsavedchanges'), 'kolab_files.terminate', delete_fn, {button_class: 'delete'});
  else
    delete_fn();
};

// document editors management dialog
function kolab_files_editors_dialog(session)
{
  var items = [], buttons = {},
    info = rcmail.env.file_data,
    dialog = $('#document-editors-dialog'),
    comment = $('#invitation-comment');

  if (!info || !info.session || !info.session.is_owner)
    return;

  // always add the session organizer
  items.push(kolab_files_attendee_record(info.session.owner, 'organizer'));

  $.each(info.session.invitations || [], function(i, u) {
    var record = kolab_files_attendee_record(u.user, u.status, u.user_name);
    items.push(record);
    info.session.invitations[i].record = record;
  });

  $('table > tbody', dialog).html(items);

  buttons[rcmail.gettext('kolab_files.close')] = function() {
    kolab_dialog_close(this);
  };

  // show dialog window
  kolab_dialog_show(dialog, {
    title: rcmail.gettext('kolab_files.manageeditors'),
    buttons: buttons,
    button_classes: ['cancel']
  });

  if (!rcmail.env.editors_dialog) {
    rcmail.env.editors_dialog = dialog;

    kolab_files_autocomplete($('#invitation-editor-name'), 'files/autocomplete', function(e) {
      var success = false;
      if (e.field.name == 'participant') {
        // e.data && e.data.type == 'group' ? 'GROUP' : 'INDIVIDUAL'
        success = kolab_files_add_attendees(e.insert, comment.val());
      }
      if (e.field && success) {
        e.field.value = '';
      }
    });

    $('#invitation-editor-add').click(function() {
      var input = $('#invitation-editor-name');
      rcmail.ksearch_blur();
      if (kolab_files_add_attendees(input.val(), comment.val())) {
        input.val('');
      }
    });
  }
};

// add the given list of participants
function kolab_files_add_attendees(names, comment)
{
  var i, item, success, email, name, attendees = {}, counter = 0;

  names = file_api.explode_quoted_string(names.replace(/,\s*$/, ''), ',');

  // parse name/email pairs
  for (i = 0; i < names.length; i++) {
    email = name = '';
    item = $.trim(names[i]);

    if (!item.length) {
      continue;
    } // address in brackets without name (do nothing)
    else if (item.match(/^<[^@]+@[^>]+>$/)) {
      email = item.replace(/[<>]/g, '');
    } // address without brackets and without name (add brackets)
    else if (rcube_check_email(item)) {
      email = item;
    } // address with name
    else if (item.match(/([^\s<@]+@[^>]+)>*$/)) {
      email = RegExp.$1;
      name = item.replace(email, '').replace(/^["\s<>]+/, '').replace(/["\s<>]+$/, '');
    }

    if (email) {
      attendees[email] = {user: email, name: name};
      counter++;
    }
    else {
      rcmail.alert_dialog(rcmail.gettext('noemailwarning'));
    }
  }

  success = counter > 0;

  // remove already existing entries
  if (counter) {
    if (attendees[rcmail.env.file_data.session.owner]) {
      delete attendees[this.user];
      counter--;
    }
    $.each(rcmail.env.file_data.session.invitations || [], function() {
      if (this.user in attendees) {
        delete attendees[this.user];
        counter--;
      }
    });
  }

  if (counter)
    file_api.document_invite(rcmail.env.file_data.session.id, attendees, comment);

  return success;
};

function kolab_files_attendee_record(user, status, username)
{
  var options = [], select,
    type = status ? status.replace(/-.*$/, '') : '',
    name = $('<td class="name">').text(user),
    buttons = $('<td class="options">'),
    state = $('<td class="status">').text(rcmail.gettext('kolab_files.status' + type));

  // @todo: accept/decline invitation request
  if (type == 'requested' || status == 'accepted-owner' || status == 'declined-owner') {
    select = $('<select>').change(function() {
        var val = $(this).val(), map = {accepted: 'invitation_accept', declined: 'invitation_decline'};
        if (map[val])
          document_editor[map[val]]({user: user, session_id: rcmail.env.file_data.session.id});
      });

    if (type == 'requested')
      options.push($('<option>').text(rcmail.gettext('kolab_files.statusrequested')).attr('value', 'requested'));

    options.push($('<option>').text(rcmail.gettext('kolab_files.statusaccepted')).attr('value', 'accepted'));
    options.push($('<option>').text(rcmail.gettext('kolab_files.statusdeclined')).attr('value', 'declined'));

    state.html(select.html(options).val(type));
  }

  // delete button
  if (status != 'organizer') {
    $('<a>').attr({'class': 'delete', href: '#', title: rcmail.gettext('kolab_files.removeparticipant')})
      .click(function() {
        file_api.document_cancel(rcmail.env.file_data.session.id, [user]);
      })
      .appendTo(buttons);
  }

  if (username && status != 'organizer')
    name.html($('<a>').attr({href: 'mailto:' + user, 'class': 'mailtolink'}).text(username))
      .click(function(e) { rcmail.command('compose', user, e.target, e); return false; });

  return $('<tr>').attr('class', 'invitation' + (type ? ' ' + type : ''))
      .append(name).append(state).append(buttons);
};

function document_editor_invitation_handler(invitation)
{
  // make the "More" link clickable
  $('#' + invitation.id).parent('div').click(function() { kolab_files_invitation_dialog(invitation); });
};

function kolab_files_invitation_dialog(invitation)
{
  var text, records = [], content = [], buttons = {}, button_classes = [],
    dialog = $('#document-invitation-dialog'),
    data_map = {status: 'status', 'changed': 'when', filename: 'file', comment: 'comment'},
    record_fn = function(type, label, value) {
        records.push($('<tr>').attr('class', type)
          .append($('<td class="label">').text(rcmail.gettext('kolab_files.'+ label)))
          .append($('<td>').text(value))
        );
      };
    join_session = function() {
        var viewer = file_api.file_type_supported('application/vnd.oasis.opendocument.text', rcmail.env.files_caps);
          params = {action: 'edit', session: invitation.session_id};

        file_api.file_open('', viewer, params);
      };

  if (!dialog.length)
    dialog = $('<div>').attr({id: 'document-invitation-dialog', role: 'dialog', 'aria-hidden': 'true'})
      .append($('<div>'))
      .appendTo(document.body);

  if (!invitation.is_session_owner) {
    if (invitation.status == 'invited') {
      text = document_editor.invitation_msg(invitation);
      button_classes = ['session join', 'session accept', 'session decline delete'];

      buttons[rcmail.gettext('kolab_files.join')] = function() {
        join_session();
        kolab_dialog_close(this);
      };
      buttons[rcmail.gettext('kolab_files.accept')] = function() {
        document_editor.invitation_accept(invitation);
        kolab_dialog_close(this);
      };
      buttons[rcmail.gettext('kolab_files.decline')] = function() {
        document_editor.invitation_decline(invitation);
        kolab_dialog_close(this);
      };
    }
    else if (invitation.status == 'declined-owner') {
      // @todo: add option to request for an invitation again?
      text = document_editor.invitation_msg(invitation);
    }
    else if (invitation.status == 'accepted-owner') {
      text = document_editor.invitation_msg(invitation);

      button_classes = ['mainaction session join'];
      buttons[rcmail.gettext('kolab_files.join')] = function() {
        join_session();
        kolab_dialog_close(this);
      };
    }
  }
  else {
    if (invitation.status == 'accepted') {
      text = document_editor.invitation_msg(invitation);
    }
    else if (invitation.status == 'declined') {
      // @todo: add option to invite the user again?
      text = document_editor.invitation_msg(invitation);
    }
    else if (invitation.status == 'requested') {
      text = document_editor.invitation_msg(invitation);

      button_classes = ['session accept', 'session decline delete'];
      buttons[rcmail.gettext('kolab_files.accept')] = function() {
        document_editor.invitation_accept(invitation);
        kolab_dialog_close(this);
      };
      buttons[rcmail.gettext('kolab_files.decline')] = function() {
        document_editor.invitation_decline(invitation);
        kolab_dialog_close(this);
      };
    }
  }

  if (text) {
    $.each(data_map, function(i, label) {
      var value = invitation[i];
      if (value) {
        if (i == 'status')
          value = rcmail.gettext('kolab_files.status' + value.replace(/-.*$/, ''));

        record_fn(i, label, value);
      }
    });

    content.push($('<div>').text(text));
    content.push($('<table class="propform">').html(records));
  }

  button_classes.push('cancel');
  buttons[rcmail.gettext('kolab_files.close')] = function() {
    kolab_dialog_close(this);
  };

  $('div', dialog).html(content);

  // show dialog window
  kolab_dialog_show(dialog, {
    title: rcmail.gettext('kolab_files.invitationtitle').replace('$file', invitation.filename),
    buttons: buttons,
    button_classes: button_classes
  });
};

/***********************************************************/
/**********              Commands                 **********/
/***********************************************************/

rcube_webmail.prototype.files_sort = function(props)
{
  this.files_sort_handler(props, 'files');
};

rcube_webmail.prototype.sessions_sort = function(props)
{
  this.files_sort_handler(props, 'sessions');
};

rcube_webmail.prototype.files_sort_handler = function(col, type)
{
  var params = {},
    c = type == 'files' ? '' : ('_' + type),
    sort_order = this.env[type + '_sort_order'],
    sort_col = !this.env['kolab_files' + c + '_disabled_sort_col'] ? col : this.env[type + '_sort_col'];

  if (!this.env['kolab_files' + c + '_disabled_sort_order'])
    sort_order = this.env[type + '_sort_col'] == sort_col && sort_order == 'ASC' ? 'DESC' : 'ASC';

  // set table header and update env
  kolab_files_set_list_sorting(sort_col, sort_order, type);

  this.http_post('files/prefs', {kolab_files_sort_col: sort_col, kolab_files_sort_order: sort_order, type: type});

  params.sort = sort_col;
  params.reverse = sort_order == 'DESC';

  this.command(type + '-list', params);
};

rcube_webmail.prototype.files_search = function()
{
  var value = $(this.gui_objects.filesearchbox).val();

  if (value)
    file_api.file_search(value, $('#search_all_folders').is(':checked'));
  else
    file_api.file_search_reset();
};

rcube_webmail.prototype.files_search_reset = function()
{
  $(this.gui_objects.filesearchbox).val('');

  file_api.file_search_reset();
};

rcube_webmail.prototype.files_folder_delete = function(prop, elem, event)
{
  this.hide_menu('folderoptions', event);
  this.confirm_dialog(this.get_label('kolab_files.folderdeleteconfirm'), 'delete', function() {
    file_api.folder_delete(file_api.env.folder);
  });
};

rcube_webmail.prototype.files_delete = function()
{
  this.confirm_dialog(this.get_label('kolab_files.filedeleteconfirm'), 'delete', function() {
    var files = rcmail.env.file ? [rcmail.env.file] : kolab_files_selected();
    file_api.file_delete(files);
  });
};

rcube_webmail.prototype.files_move = function(folder, obj, event, files)
{
  if (!files)
    files = kolab_files_selected();

  if (!folder) {
    var ref = this;
    return this.files_folder_selector(event, function(folder) {
      ref.files_move(folder, null, event, files);
    });
  }

  file_api.file_move(files, folder);
};

rcube_webmail.prototype.files_copy = function(folder, obj, event, files)
{
  if (!files)
    files = kolab_files_selected();

  if (!folder) {
    var ref = this;
    return this.files_folder_selector(event, function(folder) {
      ref.files_copy(folder, null, event, files);
    });
  }

  file_api.file_copy(files, folder);
};

  // create folder selector popup
rcube_webmail.prototype.files_folder_selector = function(event, callback)
{
  if (this.folder_selector_reset)
    this.destroy_entity_selector('folder-selector');

  // The list is incomplete, reset needed before next use
  this.folder_selector_reset = file_api.list_updates > 0;

  this.entity_selector('folder-selector', callback, file_api.env.folders, function(folder, a, folder_fullname) {
    var n = folder.depth || 0,
        row = $('<li>');

      if (folder.virtual || folder.readonly)
        a.addClass('virtual').attr({'aria-disabled': 'true', tabindex: '-1'});
      else
        a.addClass('active').data('id', folder_fullname);

      a.css('padding-left', n ? (n * 16) + 'px' : 0);

      // add folder name element
      a.append($('<span>').text(folder.name));

      return row.append(a);
    }, event);
};

rcube_webmail.prototype.files_upload = function(form)
{
  if (form)
    file_api.file_upload(form);
};

rcube_webmail.prototype.files_list = function(param)
{
  // just rcmail wrapper, to handle command busy states
  file_api.file_list(param);
};

rcube_webmail.prototype.files_list_update = function(head)
{
  var list = this.fileslist;

  $('thead', list.fixed_header ? list.fixed_header : list.list).html(head);
  kolab_files_list_coltypes();
  file_api.file_list();
};

rcube_webmail.prototype.sessions_list = function(param)
{
  // just rcmail wrapper, to handle command busy states
  file_api.session_list(param);
};

rcube_webmail.prototype.sessions_list_update = function(head)
{
  var list = this.sessionslist;

  $('thead', list.fixed_header ? list.fixed_header : list.list).html(head);
  kolab_files_list_coltypes('sessions');
  file_api.sessions_list();
};

rcube_webmail.prototype.files_get = function()
{
  var files = this.env.file ? [this.env.file] : kolab_files_selected();

  if (files.length == 1)
    file_api.file_get(files[0], {'force-download': true});
};

rcube_webmail.prototype.files_open = function()
{
  var files = kolab_files_selected();

  if (files.length == 1)
    file_api.file_open(files[0], rcmail.env.viewer);
};

// enable file editor
rcube_webmail.prototype.files_edit = function(session)
{
  var files, readonly, sessions, file = this.env.file,
    params = {action: 'edit'};

  if (!file && !this.env.action) {
    files = kolab_files_selected();
    if (files.length == 1)
      file = files[0];
    readonly = !file_api.is_writable(file_api.file_path(file));
  }
  else {
    readonly = !this.env.file_data.writable;
  }

  // check if the folder is read-only or there are ongoing sessions
  // in such cases display dialog for the user to decide what to do
  if (!session) {
    sessions = file_api.file_sessions(file);
    if (sessions.length || readonly) {
      kolab_files_file_edit_dialog(file, sessions, readonly);
      return;
    }
  }
  else if (session !== true)
    params.session = session;

  if (this.file_editor && this.file_editor.editable && !session) {
    this.file_editor.enable();
    this.enable_command('files-save', true);
  }
  else if (this.env.file) {
    var viewer = file_api.file_type_supported(this.env.file_data.type, this.env.files_caps);
    params.local = true;
    file_api.file_open(file, viewer, params);
  }
  else if (file) {
    file_api.file_open(file, this.env.viewer, params);
  }
};

// save changes to the file
rcube_webmail.prototype.files_save = function()
{
  if (!this.file_editor)
    return;

  // binary files like ODF need to be updated using FormData
  if (this.file_editor.getContentCallback) {
    if (!file_api.file_uploader_support())
      return;

    file_api.req = file_api.set_busy(true, 'saving');
//    this.file_editor.disable();
    this.file_editor.getContentCallback(function(content, filename) {
      file_api.file_uploader([content], {
        action: 'file_update',
        params: {file: rcmail.env.file, info: 1, token: file_api.env.token},
        response_handler: 'file_save_response',
        fieldname: 'content',
        single: true
      });
    });

    return;
  }

  var content = this.file_editor.getContent();

  file_api.file_save(this.env.file, content);
};

rcube_webmail.prototype.files_print = function()
{
  if (this.file_editor && this.file_editor.printable)
    this.file_editor.print();
  else if (/^image\//i.test(this.env.file_data.type)) {
    var frame = $('#fileframe').get(0),
      win = frame ? frame.contentWindow : null;

    if (win) {
      win.focus();
      win.print();
    }
  }
  else {
    // e.g. Print button in PDF viewer
    try {
      $('#fileframe').contents().find('#print').click();
    }
    catch(e) {};
  }
};

rcube_webmail.prototype.files_set_quota = function(p)
{
  if (p.total && window.file_api) {
    p.used *= 1024;
    p.total *= 1024;
    p.title = file_api.file_size(p.used) + ' / ' + file_api.file_size(p.total)
        + ' (' + p.percent + '%)';
  }

  p.type = this.env.quota_type;

  this.set_quota(p);
};

rcube_webmail.prototype.files_create = function()
{
  kolab_files_file_create_dialog();
};

rcube_webmail.prototype.files_rename = function()
{
  var files = kolab_files_selected();
  kolab_files_file_rename_dialog(files[0]);
};

rcube_webmail.prototype.folder_create = function()
{
  kolab_files_folder_create_dialog();
};

rcube_webmail.prototype.folder_rename = function(prop, elem, event)
{
  this.hide_menu('folderoptions', event);
  kolab_files_folder_edit_dialog();
};

rcube_webmail.prototype.folder_share = function(prop, elem, event)
{
  this.hide_menu('folderoptions', event);
  kolab_files_folder_share_dialog();
};

rcube_webmail.prototype.folder_mount = function(prop, elem, event)
{
  this.hide_menu('folderoptions', event);
  kolab_files_folder_mount_dialog();
};

// open a session dialog
rcube_webmail.prototype.sessions_open = function()
{
  var id = this.sessionslist.get_selection(),
    session = id ? file_api.env.sessions_list[id] : null;

  if (session)
    kolab_files_session_dialog(session);
};


/**********************************************************/
/*********          Files API handler            **********/
/**********************************************************/

function kolab_files_ui()
{
  this.requests = {};
  this.uploads = [];
  this.list_updates = 0;

/*
  // Called on "session expired" session
  this.logout = function(response) {};

  // called when a request timed out
  this.request_timed_out = function() {};

  // called on start of the request
  this.set_request_time = function() {};

  // called on request response
  this.update_request_time = function() {};
*/
  // set state
  this.set_busy = function(a, message)
  {
    if (this.req)
      rcmail.hide_message(this.req);

    return rcmail.set_busy(a, message);
  };

  // displays error message
  this.display_message = function(label, type)
  {
    return rcmail.display_message(this.t(label), type);
  };

  this.http_error = function(request, status, err, data)
  {
    rcmail.http_error(request, status, err, data ? data.req_id : null);
  };

  // check if specified/current folder/view is writable
  this.is_writable = function(folder)
  {
    if (!folder)
      folder = this.env.folder;

    if (!folder)
      return false;

    var all_folders = $.extend({}, this.env.folders, this.search_results);

    if (!all_folders[folder] || all_folders[folder].readonly || all_folders[folder].virtual)
      return false;

    return true;
  };

  // Check if specified folder (hierarchy) supports sharing
  this.is_shareable = function(folder)
  {
    if (!folder)
      folder = this.env.folder;

    if (!folder)
      return false;

    var root = folder.split(this.env.directory_separator)[0],
      caps = this.env.caps;

    if (this.env.caps.MOUNTPOINTS[root])
      caps = root != folder ? this.env.caps.MOUNTPOINTS[root] : {};

    return !!caps.ACL;
  };

  // folders list request
  this.folder_list = function(params, update)
  {
    if (!params)
      params = {}

    params.permissions = 1;

    if (params.level === undefined)
        params.level = -1;

    if (update) {
      this.list_updates++;
      params.req = rcmail.display_message('', 'loading');
    }
    else {
      params.req = this.set_busy(true, 'loading');
      this.list_params = params;
    }

    this.request('folder_list', params, update ? 'folder_list_update_response' : 'folder_list_response');
  };

  // folder list response handler
  this.folder_list_response = function(response, params)
  {
    rcmail.hide_message(params.req);

    if (!this.response(response))
      return;

    var folder, first, body, rows = [],
      list_selector = rcmail.env.folder_list_selector || '#files-folder-list',
      search_selector = rcmail.env.folder_search_selector || '#foldersearch',
      elem = $(list_selector),
      searchbox = $(search_selector),
      list = $('<ul class="treelist listing folderlist"></ul>'),
      collections = ['audio', 'video', 'image', 'document'];

    // try parent window if the list element does not exist
    // i.e. called from a dialog in parent window
    if (!elem.length && rcmail.is_framed() && (rcmail.env.task != 'files' || (rcmail.env.action != 'open' && rcmail.env.action != 'edit'))) {
      body = window.parent.document.body;
      elem = $(list_selector, body);
      searchbox = $(search_selector, body);
    }

    this.list_element = list;

    if (elem.data('no-collections') == true)
      collections = [];

    this.env.folders = this.folder_list_parse(response.result && response.result.list ? response.result.list : response.result);

    rcmail.enable_command('files-create', response.result && response.result.list && response.result.list.length > 0);

    if (!elem.length)
      return;

    elem.html('');

    $.each(this.env.folders, function(i, f) {
      var row;
      if (row = file_api.folder_list_row(i, f)) {
        if (!first)
          first = i;
        rows.push(row);
      }
    });

    // add virtual collections
    $.each(collections, function(i, n) {
      var row = $('<li class="mailbox collection ' + n + '"></li>');

      row.attr('id', 'rcmli' + rcmail.html_identifier_encode('folder-collection-' + n))
        .append($('<a class="name"></a>').text(rcmail.gettext('kolab_files.collection_' + n)))

      rows.push(row);
    });

    // add Sessions entry
    if (rcmail.task == 'files' && !rcmail.env.action && this.env.caps && this.env.caps.DOCEDIT) {
      rows.push($('<li class="mailbox collection sessions"></li>')
        .attr('id', 'rcmli' + rcmail.html_identifier_encode('folder-collection-sessions'))
        .append($('<a class="name"></a>').text(rcmail.gettext('kolab_files.sessions')))
      );
    }

    list.append(rows).appendTo(elem)
      .on('click', 'a.subscription', function(e) {
        return file_api.folder_list_subscription_button_click(this);
      })
      .on('mouseover', 'a.name', function() {
        rcube_webmail.long_subject_title_ex(this);
      });

    if (rcmail.task == 'files' && rcmail.env.contextmenu)
      list.on('contextmenu', function(e) {
        var elem = $(e.target).closest('li');
          id = rcmail.html_identifier_decode(elem.attr('id').replace(/^rcmli/, ''));
        rcmail.contextmenu.show_one(e, elem[0], id, rcmail.env.folders_cm);
      });

    if (rcmail.folder_list) {
      rcmail.folder_list.reset();
      this.search_results_widget = null;
    }

    // init treelist widget
    rcmail.folder_list = new rcube_treelist_widget(list, {
        selectable: true,
        id_prefix: 'rcmli',
        parent_focus: true,
        searchbox: searchbox,
        id_encode: rcmail.html_identifier_encode,
        id_decode: rcmail.html_identifier_decode,
        check_droptarget: function(node) {
          return !node.virtual
            && node.id != file_api.env.folder
            && $.inArray('readonly', node.classes) == -1
            && $.inArray('collection', node.classes) == -1;
        }
    });

    rcmail.folder_list
      .addEventListener('collapse', function(node) { file_api.folder_collapsed(node); })
      .addEventListener('expand', function(node) { file_api.folder_collapsed(node); })
      .addEventListener('beforeselect', function(node) { return !rcmail.busy; })
      .addEventListener('search', function(search) { file_api.folder_search(search); })
      .addEventListener('select', function(node) {
        if (file_api.search_results_widget)
          file_api.search_results_widget.select();
        file_api.folder_select(node.id);
      });

    // select first/current folder
    if (response.result.auth_errors && response.result.auth_errors.length)
      this.env.folder = this.env.collection = null;
    else if (this.env.folder)
      rcmail.folder_list.select(this.env.folder);
    else if (this.env.collection)
      rcmail.folder_list.select('folder-collection-' + this.env.collection);
    else if (folder = this.env.init_folder) {
      if (this.env.folders[folder]) {
        this.env.init_folder = null;
        rcmail.folder_list.select(folder);
      }
    }
    else if (folder = this.env.init_collection) {
      this.env.init_collection = null;
      rcmail.folder_list.select('folder-collection-' + folder);
    }
    else if (first)
      rcmail.folder_list.select(first);

    // handle authentication errors on external sources
    this.folder_list_auth_errors(response.result);

    // Fetch 2 levels of folder hierarchy for all mount points that
    // do not support fast folders list
    if (rcmail.env.files_api_version > 4) {
      var ref = this;
      $.each(this.env.caps.MOUNTPOINTS || [], function(k, v) {
        if (!v.FAST_FOLDER_LIST)
          ref.folder_list({level: 2, folder: k}, true);
      });
    }

    // Elastic: Set notree class on the folder list
    var callback = function() {
        list[list.find('.treetoggle').length > 0 ? 'removeClass' : 'addClass']('notree');
    };
    if (window.MutationObserver)
      (new MutationObserver(callback)).observe(list.get(0), {childList: true, subtree: true});
    callback();
  };

  // folder list response handler
  this.folder_list_update_response = function(response, params)
  {
    rcmail.hide_message(params.req);

    this.list_updates--;

    if (!this.response(response))
      return;

    // handle authentication errors on external sources
    this.folder_list_auth_errors(response.result);

    // Update the list
    this.folder_list_merge(params.folder, response.result.list, params.level);
  };

  this.folder_list_update_wait = function(folder)
  {
    var ref = this;

    // do maximum 10 parallel requests
    if (this.list_updates > 10)
      return setTimeout(function() { ref.folder_list_update_wait(folder); }, 20);

    this.folder_list({folder: folder, level: 0}, true);
  };

  this.folder_select = function(folder)
  {
    if (rcmail.busy || !folder)
      return;

    rcmail.triggerEvent('files-folder-select', {folder: folder});

    var is_collection = folder.match(/^folder-collection-(.*)$/),
      collection = RegExp.$1 || null;

    if (rcmail.task == 'files' && !rcmail.env.action)
      rcmail.update_state(is_collection ? {collection: collection} : {folder: folder});

    if (collection == 'sessions') {
      rcmail.enable_command('files-list', 'files-folder-delete', 'folder-rename', 'files-upload', 'files-open', 'files-edit', false);
      this.sessions_list();
      return;
    }

    if (is_collection)
      folder = null;

    // search-reset can re-select the same folder, skip
    if (this.env.folder == folder && this.env.collection == collection)
      return;

    this.env.folder = folder;
    this.env.collection = collection;

    rcmail.enable_command('files-list', true);
    rcmail.enable_command('files-folder-delete', 'folder-rename', !is_collection);
    rcmail.enable_command('files-upload', !is_collection && this.is_writable());
    rcmail.enable_command('folder-share', !is_collection && this.is_shareable());
    rcmail.command('files-list', is_collection ? {collection: collection} : {folder: folder});

    this.quota();
  };

  this.folder_unselect = function()
  {
    rcmail.folder_list.select();
    this.env.folder = null;
    this.env.collection = null;
    rcmail.enable_command('files-folder-delete', 'files-upload', false);
  };

  this.folder_collapsed = function(node)
  {
    var prefname = 'kolab_files_collapsed_folders',
      old = rcmail.env[prefname],
      entry = '&' + urlencode(node.id) + '&';

    if (node.collapsed) {
      rcmail.env[prefname] = rcmail.env[prefname] + entry;

      // select the folder if one of its childs is currently selected
      // don't select if it's virtual (#1488346)
      if (!node.virtual && this.env.folder && this.env.folder.startsWith(node.id + '/')) {
        rcmail.folder_list.select(node.id);
      }
    }
    else {
      rcmail.env[prefname] = rcmail.env[prefname].replace(entry, '');
    }

    if (old !== rcmail.env[prefname] && (!rcmail.fileslist || !rcmail.fileslist.drag_active))
      rcmail.command('save-pref', {name: prefname, value: rcmail.env[prefname]});
  };

  this.folder_list_row = function(i, folder, parent)
  {
    var toggle, sublist, collapsed, parent, parent_name, classes = ['mailbox'],
      row = $('<li>'),
      id = 'rcmli' + rcmail.html_identifier_encode(i),
      content = $('<div>').append($('<a class="name">').text(folder.name));

    if (folder.virtual)
      classes.push('virtual');
    else {
      if (folder.subscribed !== undefined)
        content.append(this.folder_list_subscription_button(folder.subscribed));

      if (folder.readonly)
        classes.push('readonly');
    }

    folder.ref = row.attr({id: id, 'class': classes.join(' ')}).append(content);

    if (folder.depth) {
      // find parent folder
      parent_name = i.replace(/\/[^/]+$/, '');

      if (!parent)
        parent = $(this.env.folders[parent_name].ref);

      toggle = $('div.treetoggle', parent);
      sublist = $('> ul', parent);

      if (!toggle.length) {
        collapsed = rcmail.env.kolab_files_collapsed_folders.indexOf('&' + urlencode(parent_name) + '&') > -1;

        toggle = $('<div>').attr('class', 'treetoggle' + (collapsed ? ' collapsed' : ' expanded'))
          .html('&nbsp;').appendTo(parent);

        sublist = $('<ul>').attr({role: 'group'}).appendTo(parent);
        if (collapsed)
          sublist.hide();
      }

      sublist.append(row);
    }
    else {
      return row;
    }
  };

  // create subscription button element
  this.folder_list_subscription_button = function(subscribed)
  {
    return $('<a>').attr({
        title: rcmail.gettext('kolab_files.listpermanent'),
        'class': 'subscription' + (subscribed ? ' subscribed' : ''),
        'aria-checked': subscribed,
        role: 'checkbox'
    });
  };

  // subscription button handler
  this.folder_list_subscription_button_click = function(elem)
  {
    var folder = $(elem).parents('li:first').prop('id').replace(/^rcmli/, ''),
      selected = $(elem).hasClass('subscribed');

    folder = folder.replace(/--xsR$/, ''); // this might be a search result
    folder = rcmail.html_identifier_decode(folder);
    file_api['folder_' + (selected ? 'unsubscribe' : 'subscribe')](folder);
    return false;
  };

  // sets subscription button status
  this.folder_list_subscription_state = function(elem, status)
  {
    $(elem).find('a.subscription:first')
      .prop('aria-checked', status)[status ? 'addClass' : 'removeClass']('subscribed');
  };

  // Folder searching handler (for unsubscribed folders)
  this.folder_search = function(search)
  {
    // hide search results
    if (this.search_results_widget) {
      this.search_results_container.hide();
      this.search_results_widget.reset();
    }
    this.search_results = {};

    // send search request to the server
    if (search.query && search.execute) {
      // cancel previous search request
      if (this.listsearch_request) {
        this.listsearch_request.abort();
        this.listsearch_request = null;
      }

      var params = $.extend({search: search.query, unsubscribed: 1}, this.list_params);

      this.req = this.set_busy(true, rcmail.gettext('searching'));
      this.listsearch_request = this.request('folder_list', params, 'folder_search_response');
    }
    else if (!search.query) {
      if (this.listsearch_request) {
        this.listsearch_request.abort();
        this.listsearch_request = null;
      }

      // any subscription changed, make sure the newly added records
      // are listed before collections not after
      if (this.folder_subscribe) {
        var r, last, move = [], rows = $(rcmail.folder_list.container).children('li');

        if (rows.length && !$(rows[rows.length-1]).hasClass('collection')) {
          // collect all folders to move
          while (rows.length--) {
            r = $(rows[rows.length]);
            if (r.hasClass('collection'))
              last = r;
            else if (last)
              break;
            else
              move.push(r);
          }

          if (last)
            $.each(move, function() {
              this.remove();
              last.before(this);
            });
        }
      }
    }
  };

  // folder search response handler
  this.folder_search_response = function(response)
  {
    if (!this.response(response))
      return;

    var folders = response.result && response.result.list ? response.result.list : response.result;

    if (!folders.length)
      return;

    folders = this.folder_list_parse(folders, 10000, false);

    if (!this.search_results_widget) {
      var list = rcmail.folder_list.container,
        title = rcmail.gettext('kolab_files.additionalfolders'),
        list_id = list.attr('id') || '0';

      this.search_results_container = $('<div class="searchresults"></div>')
          .append($('<h2 class="boxtitle" id="st:' + list_id + '"></h2>').text(title))
          .insertAfter(list);

      this.search_results_widget = new rcube_treelist_widget('<ul>', {
          id_prefix: 'rcmli',
          id_encode: rcmail.html_identifier_encode,
          id_decode: rcmail.html_identifier_decode,
          selectable: true
      });

      this.search_results_widget
        .addEventListener('beforeselect', function(node) { return !rcmail.busy; })
        .addEventListener('select', function(node) {
          rcmail.folder_list.select();
          file_api.folder_select(node.id);
        });

      this.search_results_widget.container
        // copy classes from main list
        .addClass(list.attr('class')).attr('aria-labelledby', 'st:' + list_id)
        .appendTo(this.search_results_container)
        .on('click', 'a.subscription', function(e) {
          return file_api.folder_list_subscription_button_click(this);
        });
    }

    // add results to the list
    $.each(folders, function(i, folder) {
      var node, separator = file_api.env.directory_separator,
        path = i.split(separator),
        classes = ['mailbox'],
        html = [$('<a>').text(folder.name)];

      if (!folder.virtual) {
        // add subscription button
        html.push(file_api.folder_list_subscription_button(false));

        if (folder.readonly)
          classes.push('readonly');
      }

      path.pop();

      file_api.search_results_widget.insert({
          id: i,
          classes: classes,
          text: folder.name,
          html: $('<div>').append(html),
          collapsed: false,
          virtual: folder.virtual
        }, path.length ? path.join(separator) : null);
    });

    this.search_results = folders;
    this.search_results_container.show();
  };

  // folder subscribe request
  this.folder_subscribe = function(folder)
  {
    this.env.folder_subscribe = folder;
    this.req = this.set_busy(true, 'foldersubscribing');
    this.request('folder_subscribe', {folder: folder}, 'folder_subscribe_response');
  }

  // folder subscribe response handler
  this.folder_subscribe_response = function(response)
  {
    if (!this.response(response))
      return;

    this.display_message('foldersubscribed', 'confirmation');

    var item, node = rcmail.folder_list.get_item(this.env.folder_subscribe);

    if (this.search_results && this.search_results[this.env.folder_subscribe]) {
      item = this.search_results_widget.get_item(this.env.folder_subscribe);
      this.folder_list_subscription_state(item, true);
      if (item = $(item).attr('id'))
        this.folder_list_subscription_state($('#' + item.replace(/--xsR$/, '')), true);
    }

    // search result, move from search to main list widget
    if (!node && this.search_results && this.search_results[this.env.folder_subscribe]) {
      var i, html, dir, folder, separator = this.env.directory_separator,
        path = this.env.folder_subscribe.split(separator);

      // add all folders in a path to the main list if needed
      // including the subscribed folder
      for (i=0; i<path.length; i++) {
        dir = path.slice(0, i + 1).join(separator);
        node = rcmail.folder_list.get_node(dir);

        if (!node) {
          node = this.search_results_widget.get_node(dir);
          if (!node) {
            // sanity check
            return;
          }

          if (i == path.length - 1) {
            item = this.search_results_widget.get_item(dir);
            this.folder_list_subscription_state(item, true);
          }

          folder = this.search_results[dir];
          html = [$('<a>').text(folder.name)];
          if (!folder.virtual)
            html.push(this.folder_list_subscription_button(true));

          node.html = $('<div>').append(html);
          delete node.children;

          rcmail.folder_list.insert(node, i > 0 ? path.slice(0, i).join(separator) : null);
          // we're in search result, so there will be two records,
          // add subscription button to the visible one, it was not cloned
          if (!folder.virtual) {
            node = rcmail.folder_list.get_item(dir);
            $(node).append(file_api.folder_list_subscription_button(true));
          }

          this.env.folders[dir] = folder;
        }
      }

      // now remove them from the search widget
      while (path.length) {
        dir = path.join(separator);
        node = this.search_results_widget.get_item(dir);

        if ($('ul[role="group"] > li', node).length)
          break;

        this.search_results_widget.remove(dir);

        path.pop();
      }

      node = null;
    }

    if (node)
      this.folder_list_subscription_state(node, true);

    this.env.folders[this.env.folder_subscribe].subscribed = true;
  };

  // folder unsubscribe request
  this.folder_unsubscribe = function(folder)
  {
    this.env.folder_subscribe = folder;
    this.req = this.set_busy(true, 'folderunsubscribing');
    this.request('folder_unsubscribe', {folder: folder}, 'folder_unsubscribe_response');
  }

  // folder unsubscribe response handler
  this.folder_unsubscribe_response = function(response)
  {
    if (!this.response(response))
      return;

    this.display_message('folderunsubscribed', 'confirmation');

    var folder = this.env.folders[this.env.folder_subscribe],
      node = rcmail.folder_list.get_item(this.env.folder_subscribe);

    if (this.search_results && this.search_results[this.env.folder_subscribe]) {
      item = this.search_results_widget.get_item(this.env.folder_subscribe);

      if (item) {
        this.folder_list_subscription_state(item, false);
        item = $('#' + $(item).attr('id').replace(/--xsR$/, ''));
      }
      else
        item = $('#rcmli' + rcmail.html_identifier_encode(this.env.folder_subscribe), rcmail.folder_list.container);

      this.folder_list_subscription_state(item, false);
    }

    this.folder_list_subscription_state(node, false);

    folder.subscribed = false;
  };

  // folder create request
  this.folder_create = function(folder)
  {
    this.req = this.set_busy(true, 'kolab_files.foldercreating');
    this.request('folder_create', {folder: folder}, 'folder_create_response');
  };

  // folder create response handler
  this.folder_create_response = function(response)
  {
    if (!this.response(response))
      return;

    this.display_message('kolab_files.foldercreatenotice', 'confirmation');

    // refresh folders list
    // TODO: Don't reload the whole list
    this.folder_list();
  };

  // folder rename request
  this.folder_rename = function(folder, new_name)
  {
    if (folder == new_name)
      return;

    this.req = this.set_busy(true, 'kolab_files.folderupdating');
    this.request('folder_move', {folder: folder, 'new': new_name}, 'folder_rename_response');
  };

  // folder create response handler
  this.folder_rename_response = function(response, params)
  {
    if (!this.response(response))
      return;

    this.display_message('kolab_files.folderupdatenotice', 'confirmation');

    // refresh folders and files list
    if (this.env.folder == params.folder)
      this.env.folder = params['new'];

    // Removed mount point, refresh capabilities stored in session
    if (this.env.caps.MOUNTPOINTS[params.folder]) {
      this.env.caps.MOUNTPOINTS[params['new']] = this.env.caps.MOUNTPOINTS[params.folder];
      delete this.env.caps.MOUNTPOINTS[params.folder];
      rcmail.http_post('files/reset', {});
    }

    // TODO: Don't reload the whole list
    this.folder_list();
  };

  // folder mount (external storage) request
  this.folder_mount = function(data)
  {
    this.req = this.set_busy(true, 'kolab_files.foldermounting');
    this.request('folder_create', data, 'folder_mount_response');
  };

  // folder create response handler
  this.folder_mount_response = function(response, params)
  {
    if (!this.response(response))
      return;

    this.display_message('kolab_files.foldermountnotice', 'confirmation');

    if (response.result.capabilities) {
      // we make sure the result is an object not array
      // when the list is empty it is an array, because of how works JSON encoding from PHP
      var add = {};
      add[params.folder] = response.result.capabilities;
      this.env.caps.MOUNTPOINTS = $.extend({}, this.env.caps.MOUNTPOINTS, add);
    }

    // Refresh capabilities stored in session
    rcmail.http_post('files/reset', {});

    // refresh folders list
    // TODO: load only folders from the created mount point
    this.folder_list();
  };

  // folder delete request
  this.folder_delete = function(folder)
  {
    this.req = this.set_busy(true, 'kolab_files.folderdeleting');
    this.request('folder_delete', {folder: folder}, 'folder_delete_response');
  };

  // folder delete response handler
  this.folder_delete_response = function(response, params)
  {
    if (!this.response(response))
      return;

    this.display_message('kolab_files.folderdeletenotice', 'confirmation');

    if (this.env.folder == params.folder) {
      this.env.folder = null;
      rcmail.enable_command('files-folder-delete', 'folder-rename', 'files-list', false);
    }

    // Removed mount point, refresh capabilities stored in session
    if (this.env.caps.MOUNTPOINTS[params.folder]) {
      delete this.env.caps.MOUNTPOINTS[params.folder];
      rcmail.http_post('files/reset', {});
    }

    // refresh folders list
    this.folder_list();
    this.quota();
  };

  this.sharing_submit = function(folder, row, mode)
  {
    var post = this.sharing_data(row, {action: 'submit', folder: folder, mode: mode});

    if (post === false)
      return;

    this.sharing_submit_post = post;
    this.sharing_submit_row = row;
    this.req = this.set_busy(true, 'kolab_files.updatingfolder' + mode);
    this.post('sharing', post, 'sharing_submit_response');
  };

  this.sharing_submit_response = function(response)
  {
    if (!this.response(response))
      return;

    // reset inputs
    $(this.sharing_submit_row).find('input[type=text]').val('');

    var hidden = [],
      post = $.extend({}, this.sharing_submit_post, response.result || {}),
      form_info = rcmail.env.form_info[post.mode],
      table = $(this.sharing_submit_row).closest('table'),
      row = $('<tr>'),
      btn = $('<button type="button" class="btn btn-secondary btn-danger delete">')
        .text(rcmail.gettext('delete'))
        .on('click', function() { file_api.sharing_delete(post.folder, $(this).closest('tr'), post.mode); });

    if (form_info.list_column) {
      row.append($('<td>').append($('<span class="name">')
        .text(response.result.display || post[form_info.list_column])
        .attr('title', response.result.title))
      );
    }
    else {
      $.each(form_info.form || [], function(i, v) {
        var content, opts = [];

        if (v.type == 'select') {
          content = $('<select>').attr('name', i)
            .on('change', function() { file_api.sharing_update(post.folder, $(this).closest('tr'), post.mode); });
          $.each(v.options, function(i, v) {
            opts.push($('<option>').attr('value', i).text(v));
          });

          content.append(opts).val(post[i]);
        }
        else {
          content = $('<span class="name">').text(response.result.display || post[i]).attr('title', response.result.title);
          hidden.push($('<input>').attr({type: 'hidden', name: i, value: post[i] || ''}));
        }

        row.append($('<td>').append(content));
      });
    }

    $.each(form_info.extra_fields || [], function(i, v) {
      hidden.push($('<input>').attr({type: 'hidden', name: i, value: post[i] || ''}));
    });

    row.append($('<td>').append(btn).append(hidden));

    $(this.sharing_submit_row).parent().append(row);

    if (window.UI && UI.pretty_select)
      row.find('select').each(function() { UI.pretty_select(this); }); // for Elastic

    if (table.data('single')) {
      $('tbody > tr:first', table).find('button,input,select').prop('disabled', true);
    }

    $('tbody > tr:first', table).find('input[type=text],input[type=password]').val('');
  };

  this.sharing_update = function(folder, row, mode)
  {
    var post = this.sharing_data(row, {action: 'update', folder: folder, mode: mode});

    this.req = this.set_busy(true, 'kolab_files.updatingfolder' + mode);
    this.post('sharing', post, 'sharing_update_response');
  };

  this.sharing_update_response = function(response)
  {
    if (!this.response(response))
      return;

    // todo: on error reset fields?
  };

  this.sharing_delete = function(folder, row, mode)
  {
    var post = this.sharing_data(row, {action: 'delete', folder: folder, mode: mode});

    this.sharing_delete_row = row;
    this.req = this.set_busy(true, 'kolab_files.updatingfolder' + mode);
    this.post('sharing', post, 'sharing_delete_response');
  };

  this.sharing_delete_response = function(response)
  {
    if (!this.response(response))
      return;

    var table = $(this.sharing_delete_row).closest('table');

    if (table.data('single')) {
      $('tbody > tr:first', table).find('button,input,select').prop('disabled', false);
    }

    $(this.sharing_delete_row).remove();
  };

  this.sharing_data = function(row, data)
  {
    var error;

    $('select,input', row).each(function() {
      if (this.type == 'password' && !this.name.match(/confirm$/) && this.value != $('input[name=' + this.name + 'confirm]', row).val())
        error = rcmail.display_message('kolab_files.passwordconflict', 'error');

      data[this.name] = $(this).val();
    });

    return error ? false : data;
  };

  // quota request
  this.quota = function()
  {
    if (rcmail.env.files_quota && (this.env.folder || !this.env.caps.NOROOT))
      this.request('quota', {folder: this.env.folder}, 'quota_response');
  };

  // quota response handler
  this.quota_response = function(response)
  {
    if (!this.response(response))
      return;

    rcmail.files_set_quota(response.result);
  };

  // List of sessions
  this.sessions_list = function(params)
  {
    if (!rcmail.gui_objects.sessionslist)
      return;

    this.file_list_abort(true);

    if (!params)
      params = {};

    if (rcmail.gui_objects.fileslist) {
      $(rcmail.gui_objects.fileslist).parent().hide();
      $(rcmail.gui_objects.sessionslist).parent().show();
      rcmail.fileslist.clear();
    }

    this.env.folder = null;
    this.env.collection = null;

    // empty the list
    this.env.sessions_list = {};
    rcmail.sessionslist.clear(true);

    rcmail.enable_command(rcmail.env.file_commands, false);
    rcmail.enable_command(rcmail.env.file_commands_all, false);

    params.req_id = this.set_busy(true, 'loading');
    this.requests[params.req_id] = this.request('sessions', params, 'sessions_list_response');
  };

  // file list response handler
  this.sessions_list_response = function(response)
  {
    if (response.req_id)
      rcmail.hide_message(response.req_id);

    if (!this.response(response))
      return;

    $.each(response.result, function(sess_id, data) {
      var row = file_api.session_list_row(sess_id, data);
      rcmail.sessionslist.insert_row(row);
      response.result[sess_id].row = row;
    });

    this.env.sessions_list = response.result;
    rcmail.sessionslist.resize();
  };

  // Files list
  this.file_list = function(params)
  {
    if (!rcmail.gui_objects.fileslist)
      return;

    this.file_list_abort(true);

    if (!params)
      params = {};

    if (params.all_folders) {
      params.collection = null;
      params.folder = null;
      this.folder_unselect();
    }

    if (params.collection == undefined)
      params.collection = this.env.collection;
    if (params.folder == undefined)
      params.folder = this.env.folder;
    if (params.sort == undefined)
      params.sort = this.env.files_sort_col;
    if (params.reverse == undefined)
      params.reverse = this.env.files_sort_reverse;
    if (params.search == undefined)
      params.search = this.env.search;

    this.env.folder = params.folder;
    this.env.collection = params.collection;
    this.env.files_sort_col = params.sort;
    this.env.files_sort_reverse = params.reverse;

    rcmail.enable_command(rcmail.env.file_commands, false);
    rcmail.enable_command(rcmail.env.file_commands_all, false);

    if (rcmail.gui_objects.sessionslist) {
      $(rcmail.gui_objects.sessionslist).parent().hide();
      $(rcmail.gui_objects.fileslist).parent().show();
      rcmail.sessionslist.clear(true)
    }

    // empty the list
    this.env.file_list = [];
    rcmail.fileslist.clear(true);
    rcmail.triggerEvent('listupdate', {list: rcmail.fileslist, rowcount: 0});

    // request
    if (params.collection || params.all_folders)
      this.file_list_loop(params);
    else if (this.env.folder) {
      params.req_id = this.env.file_list_req_id = this.set_busy(true, 'loading');
      this.requests[params.req_id] = this.request('file_list', params, 'file_list_response');
    }
  };

  // file list response handler
  this.file_list_response = function(response)
  {
    if (response.req_id)
      rcmail.hide_message(response.req_id);

    if (!this.response(response))
      return;

    var i = 0, list = [];

    $.each(response.result, function(key, data) {
      var row = file_api.file_list_row(key, data, ++i);
      rcmail.fileslist.insert_row(row);
      data.row = row;
      data.filename = key;
      list.push(data);
    });

    this.env.file_list = list;
    rcmail.fileslist.resize();
    rcmail.triggerEvent('listupdate', {list: rcmail.fileslist, rowcount: rcmail.fileslist.rowcount});

    // update document sessions info of this folder
    if (list && list.length)
      this.request('folder_info', {folder: this.file_path(list[0].filename), sessions: 1}, 'folder_info_response');
  };

  this.file_list_abort = function(all)
  {
    if (all) {
      clearTimeout(this.file_list_worker);
      this.file_list_worker = null;
    }

    // reset all pending list requests
    $.each(this.requests, function(i, v) {
      v.abort();
      rcmail.hide_message(i);
    });

    this.requests = {};

    rcmail.set_busy(false, null, this.env.file_list_req_id);
  };

  // call file_list request for every folder (used for search and virt. collections)
  this.file_list_loop = function(params)
  {
    var i, folders = [],
      limit = Math.max(this.env.search_threads || 1, 1),
      msg = rcmail.get_label('searching') + ' <a onclick="file_api.file_list_abort()">' + rcmail.get_label('kolab_files.abort') + '</a>';

    if (params.collection) {
      if (!params.search)
        params.search = {};
      params.search['class'] = params.collection;
      delete params['collection'];
    }

    delete params['all_folders'];

    $.each(this.env.folders, function(i, f) {
      if (!f.virtual)
        folders.push(i);
    });

    this.env.folders_loop = folders;
    this.env.folders_loop_lock = false;
    this.env.file_list_req_id = rcmail.display_message(msg, 'loading', 5 * 60 * 1000, 'files-file-search');
    rcmail.set_busy(true);

    for (i=0; i<folders.length && i<limit; i++) {
      params.req_id = new Date().getTime();
      params.folder = folders.shift();
      this.requests[params.req_id] = this.request('file_list', params, 'file_list_loop_response');
    }
  };

  // file list response handler for loop'ed request
  this.file_list_loop_response = function(response, params)
  {
    var folders = this.env.folders_loop,
      valid = this.response(response);

    if (folders.length) {
      params.req_id = new Date().getTime();
      params.folder = folders.shift();
      this.requests[params.req_id] = this.request('file_list', params, 'file_list_loop_response');
    }
    else {
      rcmail.set_busy(false, null, this.env.file_list_req_id);
    }

    // refresh sessions info in time intervals (one request for all folders)
    if (!this.file_list_worker && this.env.caps && this.env.caps.DOCEDIT && (rcmail.fileslist || rcmail.env.file))
      this.file_list_worker = setTimeout(function() {
        var params = {req_id: file_api.set_busy(true, 'loading')};
        file_api.request('sessions', params, 'file_list_sessions_response');
      }, 0);

    rcmail.fileslist.resize();

    if (!valid)
      return;

    this.file_list_loop_result_add(response.result);
  };

  // Update sessions metadata for files list (in multifolder mode)
  this.file_list_sessions_response = function(response)
  {
    this.file_list_worker = setTimeout(function() {
      var params = {req_id: file_api.set_busy(true, 'loading')};
      file_api.request('sessions', params, 'file_list_sessions_response');
    }, (rcmail.env.files_interval || 60) * 1000);

    if (response.req_id)
      rcmail.hide_message(response.req_id);

    if (!this.response(response))
      return;

    this.sessions = [];

    $.each(response.result || [], function(sess_id, data) {
      var folder = file_api.file_path(data.file);
      if (!file_api.sessions[folder])
        file_api.sessions[folder] = {};
      file_api.sessions[folder][sess_id] = data;
    });

    // update files list with document session info
    $.each(file_api.env.file_list || [], function(i, file) {
      var folder = file_api.file_path(file.filename);
      file_api.file_session_data_set(file, file_api.sessions[folder]);
    });
  };

  // add files from list request to the table (with sorting)
  this.file_list_loop_result_add = function(result)
  {
    // chack if result (hash-array) is empty
    if (!object_is_empty(result))
      return;

    if (this.env.folders_loop_lock) {
      setTimeout(function() { file_api.file_list_loop_result_add(result); }, 100);
      return;
    }

    // lock table, other list responses will wait
    this.env.folders_loop_lock = true;

    var n, i, len, elem, folder, list = [],
      index = this.env.file_list.length,
      table = rcmail.fileslist,
      fn = function(result, key, before) {
        var row = file_api.file_list_row(key, result[key], ++index);
        table.insert_row(row, before);
        result[key].row = row;
        result[key].filename = key;
        list.push(result[key]);

        if (!folder)
          folder = file_api.file_path(key);
      };

    for (n=0, len=index; n<len; n++) {
      elem = this.env.file_list[n];
      for (i in result) {
        if (this.sort_compare(elem, result[i]) < 0)
          break;

        fn(result, i, elem.row);
        delete result[i];
      }

      list.push(elem);
    }

    // add the rest of rows
    for (i in result) fn(result, i);

    this.env.file_list = list;

    // update files list with document session info
    if (folder && this.sessions[folder])
      $.each(list, function(i, file) {
        if (folder == file_api.file_path(file.filename))
          file_api.file_session_data_set(file, file_api.sessions[folder]);
      });

    this.env.folders_loop_lock = false;
  };

  // sort files list (without API request)
  this.file_list_sort = function(col, reverse)
  {
    var n, len, list = this.env.file_list,
      table = $('#filelist'), tbody = $('<tbody>', table);

    this.env.sort_col = col;
    this.env.sort_reverse = reverse;

    if (!list || !list.length)
      return;

    // sort the list
    list.sort(function (a, b) {
      return file_api.sort_compare(a, b);
    });

    // add rows to the new body
    for (n=0, len=list.length; n<len; n++) {
      tbody.append(list[n].row);
    }

    // replace table bodies
    $('tbody', table).replaceWith(tbody);
  };

  // Files list record
  this.file_list_row = function(file, data, index)
  {
    var c, col, row = '';

    for (c in rcmail.env.files_coltypes) {
      c = rcmail.env.files_coltypes[c];
      if (c == 'name')
        col = '<td class="name filename ' + this.file_type_class(data.type) + '">'
          + '<span>' + escapeHTML(data.name) + '</span></td>';
      else if (c == 'mtime')
        col = '<td class="mtime">' + data.mtime + '</td>';
      else if (c == 'size')
        col = '<td class="size">' + this.file_size(data.size) + '</td>';
      else if (c == 'options')
        col = '<td class="options"><span></span></td>';
      else
        col = '<td class="' + c + '"></td>';

      row += col;
    }

    row = $('<tr>')
      .html(row)
      .attr({id: 'rcmrow' + index, 'data-file': file, 'data-type': data.type});

    // collection (or search) lists files from all folders
    // display file name with full path as title
    if (!this.env.folder)
      $('td.name span', row).attr('title', file);

    return row.get(0);
  };

  // Sessions list record
  this.session_list_row = function(id, data)
  {
    var c, col, row = '', classes = ['session'];

    for (c in rcmail.env.sessions_coltypes) {
      c = rcmail.env.sessions_coltypes[c];
      if (c == 'name')
        col = '<td class="name filename ' + this.file_type_class(data.type) + '">'
          + '<span>' + escapeHTML(file_api.file_name(data.file)) + '</span></td>';
      else if (c == 'owner')
        col = '<td class="owner">' + escapeHTML(data.owner_name || data.owner) + '</td>';
      else if (c == 'options')
        col = '<td class="options"><span></span></td>';

      row += col;
    }

    if (data.is_owner)
      classes.push('owner');
    else if (data.is_invited)
      classes.push('invited');

    row = $('<tr>')
      .html(row)
      .attr({id: 'rcmrow' + id, 'class': classes.join(' ')});

    return row.get(0);
  };

  this.file_search = function(value, all_folders)
  {
    if (value) {
      this.env.search = {name: value};
      rcmail.command('files-list', {search: this.env.search, all_folders: all_folders});
    }
    else
      this.search_reset();
  };

  this.file_search_reset = function()
  {
    if (this.env.search) {
      this.env.search = null;
      rcmail.command('files-list');
    }
  };

  // handler for folder info response
  this.folder_info_response = function(response)
  {
    if (!this.response(response) || !response.result)
      return;

    var folder = response.result.folder,
      prefix = folder + file_api.env.directory_separator;

    if (response.result.sessions)
      this.sessions[folder] = response.result.sessions;

    // update files list with document session info
    $.each(file_api.env.file_list || [], function(i, file) {
      // skip files from a different folder (in multi-folder listing)
      if (file.filename.indexOf(prefix) >= 0)
        file_api.file_session_data_set(file, response.result.sessions)
    });

    // refresh sessions info in time intervals
    if (this.env.caps && this.env.caps.DOCEDIT && (rcmail.fileslist || rcmail.env.file))
      this.file_list_worker = setTimeout(function() {
        file_api.request('folder_info', {folder: folder, sessions: 1}, 'folder_info_response');
      }, (rcmail.env.files_interval || 60) * 1000);
  };

  // Set html classes on a file list entry according to defined document sessions
  this.file_session_data_set = function(file, sessions)
  {
    var classes = [], old_class = file.row.className;

    if ($(file.row).is('.selected'))
      classes.push('selected');

    $.each(sessions || [], function(session_id, session) {
      if (file.filename == session.file) {
        if ($.inArray('session', classes) < 0)
          classes.push('session');

        if (session.is_owner && $.inArray('owner', classes) < 0)
          classes.push('owner');
        else if (session.is_invited && $.inArray('invited', classes) < 0)
          classes.push('invited');
      }
    });

    if (classes.length || old_class.length)
      $(file.row).attr('class', classes.join(' '));
  };

  this.file_get = function(file, params)
  {
    if (!params)
      params = {};

    params.token = this.env.token;
    params.file = file;

    rcmail.redirect(this.env.url + this.url('file_get', params), false);
  };

  // file(s) delete request
  this.file_delete = function(files)
  {
    this.req = this.set_busy(true, 'kolab_files.filedeleting');
    this.request('file_delete', {file: files}, 'file_delete_response');
  };

  // file(s) delete response handler
  this.file_delete_response = function(response)
  {
    if (!this.response(response))
      return;

    var rco, dir, self = this;

    this.display_message('kolab_files.filedeletenotice', 'confirmation');

    if (rcmail.env.file) {
      rco = rcmail.opener();
      dir = this.file_path(rcmail.env.file);

      // check if opener window contains files list, if not we can just close current window
      if (rco && rco.fileslist && (opener.file_api.env.folder == dir || !opener.file_api.env.folder))
        self = opener.file_api;
      // also try parent for framed UI (Elastic)
      else if (rcmail.is_framed() && parent.rcmail.fileslist && (parent.file_api.env.folder == dir || !parent.file_api.env.folder))
        self = parent.file_api;
    }

    // @TODO: consider list modification "in-place" instead of full reload
    self.file_list();
    self.quota();

    if (rcmail.env.file) {
      if (rcmail.is_framed()) {
        parent.$('.ui-dialog:visible > .ui-dialog-buttonpane button.cancel').click();
      }
      else
        window.close();
    }
  };

  // file(s) move request
  this.file_move = function(files, folder)
  {
    if (!files || !files.length || !folder)
      return;

    var count = 0, list = {};

    $.each(files, function(i, v) {
      var name = folder + file_api.env.directory_separator + file_api.file_name(v);

      if (name != v) {
        list[v] = name;
        count++;
      }
    });

    if (!count)
      return;

    this.req = this.set_busy(true, 'kolab_files.filemoving');
    this.request('file_move', {file: list}, 'file_move_response');
  };

  // file(s) move response handler
  this.file_move_response = function(response)
  {
    if (!this.response(response))
      return;

    if (response.result && response.result.already_exist && response.result.already_exist.length)
      this.file_move_ask_user(response.result.already_exist, true);
    else {
      this.display_message('kolab_files.filemovenotice', 'confirmation');
      this.file_list();
    }
  };

  // file(s) copy request
  this.file_copy = function(files, folder)
  {
    if (!files || !files.length || !folder)
      return;

    var count = 0, list = {};

    $.each(files, function(i, v) {
      var name = folder + file_api.env.directory_separator + file_api.file_name(v);

      if (name != v) {
        list[v] = name;
        count++;
      }
    });

    if (!count)
      return;

    this.req = this.set_busy(true, 'kolab_files.filecopying');
    this.request('file_copy', {file: list}, 'file_copy_response');
  };

  // file(s) copy response handler
  this.file_copy_response = function(response)
  {
    if (!this.response(response))
      return;

    if (response.result && response.result.already_exist && response.result.already_exist.length)
      this.file_move_ask_user(response.result.already_exist);
    else {
      this.display_message('kolab_files.filecopynotice', 'confirmation');
      this.quota();
    }
  };

  // when file move/copy operation returns file-exists error
  // this displays a dialog where user can decide to skip
  // or overwrite destination file(s)
  this.file_move_ask_user = function(list, move)
  {
    var file = list[0], buttons = {}, button_classes = ['mainaction save file overwrite'],
      text = rcmail.gettext('kolab_files.filemoveconfirm').replace('$file', file.dst),
      dialog = $('<div></div>');

    buttons[rcmail.gettext('kolab_files.fileoverwrite')] = function() {
      var file = list.shift(), f = {},
        action = move ? 'file_move' : 'file_copy';

      f[file.src] = file.dst;
      file_api.file_move_ask_list = list;
      file_api.file_move_ask_mode = move;
      kolab_dialog_close(this, true);

      file_api.req = file_api.set_busy(true, move ? 'kolab_files.filemoving' : 'kolab_files.filecopying');
      file_api.request(action, {file: f, overwrite: 1}, 'file_move_ask_user_response');
    };

    if (list.length > 1) {
      button_classes.push('save file overwrite all');
      buttons[rcmail.gettext('kolab_files.fileoverwriteall')] = function() {
        var f = {}, action = move ? 'file_move' : 'file_copy';

        $.each(list, function() { f[this.src] = this.dst; });
        kolab_dialog_close(this, true);

        file_api.req = file_api.set_busy(true, move ? 'kolab_files.filemoving' : 'kolab_files.filecopying');
        file_api.request(action, {file: f, overwrite: 1}, action + '_response');
      };
    }

    var skip_func = function() {
      list.shift();
      kolab_dialog_close(this, true);

      if (list.length)
        file_api.file_move_ask_user(list, move);
      else if (move)
        file_api.file_list();
    };

    button_classes.push('cancel file skip');
    buttons[rcmail.gettext('kolab_files.fileskip')] = skip_func;

    if (list.length > 1) {
      button_classes.push('cancel file skip all');
      buttons[rcmail.gettext('kolab_files.fileskipall')] = function() {
      kolab_dialog_close(this, true);
        if (move)
          file_api.file_list();
      };
    }

    // open jquery UI dialog
    kolab_dialog_show(dialog.html(text), {
      title: rcmail.gettext('confirmationtitle'),
      close: skip_func,
      buttons: buttons,
      button_classes: button_classes,
      height: 50,
      minWidth: 400,
      width: 400
    });
  };

  // file move (with overwrite) response handler
  this.file_move_ask_user_response = function(response)
  {
    var move = this.file_move_ask_mode, list = this.file_move_ask_list;

    this.response(response);

    if (list && list.length)
      this.file_move_ask_user(list, mode);
    else {
      this.display_message('kolab_files.file' + (move ? 'move' : 'copy') + 'notice', 'confirmation');
      if (move)
        this.file_list();
    }
  };

  // file(s) create (or clone) request
  this.file_create = function(file, type, edit, cloneof)
  {
    this.file_create_edit_file = edit ? file : null;
    this.file_create_edit_type = edit ? type : null;
    this.file_create_folder = this.file_path(file);

    if (cloneof) {
      this.req = this.set_busy(true, 'kolab_files.filecopying');
      this.request('file_copy', {file: cloneof, 'new': file}, 'file_create_response');
    }
    else {
      this.req = this.set_busy(true, 'kolab_files.filecreating');
      this.request('file_create', {file: file, 'content-type': type, content: ''}, 'file_create_response');
    }
  };

  // file(s) create response handler
  this.file_create_response = function(response)
  {
    if (!this.response(response))
      return;

    // @TODO: we could update metadata instead
    if (this.file_create_folder == this.env.folder)
      this.file_list();

    // open the file for editing if editable
    if (this.file_create_edit_file) {
      var viewer = this.file_type_supported(this.file_create_edit_type, this.env.caps);
      this.file_open(this.file_create_edit_file, viewer, {action: 'edit'});
    }
  };

  // file(s) rename request
  this.file_rename = function(oldfile, newfile)
  {
    this.req = this.set_busy(true, 'kolab_files.fileupdating');
    this.request('file_move', {file: oldfile, 'new': newfile}, 'file_rename_response');
  };

  // file(s) move response handler
  this.file_rename_response = function(response)
  {
    if (!this.response(response))
      return;

    // @TODO: we could update metadata instead
    this.file_list();
  };

  // file upload request
  this.file_upload = function(form)
  {
    var form = $(form),
      field = $('input[type=file]', form).get(0),
      files = field.files ? field.files.length : field.value ? 1 : 0;

    if (!files || !this.file_upload_size_check(field.files))
      return;

    // submit form and read server response
    this.file_upload_form(form, 'file_upload', function(event) {
      var doc, response;
      try {
        doc = this.contentDocument ? this.contentDocument : this.contentWindow.document;
        response = doc.body.innerHTML;
        // response may be wrapped in <pre> tag
        if (response.slice(0, 5).toLowerCase() == '<pre>' && response.slice(-6).toLowerCase() == '</pre>') {
          response = doc.body.firstChild.firstChild.nodeValue;
        }
        response = eval('(' + response + ')');
      }
      catch (err) {
        response = {status: 'ERROR'};
      }

      file_api.file_upload_progress_stop(event.data.ts);

      // refresh the list on upload success
      file_api.file_upload_response(response);
    });
  };

  // refresh the list on upload success
  this.file_upload_response = function(response)
  {
    if (this.response_parse(response)) {
       this.file_list();
       this.quota();
    }
  };

  // check upload max size
  this.file_upload_size_check = function(files)
  {
    var i, size = 0, maxsize = rcmail.env.files_max_upload;

    if (maxsize && files) {
      for (i=0; i < files.length; i++)
        size += files[i].size || files[i].fileSize;

      if (size > maxsize) {
        rcmail.alert_dialog(rcmail.get_label('kolab_files.uploadsizeerror').replace('$size', kolab_files_file_size(maxsize)));
        return false;
      }
    }

    return true;
  };

  // post the given form to a hidden iframe
  this.file_upload_form = function(form, action, onload)
  {
    var ts = new Date().getTime(),
      frame_name = 'fileupload' + ts;

    // upload progress support
    if (rcmail.env.files_progress_name) {
      var fname = rcmail.env.files_progress_name,
        field = $('input[name='+fname+']', form);

      if (!field.length) {
        field = $('<input>').attr({type: 'hidden', name: fname});
        field.prependTo(form);
      }

      field.val(ts);
      this.file_upload_progress(ts, true);
    }

    rcmail.display_progress({name: ts});

    // have to do it this way for IE
    // otherwise the form will be posted to a new window
    if (document.all) {
      var html = '<iframe id="'+frame_name+'" name="'+frame_name+'"'
        + ' src="' + rcmail.assets_path('program/resources/blank.gif') + '"'
        + ' style="width:0;height:0;visibility:hidden;"></iframe>';
      document.body.insertAdjacentHTML('BeforeEnd', html);
    }
    // for standards-compliant browsers
    else
      $('<iframe>')
        .attr({name: frame_name, id: frame_name})
        .css({border: 'none', width: 0, height: 0, visibility: 'hidden'})
        .appendTo(document.body);

    // handle upload errors, parsing iframe content in onload
    $('#'+frame_name).on('load', {ts:ts}, onload);

    $(form).attr({
      target: frame_name,
      action: this.env.url + this.url(action, {folder: this.env.folder, token: this.env.token}),
      method: 'POST'
    }).attr(form.encoding ? 'encoding' : 'enctype', 'multipart/form-data')
      .submit();
  };

  // handler when files are dropped to a designated area.
  // compose a multipart form data and submit it to the server
  this.file_drop = function(e)
  {
    var files = e.target.files || e.dataTransfer.files;

    if (!files || !files.length || !this.file_upload_size_check(files))
      return;

    // prepare multipart form data composition
    var ts = new Date().getTime(),
      formdata = window.FormData ? new FormData() : null,
      fieldname = 'file[]',
      boundary = '------multipartformboundary' + (new Date).getTime(),
      dashdash = '--', crlf = '\r\n',
      multipart = dashdash + boundary + crlf;

    // inline function to submit the files to the server
    var submit_data = function() {
      var multiple = files.length > 1;

      rcmail.display_progress({name: ts});
      if (rcmail.env.files_progress_name)
        file_api.file_upload_progress(ts, true);

      // complete multipart content and post request
      multipart += dashdash + boundary + dashdash + crlf;

      $.ajax({
        type: 'POST',
        dataType: 'json',
        url: file_api.env.url + file_api.url('file_upload', {folder: file_api.env.folder}),
        contentType: formdata ? false : 'multipart/form-data; boundary=' + boundary,
        processData: false,
        timeout: 0, // disable default timeout set in ajaxSetup()
        data: formdata || multipart,
        headers: {'X-Session-Token': file_api.env.token},
        success: function(data) {
          file_api.file_upload_progress_stop(ts);
          file_api.file_upload_response(data);
        },
        error: function(o, status, err) {
          file_api.file_upload_progress_stop(ts);
          rcmail.http_error(o, status, err);
        },
        xhr: function() {
          var xhr = jQuery.ajaxSettings.xhr();
          if (!formdata && xhr.sendAsBinary)
            xhr.send = xhr.sendAsBinary;
          return xhr;
        }
      });
    };

    // upload progress supported (and handler exists)
    // add progress ID to the request - need to be added before files
    if (rcmail.env.files_progress_name) {
      if (formdata)
        formdata.append(rcmail.env.files_progress_name, ts);
      else
        multipart += 'Content-Disposition: form-data; name="' + rcmail.env.files_progress_name + '"'
          + crlf + crlf + ts + crlf + dashdash + boundary + crlf;
    }

    // get contents of all dropped files
    var f, j, i = 0, last = files.length - 1;
    for (j = 0; j <= last && (f = files[i]); i++) {
      if (!f.name) f.name = f.fileName;
      if (!f.size) f.size = f.fileSize;
      if (!f.type) f.type = 'application/octet-stream';

      // file name contains non-ASCII characters, do UTF8-binary string conversion.
      if (!formdata && /[^\x20-\x7E]/.test(f.name))
        f.name_bin = unescape(encodeURIComponent(f.name));

      // do it the easy way with FormData (FF 4+, Chrome 5+, Safari 5+)
      if (formdata) {
        formdata.append(fieldname, f);
        if (j == last)
          return submit_data();
      }
      // use FileReader supporetd by Firefox 3.6
      else if (window.FileReader) {
        var reader = new FileReader();

        // closure to pass file properties to async callback function
        reader.onload = (function(file, j) {
          return function(e) {
            multipart += 'Content-Disposition: form-data; name="' + fieldname + '"';
            multipart += '; filename="' + (f.name_bin || file.name) + '"' + crlf;
            multipart += 'Content-Length: ' + file.size + crlf;
            multipart += 'Content-Type: ' + file.type + crlf + crlf;
            multipart += reader.result + crlf;
            multipart += dashdash + boundary + crlf;

            if (j == last)  // we're done, submit the data
              return submit_data();
          }
        })(f,j);
        reader.readAsBinaryString(f);
      }

      j++;
    }
  };

  // upload progress requests
  this.file_upload_progress = function(id, init)
  {
    if (init && id)
      this.uploads[id] = this.env.folder;

    setTimeout(function() {
      if (id && file_api.uploads[id])
        file_api.request('upload_progress', {id: id}, 'file_upload_progress_response');
    }, rcmail.env.files_progress_time * 1000);
  };

  // upload progress response
  this.file_upload_progress_response = function(response)
  {
    if (!this.response(response))
      return;

    var param = response.result;

    if (!param.id || !this.uploads[param.id])
      return;

    if (param.total) {
      param.name = param.id;

      if (!param.done)
        param.text = kolab_files_progress_str(param);

      rcmail.display_progress(param);
    }

    if (!param.done && param.total)
      this.file_upload_progress(param.id);
    else
      delete this.uploads[param.id];
  };

  this.file_upload_progress_stop = function(id)
  {
    if (id) {
      delete this.uploads[id];
      rcmail.display_progress({name: id});
    }
  };

  // open file in new window, using file API viewer
  this.file_open = function(file, viewer, params)
  {
    var args = {
      _task: 'files',
      _action: params && params.action ? params.action : 'open',
      _file: file,
      _viewer: viewer || 0,
      _frame: 1
    };

    if (rcmail.is_framed())
      args._framed = 1;

    if (params && params.session)
      args._session = params.session;

    if (rcmail.env.extwin)
      args._extwin = 1;

    href = '?' + $.param(args);

    if (params && params.local)
      location.href = href;
    else
      rcmail.open_window(href, false, true);
  };

  // save file
  this.file_save = function(file, content)
  {
    rcmail.enable_command('files-save', false);
    // because we currently can edit only text files
    // and we do not expect them to be very big, we save
    // file in a very simple way, no upload progress, etc.
    this.req = this.set_busy(true, 'saving');
    this.request('file_update', {file: file, content: content, info: 1}, 'file_save_response');
  };

  // file save response handler
  this.file_save_response = function(response)
  {
    rcmail.enable_command('files-save', true);

    if (!this.response(response))
      return;

    // update file properties table
    var table = $('#fileinfobox table'), file = response.result;

    if (file) {
      $('td.filetype', table).text(file.type);
      $('td.filesize', table).text(this.file_size(file.size));
      $('td.filemtime', table).text(file.mtime);
    }
  };

  // document session delete request
  this.document_delete = function(id)
  {
    this.req = this.set_busy(true, 'kolab_files.sessionterminating');
    this.request('document_delete', {id: id}, 'document_delete_response');
  };

  // document session delete response handler
  this.document_delete_response = function(response, params)
  {
    if (!this.response(response))
      return;

    var list = rcmail.sessionslist;

    // remove session from the list (if sessions list exist)
    if (list)
      list.remove_row(params.id);

    if (this.env.sessions_list)
      delete this.env.sessions_list[params.id];

    // @todo: force sessions info update, update files list
  };

  // Invite document session participants
  this.document_invite = function(id, attendees, comment)
  {
    var list = [];

    // expect attendees to be email => name hash
    $.each(attendees || {}, function() { list.push(this); });

    if (list.length) {
      this.req = this.set_busy(true, 'kolab_files.documentinviting');
      this.request('document_invite', {id: id, users: list, comment: comment || ''}, 'document_invite_response');
    }
  };

  // document invite response handler
  this.document_invite_response = function(response)
  {
    if (!this.response(response) || !response.result)
      return;

    var info = rcmail.env.file_data,
      table = $('#document-editors-dialog table > tbody');

    $.each(response.result.list || {}, function() {
      var record = kolab_files_attendee_record(this.user, this.status, this.user_name);
      table.append(record);
      if (info.session && info.session.invitations)
        info.session.invitations.push($.extend({status: 'invited', record: record}, this));
    });
  };

  // Cancel invitations to an editing session
  this.document_cancel = function(id, attendees)
  {
    if (attendees.length) {
      this.req = this.set_busy(true, 'kolab_files.documentcancelling');
      this.request('document_cancel', {id: id, users: attendees}, 'document_cancel_response');
    }
  };

  // document_cancel response handler
  this.document_cancel_response = function(response)
  {
    if (!this.response(response) || !response.result)
      return;

    var info = rcmail.env.file_data;

    $.each(response.result.list || {}, function(i, user) {
      var invitations = [];
      $.each(info.session.invitations, function(i, u) {
        if (u.user == user && u.record)
          u.record.remove();
        else
          invitations.push(u);
      });
      info.session.invitations = invitations;
    });
  };

  // handle auth errors on folder list
  this.folder_list_auth_errors = function(result)
  {
    if (result && result.auth_errors) {
      if (!this.auth_errors)
        this.auth_errors = {};

      $.each(result.auth_errors || {}, function(i, v) {
        // for normal errors we display only an error message, other will display a dialog
        if (v.error) {
          if (v.error == 580) {
            rcmail.display_message(rcmail.gettext('kolab_files.storageautherror').replace('$title', i), 'error');
          }
          delete result.auth_errors[i];
        }
      });

      $.extend(this.auth_errors, result.auth_errors);
    }

    // ask for password to the first storage on the list
    var ref = this;
    $.each(this.auth_errors || [], function(i, v) {
      if (file_api.folder_list_auth_dialog(i, v))
        return false;
    });
  };

  // create dialog for user credentials of external storage
  this.folder_list_auth_dialog = function(label, driver)
  {
    var args = {width: 400, height: 300, buttons: {}},
      dialog = $('#files-folder-auth-dialog'),
      content = this.folder_list_auth_form(driver);

    if ($('#files-folder-auth-dialog').is(':visible'))
      return false;

    dialog.find('table.propform').remove();
    $('.auth-options', dialog).before(content);

    args.buttons[this.t('kolab_files.save')] = function() {
      var data = {folder: label, list: 1, permissions: 1, level: -2};

      $('input', dialog).each(function() {
        data[this.name] = this.type == 'checkbox' && !this.checked ? '' : this.value;
      });

      file_api.req = file_api.set_busy(true, 'kolab_files.authenticating');
      file_api.request('folder_auth', data, 'folder_auth_response');
      kolab_dialog_close(this);
    };

    args.buttons[this.t('kolab_files.cancel')] = function() {
      delete file_api.auth_errors[label];
      kolab_dialog_close(this);
      // go to the next one
      file_api.folder_list_auth_errors();
    };

    args.title = this.t('kolab_files.folderauthtitle').replace('$title', label);
    args.button_classes = ['mainaction save', 'cancel'];

    // show dialog window
    kolab_dialog_show(dialog, args, function() {
      // focus first empty input
      $('input', dialog).each(function() {
        if (!this.value) {
          this.focus();
          return false;
        }
      });
    });

    return true;
  };

  // folder_auth handler
  this.folder_auth_response = function(response, params)
  {
    if (!this.response(response)) {
      this.folder_list_auth_errors();
      return;
    }

    delete this.auth_errors[response.result.folder];

    // go to the next one
    this.folder_list_auth_errors();

    // update the list
    this.folder_list_merge(response.result.folder, response.result.list, params.level);
  };

  // Update folders list with additional folders
  this.folder_list_merge = function(folder, list, level)
  {
    if (!list || !list.length)
      return;

    var i, last, folders, result = {}, index = [], ref = this;

    if (this.list_merge_lock) {
      return setTimeout(function() { ref.folder_list_merge(folder, list); }, 50);
    }

    this.list_merge_lock = true;

    // Parse result
    folders = this.folder_list_parse(list);

    if (level < 0)
      level *= -1;

    // Add folders from the external source to the list
    $.each(folders, function(i, f) {
      if (ref.env.folders[i]) {
        last = i;
        return;
      }

      // We don't use this id
      delete f.id;

      // Append row to the list
      var row = file_api.folder_list_row(i, f);
      if (row)
        ref.list_element.append(row);

      // Need env.folders update so all parents are available
      // in successive folder_list_row() calls
      ref.env.folders[i] = f;
      index.push(i);

      // Load deeper folder hierarchies
      if (level && f.depth == level)
        ref.folder_list_update_wait(i);
    });

    // Reset folders list widget
    rcmail.folder_list.reset(true, true);

    // Rebuild folders list with correct order
    for (i in this.env.folders) {
      result[i] = this.env.folders[i];
      if (i === last)
        break;
    }
    for (i in index) {
      result[index[i]] = this.env.folders[index[i]];
    }
    for (i in this.env.folders) {
      if (!result[i]) {
        result[i] = this.env.folders[i];
      }
    }

    this.env.folders = result;

    if ((folder = this.env.folder) || (folder = this.env.init_folder)) {
      if (this.env.folders[folder]) {
        this.env.init_folder = null;
        if (folder != rcmail.folder_list.get_selection())
            rcmail.folder_list.select(folder);
      }
    }

    this.list_merge_lock = false;
  };

  // returns content of the external storage authentication form
  this.folder_list_auth_form = function(driver)
  {
    var rows = [];

    $.each(driver.form, function(fi, fv) {
      var id = 'authinput' + fi,
        attrs = {type: fi.match(/pass/) ? 'password' : 'text', size: 25, name: fi, id: id},
        input = $('<input>').attr(attrs);

      if (driver.form_values && driver.form_values[fi])
        input.attr({value: driver.form_values[fi]});

      rows.push($('<tr>')
        .append($('<td class="title">').append($('<label>').attr('for', id).text(fv)))
        .append($('<td>').append(input))
      );
    });

    return $('<table class="propform">').append(rows);
  };
};
