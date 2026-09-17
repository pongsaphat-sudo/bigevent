import { PageFlip } from '/assets/vendor/page-flip/page-flip.module.js';

const reader = document.querySelector('[data-native-reader]');

if (reader) {
  const stage = reader.querySelector('[data-reader-stage]');
  const message = reader.querySelector('[data-reader-message]');
  const counter = reader.querySelector('[data-reader-counter]');
  const previous = reader.querySelector('[data-reader-prev]');
  const next = reader.querySelector('[data-reader-next]');
  const thumbnailButton = reader.querySelector('[data-reader-thumbnails]');
  const thumbnailPanel = reader.querySelector('[data-reader-thumbnails-panel]');
  const fullscreenButton = reader.querySelector('[data-reader-fullscreen]');
  const zoomLabel = reader.querySelector('[data-reader-zoom-label]');
  const zoomOut = reader.querySelector('[data-reader-zoom-out]');
  const zoomIn = reader.querySelector('[data-reader-zoom-in]');
  const english = reader.dataset.lang === 'en';
  let book = reader.querySelector('[data-reader-book]');
  let pdf = null;
  let pageFlip = null;
  let pageElements = [];
  let pageRatio = 1.414;
  let zoom = 1;
  let thumbnailsLoaded = false;
  let resizeTimer;
  let buildVersion = 0;
  const pageImages = new Map();
  const pageImageWidths = new Map();
  const pendingPages = new Map();

  function visiblePages() {
    if (!pageFlip) return [1];
    const first = pageFlip.getCurrentPageIndex() + 1;
    return first === 1 || pageFlip.getOrientation() !== 'landscape'
      ? [first]
      : [first, first + 1].filter((number) => number <= pdf.numPages);
  }

  function updateControls() {
    if (!pdf || !pageFlip) return;
    const pages = visiblePages();
    counter.textContent = `${pages.join('–')} / ${pdf.numPages}`;
    previous.disabled = pages[0] <= 1;
    next.disabled = pages.at(-1) >= pdf.numPages;
    zoomLabel.textContent = `${Math.round(zoom * 100)}%`;
    zoomOut.disabled = zoom <= 0.75;
    zoomIn.disabled = zoom >= 2;
    thumbnailPanel.querySelectorAll('button').forEach((button) => {
      const selected = pages.includes(Number(button.dataset.page));
      button.classList.toggle('is-selected', selected);
      button.setAttribute('aria-current', selected ? 'page' : 'false');
    });
  }

  function trimImageCache() {
    if (pageImages.size <= 18 || !pageFlip) return;
    const current = visiblePages()[0];
    for (const [number, source] of pageImages) {
      if (pageImages.size <= 14) break;
      if (Math.abs(number - current) <= 5 || pendingPages.has(number)) continue;
      pageImages.delete(number);
      pageImageWidths.delete(number);
      pageElements[number - 1]?.querySelector('img')?.removeAttribute('src');
      if (source.startsWith('blob:')) window.setTimeout(() => URL.revokeObjectURL(source), 2000);
    }
  }

  function pageWidth() {
    // Zoom can deliberately overflow the stage, where the user can scroll.
    const available = Math.max(220, stage.clientWidth - 56);
    const singlePage = available < 800;
    const heightLimit = document.fullscreenElement === reader
      ? Math.max(180, (stage.clientHeight - 36) / pageRatio)
      : 520;
    return Math.round(Math.min(520, heightLimit, singlePage ? available : available / 2) * zoom);
  }

  async function renderPage(number) {
    if (!pdf || number < 1 || number > pdf.numPages) return null;
    const targetWidth = Math.min(1600, Math.max(900, pageWidth() * Math.min(window.devicePixelRatio || 1, 2)));
    if (pageImages.has(number) && (pageImageWidths.get(number) || 0) >= targetWidth - 50) return pageImages.get(number);
    if (pendingPages.has(number)) return pendingPages.get(number);
    const job = (async () => {
      const page = await pdf.getPage(number);
      const natural = page.getViewport({ scale: 1 });
      const viewport = page.getViewport({ scale: targetWidth / natural.width });
      const canvas = document.createElement('canvas');
      canvas.width = Math.ceil(viewport.width);
      canvas.height = Math.ceil(viewport.height);
      await page.render({ canvasContext: canvas.getContext('2d', { alpha: false }), viewport }).promise;
      const blob = await new Promise((resolve) => canvas.toBlob(resolve, 'image/webp', 0.9));
      const source = blob ? URL.createObjectURL(blob) : canvas.toDataURL('image/png');
      const previousSource = pageImages.get(number);
      pageImages.set(number, source);
      pageImageWidths.set(number, targetWidth);
      const image = pageElements[number - 1]?.querySelector('img');
      if (image) image.src = source;
      if (previousSource?.startsWith('blob:')) window.setTimeout(() => URL.revokeObjectURL(previousSource), 2000);
      return source;
    })();
    pendingPages.set(number, job);
    try {
      return await job;
    } catch (error) {
      console.error(`E-Catalog page ${number} could not be rendered`, error);
      return null;
    } finally {
      pendingPages.delete(number);
    }
  }

  function preloadAround() {
    const first = visiblePages()[0];
    for (let number = Math.max(1, first - 2); number <= Math.min(pdf.numPages, first + 4); number += 1) {
      void renderPage(number);
    }
  }

  function makePage(number) {
    const sheet = document.createElement('div');
    sheet.className = 'native-reader__sheet';
    sheet.dataset.page = String(number);
    if (number === 1 || number === pdf.numPages) sheet.dataset.density = 'hard';
    const image = document.createElement('img');
    image.alt = `${english ? 'Catalog page' : 'หน้าแคตตาล็อก'} ${number}`;
    image.draggable = false;
    image.decoding = 'async';
    if (pageImages.has(number)) image.src = pageImages.get(number);
    const label = document.createElement('span');
    label.textContent = `${number} / ${pdf.numPages}`;
    sheet.append(image, label);
    return sheet;
  }

  function buildBook(startPage = 0) {
    if (!pdf) return;
    const version = ++buildVersion;
    if (pageFlip) pageFlip.destroy();
    book = document.createElement('div');
    book.className = 'native-reader__book';
    book.dataset.readerBook = '';
    stage.insertBefore(book, message);
    pageElements = Array.from({ length: pdf.numPages }, (_, index) => makePage(index + 1));
    const width = pageWidth();
    pageFlip = new PageFlip(book, {
      width,
      height: Math.round(width * pageRatio),
      size: 'fixed',
      autoSize: true,
      usePortrait: true,
      showCover: true,
      useMouseEvents: true,
      disableFlipByClick: false,
      showPageCorners: true,
      mobileScrollSupport: true,
      drawShadow: true,
      maxShadowOpacity: 0.42,
      flippingTime: window.matchMedia('(prefers-reduced-motion: reduce)').matches ? 120 : 850,
      startPage: Math.min(startPage, pdf.numPages - 1),
    });
    pageFlip.on('flip', () => {
      updateControls();
      preloadAround();
      trimImageCache();
    });
    pageFlip.on('changeOrientation', () => {
      updateControls();
      preloadAround();
    });
    pageFlip.loadFromHTML(pageElements);
    pageFlip.on('init', () => {
      if (version !== buildVersion) return;
      updateControls();
      preloadAround();
    });
    updateControls();
    preloadAround();
  }

  function turn(direction) {
    if (!pageFlip) return;
    if (direction > 0 && !next.disabled) pageFlip.flipNext('bottom');
    if (direction < 0 && !previous.disabled) pageFlip.flipPrev('bottom');
  }

  function goToPage(number) {
    if (!pageFlip || number < 1 || number > pdf.numPages) return;
    void renderPage(number);
    pageFlip.flip(number - 1, 'bottom');
    stage.scrollTo({ left: 0, top: 0 });
  }

  async function loadThumbnails() {
    if (thumbnailsLoaded || !pdf) return;
    thumbnailsLoaded = true;
    for (let number = 1; number <= pdf.numPages; number += 1) {
      const button = document.createElement('button');
      button.type = 'button';
      button.dataset.page = String(number);
      button.setAttribute('aria-label', `${english ? 'Go to page' : 'ไปหน้า'} ${number}`);
      const canvas = document.createElement('canvas');
      const label = document.createElement('span');
      label.textContent = `${english ? 'Page' : 'หน้า'} ${number}`;
      button.append(canvas, label);
      button.addEventListener('click', () => {
        goToPage(number);
        thumbnailPanel.hidden = true;
        thumbnailButton.setAttribute('aria-expanded', 'false');
      });
      thumbnailPanel.append(button);
      try {
        const page = await pdf.getPage(number);
        const viewport = page.getViewport({ scale: 0.2 });
        canvas.width = Math.ceil(viewport.width);
        canvas.height = Math.ceil(viewport.height);
        await page.render({ canvasContext: canvas.getContext('2d', { alpha: false }), viewport }).promise;
      } catch (error) {
        console.error('E-Catalog thumbnail rendering failed', error);
      }
    }
    updateControls();
  }

  previous.addEventListener('click', () => turn(-1));
  next.addEventListener('click', () => turn(1));
  thumbnailButton.addEventListener('click', () => {
    thumbnailPanel.hidden = !thumbnailPanel.hidden;
    thumbnailButton.setAttribute('aria-expanded', String(!thumbnailPanel.hidden));
    if (!thumbnailPanel.hidden) void loadThumbnails();
  });
  fullscreenButton.addEventListener('click', async () => {
    if (document.fullscreenElement === reader) await document.exitFullscreen();
    else if (reader.requestFullscreen) await reader.requestFullscreen();
  });
  document.addEventListener('fullscreenchange', () => {
    window.setTimeout(() => buildBook(pageFlip?.getCurrentPageIndex() ?? 0), 100);
  });
  zoomOut.addEventListener('click', () => {
    zoom = Math.max(0.75, Math.round((zoom - 0.25) * 100) / 100);
    buildBook(pageFlip?.getCurrentPageIndex() ?? 0);
  });
  zoomIn.addEventListener('click', () => {
    zoom = Math.min(2, Math.round((zoom + 0.25) * 100) / 100);
    buildBook(pageFlip?.getCurrentPageIndex() ?? 0);
  });
  reader.addEventListener('keydown', (event) => {
    if (event.key === 'ArrowRight') { event.preventDefault(); turn(1); }
    if (event.key === 'ArrowLeft') { event.preventDefault(); turn(-1); }
  });
  stage.addEventListener('pointerdown', () => stage.focus({ preventScroll: true }));
  window.addEventListener('resize', () => {
    window.clearTimeout(resizeTimer);
    resizeTimer = window.setTimeout(() => buildBook(pageFlip?.getCurrentPageIndex() ?? 0), 200);
  });
  window.addEventListener('pagehide', () => {
    for (const source of pageImages.values()) if (source.startsWith('blob:')) URL.revokeObjectURL(source);
  }, { once: true });

  async function initializeReader() {
    try {
      const pdfjs = await import('/assets/vendor/pdfjs/pdf.mjs');
      pdfjs.GlobalWorkerOptions.workerSrc = '/assets/vendor/pdfjs/pdf.worker.mjs';
      pdf = await pdfjs.getDocument({
        url: reader.dataset.pdfUrl,
        disableRange: Number(reader.dataset.pdfBytes || 0) > 0 && Number(reader.dataset.pdfBytes) < 8 * 1024 * 1024,
        cMapUrl: '/assets/vendor/pdfjs/cmaps/',
        cMapPacked: true,
        standardFontDataUrl: '/assets/vendor/pdfjs/standard_fonts/',
      }).promise;
      const firstPage = await pdf.getPage(1);
      const viewport = firstPage.getViewport({ scale: 1 });
      pageRatio = viewport.height / viewport.width;
      message.textContent = english ? 'Preparing the book…' : 'กำลังเตรียมหนังสือ…';
      if (!await renderPage(1)) throw new Error('The first page could not be rendered');
      buildBook();
      message.hidden = true;
      for (const number of [2, 3]) if (number <= pdf.numPages) void renderPage(number);
    } catch (error) {
      message.hidden = false;
      message.textContent = english ? 'The book could not be loaded. Please try again.' : 'โหลดหนังสือไม่สำเร็จ กรุณาลองใหม่อีกครั้ง';
      console.error('E-Catalog loading failed', error);
    }
  }

  if ('IntersectionObserver' in window) {
    const observer = new IntersectionObserver((entries) => {
      if (!entries.some((entry) => entry.isIntersecting)) return;
      observer.disconnect();
      void initializeReader();
    }, { rootMargin: '400px 0px' });
    observer.observe(reader);
  } else {
    void initializeReader();
  }
}
