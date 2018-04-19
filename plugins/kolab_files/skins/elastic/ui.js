function kolab_files_enable_command(p)
{
  if (p.command == 'files-save') {
    var toolbar = $('#toolbar-menu');
    $('a.button.edit', toolbar).parent().hide();
    $('a.button.save', toolbar).show().parent().show();
  }
};

function kolab_files_show_listoptions(p)
{
  if (!p || !p.name) {
    return;
  }

  if (p.name.match(/^(files|sessions)listmenu$/)) {
    var type = RegExp.$1;
  }
  else {
    return;
  }

  var $dialog = $('#' + type + 'listoptions');

  // close the dialog
  if ($dialog.is(':visible')) {
    $dialog.dialog('close');
    return;
  }

  // set form values
  $('input[name="sort_col"][value="'+rcmail.env[type + '_sort_col']+'"]', $dialog).prop('checked', true);
  $('input[name="sort_ord"][value="DESC"]', $dialog).prop('checked', rcmail.env[type + '_sort_order'] == 'DESC');
  $('input[name="sort_ord"][value="ASC"]', $dialog).prop('checked', rcmail.env[type + '_sort_order'] != 'DESC');

  // set checkboxes
  $('input[name="list_col[]"]').each(function() {
    $(this).prop('checked', $.inArray(this.value, rcmail.env[type + '_coltypes']) != -1);
  });

  $dialog.dialog({
    modal: true,
    resizable: false,
    closeOnEscape: true,
    close: function() { rcmail[type + 'list'].focus(); },
    title: null,
    minWidth: 400,
    width: $dialog.width()+20
  }).show();
};

function kolab_files_save_listoptions(p)
{
  if (!p || !p.originalEvent) {
    return;
  }

  if (p.originalEvent.target.id.match(/^(files|sessions)listmenusave$/)) {
    var type = RegExp.$1;
  }
  else {
    return;
  }

  var dialog = $('#' + type + 'listoptions').dialog('close');
  var sort = $('input[name="sort_col"]:checked', dialog).val(),
    ord = $('input[name="sort_ord"]:checked', dialog).val(),
    cols = $('input[name="list_col[]"]:checked', dialog)
      .map(function() { return this.value; }).get();

  kolab_files_set_list_options(cols, sort, ord, type);
};


if (rcmail.env.action == 'open' || rcmail.env.action == 'edit') {
    rcmail.addEventListener('enable-command', kolab_files_enable_command);

    if ($('#exportmenu').length)
        rcmail.gui_object('exportmenu', 'exportmenu');

    // center and scale the image in preview frame
    if (rcmail.env.mimetype.startsWith('image/')) {
        $('#fileframe').on('load', function() {
            var css = 'img { max-width:100%; max-height:100%; } ' // scale
                + 'body { display:flex; align-items:center; justify-content:center; height:100%; margin:0; }'; // align

            $(this).contents().find('head').append('<style type="text/css">'+ css + '</style>');
        });
    }
}

$(document).ready(function() {
    rcmail.addEventListener('menu-open', kolab_files_show_listoptions);
    rcmail.addEventListener('menu-save', kolab_files_save_listoptions);
    rcmail.addEventListener('menu-close', kolab_files_show_listoptions);

    $('#toolbar-menu a.button.save').parent().hide();

    if ($('#dragfilemenu').length) {
        rcmail.gui_object('file_dragmenu', 'dragfilemenu');
    }

    if ($('#filesearchmenu').length) {
        rcmail.gui_object('file_searchmenu', 'filesearchmenu');
    }
});

kolab_files_upload_input('#filesuploadform');
