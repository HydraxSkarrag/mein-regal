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
  var reticle = document.getElementById('reticle');
  var overlay = document.getElementById('overlay');
  var statusBox = document.getElementById('status');
  var resultBox = document.getElementById('result');

  /* The cover offer gets its own container. It used to be appended to the
     status box, which say() empties on every message - so the first status
     line after saving swept the shutter button away before it could be
     pressed. */
  var afterSaveBox = document.createElement('div');
  statusBox.parentNode.insertBefore(afterSaveBox, statusBox.nextSibling);
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
  var alreadySaved = {};

  /* Feedback on the picture, not under it.
   *
   * Someone holding a book up to the camera is looking at the camera view,
   * not at a line of small text below the fold. Without this the moment a
   * barcode is recognised is invisible, and people keep waving the book
   * about while the lookup is already running. */
  function overlaySay(message, kind) {
    if (!overlay) { return; }
    if (!message) { overlay.hidden = true; return; }
    overlay.textContent = message;
    overlay.className = 'scanner-overlay' + (kind ? ' scanner-overlay--' + kind : '');
    overlay.hidden = false;
  }

  function flashReticle(kind) {
    if (!reticle) { return; }
    reticle.classList.remove('is-hit', 'is-miss');
    // Reading the layout forces the class removal to take effect, so a
    // second hit in a row animates again instead of sitting still.
    void reticle.offsetWidth;
    reticle.classList.add(kind === 'miss' ? 'is-miss' : 'is-hit');
  }

  function clearReticle() {
    if (reticle) { reticle.classList.remove('is-hit', 'is-miss'); }
  }

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
        video: {
          facingMode: { ideal: 'environment' },
          // A barcode has to survive being cropped and scaled, so ask for
          // more than the default 640x480 a laptop otherwise hands over.
          width: { ideal: 1920 },
          height: { ideal: 1080 }
        },
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
    if (!detector) { say(text.noDecoder, 'error'); stopCamera(); return; }
    tick();
  }

  function stopCamera() {
    scanning = false;
    overlaySay('');
    clearReticle();
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
          var native = new window.BarcodeDetector({ formats: ['ean_13', 'ean_8'] });
          return function (source) {
            var canvas = frameFrom(source);
            if (!canvas) { return Promise.resolve(null); }
            return native.detect(canvas).then(function (codes) {
              return codes.length ? codes[0].rawValue : null;
            });
          };
        }
      } catch (error) { /* fall through to the library */ }
    }
    return loadZxing();
  }

  /* ZXing is only fetched when the native detector is missing, so Android
     never pays for a library it does not need.
     
     It decodes a single still frame per call. The obvious-looking
     decodeOnceFromVideoElement is wrong here: it takes the video element
     over and runs its own loop, resolving only once it finds something, so
     calling it from a timer stacks a new decoder on top of the last one
     every fifth of a second and nothing ever reads. That is why scanning
     appeared dead on the desktop - the camera was on and dozens of decoders
     were fighting over it. */
  function loadZxing() {
    return new Promise(function (resolve) {
      var script = document.createElement('script');
      script.src = '/js/zxing.min.js';
      script.onload = function () {
        if (!window.ZXing) { resolve(null); return; }
        var Z = window.ZXing;
        var reader = new Z.BrowserMultiFormatReader();
        resolve(function (source) {
          var canvas = frameFrom(source);
          if (!canvas) { return Promise.resolve(null); }
          try {
            // One frame, decoded in place. This build has no
            // decodeFromCanvas, so the bitmap is assembled directly - the
            // documented way to hand ZXing a canvas.
            var luminance = new Z.HTMLCanvasElementLuminanceSource(canvas);
            var bitmap = new Z.BinaryBitmap(new Z.HybridBinarizer(luminance));
            var result = reader.decodeBitmap(bitmap);
            return Promise.resolve(result ? result.getText() : null);
          } catch (error) {
            // NotFoundException on a frame with no barcode in it.
            return Promise.resolve(null);
          }
        });
      };
      script.onerror = function () { resolve(null); };
      document.head.appendChild(script);
    });
  }

  /* Crop the frame to the band inside the reticle before decoding.
     
     A laptop webcam sees a whole desk; the barcode is a small part of it,
     and handing a decoder the full picture makes it hunt through furniture.
     Cropping to what the frame on screen already tells the user to aim with
     is both faster and markedly more reliable. */
  var scratch = document.createElement('canvas');

  function frameFrom(source) {
    var w = source.videoWidth;
    var h = source.videoHeight;
    if (!w || !h) { return null; }

    var cropW = Math.round(w * 0.8);
    var cropH = Math.round(h * 0.45);
    var x = Math.round((w - cropW) / 2);
    var y = Math.round((h - cropH) / 2);

    scratch.width = cropW;
    scratch.height = cropH;
    scratch.getContext('2d').drawImage(source, x, y, cropW, cropH, 0, 0, cropW, cropH);

    return scratch;
  }

  async function tick() {
    if (!scanning || currentBook !== null) {
      // A result is on screen waiting for a decision. Keep the camera
      // running but stop reading, or the next pass wipes the card the
      // moment it appears - on a phone still pointed at the book, that is
      // every four seconds.
      if (scanning) { setTimeout(tick, 300); }
      return;
    }
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
    /* And a book just added stays in view too - in series mode the camera
       is still pointed at it. Without this it is read again immediately and
       answered with "already on the shelf", which is both wrong-footed and
       wipes the cover buttons off the screen. */
    if (alreadySaved[code]) { return; }
    lastCode = code;
    lastCodeAt = now;

    if (navigator.vibrate) { navigator.vibrate(40); }
    flashReticle('hit');
    overlaySay(text.detected, 'busy');
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
    overlaySay(text.detected, 'busy');
    resultBox.hidden = true;
    afterSaveBox.innerHTML = '';

    var body = new FormData();
    body.append('isbn', isbn);

    var reply;
    try {
      reply = await post('/api/lookup', body);
    } catch (error) {
      say(text.error, 'error');
      return;
    }

    if (reply.status === 429) { say(text.error, 'error'); overlaySay(text.error, 'bad'); return; }
    if (reply.status === 422) {
      var message = reply.data.error || text.invalidIsbn;
      say(message, 'error');
      overlaySay(message, 'bad');
      flashReticle('miss');
      return;
    }
    if (reply.data.duplicate) {
      var message = reply.data.message || text.duplicate;
      say(message, 'error');
      overlaySay(message, 'bad');
      flashReticle('miss');
      currentBook = null;

      /* Say which book, and offer the way there. "Already on the shelf" on
         its own leaves you wondering whether it is the same edition, and
         with the book in one hand the last thing you want is to go and
         search for it. */
      var known = reply.data.book;
      if (known && known.slug) {
        afterSaveBox.innerHTML = '';
        var row = document.createElement('div');
        row.className = 'scanner-actions';
        row.innerHTML = '<a class="btn" style="flex:1" href="/book/' + esc(known.slug) + '">' +
          esc(known.title || text.openBook) + '</a>';
        afterSaveBox.appendChild(row);
      }
      return;
    }
    if (!reply.data.found) {
      say(reply.data.message || text.nothing, 'error');
      overlaySay(text.nothingShort, 'bad');
      flashReticle('miss');
      return;
    }

    say('');
    overlaySay(reply.data.book.title, 'good');
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
          '<button class="btn" type="button" id="skip">' + esc(text.skip) + '</button>' +
        '</div>' +
      '</div>';

    resultBox.hidden = false;
    document.getElementById('save').addEventListener('click', save);
    document.getElementById('skip').addEventListener('click', dismiss);
  }

  /* Put the card away and start reading again. */
  function dismiss() {
    currentBook = null;
    lastCode = '';
    resultBox.hidden = true;
    say('');
    if (scanning) { tick(); }
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
    /* The cover's own source, not the record's. A German book is answered
       by the DNB, which has no covers, so the image shown here came from
       somewhere further down the chain - checking the record's source threw
       away the very cover displayed above the button. */
    if (currentBook.cover_url && currentBook.cover_source) {
      body.append('cover_url', currentBook.cover_url);
      body.append('cover_source', currentBook.cover_source);
      body.append('cover_attribution', currentBook.attribution || '');
    }

    var reply;
    try {
      reply = await post('/api/book', body);
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

    if (currentBook && currentBook.isbn13) { alreadySaved[currentBook.isbn13] = true; }
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
      if (scanning) { tick(); }
    }
  }

  /* Taking the cover photograph.
   *
   * Aim, shoot, look at it, keep it or try again. The first version fired
   * the instant the button was pressed, with the video hidden behind the
   * result card - so you photographed the back of the book you had just
   * scanned, never saw what you got, and had no way back. A camera that
   * takes a picture you cannot see before it is saved is worse than no
   * camera at all.
   *
   * Choosing a file stays available, and is the only route offered when the
   * camera is not running because the ISBN was typed by hand. */
  function offerCoverPhoto(bookId, slug) {
    var wrapper = document.createElement('div');
    wrapper.className = 'scanner-actions';

    wrapper.innerHTML =
      (stream ? '<button class="btn btn--primary" type="button" data-start-shot style="flex:1">' + esc(text.shoot) + '</button>' : '') +
      '<label class="btn"' + (stream ? '' : ' style="flex:1"') + '>' + esc(text.photo) +
        '<input type="file" accept="image/*" capture="environment" hidden>' +
      '</label>' +
      '<a class="btn" href="/book/' + esc(slug) + '">' + esc(text.openBook) + '</a>';

    afterSaveBox.innerHTML = '';
    afterSaveBox.appendChild(wrapper);

    var startShot = wrapper.querySelector('[data-start-shot]');
    if (startShot) {
      startShot.addEventListener('click', function () { beginCoverShot(bookId, slug); });
    }
    wrapper.querySelector('input').addEventListener('change', function (event) {
      var file = event.target.files && event.target.files[0];
      if (file) { uploadCover(bookId, slug, file, file.name); }
    });
  }

  /* Step one: put the live picture back in front of the user and say what to
     do with it. The book has to be turned round, and that is impossible to
     do well against a hidden viewfinder. */
  function beginCoverShot(bookId, slug) {
    resultBox.hidden = true;
    say('');
    overlaySay('');
    clearReticle();
    hint.textContent = text.aimCover;

    afterSaveBox.innerHTML = '';
    var actions = document.createElement('div');
    actions.className = 'scanner-actions';
    actions.innerHTML =
      '<button class="btn btn--primary" type="button" data-shoot style="flex:1">' + esc(text.shutter) + '</button>' +
      '<button class="btn" type="button" data-cancel>' + esc(text.cancel) + '</button>';
    afterSaveBox.appendChild(actions);

    actions.querySelector('[data-shoot]').addEventListener('click', function () {
      var shot = grabFullFrame();
      if (shot) { reviewShot(bookId, slug, shot); }
    });
    actions.querySelector('[data-cancel]').addEventListener('click', function () {
      hint.textContent = text.aim;
      offerCoverPhoto(bookId, slug);
    });
  }

  /* Step two: show what was actually captured, frozen, at a size where a
     blurred or half-cropped cover is obvious. Nothing is uploaded until it
     is accepted. */
  function reviewShot(bookId, slug, shot) {
    hint.textContent = text.reviewShot;
    afterSaveBox.innerHTML = '';

    var review = document.createElement('div');
    review.className = 'shot-review';
    review.appendChild(shot);
    shot.className = 'shot-preview';

    var actions = document.createElement('div');
    actions.className = 'scanner-actions';
    actions.innerHTML =
      '<button class="btn btn--primary" type="button" data-keep style="flex:1">' + esc(text.keepShot) + '</button>' +
      '<button class="btn" type="button" data-retake>' + esc(text.retake) + '</button>';
    review.appendChild(actions);
    afterSaveBox.appendChild(review);

    actions.querySelector('[data-retake]').addEventListener('click', function () {
      beginCoverShot(bookId, slug);
    });
    actions.querySelector('[data-keep]').addEventListener('click', function () {
      var keep = actions.querySelector('[data-keep]');
      keep.disabled = true;
      shot.toBlob(function (blob) {
        if (blob) { uploadCover(bookId, slug, blob, 'cover.jpg'); }
        else { keep.disabled = false; }
      }, 'image/jpeg', 0.92);
    });
  }

  /* Step three: it is stored, and can still be thrown away. */
  async function uploadCover(bookId, slug, blobOrFile, filename) {
    var body = new FormData();
    body.append('book_id', String(bookId));
    body.append('cover', blobOrFile, filename);

    var reply;
    try {
      reply = await post('/api/cover', body);
    } catch (error) {
      say(text.error, 'error');
      return;
    }
    if (!reply.data || !reply.data.saved) {
      say(text.error, 'error');
      offerCoverPhoto(bookId, slug);
      return;
    }

    hint.textContent = text.aim;
    afterSaveBox.innerHTML = '';

    var done = document.createElement('div');
    done.className = 'scanner-actions';
    done.innerHTML =
      '<img src="' + esc(reply.data.url) + '" alt="" class="shot-thumb">' +
      '<button class="btn" type="button" data-drop>' + esc(text.dropCover) + '</button>' +
      '<a class="btn" href="/book/' + esc(slug) + '">' + esc(text.openBook) + '</a>';
    afterSaveBox.appendChild(done);

    done.querySelector('[data-drop]').addEventListener('click', async function () {
      var drop = new FormData();
      drop.append('book_id', String(bookId));
      try {
        await post('/api/cover-delete', drop);
        offerCoverPhoto(bookId, slug);
      } catch (error) { /* leave it as it is */ }
    });
  }

  /* The whole frame this time, not the barcode band: a cover fills the
     picture, so cropping would cut it in half. */
  function grabFullFrame() {
    if (!video.videoWidth) { return null; }
    var shot = document.createElement('canvas');
    shot.width = video.videoWidth;
    shot.height = video.videoHeight;
    shot.getContext('2d').drawImage(video, 0, 0);

    return shot;
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
