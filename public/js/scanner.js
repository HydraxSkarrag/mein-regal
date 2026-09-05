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
  var shell = document.getElementById('scanner');
  var pickCamera = document.getElementById('pick-camera');
  var pickManual = document.getElementById('pick-manual');
  var backButton = document.getElementById('manual-back');
  var stopButton = document.getElementById('stop');

  /* Which of the screens is on show.
   *
   * One at a time, so the found book, the viewfinder and the shutter button
   * are each at the top rather than stacked under one another - on a phone
   * that is the difference between reading the answer and scrolling for it.
   */
  function step(name) {
    shell.dataset.step = name;
    if (name !== 'result' && name !== 'photo') {
      resultBox.innerHTML = '';
    }
    /* Hidden video elements are paused by some browsers, and the stream
       itself stays live the whole time - only the decode loop stops. So on
       the way back in, ask it to play again rather than assume. */
    if (name === 'camera' && video.srcObject) {
      var resumed = video.play();
      if (resumed && resumed.catch) { resumed.catch(function () {}); }
    }
  }
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
  shell.appendChild(afterSaveBox);
  var manualForm = document.getElementById('manual');
  var isbnInput = document.getElementById('isbn');
  var seriesToggle = document.getElementById('series');
  var readToggle = document.getElementById('read');
  var counter = document.getElementById('counter');

  var stream = null;
  var detector = null;
  var scanning = false;
  var lastCode = '';
  var lastCodeAt = 0;
  var savedCount = 0;
  var currentBook = null;
  /* ISBN -> when it was saved. A moment, not a fact: see onCode. */
  var alreadySaved = {};
  var JUST_SAVED_MS = 10000;

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
    step('camera');
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
    step('choose');
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
       answered with "already on the shelf", which is wrong-footed when you
       have not moved yet.

       For ten seconds, though, and not for the rest of the session as it
       used to be. That silence was meant for the book still in your hand and
       ended up covering every later pass: pick the same book up again to
       check something, or find it a second time in the pile, and the scanner
       said nothing at all - no buzz, no message, no card. Silence is the one
       answer that cannot be told apart from a broken scanner. After the ten
       seconds the lookup runs and says "already on the shelf", which is true
       and is what you wanted to know. */
    if (alreadySaved[code] && now - alreadySaved[code] < JUST_SAVED_MS) { return; }
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
    /* Its own step rather than a line under the back button.
     *
     * "Wird gesucht …" used to appear in the status bar at the very bottom -
     * against the button you had just left, which is neither where you are
     * looking nor enough to sit through. Three sources are asked in turn and
     * that takes a couple of seconds; a screen that says which ones, for
     * which ISBN, with something moving, is the difference between waiting
     * and wondering whether the tap registered. */
    resultBox.innerHTML =
      '<div class="card result-card searching">' +
        '<p class="searching-title">' + esc(text.searching) + '</p>' +
        '<p class="searching-isbn">' + esc(isbn) + '</p>' +
        '<div class="searching-bar" role="progressbar" aria-valuetext="' + esc(text.searching) + '">' +
          '<div class="searching-bar-run"></div>' +
        '</div>' +
        '<p class="note searching-sources">' + esc(text.sources) + '</p>' +
      '</div>';
    resultBox.hidden = false;
    step('result');

    say('');
    overlaySay(text.detected, 'busy');
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
        row.innerHTML = '<a class="btn btn--grow" href="/book/' + esc(known.slug) + '">' +
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
      : '<div class="cover cover--placeholder ph-5"><span class="ph-title">'
        + esc(book.title) + '</span></div>';

    resultBox.innerHTML =
      '<div class="card result-card">' +
        '<p class="result-source">' + esc(book.source_label || '') + ' · ' + esc(book.isbn_formatted || '') + '</p>' +
        '<div class="result">' + cover +
          '<div>' +
            '<p class="result-title">' + esc(book.title) + '</p>' +
            (authors ? '<p class="result-author">' + esc(authors) + '</p>' : '') +
            (meta ? '<p class="result-meta">' + esc(meta) + '</p>' : '') +
          '</div>' +
        '</div>' +
        /* The subjects the source came back with, before anything is saved.
           A classification code or a shop category is easiest to catch here,
           while the book is still on the screen and nothing has been
           written - afterwards it is a trip through the tag administration. */
        ((book.tags || []).length
          ? '<ul class="result-tags">' + (book.tags || []).map(function (tag) {
              return '<li>' + esc(tag) + '</li>';
            }).join('') + '</ul>'
          : '') +
        '<div class="scanner-actions">' +
          '<button class="btn btn--primary" type="button" id="save">' + esc(text.save) + '</button>' +
          '<button class="btn" type="button" id="skip">' + esc(text.skip) + '</button>' +
        '</div>' +
      '</div>';

    resultBox.hidden = false;
    step('result');
    document.getElementById('save').addEventListener('click', save);
    document.getElementById('skip').addEventListener('click', dismiss);
  }

  /* Put the card away and start reading again. */
  function dismiss() {
    currentBook = null;
    lastCode = '';
    resultBox.hidden = true;
    say('');
    step(scanning ? 'camera' : 'choose');
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
    body.append('reading_status', readToggle.checked ? 'read' : 'unread');
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

    if (currentBook && currentBook.isbn13) { alreadySaved[currentBook.isbn13] = Date.now(); }
    savedCount++;
    counter.hidden = false;
    counter.textContent = text.count.replace('{count}', String(savedCount));

    say('');
    offerCoverPhoto(reply.data.id, reply.data.slug, reply.data.message);
    currentBook = null;

    /* Series mode: straight back to the camera. Cataloguing a shelf means
       twenty books in a row, and returning to the list between each one is
       the difference between an hour and an afternoon. */
    if (seriesToggle.checked && stream) {
      lastCode = '';
      resultBox.hidden = true;
      /* Back to the viewfinder, not just back to reading barcodes. Without
         this the step stayed on the result, so the camera was hidden while
         it scanned - which is every part of a series scan except the part
         you can see. The cover buttons stay reachable underneath, quietly:
         they are the way out, not the next thing to do. */
      step('camera');
      afterSaveBox.classList.add('after-save--quiet');
      if (scanning) { tick(); }
    } else {
      afterSaveBox.classList.remove('after-save--quiet');
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
  function offerCoverPhoto(bookId, slug, said) {
    afterSaveBox.innerHTML = '';

    /* "Erdsee steht jetzt im Regal" belongs to the three buttons underneath
       it, not to a status line at the other end of the screen. Together they
       are one block about one book: what happened, and what can still be
       done about it. */
    if (said) {
      var note = document.createElement('p');
      note.className = 'after-save-said';
      note.textContent = said;
      afterSaveBox.appendChild(note);
    }

    /* Two ways to a cover, and they are not the same way twice.
     *
     * The question was asked and settled with numbers: the shutter here uses
     * the stream that is already running, 1920x1080 requested, cropped to the
     * viewfinder, which on a phone leaves about 1080x1620 - and CoverStorage
     * caps what it keeps at 900 wide. So the phone's 12 megapixel still
     * camera would end up at exactly the same stored size. There is no
     * quality to win by sending people out to the camera app.
     *
     * What that route costs is an app switch per book, twenty of them in a
     * series scan, and on iOS it means opening the camera app while a
     * getUserMedia stream is live, which is its own risk. What it is good for
     * is a picture that already exists on the device.
     *
     * The file input carries capture="environment" on purpose: straight to
     * the camera, no gallery. It also means no crop step - the camera app
     * hands the photograph back as taken. Cropping would need either the
     * gallery (drop the attribute) or a drag frame in the review step here.
     *
     * Neither button is redundant. Delete one and you lose either the speed
     * or the photograph already in the camera roll. */
    var wrapper = document.createElement('div');
    wrapper.className = 'scanner-actions';

    wrapper.innerHTML =
      (stream ? '<button class="btn btn--primary btn--grow" type="button" data-start-shot>' + esc(text.shoot) + '</button>' : '') +
      '<label class="btn' + (stream ? '' : ' btn--grow') + '">' + esc(text.photo) +
        '<input type="file" accept="image/*" capture="environment" hidden>' +
      '</label>' +
      '<a class="btn" href="/book/' + esc(slug) + '">' + esc(text.openBook) + '</a>';

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
    /* Portrait, because a book is. The same frame reads the barcode, where
       wide is right; framing a cover in it means either a lot of table or a
       cover with its head cut off. */
    frame.classList.add('scanner-frame--portrait');
    /* Out of the quiet series styling: the shutter is the thing to press
       now, not a way out of something else. */
    afterSaveBox.classList.remove('after-save--quiet');
    step('photo');
    say('');
    overlaySay('');
    clearReticle();
    hint.textContent = text.aimCover;

    afterSaveBox.innerHTML = '';
    var actions = document.createElement('div');
    actions.className = 'scanner-actions';
    actions.innerHTML =
      '<button class="btn btn--primary btn--grow" type="button" data-shoot>' + esc(text.shutter) + '</button>' +
      '<button class="btn" type="button" data-cancel>' + esc(text.cancel) + '</button>';
    afterSaveBox.appendChild(actions);

    actions.querySelector('[data-shoot]').addEventListener('click', function () {
      var shot = grabVisibleFrame();
      if (shot) { reviewShot(bookId, slug, shot); }
    });
    actions.querySelector('[data-cancel]').addEventListener('click', function () {
      hint.textContent = text.aim;
      frame.classList.remove('scanner-frame--portrait');
      step('result');
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
      '<button class="btn btn--primary btn--grow" type="button" data-keep>' + esc(text.keepShot) + '</button>' +
      '<button class="btn" type="button" data-retake>' + esc(text.retake) + '</button>';
    review.appendChild(actions);
    afterSaveBox.appendChild(review);

    /* And then scroll to it. The frozen picture goes in below the live
       viewfinder, which on a phone puts it off the bottom of the screen - a
       few pixels of it showing under the fold, with the two buttons that
       decide its fate further down still. You have just taken a photograph
       and cannot see it. */
    if (review.scrollIntoView) {
      review.scrollIntoView({ behavior: 'smooth', block: 'center' });
    }

    actions.querySelector('[data-retake]').addEventListener('click', function () {
      beginCoverShot(bookId, slug);
    });
    actions.querySelector('[data-keep]').addEventListener('click', function () {
      frame.classList.remove('scanner-frame--portrait');
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
    frame.classList.remove('scanner-frame--portrait');

    /* And straight back to reading barcodes, if that is what we were doing.
       A cover is part of putting one book away, not the end of the run - the
       series used to stop here, on a portrait viewfinder that was no longer
       looking for anything. */
    if (seriesToggle.checked && stream) {
      lastCode = '';
      step('camera');
      afterSaveBox.classList.add('after-save--quiet');
      if (scanning) { tick(); }
    } else {
      afterSaveBox.classList.remove('after-save--quiet');
      step('result');
    }

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
  /* What the viewfinder showed, not what the camera saw.
   *
   * The frame displays the stream with object-fit: cover, so a portrait box
   * over a landscape camera shows a centre strip and hides the rest. Grabbing
   * the whole frame then handed back a wide photograph of a room with a book
   * somewhere in it - the careful framing threw away and the reviewing step
   * reduced to theatre, since what was approved is not what was kept.
   *
   * So the same crop is computed here: cover means the source is scaled until
   * it fills the box, and what is visible is the middle of whichever
   * dimension had to overflow.
   */
  function grabVisibleFrame() {
    if (!video.videoWidth) { return null; }

    var vw = video.videoWidth;
    var vh = video.videoHeight;
    var box = frame.clientHeight > 0 ? frame.clientWidth / frame.clientHeight : vw / vh;

    var sw = vw;
    var sh = vh;
    if (vw / vh > box) {
      sw = vh * box;          // camera wider than the box: sides are hidden
    } else {
      sh = vw / box;          // taller than the box: top and bottom are
    }
    var sx = (vw - sw) / 2;
    var sy = (vh - sh) / 2;

    var shot = document.createElement('canvas');
    shot.width = Math.round(sw);
    shot.height = Math.round(sh);
    shot.getContext('2d').drawImage(video, sx, sy, sw, sh, 0, 0, shot.width, shot.height);

    return shot;
  }

  // ----------------------------------------------------------------- wiring

  pickCamera.addEventListener('click', startCamera);
  pickManual.addEventListener('click', function () {
    step('manual');
    document.getElementById('isbn').focus();
  });
  backButton.addEventListener('click', function () { step('choose'); });
  stopButton.addEventListener('click', stopCamera);

  /* Whether new books count as read is remembered.
   *
   * Cataloguing a collection for the first time means one shelf of books
   * already read, then another of ones waiting - not an alternating sequence.
   * Ticking the box again after every reload is the sort of small friction
   * that ends with a hundred books recorded wrongly and an evening spent
   * correcting them, which is exactly the work the scanner exists to avoid.
   *
   * localStorage, not a cookie: it never leaves the browser, so there is
   * nothing to declare and nobody to ask. Storage can be refused outright in
   * a private window, and then the default simply stands.
   */
  try {
    readToggle.checked = localStorage.getItem('regal.scan.read') === '1';
  } catch (error) {
    // No storage available. Unread it is.
  }

  readToggle.addEventListener('change', function () {
    try {
      localStorage.setItem('regal.scan.read', readToggle.checked ? '1' : '0');
    } catch (error) {
      // Not being able to remember it does not stop it applying now.
    }
    say(readToggle.checked ? text.markedRead : text.markedUnread, 'ok');
  });

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
