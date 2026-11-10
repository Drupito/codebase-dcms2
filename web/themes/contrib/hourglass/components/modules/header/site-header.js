/**
 * @file
 * JS for the Site Header component.
 */

(function () {
  // The vendored Klaro! library unconditionally logs every automatic
  // non-consented-cookie cleanup via console.debug, with no config toggle
  // to disable it (see web/libraries/klaro/dist/klaro-no-translations-no-css.js,
  // updateServiceStorage()). Drop only that exact message signature; every
  // other console.debug call is left untouched.
  var nativeDebug = console.debug;
  console.debug = function () {
    if (arguments[0] === 'Deleting cookie:') {
      return;
    }
    return nativeDebug.apply(console, arguments);
  };
})();

(function (Drupal, once) {
  /**
   * Behavior for Saturn Header.
   *
   * Sets up mega-menu open/close, L2 submenu activation, search bar toggle,
   * mobile hamburger menu, overlay, keyboard accessibility, and scroll-based
   * class toggling.
   *
   * @type {Drupal~behavior}
   */
  Drupal.behaviors.corporateHeader = {
    /**
     * @param {HTMLElement|Document} context
     *   The DOM element to attach the behavior to.
     */
    attach: function (context) {
      const headers = once('corporate-header', '.site-header', context);
      headers.forEach(function (header) {
        var navItems = header.querySelectorAll(".site-header__nav-item--has-mega");
        var overlay = header.querySelector("#site-header-overlay");
        var searchToggle = header.querySelector("#search-toggle-btn");
        var searchClose = header.querySelector("#search-close-btn");
        var searchBar = header.querySelector("#site-search-bar");
        var searchInput = header.querySelector("#site-search-input");
        var searchSubmit = header.querySelector(".site-header__search-submit");
        var hamburger = header.querySelector("#mobile-menu-toggle");
        var mobileNav = header.querySelector("#site-primary-nav");

        var hasHero = !!document.querySelector(
          ".c-product-hero, .c-hero-video, .c-event-hero, .c-hero, .c-blog-detail__hero",
        );
        if (!hasHero) {
          header.classList.add("no-hero");
          document.body.classList.add("no-hero");
        }

        /**
         * Redirects the browser to the search results page for the current query.
         */
        function performSearch() {
          var query = searchInput ? searchInput.value.trim() : "";
          if (query) {
            window.location.href = "/search?query=" + encodeURIComponent(query);
          }
        }

        navItems.forEach(function (item) {
          var menu = item.querySelector(".site-header__megamenu");
          if (menu) {
            document.body.appendChild(menu);
            item._megaMenuEl = menu;
          }
        });

        if (overlay) {
          document.body.appendChild(overlay);
        }

        /**
         * Opens the mega-menu for the given nav item and shows the overlay.
         *
         * @param {HTMLElement} item - A `.site-header__nav-item--has-mega` element.
         */
        function openMegaMenu(item) {
          closeMegaMenus();
          item.classList.add("is-open");
          header.classList.add("is-menu-open");
          var trigger = item.querySelector(".site-header__nav-trigger");
          var menu = item._megaMenuEl;
          if (trigger) trigger.setAttribute("aria-expanded", "true");
          if (menu) {
            menu.classList.add("is-open");
            menu.setAttribute("aria-hidden", "false");
          }
          if (overlay) overlay.classList.add("is-visible");
        }

        /**
         * Activates a second-level nav link and shows the corresponding L3 panel.
         *
         * @param {HTMLElement} link - The `.site-header__megamenu-link` that was clicked.
         * @param {HTMLElement} menu - The parent mega-menu element.
         */
        function activateL2(link, menu) {
          var l2Id = link.getAttribute("data-l2-item");
          if (!l2Id) return;

          menu.classList.add("has-active-l2");

          menu
            .querySelectorAll(".site-header__megamenu-link")
            .forEach(function (l) {
              l.classList.remove("is-active-l2");
              l.setAttribute("aria-expanded", "false");
            });
          link.classList.add("is-active-l2");
          link.setAttribute("aria-expanded", "true");

          menu
            .querySelectorAll(".site-header__megamenu-sublist-wrapper")
            .forEach(function (wrapper) {
              wrapper.classList.remove("is-visible");
              wrapper.setAttribute("aria-hidden", "true");
            });

          var targetL3s = menu.querySelectorAll('[data-l3-list="' + l2Id + '"]');
          targetL3s.forEach(function (target) {
            target.classList.add("is-visible");
            target.setAttribute("aria-hidden", "false");
          });
        }

        /**
         * Closes the mega-menu for a single nav item and resets its ARIA state.
         *
         * @param {HTMLElement} item - A `.site-header__nav-item--has-mega` element.
         */
        function closeMegaMenu(item) {
          item.classList.remove("is-open");
          var trigger = item.querySelector(".site-header__nav-trigger");
          var menu = item._megaMenuEl;

          if (trigger) trigger.setAttribute("aria-expanded", "false");
          if (menu) {
            menu.classList.remove("is-open");
            menu.setAttribute("aria-hidden", "true");
            menu.classList.remove("has-active-l2");
            menu
              .querySelectorAll(".site-header__megamenu-link")
              .forEach(function (l) {
                l.classList.remove("is-active-l2");
              });
            menu
              .querySelectorAll(".site-header__megamenu-sublist-wrapper")
              .forEach(function (wrapper) {
                wrapper.classList.remove("is-visible");
              });
          }
        }

        /**
         * Closes all open mega-menus and hides the overlay unless the search bar
         * is still open.
         */
        function closeMegaMenus() {
          navItems.forEach(closeMegaMenu);
          if (!isSearchOpen()) {
            header.classList.remove("is-menu-open");
            if (overlay) overlay.classList.remove("is-visible");
          }
        }

        /**
         * Returns whether the search bar is currently open.
         *
         * @return {boolean} `true` when the search bar has the `is-open` class.
         */
        function isSearchOpen() {
          return searchBar && searchBar.classList.contains("is-open");
        }

        /**
         * Returns whether any mega-menu item is currently open.
         *
         * @return {boolean} `true` when at least one nav item has the `is-open` class.
         */
        function anyMenuOpen() {
          return Array.from(navItems).some(function (item) {
            return item.classList.contains("is-open");
          });
        }

        // Set --announcement-height so megamenu top offsets stay correct.
        var announcement = header.querySelector('.js-site-announcement');
        function updateAnnouncementHeight() {
          var h = announcement ? announcement.offsetHeight : 0;
          document.documentElement.style.setProperty('--announcement-height', h + 'px');
        }
        updateAnnouncementHeight();
        window.addEventListener('resize', updateAnnouncementHeight, { passive: true });

        /**
         * Toggles the `is-scrolled` class on the header based on scroll position.
         */
        function onScroll() {
          header.classList.toggle("is-scrolled", window.scrollY > 10);
        }
        window.addEventListener("scroll", onScroll, { passive: true });
        onScroll();

        navItems.forEach(function (item) {
          var trigger = item.querySelector(".site-header__nav-trigger");
          if (!trigger) return;

          trigger.addEventListener("click", function (e) {
            e.stopPropagation();
            var isOpen = item.classList.contains("is-open");
            if (isOpen) {
              closeMegaMenu(item);
              if (!isSearchOpen()) {
                header.classList.remove("is-menu-open");
                if (overlay) overlay.classList.remove("is-visible");
              }
            } else {
              openMegaMenu(item);
            }
          });

          trigger.addEventListener("keydown", function (e) {
            if (e.key === "Escape") {
              closeMegaMenus();
              trigger.focus();
            }
          });
        });

        // Register direct click handlers on L2 toggle buttons to prevent reload race conditions
        var arrowToggles = header.querySelectorAll(".site-header__megamenu-arrow-toggle");
        arrowToggles.forEach(function (toggle) {
          toggle.addEventListener("click", function (e) {
            e.preventDefault();
            e.stopPropagation();
            var wrapper = toggle.closest(".site-header__megamenu-link-wrapper");
            if (wrapper) {
              var l2Link = wrapper.querySelector(".site-header__megamenu-link");
              var resolvedMenu = toggle.closest(".site-header__megamenu");
              if (l2Link && resolvedMenu) {
                activateL2(l2Link, resolvedMenu);
              }
            }
          });
        });

        // Also register direct click handlers on mobile for the link itself
        var l2Links = header.querySelectorAll(".site-header__megamenu-link.has-children");
        l2Links.forEach(function (l2Link) {
          l2Link.addEventListener("click", function (e) {
            var isMobile = window.innerWidth < 1024;
            if (isMobile) {
              e.preventDefault();
              e.stopPropagation();
              var resolvedMenu = l2Link.closest(".site-header__megamenu");
              if (resolvedMenu) {
                activateL2(l2Link, resolvedMenu);
              }
            }
          });
        });

        document.addEventListener("click", function (e) {
          var insideHeader = header.contains(e.target);
          var insideMegaMenu = false;
          var currentMenu = null;

          navItems.forEach(function (item) {
            if (item._megaMenuEl && item._megaMenuEl.contains(e.target)) {
              insideMegaMenu = true;
              currentMenu = item._megaMenuEl;
            }
          });

          if (!insideHeader && !insideMegaMenu) {
            closeMegaMenus();
            if (isSearchOpen()) closeSearch();
          }

          var l2Link = e.target.closest(".site-header__megamenu-link.has-children");
          if (l2Link) {
            var isMobile = window.innerWidth < 1024;
            if (isMobile) {
              var resolvedMenu =
                currentMenu || l2Link.closest(".site-header__megamenu");
              if (resolvedMenu) {
                e.preventDefault();
                e.stopPropagation();
                activateL2(l2Link, resolvedMenu);
                return;
              }
            }
          }

          var arrowToggle = e.target.closest(".site-header__megamenu-arrow-toggle");
          if (arrowToggle) {
            var wrapper = arrowToggle.closest(".site-header__megamenu-link-wrapper");
            if (wrapper) {
              var l2Link = wrapper.querySelector(".site-header__megamenu-link");
              var resolvedMenu =
                currentMenu || arrowToggle.closest(".site-header__megamenu");
              if (l2Link && resolvedMenu) {
                e.preventDefault();
                e.stopPropagation();
                activateL2(l2Link, resolvedMenu);
                return;
              }
            }
          }

          var backBtn = e.target.closest(".site-header__mobile-back");
          if (backBtn && insideMegaMenu) {
            e.preventDefault();
            e.stopPropagation();
            navItems.forEach(function (item) {
              if (item._megaMenuEl === currentMenu) {
                closeMegaMenu(item);
              }
            });
          }
        });

        document.addEventListener("keydown", function (e) {
          if (e.key === "Escape") {
            if (anyMenuOpen()) closeMegaMenus();
            if (isSearchOpen()) closeSearch();
            if (hamburger && hamburger.getAttribute("aria-expanded") === "true")
              closeMobileMenu();
          }
        });

        if (overlay) {
          overlay.addEventListener("click", function () {
            closeMegaMenus();
            if (isSearchOpen()) closeSearch();
          });
        }

        /**
         * Opens the search bar, focuses the input, and closes any open mega-menus.
         */
        function openSearch() {
          header.classList.add("is-menu-open");
          header.classList.add("search-open");
          if (searchBar) {
            searchBar.classList.add("is-open");
            searchBar.setAttribute("aria-hidden", "false");
          }
          if (searchToggle) searchToggle.setAttribute("aria-expanded", "true");
          if (overlay) overlay.classList.add("is-visible");
          closeMegaMenus();
          if (searchInput) {
            setTimeout(function () {
              searchInput.focus();
            }, 320);
          }
        }

        /**
         * Closes the search bar and hides the overlay unless a mega-menu is open.
         */
        function closeSearch() {
          header.classList.remove("search-open");
          if (searchBar) {
            searchBar.classList.remove("is-open");
            searchBar.setAttribute("aria-hidden", "true");
          }
          if (searchToggle) searchToggle.setAttribute("aria-expanded", "false");
          if (!anyMenuOpen()) {
            header.classList.remove("is-menu-open");
            if (overlay) overlay.classList.remove("is-visible");
          }
        }

        if (searchToggle) {
          searchToggle.addEventListener("click", function (e) {
            e.stopPropagation();
            if (isSearchOpen() && searchInput && searchInput.value.trim()) {
              performSearch();
            } else if (isSearchOpen()) {
              closeSearch();
            } else {
              openSearch();
            }
          });
        }

        if (searchSubmit) {
          searchSubmit.addEventListener("click", function (e) {
            e.preventDefault();
            performSearch();
          });
        }

        if (searchClose) {
          searchClose.addEventListener("click", function () {
            closeSearch();
            if (searchToggle) searchToggle.focus();
          });
        }

        if (searchInput) {
          searchInput.addEventListener("keydown", function (e) {
            if (e.key === "Enter") {
              closeSuggestions();
              performSearch();
            }
            if (e.key === "Escape") {
              closeSuggestions();
            }
            if (e.key === "ArrowDown") {
              e.preventDefault();
              moveSuggestionFocus(1);
            }
            if (e.key === "ArrowUp") {
              e.preventDefault();
              moveSuggestionFocus(-1);
            }
          });
        }

        // ── Blog suggestions autocomplete ────────────────────────────────────
        var suggestionsBox = null;
        var suggestDebounce = null;
        var activeSuggestionIndex = -1;
        // Append to the inner box so the dropdown sits below the input field,
        // not relative to the full-width absolute search bar overlay.
        var searchBarInner = searchBar
          ? searchBar.querySelector(".site-header__search-bar-inner")
          : null;

        function createSuggestionsBox() {
          if (suggestionsBox) return;
          suggestionsBox = document.createElement("div");
          suggestionsBox.className = "site-header__suggestions";
          suggestionsBox.setAttribute("role", "listbox");
          suggestionsBox.setAttribute("aria-label", "Blog suggestions");
          var container = searchBarInner || searchBar;
          if (container) {
            container.appendChild(suggestionsBox);
          }
        }

        function closeSuggestions() {
          if (suggestionsBox) suggestionsBox.innerHTML = "";
          if (suggestionsBox) suggestionsBox.classList.remove("is-open");
          activeSuggestionIndex = -1;
        }

        function moveSuggestionFocus(dir) {
          if (!suggestionsBox) return;
          var items = suggestionsBox.querySelectorAll(".site-header__suggestion-item");
          if (!items.length) return;
          activeSuggestionIndex = Math.max(0, Math.min(items.length - 1, activeSuggestionIndex + dir));
          items.forEach(function (el, i) {
            el.classList.toggle("is-focused", i === activeSuggestionIndex);
          });
          if (items[activeSuggestionIndex]) {
            searchInput.value = items[activeSuggestionIndex].dataset.title || searchInput.value;
          }
        }

        function renderSuggestions(query, items) {
          createSuggestionsBox();
          suggestionsBox.innerHTML = "";
          activeSuggestionIndex = -1;

          items.forEach(function (item) {
            suggestionsBox.appendChild(item.cloneNode(true));
          });

          // "See all" footer link
          var footer = document.createElement("a");
          footer.className = "site-header__suggestions-footer";
          footer.href = "/search?query=" + encodeURIComponent(query);
          footer.textContent = items.length
            ? "See all results for “" + query + "” →"
            : "Search for “" + query + "” →";
          suggestionsBox.appendChild(footer);

          suggestionsBox.classList.add("is-open");
        }

        // The "/search/suggestions" path is a plain Views page display
        // (see views.view.blog_search.yml, "suggestions" display); results
        // are parsed out of its HTML response client-side so no custom
        // preprocessing or endpoint code is needed server-side.
        var suggestAbortController = null;

        function fetchSuggestions(query) {
          if (query.length < 2) { closeSuggestions(); return; }

          if (suggestAbortController) suggestAbortController.abort();
          suggestAbortController = new AbortController();

          fetch("/search/suggestions?query=" + encodeURIComponent(query), {
            signal: suggestAbortController.signal,
          })
            .then(function (response) { return response.text(); })
            .then(function (html) {
              var doc = new DOMParser().parseFromString(html, "text/html");
              var items = doc.querySelectorAll(".site-header__suggestion-item");
              renderSuggestions(query, items);
            })
            .catch(function (error) {
              if (error.name !== "AbortError") closeSuggestions();
            });
        }

        if (searchInput) {
          searchInput.addEventListener("input", function () {
            clearTimeout(suggestDebounce);
            var q = searchInput.value.trim();
            if (!q) { closeSuggestions(); return; }
            suggestDebounce = setTimeout(function () { fetchSuggestions(q); }, 250);
          });
        }

        document.addEventListener("click", function (e) {
          if (suggestionsBox && !suggestionsBox.contains(e.target) && e.target !== searchInput) {
            closeSuggestions();
          }
        });

        /**
         * Opens the mobile navigation drawer and locks body scroll.
         */
        function openMobileMenu() {
          header.classList.add("is-menu-open");
          if (mobileNav) mobileNav.classList.add("is-open");
          if (hamburger) hamburger.setAttribute("aria-expanded", "true");
          if (overlay) overlay.classList.add("is-visible");
          document.body.style.overflow = "hidden";
        }

        /**
         * Closes the mobile navigation drawer and restores body scroll.
         */
        function closeMobileMenu() {
          header.classList.remove("is-menu-open");
          if (mobileNav) mobileNav.classList.remove("is-open");
          if (hamburger) hamburger.setAttribute("aria-expanded", "false");
          if (overlay && !anyMenuOpen() && !isSearchOpen())
            overlay.classList.remove("is-visible");
          document.body.style.overflow = "";
        }

        if (hamburger) {
          hamburger.addEventListener("click", function (e) {
            e.stopPropagation();
            if (hamburger.getAttribute("aria-expanded") === "true") {
              closeMobileMenu();
            } else {
              openMobileMenu();
            }
          });
        }

        window.addEventListener("resize", function () {
          if (window.innerWidth >= 1024) closeMobileMenu();
        });

        // OS Detection
        const platform = navigator.platform || '';
        const userAgent = navigator.userAgent || '';
        
        if (userAgent.includes('Windows') || platform.includes('Win')) {
          document.documentElement.classList.add('windows');
          document.body.classList.add('windows');
        } else if (userAgent.includes('Mac') || platform.includes('Mac')) {
          document.documentElement.classList.add('mac');
          document.body.classList.add('mac');
        }
      });
    }
  };
})(Drupal, once);
