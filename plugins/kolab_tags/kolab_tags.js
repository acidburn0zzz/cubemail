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
    if (rcmail.task == 'mail') {
        var msg_view = rcmail.env.action == 'show' || rcmail.env.action == 'preview';

        if (!msg_view && rcmail.env.action) {
            return;
        }

        // load tags cloud
        if (rcmail.gui_objects.taglist) {
            load_tags();
        }

        // display tags in message subject (message window)
        if (msg_view) {
            rcmail.enable_command('tag-add', 'tag-remove', 'tag-remove-all', rcmail.env.tags.length);
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
    }
});

var tagsfilter = [], tag_selector_element, tag_form_data, tag_form_save_func,
    reset_css = {color: '', backgroundColor: ''};

// fills tag cloud with tags list
function load_tags()
{
    var ul = $('#taglist'), clickable = rcmail.message_list;

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

    rcmail.enable_command('reset-tags', tagsfilter.length && clickable);
}

function add_tag_element(list, tag, clickable)
{
    // @todo: .append('<span class="count"></span>')
    var element = $('<li>').text(tag.name).data('tag', tag.uid).appendTo(list);

    if (clickable) {
        element.click(function(e) {
            var item = $(this),
                tagid = item.data('tag');

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
                item.addClass('selected');
                tag_set_color(item, t);
                tagsfilter.push(tagid);
            }
            else if (shift) {
                item.removeClass('selected').css(reset_css);
                tagsfilter.splice(index, 1);
            }

            apply_tags_filter();

            // clear text selection in IE after shift+click
            if (shift && document.selection)
                document.selection.empty();

            e.preventDefault();
            return false;
        })
    }

    return element;
}

function manage_tags()
{
    // display it as popup
    rcmail.tags_popup = rcmail.show_popup_dialog(
        '<div id="tagsform"><select size="8" multiple="multiple"></select><div class="buttons"></div></div>',
        rcmail.gettext('kolab_tags.tags'),
        [{
            text: rcmail.gettext('save'),
            click: function() { if (tag_form_save()) $(this).dialog('close'); }
        },
        {
            text: rcmail.gettext('cancel'),
            click: function() { $(this).dialog('close'); }
        }],
        {
            width: 400,
            modal: true,
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
            $('<input type="button" />').val(rcmail.gettext('kolab_tags.add'))
                .click(function() { tag_form_dialog(); }),
            $('<input type="button" />').val(rcmail.gettext('kolab_tags.edit'))
                .attr('disabled', true)
                .click(function() { tag_form_dialog((select.val())[0]); }),
            $('<input type="button" />').val(rcmail.gettext('kolab_tags.delete'))
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
            .on('dblclick', function() { tag_form_dialog(v.uid); })
            .appendTo(select);
    });

    $('div.buttons', form).append(buttons);
}

function tag_form_dialog(id)
{
    var tag, form = $('#tagsform'),
        content = $('<div id="tageditform"></div>'),
        name_input = $('<input type="text" size="25" id="tag-form-name" />'),
        color_input = $('<input type="text" size="6" class="colors" id="tag-form-color" />'),
        name_label = $('<label for="tag-form-name">').text(rcmail.gettext('kolab_tags.tagname')),
        color_label = $('<label for="tag-form-color">').text(rcmail.gettext('kolab_tags.tagcolor'));

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
    content.append([name_label, name_input, '<br>', color_label, color_input]).show();
    name_input.focus();
    color_input.miniColors({colorValues: rcmail.env.mscolors});
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
    var list = rcmail.message_list;

    // remove deleted tags
    remove_tags(response['delete']);

    // add new tags
    $.each(response.add || [], function() {
        rcmail.env.tags.push(this);
        add_tag_element($('#taglist'), this, list);
    });

    // update existing tag
    $.each(response.update || [], function() {
        var i, old, tag = this, id = tag.uid,
            filter = function() { return $(this).data('tag') == id; },
            tagbox = $('#taglist li').filter(filter),
            elements = $('span.tagbox').filter(filter),
            win = rcmail.get_frame_window(rcmail.env.contentframe),
            framed = win && win.jQuery ? win.jQuery('span.tagbox').filter(function() { return win.jQuery(this).data('tag') == id; }) : [];
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

    rcmail.enable_command('reset-tags', tagsfilter.length && list);

    // update Mark menu in case some messages are already selected
    if (list && list.selection.length) {
        message_list_select(list);
    }

    // reset tag selector popup
    tag_selector_element = null;

    // @TODO: sort tags by name/prio
}

// internal method to remove tags from messages and optionally tags cloud
function remove_tags(tags, selection)
{
    if (!tags || !tags.length) {
        return;
    }

    var taglist = $('#taglist li'),
        tagboxes = $((selection && rcmail.message_list ? 'tr.selected ' : '') + 'span.tagbox'),
        win = rcmail.get_frame_window(rcmail.env.contentframe),
        frame_tagboxes = win && win.jQuery ? win.jQuery('span.tagbox') : [],
        update_filter = false;

    // remove deleted tags
    $.each(tags, function() {
        var i, id = this,
            filter = function() { return $(this).data('tag') == id; },
            elements = tagboxes.filter(filter),
            selected = $.inArray(String(id), tagsfilter);

        // ... from the messages list (or message page)
        elements.remove();

        if (!selection) {
            // ...from the tag cloud
            taglist.filter(filter).remove();

            // ... from tags list
            for (i in rcmail.env.tags) {
                if (rcmail.env.tags[i].uid == id) {
                    rcmail.env.tags.splice(i, 1);
                    break;
                }
            }
        }

        // ... from the message frame
        if (frame_tagboxes.length) {
            frame_tagboxes.filter(function() { return win.jQuery(this).data('tag') == id; }).remove();
        }

        // if tagged messages found and tag was selected - refresh the list
        if (selected > -1) {
            tagsfilter.splice(selected, 1);
            update_filter = true;
        }
    });

    if (update_filter && rcmail.message_list) {
        apply_tags_filter();
    }
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
        return tag_selector(event, function(props) { rcmail.command('tag-add', props); });
    }

    var postdata = rcmail.selection_post_data(),
        tag = this.tag_find(props),
        frame_window = rcmail.get_frame_window(rcmail.env.contentframe);

    postdata._tag = props;
    postdata._act = 'add';

    rcmail.http_post('plugin.kolab_tags', postdata, true);

    // add tags to message(s) without waiting to a response
    // in case of an error the list will be refreshed
    if (rcmail.message_list) {
        $.each(rcmail.message_list.get_selection(), function (i, uid) {
            var row = rcmail.message_list.rows[uid];
            if (!row)
                return;

            var subject = $('td.subject > a', row.obj);

            if ($('span.tagbox', subject).filter(function() { return $(this).data('tag') == tag.uid; }).length) {
                return;
            }

            subject.prepend(tag_box_element(tag));
        });
    }

    (frame_window && frame_window.message_tags ? frame_window : window).message_tags([tag], true);
}

// request removing tag(s) from selected messages
function tag_remove(props, obj, event)
{
    if (!props) {
        return tag_selector(event, function(props) { rcmail.command('tag-remove', props); });
    }

    var postdata = rcmail.selection_post_data(),
        tags = props != '*' ? [props] : $.map(rcmail.env.tags, function(tag) { return tag.uid; })
        win = window.parent && parent.rcmail && parent.remove_tags ? parent : window;

    postdata._tag = props;
    postdata._act = 'remove';

    rcmail.http_post('plugin.kolab_tags', postdata, true);

    // remove tags from message(s) without waiting to a response
    // in case of an error the list will be refreshed
    win.remove_tags(tags, true);
}

// executes messages search according to selected messages
function apply_tags_filter()
{
    rcmail.enable_command('reset-tags', tagsfilter.length && rcmail.message_list);
    rcmail.qsearch();
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

        return url;
    }
}

function message_list_select(list)
{
    rcmail.enable_command('tag-add', 'tag-remove', 'tag-remove-all', rcmail.env.tags.length && list.selection.length);
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

        var subject = $('td.subject > a', row.obj),
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
}

// add tags to message subject in message preview
function message_tags(tags, merge)
{
    var boxes = [], subject_tag = $('#messageheader .subject');

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
function tag_find(uid)
{
    for (var i in rcmail.env.tags)
        if (rcmail.env.tags[i].uid == uid)
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
function tag_selector(event, callback)
{
    var container = tag_selector_element;

    if (!container) {
        var rows = [],
            ul = $('<ul class="toolbarmenu">'),
            li = document.createElement('li'),
            link = document.createElement('a'),
            span = document.createElement('span');

        container = $('<div id="tag-selector" class="popupmenu"></div>');
        link.href = '#';
        link.className = 'active';

        // loop over tags list
        $.each(rcmail.env.tags, function(i, tag) {
            var a = link.cloneNode(false), row = li.cloneNode(false);

            a.onclick = function(e) {
                container.data('callback')(tag.uid);
                return rcmail.hide_menu('tag-selector', e);
            };

            // add tag name element
            tmp = span.cloneNode(false);
            $(tmp).text(tag.name);
            a.appendChild(tmp);

            row.appendChild(a);
            rows.push(row);
        });

        ul.append(rows).appendTo(container);

        // temporarily show element to calculate its size
        container.css({left: '-1000px', top: '-1000px'})
            .appendTo(document.body).show();

        // set max-height if the list is long
        if (rows.length > 10)
            container.css('max-height', $('li', container)[0].offsetHeight * 10 + 9)

        tag_selector_element = container;
    }

    container.data('callback', callback);

    rcmail.show_menu('tag-selector', true, event);
}
