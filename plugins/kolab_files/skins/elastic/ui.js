function kolab_files_enable_command(p)
{
    if (p.command == 'files-save') {
        var toolbar = $('#toolbar-menu');
        $('a.edit', toolbar).parent().hide();
        $('a.save', toolbar).show().parent().show();

        if (window.editor_edit_button)
            window.editor_edit_button.addClass('hidden');
        if (window.editor_save_button)
            window.editor_save_button.removeClass('hidden');
    }
    else if (p.command == 'files-edit' && p.status) {
        if (window.editor_edit_button)
            window.editor_edit_button.removeClass('hidden');
    }
};

function kolab_files_listoptions(type)
{
    var content = $('#' + type + 'listoptions'),
        width = content.width() + 25,
        dialog = content.clone(true),
        title = rcmail.gettext('kolab_files.arialabel' + (type == 'sessions' ? 'sessions' : '') + 'listoptions'),
        close_func = function() { rcmail[type + 'list'].focus(); },
        save_func = function(e) {
            if (rcube_event.is_keyboard(e.originalEvent)) {
                $('#' + type + 'listmenu-link').focus();
            }

            var col = $('select[name="sort_col"]', dialog).val(),
                ord = $('select[name="sort_ord"]', dialog).val();

            kolab_files_set_list_options([], col, ord, type);
            close_func();
            return true;
        };

    // set form values
    $('select[name="sort_col"]', dialog).val(rcmail.env[type + '_sort_col'] || 'name');
    $('select[name="sort_ord"]', dialog).val(rcmail.env[type + '_sort_order'] == 'DESC' ? 'DESC' : 'ASC');

    dialog = rcmail.simple_dialog(dialog, title, save_func, {
        cancel_func: close_func,
        closeOnEscape: true,
        minWidth: 400,
        width: width
    });
};

function kolab_files_members_list(link)
{
    var dialog = $('<div id="members-dialog" class="session-members"><ul></ul></div>'),
        title = $(link).text(),
        add_button = $('#collaborators a.add'),
        save_func = function(e) {
            add_button.click();
            return true;
        };

    if (add_button.is('.disabled')) {
        save_func = null;
    }

    $('#members img').each(function() {
        var cloned = $(this).clone();
        $('<li>').append(cloned).append($('<span>').text(this.title))
            .appendTo(dialog.find('ul'));
    });

    dialog = rcmail.simple_dialog(dialog, title, save_func, {
        closeOnEscape: true,
        width: 400,
        button: 'kolab_files.addparticipant',
        button_class: 'participant add',
        cancel_button: 'close'
    });
};


if (rcmail.env.action == 'open' || rcmail.env.action == 'edit') {
    rcmail.addEventListener('enable-command', kolab_files_enable_command);

    if (rcmail.env.action == 'open') {
        $('#toolbar-menu a.save').parent().hide();
    }
    else if (rcmail.env.action == 'edit') {
        if (rcmail.env.editor_type == 'wopi' && rcmail.is_framed()) {
            parent.$('.ui-dialog:visible .ui-dialog-buttonpane').addClass('hidden');
        }

        rcmail.gui_object('exportmenu', 'export-menu');
    }

    // center and scale the image in preview frame
    if (rcmail.env.mimetype.startsWith('image/')) {
        $('#fileframe').on('load', function() {
            var css = 'img { max-width:100%; max-height:100%; } ' // scale
                + 'body { display:flex; align-items:center; justify-content:center; height:100%; margin:0; }'; // align

            $(this).contents().find('head').append('<style type="text/css">'+ css + '</style>');
        });
    }

    // Elastic mobile preview uses an iframe in a dialog
    if ((rcmail.env.action == 'open' || rcmail.env.editor_type != 'wopi') && rcmail.is_framed()) {
        var edit_button = $('#filetoolbar a.edit'),
            save_button = $('#filetoolbar a.save');

        parent.$('.ui-dialog:visible .ui-dialog-buttonpane .ui-dialog-buttonset').prepend(
            window.editor_save_button = $('<button type="button">')
                .addClass('save btn btn-secondary' + (save_button.is('.disabled') ? ' hidden' : ''))
                .text(save_button.text())
                .on('click', function() { save_button.click(); })
        );

        parent.$('.ui-dialog:visible .ui-dialog-buttonpane .ui-dialog-buttonset').prepend(
            window.editor_edit_button = $('<button type="button">')
                .addClass('edit btn btn-secondary' + (edit_button.is('.disabled') ? ' hidden' : ''))
                .text(edit_button.text())
                .on('click', function() { edit_button.click(); })
        );
    }
}
else {
    rcmail.addEventListener('files-folder-select', function(p) {
        var fname, is_sess = p.folder == 'folder-collection-sessions';

        $('#fileslistmenu-link, #layout-content > .pagenav, #layout-content .searchbar')[is_sess ? 'hide' : 'show']();
        $('#sessionslistmenu-link')[is_sess ? 'removeClass' : 'addClass']('hidden');

        if (is_sess)
            fname = rcmail.gettext('kolab_files.sessions');
        else if (p.folder.match(/^folder-collection-([a-z]+)$/))
            fname = rcmail.gettext('kolab_files.collection_' + RegExp.$1);
        else
            fname = p.folder.split(file_api.env.directory_separator).pop();

        // jump to files list and set list header title for mobile
        rcmail.triggerEvent('show-content', {title: fname});
    });
}

$(document).ready(function() {
    if ($('#dragfilemenu').length) {
        rcmail.gui_object('file_dragmenu', 'dragfilemenu');
    }

    if ($('#filesearchmenu').length) {
        rcmail.gui_object('file_searchmenu', 'filesearchmenu');
    }
});

kolab_files_upload_input('#filesuploadform');
