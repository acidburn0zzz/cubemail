/**
 * Client script for the Kolab address book plugin
 *
 * @author Aleksander Machniak <machniak@kolabsys.com>
 * @author Thomas Bruederli <bruederli@kolabsys.com>
 *
 * @licstart  The following is the entire license notice for the
 * JavaScript code in this file.
 *
 * Copyright (C) 2011-2014, Kolab Systems AG <contact@kolabsys.com>
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

if (window.rcmail) {
    rcmail.addEventListener('init', function() {
        rcmail.set_book_actions();
        if (rcmail.gui_objects.editform && rcmail.env.action.match(/^plugin\.book/)) {
            rcmail.enable_command('book-save', true);
        }

        // add contextmenu items
        if (window.rcm_contextmenu_register_command) {
            var menu = $('#rcmGroupMenu');
            rcm_contextmenu_register_command(
                'book-edit',
                function(cmd,el){ rcmail.book_edit() },
                'kolab_addressbook.bookedit',
                null,
                true,
                false,
                false,
                menu
            );
            rcm_contextmenu_register_command(
                'book-delete',
                function(cmd,el){ rcmail.book_delete() },
                'kolab_addressbook.bookdelete',
                null,
                false,
                false,
                false,
                menu
            );

            if (rcmail.env.kolab_addressbook_carddav_url) {
                rcm_contextmenu_register_command(
                    'book-showurl',
                    function(cmd,el){ rcmail.book_showurl() },
                    'kolab_addressbook.bookshowurl',
                    null,
                    false,
                    false,
                    false,
                    menu
                );
            }

            // adjust menu items when shown
            rcmail.addEventListener('contextmenu_show', function(p){
                if (p.menu.attr('id') != 'rcmGroupMenu')
                    return;

                var m = String(p.src.attr('id')).match(/rcmli([a-z0-9\-_=]+)/i),
                    source = m && m.length ? rcmail.html_identifier_decode(m[1]) : null,
                    sources = rcmail.env.address_sources,
                    editable = source && sources[source] && sources[source].kolab && sources[source].editable,
                    showurl = source && sources[source] && sources[source].carddavurl;

                if (p.menu) {
                    p.menu[editable ? 'enableContextMenuItems' : 'disableContextMenuItems']('#book-edit,#book-delete');
                    p.menu[showurl  ? 'enableContextMenuItems' : 'disableContextMenuItems']('#book-showurl');
                }
            });
        }
    });
    rcmail.addEventListener('listupdate', function() {
        rcmail.set_book_actions();
    });
}

// (De-)activates address book management commands
rcube_webmail.prototype.set_book_actions = function()
{
    var source = this.env.source,
        sources = this.env.address_sources;

    this.enable_command('book-create', true);
    this.enable_command('book-edit', 'book-delete', source && sources[source] && sources[source].kolab && sources[source].editable);
    this.enable_command('book-showurl', source && sources[source] && sources[source].carddavurl);
};

rcube_webmail.prototype.book_create = function()
{
    this.book_show_contentframe('create');
};

rcube_webmail.prototype.book_edit = function()
{
    this.book_show_contentframe('edit');
};

rcube_webmail.prototype.book_delete = function()
{
    if (this.env.source != '' && confirm(this.get_label('kolab_addressbook.bookdeleteconfirm'))) {
        var lock = this.set_busy(true, 'kolab_addressbook.bookdeleting');
        this.http_request('plugin.book', '_act=delete&_source='+urlencode(this.book_realname()), lock);
    }
};

rcube_webmail.prototype.book_showurl = function()
{
    var source = this.env.source ? this.env.address_sources[this.env.source] : null;
    if (source && source.carddavurl) {
        $('div.showurldialog:ui-dialog').dialog('close');

        var $dialog = $('<div>').addClass('showurldialog').append('<p>'+rcmail.gettext('carddavurldescription', 'kolab_addressbook')+'</p>'),
            textbox = $('<textarea>').addClass('urlbox').css('width', '100%').attr('rows', 2).appendTo($dialog);

          $dialog.dialog({
            resizable: true,
            closeOnEscape: true,
            title: rcmail.gettext('bookshowurl', 'kolab_addressbook'),
            close: function() {
              $dialog.dialog("destroy").remove();
            },
            width: 520
          }).show();

          textbox.val(source.carddavurl).select();
    }
};

// displays page with book edit/create form
rcube_webmail.prototype.book_show_contentframe = function(action, framed)
{
    var add_url = '', target = window;

    // unselect contact
    this.contact_list.clear_selection();
    this.enable_command('edit', 'delete', 'compose', false);

    if (this.env.contentframe && window.frames && window.frames[this.env.contentframe]) {
        add_url = '&_framed=1';
        target = window.frames[this.env.contentframe];
        this.show_contentframe(true);
    }
    else if (framed)
        return false;

    if (action) {
        this.lock_frame();
        this.location_href(this.env.comm_path+'&_action=plugin.book&_act='+action
            +'&_source='+urlencode(this.book_realname())
            +add_url, target);
    }

    return true;
};

// submits book create/update form
rcube_webmail.prototype.book_save = function()
{
    var form = this.gui_objects.editform,
        input = $("input[name='_name']", form)

    if (input.length && input.val() == '') {
        alert(this.get_label('kolab_addressbook.nobooknamewarning'));
        input.focus();
        return;
    }

    input = this.display_message(this.get_label('kolab_addressbook.booksaving'), 'loading');
    $('<input type="hidden" name="_unlock" />').val(input).appendTo(form);

    form.submit();
};

// action executed after book delete
rcube_webmail.prototype.book_delete_done = function(id, recur)
{
    var n, groups = this.env.contactgroups,
        sources = this.env.address_sources,
        olddata = sources[id];

    this.treelist.remove(id);

    for (n in groups)
        if (groups[n].source == id) {
            delete this.env.contactgroups[n];
            delete this.env.contactfolders[n];
        }

    delete this.env.address_sources[id];
    delete this.env.contactfolders[id];

    if (recur)
        return;

    this.enable_command('group-create', 'book-edit', 'book-delete', false);

    // remove subfolders
    olddata.realname += this.env.delimiter;
    for (n in sources)
        if (sources[n].realname && sources[n].realname.indexOf(olddata.realname) == 0)
            this.book_delete_done(n, true);
};

// action executed after book create/update
rcube_webmail.prototype.book_update = function(data, old)
{
    var link, classes = [(data.group || ''), 'addressbook'];

    this.show_contentframe(false);

    // set row attributes
    if (data.readonly)
        classes.push('readonly');
    if (data.group)
        classes.push(data.group);

    link = $('<a>').html(data.name)
      .attr({
        href: this.url('', { _source: data.id }),
        rel: data.id,
        onclick: "return rcmail.command('list', '" + data.id + "', this)"
      });

    // update (remove old row)
    if (old) {
      this.treelist.update(old, { id: data.id, html:link, classes: classes, parent:(old != data.id ? data.parent : null) }, data.group || true);
    }
    else {
      this.treelist.insert({ id: data.id, html:link, classes: classes, childlistclass: 'groups' }, data.parent, data.group || true);
    }

    this.env.contactfolders[data.id] = this.env.address_sources[data.id] = data;

    // updated currently selected book
    if (this.env.source != '' && this.env.source == old) {
        this.treelist.select(data.id);
        this.env.source = data.id;
    }
};

// returns real IMAP folder name
rcube_webmail.prototype.book_realname = function()
{
    var source = this.env.source, sources = this.env.address_sources;
    return source != '' && sources[source] && sources[source].realname ? sources[source].realname : '';
};
