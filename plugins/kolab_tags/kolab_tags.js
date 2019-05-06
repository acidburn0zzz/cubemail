/**
 * Kolab Tags plugin
 *
 * @author Aleksander Machniak <machniak@kolabsys.com>
 *
 * @licstart  The following is the entire license notice for the
 * JavaScript code in this file.
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
 *
 * @licend  The above is the entire license notice
 * for the JavaScript code in this file.
 */

window.rcmail && rcmail.addEventListener('init', function() {
    if (/^(mail|notes|tasks)$/.test(rcmail.task)) {
        var msg_view = rcmail.env.action == 'show' || rcmail.env.action == 'preview';

        if (!msg_view && rcmail.env.action) {
            return;
        }

        // load tags cloud
        if (rcmail.gui_objects.taglist) {
            // Tags for kolab_notes plugin have to be initialized via an event
            if (rcmail.task == 'notes' && !window.kolabnotes) {
                rcmail.addEventListener('kolab-notes-init', load_tags);
            }
            else {
                load_tags();
            }
        }

        // display tags in message subject (message window)
        if (msg_view) {
            rcmail.enable_command('tag-add', true);
            rcmail.enable_command('tag-remove', 'tag-remove-all', rcmail.env.tags.length);
            message_tags(rcmail.env.message_tags);
        }

        // register events related to messages list
        if (rcmail.message_list) {
            rcmail.addEventListener('listupdate', message_list_update_tags);
            rcmail.addEventListener('requestsearch', search_request);
            rcmail.addEventListener('requestlist', search_request);
            rcmail.message_list.addEventListener('select', function(o) { message_list_select(o); });
        }

        // register commands
        rcmail.register_command('manage-tags', function() { manage_tags(); }, true);
        rcmail.register_command('reset-tags', function() { reset_tags(); });
        rcmail.register_command('tag-add', function(props, obj, event) { tag_add(props, obj, event); });
        rcmail.register_command('tag-remove', function(props, obj, event) { tag_remove(props, obj, event); });
        rcmail.register_command('tag-remove-all', function() { tag_remove('*'); });

        // Disable tag functionality in contextmenu in Roundcube < 1.4
        if (!rcmail.env.rcversion) {
            rcmail.addEventListener('menu-open', function(e) {
                if (e.name == 'rcm_markmessagemenu') {
                    $.each(['add', 'remove', 'remove-all'], function() {
                        $('a.cmd_tag-' + this).parent().hide();
                    });
                }
            });
        }
        // Set activation state for contextmenu entries related with tags management
        else {
            rcmail.addEventListener('contextmenu_init', function(menu) {
                if (menu.menu_name == 'folderlist') {
                    menu.addEventListener('activate', function(p) {
                        if (p.command == 'manage-tags') {
                            return true;
                        }
                        if (p.command == 'reset-tags') {
                            return !!(tagsfilter.length && main_list_widget());
                        }
                    });
                }
            });
        }

        // ajax response handler
        rcmail.addEventListener('plugin.kolab_tags', update_tags);

        // select current messages list filter, this need to be done here
        // because we modify $_SESSION['search_filter']
        if (rcmail.env.search_filter_selected && rcmail.gui_objects.search_filter) {
            $(rcmail.gui_objects.search_filter).val(rcmail.env.search_filter_selected)
                // update selection in decorated select
                .filter('.decorated').each(function() {
                    var title = $('option:selected', this).text();
                    $('a.menuselector span', $(this).parent()).text(title);
                });
        }

        // Allow other plugins to update tags list
        rcmail.addEventListener('kolab-tags-counts', update_counts)
            .addEventListener('kolab-tags-refresh', refresh_tags);
    }
});

var tag_selector_element, tag_form_data, tag_form_save_func,
    tagsfilter = [], 
    tagscounts = [],
    reset_css = {color: '', backgroundColor: ''};

function main_list_widget()
{
    if (rcmail.task == 'mail' && rcmail.message_list)
        return rcmail.message_list;

    if (rcmail.task == 'notes' && rcmail.noteslist)
        return rcmail.noteslist;

    if (rcmail.task == 'tasks' && rcmail.gui_objects.resultlist)
        return rcmail.gui_objects.resultlist;
}

// fills tag cloud with tags list
function load_tags()
{
    var ul = $('#taglist'), clickable = !!main_list_widget();

    $.each(rcmail.env.tags, function(i, tag) {
        var li = add_tag_element(ul, tag, clickable);

        // remember default color/bg of unselected tag element
        if (!i)
            tag_css = li.css(['color', 'background-color']);

        if (rcmail.env.selected_tags && $.inArray(String(tag.uid), rcmail.env.selected_tags) > -1) {
            li.addClass('selected');
            tag_set_color(li, tag);
            tagsfilter.push(String(tag.uid));
        }
    });

    rcmail.triggerEvent('kolab-tags-update', {});
    rcmail.enable_command('reset-tags', tagsfilter.length && clickable);
}

function add_tag_element(list, tag, clickable)
{
    var element = $('<li>').attr('class', tag_class_name(tag))
        .text(tag.name).data('tag', tag.uid)
        .append('<span class="count">')
        .appendTo(list);

    if (clickable) {
        element.click(function(e) {
            var tagid = element.data('tag');

            if (!tagid)
                return false;

            // reset selection on regular clicks
            var index = $.inArray(tagid, tagsfilter),
                shift = e.shiftKey || e.ctrlKey || e.metaKey,
                t = tag_find(tagid);

            if (!shift) {
                if (tagsfilter.length > 1)
                    index = -1;

                $('li', list).removeClass('selected').css(reset_css);
                tagsfilter = [];
            }

            // add tag to the filter
            if (index < 0) {
                element.addClass('selected');
                tag_set_color(element, t);
                tagsfilter.push(tagid);
            }
            else if (shift) {
                element.removeClass('selected').css(reset_css);
                tagsfilter.splice(index, 1);
            }

            apply_tags_filter();

            // clear text selection in IE after shift+click
            if (shift && document.selection)
                document.selection.empty();

            e.preventDefault();
            return false;
        });

        if (!$('html.touch').length) {
            element.draggable({
                addClasses: false,
                cursor: 'default',
                cursorAt: {left: -10},
                revert: 'invalid',
                revertDuration: 300,
                helper: tag_draggable_helper,
                start: tag_draggable_start,
                appendTo: 'body'
            });
        }
    }

    return element;
}

function update_counts(p)
{
    $('#taglist > li').each(function(i,li) {
        var elem = $(li), tagid = elem.data('tag'),
            tag = tag_find(tagid);
            count = tag && p.counter ? p.counter[tag.name] : '';

            elem.children('.count').text(count ? count : '');
    });

    tagscounts = p.counter;
}

function manage_tags()
{
    // display it as popup
    rcmail.tags_popup = rcmail.show_popup_dialog(
        '<div id="tagsform"><select size="6" multiple="multiple"></select><div class="buttons"></div></div>',
        rcmail.gettext('kolab_tags.tags'),
        [{
            text: rcmail.gettext('save'),
            'class': 'mainaction save',
            click: function() { if (tag_form_save()) $(this).dialog('close'); }
        },
        {
            text: rcmail.gettext('cancel'),
            'class': 'cancel',
            click: function() { $(this).dialog('close'); }
        }],
        {
            width: 400,
            modal: true,
            classes: {'ui-dialog-content': 'formcontent'},
            closeOnEscape: true,
            close: function(e, ui) {
                $(this).remove();
            }
        }
    );

    tag_form_data = {add: {}, 'delete': [], update: {}};
    tag_form_save_func = null;

    var form = $('#tagsform'),
        select = $('select', form),
        buttons = [
            $('<button type="button" class="btn btn-secondary create">')
                .text(rcmail.gettext('kolab_tags.add'))
                .click(function() { tag_form_dialog(); }),
            $('<button type="button" class="btn btn-secondary edit">')
                .text(rcmail.gettext('kolab_tags.edit'))
                .attr('disabled', true)
                .click(function() { tag_form_dialog((select.val())[0]); }),
            $('<button type="button" class="btn btn-danger delete">')
                .text(rcmail.gettext('kolab_tags.delete'))
                .attr('disabled', true)
                .click(function() {
                    $.each(select.val() || [], function(i, v) {
                        $('option[value="' + v + '"]', select).remove();
                        delete tag_form_data.update[v];
                        delete tag_form_data.add[v];
                        if (!/^temp/.test(v))
                            tag_form_data['delete'].push(v);
                    });
                    $(this).prop('disabled', true);
                })
        ];

    select.on('change', function() {
        var selected = $(this).val() || [];
        buttons[1].attr('disabled', selected.length != 1);
        buttons[2].attr('disabled', selected.length == 0);
    });

    $.each(rcmail.env.tags, function(i, v) {
        $('<option>').val(v.uid).text(v.name)
            .attr('class', tag_class_name(v))
            .on('dblclick', function() { tag_form_dialog(v.uid); })
            .appendTo(select);
    });

    $('div.buttons', form).append(buttons);
}

function tag_form_dialog(id)
{
    var tag, form = $('#tagsform'),
        content = $('<div id="tageditform">'),
        row1 = $('<div class="form-group row">'),
        row2 = $('<div class="form-group row">'),
        name_input = $('<input type="text" size="25" id="tag-form-name" class="form-control">'),
        color_input = $('<input type="text" size="6" id="tag-form-color" class="colors form-control">'),
        name_label = $('<label for="tag-form-name" class="col-form-label col-sm-2">')
            .text(rcmail.gettext('kolab_tags.tagname')),
        color_label = $('<label for="tag-form-color" class="col-form-label col-sm-2">')
            .text(rcmail.gettext('kolab_tags.tagcolor'));

    tag_form_save_func = function() {
        var i, tag, name = $.trim(name_input.val()), color = $.trim(color_input.val());

        if (!name) {
            alert(rcmail.gettext('kolab_tags.nameempty'));
            return false;
        }

        // check if specified name already exists
        for (i in rcmail.env.tags) {
            tag = rcmail.env.tags[i];
            if (tag.uid != id) {
                if (tag_form_data.update[tag.uid]) {
                    tag.name = tag_form_data.update[tag.uid].name;
                }

                if (tag.name == name && !tag_form_data['delete'][tag.uid]) {
                    alert(rcmail.gettext('kolab_tags.nameexists'));
                    return false;
                }
            }
        }

        for (i in tag_form_data.add) {
            if (i != id) {
                if (tag_form_data.add[i].name == name) {
                    alert(rcmail.gettext('kolab_tags.nameexists'));
                    return false;
                }
            }
        }

        // check color
        if (color) {
            color = color.toUpperCase();

            if (!color.match(/^#/)) {
                color = '#' + color;
            }

            if (!color.match(/^#[a-f0-9]{3,6}$/i)) {
                alert(rcmail.gettext('kolab_tags.colorinvalid'));
                return false;
            }
        }

        tag = {name: name, color: color};

        if (!id) {
            tag.uid = 'temp' + (new Date()).getTime(); // temp ID
            tag_form_data.add[tag.uid] = tag;
        }
        else {
            tag_form_data[tag_form_data.add[id] ? 'add' : 'update'][id] = tag;
        }

        return true;
    };

    // reset inputs
    if (id) {
        tag = tag_form_data.add[id];
        if (!tag)
            tag = tag_form_data.update[id];
        if (!tag)
            tag = tag_find(id);

        if (tag) {
            name_input.val(tag.name);
            color_input.val(tag.color ? tag.color.replace(/^#/, '') : '');
        }
    }

    // display form
    form.children().hide();
    form.append(content);
    content.append(row1.append(name_label).append($('<div class="col-sm-10">').append(name_input)))
        .append(row2.append(color_label).append($('<div class="col-sm-10">').append(color_input)))
        .show();
    name_input.focus();
    color_input.minicolors(rcmail.env.minicolors_config || {});
}

// save tags form (create/update/delete tags)
function tag_form_save()
{
    if (tag_form_save_func && !tag_form_save_func()) {
        return false;
    }

    var count = 0;

    // check if updates are needed
    tag_form_data.update = $.map(tag_form_data.update, function (t, i) {
        var tag = tag_find(i);

        if (tag.name == t.name && tag.color == t.color)
            return null;

        count++;
        t.uid = i

        return t;
    });

    // check if anything added/deleted
    if (!count) {
        count = tag_form_data['delete'].length || $.makeArray(tag_form_data.add).length;
    }

    if (count) {
        rcmail.http_post('plugin.kolab_tags', tag_form_data, rcmail.display_message(rcmail.get_label('kolab_tags.saving'), 'loading'));
    }

    return true;
}

// ajax response handler
function update_tags(response)
{
    var list = main_list_widget();

    // reset tag selector popup
    tag_selector_reset();

    if (response.refresh) {
        var list = $('#taglist');

        tagsfilter = $.map(list.children('.selected'), function(li) {
            return $(li).data('tag');
        });

        list.html('');
        load_tags();
        update_counts({counter: tagscounts});

        if (tagsfilter.length) {
            list.children('li').each(function() {
                if ($.inArray($(this).data('tag'), tagsfilter) > -1) {
                    $(this).addClass('selected');
                }
            });
            apply_tags_filter();
        }

        return;
    }

    // remove deleted tags
    remove_tags(response['delete'], response.mark);

    // add new tags
    $.each(response.add || [], function() {
        rcmail.env.tags.push(this);
        add_tag_element($('#taglist'), this, list);
        if (response.mark) {
            tag_add_callback(this);
        }
    });

    // update existing tag
    $.each(response.update || [], function() {
        var i, old, tag = this, id = tag.uid,
            filter = function() { return $(this).data('tag') == id; },
            tagbox = $('#taglist li').filter(filter),
            elements = $('.tagbox').filter(filter),
            win = rcmail.get_frame_window(rcmail.env.contentframe),
            framed = win && win.jQuery ? win.jQuery('.tagbox').filter(function() { return win.jQuery(this).data('tag') == id; }) : [];
            selected = $.inArray(String(id), tagsfilter);

        for (i in rcmail.env.tags)
            if (rcmail.env.tags[i].uid == id)
                break;

        old = rcmail.env.tags[i];

        if (tag.name != old.name) {
            tagbox.text(tag.name);
            elements.text(tag.name);
            if (framed.length) {
                framed.text(tag.name);
            }
        }

        if (tag.color != old.color) {
            tagbox.filter('.selected').each(function() { tag_set_color(this, tag); });
            elements.each(function() { tag_set_color(this, tag); });
            if (framed.length) {
                framed.each(function() { tag_set_color(this, tag); });
            }
        }

        rcmail.env.tags[i] = tag;
    });

    rcmail.triggerEvent('kolab-tags-update', {});
    rcmail.enable_command('reset-tags', tagsfilter.length && list);

    // update Mark menu in case some messages are already selected
    if (list && list.selection && list.selection.length) {
        message_list_select(list);
    }

    // @TODO: sort tags by name/prio
}

// internal method to remove tags from messages and tags cloud
function remove_tags(tags, selection)
{
    if (!tags || !tags.length) {
        return;
    }

    var taglist = $('#taglist li'),
        list = main_list_widget(),
        tagboxes = $('span.tagbox'),
        win = rcmail.get_frame_window(rcmail.env.contentframe),
        frame_tagboxes = win && win.jQuery ? win.jQuery('span.tagbox') : [],
        update_filter = false;

    // remove deleted tags
    $.each(tags, function() {
        var i, id = this,
            filter = function() { return $(this).data('tag') == id; };

        // When removing tags from selected messages only, we're using
        // tag_remove_callback(), so here we can ignore that part
        if (!selection) {
            // ... from the messages list (or message page)
            tagboxes.filter(filter).remove();

            // ...from the tag cloud
            taglist.filter(filter).remove();

            // ... from tags list
            for (i in rcmail.env.tags) {
                if (rcmail.env.tags[i].uid == id) {
                    rcmail.env.tags.splice(i, 1);
                    break;
                }
            }

            // ... from the message frame
            if (frame_tagboxes.length) {
                frame_tagboxes.filter(function() { return win.jQuery(this).data('tag') == id; }).remove();
            }
        }

        // if tagged messages found and tag was selected - refresh the list
        if (!update_filter && $.inArray(String(id), tagsfilter) > -1) {
            update_filter = true;
        }
    });

    if (update_filter && list) {
        apply_tags_filter();
    }
}

// kolab-tags-refresh event handler, allowing plugins to refresh
// the tags list e.g. when a new tag is created
function refresh_tags(e)
{
    // find a new tag
    $.each(e.tags || [], function() {
        for (var i in rcmail.env.tags) {
            if (rcmail.env.tags[i].name == this) {
                return;
            }
        }

        rcmail.http_post('plugin.kolab_tags', {_act: 'refresh'}, rcmail.display_message(rcmail.get_label('loading'), 'loading'));
        return false;
    });
}

// unselect all selected tags in the tag cloud
function reset_tags()
{
    $('#taglist li').removeClass('selected').css(reset_css);
    tagsfilter = [];

    apply_tags_filter();
}

// request adding a tag to selected messages
function tag_add(props, obj, event)
{
    if (!props) {
        return tag_selector(event, function(props) { tag_add(props); });
    }

    var tag, postdata = rcmail.selection_post_data();

    // requested new tag?
    if (props.name) {
        postdata._tag = props.name;
        // find the tag by name to be sure it exists or not
        if (props = tag_find(props.name, 'name')) {
            postdata._tag = props.uid;
        }
        else {
            postdata._new = 1;
        }
    }
    else {
        postdata._tag = props;
    }

    postdata._act = 'add';

    rcmail.http_post('plugin.kolab_tags', postdata, true);

    // add tags to message(s) without waiting to a response
    // in case of an error the list will be refreshed
    // this is possible if we use existing tag
    if (!postdata._new && (tag = this.tag_find(props))) {
        tag_add_callback(tag);
    }
}

// update messages list and message frame after tagging
function tag_add_callback(tag)
{
    if (!tag)
        return;

    var frame_window = rcmail.get_frame_window(rcmail.env.contentframe),
        list = rcmail.message_list;

    if (list) {
        $.each(list.get_selection(), function (i, uid) {
            var row = list.rows[uid];
            if (!row)
                return;

            var subject = $('td.subject a', row.obj);

            if ($('span.tagbox', subject).filter(function() { return $(this).data('tag') == tag.uid; }).length) {
                return;
            }

            subject.prepend(tag_box_element(tag));
        });

        message_list_select(list);
    }

    (frame_window && frame_window.message_tags ? frame_window : window).message_tags([tag], true);
}

// request removing tag(s) from selected messages
function tag_remove(props, obj, event)
{
    if (!props) {
        return tag_selector(event, function(props) { tag_remove(props); }, true);
    }

    if (props.name) {
        // find the tag by name, make sure it exists
        props = tag_find(props.name, 'name');
        if (!props) {
            return;
        }

        props = props.uid;
    }

    var postdata = rcmail.selection_post_data(),
        tags = props != '*' ? [props] : $.map(rcmail.env.tags, function(tag) { return tag.uid; }),
        rc = window.parent && parent.rcmail && parent.remove_tags ? parent.rcmail : rcmail;

    postdata._tag = props;
    postdata._act = 'remove';

    rc.http_post('plugin.kolab_tags', postdata, true);

    // remove tags from message(s) without waiting to a response
    // in case of an error the list will be refreshed
    $.each(tags, function() { tag_remove_callback(this); });
}

// update messages list and message frame after removing tag assignments
function tag_remove_callback(tag)
{
    if (!tag)
        return;

    var uids = [],
        win = rcmail.get_frame_window(rcmail.env.contentframe),
        frame_tagboxes = win && win.jQuery ? win.jQuery('span.tagbox') : [],
        list = main_list_widget();

    if (list) {
        $.each(list.get_selection(), function (i, uid) {
            var row = list.rows[uid];
            if (row) {
                $('span.tagbox', row.obj).each(function() {
                    if ($(this).data('tag') == tag) {
                        $(this).remove();
                        uids.push(String(uid));
                    }
                });
            }
        });

        message_list_select(list);
    }
    else {
        $('span.tagbox').filter(function() { return $(this).data('tag') == tag; }).remove();
    }

    // ... from the message frame (make sure it the selected message frame,
    // i.e. when using contextmenu it might be not the selected one)
    if (frame_tagboxes.length && $.inArray(String(win.rcmail.env.uid), uids) >= 0) {
        frame_tagboxes.filter(function() { return win.jQuery(this).data('tag') == tag; }).remove();
    }
}

// executes messages search according to selected messages
function apply_tags_filter()
{
    rcmail.enable_command('reset-tags', tagsfilter.length && main_list_widget());

    if (rcmail.task == 'mail')
        rcmail.qsearch();
    else {
        // Convert tag id to tag label
        var tags = [];
        $.each(rcmail.env.tags, function() {
            if ($.inArray(this.uid, tagsfilter) > -1) {
                tags.push(this.name);
            }
        });

        rcmail.triggerEvent('kolab-tags-search', tags);
    }
}

// adds _tags argument to http search request
function search_request(url)
{
    // remove old tags filter
    if (url._filter) {
        url._filter = url._filter.replace(/^kolab_tags_[0-9]+:[^:]+:/, '');
    }

    if (tagsfilter.length) {
        url._filter = 'kolab_tags_' + (new Date).getTime() + ':' + tagsfilter.join(',') + ':' + (url._filter || 'ALL');

        // force search request, needed to keep tag filter when changing folder
        if (url._page == 1)
            url._action = 'search';

        return url;
    }
}

function message_list_select(list)
{
    var selection = list.get_selection(),
        has_tags = selection.length && rcmail.env.tags.length;

    if (has_tags && !rcmail.select_all_mode) {
        has_tags = false;
        $.each(selection, function() {
            var row = list.rows[this];
            if (row && row.obj && $('span.tagbox', row.obj).length) {
                has_tags = true;
                return false;
            }
        });
    }

    rcmail.enable_command('tag-remove', 'tag-remove-all', has_tags);
    rcmail.enable_command('tag-add', selection.length);
}

// add tags to message subject on message list
function message_list_update_tags(e)
{
    if (!e.rowcount || !rcmail.message_list) {
        return;
    }

    $.each(rcmail.env.message_tags || [], function (uid, tags) {
        // remove folder from UID if in single folder listing
        if (!rcmail.env.multifolder_listing)
            uid = uid.replace(/-.*$/, '');

        var row = rcmail.message_list.rows[uid];
        if (!row)
            return;

        var subject = $('td.subject a', row.obj),
            boxes = [];

        $('span.tagbox', subject).remove();

        $.each(tags, function () {
            var tag = tag_find(this);
            if (tag) {
                boxes.push(tag_box_element(tag));
            }
        });

        subject.prepend(boxes);
    });

    // we don't want to do this on every listupdate event
    rcmail.env.message_tags = null;
}

// add tags to message subject in message preview
function message_tags(tags, merge)
{
    var boxes = [], subject_tag = $('#messageheader .subject,#message-header h2.subject'); // Larry and Elastic

    $.each(tags || [], function (i, tag) {
        if (merge) {
            if ($('span.tagbox', subject_tag).filter(function() { return $(this).data('tag') == tag.uid; }).length) {
                return;
            }
        }

        boxes.push(tag_box_element(tag, true));
    });

    if (boxes.length) {
        subject_tag.prepend(boxes);
    }
}

// return tag info by tag uid
function tag_find(search, key)
{
    if (!key) {
        key = 'uid';
    }

    for (var i in rcmail.env.tags)
        if (rcmail.env.tags[i][key] == search)
            return rcmail.env.tags[i];
}

// create and return tag box element
function tag_box_element(tag, del_btn)
{
    var span = $('<span class="tagbox skip-on-drag"></span>')
        .text(tag.name).data('tag', tag.uid);

    tag_set_color(span, tag);

    if (del_btn) {
        span.append($('<a>').attr({href: '#', title: rcmail.gettext('kolab_tags.untag')})
            .html('&times;').click(function() {
                return rcmail.command('tag-remove', tag.uid, this);
            })
        );
    }

    return span;
}

// set color style on tag box
function tag_set_color(obj, tag)
{
    if (obj && tag.color) {
        if (rcmail.triggerEvent('kolab-tag-color', {obj: obj, tag: tag}) === false) {
            return;
        }

        var style = 'background-color: ' + tag.color + ' !important';

        // choose black text color when background is bright, white otherwise
        if (/^#([a-f0-9]{2})([a-f0-9]{2})([a-f0-9]{2})$/i.test(tag.color)) {
            // use information about brightness calculation found at
            // http://javascriptrules.com/2009/08/05/css-color-brightness-contrast-using-javascript/
            var brightness = (parseInt(RegExp.$1, 16) * 299 + parseInt(RegExp.$2, 16) * 587 + parseInt(RegExp.$3, 16) * 114) / 1000;
            style += '; color: ' + (brightness > 125 ? 'black' : 'white') + ' !important';
        }

        // don't use css() it does not work with "!important" flag
        $(obj).attr('style', style);
    }
}

// create tag selector popup, position and display it
function tag_selector(event, callback, remove_mode)
{
    var container = tag_selector_element,
        max_items = 10;

    if (!container) {
        var rows = [],
            ul = $('<ul class="toolbarmenu menu">'),
            li = document.createElement('li'),
            link = document.createElement('a'),
            span = document.createElement('span');

        link.href = '#';
        container = $('<div id="tag-selector" class="popupmenu"></div>')
            .keydown(function(e) {
                var focused = $('*:focus', container).parent();

                if (e.which == 40) { // Down
                    focused.nextAll('li:visible').first().find('a').focus();
                    return false;
                }
                else if (e.which == 38) { // Up
                    focused.prevAll('li:visible').first().find('input,a').focus();
                    return false;
                }
            });

        // add tag search/create input
        rows.push(tag_selector_search_element(container));

        // loop over tags list
        $.each(rcmail.env.tags, function(i, tag) {
            var a = link.cloneNode(false), row = li.cloneNode(false), tmp = span.cloneNode(false);

            // add tag name element
            $(tmp).text(tag.name);
            $(a).data('uid', tag.uid).attr('class', 'tag active ' + tag_class_name(tag));
            a.appendChild(tmp);

            row.appendChild(a);
            rows.push(row);
        });

        ul.append(rows).appendTo(container);

        // temporarily show element to calculate its size
        container.css({left: '-1000px', top: '-1000px'})
            .appendTo(document.body).show()
            .on('click', 'a.tag', function(e) {
                container.data('callback')($(this).data('uid'));
                return rcmail.hide_menu('tag-selector', e);
            });

        // set max-height if the list is long
        if (rows.length > max_items)
            container.css('max-height', $('li', container)[0].offsetHeight * max_items + (max_items-1))

        tag_selector_element = container;
    }

    container.data('callback', callback);

    rcmail.show_menu('tag-selector', true, event);

    // reset list and search input
    $('li', container).show();
    $('input', container).val('').focus();

    // When displaying tags for remove we hide those that are not in a selected messages set
    if (remove_mode && rcmail.message_list) {
        var tags = [], selection = rcmail.message_list.get_selection();

        if (selection.length
            && selection.length <= rcmail.env.messagecount
            && (!rcmail.select_all_mode || selection.length == rcmail.env.messagecount)
        ) {
            $.each(selection, function (i, uid) {
                var row = rcmail.message_list.rows[uid];
                if (row) {
                   $('span.tagbox', row.obj).each(function() { tags.push($(this).data('tag')); });
                }
            });

            tags = $.uniqueSort(tags);

            $('a', container).each(function() {
                if ($.inArray($(this).data('uid'), tags) == -1) {
                    $(this).parent().hide();
                }
            });
        }

        // we also hide the search input, if there's not many tags left
        if ($('a:visible', container).length < max_items) {
            $('input', container).parents('li').hide();
        }
    }
}

// remove tag selector element (e.g. after adding/removing a tag)
function tag_selector_reset()
{
    $(tag_selector_element).remove();
    tag_selector_element = null;

    // Elastic requires to destroy the menu, otherwise we end up with
    // content element duplicates that are not connected with the menu
    if (window.UI && window.UI.menu_destroy) {
        window.UI.menu_destroy('tag-selector');
    }
}

function tag_selector_search_element(container)
{
    var title = rcmail.gettext('kolab_tags.tagsearchnew'),
        placeholder = rcmail.gettext('kolab_tags.newtag'),
        form = $('<span class="input-group"><span class="input-group-prepend"><i class="input-group-text icon search"></i></span></span>'),
        input = $('<input>').attr({'type': 'text', title: title, placeholder: placeholder, 'class': 'form-control'})
            .keyup(function(e) {
                if (this.value) {
                    // execute action on Enter
                    if (e.which == 13) {
                        container.data('callback')({name: this.value});
                        rcmail.hide_menu('tag-selector', e);
                        if ($('#markmessagemenu').is(':visible')) {
                            rcmail.hide_menu('markmessagemenu', e);
                        }
                    }
                    // search tags
                    else {
                        var search = this.value.toUpperCase();
                        $('li:not(.search)', container).each(function() {
                            var tag_name = $(this).text().toUpperCase();
                            $(this)[tag_name.indexOf(search) >= 0 ? 'show' : 'hide']();
                        });
                    }
                }
                else {
                    // reset search
                    $('li', container).show();
                }
            });

    return $('<li class="search">').append(form.append(input))
        // prevents from closing the menu on click in the input/row
        .on('mouseup click', function(e) { e.stopPropagation(); return false; });
}

function tag_class_name(tag)
{
    return 'kolab-tag-' + tag.uid.replace(/[^a-z0-9]/ig, '');
}

function kolab_tags_input(element, tags, readonly)
{
    var list,
        tagline = $(element)[readonly ? 'addClass' : 'removeClass']('disabled').empty().show(),
        source_callback = function(request, response) {
            request = request.term.toUpperCase();
            response($.map(rcmail.env.tags || [], function(v) {
                if (request.length && v.name.toUpperCase().indexOf(request) > -1)
                    return v.name;
            }));
        };

    $.each(tags && tags.length ? tags : [''], function(i,val) {
        $('<input>').attr({name: 'tags[]', tabindex: '0', 'class': 'tag'})
            .val(val)
            .appendTo(tagline);
    });

    if (!tags || !tags.length) {
        $('<span>').addClass('placeholder')
            .text(rcmail.gettext('kolab_tags.notags'))
            .appendTo(tagline)
            .click(function(e) { list.trigger('click'); });
    }

    $('input.tag', element).tagedit({
        animSpeed: 100,
        allowEdit: false,
        allowAdd: !readonly,
        allowDelete: !readonly,
        checkNewEntriesCaseSensitive: false,
        autocompleteOptions: { source: source_callback, minLength: 0, noCheck: true },
        texts: { removeLinkTitle: rcmail.gettext('kolab_tags.untag') }
    });

    list = element.find('.tagedit-list');

    list.addClass('form-control'); // Elastic

    if (!readonly) {
        list.on('click', function() { $('.placeholder', element).hide(); });
    }

    // Track changes on the list to style tag elements
    if (window.MutationObserver) {
        var observer = new MutationObserver(kolab_tags_input_update);
        observer.observe(list[0], {childList: true});
    }
    else {
        list.on('click keyup', kolab_tags_input_update);
    }

    kolab_tags_input_update({target: list});

    return list;
}

function kolab_tags_input_update(e)
{
    var tags = {},
        target = e.length && e[0].target ? e[0].target : e.target;

    // first generate tags list indexed by name
    $.each(rcmail.env.tags, function(i, tag) {
        tags[tag.name] = tag;
    });

    $(target).find('li.tagedit-listelement-old:not([class*="tagbox"])').each(function() {
        var text = $('span', this).text();

        $(this).addClass('tagbox');

        if (tags[text]) {
            $(this).data('tag', tags[text].uid);
            tag_set_color(this, tags[text]);
        }
    });
}

function kolab_tags_input_value(element)
{
    var tags = [];

    $(element || '.tagedit-list').find('input').each(function(i, elem) {
        if (elem.value)
            tags.push(elem.value);
    });

    return tags;
}

function tag_draggable_helper()
{
    var draghelper = $('.tag-draghelper'),
        tagid = $(this).data('tag'),
        node = $(this).clone(),
        tagbox = $('<span class="tagbox">');

    if (!draghelper.length) {
        draghelper = $('<div class="tag-draghelper"></div>');
    }

    $('span.count', node).remove();
    tagbox.text(node.text()).appendTo(draghelper.html(''));
    tag_set_color(tagbox, tag_find(tagid));

    return draghelper[0];
}

function tag_draggable_start(event, ui)
{
    if (rcmail.gui_objects.noteslist) {
        // register notes list to receive drop events
        $('tr', rcmail.gui_objects.noteslist).droppable({
            addClasses: false,
            hoverClass: 'droptarget',
            accept: tag_droppable_accept,
            drop: tag_draggable_dropped
        });

        // allow to drop tags onto note edit form
        $('body.task-notes .content.formcontainer,#notedetailstitle.boxtitle').droppable({
            addClasses: false,
            accept: function() { return $(this).is('.formcontainer,.boxtitle'); },
            drop: function(event, ui) {
                var tag = tag_find(ui.draggable.data('tag'));
                $('#tagedit-input').val(tag.name).trigger('transformToTag');
                $('.tagline .placeholder', rcmail.gui_objects.noteviewtitle).hide();
            }
        }).addClass('tag-droppable');
    }
    else if (rcmail.task == 'tasks' && rcmail.gui_objects.resultlist) {

        $('div.taskhead', rcmail.gui_objects.resultlist).droppable({
            addClasses: false,
            hoverClass: 'droptarget',
            accept: tag_droppable_accept,
            drop: tag_draggable_dropped
        });


        // allow to drop tags onto task edit form
        $('body.task-tasks .content.formcontainer').droppable({
            addClasses: false,
            accept: function() { return $(this).is('.formcontainer') && $('#taskedit').is(':visible'); },
            drop: function(event, ui) {
                var tag = tag_find(ui.draggable.data('tag'));
                $('#tagedit-input').val(tag.name).trigger('transformToTag');
            }
        }).addClass('tag-droppable');
    }
}

function tag_draggable_dropped(event, ui)
{
    var drop_id = tag_draggable_target_id(this),
        tag = tag_find(ui.draggable.data('tag')),
        list = $(this).parent();

    rcmail.triggerEvent('kolab-tags-drop', {id: drop_id, tag: tag.name, list: list});
}

function tag_droppable_accept(draggable)
{
    if (rcmail.busy) {
        return false;
    }

    var drop_id = tag_draggable_target_id(this),
        tag = tag_find(draggable.data('tag')),
        data = rcmail.triggerEvent('kolab-tags-drop-data', {id: drop_id});

    // target already has this tag assigned
    if (!data || !tag || (data.tags && $.inArray(tag.name, data.tags) >= 0)) {
        return false;
    }

    return true;
}

function tag_draggable_target_id(elem)
{
    var id = $(elem).attr('id');

    if (id) {
        id = id.replace(/^rcmrow/, ''); // Notes
    }
    else {
        id = $(elem).parent().attr('rel'); // Tasks
    }

    return id;
}

// Convert list of tag names into "blocks" and add to the specified element
function kolab_tags_text_block(tags, element, del_btn)
{
    if (tags && tags.length) {
        var items = [];

        tags.sort();

        $.each(tags, function(i,val) {
            var tag = tag_find(val, 'name');
            if (tag) {
                items.push(tag_box_element(tag, del_btn));
            }
        });

        $(element).append(items);
    }
};
