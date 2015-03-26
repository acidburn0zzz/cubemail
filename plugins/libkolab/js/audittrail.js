/**
 * Kolab groupware audit trail utilities
 *
 * @author Thomas Bruederli <bruederli@kolabsys.com>
 *
 * @licstart  The following is the entire license notice for the
 * JavaScript code in this file.
 *
 * Copyright (C) 2015, Kolab Systems AG <contact@kolabsys.com>
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

var libkolab_audittrail = {}

libkolab_audittrail.quote_html = function(str)
{
    return String(str).replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;');
};


// show object changelog in a dialog
libkolab_audittrail.object_history_dialog = function(p)
{
    // render dialog
    var $dialog = $(p.container);

    // close show dialog first
    if ($dialog.is(':ui-dialog'))
        $dialog.dialog('close');

    var buttons = {};
    buttons[rcmail.gettext('close')] = function() {
        $dialog.dialog('close');
    };

    // hide and reset changelog table
    $dialog.find('div.notfound-message').remove();
    $dialog.find('.changelog-table').show().children('tbody')
        .html('<tr><td colspan="6"><span class="loading">' + rcmail.gettext('loading') + '</span></td></tr>');

    // open jquery UI dialog
    $dialog.dialog({
        modal: false,
        resizable: true,
        closeOnEscape: true,
        title: p.title,
        open: function() {
            $dialog.attr('aria-hidden', 'false');
            setTimeout(function(){
                $dialog.parent().find('.ui-dialog-buttonpane .ui-button').first().focus();
            }, 5);
        },
        close: function() {
            $dialog.dialog('destroy').attr('aria-hidden', 'true').hide();
        },
        buttons: buttons,
        minWidth: 450,
        width: 650,
        height: 350,
        minHeight: 200,
    })
    .show().children('.compare-button').hide();

    // initialize event handlers for history dialog UI elements
    if (!$dialog.data('initialized')) {
      // compare button
      $dialog.find('.compare-button input').click(function(e) {
        var rev1 = $dialog.find('.changelog-table input.diff-rev1:checked').val(),
          rev2 = $dialog.find('.changelog-table input.diff-rev2:checked').val();

          if (rev1 && rev2 && rev1 != rev2) {
            // swap revisions if the user got it wrong
            if (rev1 > rev2) {
              var tmp = rev2;
              rev2 = rev1;
              rev1 = tmp;
            }

            if (p.comparefunc) {
                p.comparefunc(rev1, rev2);
            }
          }
          else {
              alert('Invalid selection!')
          }

          if (!rcube_event.is_keyboard(e) && this.blur) {
              this.blur();
          }
          return false;
      });

      // delegate handlers for list actions
      $dialog.find('.changelog-table tbody').on('click', 'td.actions a', function(e) {
          var link = $(this),
            action = link.hasClass('restore') ? 'restore' : 'show',
            event = $('#eventhistory').data('event'),
            rev = link.attr('data-rev');

            // ignore clicks on first row (current revision)
            if (link.closest('tr').hasClass('first')) {
                return false;
            }

            // let the user confirm the restore action
            if (action == 'restore' && !confirm(rcmail.gettext('revisionrestoreconfirm', p.module).replace('$rev', rev))) {
                return false;
            }

            if (p.listfunc) {
                p.listfunc(action, rev);
            }

            if (!rcube_event.is_keyboard(e) && this.blur) {
                this.blur();
            }
            return false;
      })
      .on('click', 'input.diff-rev1', function(e) {
          if (!this.checked) return true;

          var rev1 = this.value, selection_valid = false;
          $dialog.find('.changelog-table input.diff-rev2').each(function(i, elem) {
              $(elem).prop('disabled', elem.value <= rev1);
              if (elem.checked && elem.value > rev1) {
                  selection_valid = true;
              }
          });
          if (!selection_valid) {
              $dialog.find('.changelog-table input.diff-rev2:not([disabled])').last().prop('checked', true);
          }
      });

      $dialog.addClass('changelog-dialog').data('initialized', true);
    }

    return $dialog;
};

// callback from server with changelog data
libkolab_audittrail.render_changelog = function(data, object, folder)
{
    var Q = libkolab_audittrail.quote_html;

    var $dialog = $('.changelog-dialog')
    if (data === false || !data.length) {
        return false;
    }

    var i, change, accessible, op_append,
      first = data.length - 1, last = 0,
      is_writeable = !!folder.editable,
      op_labels = { APPEND: 'actionappend', MOVE: 'actionmove', DELETE: 'actiondelete' },
      actions = '<a href="#show" class="iconbutton preview" title="'+ rcmail.gettext('showrevision',data.module) +'" data-rev="{rev}" /> ' +
          (is_writeable ? '<a href="#restore" class="iconbutton restore" title="'+ rcmail.gettext('restore',data.module) + '" data-rev="{rev}" />' : ''),
      tbody = $dialog.find('.changelog-table tbody').html('');

    for (i=first; i >= 0; i--) {
        change = data[i];
        accessible = change.date && change.user;

        if (change.op == 'MOVE' && change.mailbox) {
            op_append = ' ⇢ ' + change.mailbox;
        }
        else {
            op_append = '';
        }

        $('<tr class="' + (i == first ? 'first' : (i == last ? 'last' : '')) + (accessible ? '' : 'undisclosed') + '">')
            .append('<td class="diff">' + (accessible && change.op != 'DELETE' ? 
                '<input type="radio" name="rev1" class="diff-rev1" value="' + change.rev + '" title="" '+ (i == last ? 'checked="checked"' : '') +' /> '+
                '<input type="radio" name="rev2" class="diff-rev2" value="' + change.rev + '" title="" '+ (i == first ? 'checked="checked"' : '') +' /></td>'
                : ''))
            .append('<td class="revision">' + Q(i+1) + '</td>')
            .append('<td class="date">' + Q(change.date || '') + '</td>')
            .append('<td class="user">' + Q(change.user || 'undisclosed') + '</td>')
            .append('<td class="operation" title="' + op_append + '">' + Q(rcmail.gettext(op_labels[change.op] || '', data.module) + (op_append ? ' ...' : '')) + '</td>')
            .append('<td class="actions">' + (accessible && change.op != 'DELETE' ? actions.replace(/\{rev\}/g, change.rev) : '') + '</td>')
            .appendTo(tbody);
    }

    if (first > 0) {
        $dialog.find('.compare-button').fadeIn(200);
        $dialog.find('.changelog-table tr.last input.diff-rev1').click();
    }

    return $dialog;
};