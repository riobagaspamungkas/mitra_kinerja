(function () {
  "use strict";

  // Ease Out Cubic: 1 - (1 - t)^3
  function easeOutCubic(t) {
    return 1 - Math.pow(1 - t, 3);
  }

  // Count-up logic
  var statItems = document.querySelectorAll(".stat-item");
  var started = false;

  function runCounters() {
    if (started) return;
    started = true;

    statItems.forEach(function (item, index) {
      var valEl = item.querySelector(".stat-val");
      if (!valEl) return;

      var target = parseFloat(valEl.getAttribute("data-target") || "0");
      var decimals = parseInt(valEl.getAttribute("data-decimals") || "0", 10);
      var duration = 1500 + index * 80;
      var offset = 480 + index * 90;

      setTimeout(function () {
        var startTime = null;

        function updateCounter(currentTime) {
          if (!startTime) startTime = currentTime;
          var elapsed = currentTime - startTime;
          var progress = Math.min(elapsed / duration, 1);
          var currentVal = easeOutCubic(progress) * target;

          valEl.textContent = currentVal.toFixed(decimals);

          if (progress < 1) {
            requestAnimationFrame(updateCounter);
          } else {
            valEl.textContent = target.toFixed(decimals);
          }
        }

        requestAnimationFrame(updateCounter);
      }, offset);
    });
  }

  // IntersectionObserver for count-up
  var statsSection = document.querySelector(".stats");
  if (statsSection && "IntersectionObserver" in window) {
    var observer = new IntersectionObserver(
      function (entries) {
        entries.forEach(function (entry) {
          if (entry.isIntersecting) {
            runCounters();
            observer.disconnect();
          }
        });
      },
      { threshold: 0.25 }
    );
    observer.observe(statsSection);
  } else {
    runCounters();
  }

  // Mobile Menu Logic
  var burgerBtn = document.getElementById("burgerBtn");
  var mobileOverlay = document.getElementById("mobileOverlay");
  var mobileSheet = document.getElementById("mobileSheet");
  var body = document.body;

  function openMenu() {
    body.classList.add("body-menu-open");
    if (burgerBtn) burgerBtn.setAttribute("aria-expanded", "true");
    if (mobileOverlay) mobileOverlay.removeAttribute("hidden");
    if (mobileSheet) mobileSheet.removeAttribute("hidden");
  }

  function closeMenu() {
    body.classList.remove("body-menu-open");
    if (burgerBtn) burgerBtn.setAttribute("aria-expanded", "false");
    if (mobileOverlay) mobileOverlay.setAttribute("hidden", "");
    if (mobileSheet) mobileSheet.setAttribute("hidden", "");
  }

  function toggleMenu() {
    if (body.classList.contains("body-menu-open")) {
      closeMenu();
    } else {
      openMenu();
    }
  }

  if (burgerBtn) {
    burgerBtn.addEventListener("click", toggleMenu);
  }

  if (mobileOverlay) {
    mobileOverlay.addEventListener("click", closeMenu);
  }

  document.addEventListener("keydown", function (e) {
    if (e.key === "Escape" && body.classList.contains("body-menu-open")) {
      closeMenu();
      if (burgerBtn) burgerBtn.focus();
    }
  });

  window.addEventListener("resize", function () {
    if (window.innerWidth > 720 && body.classList.contains("body-menu-open")) {
      closeMenu();
    }
  });

  // Close when clicking mobile sheet links
  var sheetLinks = document.querySelectorAll(".sheet-link, .sheet-sign-in");
  sheetLinks.forEach(function (link) {
    link.addEventListener("click", closeMenu);
  });
})();
