/* Setting read or unread from a tile, without leaving the shelf.
 *
 * The form underneath does the same thing on its own: it posts, the endpoint
 * answers with a redirect back to this very address, filters and page number
 * intact. That path is what runs with scripting off and it is not touched
 * here. What this saves is the reload, which matters because the whole point
 * is a run of presses: twenty reloads of a shelf of sixty covers is a
 * different thing from twenty presses.
 */
(function () {
  'use strict';

  var shelf = document.querySelector('ul.shelf');
  var strings = document.getElementById('shelf-i18n');
  if (!shelf || !strings || !window.fetch) { return; }

  var text = JSON.parse(strings.textContent);

  shelf.addEventListener('submit', function (event) {
    var form = event.target;
    if (!form.classList || !form.classList.contains('tile-status')) { return; }
    event.preventDefault();

    var button = form.querySelector('button');
    var field = form.querySelector('[name="status"]');
    if (!button || !field || button.disabled) { return; }

    button.disabled = true;

    fetch(form.action, {
      method: 'POST',
      body: new FormData(form),
      headers: { 'X-Requested-With': 'fetch' },
      credentials: 'same-origin'
    }).then(function (response) {
      return response.json();
    }).then(function (data) {
      if (!data || !data.status) { throw new Error('no status'); }
      apply(form, data.status);
    }).catch(function () {
      /* The one honest fallback: hand it to the form. The page reloads,
         which is what would have happened without any of this, and the book
         still gets its status. */
      form.submit();
    }).then(function () {
      button.disabled = false;
    });
  });

  /* What the tile shows afterwards: the dot that marks an unread book, and a
     button offering the other direction. Rebuilt here rather than fetched,
     because it is two attributes and a character. */
  function apply(form, status) {
    var tile = form.closest('.book');
    var link = tile.querySelector('a');
    var dot = tile.querySelector('.badge-unread');
    var field = form.querySelector('[name="status"]');
    var mark = form.querySelector('.tile-status-mark');
    var label = form.querySelector('.visually-hidden');
    var button = form.querySelector('button');

    if (status === 'unread' && !dot) {
      dot = document.createElement('span');
      dot.className = 'badge-unread';
      dot.title = text.unread;
      link.appendChild(dot);
    } else if (status !== 'unread' && dot) {
      dot.remove();
    }

    var wantsRead = status !== 'read';
    field.value = wantsRead ? 'read' : 'unread';
    mark.textContent = wantsRead ? '✓' : '↺';
    label.textContent = wantsRead ? text.markRead : text.markUnread;
    button.title = wantsRead ? text.markRead : text.markUnread;
  }
})();
