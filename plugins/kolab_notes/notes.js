/**
 * Client scripts for the Kolab Notes plugin
 *
 * @author Thomas Bruederli <bruederli@kolabsys.com>
 * @author Aleksander Machniak <machniak@kolabsys.com>
 *
 * @licstart  The following is the entire license notice for the
 * JavaScript code in this file.
 *
 * Copyright (C) 2014-2017, Kolab Systems AG <contact@kolabsys.com>
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

function rcube_kolab_notes_ui(settings)
{
    /*  private vars  */
    var ui_loading = false;
    var saving_lock;
    var search_query;
    var folder_drop_target;
    var notebookslist;
    var noteslist;
    var notesdata = {};
    var taglist;
    var search_request;
    var search_query;
    var render_no_focus;
    var me = this;

    /*  public members  */
    this.selected_list = null;
    this.selected_note = null;
    this.notebooks = rcmail.env.kolab_notebooks || {};
    this.list_set_sort = list_set_sort;
    this.settings = settings;

    /**
     * initialize the notes UI
     */
    function init()
    {
        // register button commands
        rcmail.register_command('createnote', function(){
            warn_unsaved_changes(function(){ edit_note(null, 'new'); });
        }, false);
        rcmail.register_command('list-create', function(){ list_edit_dialog(null); }, true);
        rcmail.register_command('list-edit', function(){ list_edit_dialog(me.selected_list); }, false);
        rcmail.register_command('list-delete', function(){ list_delete(me.selected_list); }, false);
        rcmail.register_command('list-remove', function(){ list_remove(me.selected_list); }, false);
        rcmail.register_command('list-sort', list_set_sort, true);
        rcmail.register_command('save', save_note, true);
        rcmail.register_command('delete', delete_notes, false);
        rcmail.register_command('search', quicksearch, true);
        rcmail.register_command('reset-search', reset_search, true);
        rcmail.register_command('sendnote', send_note, false);
        rcmail.register_command('print', print_note, false);
        rcmail.register_command('history', show_history_dialog, false);

        // register server callbacks
        rcmail.addEventListener('plugin.data_ready', data_ready);
        rcmail.addEventListener('plugin.render_note', render_note);
        rcmail.addEventListener('plugin.update_note', update_note);
        rcmail.addEventListener('plugin.update_list', list_update);
        rcmail.addEventListener('plugin.destroy_list', list_destroy);
        rcmail.addEventListener('plugin.unlock_saving', function() {
            if (saving_lock) {
                rcmail.set_busy(false, null, saving_lock);
            }
            if (rcmail.gui_objects.noteseditform) {
                rcmail.lock_form(rcmail.gui_objects.noteseditform, false);
            }
        });

        rcmail.addEventListener('plugin.close_history_dialog', close_history_dialog);
        rcmail.addEventListener('plugin.note_render_changelog', render_changelog);
        rcmail.addEventListener('plugin.note_show_revision', render_revision);
        rcmail.addEventListener('plugin.note_show_diff', show_diff);

        // initialize folder selectors
        if (settings.selected_list && !me.notebooks[settings.selected_list]) {
            settings.selected_list = null;
        }
        for (var id in me.notebooks) {
            if (me.notebooks[id].editable && !settings.selected_list) {
                settings.selected_list = id;
            }
        }

        var widget_class = window.kolab_folderlist || rcube_treelist_widget;
        notebookslist = new widget_class(rcmail.gui_objects.notebooks, {
          id_prefix: 'rcmliknb',
          save_state: true,
          selectable: true,
          keyboard: true,
          searchbox: '#notebooksearch',
          search_action: 'notes/list',
          search_sources: [ 'folders', 'users' ],
          search_title: rcmail.gettext('listsearchresults','kolab_notes'),
          check_droptarget: function(node) {
              var list = me.notebooks[node.id];
              return !node.virtual && list.editable && node.id != me.selected_list;
          }
        });
        notebookslist.addEventListener('select', function(node) {
            var id = node.id;
            if (me.notebooks[id] && id != me.selected_list) {
                warn_unsaved_changes(function(){
                    rcmail.enable_command('createnote', has_permission(me.notebooks[id], 'i'));
                    rcmail.enable_command('list-edit', has_permission(me.notebooks[id], 'a'));
                    rcmail.enable_command('list-delete', has_permission(me.notebooks[id], 'xa'));
                    rcmail.enable_command('list-remove', !me.notebooks[id]['default']);
                    fetch_notes(id);  // sets me.selected_list
                    rcmail.triggerEvent('show-list', {title: me.notebooks[id].listname}); // Elastic
                },
                function(){
                    // restore previous selection
                    notebookslist.select(me.selected_list);
                });
            }

            // unfocus clicked list item
            $(notebookslist.get_item(id)).find('a.listname').first().blur();
        });
        notebookslist.addEventListener('subscribe', function(p) {
            var list;
            if ((list = me.notebooks[p.id])) {
                list.subscribed = p.subscribed || false;
                rcmail.http_post('list', { _do:'subscribe', _list:{ id:p.id, permanent:list.subscribed?1:0 } });
            }
        });
        notebookslist.addEventListener('remove', function(p) {
            if (me.notebooks[p.id] && !me.notebooks[p.id]['default']) {
                list_remove(p.id);
            }
        });
        notebookslist.addEventListener('insert-item', function(p) {
            var list = p.data;
            if (list && list.id && !list.virtual) {
                me.notebooks[list.id] = list;
                if (list.subscribed)
                    rcmail.http_post('list', { _do:'subscribe', _list:{ id:p.id, permanent:1 } });
            }
        });
        notebookslist.addEventListener('click-item', function(p) {
            // avoid link execution
            return false;
        });
        notebookslist.addEventListener('search-complete', function(data) {
            if (data.length)
                rcmail.display_message(rcmail.gettext('nrnotebooksfound','kolab_notes').replace('$nr', data.length), 'voice');
            else
                rcmail.display_message(rcmail.gettext('nonotebooksfound','kolab_notes'), 'notice');
        });

        // Make Elastic checkboxes pretty
        if (window.UI && UI.pretty_checkbox) {
            notebookslist.addEventListener('add-item', function(prop) {
                UI.pretty_checkbox($(prop.li).find('input'));
            });
        }

        $(rcmail.gui_objects.notebooks).on('click', 'div.folder > a.listname', function(e) {
            var id = String($(this).closest('li').attr('id')).replace(/^rcmliknb/, '');
            notebookslist.select(id);
            e.preventDefault();
            return false;
        });

        // register dbl-click handler to open list edit dialog
        $(rcmail.gui_objects.notebooks).on('dblclick', 'li:not(.virtual) a', function(e) {
            var id = String($(this).closest('li').attr('id')).replace(/^rcmliknb/, '');
            if (me.notebooks[id] && has_permission(me.notebooks[id], 'a')) {
                list_edit_dialog(id);
            }

            // clear text selection (from dbl-clicking)
            var sel = window.getSelection ? window.getSelection() : document.selection;
            if (sel && sel.removeAllRanges) {
                sel.removeAllRanges();
            }
            else if (sel && sel.empty) {
                sel.empty();
            }

            e.preventDefault();
            return false;
        });

        // initialize notes list widget
        if (rcmail.gui_objects.noteslist) {
            rcmail.noteslist = noteslist = new rcube_list_widget(rcmail.gui_objects.noteslist,
                { multiselect:true, draggable:true, keyboard:true });
            noteslist.addEventListener('select', function(list) {
                render_no_focus = rcube_event._last_keyboard_event && $(list.list).has(rcube_event._last_keyboard_event.target);
                var selection_changed = list.selection.length != 1 || !me.selected_note || list.selection[0] != me.selected_note.id;
                selection_changed && warn_unsaved_changes(function(){
                    var note;
                    if (!list.multi_selecting && noteslist.selection.length == 1 && (note = notesdata[noteslist.selection[0]])) {
                        edit_note(note.uid, 'edit');
                    }
                    else {
                        reset_view(true);
                    }
                },
                function(){
                    // TODO: previous restore selection
                    list.select(me.selected_note.id);
                });

                rcmail.enable_command('delete', me.notebooks[me.selected_list] && has_permission(me.notebooks[me.selected_list], 'td') && list.selection.length > 0);
                rcmail.enable_command('sendnote', list.selection.length > 0);
                rcmail.enable_command('print', 'history', list.selection.length == 1);
            })
            .addEventListener('dragstart', function(e) {
                folder_drop_target = null;
                notebookslist.drag_start();
            })
            .addEventListener('dragmove', function(e) {
                folder_drop_target = notebookslist.intersects(rcube_event.get_mouse_pos(e), true);
            })
            .addEventListener('dragend', function(e) {
                notebookslist.drag_end();

                // move dragged notes to this folder
                if (folder_drop_target) {
                    noteslist.draglayer.hide();

                    // check unsaved changes first
                    var new_folder_id = folder_drop_target;
                    warn_unsaved_changes(
                        // ok
                        function() {
                            move_notes(new_folder_id);
                            reset_view();
                            noteslist.clear_selection();
                        },
                        // nok
                        undefined,
                        // beforesave
                        function(savedata) {
                            savedata.list = new_folder_id;

                            // remove from list and thus avoid being moved (again)
                            var id = me.selected_note.id;
                            noteslist.remove_row(id);
                            delete notesdata[id];
                        }
                    );
                }
                folder_drop_target = null;
            })
            .init().focus();
        }

        if (settings.sort_col) {
            $('#notessortmenu a.by-' + settings.sort_col).addClass('selected');
        }

        init_editor();

        if (settings.selected_list) {
            notebookslist.select(settings.selected_list)
        }

        rcmail.addEventListener('kolab-tags-search', filter_notes)
            .addEventListener('kolab-tags-drop-data', function(e) { return notesdata[e.id]; })
            .addEventListener('kolab-tags-drop', function(e) {
                if ($(e.list).is('#kolabnoteslist')) {
                    return;
                }

                var rec = notesdata[e.id];

                if (rec && rec.id && e.tag) {
                    savedata = me.selected_note && rec.uid == me.selected_note.uid ? get_save_data() : $.extend({}, rec);

                    if (savedata.id)   delete savedata.id;
                    if (savedata.html) delete savedata.html;

                    if (!savedata.tags)
                        savedata.tags = [];
                    savedata.tags.push(e.tag);

                    rcmail.lock_form(rcmail.gui_objects.noteseditform, true);
                    saving_lock = rcmail.set_busy(true, 'kolab_notes.savingdata');
                    rcmail.http_post('action', { _data: savedata, _do: 'edit' }, true);
                }
            });

        rcmail.triggerEvent('kolab-notes-init');
    }
    this.init = init;

    /**
     *
     */
    function init_dialog()
    {
        rcmail.register_command('save', save_note, true);
        rcmail.addEventListener('plugin.render_note', render_note);
        rcmail.addEventListener('plugin.update_note', function(data){
            data.id = rcmail.html_identifier_encode(data.uid);
            notesdata[data.id] = data;
            render_note(data);
        });
        rcmail.addEventListener('plugin.unlock_saving', function(){
            if (saving_lock) {
                rcmail.set_busy(false, null, saving_lock);
            }
            if (rcmail.gui_objects.noteseditform) {
                rcmail.lock_form(rcmail.gui_objects.noteseditform, false);
            }
            if (rcmail.is_framed()) {
                parent.$(parent.kolab_note_dialog_element).dialog('destroy');
            }
        });

        var id, callback = function() {
            if (settings.selected_uid) {
                me.selected_list = settings.selected_list;
                edit_note(settings.selected_uid);
            }
            else {
                me.selected_note = $.extend({
                    list: me.selected_list,
                    uid: null,
                    title: rcmail.gettext('newnote','kolab_notes'),
                    description: '',
                    tags: [],
                    created: rcmail.gettext('now', 'kolab_notes'),
                    changed: rcmail.gettext('now', 'kolab_notes')
                }, rcmail.env.kolab_notes_template || {});
                render_note(me.selected_note);
            }
        };

        for (id in me.notebooks) {
            if (me.notebooks[id].editable) {
                me.selected_list = id;
                break;
            }
        }

        init_editor(callback);
    }
    this.init_dialog = init_dialog;

    /**
     * Initialize TinyMCE editor
     */
    function init_editor(callback)
    {
        if (callback) {
            rcmail.addEventListener('editor-load', callback);
        }

        // initialize HTML editor
        var config = $.extend(rcmail.env.editor_config, {disabled_plugins: ['image', 'media']});
        rcmail.editor_init(config, 'notecontent');

        // register click handler for message links
        $(rcmail.gui_objects.notesattachmentslist).on('click', 'li a.messagelink', function() {
            rcmail.open_window(this.href);
            return false;
        });
    }

    /**
     * Quote HTML entities
     */
    function Q(str)
    {
        return String(str).replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;');
    }

    /**
     * Check permissions on the given list object
     */
    function has_permission(list, perm)
    {
        // multiple chars means "either of"
        if (String(perm).length > 1) {
            for (var i=0; i < perm.length; i++) {
                if (has_permission(list, perm[i])) {
                    return true;
                }
            }
        }

        if (list.rights && String(list.rights).indexOf(perm) >= 0) {
            return true;
        }

        return (perm == 'i' && list.editable);
    }

    /**
     * 
     */
    function edit_note(uid, action)
    {
        if (!uid) {
            me.selected_note = null;
            if (noteslist)
                noteslist.clear_selection();

            me.selected_note = {
                list: me.selected_list,
                uid: null,
                title: rcmail.gettext('newnote','kolab_notes'),
                description: '',
                tags: [],
                created: rcmail.gettext('now', 'kolab_notes'),
                changed: rcmail.gettext('now', 'kolab_notes')
            }
            render_note(me.selected_note);
        }
        else {
            ui_loading = rcmail.set_busy(true, 'loading');
            rcmail.http_request('get', { _list:me.selected_list, _id:uid }, true);
        }
    }

    /**
     * 
     */
    function list_edit_dialog(id)
    {
        var list = me.notebooks[id] || { name:'', editable:true, rights: 'riwta' },
            title = rcmail.gettext((list.id ? 'editlist' : 'newnotebook'), 'kolab_notes'),
            params = {_do: (list.id ? 'form-edit' : 'form-new'), _list: {id: list.id}, _framed: 1},
            $dialog = $('<iframe>').attr('src', rcmail.url('list', params)).on('load', function() {
                $(this).contents().find('#noteslist-name')
                    .prop('disabled', !has_permission(list, 'a'))
                    .val(list.editname || list.name)
                    .select();
            }),
            save_func = function() {
                var data,
                    form = $dialog.contents().find('#noteslistpropform'),
                    name = form.find('#noteslist-name');

                // form is not loaded
                if (!form || !form.length)
                    return false;

                // do some input validation
                if (!name.val() || name.val().length < 2) {
                    rcmail.alert_dialog(rcmail.gettext('invalidlistproperties', 'kolab_notes'), function() {
                        name.select();
                    });

                    return false;
                }

                // post data to server
                data = form.serializeJSON();
                if (list.id)
                    data.id = list.id;

                saving_lock = rcmail.set_busy(true, 'kolab_notes.savingdata');
                rcmail.http_post('list', { _do: (list.id ? 'edit' : 'new'), _list: data });
                return true;
            };

        rcmail.simple_dialog($dialog, title, save_func, {
            width: 600,
            height: 400
        });
    }

    /**
     * Callback from server after changing list properties
     */
    function list_update(prop)
    {
        if (prop._reload) {
            rcmail.redirect(rcmail.url('', { _list: (prop.newid || prop.id) }));
        }
        else if (prop.newid && prop.newid != prop.id) {
            var book = $.extend({}, me.notebooks[prop.id]);
            book.id = prop.newid;
            book.name = prop.name;
            book.listname = prop.listname;
            book.editname = prop.editname || prop.name;

            me.notebooks[prop.newid] = book;
            delete me.notebooks[prop.id];

            // update treelist item
            var li = $(notebookslist.get_item(prop.id));
            $('.listname', li).html(prop.listname);
            notebookslist.update(prop.id, { id:book.id, html:li.html() });

            // link all loaded note records to the new list id
            if (me.selected_list == prop.id) {
                me.selected_list = prop.newid;
                for (var k in notesdata) {
                    if (notesdata[k].list == prop.id) {
                        notesdata[k].list = book.id;
                    }
                }
                notebookslist.select(prop.newid);
            }
        }
    }

    /**
     * 
     */
    function list_delete(id)
    {
        var list = me.notebooks[id];
        if (list) {
            rcmail.confirm_dialog(rcmail.gettext('kolab_notes.deletenotebookconfirm'), 'delete', function() {
                saving_lock = rcmail.set_busy(true, 'kolab_notes.savingdata');
                rcmail.http_post('list', { _do: 'delete', _list: { id: list.id } });
            });
        }
    }

    /**
     * 
     */
    function list_remove(id)
    {
        if (me.notebooks[id]) {
            list_destroy(me.notebooks[id]);
            rcmail.http_post('list', { _do:'subscribe', _list:{ id:id, permanent:0, recursive:1 } });
        }
    }

    /**
     * Callback from server on list delete command
     */
    function list_destroy(prop)
    {
        if (!me.notebooks[prop.id]) {
            return;
        }

        notebookslist.remove(prop.id);
        delete me.notebooks[prop.id];

        if (me.selected_list == prop.id) {
            for (id in me.notebooks) {
                if (me.notebooks[id]) {
                    notebookslist.select(id);
                    break;
                }
            }
        }
    }

    /**
     * Change notes list sort order
     */
    function list_set_sort(col)
    {
        if (settings.sort_col != col) {
            settings.sort_col = col;
            $('#notessortmenu a').removeClass('selected').filter('.by-' + col).addClass('selected');
            rcmail.save_pref({ name: 'kolab_notes_sort_col', value: col });

            // re-sort table in DOM
            $(noteslist.tbody).children().sortElements(function(la, lb){
                var a_id = String(la.id).replace(/^rcmrow/, ''),
                    b_id = String(lb.id).replace(/^rcmrow/, ''),
                    a = notesdata[a_id],
                    b = notesdata[b_id];

                if (!a || !b) {
                    return 0;
                }
                else if (settings.sort_col == 'title') {
                    return String(a.title).toLowerCase() > String(b.title).toLowerCase() ? 1 : -1;
                }
                else {
                    return b.changed_ - a.changed_;
                }
            });
        }
    }

    /**
     * Execute search
     */
    function quicksearch()
    {
        var q;
        if (rcmail.gui_objects.qsearchbox && (q = rcmail.gui_objects.qsearchbox.value)) {
            var id = 'search-'+q;

            // ignore if query didn't change
            if (search_request == id)
                return;

            warn_unsaved_changes(function(){
                search_request = id;
                search_query = q;

                fetch_notes();
            },
            function(){
                reset_search();
            });
        }
        else {  // empty search input equals reset
            reset_search();
        }
    }

    /**
     * Reset search and get back to normal listing
     */
    function reset_search()
    {
        $(rcmail.gui_objects.qsearchbox).val('');

        if (search_request) {
            search_request = search_query = null;
            fetch_notes();
        }
    }

    /**
     * 
     */
    function fetch_notes(id)
    {
        if (rcmail.busy)
            return;

        if (id && id != me.selected_list) {
            me.selected_list = id;
        }

        ui_loading = rcmail.set_busy(true, 'loading');
        rcmail.http_request('fetch', { _list:me.selected_list, _q:search_query }, true);

        reset_view();
        noteslist.clear(true);
        notesdata = {};
        update_state();
    }

    /**
     * Filter notes by tag
     */
    function filter_notes(tags)
    {
        var id, note, tr, match;
        for (id in noteslist.rows) {
            tr = noteslist.rows[id].obj;
            note = notesdata[id];
            match = note.tags && note.tags.length;
            for (var i=0; match && note && i < tags.length; i++) {
                if ($.inArray(tags[i], note.tags) < 0)
                    match = false;
            }

            if (match || !tags.length) {
                $(tr).show();
            }
            else {
                $(tr).hide();
            }

            if (me.selected_note && me.selected_note.uid == note.uid && !match) {
                warn_unsaved_changes(function(){
                    me.selected_note = null;
                    noteslist.clear_selection();
                }, function(){
                    filter_notes(tags);
                });
            }
        }
    }

    /**
     * 
     */
    function data_ready(data)
    {
        data.data.sort(function(a,b){
            if (settings.sort_col == 'title') {
                return String(a.title).toLowerCase() > String(b.title).toLowerCase() ? 1 : -1;
            }
            else {
                return b.changed_ - a.changed_;
            }
        });

        var i, id, rec;
        for (i=0; data.data && i < data.data.length; i++) {
            rec = data.data[i];
            rec.id = rcmail.html_identifier_encode(rec.uid);
            noteslist.insert_row({
                id: 'rcmrow' + rec.id,
                cols: [
                    { className:'title', innerHTML:Q(rec.title) },
                    { className:'date',  innerHTML:Q(rec.changed || '') }
                ]
            });

            notesdata[rec.id] = rec;
        }

        update_taglist();
        rcmail.set_busy(false, 'loading', ui_loading);

        rcmail.triggerEvent('listupdate', {list: noteslist, rowcount:noteslist.rowcount});

        if (settings.selected_uid) {
            noteslist.select(rcmail.html_identifier_encode(settings.selected_uid));
            delete settings.selected_uid;
        }
        else if (me.selected_note && notesdata[me.selected_note.id]) {
            noteslist.select(me.selected_note.id);
        }
        else if (!data.data.length) {
            rcmail.display_message(rcmail.gettext('norecordsfound','kolab_notes'), 'notice');
        }
    }

    /**
     *
     */
    function render_note(data, container, temp, retry)
    {
        rcmail.set_busy(false, 'loading', ui_loading);

        if (!data) {
            rcmail.display_message(rcmail.get_label('recordnotfound', 'kolab_notes'), 'error');
            return;
        }

        if (!container) {
            container = rcmail.gui_containers['notedetailview'];
        }

        // for Elastic
        rcmail.triggerEvent('show-content', {
            mode: data.uid ? 'edit' : 'add',
            title: rcmail.gettext(data.uid ? 'editnote' : 'newnote', 'kolab_notes'),
            obj: $('#notedetailsbox').parent(),
            scrollElement: $('#notedetailsbox')
        });

        var list = me.notebooks[data.list] || me.notebooks[me.selected_list] || { rights: 'lrs', editable: false };
            content = $('#notecontent').val(data.description),
            readonly = data.readonly || !(list.editable || !data.uid && has_permission(list,'i')),
            attachmentslist = gui_object('notesattachmentslist', container).html(''),
            titlecontainer = rcmail.gui_objects.noteviewtitle || container,
            is_html = false;

        // tag-edit line
        var tagline = $('.tagline', titlecontainer);
        if (window.kolab_tags_input) {
            tagline.parent('.form-group').show();
            taglist = kolab_tags_input(tagline, data.tags, readonly);
        }
        else {
            tagline.parent('.form-group').hide();
        }

        $('.notetitle', titlecontainer).val(data.title).prop('disabled', readonly).show();
        $('.dates .notecreated', titlecontainer).text(data.created || '');
        $('.dates .notechanged', titlecontainer).text(data.changed || '');
        if (data.created || data.changed) {
            $('.dates', titlecontainer).show();
        }

        if (data.links) {
            $.each(data.links, function(i, link) {
                var li = $('<li>').addClass('link')
                    .addClass('message eml')
                    .append($('<a>')
                        .attr('href', link.mailurl)
                        .addClass('messagelink filename')
                        .text(link.subject || link.uri)
                    )
                    .appendTo(attachmentslist);

                if (!readonly && !data._from_mail) {
                    $('<a>').attr({
                            href: '#delete',
                            title: rcmail.gettext('removelink', 'kolab_notes'),
                            label: rcmail.gettext('delete'),
                            'class': 'delete'
                        })
                        .click({ uri:link.uri }, function(e) {
                            remove_link(this, e.data.uri);
                            return false;
                        })
                        .appendTo(li);
                }
            });
        }

        if (!data.list)
            data.list = list.id;

        data.readonly = readonly;

        if (!temp) {
            $(rcmail.gui_objects.notebooks).filter('select').val(list.id);
            me.selected_note = data;
            me.selected_note.id = rcmail.html_identifier_encode(data.uid);
            rcmail.enable_command('save', !readonly);
        }

        var html = data.html || data.description;

        // convert plain text to HTML and make URLs clickable
        if (html != '' && (!data.html || !html.match(/<(html|body)/))) {
            html = text2html(html);
        }

        if (!readonly) {
            gui_object('notesdetailview', container).hide();
            gui_object('noteseditform', container).show();

            rcmail.editor.set_content(html);

            // read possibly re-formatted content back from editor for later comparison
            me.selected_note.description = rcmail.editor.get_content().replace(/^\s*(<p><\/p>\n*)?/, '');
            is_html = true;
        }
        else {
            gui_object('noteseditform', container).hide();
            gui_object('notesdetailview', container).html(html).show();
        }

        // notify subscribers
        rcmail.triggerEvent('kolab_notes_render', { data:data, readonly:readonly, html:is_html });
        if (rcmail.is_framed())
            parent.rcmail.triggerEvent('kolab_notes_render', { data:data, readonly:readonly, html:is_html });

        // Trigger resize (needed for proper editor resizing)
        $(window).resize();

        // Elastic
        $(container).parents('.watermark').addClass('formcontainer');
        $('#notedetailsbox').parent().trigger('loaded');

        if (!readonly)
            $('.notetitle', titlecontainer).focus().select();
    }

    /**
     *
     */
    function gui_object(name, container)
    {
        var elem = rcmail.gui_objects[name], selector = elem;
        if (elem && elem.className && container) {
            selector = '.' + String(elem.className).split(' ').join('.');
        }
        else if (elem && elem.id) {
            selector = '#' + elem.id;
        }

        return $(selector, container);
    }

    /**
     * Convert the given plain text to HTML contents to be displayed in editor
     */
    function text2html(str)
    {
        // simple link parser (similar to rcube_string_replacer class in PHP)
        var utf_domain = '[^?&@"\'/\\(\\)\\s\\r\\t\\n]+\\.([^\x00-\x2f\x3b-\x40\x5b-\x60\x7b-\x7f]{2,}|xn--[a-z0-9]{2,})',
            url1 = '.:;,', url2 = 'a-z0-9%=#@+?&/_~\\[\\]-',
            link_pattern = new RegExp('([hf]t+ps?://|www.)('+utf_domain+'(['+url1+']?['+url2+']+)*)?', 'ig'),
            link_replace = function(matches, p1, p2) {
                var url = (p1 == 'www.' ? 'http://' : '') + p1 + p2;
                return '<a href="' + url + '" class="x-templink">' + p1 + p2 + '</a>';
            };

        return '<pre>' + Q(str).replace(link_pattern, link_replace) + '</pre>';
    }

    /**
     *
     */
    function show_history_dialog()
    {
        var dialog, rec = me.selected_note;
        if (!rec || !rec.uid || !window.libkolab_audittrail) {
            return false;
        }

        // render dialog
        $dialog = libkolab_audittrail.object_history_dialog({
            module: 'kolab_notes',
            container: '#notehistory',
            title: rcmail.gettext('libkolab.objectchangelog') + ' - ' + rec.title,

            // callback function for list actions
            listfunc: function(action, rev) {
                var rec = $dialog.data('rec');
                saving_lock = rcmail.set_busy(true, 'loading', saving_lock);
                rcmail.http_post('action', { _do: action, _data: { uid: rec.uid, list:rec.list, rev: rev } }, saving_lock);
            },

            // callback function for comparing two object revisions
            comparefunc: function(rev1, rev2) {
                var rec = $dialog.data('rec');
                saving_lock = rcmail.set_busy(true, 'loading', saving_lock);
                rcmail.http_post('action', { _do: 'diff', _data: { uid: rec.uid, list: rec.list, rev1: rev1, rev2: rev2 } }, saving_lock);
            }
        });

        $dialog.data('rec', rec);

        // fetch changelog data
        saving_lock = rcmail.set_busy(true, 'loading', saving_lock);
        rcmail.http_post('action', { _do: 'changelog', _data: { uid: rec.uid, list: rec.list } }, saving_lock);
    }

    /**
     *
     */
    function render_changelog(data)
    {
        var $dialog = $('#notehistory'),
            rec = $dialog.data('rec');

        if (data === false || !data.length || !rec) {
            // display 'unavailable' message
            $('<div class="notfound-message note-dialog-message warning">').text(rcmail.gettext('libkolab.objectchangelognotavailable'))
                .insertBefore($dialog.find('.changelog-table').hide());
            return;
        }

        data.module = 'kolab_notes';
        libkolab_audittrail.render_changelog(data, rec, me.notebooks[rec.list]);
    }

    /**
     *
     */
    function render_revision(data)
    {
        data.readonly = true;

        // clone view and render data into a dialog
        var model = rcmail.gui_containers['notedetailview'],
            container = model.clone();

        container
            .removeAttr('id style class role')
            .find('.mce-container').remove();

        // reset custom styles
        container.children('div, form').removeAttr('id style');

        // open jquery UI dialog
        container.dialog({
            modal: false,
            resizable: true,
            closeOnEscape: true,
            title: data.title + ' @ ' + data.rev,
            close: function() {
                container.dialog('destroy').remove();
            },
            buttons: [
                {
                    text: rcmail.gettext('close'),
                    click: function() { container.dialog('close'); },
                    autofocus: true
                }
            ],
            width: model.width(),
            height: model.height(),
            minWidth: 450,
            minHeight: 400
        })
        .show();

        render_note(data, container, true);
    }

    /**
     *
     */
    function show_diff(data)
    {
        var change_old, change_new,
            rec = me.selected_note,
            $dialog = $('#notediff').clone();

        $dialog.find('div.form-section, h2.note-title-new').hide().data('set', false);

        // always show title
        $('.note-title', $dialog).text(rec.title).removeClass('diff-text-old').show();

        // show each property change
        $.each(data.changes, function(i, change) {
            var prop = change.property, r2, html = false,
                row = $('div.note-' + prop, $dialog).first();

            // special case: title
            if (prop == 'title') {
                $('.note-title', $dialog).addClass('diff-text-old').text(change['old'] || '--');
                $('.note-title-new', $dialog).text(change['new'] || '--').show();
            }

            // no display container for this property
            if (!row.length) {
                return true;
            }

            if (change.diff_) {
                row.children('.diff-text-diff').html(change.diff_);
                row.children('.diff-text-old, .diff-text-new').hide();
            }
            else {
                change_old = change.old_ || change['old'] || '--';
                change_new = change.new_ || change['new'] || '--';

                row.children('.diff-text-old')[html ? 'html' : 'text'](change_old).show();
                row.children('.diff-text-new')[html ? 'html' : 'text'](change_new).show();
            }

            row.show().data('set', true);
        });

        // open jquery UI dialog
        rcmail.simple_dialog($dialog,
            rcmail.gettext('libkolab.objectdiff').replace('$rev1', data.rev1).replace('$rev2', data.rev2) + ' - ' + rec.title,
            null, {
                cancel_label: 'close',
                minWidth: 400,
                width: 480
            }
        );

        // set dialog size according to content
        libkolab_audittrail.dialog_resize($dialog.get(0), $dialog.height(), rcmail.gui_containers.notedetailview.width() - 40);
    }

    // close the note history dialog
    function close_history_dialog()
    {
        $('#notehistory, #notediff').each(function(i, elem) {
            var $dialog = $(elem);
            if ($dialog.is(':ui-dialog'))
                $dialog.dialog('close');
        });
    };

    /**
     *
     */
    function remove_link(elem, uri)
    {
        // remove the link item matching the given uri
        me.selected_note.links = $.grep(me.selected_note.links, function(link) { return link.uri != uri; });
        me.selected_note._links_removed = true

        // remove UI list item
        $(elem).hide().closest('li').addClass('deleted');
    }

    /**
     * Open a new window to print the currently selected note
     */
    function print_note()
    {
        var list = me.notebooks[me.selected_list],
            uid = me.selected_note ? me.selected_note.uid : 0;

        if (list && uid) {
            rcmail.open_window(rcmail.url('print', {_uid: uid, _list: list.id}), true, true);
        }
    }

    /**
     * Redirect to message compose screen with UIDs of notes to be appended
     */
    function send_note()
    {
        var uids = [];
        $.each(noteslist.selection, function() {
            if (rec = notesdata[this]) {
                uids.push(rec.uid);
                // TODO: check if rec.uid == me.selected_note.uid and unsaved changes
            }
        });

        if (uids.length) {
            rcmail.goto_url('mail/compose', { _with_notes: uids.join(','), _notes_list: me.selected_list }, true);
        }
    }

    /**
     * Display the given counts to each tag
     */
    function update_taglist(tags)
    {
        // compute counts first by iterating over all notes
        var counts = {};
        $.each(notesdata, function(id, rec) {
            for (var t, j=0; rec && rec.tags && j < rec.tags.length; j++) {
                t = rec.tags[j];
                if (typeof counts[t] == 'undefined')
                    counts[t] = 0;
                counts[t]++;
            }
        });

        rcmail.triggerEvent('kolab-tags-counts', {counter: counts});

        if (tags && tags.length) {
            rcmail.triggerEvent('kolab-tags-refresh', {tags: tags});
        }
    }

    /**
     * Callback from server after saving a note record
     */
    function update_note(data)
    {
        data.id = rcmail.html_identifier_encode(data.uid);

        var row, is_new = (notesdata[data.id] == undefined && data.list == me.selected_list);
        notesdata[data.id] = data;

        if (is_new || me.selected_note && data.id == me.selected_note.id) {
            render_note(data);
            update_taglist(data.tags || []);
        }
        else if (data.tags) {
            update_taglist(data.tags);
        }

        // add list item on top
        if (is_new) {
            noteslist.insert_row({
                id: 'rcmrow' + data.id,
                cols: [
                    { className:'title', innerHTML:Q(data.title) },
                    { className:'date',  innerHTML:Q(data.changed || '') }
                ]
            }, true);

            noteslist.select(data.id);
        }
        // update list item
        else if (row = noteslist.rows[data.id]) {
            $('.title', row.obj).text(data.title);
            $('.date', row.obj).text(data.changed || '');
            // TODO: move to top
        }
    }

    /**
     * 
     */
    function reset_view(list)
    {
        close_history_dialog();
        me.selected_note = null;
        $('.notetitle', rcmail.gui_objects.noteviewtitle).val('').hide();
        $('.dates', rcmail.gui_objects.noteviewtitle).hide();
        $('.tagline', rcmail.gui_objects.noteviewtitle).parent('.form-group').hide();
        $(rcmail.gui_objects.noteseditform).hide().parents('.watermark').removeClass('formcontainer');
        $(rcmail.gui_objects.notesdetailview).hide();
        $(rcmail.gui_objects.notesattachmentslist).html('');
        rcmail.enable_command('save', false);

        if (!list) {
            rcmail.triggerEvent('show-list', {force: true});
        }
    }

    /**
     * Collect data from the edit form and submit it to the server
     */
    function save_note(beforesave)
    {
        if (!me.selected_note) {
            return false;
        }

        var savedata = get_save_data();

        // run savedata through the given callback function
        if (typeof beforesave == 'function') {
            beforesave(savedata);
        }

        // add reference to old list if changed
        if (me.selected_note.list && savedata.list != me.selected_note.list) {
            savedata._fromlist = me.selected_note.list;
        }

        // do some input validation
        if (savedata.title == '') {
            rcmail.alert_dialog(rcmail.gettext('entertitle', 'kolab_notes'), function() {;
                $('.notetitle', rcmail.gui_objects.noteviewtitle).focus();
            });

            return false;
        }

        if (check_change_state(savedata)) {
            rcmail.lock_form(rcmail.gui_objects.noteseditform, true);
            saving_lock = rcmail.set_busy(true, 'kolab_notes.savingdata');
            rcmail.http_post('action', {_data: savedata, _do: savedata.uid ? 'edit' : 'new'}, true);
        }
        else {
            rcmail.display_message(rcmail.get_label('nochanges', 'kolab_notes'), 'notice');
        }
    }

    /**
     * Collect updated note properties from edit form for saving
     */
    function get_save_data()
    {
        var listselect = $('option:selected', rcmail.gui_objects.notebooks),
            savedata = {
                title: $.trim($('.notetitle', rcmail.gui_objects.noteviewtitle).val()),
                description: rcmail.editor.get_content().replace(/^\s*(<p><\/p>\n*)?/, ''),
                list: listselect.length ? listselect.val() : me.selected_note.list || me.selected_list,
                uid: me.selected_note.uid,
                tags: taglist ? kolab_tags_input_value(taglist) : []
            };

        // copy links
        if ($.isArray(me.selected_note.links)) {
            savedata.links = me.selected_note.links;
        }

        return savedata;
    }

    /**
     * Check if the currently edited note record was changed
     */
    function check_change_state(data)
    {
        if (!me.selected_note || me.selected_note.readonly || !has_permission(me.notebooks[me.selected_note.list || me.selected_list], 'i')) {
            return false;
        }

        var savedata = data || get_save_data();

        return savedata.title != me.selected_note.title
            || savedata.description != me.selected_note.description
            || savedata.tags.join(',') != (me.selected_note.tags || []).join(',')
            || savedata.list != me.selected_note.list
            || me.selected_note._links_removed;
    }

    /**
     * Check for unsaved changes and warn the user
     */
    function warn_unsaved_changes(ok, nok, beforesave)
    {
        if (typeof ok != 'function')
            ok = function(){ };
        if (typeof nok != 'function')
            nok = function(){ };

        if (check_change_state()) {
            var dialog, buttons = [];

            buttons.push({
                text: rcmail.gettext('save'),
                'class': 'mainaction save',
                click: function() {
                    save_note(beforesave);
                    dialog.dialog('close');
                    rcmail.busy = false;  // don't block next action
                    ok();
                }
            });

            buttons.push({
                text: rcmail.gettext('discard', 'kolab_notes'),
                'class': 'discard',
                click: function() {
                    dialog.dialog('close');
                    ok();
                }
            });

            buttons.push({
                text: rcmail.gettext('abort', 'kolab_notes'),
                'class': 'cancel',
                click: function() {
                    dialog.dialog('close');
                    nok();
                }
            });

            var options = {
                width: 460,
                resizable: false,
                closeOnEscape: false,
                dialogClass: 'warning',
                open: function(event, ui) {
                    $(this).parent().find('.ui-dialog-titlebar-close').hide();
                    setTimeout(function() {
                        dialog.parent().find('button:visible').first().focus();
                    }, 10);
                },
                close: function(event, ui) {
                    $(this).dialog('destroy').remove();
                }
            };

            // open jquery UI dialog
            dialog = rcmail.show_popup_dialog(
                rcmail.gettext('discardunsavedchanges', 'kolab_notes'),
                rcmail.gettext('unsavedchanges', 'kolab_notes'),
                buttons,
                options
            );

            return false;
        }

        if (typeof ok == 'function') {
            ok();
        }

        return true;
    }

    /**
     *
     */
    function delete_notes()
    {
        if (!noteslist.selection.length) {
            return false;
        }

        rcmail.confirm_dialog(rcmail.gettext('kolab_notes.deletenotesconfirm'), 'delete', function() {
            var i, rec, id, uids = [];
            for (i=0; i < noteslist.selection.length; i++) {
                id = noteslist.selection[i];
                rec = notesdata[id];
                if (rec) {
                    noteslist.remove_row(id);
                    uids.push(rec.uid);
                    delete notesdata[id];
                }
            }

            saving_lock = rcmail.set_busy(true, 'kolab_notes.savingdata');
            rcmail.http_post('action', { _data: { uid: uids.join(','), list: me.selected_list }, _do: 'delete' }, true);

            reset_view();
            update_taglist();
            noteslist.clear_selection();
        });
    }

    /**
     *
     */
    function move_notes(list_id)
    {
        var rec, id, uids = [];
        for (var i=0; i < noteslist.selection.length; i++) {
            id = noteslist.selection[i];
            rec = notesdata[id];
            if (rec) {
                noteslist.remove_row(id);
                uids.push(rec.uid);
                delete notesdata[id];
            }
        }

        if (uids.length) {
            saving_lock = rcmail.set_busy(true, 'kolab_notes.savingdata');
            rcmail.http_post('action', { _data: { uid: uids.join(','), list: me.selected_list, to: list_id }, _do: 'move' }, true);
        }
    }

    /**
     * update browser location to remember current view
     */
    function update_state()
    {
        var query = { _list: me.selected_list }

        if (settings.selected_uid) {
            query._id = settings.selected_uid;
        }

        if (window.history.replaceState) {
            window.history.replaceState({}, document.title, rcmail.url('', query));
        }
    }
}


// extend jQuery
// from http://james.padolsey.com/javascript/sorting-elements-with-jquery/
jQuery.fn.sortElements = (function(){
    var sort = [].sort;

    return function(comparator, getSortable) {
        getSortable = getSortable || function(){ return this };

        var last = null;
        return sort.call(this, comparator).each(function(i){
            // at this point the array is sorted, so we can just detach each one from wherever it is, and add it after the last
            var node = $(getSortable.call(this));
            var parent = node.parent();
            if (last) last.after(node);
            else      parent.prepend(node);
            last = node;
        });
    };
})();


// List options menu for Elastic
function kolab_notes_options_menu()
{
    var content = $('#options-menu'),
        width = content.width() + 25,
        dialog = content.clone(true);

    // set form values
    $('select[name="sort_col"]', dialog).val(kolabnotes.settings.sort_col || '');
    $('select[name="sort_ord"]', dialog).val(kolabnotes.settings.sort_order || 'ASC');

    // Fix id/for attributes
    $('select', dialog).each(function() { this.id = this.id + '-clone'; });
    $('label', dialog).each(function() { $(this).attr('for', $(this).attr('for') + '-clone'); });

    var save_func = function(e) {
        if (rcube_event.is_keyboard(e.originalEvent)) {
            $('#listmenulink').focus();
        }

        var col = $('select[name="sort_col"]', dialog).val(),
            ord = $('select[name="sort_ord"]', dialog).val();

        kolabnotes.list_set_sort(col, ord);
        return true;
    };

    dialog = rcmail.simple_dialog(dialog, rcmail.gettext('listoptionstitle'), save_func, {
        closeOnEscape: true,
        open: function(e) {
            setTimeout(function() { dialog.find('select').first().focus(); }, 100);
        },
        minWidth: 400,
        width: width
    });
};


/* Notes plugin UI initialization */
var kolabnotes;
window.rcmail && rcmail.addEventListener('init', function(evt) {
    if (rcmail.task == 'notes' && rcmail.env.action == 'print') {
        rcmail.print_dialog();
        return;
    }

    kolabnotes = new rcube_kolab_notes_ui(rcmail.env.kolab_notes_settings);

    if (rcmail.env.action == 'dialog-ui')
        kolabnotes.init_dialog();
    else
        kolabnotes.init();
});
