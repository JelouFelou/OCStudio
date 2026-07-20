(function () {
    const PUBLICATION_PATH_RE = /^\/p\/[^/]+/;
    const PUBLIC_PROFILE_PATH_RE = /^\/u\/[^/]+/;
    let overlay = null;
    let iframe = null;
    let previousFocus = null;

    function publicationUrl(rawUrl) {
        try {
            const url = new URL(rawUrl, window.location.origin);
            if (url.origin !== window.location.origin || !PUBLICATION_PATH_RE.test(url.pathname)) {
                return null;
            }
            return url;
        } catch (error) {
            return null;
        }
    }

    function withEmbed(url) {
        const modalUrl = new URL(url.href);
        modalUrl.searchParams.set('embed', '1');
        return modalUrl.href;
    }

    function sameOriginUrl(rawUrl) {
        try {
            const url = new URL(rawUrl, window.location.origin);
            return url.origin === window.location.origin ? url : null;
        } catch (error) {
            return null;
        }
    }

    function ensureOverlay() {
        if (overlay && iframe) {
            return;
        }

        overlay = document.createElement('div');
        overlay.className = 'publication-preview-modal';
        overlay.hidden = true;
        overlay.setAttribute('role', 'dialog');
        overlay.setAttribute('aria-modal', 'true');
        overlay.setAttribute('aria-label', 'Podglad publikacji');

        const close = document.createElement('button');
        close.type = 'button';
        close.className = 'publication-preview-close';
        close.setAttribute('aria-label', 'Zamknij podglad');
        close.innerHTML = '<i class="fa-solid fa-arrow-left"></i>';
        close.addEventListener('click', () => closePreview());

        const frameShell = document.createElement('div');
        frameShell.className = 'publication-preview-frame-shell';

        iframe = document.createElement('iframe');
        iframe.className = 'publication-preview-frame';
        iframe.title = 'Podglad publikacji';
        iframe.loading = 'eager';
        iframe.referrerPolicy = 'same-origin';

        frameShell.appendChild(iframe);
        overlay.append(close, frameShell);
        overlay.addEventListener('click', event => {
            if (event.target === overlay) {
                closePreview();
            }
        });

        document.body.appendChild(overlay);
    }

    function openPreview(rawUrl) {
        const url = publicationUrl(rawUrl);
        if (!url) {
            return false;
        }

        ensureOverlay();
        previousFocus = document.activeElement instanceof HTMLElement ? document.activeElement : null;
        iframe.src = withEmbed(url);
        overlay.hidden = false;
        document.body.classList.add('publication-preview-open');
        overlay.querySelector('.publication-preview-close')?.focus();
        return true;
    }

    function closePreview() {
        if (!overlay || overlay.hidden) {
            return;
        }

        overlay.hidden = true;
        document.body.classList.remove('publication-preview-open');
        if (iframe) {
            iframe.src = 'about:blank';
        }
        previousFocus?.focus?.();
    }

    document.addEventListener('click', event => {
        const link = event.target.closest?.('a[href]');
        if (!link || event.defaultPrevented || event.button !== 0) {
            return;
        }
        if (event.metaKey || event.ctrlKey || event.shiftKey || event.altKey) {
            return;
        }
        if (link.target && link.target !== '_self') {
            return;
        }
        if (link.dataset.noPublicationPreview === '1') {
            return;
        }

        if (window.self !== window.top) {
            const url = sameOriginUrl(link.href);
            if (!url) {
                return;
            }
            if (PUBLIC_PROFILE_PATH_RE.test(url.pathname)) {
                event.preventDefault();
                window.parent?.postMessage({ type: 'oc-publication-preview-navigate', href: url.pathname + url.search + url.hash }, window.location.origin);
                return;
            }
            if (PUBLICATION_PATH_RE.test(url.pathname)) {
                event.preventDefault();
                window.location.href = withEmbed(url);
            }
            return;
        }

        const url = publicationUrl(link.href);
        if (!url) {
            return;
        }

        if (document.body?.dataset.view === 'public_publication') {
            return;
        }

        event.preventDefault();
        openPreview(url.href);
    });

    document.addEventListener('keydown', event => {
        if (event.key === 'Escape') {
            closePreview();
        }
    });

    window.addEventListener('message', event => {
        if (event.origin === window.location.origin && event.data?.type === 'oc-publication-preview-close') {
            closePreview();
        }
        if (event.origin === window.location.origin && event.data?.type === 'oc-publication-preview-navigate') {
            const url = sameOriginUrl(event.data.href || '');
            if (!url) {
                return;
            }
            closePreview();
            window.location.href = url.pathname + url.search + url.hash;
        }
    });

    window.OCPublicationPreview = {
        open: openPreview,
        close: closePreview
    };
})();
