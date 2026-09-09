/* Small conveniences on the edit form.
 *
 * The removal section stays a plain <details> in the markup so it works with
 * scripting switched off; this only makes the button in the action bar open
 * it, scroll to it and put the cursor in the confirmation field. Nothing here
 * is required for the page to function.
 */
/* Looking for a cover without leaving the page.
 *
 * The button posted a form of its own and the answer was a redirect, so
 * everything typed into the edit form and not yet saved was thrown away by a
 * button that only went to fetch a picture. Nobody expects that, and there is
 * no way to get the paragraph back.
 *
 * The same request, asked with fetch, answers JSON and the cover block is
 * swapped where it stands. With scripting off the form still posts and still
 * redirects - that path is untouched, which is why the button stays a submit
 * button in a real form rather than becoming an onclick.
 */
(function () {
  var form = document.getElementById('cover-search');
  var block = document.getElementById('cover-current');
  var wrapper = document.querySelector('.cover-search');
  var strings = document.getElementById('cover-i18n');
  if (!form || !block || !wrapper || !strings || !window.fetch) { return; }

  var text = JSON.parse(strings.textContent);
  var button = document.querySelector('[form="cover-search"]');

  /* Beside the button, not in the flash bar at the top of the page. What
     happened and the thing it happened to belong together; a sentence two
     screens up is one nobody reads.
     
     But it wore .note, which is what the sentence above it wears - so the
     answer looked like more of the explanation and went under. It gets the
     same box the page's own messages get, and it sits between the button and
     the hint: under the thing pressed, above the thing describing it. */
  var status = document.createElement('p');
  status.setAttribute('role', 'status');
  status.hidden = true;
  wrapper.insertBefore(status, wrapper.querySelector('.note'));

  function say(message, kind) {
    status.className = 'flash flash--' + kind;
    status.textContent = message;
    status.hidden = false;
  }

  form.addEventListener('submit', function (event) {
    event.preventDefault();
    if (button) { button.disabled = true; }
    say(text.searching, 'hint');

    fetch(form.action, {
      method: 'POST',
      body: new FormData(form),
      headers: { 'X-Requested-With': 'fetch' },
      credentials: 'same-origin'
    }).then(function (response) {
      return response.json();
    }).then(function (data) {
      say(data.message || text.failed, data.found ? 'ok' : 'error');
      if (data.found && data.block) {
        block.innerHTML = data.block;
      }
    }).catch(function () {
      say(text.failed, 'error');
    }).then(function () {
      if (button) { button.disabled = false; }
    });
  });
})();

(function () {
  'use strict';

  var opener = document.querySelector('[data-open-delete]');
  var details = document.getElementById('delete-book');
  if (!opener || !details) { return; }

  opener.addEventListener('click', function () {
    details.open = true;
    details.scrollIntoView({ block: 'center', behavior: 'smooth' });
    var confirm = details.querySelector('#confirm');
    if (confirm) {
      // After the scroll, or the browser jumps back to the field's old spot.
      window.setTimeout(function () { confirm.focus(); }, 320);
    }
  });
})();
