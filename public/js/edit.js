/* Small conveniences on the edit form.
 *
 * The removal section stays a plain <details> in the markup so it works with
 * scripting switched off; this only makes the button in the action bar open
 * it, scroll to it and put the cursor in the confirmation field. Nothing here
 * is required for the page to function.
 */
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
