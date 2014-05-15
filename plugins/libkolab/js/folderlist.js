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
                  .html(p.search_title ? '<h2 class="boxtitle">' + p.search_title + '</h2>' : '')
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
                          id = li.attr('id').replace(new RegExp('^'+p.id_prefix), '')
                          node = search_results_widget.get_node(id),
                          has_children = node.children && node.children.length;

                      // copy item to the main list
                      add_result2list(id, li, true);

                      if (has_children) {
                          li.find('input[type=checkbox]').first().prop('disabled', true).get(0).checked = true;
                      }
                      else {
                          li.remove();
                      }
                  });
          }

          // add results to list
          for (var prop, item, i=0; i < results.length; i++) {
              prop = results[i];
              item = $(prop.html);
              search_results[prop.id] = prop;
              search_results_widget.insert({
                  id: prop.id,
                  classes: prop.class_name ? String(prop.class_name).split(' ') : [],
                  html: item,
                  collapsed: true
              }, prop.parent);

              // disable checkbox if item already exists in main list
              if (me.get_node(prop.id) && !me.get_node(prop.id).virtual) {
                  item.find('input[type=checkbox]').first().prop('disabled', true).get(0).checked = true;
              }
          }

          search_results_container.show();
        }
    }

    // helper method to (recursively) add a search result item to the main list widget
    function add_result2list(id, li, active)
    {
        var node = search_results_widget.get_node(id),
            prop = search_results[id],
            parent_id = prop.parent || null,
            has_children = node.children && node.children.length,
            dom_node = has_children ? li.children().first().clone(true, true) : li.children().first();

        // find parent node and insert at the right place
        if (parent_id && me.get_node(parent_id)) {
            dom_node.children('span,a').first().html(Q(prop.editname));
        }
        else if (parent_id && search_results[parent_id]) {
            // copy parent tree from search results
            add_result2list(parent_id, $(search_results_widget.get_item(parent_id)), false);
        }
        else if (parent_id) {
            // use full name for list display
            dom_node.children('span,a').first().html(Q(prop.name));
        }

        // replace virtual node with a real one
        if (me.get_node(id)) {
            $(me.get_item(id, true)).children().first()
                .replaceWith(dom_node)
                .removeClass('virtual');
        }
        else {
            // move this result item to the main list widget
            me.insert({
                id: id,
                classes: [],
                virtual: prop.virtual,
                html: dom_node,
            }, parent_id, parent_id ? true : false);
        }

        delete prop.html;
        prop.active = active;
        me.triggerEvent('insert-item', { id: id, data: prop, item: li });
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
            var sources = p.search_sources || [ 'folders' ];
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
