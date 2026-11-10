((Drupal, once) => {
  Drupal.behaviors.blogDetail = {
    attach(context) {
      once('blog-detail', '.c-blog-detail', context).forEach((article) => {
        const body = article.querySelector('.js-blog-body');
        const tocNav = article.querySelector('.c-blog-detail__toc-nav');
        const toc = article.querySelector('.js-blog-toc');

        if (!body || !tocNav) return;

        // Build TOC from h2 and h4 headings in the body
        const headings = body.querySelectorAll('h2, h4');
        if (headings.length < 2) {
          if (toc) toc.style.display = 'none';
          return;
        }

        const getHeaderOffset = () => (window.innerWidth < 1025 ? 80 : 144) + 16;

        headings.forEach((h, i) => {
          if (!h.id) h.id = `blog-section-${i}`;
          const a = document.createElement('a');
          a.href = `#${h.id}`;
          a.textContent = h.textContent;
          if (h.tagName === 'H4') a.style.paddingLeft = '8px';
          a.addEventListener('click', (e) => {
            e.preventDefault();
            const top = h.getBoundingClientRect().top + window.pageYOffset - getHeaderOffset();
            window.scrollTo({ top, behavior: 'smooth' });
            history.pushState(null, '', a.hash);
          });
          tocNav.appendChild(a);
        });

        // Highlight active section on scroll
        const links = tocNav.querySelectorAll('a');
        const observer = new IntersectionObserver(
          (entries) => {
            entries.forEach((entry) => {
              if (entry.isIntersecting) {
                const id = entry.target.id;
                links.forEach((l) => l.classList.toggle('is-active', l.hash === `#${id}`));
              }
            });
          },
          { rootMargin: '-20% 0px -70% 0px' }
        );

        headings.forEach((h) => observer.observe(h));
      });
    },
  };
})(Drupal, once);
