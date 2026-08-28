/* Barcode scanning in the browser.
 *
 * Two paths, because no single one covers both phones in the house:
 *
 *   * BarcodeDetector is built into Chrome on Android. It is fast, needs no
 *     library, and costs nothing to try.
 *   * Safari on iOS has no such thing, so a small ZXing build is loaded from
 *     this server (never a CDN - that would be the first external request on
 *     the site and would cost it its strict content policy).
 *
 * Either way the camera only works over HTTPS; on plain HTTP the browser
 * refuses without explanation, so that case is named rather than left silent.
 */
(function () {
  'use strict';

  var text = JSON.parse(document.getElementById('scan-i18n').textContent);
  var token = document.querySelector('input[name="_token"]').value;

  var frame = document.getElementById('frame');
  var video = document.getElementById('video');
  var startButton = document.getElementById('start');
  var stopButton = document.getElementById('stop');
  var hint = document.getElementById('hint');
  var statusBox = document.getElementById('status');
  var resultBox = document.getElementById('result');
  var manualForm = document.getElementById('manual');
  var isbnInput = document.getElementById('isbn');
  var seriesToggle = document.getElementById('series');
  var counter = document.getElementById('counter');

  var stream = null;
  var detector = null;
  var scanning = false;
  var lastCode = '';
  var lastCodeAt = 0;
  var savedCount = 0;
  var currentBook = null;

  function say(message, kind) {
    statusBox.innerHTML = '';
    if (!message) { return; }
    var p = document.createElement('p');
    p.className = 'flash flash--' + (kind || 'info');
    p.textContent = message;
    statusBox.appendChild(p);
  }

  function esc(value) {
    var div = document.createElement('div');
    div.textContent = value == null ? '' : String(value);
    return div.innerHTML;
  }

  // ---------------------------------------------------------------- camera

  async function startCamera() {
    if (!window.isSecureContext) { say(text.noHttps, 'error'); return; }
    if (!navigator.mediaDevices || !navigator.mediaDevices.getUserMedia) {
      say(text.noCamera, 'error');
      return;
    }

    try {
      stream = await navigator.mediaDevices.getUserMedia({
        video: { facingMode: { ideal: 'environment' }, width: { ideal: 1280 } },
        audio: false
      });
    } catch (error) {
      say(error && error.name === 'NotAllowedError' ? text.denied : text.noCamera, 'error');
      return;
    }

    video.srcObject = stream;
    await video.play();

    frame.hidden = false;
    startButton.hidden = true;
    stopButton.hidden = false;
    hint.textContent = text.aim;

    scanning = true;
    detector = await makeDetector();
    if (!detector) { say(text.noCamera, 'error'); stopCamera(); return; }
    tick();
  }

  function stopCamera() {
    scanning = false;
    if (stream) {
      stream.getTracks().forEach(function (track) { track.stop(); });
      stream = null;
    }
    video.srcObject = null;
    frame.hidden = true;
    startButton.hidden = false;
    stopButton.hidden = true;
  }

  async function makeDetector() {
    if ('BarcodeDetector' in window) {
      try {
        var formats = await window.BarcodeDetector.getSupportedFormats();
        if (formats.indexOf('ean_13') !== -1) {
          var native = new window.BarcodeDetector({ formats: ['ean_13'] });
          return function (source) {
            return native.detect(source).then(function (codes) {
              return codes.length ? codes[0].rawValue : null;
            });
          };
        }
      } catch (error) { /* fall through to the library */ }
    }
    return loadZxing();
  }

  /* ZXing is only fetched when the native detector is missing, so Android
     never pays for a library it does not need. */
  function loadZxing() {
    return new Promise(function (resolve) {
      var script = document.createElement('script');
      script.src = '/js/zxing.min.js';
      script.onload = function () {
        if (!window.ZXing) { resolve(null); return; }
        var reader = new window.ZXing.BrowserMultiFormatReader();
        resolve(function (source) {
          return reader.decodeOnceFromVideoElement(source)
            .then(function (r) { return r ? r.getText() : null; })
            .catch(function () { return null; });
        });
      };
      script.onerror = function () { resolve(null); };
      document.head.appendChild(script);
    });
  }

  async function tick() {
    if (!scanning) { return; }
    try {
      var code = await detector(video);
      if (code) { onCode(code); }
    } catch (error) { /* a frame that will not decode is not an error */ }
    if (scanning) { setTimeout(tick, 220); }
  }

  function onCode(code) {
    var now = Date.now();
    // The same barcode stays in view for many frames; ignore repeats.
    if (code === lastCode && now - lastCodeAt < 4000) { return; }
    lastCode = code;
    lastCodeAt = now;

    if (navigator.vibrate) { navigator.vibrate(40); }
    lookup(code);
  }

  // ---------------------------------------------------------------- lookup

  async function post(url, body) {
    body.append('_token', token);
    var response = await fetch(url, {
      method: 'POST',
      body: body,
      headers: { 'X-Requested-With': 'fetch' },
      credentials: 'same-origin'
    });
    return { status: response.status, data: await response.json().catch(function () { return {}; }) };
  }

  async function lookup(isbn) {
    say(text.searching);
    resultBox.hidden = true;

    var body = new FormData();
    body.append('isbn', isbn);

    var reply;
    try {
      reply = await post('/api/lookup', body);
    } catch (error) {
      say(text.error, 'error');
      return;
    }

    if (reply.status === 429) { say(text.error, 'error'); return; }
    if (reply.status === 422) { say(reply.data.error || text.invalidIsbn, 'error'); return; }
    if (reply.data.duplicate) { say(reply.data.message || text.duplicate, 'error'); return; }
    if (!reply.data.found) { say(reply.data.message || text.nothing, 'error'); return; }

    say('');
    showResult(reply.data.book);
  }

  function showResult(book) {
    currentBook = book;

    var authors = (book.authors || []).map(function (a) { return a.name; }).join(', ');
    var meta = [book.publisher, book.published_year, book.page_count ? book.page_count + ' S.' : null]
      .filter(Boolean).join(' · ');

    var cover = book.cover_url
      ? '<div class="cover"><img src="' + esc(book.cover_url) + '" alt=""></div>'
      : '<div class="cover cover--placeholder" style="background:#3b4a63"><span class="ph-title">'
        + esc(book.title) + '</span></div>';

    resultBox.innerHTML =
      '<div class="card" style="margin-top:14px">' +
        '<p class="result-source">' + esc(book.source_label || '') + ' · ' + esc(book.isbn_formatted || '') + '</p>' +
        '<div class="result">' + cover +
          '<div>' +
            '<p class="result-title">' + esc(book.title) + '</p>' +
            (authors ? '<p class="result-author">' + esc(authors) + '</p>' : '') +
            (meta ? '<p class="result-meta">' + esc(meta) + '</p>' : '') +
          '</div>' +
        '</div>' +
        '<div class="scanner-actions">' +
          '<button class="btn btn--primary" type="button" id="save">' + esc(text.save) + '</button>' +
        '</div>' +
      '</div>';

    resultBox.hidden = false;
    document.getElementById('save').addEventListener('click', save);
  }

  async function save() {
    if (!currentBook) { return; }
    var button = document.getElementById('save');
    button.disabled = true;

    var body = new FormData();
    body.append('isbn', currentBook.isbn13 || '');
    body.append('title', currentBook.title || '');
    body.append('subtitle', currentBook.subtitle || '');
    body.append('publisher', currentBook.publisher || '');
    body.append('published_year', currentBook.published_year || '');
    body.append('page_count', currentBook.page_count || '');
    body.append('language', currentBook.language || '');
    body.append('binding', currentBook.binding || '');
    body.append('price', currentBook.price || '');
    body.append('reading_status', 'unread');
    body.append('acquisition_type', 'purchase');
    body.append('authors', JSON.stringify(currentBook.authors || []));
    body.append('tags', JSON.stringify(currentBook.tags || []));
    if (currentBook.cover_url && currentBook.source !== 'dnb') {
      body.append('cover_url', currentBook.cover_url);
      body.append('cover_source', currentBook.source);
      body.append('cover_attribution', currentBook.attribution || '');
    }

    var reply;
    try {
      reply = await post('/api/buch', body);
    } catch (error) {
      say(text.error, 'error');
      button.disabled = false;
      return;
    }

    if (!reply.data.saved) {
      say(reply.data.error || text.error, 'error');
      button.disabled = false;
      return;
    }

    savedCount++;
    counter.hidden = false;
    counter.textContent = text.count.replace('{count}', String(savedCount));

    say(reply.data.message, 'ok');
    offerCoverPhoto(reply.data.id, reply.data.slug);
    currentBook = null;

    /* Series mode: straight back to the camera. Cataloguing a shelf means
       twenty books in a row, and returning to the list between each one is
       the difference between an hour and an afternoon. */
    if (seriesToggle.checked && stream) {
      lastCode = '';
      resultBox.hidden = true;
    }
  }

  function offerCoverPhoto(bookId, slug) {
    var wrapper = document.createElement('div');
    wrapper.className = 'scanner-actions';
    wrapper.innerHTML =
      '<label class="btn" style="flex:1">' + esc(text.photo) +
        '<input type="file" accept="image/*" capture="environment" hidden>' +
      '</label>' +
      '<a class="btn" href="/buch/' + esc(slug) + '">&rarr;</a>';
    statusBox.appendChild(wrapper);

    wrapper.querySelector('input').addEventListener('change', async function (event) {
      var file = event.target.files && event.target.files[0];
      if (!file) { return; }
      var body = new FormData();
      body.append('book_id', String(bookId));
      body.append('cover', file);
      try {
        await post('/api/cover', body);
        wrapper.remove();
      } catch (error) { /* the book is saved either way */ }
    });
  }

  // ----------------------------------------------------------------- wiring

  startButton.addEventListener('click', startCamera);
  stopButton.addEventListener('click', stopCamera);

  manualForm.addEventListener('submit', function (event) {
    event.preventDefault();
    var value = isbnInput.value.replace(/[^0-9Xx]/g, '');
    if (value.length !== 10 && value.length !== 13) {
      say(text.invalidIsbn, 'error');
      return;
    }
    lookup(value);
    isbnInput.value = '';
  });

  /* Releasing the camera when the page is hidden stops the light staying on
     after switching apps. */
  document.addEventListener('visibilitychange', function () {
    if (document.hidden && stream) { stopCamera(); }
  });
})();
