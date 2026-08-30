/**
 * The toolbar and live preview for the prose pages.
 *
 * Two rules shape this file. The buttons insert plain characters into the
 * textarea - they never build HTML - so what is stored is always exactly what
 * a person could have typed by hand. And the preview is rendered by the
 * server through the same function the published page uses, rather than by a
 * second parser written here: two implementations of one syntax drift, and it
 * is always the preview that drifts, which is precisely the copy people trust
 * before they publish.
 *
 * Everything is an enhancement. With the script absent the toolbar never
 * appears and the textarea works as it always did.
 */
(function () {
  'use strict';

  var root = document.querySelector('[data-editor]');
  if (!root) { return; }

  var input = root.querySelector('[data-editor-input]');
  var bar = root.querySelector('[data-editor-bar]');
  var preview = root.querySelector('[data-editor-preview]');
  var toggle = root.querySelector('[data-editor-toggle]');
  var tokenField = root.closest('form').querySelector('input[name="_token"]');
  if (!input || !bar) { return; }

  bar.hidden = false;

  /* What each button does to the selection. A "wrap" puts the same characters
     on both sides; a "prefix" goes at the start of every selected line. */
  var tools = {
    b:  { wrap: '**' },
    i:  { wrap: '*' },
    h:  { prefix: '## ' },
    ul: { prefix: '- ' },
    ol: { prefix: '1. ' },
    q:  { prefix: '> ' },
    a:  { link: true }
  };

  bar.addEventListener('click', function (event) {
    var button = event.target.closest('[data-tool]');
    if (!button) { return; }
    /* The stand-in text when nothing is selected comes from the button's own
       label, so it is translated with the rest of the interface. */
    apply(tools[button.getAttribute('data-tool')], button.getAttribute('data-placeholder') || '');
  });

  function apply(tool, placeholder) {
    if (!tool) { return; }
    var value = input.value;
    var start = input.selectionStart;
    var end = input.selectionEnd;
    var replacement;
    var caret;

    if (tool.prefix) {
      /* Markers belong at the start of a line, so the range is grown to whole
         lines. With nothing selected that means the line the caret sits in,
         which is what "put the cursor here and click list" should do -
         inserting a marker mid-sentence never is. */
      var lineStart = value.lastIndexOf('\n', start - 1) + 1;
      var lineEnd = value.indexOf('\n', end);
      if (start === end || lineEnd === -1) {
        lineEnd = lineEnd === -1 ? value.length : lineEnd;
      } else {
        lineEnd = end;
      }
      var block = value.slice(lineStart, lineEnd);
      if (block.trim() === '') { block = placeholder; }

      replacement = block.split('\n').map(function (line, index) {
        /* An ordered list counts up; everything else repeats its marker. */
        var marker = tool.prefix === '1. ' ? (index + 1) + '. ' : tool.prefix;
        return line.startsWith(marker) ? line : marker + line;
      }).join('\n');

      start = lineStart;
      end = lineEnd;
      caret = [start + replacement.length, start + replacement.length];
    } else {
      var selected = value.slice(start, end);
      /* Whitespace stays outside the markers. A double-click commonly takes
         the space after the word with it, and "**word **" is not bold - it
         is two asterisks the reader gets to look at. */
      var lead = selected.match(/^\s*/)[0];
      var tail = selected.match(/\s*$/)[0];
      var core = selected.trim() || placeholder;
      if (selected.trim() === '') { lead = ''; tail = ''; }

      if (tool.link) {
        replacement = lead + '[' + core + '](https://)' + tail;
        /* Land the caret inside the empty address, ready to paste. */
        var offset = start + lead.length + core.length + 3 + 8;
        caret = [offset, offset];
      } else {
        replacement = lead + tool.wrap + core + tool.wrap + tail;
        caret = [
          start + lead.length + tool.wrap.length,
          start + lead.length + tool.wrap.length + core.length
        ];
      }
    }

    input.setRangeText(replacement, start, end, 'preserve');
    input.focus();
    input.setSelectionRange(caret[0], caret[1]);
    schedule();
  }

  /* Preview */

  var timer = null;
  var lastSent = null;
  var showing = false;

  if (toggle && preview && tokenField) {
    toggle.addEventListener('click', function () {
      showing = !showing;
      toggle.setAttribute('aria-pressed', showing ? 'true' : 'false');
      preview.hidden = !showing;
      if (showing) { render(); }
    });
    input.addEventListener('input', schedule);
  } else if (toggle) {
    toggle.hidden = true;
  }

  function schedule() {
    if (!showing) { return; }
    window.clearTimeout(timer);
    timer = window.setTimeout(render, 400);
  }

  async function render() {
    var body = input.value;
    if (body === lastSent) { return; }
    lastSent = body;

    var form = new FormData();
    form.append('body', body);
    form.append('_token', tokenField.value);

    try {
      var response = await fetch('/api/preview', {
        method: 'POST',
        body: form,
        headers: { 'X-Requested-With': 'fetch' },
        credentials: 'same-origin'
      });
      if (!response.ok) { return; }
      var data = await response.json();
      /* The server escaped every character of this before it produced a
         single tag; see App\Core\Markup. */
      preview.innerHTML = data.html || '';
    } catch (error) {
      /* A failed preview is not worth interrupting the writing for. */
    }
  }
})();
