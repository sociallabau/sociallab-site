/* Social Lab — ad landing page: multi-step qualifying form, sticky CTA, video facade. */

(function () {
  'use strict';

  var loadedAt = Date.now();
  var params = new URLSearchParams(window.location.search);
  var TRACKED = ['utm_source', 'utm_medium', 'utm_campaign', 'utm_content', 'utm_term'];
  var reduceMotion = window.matchMedia && window.matchMedia('(prefers-reduced-motion: reduce)').matches;

  var quizzes = [];
  var submitted = false;

  /* ---------------------------------------------------------------- form -- */

  function initQuiz(quiz) {
    var form = quiz.querySelector('form');
    var steps = [].slice.call(quiz.querySelectorAll('[data-step]'));
    var nav = quiz.querySelector('[data-nav]');
    var back = quiz.querySelector('[data-back]');
    var next = quiz.querySelector('[data-next]');
    var count = quiz.querySelector('[data-count]');
    var dots = quiz.querySelector('[data-dots]').children; // dot, line, dot, line, dot
    var status = quiz.querySelector('.form-status');
    var current = 1;
    var group = null;

    // Carry the ad's UTM tags through with the lead, so every enquiry can be traced to its campaign.
    TRACKED.forEach(function (key) {
      var value = params.get(key);
      if (value && form.elements[key]) form.elements[key].value = value.slice(0, 120);
    });
    form.querySelector('[data-page]').value = window.location.pathname;

    // Only the screening question that matches the business type is live: the rest are disabled so they never submit.
    function setGroup(name) {
      group = name;
      quiz.querySelectorAll('[data-qgroup]').forEach(function (g) {
        var on = g.dataset.qgroup === name;
        g.hidden = !on;
        g.querySelectorAll('input').forEach(function (input) {
          input.disabled = !on;
          if (!on) input.checked = false;
        });
      });
    }

    function answered(n) {
      if (n === 1) return !!form.querySelector('input[name="business_type"]:checked');
      if (n === 2) {
        var g = quiz.querySelector('[data-qgroup="' + group + '"]');
        if (!g) return false;
        return [].every.call(g.querySelectorAll('.opts'), function (opts) { return !!opts.querySelector('input:checked'); });
      }
      return true;
    }

    function refresh() {
      next.disabled = !answered(current);
      quiz.querySelectorAll('[data-error]').forEach(function (el) { el.hidden = true; });
    }

    function show(n, userInitiated) {
      current = n;
      quiz.dataset.step = String(n);
      steps.forEach(function (s) { s.hidden = Number(s.dataset.step) !== n; });
      count.textContent = 'Step ' + n + ' of 3';
      [].forEach.call(dots, function (el, i) {
        var isDot = i % 2 === 0;
        var index = isDot ? i / 2 + 1 : (i + 1) / 2; // the step a dot stands for, or the one a line leads to
        el.classList.toggle('is-on', index <= n);
        if (isDot) el.classList.toggle('is-current', index === n);
      });
      nav.hidden = false;
      back.style.visibility = n === 1 ? 'hidden' : 'visible';
      refresh();

      if (!userInitiated) return;
      var visibleStep = steps[n - 1];
      var title = visibleStep.querySelector('.qgroup:not([hidden]) .step__title') || visibleStep.querySelector('.step__title');
      if (title) title.focus({ preventScroll: true });
      // Keep the top of the form on screen when the next step is taller than the last.
      if (quiz.getBoundingClientRect().top < 0) {
        quiz.scrollIntoView({ behavior: reduceMotion ? 'auto' : 'smooth', block: 'start' });
      }
    }

    function advance() {
      if (!answered(current)) {
        var error = steps[current - 1].querySelector('[data-error]');
        if (error) error.hidden = false;
        return;
      }
      if (current < 3) show(current + 1, true);
    }

    quiz.addEventListener('change', function (event) {
      if (event.target.name === 'business_type') setGroup(event.target.dataset.group);
      refresh();
    });

    // Tapping or clicking an option moves straight on. Keyboard users get no auto-advance
    // (arrow keys would skip past the options), so they use the Continue button instead.
    quiz.addEventListener('click', function (event) {
      var option = event.target.closest && event.target.closest('.opt');
      if (!option || event.target.tagName === 'INPUT') return;
      setTimeout(function () { if (answered(current)) advance(); }, 240);
    });

    next.addEventListener('click', advance);
    back.addEventListener('click', function () { if (current > 1) show(current - 1, true); });

    var showError = function (field, on) {
      var wrapper = field.closest('.qfield');
      if (wrapper) wrapper.classList.toggle('has-error', on);
    };
    form.querySelectorAll('.qfield input').forEach(function (field) {
      field.addEventListener('input', function () { showError(field, false); });
    });

    form.addEventListener('submit', function (event) {
      event.preventDefault();
      if (current !== 3) { advance(); return; }

      status.className = 'form-status';
      status.textContent = '';

      // Mirrors the server rules in api/enquiry.php.
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

      form.querySelector('[data-elapsed]').value = Math.round((Date.now() - loadedAt) / 1000);

      var button = form.querySelector('button[type=submit]');
      var label = button.innerHTML;
      form.classList.add('is-submitting');
      button.textContent = 'Sending…';

      fetch(form.action, {
        method: 'POST',
        body: new FormData(form),
        headers: { 'X-Requested-With': 'XMLHttpRequest', 'Accept': 'application/json' }
      })
        .then(function (response) { return response.json().catch(function () { return { ok: response.ok }; }); })
        .then(function (data) {
          if (!data.ok) throw new Error(data.message || 'Something went wrong.');

          var firstName = form.elements.name.value.trim().split(/\s+/)[0];
          submitted = true;
          document.documentElement.classList.add('is-done');
          // Once one form is in, retire every copy of it on the page so nobody submits twice.
          quizzes.forEach(function (q) { q.finish(firstName); });
          quiz.scrollIntoView({ behavior: reduceMotion ? 'auto' : 'smooth', block: 'center' });

          if (window.gtag) window.gtag('event', 'generate_lead', { form: form.dataset.source });
          if (window.fbq) window.fbq('track', 'Lead', { content_name: form.dataset.source });
        })
        .catch(function (error) {
          status.className = 'form-status is-error';
          status.textContent = error.message + ' Please try again in a moment, or call us on 0459 224 408.';
        })
        .finally(function () {
          form.classList.remove('is-submitting');
          button.innerHTML = label;
        });
    });

    quizzes.push({
      el: quiz,
      finish: function (firstName) {
        form.hidden = true;
        var done = quiz.querySelector('[data-done]');
        var title = quiz.querySelector('[data-done-title]');
        if (firstName) title.textContent = 'Got it, ' + firstName + '. Thanks.';
        done.hidden = false;
      }
    });

    show(1, false);
    nav.hidden = false;
  }

  document.querySelectorAll('[data-quiz]').forEach(initQuiz);

  /* ------------------------------------------------------------- sticky -- */
  // On phones a bar follows the reader down the page once the form has scrolled out of view.

  var sticky = document.querySelector('[data-sticky]');
  if (sticky && 'IntersectionObserver' in window) {
    var inView = {};
    var link = sticky.querySelector('a');
    var update = function () {
      var formVisible = Object.keys(inView).some(function (k) { return inView[k]; });
      var on = !formVisible && !submitted && window.scrollY > 300;
      sticky.classList.toggle('is-on', on);
      sticky.setAttribute('aria-hidden', String(!on));
      link.tabIndex = on ? 0 : -1;
    };
    var io = new IntersectionObserver(function (entries) {
      entries.forEach(function (entry) {
        inView[entry.target.dataset.i] = entry.isIntersecting;
      });
      update();
    });
    quizzes.forEach(function (q, i) { q.el.dataset.i = String(i); io.observe(q.el); });
    window.addEventListener('scroll', update, { passive: true });
  }

  /* --------------------------------------------------------- wistia video -- */
  // The Wistia player is a big third-party script, so the page shows a plain thumbnail
  // and only loads the player when someone presses play.

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
})();
