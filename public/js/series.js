/* Picking a series from the ones that already exist.
 *
 * The same job the genre field does, so it wears the same clothes: one input,
 * a list under it that narrows as you type, name on the left and how much of
 * that series is already on the shelf on the right, arrow keys and Enter.
 *
 * It replaces a <datalist>, which looked like completion on a desktop browser
 * and was nothing at all on a phone - Safari on iOS ignores it - so the one
 * place where a series actually gets typed, standing in front of the shelf,
 * was the one place with no help. Anything typed is still accepted: a list
 * you cannot type past would refuse every new series.
 *
 * The field is a plain text input either way. Without JavaScript the datalist
 * is still there and still works where it works; this only takes over.
 */
(function () {
  'use strict';

  var field = document.getElementById('series-field');
  var input = document.getElementById('series');
  var knownNode = document.getElementById('known-series-data');
  var i18nNode = document.getElementById('series-i18n');
  if (!field || !input || !knownNode || !i18nNode) { return; }

  var known = JSON.parse(knownNode.textContent);
  var text = JSON.parse(i18nNode.textContent);

  // Two lists of suggestions for one field would be one too many.
  input.removeAttribute('list');

  /* Comparison form, the same folding the server slugs a series name with, so
     that what this calls "already there" is what findOrCreate will call the
     same series. */
  function fold(value) {
    return value
      .toLowerCase()
      .replace(/ä/g, 'ae').replace(/ö/g, 'oe').replace(/ü/g, 'ue').replace(/ß/g, 'ss')
      .normalize('NFD').replace(/[̀-ͯ]/g, '')
      .replace(/[^a-z0-9]+/g, '');
  }

  var byFold = {};
  known.forEach(function (series) { byFold[fold(series.name)] = series; });

  /* Levenshtein, capped: only used to ask whether what was typed is within a
     keystroke or two of a series that already exists. The slug catches
     "Sturmlicht Chroniken" for "Sturmlicht-Chroniken" on its own; this is for
     the ones it cannot, where a letter is simply wrong. */
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
    known.forEach(function (series) {
      var d = distance(f, fold(series.name));
      if (d <= 2 && (best === null || d < best.d)) { best = { series: series, d: d }; }
    });
    return best ? best.series : null;
  }

  // ------------------------------------------------------------- view

  var list = document.createElement('ul');
  list.className = 'picker-list';
  list.hidden = true;

  var warning = document.createElement('p');
  warning.className = 'picker-warning';
  warning.hidden = true;

  /* Into the row, not into the field. A series name is long - "Die Chronik
     der Drachenlanze" broke over three lines in a box the width of the input
     - while the field beside it holds a number and needs none of its width.
     So the list is a grid item of its own, spanning the whole row, and the
     name has the room the shelf actually gives it. */
  field.appendChild(list);
  field.appendChild(warning);

  function esc(value) {
    var d = document.createElement('div');
    d.textContent = value;
    return d.innerHTML;
  }

  var active = -1;

  function suggestionsFor(query) {
    var f = fold(query);
    if (f === '') { return known.slice(0, 8); }
    var starts = [];
    var contains = [];
    known.forEach(function (series) {
      var sf = fold(series.name);
      if (sf.indexOf(f) === 0) { starts.push(series); }
      else if (sf.indexOf(f) !== -1) { contains.push(series); }
    });
    return starts.concat(contains).slice(0, 8);
  }

  function renderList() {
    var query = input.value.trim();
    var matches = suggestionsFor(query);
    active = -1;

    list.innerHTML = '';
    matches.forEach(function (series) {
      var li = document.createElement('li');
      li.innerHTML = '<span>' + esc(series.name) + '</span>' +
        '<span class="n">' + series.n + ' ' + esc(text.books) + '</span>';
      li.addEventListener('mousedown', function (event) {
        event.preventDefault();
        choose(series.name);
      });
      list.appendChild(li);
    });

    /* What is typed, offered as a new series, exactly the way the genre field
       offers a new genre - so that making one is something clicked rather
       than something that happens by not clicking. */
    if (query !== '' && !byFold[fold(query)]) {
      var li = document.createElement('li');
      li.className = 'picker-new';
      li.innerHTML = '<span>' + esc(query) + '</span>' +
        '<span class="n">' + esc(text.newSeries) + '</span>';
      li.addEventListener('mousedown', function (event) {
        event.preventDefault();
        choose(query);
      });
      list.appendChild(li);
    }

    list.hidden = list.children.length === 0;
    showWarning(query);
  }

  function showWarning(query) {
    var near = query === '' ? null : nearMiss(query);
    if (near === null) { warning.hidden = true; return; }
    warning.textContent = text.similar.replace('{name}', near.name);
    warning.hidden = false;
  }

  function choose(name) {
    // Prefer the spelling already in use over whatever was typed.
    var existing = byFold[fold(name)];
    input.value = existing ? existing.name : name;
    list.hidden = true;
    warning.hidden = true;
    /* Straight on to the volume, which is the next thing to say and the
       reason the two fields stand side by side. */
    var volume = document.getElementById('series_index');
    if (volume) { volume.focus(); }
  }

  input.addEventListener('input', renderList);
  input.addEventListener('focus', renderList);
  input.addEventListener('blur', function () {
    window.setTimeout(function () { list.hidden = true; warning.hidden = true; }, 120);
  });

  input.addEventListener('keydown', function (event) {
    var items = list.querySelectorAll('li');

    if (event.key === 'Enter') {
      // Only when a suggestion is highlighted. Otherwise Enter is what it is
      // everywhere else in this form, and submits it.
      if (active >= 0 && items[active]) {
        event.preventDefault();
        items[active].dispatchEvent(new MouseEvent('mousedown'));
      }
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
    if (event.key === 'Escape') { list.hidden = true; warning.hidden = true; }
  });
})();
