/**
 * Client scripts for the Kolab Notes plugin
 *
 * @author Thomas Bruederli <bruederli@kolabsys.com>
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
    var tagsfilter = [];
    var tags = [];
    var me = this;

    /*  public members  */
    this.selected_list;
    this.selected_note;
    this.notebooks = rcmail.env.kolab_notebooks || {};

    /**
     * initialize the notes UI
     */
    function init()
    {
        // register button commands
        rcmail.register_command('createnote', function(){ edit_note(null, 'new'); }, false);
        rcmail.register_command('list-create', function(){ list_edit_dialog(null); }, false);
        rcmail.register_command('list-edit', function(){ list_edit_dialog(me.selected_list); }, false);
        rcmail.register_command('list-remove', function(){ list_remove(me.selected_list); }, false);
        rcmail.register_command('save', save_note, true);
        rcmail.register_command('delete', delete_notes, false);
        rcmail.register_command('search', quicksearch, true);
        rcmail.register_command('reset-search', reset_search, true);

        // register server callbacks
        rcmail.addEventListener('plugin.data_ready', data_ready);
        rcmail.addEventListener('plugin.render_note', render_note);
        rcmail.addEventListener('plugin.update_note', update_note);
        rcmail.addEventListener('plugin.unlock_saving', function(){
            if (saving_lock) {
                rcmail.set_busy(false, null, saving_lock);
            }
            if (rcmail.gui_objects.noteseditform) {
                rcmail.lock_form(rcmail.gui_objects.noteseditform, false);
            }
        });

        // initialize folder selectors
        var li, id;
        for (id in me.notebooks) {
            if (me.notebooks[id].editable && (!me.selected_list || (me.notebooks[id].active && !me.notebooks[me.selected_list].active))) {
                me.selected_list = id;
            }
        }

        notebookslist = new rcube_treelist_widget(rcmail.gui_objects.notebooks, {
          id_prefix: 'rcmliknb',
          selectable: true,
          check_droptarget: function(node) {
              return !node.virtual && node.id != me.selected_list;
          }
        });
        notebookslist.addEventListener('select', function(node) {
            var id = node.id;
            if (me.notebooks[id]) {
                rcmail.enable_command('list-edit', 'list-remove', me.notebooks[id].editable);
                fetch_notes(id);  // sets me.notebooks[id]
            }
        });

        // initialize notes list widget
        if (rcmail.gui_objects.noteslist) {
            noteslist = new rcube_list_widget(rcmail.gui_objects.noteslist,
                { multiselect:true, draggable:true, keyboard:false });
            noteslist.addEventListener('select', function(list) {
                var note;
                if (list.selection.length == 1 && (note = notesdata[list.selection[0]])) {
                    // TODO: check for unsaved changes and warn
                    edit_note(note.uid, 'edit');
                }
                else {
                    reset_view();
                }

                rcmail.enable_command('delete', me.notebooks[me.selected_list] && me.notebooks[me.selected_list].editable && list.selection.length > 0);
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
                    move_notes(folder_drop_target);
                    noteslist.clear_selection();
                    reset_view();
                }
                folder_drop_target = null;
            })
            .init();
        }

        // click-handler on tags list
        $(rcmail.gui_objects.notestagslist).on('click', function(e){
            var item = e.target.nodeName == 'LI' ? $(e.target) : $(e.target).closest('li'),
                tag = item.data('value');

            if (!tag)
                return false;

            // reset selection on regular clicks
            var index = $.inArray(tag, tagsfilter);
            var shift = e.shiftKey || e.ctrlKey || e.metaKey;

            if (!shift) {
                if (tagsfilter.length > 1)
                    index = -1;

                $('li', this).removeClass('selected');
                tagsfilter = [];
            }

            // add tag to filter
            if (index < 0) {
                item.addClass('selected');
                tagsfilter.push(tag);
            }
            else if (shift) {
                item.removeClass('selected');
                var a = tagsfilter.slice(0,index);
                tagsfilter = a.concat(tagsfilter.slice(index+1));
            }

            filter_notes();

            // clear text selection in IE after shift+click
            if (shift && document.selection)
              document.selection.empty();

            e.preventDefault();
            return false;
        })
        .mousedown(function(e){
            // disable content selection with the mouse
            e.preventDefault();
            return false;
        });

        // initialize tinyMCE editor
        var editor_conf = {
            mode: 'textareas',
            elements: 'notecontent',
            apply_source_formatting: true,
            theme: 'advanced',
            language: settings.editor.lang,
            content_css: settings.editor.editor_css,
            theme_advanced_toolbar_location: 'top',
            theme_advanced_toolbar_align: 'left',
            theme_advanced_buttons3: '',
            theme_advanced_statusbar_location: 'none',
            relative_urls: false,
            remove_script_host: false,
            gecko_spellcheck: true,
            convert_urls: false,
            paste_data_images: true,
            plugins: 'paste,tabfocus,searchreplace,table,inlinepopups',
            theme_advanced_buttons1: 'bold,italic,underline,|,justifyleft,justifycenter,justifyright,justifyfull,|,bullist,numlist,outdent,indent,blockquote,|,forecolor,backcolor,fontselect,fontsizeselect',
            theme_advanced_buttons2: 'link,unlink,table,charmap,|,search,code,|,undo,redo',
            setup: function(ed) {
                // make links open on shift-click
                ed.onClick.add(function(ed, e) {
                    var link = $(e.target).closest('a');
                    if (link.length && e.shiftKey) {
                        if (!bw.mz) window.open(link.get(0).href, '_blank');
                        return false;
                    }
                });
            }
        };

        // support external configuration settings e.g. from skin
        if (window.rcmail_editor_settings)
            $.extend(editor_conf, window.rcmail_editor_settings);

        tinyMCE.init(editor_conf);

        if (me.selected_list) {
            rcmail.enable_command('createnote', true);
            notebookslist.select(me.selected_list)
        }
    }
    this.init = init;

    /**
     * Quote HTML entities
     */
    function Q(str)
    {
        return String(str).replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;');
    }

    /**
     * Trim whitespace off the given string
     */
    function trim(str)
    {
        return String(str).replace(/\s+$/, '').replace(/^\s+/, '');
    }

    /**
     * 
     */
    function edit_note(uid, action)
    {
        if (!uid) {
            noteslist.clear_selection();
            me.selected_note = {
                list: me.selected_list,
                uid: null,
                title: rcmail.gettext('newnote','kolab_notes'),
                description: '',
                categories: [],
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
        
    }

    /**
     * 
     */
    function list_remove(id)
    {
        
    }

    /**
     * 
     */
    function quicksearch()
    {
        
    }

    /**
     * 
     */
    function reset_search()
    {
        
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
            noteslist.clear_selection();
        }

        ui_loading = rcmail.set_busy(true, 'loading');
        rcmail.http_request('fetch', { _list:me.selected_list, _q:search_query }, true);

        reset_view();
        noteslist.clear();
        notesdata = {};
        tagsfilter = [];
    }

    function filter_notes()
    {
        // tagsfilter
        var note, tr, match;
        for (var id in noteslist.rows) {
            tr = noteslist.rows[id].obj;
            note = notesdata[id];
            match = note.categories && note.categories.length;
            for (var i=0; match && note && i < tagsfilter.length; i++) {
                if ($.inArray(tagsfilter[i], note.categories) < 0)
                    match = false;
            }

            if (match || !tagsfilter.length) {
                $(tr).show();
            }
            else {
                $(tr).hide();
            }

            if (me.selected_note && me.selected_note.uid == note.uid && !match) {
                noteslist.clear_selection();
//                reset_view();
            }
        }
    }

    /**
     * 
     */
    function data_ready(data)
    {
        data.data.sort(function(a,b){
            return b.changed_ - a.changed_;
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

        render_tagslist(data.tags || [], true)
        rcmail.set_busy(false, 'loading', ui_loading);
    }

    /**
     *
     */
    function render_note(data)
    {
        rcmail.set_busy(false, 'loading', ui_loading);

        if (!data) {
            rcmail.display_message(rcmail.get_label('recordnotfound', 'kolab_notes'), 'error');
            return;
        }

        var list = me.notebooks[data.list] || me.notebooks[me.selected_list];
            content = $('#notecontent').val(data.description);
        $('.notetitle', rcmail.gui_objects.noteviewtitle).val(data.title);
        $('.dates .notecreated', rcmail.gui_objects.noteviewtitle).html(Q(data.created || ''));
        $('.dates .notechanged', rcmail.gui_objects.noteviewtitle).html(Q(data.changed || ''));
        if (data.created || data.changed) {
            $('.dates', rcmail.gui_objects.noteviewtitle).show();
        }

        $(rcmail.gui_objects.noteseditform).show();

        // tag-edit line
        var tagline = $('.tagline', rcmail.gui_objects.noteviewtitle).empty().show();
        $.each(typeof data.categories == 'object' && data.categories.length ? data.categories : [''], function(i,val){
            $('<input>')
                .attr('name', 'tags[]')
                .attr('tabindex', '2')
                .addClass('tag')
                .val(val)
                .appendTo(tagline);
        });

        if (!data.categories || !data.categories.length) {
            $('<span>').addClass('placeholder').html(rcmail.gettext('notags', 'kolab_notes')).appendTo(tagline);
        }

        $('.tagline input.tag', rcmail.gui_objects.noteviewtitle).tagedit({
            animSpeed: 100,
            allowEdit: false,
            checkNewEntriesCaseSensitive: false,
            autocompleteOptions: { source: tags, minLength: 0, noCheck: true },
            texts: { removeLinkTitle: rcmail.gettext('removetag', 'kolab_notes') }
        })

        $('.tagedit-list', rcmail.gui_objects.noteviewtitle)
            .on('click', function(){ $('.tagline .placeholder').hide(); });

        me.selected_note = data;
        me.selected_note.id = rcmail.html_identifier_encode(data.uid);
        rcmail.enable_command('save', list.editable && !data.readonly);

        var html, node, editor = tinyMCE.get('notecontent');
        if (editor) {
            html = data.html || data.description;

            // convert plain text to HTML and make URLs clickable
            if (!data.html || !html.match(/<(html|body)/)) {
                html = text2html(html);
            }

            editor.setContent(html);
            node = editor.getContentAreaContainer().childNodes[0];
            if (node) node.tabIndex = content.get(0).tabIndex;
            editor.getBody().focus();
        }

        // Trigger resize (needed for proper editor resizing)
        $(window).resize();
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
    function render_tagslist(newtags, replace)
    {
        if (replace) {
            tags = newtags;
        }
        else {
            var append = [];
            for (var i=0; i < newtags.length; i++) {
                if ($.inArray(newtags[i], tags) < 0)
                    append.push(newtags[i]);
            }
            if (!append.length) {
                update_tagcloud();
                return;  // nothing to be added
            }
            tags = tags.concat(append);
        }

        // sort tags first
        tags.sort(function(a,b){
            return a.toLowerCase() > b.toLowerCase() ? 1 : -1;
        })

        var widget = $(rcmail.gui_objects.notestagslist).html('');

        // append tags to tag cloud
        $.each(tags, function(i, tag){
            li = $('<li>').attr('rel', tag).data('value', tag)
                .html(Q(tag) + '<span class="count"></span>')
                .appendTo(widget)
/*
                .draggable({
                    addClasses: false,
                    revert: 'invalid',
                    revertDuration: 300,
                    helper: tag_draggable_helper,
                    start: tag_draggable_start,
                    appendTo: 'body',
                    cursor: 'pointer'
                });
*/
        });

        update_tagcloud();
    }

    /**
     * Display the given counts to each tag and set those inactive which don't
     * have any matching records in the current view.
     */
    function update_tagcloud(counts)
    {
        // compute counts first by iterating over all visible task items
        if (typeof counts == 'undefined') {
            counts = {};
            $.each(notesdata, function(id, rec){
                for (var t, j=0; rec && rec.categories && j < rec.categories.length; j++) {
                    t = rec.categories[j];
                    if (typeof counts[t] == 'undefined')
                        counts[t] = 0;
                    counts[t]++;
                }
            });
        }

        $(rcmail.gui_objects.notestagslist).children('li').each(function(i,li){
            var elem = $(li), tag = elem.attr('rel'),
                count = counts[tag] || 0;

            elem.children('.count').html(count+'');
            if (count == 0) elem.addClass('inactive');
            else            elem.removeClass('inactive');

            if (tagsfilter && tagsfilter.length && $.inArray(tag, tagsfilter)) {
                elem.addClass('selected');
            }
        });
    }

    /**
     * Callback from server after saving a note record
     */
    function update_note(data)
    {
        data.id = rcmail.html_identifier_encode(data.uid);

        var row, is_new = notesdata[data.id] == undefined
        notesdata[data.id] = data;
        render_note(data);

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
            $('.title', row.obj).html(Q(data.title));
            $('.date', row.obj).html(Q(data.changed || ''));
            // TODO: move to top
        }

        render_tagslist(data.categories || []);
    }

    /**
     * 
     */
    function reset_view()
    {
        me.selected_note = null;
        $('.notetitle', rcmail.gui_objects.noteviewtitle).val('');
        $('.tagline, .dates', rcmail.gui_objects.noteviewtitle).hide();
        $(rcmail.gui_objects.noteseditform).hide();
        rcmail.enable_command('save', false);
    }

    /**
     * Collect data from the edit form and submit it to the server
     */
    function save_note()
    {
        if (!me.selected_note) {
            return false;
        }

        var editor = tinyMCE.get('notecontent');
        var savedata = {
            title: trim($('.notetitle', rcmail.gui_objects.noteviewtitle).val()),
            description: editor ? editor.getContent({ format:'html' }) : $('#notecontent').val(),
            list: me.selected_note.list || me.selected_list,
            uid: me.selected_note.uid,
            categories: []
        };

        // collect tags
        $('.tagedit-list input[type="hidden"]', rcmail.gui_objects.noteviewtitle).each(function(i, elem){
            if (elem.value)
                savedata.categories.push(elem.value);
        });
        // including the "pending" one in the text box
        var newtag = $('#tagedit-input').val();
        if (newtag != '') {
            savedata.categories.push(newtag);
        }

        // do some input validation
        if (savedata.title == '') {
            alert(rcmail.gettext('entertitle', 'kolab_notes'))
            return false;
        }

        rcmail.lock_form(rcmail.gui_objects.noteseditform, true);
        saving_lock = rcmail.set_busy(true, 'kolab_notes.savingdata');
        rcmail.http_post('action', { _data: savedata, _do: savedata.uid?'edit':'new' }, true);
    }

    /**
     *
     */
    function delete_notes()
    {
        if (!noteslist.selection.length) {
            return false;
        }

        if (confirm(rcmail.gettext('deletenotesconfirm','kolab_notes'))) {
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

            saving_lock = rcmail.set_busy(true, 'kolab_notes.savingdata');
            rcmail.http_post('action', { _data: { uid: uids.join(','), list: me.selected_list }, _do: 'delete' }, true);

            reset_view();
            update_tagcloud();
        }
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

}


/* notes plugin UI initialization */
var kolabnotes;
window.rcmail && rcmail.addEventListener('init', function(evt) {
  kolabnotes = new rcube_kolab_notes_ui(rcmail.env.kolab_notes_settings);
  kolabnotes.init();
});

