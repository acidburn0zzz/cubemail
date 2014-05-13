/**
 * Kolab groupware folders treelist widget
 *
 * @author Thomas Bruederli <bruederli@kolabsys.com>
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

function kolab_folderlist(node, p)
{
    // extends treelist.js
    rcube_treelist_widget.call(this, node, p);

    // private vars
    var me = this;
    var search_results;
    var search_results_widget;
    var search_results_container;
    var listsearch_request;

    var Q = rcmail.quote_html;

    // render the results for folderlist search
    function render_search_results(results)
    {
        if (results.length) {
          // create treelist widget to present the search results
          if (!search_results_widget) {
              search_results_container = $('<div class="searchresults"></div>')
                  .html('<h2 class="boxtitle">' + rcmail.gettext('calsearchresults','calendar') + '</h2>')
                  .insertAfter(me.container);

              search_results_widget = new rcube_treelist_widget('<ul class="treelist listing"></ul>', {
                  id_prefix: p.id_prefix,
                  selectable: false
              });

              // register click handler on search result's checkboxes to select the given item for listing
              search_results_widget.container
                  .appendTo(search_results_container)
                  .on('click', 'input[type=checkbox]', function(e) {
                      if (!this.checked)
                          return;

                      var li = $(this).closest('li'),
                          id = li.attr('id').replace(new RegExp('^'+p.id_prefix), ''),
                          prop = search_results[id],
                          parent_id = prop.parent || null;

                      // find parent node and insert at the right place
                      if (parent_id && $('#' + p.id_prefix + parent_id, me.container).length) {
                          prop.listname = prop.editname;
                          li.children().first().children('span,a').first().html(Q(prop.listname));
                      }

                      // move this result item to the main list widget
                      me.insert({
                          id: id,
                          classes: [],
                          html: li.children().first()
                      }, parent_id, parent_id ? true : false);

                      delete prop.html;
                      me.triggerEvent('insert-item', { id: id, data: prop, item: li });
                      li.remove();
                  });
          }

          // add results to list
          for (var prop, i=0; i < results.length; i++) {
              prop = results[i];
              search_results[prop.id] = prop;
              $('<li>')
                  .attr('id', p.id_prefix + prop.id)
                  .html(prop.html)
                  .appendTo(search_results_widget.container);
          }

          search_results_container.show();
        }
    }

    // do some magic when search is performed on the widget
    this.addEventListener('search', function(search) {
        // hide search results
        if (search_results_widget) {
            search_results_container.hide();
            search_results_widget.reset();
        }
        search_results = {};

        // send search request(s) to server
        if (search.query && search.execute) {
            var sources = [ 'folders' /*, 'users'*/ ];
            var reqid = rcmail.multi_thread_http_request({
                items: sources,
                threads: rcmail.env.autocomplete_threads || 1,
                action:  p.search_action || 'listsearch',
                postdata: { action:'search', q:search.query, source:'%s' },
                lock: rcmail.display_message(rcmail.get_label('searching'), 'loading'),
                onresponse: render_search_results
            });

            listsearch_request = { id:reqid, sources:sources.slice(), num:sources.length };
        }
    });

}

// link prototype from base class
kolab_folderlist.prototype = rcube_treelist_widget.prototype;
