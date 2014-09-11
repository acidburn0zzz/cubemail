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

        // contextmenu
        kolab_addressbook_contextmenu();

        // append search form for address books
        if (rcmail.gui_objects.folderlist) {
            var container = $(rcmail.gui_objects.folderlist);
            $('<div class="listsearchbox" style="display:none">' +
                '<div class="searchbox" role="search" aria-labelledby="aria-labelfoldersearchform" aria-controls="' + rcmail.gui_objects.folderlist.id + '">' +
                    '<h3 id="aria-label-labelfoldersearchform" class="voice">' + rcmail.gettext('foldersearchform', 'kolab_addressbook') + '" /></h3>' +
                    '<label for="addressbooksearch" class="voice">' + rcmail.gettext('searchterms', 'kolab_addressbook') + '</label>' +
                    '<input type="text" name="q" id="addressbooksearch" placeholder="' + rcmail.gettext('findaddressbooks', 'kolab_addressbook') + '" />' +
                    '<a class="iconbutton searchicon"></a>' +
                    '<a href="#reset" onclick="return rcmail.command(\'reset-listsearch\',null,this,event)" id="directorylistsearch-reset" class="iconbutton reset" title="' + rcmail.gettext('resetsearch') + '">' +
                        rcmail.gettext('resetsearch') + '</a>' +
                '</div>' +
            '</div>')
            .insertBefore(container.parent());

            $('<a href="#search" class="iconbutton search" title="' + rcmail.gettext('findaddressbooks', 'kolab_addressbook') + '" tabindex="0">' +
                rcmail.gettext('findaddressbooks', 'kolab_addressbook') + '</a>')
                .appendTo('#directorylistbox h2.boxtitle')
                .click(function(e){
                    var title = $('#directorylistbox .boxtitle'),
                        box = $('#directorylistbox .listsearchbox'),
                        dir = box.is(':visible') ? -1 : 1;

                    box.slideToggle({
                        duration: 160,
                        progress: function(animation, progress) {
                            if (dir < 0) progress = 1 - progress;
                            $('#directorylistbox .scroller').css('top', (title.outerHeight() + 34 * progress) + 'px');
                        },
                        complete: function() {
                            box.toggleClass('expanded');
                            if (box.is(':visible')) {
                                box.find('input[type=text]').focus();
                            }
                            else {
                                $('#directorylistsearch-reset').click();
                            }
                        }
                    });
                });


            // remove event handlers set by the regular treelist widget
            rcmail.treelist.container.off('click mousedown focusin focusout');

            // re-initialize folderlist widget
            // copy form app.js with additional parameters
            var widget_class = window.kolab_folderlist || rcube_treelist_widget;
            rcmail.treelist = new widget_class(rcmail.gui_objects.folderlist, {
                selectable: true,
                id_prefix: 'rcmli',
                id_encode: rcmail.html_identifier_encode,
                id_decode: rcmail.html_identifier_decode,
                searchbox: '#addressbooksearch',
                search_action: 'plugin.book-search',
                search_sources: [ 'folders', 'users' ],
                search_title: rcmail.gettext('listsearchresults','kolab_addressbook'),
                check_droptarget: function(node) { return !node.virtual && rcmail.check_droptarget(node.id) }
            });

            rcmail.treelist
                .addEventListener('collapse',  function(node) { rcmail.folder_collapsed(node) })
                .addEventListener('expand',    function(node) { rcmail.folder_collapsed(node) })
                .addEventListener('select',    function(node) { rcmail.triggerEvent('selectfolder', { folder:node.id, prefix:'rcmli' }) })
                .addEventListener('subscribe', function(node) {
                    var source;
                    if ((source = rcmail.env.address_sources[node.id])) {
                        source.subscribed = node.subscribed || false;
                        rcmail.http_post('plugin.book-subscribe', { _source:node.id, _permanent:source.subscribed?1:0 });
                    }
                })
                .addEventListener('remove', function(node) {
                    if (rcmail.env.address_sources[node.id]) {
                        rcmail.book_remove(node.id);
                    }
                })
                .addEventListener('insert-item', function(data) {
                    // register new address source
                    rcmail.env.address_sources[data.id] = rcmail.env.contactfolders[data.id] = data.data;
                    // subscribe folder and load groups to add them to the list
                    if (!data.data.virtual)
                      rcmail.http_post('plugin.book-subscribe', { _source:data.id, _permanent:data.data.subscribed?1:0, _groups:1 });
                })
                .addEventListener('search-complete', function(data) {
                    if (data.length)
                        rcmail.display_message(rcmail.gettext('nraddressbooksfound','kolab_addressbook').replace('$nr', data.length), 'voice');
                    else
                        rcmail.display_message(rcmail.gettext('noaddressbooksfound','kolab_addressbook'), 'info');
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
    var source = !this.env.group ? this.env.source : null,
        sources = this.env.address_sources;

    this.enable_command('book-create', true);
    this.enable_command('book-edit', 'book-delete', source && sources[source] && sources[source].kolab && sources[source].editable);
    this.enable_command('book-remove', source && sources[source] && sources[source].kolab && sources[source].removable);
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

rcube_webmail.prototype.book_remove = function(id)
{
    if (!id) id = this.env.source;
    if (id != '' && rcmail.env.address_sources[id]) {
        rcmail.book_delete_done(id, true);
        rcmail.http_post('plugin.book-subscribe', { _source:id, _permanent:0, _recursive:1 });
    }
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
    var classes = ['addressbook'],
        content = $('<div class="subscribed">').append(
            $('<a>').html(data.listname).attr({
                href: this.url('', {_source: data.id}),
                id: 'kabt:' + data.id,
                rel: data.id,
                onclick: "return rcmail.command('list', '" + data.id + "', this)"
            }),
            $('<span>').attr({
                'class': 'subscribed',
                role: 'checkbox',
                'aria-checked': true,
                title: this.gettext('kolab_addressbook.foldersubscribe')
            })
        );

    this.show_contentframe(false);

    // set row attributes
    if (data.readonly)
        classes.push('readonly');
    if (data.group)
        classes.push(data.group);

    // update (remove old row)
    if (old) {
        // is the folder subscribed?
        if (!data.subscribed) {
            content.removeClass('subscribed').find('span').attr('aria-checked', false);
        }

        this.treelist.update(old, {id: data.id, html: content, classes: classes, parent: (old != data.id ? data.parent : null)}, data.group || true);
    }
    else {
        this.treelist.insert({id: data.id, html: content, classes: classes, childlistclass: 'groups'}, data.parent, data.group || true);
    }

    this.env.contactfolders[data.id] = this.env.address_sources[data.id] = data;

    // updated currently selected book
    if (this.env.source != '' && this.env.source == old) {
        this.treelist.select(data.id);
        this.env.source = data.id;
    }

    // update contextmenu
    kolab_addressbook_contextmenu();
};

// returns real IMAP folder name
rcube_webmail.prototype.book_realname = function()
{
    var source = this.env.source, sources = this.env.address_sources;
    return source != '' && sources[source] && sources[source].realname ? sources[source].realname : '';
};

function kolab_addressbook_contextmenu()
{
    if (!window.rcm_callbackmenu_init) {
        return;
    }

    if (!rcmail.env.kolab_addressbook_contextmenu) {
        // adjust default addressbook menu actions
        rcmail.addEventListener('contextmenu_init', function(menu) {
            if (menu.menu_name == 'abooklist') {
                menu.addEventListener('activate', function(p) {
                    // deactivate kolab addressbook actions
                    if (p.command.match(/^book-/)) {
                        return p.command == 'book-create';
                    }
                });
            }
        });
    }

    rcmail.env.kolab_addressbook_contextmenu = true;

    // add menu on kolab addressbooks
    var menu = rcm_callbackmenu_init({
            menu_name: 'kolab_abooklist',
            mouseover_timeout: -1, // no submenus here
            menu_source: ['#directorylist-footer', '#groupoptionsmenu']
        }, {
            'activate': function(p) {
                var source = !rcmail.env.group ? rcmail.env.source : null,
                    sources = rcmail.env.address_sources;

                if (p.command == 'book-create') {
                    return true;
                }

                if (p.command == 'book-edit' || p.command == 'book-delete' || p.command == 'group-create') {
                    return !!(source && sources[source] && sources[source].kolab && sources[source].editable);
                }

                if (p.command == 'book-remove') {
                    return !!(source && sources[source] && sources[source].kolab && sources[source].removable);
                }

                if (p.command == 'book-showurl') {
                    return !!(source && sources[source] && sources[source].carddavurl);
                }

                if (p.command == 'group-rename' || p.command == 'group-delete') {
                    return !!(rcmail.env.group && sources[rcmail.env.source] && sources[rcmail.env.source].editable);
                }

                return false;
            },
            'beforeactivate': function(p) {
                // remove dummy items
                $('li.submenu', p.ref.container).remove();

                rcmail.env.kolab_old_source = rcmail.env.source;
                rcmail.env.kolab_old_group = rcmail.env.group;

                var elem = $(p.source), onclick = elem.attr('onclick');
                if (onclick && onclick.match(rcmail.context_menu_command_pattern)) {
                    rcmail.env.source = RegExp.$2;
                    rcmail.env.group = null;
                }
                else if (elem.parent().hasClass('contactgroup')) {
                    var grp = String(elem.attr('rel')).split(':');
                    rcmail.env.source = grp[0];
                    rcmail.env.group = grp[1];
                }
            },
            'aftercommand': function(p) {
                rcmail.env.source = rcmail.env.kolab_old_source;
                rcmail.env.group = rcmail.env.kolab_old_group;
            }
        }
    );

    $('#directorylist').off('contextmenu').on('contextmenu', 'div > a, li.contactgroup > a', function(e) {
        $(this).blur();
        rcm_show_menu(e, this, $(this).attr('rel'), menu);
    });
};
