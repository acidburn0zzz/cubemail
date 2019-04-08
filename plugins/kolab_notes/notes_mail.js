/**
 * Mail integration script for the Kolab Notes plugin
 *
 * @author Thomas Bruederli <bruederli@kolabsys.com>
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

window.rcmail && rcmail.addEventListener('init', function(evt) {
    /**
     * Open the notes edit GUI in a jquery UI dialog
     */
    function kolab_note_dialog(url)
    {
        if (!url) url = {};
        url._framed = 1;

        var $dialog, frame, buttons = {},
            button_classes = ['mainaction save'],
            edit = url._id,
            title = edit ? rcmail.gettext('kolab_notes.editnote') : rcmail.gettext('kolab_notes.appendnote'),
            dialog_render = function(p) {
                $dialog.parent().find('.ui-dialog-buttonset button')
                    .prop('disabled', p.readonly)
                    .last().prop('disabled', false);
            };

        $dialog = $('<iframe>').attr({
                id: 'kolabnotesinlinegui',
                name: 'kolabnotesdialog',
                src: rcmail.url('notes/dialog-ui', url)
            }).on('load', function(e) {
                frame = rcmail.get_frame_window('kolabnotesinlinegui');
                frame.rcmail.addEventListener('responseafteraction', refresh_mailview);
            });

        // subscribe event in parent window which is also triggered from iframe
        // (probably before the 'load' event from above)
        rcmail.addEventListener('kolab_notes_render', dialog_render);

        // dialog buttons
        buttons[rcmail.gettext('save')] = function() {
            // frame is not loaded
            if (!frame)
                return;

            frame.rcmail.command('save');
        };

        if (edit) {
            button_classes.push('delete');
            buttons[rcmail.gettext('delete')] = function() {
                if (confirm(rcmail.gettext('deletenotesconfirm','kolab_notes'))) {
                    rcmail.addEventListener('responseafteraction', refresh_mailview);
                    rcmail.http_post('notes/action', { _data: { uid: url._id, list: url._list }, _do: 'delete' }, true);
                    $dialog.dialog('destroy');
                }
            };
        }

        button_classes.push('cancel');
        buttons[rcmail.gettext(edit ? 'close' : 'cancel')] = function() {
            $dialog.dialog('destroy');
        };

        // open jquery UI dialog
        window.kolab_note_dialog_element = $dialog = rcmail.show_popup_dialog($dialog, title, buttons, {
            button_classes: button_classes,
            close: function() {
                rcmail.removeEventListener('kolab_notes_render', dialog_render);
            },
            minWidth: 500,
            width: 600,
            height: 500
        });
    }

    /**
     * Reload the mail view/preview to update the notes listing
     */
    function refresh_mailview(e)
    {
        var win = rcmail.env.contentframe ? rcmail.get_frame_window(rcmail.env.contentframe) : window;
        if (win && e.response) {
            win.location.reload();
            if (e.response.action == 'action')
                $('#kolabnotesinlinegui').dialog('destroy');
        }
    }

    // register commands
    rcmail.register_command('edit-kolab-note', kolab_note_dialog, true);
    rcmail.register_command('append-kolab-note', function() {
        var uid = rcmail.get_single_uid();
        if (uid) {
            kolab_note_dialog({ _msg: uid + '-' + rcmail.env.mailbox });
        }
    });

    if (rcmail.env.action == 'show') {
        rcmail.enable_command('append-kolab-note', true);
    }
    else {
        rcmail.env.message_commands.push('append-kolab-note');
    }

    // register handlers for inline note editing
    if (rcmail.env.action == 'show' || rcmail.env.action == 'preview') {
        $('.kolabmessagenotes a.kolabnotesref').click(function(e){
            var ref = String($(this).attr('rel')).split('@'),
                win = rcmail.is_framed() ? parent.window : window;
            win.rcmail.command('edit-kolab-note', { _list:ref[1], _id:ref[0] });
            return false;
        });
    }
});
