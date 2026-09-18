/* Social Lab — site behaviour: nav, reveals, lightbox, AJAX enquiry forms. */

(function () {
  'use strict';

  /* ------------------------------------------------------------- header -- */

  var header = document.getElementById('site-header');
  var onScroll = function () {
    if (header) header.classList.toggle('is-stuck', window.scrollY > 24);
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

  /* ------------------------------------------------------------ marquee -- */
  // Duplicate the marquee content so the loop has no visible seam.
  document.querySelectorAll('.marquee__track').forEach(function (track) {
    track.innerHTML += track.innerHTML;
  });
})();
