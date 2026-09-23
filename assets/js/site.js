/* Social Lab — site behaviour: nav, reveals, lightbox, AJAX enquiry forms. */

(function () {
  'use strict';

  /* ------------------------------------------------------------- header -- */

  // One scroll handler, run at most once per frame: read first, then write.
  var header = document.getElementById('site-header');
  var bar = null; // scroll progress bar, created further down
  var scrollQueued = false;
  var onScroll = function () {
    if (scrollQueued) return;
    scrollQueued = true;
    requestAnimationFrame(function () {
      scrollQueued = false;
      var y = window.scrollY;
      var max = bar ? document.documentElement.scrollHeight - window.innerHeight : 0;
      if (bar) bar.style.transform = 'scaleX(' + (max > 0 ? Math.min(y / max, 1) : 0) + ')';
      if (header) header.classList.toggle('is-stuck', y > 24);
    });
  };
  onScroll();
  window.addEventListener('scroll', onScroll, { passive: true });

  var burger = document.getElementById('burger');
  if (burger) {
    burger.addEventListener('click', function () {
      var open = document.body.classList.toggle('nav-open');
      burger.setAttribute('aria-expanded', String(open));
      document.body.style.overflow = open ? 'hidden' : '';
    });
    document.querySelectorAll('#mobile-nav a').forEach(function (link) {
      link.addEventListener('click', function () {
        document.body.classList.remove('nav-open');
        document.body.style.overflow = '';
        burger.setAttribute('aria-expanded', 'false');
      });
    });
  }

  /* ------------------------------------------------------------ reveals -- */

  var revealables = document.querySelectorAll('[data-reveal]');
  if ('IntersectionObserver' in window && revealables.length) {
    var io = new IntersectionObserver(function (entries) {
      entries.forEach(function (entry) {
        if (!entry.isIntersecting) return;
        var delay = parseInt(entry.target.dataset.revealDelay || '0', 10);
        setTimeout(function () { entry.target.classList.add('is-in'); }, delay);
        io.unobserve(entry.target);
      });
    }, { rootMargin: '0px 0px -8% 0px', threshold: 0.08 });
    revealables.forEach(function (el) { io.observe(el); });
  } else {
    revealables.forEach(function (el) { el.classList.add('is-in'); });
  }

  /* ----------------------------------------------------------- lightbox -- */

  var lightbox = document.getElementById('lightbox');
  if (lightbox) {
    var lightboxImg = lightbox.querySelector('img');

    document.addEventListener('click', function (event) {
      var figure = event.target.closest('.gallery figure, .reel');
      if (figure) {
        var img = figure.querySelector('img');
        var full = figure.dataset.full || (img && img.src);
        if (full) {
          lightboxImg.src = full;
          lightboxImg.alt = (img && img.alt) || '';
          lightbox.classList.add('is-open');
          lightbox.setAttribute('aria-hidden', 'false');
          document.body.style.overflow = 'hidden';
        }
        return;
      }
      if (event.target.closest('.lightbox')) closeLightbox();
    });

    document.addEventListener('keydown', function (event) {
      if (event.key === 'Escape') closeLightbox();
    });

    function closeLightbox() {
      if (!lightbox.classList.contains('is-open')) return;
      lightbox.classList.remove('is-open');
      lightbox.setAttribute('aria-hidden', 'true');
      document.body.style.overflow = '';
      setTimeout(function () { lightboxImg.src = ''; }, 250);
    }
  }

  /* -------------------------------------------------------------- forms -- */

  var loadedAt = Date.now();

  document.querySelectorAll('.enquiry-form').forEach(function (form) {
    var panel  = form.closest('.form-panel');
    var status = form.querySelector('.form-status');

    var pageField = form.querySelector('[data-page]');
    if (pageField) pageField.value = location.pathname + location.hash;

    var showError = function (field, show) {
      var wrapper = field.closest('.field');
      if (wrapper) wrapper.classList.toggle('has-error', show);
    };

    form.querySelectorAll('input, select, textarea').forEach(function (field) {
      field.addEventListener('input', function () { showError(field, false); });
    });

    form.addEventListener('submit', function (event) {
      event.preventDefault();
      status.className = 'form-status';
      status.textContent = '';

      // Client-side validation mirrors the server rules.
      var invalid = null;
      form.querySelectorAll('[required]').forEach(function (field) {
        var value = field.value.trim();
        var bad = value === '';
        if (!bad && field.type === 'email') bad = !/^[^\s@]+@[^\s@]+\.[^\s@]{2,}$/.test(value);
        if (!bad && field.type === 'tel') bad = (value.match(/\d/g) || []).length < 6;
        showError(field, bad);
        if (bad && !invalid) invalid = field;
      });
      if (invalid) { invalid.focus(); return; }

      var elapsedField = form.querySelector('[data-elapsed]');
      if (elapsedField) elapsedField.value = Math.round((Date.now() - loadedAt) / 1000);

      var button = form.querySelector('button[type=submit]');
      var label = button.innerHTML;
      form.classList.add('is-submitting');
      button.innerHTML = 'Sending…';

      fetch(form.action, {
        method: 'POST',
        body: new FormData(form),
        headers: { 'X-Requested-With': 'XMLHttpRequest', 'Accept': 'application/json' }
      })
        .then(function (response) { return response.json().catch(function () { return { ok: response.ok }; }); })
        .then(function (data) {
          if (!data.ok) throw new Error(data.message || 'Something went wrong.');

          form.style.display = 'none';
          var success = panel.querySelector('.form-success');
          if (success) success.classList.add('is-visible');
          panel.scrollIntoView({ behavior: 'smooth', block: 'center' });

          if (window.gtag) window.gtag('event', 'generate_lead', { form: form.dataset.source });
          if (window.fbq) window.fbq('track', 'Lead', { content_name: form.dataset.source });
        })
        .catch(function (error) {
          status.className = 'form-status is-error';
          status.textContent = error.message + ' Please try again in a moment.';
        })
        .finally(function () {
          form.classList.remove('is-submitting');
          button.innerHTML = label;
        });
    });
  });

  /* ------------------------------------------------------ hero slideshow -- */
  // The first photo is in the HTML so the page paints instantly. The others are fetched one at a time,
  // just ahead of when they're needed, and only on connections that can afford them.
  var reduceMotion = window.matchMedia && window.matchMedia('(prefers-reduced-motion: reduce)').matches;
  var connection = navigator.connection || {};
  var slowConnection = connection.saveData || /(^|-)2g$|^3g$/.test(connection.effectiveType || '');

  document.querySelectorAll('[data-slides]').forEach(function (box) {
    if (reduceMotion || slowConnection) return;
    var first = box.querySelector('img');
    if (!first) return;

    var slides = box.dataset.slides.split(',').filter(Boolean).map(function (entry) {
      var parts = entry.split('|');
      return { src: parts[0], pos: parts[1] || '50% 50%' };
    });
    var imgs = [first];
    var current = 0;
    var nextSlide = 0;
    var fetching = false;

    var fetchNext = function () {
      if (fetching || nextSlide >= slides.length) return;
      fetching = true;
      var slide = slides[nextSlide++];
      var img = new Image();
      img.alt = '';
      img.decoding = 'async';
      img.style.objectPosition = slide.pos;
      img.onload = function () { box.appendChild(img); imgs.push(img); fetching = false; };
      img.onerror = function () { fetching = false; };
      img.src = slide.src;
    };

    var advance = function () {
      if (document.hidden) return;
      // Don't wrap back to the start while there are still photos on their way.
      if (current + 1 >= imgs.length && nextSlide < slides.length) { fetchNext(); return; }
      if (imgs.length < 2) return;
      var prev = imgs[current];
      current = (current + 1) % imgs.length;
      var next = imgs[current];
      prev.classList.remove('is-active');
      prev.classList.add('is-prev');
      next.classList.remove('is-prev');
      next.classList.add('is-active');
      setTimeout(function () { prev.classList.remove('is-prev'); }, 2600);
      fetchNext();
    };

    var start = function () {
      fetchNext();
      setInterval(advance, 7000);
    };
    if (document.readyState === 'complete') setTimeout(start, 1500);
    else window.addEventListener('load', function () { setTimeout(start, 1500); });
  });

  /* ------------------------------------------------------ wistia videos -- */
  // The Wistia player is a large third-party script, so the page shows a plain thumbnail and only loads
  // the player when someone presses play.
  document.querySelectorAll('[data-wistia]').forEach(function (facade) {
    facade.addEventListener('click', function () {
      var id = facade.dataset.wistia;
      var add = function (src, isModule) {
        var script = document.createElement('script');
        script.src = src;
        script.async = true;
        if (isModule) script.type = 'module';
        document.head.appendChild(script);
      };
      add('https://fast.wistia.com/player.js');
      add('https://fast.wistia.com/embed/' + id + '.js', true);

      var player = document.createElement('wistia-player');
      player.setAttribute('media-id', id);
      player.setAttribute('aspect', '1.7777777777777777');
      facade.replaceWith(player);
      if (window.customElements) {
        customElements.whenDefined('wistia-player').then(function () { if (player.play) player.play(); });
      }
    }, { once: true });
  });

  /* ------------------------------------------------- card spotlight, bar -- */

  var pointer = null;
  document.addEventListener('pointermove', function (event) {
    var card = event.target.closest && event.target.closest('.card');
    if (!card) return;
    var queued = pointer !== null;
    pointer = { card: card, x: event.clientX, y: event.clientY };
    if (queued) return;
    requestAnimationFrame(function () {
      var rect = pointer.card.getBoundingClientRect();
      pointer.card.style.setProperty('--mx', (pointer.x - rect.left) + 'px');
      pointer.card.style.setProperty('--my', (pointer.y - rect.top) + 'px');
      pointer = null;
    });
  }, { passive: true });

  bar = document.createElement('div');
  bar.className = 'scroll-progress';
  bar.setAttribute('aria-hidden', 'true');
  document.body.appendChild(bar);
  onScroll();
  window.addEventListener('resize', onScroll);

  /* ------------------------------------------------------- stat counters -- */
  // "$1.5M", "513", "2.9M+" count up once when they scroll into view.
  var counters = document.querySelectorAll('.stat b, .case__stat b');
  if (!reduceMotion && 'IntersectionObserver' in window && counters.length) {
    var countIo = new IntersectionObserver(function (entries) {
      entries.forEach(function (entry) {
        if (!entry.isIntersecting) return;
        countIo.unobserve(entry.target);
        var el = entry.target;
        var match = el.textContent.match(/^([^\d]*)(\d[\d,]*\.?\d*)(.*)$/);
        if (!match) return;
        var target = parseFloat(match[2].replace(/,/g, ''));
        var decimals = (match[2].split('.')[1] || '').length;
        var commas = match[2].indexOf(',') !== -1;
        var began = null;
        var tick = function (now) {
          if (began === null) began = now;
          var t = Math.min((now - began) / 1400, 1);
          var eased = 1 - Math.pow(1 - t, 3);
          var value = (target * eased).toFixed(decimals);
          if (commas) value = Number(value).toLocaleString('en-AU', { minimumFractionDigits: decimals, maximumFractionDigits: decimals });
          el.textContent = match[1] + value + match[3];
          if (t < 1) requestAnimationFrame(tick);
        };
        requestAnimationFrame(tick);
      });
    }, { threshold: 0.6 });
    counters.forEach(function (el) { countIo.observe(el); });
  }

  /* ------------------------------------------------------------ marquee -- */
  // Duplicate the marquee content so the loop has no visible seam.
  document.querySelectorAll('.marquee__track').forEach(function (track) {
    track.innerHTML += track.innerHTML;
  });
})();
