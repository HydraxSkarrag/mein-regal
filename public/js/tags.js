/* Picking a genre from the ones that already exist.
 *
 * The plain comma-separated field is the source of truth and keeps working
 * with JavaScript switched off; this only enhances it. Everything typed still
 * ends up in that same input, so the server needs no second code path.
 *
 * The point is not convenience. With 382 genres already in the shelf, typing
 * one by hand quietly makes it 383 the first time a finger slips, and nobody
 * ever notices. So: suggestions ranked by how many books use them, and a
 * warning when what you typed is nearly - but not exactly - one that exists.
 */
(function () {
  'use strict';

  var field = document.getElementById('tag-field');
  var input = document.getElementById('tags');
  var knownNode = document.getElementById('known-tags');
  var i18nNode = document.getElementById('tag-i18n');
  if (!field || !input || !knownNode || !i18nNode) { return; }

  var known = JSON.parse(knownNode.textContent);
  var text = JSON.parse(i18nNode.textContent);

  /* Comparison form: accents folded, case and punctuation dropped. Two
     spellings of the same genre have to collide here or the warning is
     useless. Mirrors what the server does with author names. */
  function fold(value) {
    return value
      .toLowerCase()
      .replace(/ä/g, 'ae').replace(/ö/g, 'oe').replace(/ü/g, 'ue').replace(/ß/g, 'ss')
      .normalize('NFD').replace(/[̀-ͯ]/g, '')
      .replace(/[^a-z0-9]+/g, '');
  }

  var byFold = {};
  known.forEach(function (tag) { byFold[fold(tag.name)] = tag; });

  /* Levenshtein, capped: only used to ask "is this within one or two
     keystrokes of something that already exists". */
  function distance(a, b) {
    if (Math.abs(a.length - b.length) > 2) { return 99; }
    var prev = [];
    for (var j = 0; j <= b.length; j++) { prev[j] = j; }
    for (var i = 1; i <= a.length; i++) {
      var cur = [i];
      for (var k = 1; k <= b.length; k++) {
        cur[k] = Math.min(
          prev[k] + 1,
          cur[k - 1] + 1,
          prev[k - 1] + (a[i - 1] === b[k - 1] ? 0 : 1)
        );
      }
      prev = cur;
    }
    return prev[b.length];
  }

  function nearMiss(name) {
    var f = fold(name);
    if (f === '' || byFold[f]) { return null; }
    var best = null;
    known.forEach(function (tag) {
      var d = distance(f, fold(tag.name));
      if (d <= 2 && (best === null || d < best.d)) { best = { tag: tag, d: d }; }
    });
    return best ? best.tag : null;
  }

  // ------------------------------------------------------------ state

  function parse(value) {
    var seen = {};
    return value.split(',').map(function (s) { return s.trim(); })
      .filter(function (s) {
        if (s === '') { return false; }
        var f = fold(s);
        if (seen[f]) { return false; }
        seen[f] = true;
        return true;
      });
  }

  var chosen = parse(input.value);

  function sync() {
    input.value = chosen.join(', ');
  }

  // ------------------------------------------------------------- view

  input.type = 'hidden';

  var box = document.createElement('div');
  box.className = 'tagbox';

  var chips = document.createElement('div');
  chips.className = 'tagbox-chips';

  var entry = document.createElement('input');
  entry.type = 'text';
  entry.className = 'tagbox-entry';
  entry.placeholder = text.placeholder;
  entry.autocomplete = 'off';
  entry.setAttribute('aria-label', text.placeholder);

  var list = document.createElement('ul');
  list.className = 'tagbox-list';
  list.hidden = true;

  var warning = document.createElement('p');
  warning.className = 'tagbox-warning';
  warning.hidden = true;

  box.appendChild(chips);
  box.appendChild(entry);
  field.insertBefore(box, input.nextSibling);
  field.insertBefore(list, box.nextSibling);
  field.insertBefore(warning, list.nextSibling);

  function esc(value) {
    var d = document.createElement('div');
    d.textContent = value;
    return d.innerHTML;
  }

  function renderChips() {
    chips.innerHTML = '';
    chosen.forEach(function (name, index) {
      var chip = document.createElement('span');
      chip.className = 'tagbox-chip' + (byFold[fold(name)] ? '' : ' tagbox-chip--new');
      chip.innerHTML = esc(name) +
        '<button type="button" aria-label="' + esc(text.remove + ' ' + name) + '">&times;</button>';
      chip.querySelector('button').addEventListener('click', function () {
        chosen.splice(index, 1);
        renderChips();
        sync();
        entry.focus();
      });
      chips.appendChild(chip);
    });
  }

  var active = -1;

  function suggestionsFor(query) {
    var f = fold(query);
    if (f === '') {
      // Nothing typed yet: offer the ones actually in use.
      return known.filter(function (t) { return chosen.indexOf(t.name) === -1; }).slice(0, 8);
    }
    var starts = [];
    var contains = [];
    known.forEach(function (tag) {
      if (chosen.indexOf(tag.name) !== -1) { return; }
      var tf = fold(tag.name);
      if (tf.indexOf(f) === 0) { starts.push(tag); }
      else if (tf.indexOf(f) !== -1) { contains.push(tag); }
    });
    return starts.concat(contains).slice(0, 8);
  }

  function renderList() {
    var query = entry.value.trim();
    var matches = suggestionsFor(query);
    active = -1;

    list.innerHTML = '';
    matches.forEach(function (tag, index) {
      var li = document.createElement('li');
      li.innerHTML = '<span>' + esc(tag.name) + '</span><span class="n">' +
        tag.n + ' ' + esc(text.books) + '</span>';
      li.addEventListener('mousedown', function (event) {
        event.preventDefault();
        add(tag.name);
      });
      li.dataset.index = String(index);
      list.appendChild(li);
    });

    // Offer the typed value as a new tag when it matches nothing exactly.
    if (query !== '' && !byFold[fold(query)]) {
      var li = document.createElement('li');
      li.className = 'tagbox-new';
      li.innerHTML = '<span>' + esc(query) + '</span><span class="n">' + esc(text.newTag) + '</span>';
      li.addEventListener('mousedown', function (event) {
        event.preventDefault();
        add(query);
      });
      list.appendChild(li);
    }

    list.hidden = list.children.length === 0;
    showWarning(query);
  }

  function showWarning(query) {
    var near = query === '' ? null : nearMiss(query);
    if (near === null) { warning.hidden = true; return; }
    warning.textContent = text.similar.replace('{tag}', near.name);
    warning.hidden = false;
  }

  function add(name) {
    name = name.trim();
    if (name === '') { return; }
    var f = fold(name);
    if (chosen.some(function (c) { return fold(c) === f; })) { entry.value = ''; renderList(); return; }
    // Prefer the spelling already in use over whatever was typed.
    chosen.push(byFold[f] ? byFold[f].name : name);
    entry.value = '';
    renderChips();
    sync();
    renderList();
  }

  entry.addEventListener('input', renderList);
  entry.addEventListener('focus', renderList);
  entry.addEventListener('blur', function () {
    window.setTimeout(function () { list.hidden = true; warning.hidden = true; }, 120);
  });

  entry.addEventListener('keydown', function (event) {
    var items = list.querySelectorAll('li');

    if (event.key === 'Enter' || event.key === ',') {
      event.preventDefault();
      if (active >= 0 && items[active]) { items[active].dispatchEvent(new MouseEvent('mousedown')); }
      else { add(entry.value); }
      return;
    }
    if (event.key === 'Backspace' && entry.value === '' && chosen.length > 0) {
      chosen.pop();
      renderChips();
      sync();
      renderList();
      return;
    }
    if (event.key === 'ArrowDown' || event.key === 'ArrowUp') {
      if (items.length === 0) { return; }
      event.preventDefault();
      active += event.key === 'ArrowDown' ? 1 : -1;
      if (active < 0) { active = items.length - 1; }
      if (active >= items.length) { active = 0; }
      items.forEach(function (li, i) { li.classList.toggle('is-active', i === active); });
      return;
    }
    if (event.key === 'Escape') { list.hidden = true; }
  });

  // The comma-separated field must be current when the form submits, even if
  // something is still half-typed in the entry box.
  var form = input.closest('form');
  if (form) {
    form.addEventListener('submit', function () {
      if (entry.value.trim() !== '') { add(entry.value); }
      sync();
    });
  }

  renderChips();
  sync();
})();
