/* Removing a tag, and taking it back, without leaving the list.
 *
 * Both are buttons of form#tag-actions and work without any of this: they
 * post, and the endpoint sends the browser back to the list scrolled to the
 * row. What this saves is the reload, and here a reload costs more than time.
 * The × sits among the genre checkboxes, and reloading throws away every box
 * ticked and not yet saved.
 *
 * The row that comes back is drawn by the server, so its markup exists once.
 * The exception is taking back a removal made on this page: then the row that
 * was taken out is put back as it was, ticks and all.
 */
(function () {
  'use strict';

  var form = document.getElementById('tag-actions');
  var list = document.querySelector('ul.tag-sort');
  if (!form || !list || !window.fetch || !window.FormData) { return; }

  var count = document.querySelector('.page-head .count');
  // Rows taken out on this page, by row id, waiting for an undo.
  var kept = {};

  form.addEventListener('submit', function (event) {
    var button = event.submitter;
    // No submitter to read (an old browser), or not one of the list's own
    // buttons: leave it to the form.
    if (!button || !list.contains(button)) { return; }
    event.preventDefault();
    if (button.disabled) { return; }

    var row = button.closest('li');
    button.disabled = true;

    fetch(button.formAction, {
      method: 'POST',
      body: new FormData(form),
      headers: { 'X-Requested-With': 'fetch' },
      credentials: 'same-origin'
    }).then(function (response) {
      if (!response.ok) { throw new Error('HTTP ' + response.status); }
      return response.json();
    }).then(function (data) {
      if (!data || typeof data.row !== 'string' || data.row === '') { throw new Error('no row'); }
      button.disabled = false;
      swap(row, data.row);
      if (count && data.count) { count.textContent = data.count; }
    }).catch(function () {
      /* Handed to the form, pointed where the button points. Both endpoints
         can be asked twice without harm - removing a removed tag and
         restoring a restored one change nothing - so a request that did
         arrive and only lost its answer does no damage the second time.
         submit() rather than requestSubmit(), which would come straight
         back here. */
      button.disabled = false;
      form.action = button.formAction;
      HTMLFormElement.prototype.submit.call(form);
    });
  });

  function swap(row, html) {
    var holder = document.createElement('template');
    holder.innerHTML = html.trim();
    var fresh = holder.content.firstElementChild;
    var removed = fresh.classList.contains('tag-undo');
    var next = fresh;

    if (removed) {
      kept[row.id] = row;
    } else if (kept[row.id]) {
      next = kept[row.id];
      delete kept[row.id];
    }

    row.replaceWith(next);
    offer(row.id.replace('tag-row-', ''), !removed);

    // The pressed button is gone; focus goes to the one that reverses it.
    var control = next.querySelector('button');
    if (control) { control.focus(); }
  }

  /* The merge and field boxes above list every tag in use. A removed one is
     hidden there rather than taken out, so an undo can show it again in its
     place. */
  function offer(id, available) {
    var options = document.querySelectorAll('.tag-tools option[value="' + id + '"]');
    Array.prototype.forEach.call(options, function (option) {
      option.hidden = !available;
      option.disabled = !available;
      var select = option.parentNode;
      if (!available && select.value === id) {
        var first = select.querySelector('option:not([disabled])');
        if (first) { select.value = first.value; }
      }
    });
  }
})();
