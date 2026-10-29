/**
 * Mitra Kinerja: Native PJAX Navigation Engine
 * Keeps the Hero Header (.app-hero) and Sidebar (.app-sidebar) permanently mounted
 * Swaps only .page-body, updates title and active nav, with zero flicker.
 */
(function() {
    'use strict';

    if (window._MK_PJAX_INITIALIZED) return;
    window._MK_PJAX_INITIALIZED = true;

    var isNavigating = false;
    var parser = new DOMParser();

    function setProgressBar(pct, duration) {
        var bar = document.getElementById('pageProgressBar');
        if (!bar) return;
        bar.style.transition = duration ? ('width ' + duration + 's cubic-bezier(0.16, 1, 0.3, 1)') : 'none';
        bar.style.width = pct + '%';
    }

    function resetProgressBar() {
        var bar = document.getElementById('pageProgressBar');
        if (!bar) return;
        bar.style.width = '100%';
        setTimeout(function() {
            bar.style.transition = 'none';
            bar.style.width = '0%';
        }, 220);
    }

    function isPjaxEligible(link, event) {
        if (!link || !link.href) return false;

        // Skip modified clicks (Ctrl, Cmd, Shift, Alt, middle-click)
        if (event.metaKey || event.ctrlKey || event.shiftKey || event.altKey || event.button !== 0) {
            return false;
        }

        // Skip target="_blank" or download attribute
        if (link.target === '_blank' || link.hasAttribute('download')) return false;

        // Origin check
        var linkUrl;
        try {
            linkUrl = new URL(link.href, window.location.href);
        } catch(e) {
            return false;
        }

        if (linkUrl.origin !== window.location.origin) return false;
        if (linkUrl.protocol !== 'http:' && linkUrl.protocol !== 'https:') return false;

        // Skip same page anchors
        if (linkUrl.pathname === window.location.pathname && linkUrl.search === window.location.search && linkUrl.hash) {
            return false;
        }

        // Skip logout and static download extensions
        var path = linkUrl.pathname.toLowerCase();
        if (path.indexOf('logout.php') !== -1) return false;
        if (path.match(/\.(pdf|xlsx|csv|rar|zip|docx|png|jpg|jpeg|webp|gif|txt)$/i)) return false;

        return true;
    }

    function runScripts(container) {
        var scripts = Array.from(container.querySelectorAll('script'));
        scripts.forEach(function(oldScript) {
            var newScript = document.createElement('script');
            Array.from(oldScript.attributes).forEach(function(attr) {
                newScript.setAttribute(attr.name, attr.value);
            });
            newScript.textContent = oldScript.textContent;
            document.body.appendChild(newScript);
            newScript.parentNode.removeChild(newScript);
        });
    }

    function updateSidebarActive(newDoc) {
        var newActiveLink = newDoc.querySelector('.sidebar-nav a.active');
        var curNavLinks = document.querySelectorAll('.sidebar-nav a');
        curNavLinks.forEach(function(a) { a.classList.remove('active'); });

        if (newActiveLink) {
            var href = newActiveLink.getAttribute('href');
            var matchingCur = document.querySelector('.sidebar-nav a[href="' + href + '"]');
            if (matchingCur) {
                matchingCur.classList.add('active');
            }
        }
    }

    function updateHeaderElements(newDoc) {
        var curNotif = document.getElementById('headerNotifWrap');
        var newNotif = newDoc.getElementById('headerNotifWrap');
        if (curNotif && newNotif) {
            curNotif.innerHTML = newNotif.innerHTML;
        }

        var curUserDrop = document.getElementById('headerUserDropdown');
        var newUserDrop = newDoc.getElementById('headerUserDropdown');
        if (curUserDrop && newUserDrop) {
            curUserDrop.innerHTML = newUserDrop.innerHTML;
        }
    }

    function navigateTo(url, pushState) {
        if (isNavigating) return;
        isNavigating = true;

        var currentBody = document.querySelector('.page-body');
        if (currentBody) {
            currentBody.classList.add('is-leaving');
        }

        setProgressBar(35, 0.35);

        fetch(url, {
            headers: {
                'X-PJAX': 'true',
                'X-Requested-With': 'XMLHttpRequest'
            }
        })
        .then(function(res) {
            setProgressBar(75, 0.25);
            if (!res.ok) {
                throw new Error('HTTP ' + res.status);
            }
            if (res.url && res.url.indexOf('login.php') !== -1 && window.location.pathname.indexOf('login.php') === -1) {
                window.location.href = res.url;
                return null;
            }
            return res.text();
        })
        .then(function(html) {
            if (!html) return;

            var newDoc = parser.parseFromString(html, 'text/html');
            var newBody = newDoc.querySelector('.page-body');

            if (!newBody) {
                window.location.href = url;
                return;
            }

            if (newDoc.title) {
                document.title = newDoc.title;
            }

            updateSidebarActive(newDoc);
            updateHeaderElements(newDoc);

            var targetBody = document.querySelector('.page-body');
            if (targetBody) {
                targetBody.innerHTML = newBody.innerHTML;
                targetBody.className = newBody.className;
                targetBody.classList.remove('is-leaving');

                targetBody.style.animation = 'none';
                targetBody.offsetHeight;
                targetBody.style.animation = '';
            }

            if (pushState) {
                history.pushState({ pjax: true, url: url }, newDoc.title, url);
            }

            window.scrollTo(0, 0);

            if (targetBody) {
                runScripts(targetBody);
            }

            if (typeof initSearchableDropdowns === 'function') {
                try { initSearchableDropdowns(); } catch(e){}
            }

            try {
                document.dispatchEvent(new Event('DOMContentLoaded'));
                window.dispatchEvent(new Event('pageshow'));
                window.dispatchEvent(new CustomEvent('pjax:complete', { detail: { url: url } }));
            } catch(e){}

            resetProgressBar();
            isNavigating = false;
        })
        .catch(function(err) {
            console.warn('[PJAX] Fallback to native navigation:', err);
            resetProgressBar();
            isNavigating = false;
            window.location.href = url;
        });
    }

    document.addEventListener('click', function(e) {
        var link = e.target.closest('a');
        if (!link) return;

        if (isPjaxEligible(link, e)) {
            e.preventDefault();
            navigateTo(link.href, true);
        }
    });

    window.addEventListener('popstate', function(e) {
        navigateTo(window.location.href, false);
    });

    window.pjaxNavigate = function(url) {
        navigateTo(url, true);
    };

})();
