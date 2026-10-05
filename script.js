const syncPublicButtonContrast = () => {
  if (document.body.dataset.page === 'admin') return;
  const styles = getComputedStyle(document.documentElement);
  [['--text', '--on-text', 1], ['--blue-deep', '--on-accent', 1], ['--blue-deep', '--on-accent-hover', 0.82]].forEach(([background, foreground, shade]) => {
    const hex = styles.getPropertyValue(background).trim();
    if (!/^#[\da-f]{6}$/i.test(hex)) return;
    const rgb = [1, 3, 5].map((offset) => parseInt(hex.slice(offset, offset + 2), 16) / 255 * shade)
      .map((channel) => channel <= 0.04045 ? channel / 12.92 : ((channel + 0.055) / 1.055) ** 2.4);
    const luminance = rgb[0] * 0.2126 + rgb[1] * 0.7152 + rgb[2] * 0.0722;
    document.body.style.setProperty(foreground, (luminance + 0.05) / 0.05 >= 1.05 / (luminance + 0.05) ? '#000000' : '#ffffff');
  });
};
syncPublicButtonContrast();

const menuToggle = document.querySelector('.menu-toggle');
const nav = document.querySelector('.nav');

const reducedMotion = window.matchMedia('(prefers-reduced-motion: reduce)');
const isMobileViewport = window.matchMedia('(max-width: 760px)').matches;

// Public personalization creates no floating elements or listeners inside the CMS.
if (document.body.dataset.page !== 'admin') {
  const pointerCanvas = document.createElement('canvas');
  pointerCanvas.className = 'pointer-canvas';
  pointerCanvas.setAttribute('aria-hidden', 'true');
  document.body.prepend(pointerCanvas);

  const canvasContext = pointerCanvas.getContext('2d');
  let canvasScale = 1;
  let lastPointerPosition = null;
  let trailColor = '200, 111, 82';
  const syncTrailColor = () => {
    const accent = getComputedStyle(document.documentElement).getPropertyValue('--blue-deep').trim();
    if (/^#[\da-f]{6}$/i.test(accent)) trailColor = [1, 3, 5].map((offset) => parseInt(accent.slice(offset, offset + 2), 16)).join(', ');
  };
  syncTrailColor();
  document.addEventListener('public-theme-applied', syncTrailColor);

  let contactMascot = document.querySelector('.contact-mascot');
  if (!contactMascot) {
    contactMascot = document.createElement('img');
    contactMascot.className = 'contact-mascot';
    contactMascot.alt = 'Dyndel Pino mascot illustration';
    const iconHref = document.querySelector('link[rel="icon"]')?.href;
    contactMascot.src = iconHref ? new URL('mascot.gif', iconHref).href : 'img/mascot.gif';
    document.body.append(contactMascot);
  } else {
    document.body.append(contactMascot);
  }
  const resizePointerCanvas = () => {
    canvasScale = Math.min(window.devicePixelRatio || 1, 2);
    pointerCanvas.width = Math.floor(window.innerWidth * canvasScale);
    pointerCanvas.height = Math.floor(window.innerHeight * canvasScale);
    pointerCanvas.style.width = `${window.innerWidth}px`;
    pointerCanvas.style.height = `${window.innerHeight}px`;
    canvasContext.setTransform(canvasScale, 0, 0, canvasScale, 0, 0);
  };

  const drawBrushStamp = (x, y, pressure = 1) => {
    const brushSize = 34 + Math.random() * 30;
    const markCount = 10;

    for (let index = 0; index < markCount; index += 1) {
      const angle = Math.random() * Math.PI * 2;
      const distance = Math.random() * brushSize * 0.55;
      const radius = brushSize * (0.18 + Math.random() * 0.2) * pressure;
      const markX = x + Math.cos(angle) * distance;
      const markY = y + Math.sin(angle) * distance;
      const gradient = canvasContext.createRadialGradient(markX, markY, 0, markX, markY, radius);
      const color = trailColor;

      gradient.addColorStop(0, `rgba(${color}, ${0.08 + Math.random() * 0.1})`);
      gradient.addColorStop(1, `rgba(${color}, 0)`);
      canvasContext.fillStyle = gradient;
      canvasContext.beginPath();
      canvasContext.arc(markX, markY, radius, 0, Math.PI * 2);
      canvasContext.fill();
    }
  };

  const paintBetween = (from, to, pressure = 1) => {
    const distance = Math.hypot(to.x - from.x, to.y - from.y);
    const steps = Math.max(1, Math.ceil(distance / 18));

    for (let step = 1; step <= steps; step += 1) {
      const progress = step / steps;
      drawBrushStamp(
        from.x + (to.x - from.x) * progress,
        from.y + (to.y - from.y) * progress,
        pressure
      );
    }
  };

  if (canvasContext && !reducedMotion.matches && !isMobileViewport) {
    resizePointerCanvas();
    window.addEventListener('resize', resizePointerCanvas);
    window.addEventListener('pointermove', (event) => {
      const currentPosition = { x: event.clientX, y: event.clientY };
      if (lastPointerPosition) {
        paintBetween(lastPointerPosition, currentPosition, event.pressure || 1);
      } else {
        drawBrushStamp(currentPosition.x, currentPosition.y, event.pressure || 1);
      }
      lastPointerPosition = currentPosition;
    });
    window.addEventListener('pointerout', (event) => {
      if (!event.relatedTarget) lastPointerPosition = null;
    });

    const fadeCanvas = () => {
      canvasContext.save();
      canvasContext.globalCompositeOperation = 'destination-out';
      canvasContext.fillStyle = 'rgba(0, 0, 0, 0.018)';
      canvasContext.fillRect(0, 0, window.innerWidth, window.innerHeight);
      canvasContext.restore();
      window.requestAnimationFrame(fadeCanvas);
    };

    window.requestAnimationFrame(fadeCanvas);
  }
}

// Resolve public destinations from their existing links, including nested project pages.
if (nav && document.body.dataset.page !== 'admin') {
  const primaryLinks = [...nav.querySelectorAll('a[href]')];
  const normalizeNavPath = (path) => path.replace(/\/index\.html$/, '/');
  const syncCurrentNavigation = () => {
    const current = new URL(window.location.href);
    const currentPath = normalizeNavPath(current.pathname);
    const destinations = primaryLinks.map((link) => ({ link, url: new URL(link.href) }));
    const samePage = (url) => url.origin === current.origin && normalizeNavPath(url.pathname) === currentPath;
    const active = destinations.find(({ url }) => url.hash && url.hash === current.hash && samePage(url))
      || destinations.find(({ url }) => !url.hash && samePage(url))
      || destinations.find(({ url }) => {
        if (url.hash || url.origin !== current.origin) return false;
        const parentPath = url.pathname.replace(/\.(?:html|php)$/, '/');
        return current.pathname.startsWith(parentPath)
          || (url.pathname.endsWith('/stories.php') && current.pathname === url.pathname.replace(/stories\.php$/, 'story.php'));
      });
    primaryLinks.forEach((link) => link.removeAttribute('aria-current'));
    if (active) active.link.setAttribute('aria-current', active.url.hash ? 'location' : 'page');
  };
  syncCurrentNavigation();
  window.addEventListener('hashchange', syncCurrentNavigation);
}

if (menuToggle && nav) {
  menuToggle.addEventListener('click', () => {
    const isOpen = nav.classList.toggle('open');
    menuToggle.setAttribute('aria-expanded', String(isOpen));
  });

  nav.querySelectorAll('a').forEach((link) => {
    link.addEventListener('click', () => {
      nav.classList.remove('open');
      menuToggle.setAttribute('aria-expanded', 'false');
    });
  });
}

const revealElements = document.querySelectorAll('.reveal');

if (revealElements.length) {
  const revealObserver = new IntersectionObserver((entries) => {
    entries.forEach((entry) => {
      if (entry.isIntersecting) {
        entry.target.classList.add('visible');
        revealObserver.unobserve(entry.target);
      }
    });
  }, { threshold: 0.12 });

  revealElements.forEach((element) => revealObserver.observe(element));
}

const aboutVideo = document.querySelector('[data-about-video]');
const aboutVideoFrame = aboutVideo?.querySelector('iframe[data-video-src]');

if (aboutVideo && aboutVideoFrame && window.location.protocol === 'file:') {
  aboutVideo.classList.add('is-local');
}

if (aboutVideo && aboutVideoFrame && window.location.protocol !== 'file:' && typeof IntersectionObserver !== 'undefined') {
  const videoObserver = new IntersectionObserver((entries) => {
    entries.forEach((entry) => {
      if (!entry.isIntersecting || aboutVideoFrame.src) return;
      aboutVideoFrame.src = aboutVideoFrame.dataset.videoSrc;
    });
  }, { threshold: 0.35 });

  videoObserver.observe(aboutVideo);
}

const typedWord = document.querySelector('[data-typed-words]');
if (typedWord && !window.matchMedia('(prefers-reduced-motion: reduce)').matches) {
  const words = typedWord.dataset.typedWords.split(',').map((word) => word.trim());
  let wordIndex = 0;
  let characterIndex = typedWord.textContent.length;
  let deleting = true;

  const typeNextWord = () => {
    const word = words[wordIndex];
    let nextDelay = 95;

    if (deleting) {
      characterIndex -= 1;
      typedWord.textContent = word.slice(0, characterIndex);
      if (characterIndex === 0) {
        deleting = false;
        wordIndex = (wordIndex + 1) % words.length;
        nextDelay = 420;
      } else {
        nextDelay = 72;
      }
    } else {
      characterIndex += 1;
      typedWord.textContent = words[wordIndex].slice(0, characterIndex);
      if (characterIndex === words[wordIndex].length) {
        deleting = true;
        nextDelay = 1400;
      } else {
        nextDelay = 95;
      }
    }

    window.setTimeout(typeNextWord, nextDelay);
  };

  window.setTimeout(typeNextWord, 1800);
}

document.querySelectorAll('.portrait-gallery .gallery-image').forEach((image, index) => {
  const direction = index % 2 === 0 ? 1 : -1;
  const duration = 9 + ((index * 2) % 6);
  const delay = (index % 4) * -0.9;
  image.style.setProperty('--drift-duration', `${duration}s`);
  image.style.setProperty('--drift-delay', `${delay}s`);
  image.style.setProperty('--drift-x-start', `${-1.4 * direction}%`);
  image.style.setProperty('--drift-x-end', `${1.4 * direction}%`);
  image.style.setProperty('--drift-y-start', `${index % 3 === 0 ? '-1%' : '0.5%'}`);
  image.style.setProperty('--drift-y-end', `${index % 3 === 0 ? '1%' : '-0.5%'}`);
  image.style.setProperty('--drift-rotate-start', `${-0.35 * direction}deg`);
  image.style.setProperty('--drift-rotate-end', `${0.35 * direction}deg`);
});

const STORAGE_KEYS = {
  projects: 'dyndelProjects',
  socials: 'dyndelSocials',
  adminSession: 'dyndelAdminSession'
};

const ADMIN_CREDENTIALS = {
  username: 'admin',
  password: 'dyndel'
};

const defaultProjects = [
  { title: 'Maison Élan', category: 'Graphic Design', image: 'https://images.unsplash.com/photo-1524758631624-e2822e304c36?auto=format&fit=crop&w=900&q=80', description: 'Luxury visual direction and packaging concept for a boutique lifestyle brand.', managed: false, showHome: true },
  { title: 'Velvet Echo', category: 'Portraits', image: 'https://images.unsplash.com/photo-1517841905240-472988babdf9?auto=format&fit=crop&w=900&q=80', description: 'An expressive portrait story exploring mood and character.', managed: false, showHome: true },
  { title: 'Northwind', category: 'Illustration', image: 'https://images.unsplash.com/photo-1493246507139-91e8fad9978e?auto=format&fit=crop&w=900&q=80', description: 'A bold illustration series inspired by atmospheric landscapes.', managed: false, showHome: true },
  { title: 'Harbor & Co', category: 'Logos', image: 'https://images.unsplash.com/photo-1516321165247-4aa89a48be28?auto=format&fit=crop&w=900&q=80', description: 'Simple and warm brand identity design for a coastal lifestyle business.', managed: false, showHome: true }
];

const defaultSocials = {
  instagram: 'https://www.instagram.com/d4dyndel',
  facebook: 'https://www.facebook.com/d4dyndel',
  twitter: 'https://twitter.com/d4dyndel',
  youtube: 'https://www.youtube.com/@d4dyndel'
};

const getStoredData = (key, fallback) => {
  try {
    const stored = localStorage.getItem(key);
    return stored ? JSON.parse(stored) : fallback;
  } catch (error) {
    return fallback;
  }
};

const saveData = (key, value) => {
  localStorage.setItem(key, JSON.stringify(value));
};

const useCmsApi = window.location.protocol === 'http:' || window.location.protocol === 'https:';
const scriptSource = document.querySelector('script[src*="script.js"]')?.src;
const cmsApi = scriptSource ? new URL('api/index.php', scriptSource).href : 'api/index.php';
const contentTypeLabels = { blog: 'Blog', news: 'News', update: 'Update', announcement: 'Announcement' };

const cmsRequest = async (action, options = {}) => {
  const response = await fetch(`${cmsApi}?action=${action}`, options);
  const data = await response.json();
  if (!response.ok) throw new Error(data.error || 'CMS request failed.');
  return data;
};

const projectList = document.getElementById('project-list');
const projectModal = document.querySelector('[data-project-modal]');
let cmsProjects = [];

const isHomeVisible = (value) => value === true || value === 1 || value === '1';
const normalizeProjectVisibility = (project) => ({
  ...project,
  showHome: project.showHome === undefined ? true : isHomeVisible(project.showHome),
  displaySize: ['standard', 'wide', 'tall', 'featured'].includes(project.displaySize) ? project.displaySize : 'standard'
});

const adminLoginForm = document.getElementById('admin-login-form');
const adminLoginPanel = document.getElementById('admin-login-panel');
const adminContent = document.getElementById('admin-content');
const adminLoginMessage = document.getElementById('admin-login-message');

const updateAdminVisibility = () => {
  const isAuthenticated = sessionStorage.getItem(STORAGE_KEYS.adminSession) === 'authenticated';
  if (adminLoginPanel) adminLoginPanel.hidden = isAuthenticated;
  if (adminContent) adminContent.hidden = !isAuthenticated;
  return isAuthenticated;
};

const restoreCmsSession = async () => {
  if (!useCmsApi || !adminLoginPanel) return;
  try {
    const session = await cmsRequest('session');
    if (session.authenticated) {
      sessionStorage.setItem(STORAGE_KEYS.adminSession, 'authenticated');
      adminLoginPanel.hidden = true;
      if (adminContent) adminContent.hidden = false;
      renderProjectList();
      if (useCmsApi) renderAdminProducts();
      renderAdminContent();
      loadAdminTheme();
    }
  } catch (error) {
    if (adminLoginMessage) adminLoginMessage.textContent = 'CMS connection unavailable.';
  }
};

if (adminLoginForm) {
  adminLoginForm.addEventListener('submit', async (event) => {
    event.preventDefault();
    const formData = new FormData(adminLoginForm);
    if (useCmsApi) {
      try {
        await cmsRequest('login', { method: 'POST', body: formData });
        sessionStorage.setItem(STORAGE_KEYS.adminSession, 'authenticated');
        adminLoginForm.reset();
        if (adminLoginMessage) adminLoginMessage.textContent = '';
        updateAdminVisibility();
        renderProjectList();
        if (useCmsApi) renderAdminProducts();
        renderAdminContent();
        loadAdminTheme();
      } catch (error) {
        if (adminLoginMessage) adminLoginMessage.textContent = error.message;
      }
      return;
    }
    const isValid = formData.get('username') === ADMIN_CREDENTIALS.username
      && formData.get('password') === ADMIN_CREDENTIALS.password;

    if (!isValid) {
      if (adminLoginMessage) adminLoginMessage.textContent = 'That login did not match.';
      return;
    }

    sessionStorage.setItem(STORAGE_KEYS.adminSession, 'authenticated');
    adminLoginForm.reset();
    if (adminLoginMessage) adminLoginMessage.textContent = '';
    updateAdminVisibility();
    renderProjectList();
    if (useCmsApi) renderAdminProducts();
    renderAdminContent();
    loadAdminTheme();
  });
}

document.getElementById('admin-logout')?.addEventListener('click', async () => {
  if (useCmsApi) {
    await cmsRequest('logout', { method: 'POST' });
  }
  sessionStorage.removeItem(STORAGE_KEYS.adminSession);
  updateAdminVisibility();
});

const renderProjectList = async () => {
  let projects = getStoredData(STORAGE_KEYS.projects, defaultProjects).map(normalizeProjectVisibility);
  if (useCmsApi) {
    try {
      projects = (await cmsRequest('projects')).projects.map(normalizeProjectVisibility);
    } catch (error) {
      if (projectList) projectList.innerHTML = `<p class="admin-note">${error.message}</p>`;
      return;
    }
  }

  if (!projectList) return;
  cmsProjects = projects;
  const projectCount = document.querySelector('[data-dashboard-project-count]');
  const homeProjectCount = document.querySelector('[data-dashboard-home-count]');
  if (projectCount) projectCount.textContent = String(projects.length);

  const renderFilteredProjects = () => {
    const search = (document.getElementById('project-search')?.value || '').trim().toLocaleLowerCase();
    const category = document.getElementById('project-category-filter')?.value || '';
    const homeFilter = document.getElementById('project-home-filter')?.value || '';
    const sort = document.getElementById('project-sort')?.value || 'order-asc';
    const filteredProjects = cmsProjects.filter((project) => {
      const matchesSearch = !search || `${project.title} ${project.description}`.toLocaleLowerCase().includes(search);
      const matchesCategory = !category || project.category === category;
      const matchesHome = homeFilter === '' || String(Number(isHomeVisible(project.showHome))) === homeFilter;
      return matchesSearch && matchesCategory && matchesHome;
    });
    filteredProjects.sort((first, second) => {
      if (sort === 'title-asc') return first.title.localeCompare(second.title);
      if (sort === 'title-desc') return second.title.localeCompare(first.title);
      const firstOrder = Number(first.sortOrder ?? first.sort_order ?? 0);
      const secondOrder = Number(second.sortOrder ?? second.sort_order ?? 0);
      return sort === 'order-desc' ? secondOrder - firstOrder : firstOrder - secondOrder;
    });
    projectList.innerHTML = filteredProjects.map((project) => `
      <article class="project-item" data-project-item>
        <img src="${project.image}" alt="${project.altText || project.title}">
        <div class="project-copy">
          <div class="project-meta"><span class="project-order">#${Number(project.sortOrder ?? project.sort_order ?? 0)}</span><span class="project-category">${project.category}</span></div>
          <div class="project-summary"><h3>${project.title}</h3><p>${project.description}</p></div>
          <div class="admin-item-actions"><button class="btn btn-secondary" type="button" data-edit-project="${project.id}">Edit</button><button class="btn btn-danger" type="button" data-delete-project="${project.id}">Delete</button></div>
          <label class="project-toggle" aria-label="Homepage visibility for ${project.title}">
            <input type="checkbox" data-toggle-home="${useCmsApi ? project.id : cmsProjects.indexOf(project)}" ${isHomeVisible(project.showHome) ? 'checked' : ''}>
            <span class="project-toggle-track" aria-hidden="true"></span>
            <span class="project-toggle-label">${isHomeVisible(project.showHome) ? 'On home' : 'Hidden'}</span>
          </label>
        </div>
      </article>
    `).join('') || '<p class="cms-note">No projects match these filters.</p>';
    const visibleCount = cmsProjects.filter((project) => isHomeVisible(project.showHome)).length;
    if (homeProjectCount) homeProjectCount.textContent = String(visibleCount);
    renderGalleryThemePreview();
  };

  ['project-search', 'project-category-filter', 'project-home-filter', 'project-sort'].forEach((id) => {
    const control = document.getElementById(id);
    if (!control) return;
    control[control.type === 'search' ? 'oninput' : 'onchange'] = renderFilteredProjects;
  });

  projectList.onchange = async (event) => {
    const toggle = event.target.closest('[data-toggle-home]');
    if (!toggle) return;
    const project = useCmsApi
      ? cmsProjects.find((item) => Number(item.id) === Number(toggle.dataset.toggleHome))
      : cmsProjects[Number(toggle.dataset.toggleHome)];
    if (!project) return;
    const previousValue = project.showHome;
    project.showHome = toggle.checked;
    if (useCmsApi) {
      try {
        await cmsRequest('visibility', { method: 'POST', body: new URLSearchParams({ id: toggle.dataset.toggleHome, showHome: toggle.checked ? '1' : '0' }) });
      } catch (error) {
        project.showHome = previousValue;
        window.alert(error.message);
      }
    } else {
      saveData(STORAGE_KEYS.projects, cmsProjects);
      renderManagedProjectViews();
    }
    const label = toggle.closest('.project-toggle')?.querySelector('.project-toggle-label');
    if (label) label.textContent = project.showHome ? 'On home' : 'Hidden';
    renderFilteredProjects();
  };

  projectList.onclick = async (event) => {
    const editButton = event.target.closest('[data-edit-project]');
    if (editButton) {
      const project = cmsProjects.find((item) => Number(item.id) === Number(editButton.dataset.editProject));
      if (!project || !projectForm) return;
      openProjectModal(project);
      document.querySelector('[data-admin-module="projects"]')?.click();
      projectForm.scrollIntoView({ behavior: 'smooth', block: 'start' });
      return;
    }
    const deleteButton = event.target.closest('[data-delete-project]');
    if (!deleteButton || !window.confirm('Delete this project?')) return;
    try {
      await cmsRequest('delete-project', { method: 'POST', body: new URLSearchParams({ id: deleteButton.dataset.deleteProject }) });
      await renderProjectList();
      await renderManagedProjectViews();
    } catch (error) { window.alert(error.message); }
  };

  renderFilteredProjects();
};

const projectForm = document.getElementById('project-form');

const resetProjectForm = () => {
  projectForm?.reset();
  if (projectForm) projectForm.elements.id.value = '';
  if (projectForm) projectForm.elements.sortOrder.value = '0';
  if (projectForm) projectForm.elements.displaySize.value = 'standard';
  document.querySelector('[data-project-form-title]')?.replaceChildren(document.createTextNode('Add project'));
  const preview = document.querySelector('[data-project-preview]');
  if (preview) preview.innerHTML = '<span>Project image preview</span>';
};

const closeProjectModal = () => {
  if (projectModal) projectModal.hidden = true;
  resetProjectForm();
};

const openProjectModal = (project = null) => {
  if (!projectForm || !projectModal) return;
  resetProjectForm();
  if (project) {
      projectForm.elements.id.value = project.id;
      projectForm.elements.title.value = project.title;
      projectForm.elements.slug.value = project.slug || '';
      projectForm.elements.category.value = project.category;
      projectForm.elements.description.value = project.description;
      projectForm.elements.altText.value = project.altText || '';
      projectForm.elements.displaySize.value = project.displaySize || 'standard';
      const storedSortOrder = project.sortOrder ?? project.sort_order;
      projectForm.elements.sortOrder.value = storedSortOrder === undefined || storedSortOrder === null ? '' : String(storedSortOrder);
      projectForm.elements.showHome.checked = project.showHome !== false && project.showHome !== 0 && project.showHome !== '0';
      document.querySelector('[data-project-form-title]').textContent = 'Edit project';
      const preview = document.querySelector('[data-project-preview]');
      if (preview) preview.innerHTML = `<img src="${project.image}" alt="${project.altText || project.title}"><span>${project.images?.length || 1} image${(project.images?.length || 1) === 1 ? '' : 's'} stored</span>`;
  }
  projectModal.hidden = false;
};

const projectCardMarkup = (project) => {
  const images = project.images || [project.image];
  const controls = images.length > 1 ? `<div class="project-card-controls"><button type="button" data-card-prev aria-label="Previous panel">&larr;</button><span data-card-index>1 / ${images.length}</span><button type="button" data-card-next aria-label="Next panel">&rarr;</button></div>` : '';
  const displaySize = ['standard', 'wide', 'tall', 'featured'].includes(project.displaySize) ? project.displaySize : 'standard';
  return `
    <article class="gallery-card admin-project-card reveal visible" data-display-size="${displaySize}" data-modal-images="${images.join('|')}" data-card-images="${images.join('|')}" data-card-position="0">
      <img class="featured-image" src="${images[0]}" alt="${project.altText || project.title}" loading="lazy">
      ${controls}
      <div class="tile-overlay"><span>${project.category}</span><h3>${project.title}</h3><p>${project.description}</p></div>
    </article>
  `;
};

document.addEventListener('click', (event) => {
  const button = event.target.closest('[data-card-prev], [data-card-next]');
  if (!button) return;
  const card = button.closest('[data-card-images]');
  const image = card?.querySelector('.featured-image');
  if (!card || !image) return;
  const images = card.dataset.cardImages.split('|');
  const direction = button.hasAttribute('data-card-next') ? 1 : -1;
  const position = (Number(card.dataset.cardPosition) + direction + images.length) % images.length;
  card.dataset.cardPosition = position;
  image.src = images[position];
  const index = card.querySelector('[data-card-index]');
  if (index) index.textContent = `${position + 1} / ${images.length}`;
});

const renderManagedProjectViews = async () => {
  let projects = getStoredData(STORAGE_KEYS.projects, defaultProjects)
    .filter((project) => project.managed !== false);
  if (useCmsApi) {
    try {
      projects = (await cmsRequest('projects')).projects.map((project) => ({
        ...project,
        showHome: project.showHome === true || project.showHome === 1 || project.showHome === '1',
        displaySize: ['standard', 'wide', 'tall', 'featured'].includes(project.displaySize) ? project.displaySize : 'standard'
      }));
    } catch (error) {
      return;
    }
  }
  const homeProjects = document.querySelector('[data-home-projects]');
  if (homeProjects) {
    homeProjects.innerHTML = projects
      .filter((project) => project.showHome !== false)
      .map(projectCardMarkup)
      .join('');
  }

  const categoryTarget = document.querySelector('[data-category-projects]');
  if (categoryTarget) {
    const category = categoryTarget.dataset.categoryProjects;
    const categoryProjects = projects.filter((project) => project.category === category);
    categoryTarget.innerHTML = categoryProjects.map(projectCardMarkup).join('');
  }
};

const adminProductList = document.getElementById('product-list');
const productForm = document.getElementById('product-form');
const productFormMessage = document.querySelector('[data-product-form-message]');
const productBadgeInputs = [...document.querySelectorAll('[data-product-badge]')];
let adminProducts = [];
let productSlugManuallyEdited = false;
let productSubmitting = false;

const formatProductMoney = (value) => `$${Number(value).toFixed(2)}`;

const setProductMessage = (message = '', state = '') => {
  if (!productFormMessage) return;
  productFormMessage.textContent = message;
  if (state) productFormMessage.dataset.state = state;
  else delete productFormMessage.dataset.state;
};

const productSlugFromTitle = (title) => title
  .trim()
  .toLowerCase()
  .replace(/[^a-z0-9]+/g, '-')
  .replace(/^-+|-+$/g, '')
  .slice(0, 180);

const syncProductPurchaseAction = () => {
  if (!productForm) return;
  const action = productForm.elements.purchaseAction.value;
  const externalField = document.querySelector('[data-product-external-url]');
  const externalInput = productForm.elements.externalUrl;
  const help = document.querySelector('[data-product-action-help]');
  const isExternal = action === 'external';
  if (externalField) externalField.hidden = !isExternal;
  if (externalInput) externalInput.required = isExternal;
  if (help) {
    help.textContent = action === 'external'
      ? 'Sends customers to a validated external product page.'
      : action === 'inquiry'
        ? 'Uses the site contact and inquiry flow.'
        : 'Uses the local cart and order form.';
  }
};

const syncProductPublishActions = () => {
  if (!productForm) return;
  const isEditing = productForm.elements.id.value !== '';
  const published = productForm.elements.publicationStatus.value === 'published';
  const save = document.querySelector('[data-product-save]');
  const toggle = document.querySelector('[data-product-toggle-publication]');
  if (save) save.textContent = published ? (isEditing ? 'Save Changes' : 'Publish') : 'Save Draft';
  if (toggle) toggle.textContent = published ? 'Unpublish' : 'Publish';
  productForm.elements.description.required = published;
};

const setProductSubmitting = (submitting) => {
  productSubmitting = submitting;
  document.querySelectorAll('[data-product-save], [data-product-toggle-publication], [data-cancel-product]').forEach((button) => {
    button.disabled = submitting;
  });
};

const validateProductPricing = () => {
  if (!productForm) return true;
  const regularInput = productForm.elements.price;
  const saleInput = productForm.elements.salePrice;
  saleInput.setCustomValidity('');
  if (saleInput.value !== '') {
    const regular = Number(regularInput.value);
    const sale = Number(saleInput.value);
    if (!Number.isFinite(sale) || sale <= 0 || !Number.isFinite(regular) || sale >= regular) {
      saleInput.setCustomValidity('Sale price must be positive and lower than regular price.');
    }
  }
  return saleInput.validity.valid;
};

const validateProductBadges = () => {
  const reserved = new Set(['sale', 'sold out', 'featured']);
  const seen = new Set();
  let valid = true;
  productBadgeInputs.forEach((input) => {
    input.setCustomValidity('');
    const label = input.value.trim().replace(/\s+/g, ' ');
    if (!label) return;
    const normalized = label.toLocaleLowerCase();
    if (reserved.has(normalized)) {
      input.setCustomValidity(`${label} is generated automatically.`);
      valid = false;
    } else if (seen.has(normalized)) {
      input.setCustomValidity('Manual badge labels must be unique.');
      valid = false;
    }
    seen.add(normalized);
  });
  return valid;
};

const syncProductImagePreview = (product = null) => {
  const preview = document.querySelector('[data-product-image-preview]');
  if (!preview) return;
  const image = preview.querySelector('img');
  const count = preview.querySelector('[data-product-image-count]');
  if (!product || !product.image) {
    preview.hidden = true;
    image.removeAttribute('src');
    image.alt = '';
    if (count) count.textContent = '';
    return;
  }
  image.src = product.image;
  image.alt = product.images?.[0]?.altText || '';
  if (count) count.textContent = `${product.images.length} product image${product.images.length === 1 ? '' : 's'}`;
  preview.hidden = false;
};

const resetProductForm = (clearMessage = true) => {
  productForm?.reset();
  if (productForm) {
    productForm.elements.id.value = '';
    productForm.elements.publicationStatus.value = 'draft';
    productForm.elements.productType.value = 'physical';
    productForm.elements.purchaseAction.value = 'internal';
    productForm.elements.storefrontVisible.checked = true;
    productForm.elements.showWhenSoldOut.checked = true;
    productForm.elements.featured.checked = false;
    productForm.elements.sortOrder.value = String(Math.max(0, ...adminProducts.map((product) => Number(product.sortOrder) || 0)) + 1);
  }
  productBadgeInputs.forEach((input) => { input.value = ''; input.setCustomValidity(''); });
  productSlugManuallyEdited = false;
  document.querySelector('[data-product-form-title]')?.replaceChildren(document.createTextNode('Add product'));
  const cancel = document.querySelector('[data-cancel-product]');
  if (cancel) cancel.hidden = true;
  syncProductImagePreview();
  syncProductPurchaseAction();
  syncProductPublishActions();
  if (clearMessage) setProductMessage();
};

const editAdminProduct = (product) => {
  if (!productForm) return;
  productForm.elements.id.value = product.id;
  productForm.elements.title.value = product.title;
  productForm.elements.sku.value = product.sku;
  productForm.elements.slug.value = product.slug;
  productForm.elements.shortDescription.value = product.shortDescription;
  productForm.elements.description.value = product.description;
  productForm.elements.category.value = product.category || '';
  productForm.elements.price.value = product.regularPrice;
  productForm.elements.salePrice.value = product.salePrice || '';
  productForm.elements.productType.value = product.productType;
  productForm.elements.stock.value = product.stock;
  productForm.elements.publicationStatus.value = product.publicationStatus;
  productForm.elements.storefrontVisible.checked = product.storefrontVisible;
  productForm.elements.showWhenSoldOut.checked = product.showWhenSoldOut;
  productForm.elements.featured.checked = product.featured;
  productForm.elements.sortOrder.value = product.sortOrder;
  productForm.elements.purchaseAction.value = product.purchaseAction;
  productForm.elements.externalUrl.value = product.externalUrl || '';
  productForm.elements.image.value = product.image || '';
  productBadgeInputs.forEach((input, index) => { input.value = product.manualBadges[index]?.label || ''; });
  productSlugManuallyEdited = true;
  document.querySelector('[data-product-form-title]').textContent = 'Edit product';
  document.querySelector('[data-cancel-product]').hidden = false;
  syncProductImagePreview(product);
  syncProductPurchaseAction();
  syncProductPublishActions();
  setProductMessage();
  productForm.scrollIntoView({ behavior: 'smooth', block: 'start' });
};

const productStatusChip = (text, className = '') => {
  const chip = document.createElement('span');
  chip.className = `cms-product-chip${className ? ` ${className}` : ''}`;
  chip.textContent = text;
  return chip;
};

const productActionLabel = (action) => ({ internal: 'Internal Cart', external: 'External Link', inquiry: 'Inquiry' }[action] || action);

const productManagementCard = (product) => {
  const card = document.createElement('article');
  card.className = 'cms-product-row';
  card.dataset.productId = String(product.id);
  const image = document.createElement('img');
  image.src = product.image;
  image.alt = '';
  image.loading = 'lazy';
  const main = document.createElement('div');
  main.className = 'cms-product-row-main';
  const sku = document.createElement('span');
  sku.className = 'cms-product-sku';
  sku.textContent = product.sku;
  const title = document.createElement('h3');
  title.textContent = product.title;
  const meta = document.createElement('p');
  const priceText = product.onSale
    ? `${formatProductMoney(product.currentPrice)} sale · ${formatProductMoney(product.regularPrice)} regular`
    : formatProductMoney(product.currentPrice);
  meta.textContent = `${priceText} · ${product.stock} in stock · ${productActionLabel(product.purchaseAction)}`;
  const state = document.createElement('div');
  state.className = 'cms-product-state';
  state.append(productStatusChip(product.publicationStatus === 'published' ? 'Published' : 'Draft', `is-${product.publicationStatus}`));
  state.append(productStatusChip(product.storefrontVisible ? 'Visible' : 'Hidden'));
  if (!product.available) state.append(productStatusChip('Sold Out', 'is-warning'));
  main.append(sku, title, meta, state);
  const actions = document.createElement('div');
  actions.className = 'admin-item-actions';
  const edit = document.createElement('button');
  edit.className = 'cms-button';
  edit.type = 'button';
  edit.dataset.editProduct = String(product.id);
  edit.textContent = 'Edit';
  edit.addEventListener('click', () => editAdminProduct(product));
  const remove = document.createElement('button');
  remove.className = 'cms-button cms-button-danger';
  remove.type = 'button';
  remove.dataset.deleteProduct = String(product.id);
  remove.textContent = 'Delete';
  remove.addEventListener('click', async () => {
    if (!window.confirm(`Delete “${product.title}”? This cannot be undone.`)) return;
    remove.disabled = true;
    setProductMessage('Deleting product...');
    try {
      await cmsRequest('delete-product', { method: 'POST', body: new URLSearchParams({ id: product.id }) });
      if (productForm?.elements.id.value === String(product.id)) resetProductForm(false);
      await renderAdminProducts();
      setProductMessage('Product deleted.', 'success');
    } catch (error) {
      remove.disabled = false;
      setProductMessage(error.message, 'error');
    }
  });
  actions.append(edit, remove);
  card.append(image, main, actions);
  return card;
};

const renderAdminProducts = async () => {
  if (!adminProductList) return;
  try {
    adminProducts = (await cmsRequest('admin-products')).products;
    const productCount = document.querySelector('[data-dashboard-product-count]');
    if (productCount) productCount.textContent = String(adminProducts.length);
    adminProductList.replaceChildren();
    if (!adminProducts.length) {
      const empty = document.createElement('p');
      empty.className = 'cms-empty-state';
      empty.textContent = 'No products yet.';
      adminProductList.append(empty);
    } else {
      adminProductList.append(...adminProducts.map(productManagementCard));
    }
    if (!productForm?.elements.id.value) resetProductForm(false);
  } catch (error) {
    adminProductList.replaceChildren();
    const note = document.createElement('p');
    note.className = 'admin-note';
    note.textContent = error.message;
    adminProductList.append(note);
  }
};

document.querySelectorAll('[data-admin-module]').forEach((control) => control.addEventListener('click', () => {
  const moduleName = control.dataset.adminModule;
  document.querySelectorAll('[data-admin-module]').forEach((item) => {
    const active = item === control;
    item.classList.toggle('is-active', active);
    if (active) item.setAttribute('aria-current', 'page');
    else item.removeAttribute('aria-current');
  });
  document.querySelectorAll('[data-admin-module-panel]').forEach((panel) => {
    panel.hidden = panel.dataset.adminModulePanel !== moduleName;
  });
  if (moduleName === 'shop') renderAdminProducts();
}));
document.querySelector('[data-cancel-project]')?.addEventListener('click', () => {
  projectForm?.reset();
  if (projectForm) projectForm.elements.id.value = '';
  document.querySelector('[data-project-form-title]').textContent = 'Add project';
  document.querySelector('[data-cancel-project]').hidden = true;
});
document.querySelector('[data-cancel-product]')?.addEventListener('click', resetProductForm);
productForm?.elements.title.addEventListener('input', () => {
  if (!productForm.elements.id.value && !productSlugManuallyEdited) {
    productForm.elements.slug.value = productSlugFromTitle(productForm.elements.title.value);
  }
});
productForm?.elements.slug.addEventListener('input', () => { productSlugManuallyEdited = true; });
productForm?.elements.purchaseAction.addEventListener('change', syncProductPurchaseAction);
productForm?.elements.publicationStatus.addEventListener('change', syncProductPublishActions);
productForm?.elements.price.addEventListener('input', validateProductPricing);
productForm?.elements.salePrice.addEventListener('input', validateProductPricing);
productBadgeInputs.forEach((input) => input.addEventListener('input', validateProductBadges));

const saveAdminProduct = async () => {
  if (!productForm || productSubmitting) return;
  validateProductPricing();
  validateProductBadges();
  syncProductPurchaseAction();
  syncProductPublishActions();
  if (!productForm.reportValidity()) return;
  const wasEditing = productForm.elements.id.value !== '';
  const publicationStatus = productForm.elements.publicationStatus.value;
  const formData = new FormData(productForm);
  formData.set('storefrontVisible', productForm.elements.storefrontVisible.checked ? '1' : '0');
  formData.set('showWhenSoldOut', productForm.elements.showWhenSoldOut.checked ? '1' : '0');
  formData.set('featured', productForm.elements.featured.checked ? '1' : '0');
  const badges = productBadgeInputs
    .map((input) => input.value.trim().replace(/\s+/g, ' '))
    .filter(Boolean)
    .map((label, index) => ({ label, sortOrder: index + 1 }));
  formData.set('manualBadges', JSON.stringify(badges));
  setProductSubmitting(true);
  setProductMessage(publicationStatus === 'published' ? 'Publishing product...' : 'Saving draft...');
  try {
    await cmsRequest('product', { method: 'POST', body: formData });
    await renderAdminProducts();
    resetProductForm(false);
    setProductMessage(
      publicationStatus === 'published'
        ? (wasEditing ? 'Product changes published.' : 'Product published.')
        : (wasEditing ? 'Draft changes saved.' : 'Draft saved.'),
      'success'
    );
  } catch (error) {
    setProductMessage(error.message, 'error');
  } finally {
    setProductSubmitting(false);
  }
};

productForm?.addEventListener('submit', async (event) => {
  event.preventDefault();
  await saveAdminProduct();
});

document.querySelector('[data-product-toggle-publication]')?.addEventListener('click', async () => {
  if (!productForm || productSubmitting) return;
  productForm.elements.publicationStatus.value = productForm.elements.publicationStatus.value === 'published' ? 'draft' : 'published';
  syncProductPublishActions();
  await saveAdminProduct();
});

resetProductForm();

const renderHomepageGallery = () => {
  const gallery = document.querySelector('.home-gallery');
  if (!gallery || gallery.dataset.staticGallery === 'true') return;

  const projects = getStoredData(STORAGE_KEYS.projects, defaultProjects);
  const cards = projects.slice(0, 6).map((project, index) => {
    const className = index === 0 ? 'gallery-card gallery-card-large' : 'gallery-card';
    return `
      <article class="${className} reveal">
        <div class="tile-image" style="background-image: linear-gradient(135deg, rgba(107, 149, 196, 0.15), rgba(27, 39, 58, 0.2)), url('${project.image}');"></div>
        <div class="tile-overlay">
          <span>${project.category}</span>
          <h3>${project.title}</h3>
        </div>
      </article>
    `;
  }).join('');

  gallery.innerHTML = cards;

  const newRevealElements = gallery.querySelectorAll('.reveal');
  newRevealElements.forEach((element) => {
    if (typeof IntersectionObserver !== 'undefined') {
      const observer = new IntersectionObserver((entries) => {
        entries.forEach((entry) => {
          if (entry.isIntersecting) {
            entry.target.classList.add('visible');
            observer.unobserve(entry.target);
          }
        });
      }, { threshold: 0.12 });
      observer.observe(element);
    } else {
      element.classList.add('visible');
    }
  });
};

const updateSocialLinks = () => {
  const socials = getStoredData(STORAGE_KEYS.socials, defaultSocials);

  document.querySelectorAll('[data-social]').forEach((link) => {
    const key = link.getAttribute('data-social');
    if (socials[key]) {
      link.href = socials[key];
    }
  });
};

const processSlider = document.querySelector('[data-process-slider]');

const artModal = document.createElement('div');
artModal.className = 'art-modal';
artModal.setAttribute('role', 'dialog');
artModal.setAttribute('aria-modal', 'true');
artModal.setAttribute('aria-labelledby', 'art-modal-title');
artModal.innerHTML = `
  <div class="art-modal-dialog">
    <button class="art-modal-close" type="button" aria-label="Close artwork viewer">&times;</button>
    <button class="art-modal-nav art-modal-prev" type="button" data-modal-prev aria-label="Previous artwork">&larr;</button>
    <div class="art-modal-media"></div>
    <button class="art-modal-nav art-modal-next" type="button" data-modal-next aria-label="Next artwork">&rarr;</button>
    <div class="art-modal-copy">
      <p class="eyebrow" data-modal-category>Artwork</p>
      <h2 id="art-modal-title" data-modal-title>Artwork preview</h2>
      <p data-modal-description>Take a closer look at this piece.</p>
    </div>
  </div>
`;
document.body.append(artModal);

const modalMedia = artModal.querySelector('.art-modal-media');
const modalTitle = artModal.querySelector('[data-modal-title]');
const modalCategory = artModal.querySelector('[data-modal-category]');
const modalDescription = artModal.querySelector('[data-modal-description]');
const modalPrev = artModal.querySelector('[data-modal-prev]');
const modalNext = artModal.querySelector('[data-modal-next]');
let modalReturnFocus = null;
let modalItems = [];
let modalIndex = 0;

const closeArtModal = () => {
  artModal.classList.remove('is-open');
  document.body.classList.remove('modal-is-open');
  modalMedia.replaceChildren();
  modalItems = [];
  modalIndex = 0;
  modalReturnFocus?.focus();
  modalReturnFocus = null;
};

const getModalGroup = (source) => {
  if (source.matches('.process-slide')) return [...source.parentElement.querySelectorAll('.process-slide')].map((item) => ({ source: item, image: item.querySelector('img') }));
  if (source.matches('.released-sketch')) return [...document.querySelectorAll('.released-sketch')].map((item) => ({ source: item, canvas: item.querySelector('canvas') }));
  if (source.dataset.modalImages) {
    return source.dataset.modalImages.split('|').map((imageSrc) => ({
      source,
      imageSrc,
      alt: source.querySelector('img')?.alt || 'Project artwork'
    }));
  }
  const project = source.closest('.illustration-project-panel, .brand-kit-project, .illustration-showcase-item, .graphic-showcase-item');
  if (project) return [...project.querySelectorAll('img')].map((image) => ({ source: project, image }));
  return [{ source, image: source.querySelector('img'), canvas: source.querySelector('canvas') }];
};

const renderModalItem = (item) => {
  const source = item.source;
  const image = item.image;
  const canvas = item.canvas;
  if (!image && !canvas && !item.imageSrc) return;

  modalMedia.replaceChildren();

  if (image) {
    const modalImage = image.cloneNode();
    modalImage.removeAttribute('width');
    modalImage.removeAttribute('height');
    modalMedia.append(modalImage);
  } else if (item.imageSrc) {
    const modalImage = document.createElement('img');
    modalImage.src = item.imageSrc;
    modalImage.alt = item.alt;
    modalMedia.append(modalImage);
  } else {
    const modalCanvas = document.createElement('canvas');
    modalCanvas.width = canvas.width;
    modalCanvas.height = canvas.height;
    modalCanvas.getContext('2d').drawImage(canvas, 0, 0);
    modalMedia.append(modalCanvas);
  }

  modalCategory.textContent = source.querySelector('.tile-overlay span, .illustration-panel-heading .eyebrow, .brand-kit-hero-copy span')?.textContent || 'Artwork';
  modalTitle.textContent = source.querySelector('.tile-overlay h3, figcaption, .illustration-panel-heading h2, .brand-kit-hero-copy h2')?.textContent || 'Released sketch';
  const hasNavigation = modalItems.length > 1;
  modalDescription.textContent = hasNavigation ? `Panel ${modalIndex + 1} of ${modalItems.length}` : 'Take a closer look at this piece.';
  modalPrev.hidden = !hasNavigation;
  modalNext.hidden = !hasNavigation;
};

const openArtModal = (source, event, clickedVisual = source.querySelector('img, canvas')) => {
  modalItems = getModalGroup(source);
  modalIndex = Math.max(0, modalItems.findIndex((item) => item.image === clickedVisual || item.canvas === clickedVisual));
  event?.preventDefault();
  event?.stopPropagation();
  modalReturnFocus = event?.currentTarget instanceof HTMLElement ? event.currentTarget : document.activeElement;
  renderModalItem(modalItems[modalIndex] || { source, image: source.querySelector('img'), canvas: source.querySelector('canvas') });
  artModal.classList.add('is-open');
  document.body.classList.add('modal-is-open');
  artModal.querySelector('.art-modal-close').focus();
};

const showModalItem = (direction) => {
  if (modalItems.length < 2) return;
  modalIndex = (modalIndex + direction + modalItems.length) % modalItems.length;
  renderModalItem(modalItems[modalIndex]);
};

document.addEventListener('click', (event) => {
  const target = event.target;
  if (!(target instanceof Element)) return;
  const card = target.closest('.gallery-card, .process-slide, .released-sketch, .illustration-project-panel, .brand-kit-project, .illustration-showcase-item, .graphic-showcase-item');
  const clickedVisual = target.closest('img, canvas');
  if (card && clickedVisual && !target.closest('button, .process-controls, .tablet-desk')) openArtModal(card, event, clickedVisual);
});

artModal.addEventListener('click', (event) => {
  if (event.target === artModal || event.target.closest('.art-modal-close')) closeArtModal();
  if (event.target.closest('[data-modal-prev]')) showModalItem(-1);
  if (event.target.closest('[data-modal-next]')) showModalItem(1);
});

document.addEventListener('keydown', (event) => {
  if (event.key === 'Escape' && artModal.classList.contains('is-open')) closeArtModal();
  if (event.key === 'ArrowLeft' && artModal.classList.contains('is-open')) showModalItem(-1);
  if (event.key === 'ArrowRight' && artModal.classList.contains('is-open')) showModalItem(1);
});

if (processSlider) {
  const slides = [...processSlider.querySelectorAll('.process-slide')];
  const dots = [...processSlider.querySelectorAll('.process-dot')];
  let currentSlide = 0;

  const showProcessSlide = (index) => {
    currentSlide = (index + slides.length) % slides.length;
    slides.forEach((slide, slideIndex) => {
      slide.classList.toggle('is-active', slideIndex === currentSlide);
    });
    dots.forEach((dot, dotIndex) => {
      dot.classList.toggle('is-active', dotIndex === currentSlide);
    });
  };

  processSlider.querySelector('[data-process-prev]')?.addEventListener('click', () => {
    showProcessSlide(currentSlide - 1);
  });

  processSlider.querySelector('[data-process-next]')?.addEventListener('click', () => {
    showProcessSlide(currentSlide + 1);
  });

  dots.forEach((dot) => {
    dot.addEventListener('click', () => {
      showProcessSlide(Number(dot.dataset.processSlide));
    });
  });

  window.setInterval(() => showProcessSlide(currentSlide + 1), 4500);
}

const tabletDesk = document.querySelector('[data-tablet-desk]');
const tabletToggle = document.querySelector('[data-tablet-toggle]');
const tabletCanvas = document.querySelector('[data-tablet-canvas]');
const tabletCard = document.querySelector('.card-main');

if (tabletDesk && tabletToggle && tabletCanvas) {
  const tabletContext = tabletCanvas.getContext('2d');
  const floatingSketches = [];
  const removeSketch = (sketch) => {
    window.clearTimeout(sketch.activeTimer);
    window.clearTimeout(sketch.removalTimer);
    sketch.element.remove();
    const index = floatingSketches.indexOf(sketch);
    if (index !== -1) floatingSketches.splice(index, 1);
  };
  const sketchLimit = () => window.matchMedia('(max-width: 760px)').matches ? 6 : 10;
  const trimSketches = (limit = sketchLimit()) => {
    while (floatingSketches.length > limit) removeSketch(floatingSketches[0]);
  };
  const fadeSketch = (sketch) => {
    window.clearTimeout(sketch.activeTimer);
    window.clearTimeout(sketch.removalTimer);
    sketch.element.classList.add('is-fading');
    sketch.removalTimer = window.setTimeout(() => removeSketch(sketch), 6000);
  };
  let tabletColor = '243, 168, 137';
  let tabletTool = 'brush';
  let tabletDrawing = false;
  let tabletLastPoint = null;

  const setTabletMode = (isOpen) => {
    tabletDesk.hidden = !isOpen;
    processSlider.hidden = isOpen;
    document.body.classList.toggle('tablet-is-open', isOpen);
    tabletToggle.textContent = isOpen ? 'Back to process' : 'Open sketchbook';
    tabletToggle.setAttribute('aria-expanded', String(isOpen));
    if (isOpen) window.requestAnimationFrame(resizeTablet);
  };

  const resizeTablet = () => {
    const bounds = tabletCanvas.getBoundingClientRect();
    const pixelRatio = Math.min(window.devicePixelRatio || 1, 2);
    const snapshot = tabletCanvas.width ? tabletCanvas.toDataURL() : null;
    tabletCanvas.width = Math.max(1, Math.floor(bounds.width * pixelRatio));
    tabletCanvas.height = Math.max(1, Math.floor(bounds.height * pixelRatio));
    tabletContext.setTransform(pixelRatio, 0, 0, pixelRatio, 0, 0);
    if (snapshot) {
      const image = new Image();
      image.onload = () => tabletContext.drawImage(image, 0, 0, bounds.width, bounds.height);
      image.src = snapshot;
    }
  };

  const tabletPoint = (event) => {
    const bounds = tabletCanvas.getBoundingClientRect();
    return { x: event.clientX - bounds.left, y: event.clientY - bounds.top };
  };

  const drawTabletLine = (from, to) => {
    tabletContext.strokeStyle = `rgb(${tabletColor})`;
    tabletContext.lineCap = tabletTool === 'pencil' ? 'butt' : 'round';
    tabletContext.lineJoin = 'round';
    tabletContext.lineWidth = tabletTool === 'pencil' ? 2 : 15;
    tabletContext.globalAlpha = tabletTool === 'pencil' ? 0.8 : 0.9;
    tabletContext.beginPath();
    tabletContext.moveTo(from.x, from.y);
    tabletContext.lineTo(to.x, to.y);
    tabletContext.stroke();
    tabletContext.globalAlpha = 1;
  };

  const runSketchPhysics = () => {
    floatingSketches.forEach((sketch) => {
      const width = sketch.element.offsetWidth;
      const height = sketch.element.offsetHeight;
      const maxX = Math.max(8, window.innerWidth - width - 8);
      const maxY = Math.max(8, window.innerHeight - height - 8);

      sketch.velocityY += 0.035;
      sketch.velocityX *= 0.999;
      sketch.velocityY *= 0.999;
      sketch.x += sketch.velocityX;
      sketch.y += sketch.velocityY;
      sketch.rotation += sketch.angularVelocity;

      if (sketch.x <= 8 || sketch.x >= maxX) {
        sketch.x = Math.max(8, Math.min(maxX, sketch.x));
        sketch.velocityX *= -0.88;
      }
      if (sketch.y <= 8 || sketch.y >= maxY) {
        sketch.y = Math.max(8, Math.min(maxY, sketch.y));
        sketch.velocityY *= -0.82;
        sketch.angularVelocity *= 0.96;
      }

      sketch.element.style.transform = `translate3d(${sketch.x}px, ${sketch.y}px, 0) rotate(${sketch.rotation}deg)`;
    });

    if (!reducedMotion.matches) window.requestAnimationFrame(runSketchPhysics);
  };

  if (!reducedMotion.matches) window.requestAnimationFrame(runSketchPhysics);

  tabletToggle.addEventListener('click', () => {
    setTabletMode(tabletDesk.hidden);
  });

  if (isMobileViewport) {
    tabletCard?.addEventListener('click', (event) => {
      if (event.target.closest('button, a, input, textarea, select, .tablet-desk')) return;
      event.preventDefault();
      event.stopPropagation();
      setTabletMode(tabletDesk.hidden);
    });
  }

  if (!isMobileViewport && window.matchMedia('(hover: hover) and (pointer: fine)').matches) {
    tabletCard?.addEventListener('pointerenter', () => setTabletMode(true));
    tabletCard?.addEventListener('pointerleave', () => setTabletMode(false));
  }

  tabletCanvas.addEventListener('pointerdown', (event) => {
    tabletDrawing = true;
    tabletLastPoint = tabletPoint(event);
    tabletCanvas.setPointerCapture(event.pointerId);
  });
  tabletCanvas.addEventListener('pointermove', (event) => {
    if (!tabletDrawing) return;
    const point = tabletPoint(event);
    drawTabletLine(tabletLastPoint, point);
    tabletLastPoint = point;
  });
  tabletCanvas.addEventListener('pointerup', () => {
    tabletDrawing = false;
    tabletLastPoint = null;
  });
  tabletCanvas.addEventListener('pointercancel', () => {
    tabletDrawing = false;
    tabletLastPoint = null;
  });

  tabletDesk.querySelectorAll('[data-tablet-color]').forEach((swatch) => {
    swatch.addEventListener('click', () => {
      tabletColor = swatch.dataset.tabletColor;
      tabletDesk.querySelectorAll('[data-tablet-color]').forEach((button) => {
        const selected = button === swatch;
        button.classList.toggle('is-selected', selected);
        button.setAttribute('aria-pressed', String(selected));
      });
    });
  });

  tabletDesk.querySelectorAll('[data-tablet-tool]').forEach((tool) => {
    tool.addEventListener('click', () => {
      tabletTool = tool.dataset.tabletTool;
      tabletDesk.querySelectorAll('[data-tablet-tool]').forEach((button) => {
        const selected = button === tool;
        button.classList.toggle('is-active', selected);
        button.setAttribute('aria-pressed', String(selected));
      });
    });
  });

  tabletDesk.querySelector('[data-tablet-clear]').addEventListener('click', () => {
    tabletContext.clearRect(0, 0, tabletCanvas.width, tabletCanvas.height);
  });

  tabletDesk.querySelector('[data-tablet-release]').addEventListener('click', () => {
    // Remove oldest first, including fading sheets, to keep a strict live DOM cap.
    trimSketches(sketchLimit() - 1);
    const releasedCanvas = document.createElement('canvas');
    releasedCanvas.width = tabletCanvas.width;
    releasedCanvas.height = tabletCanvas.height;
    releasedCanvas.getContext('2d').drawImage(tabletCanvas, 0, 0);
    const releasedSketch = document.createElement('div');
    releasedSketch.className = 'released-sketch';
    const startX = 12 + Math.random() * Math.max(20, window.innerWidth - 300);
    const startY = 90 + Math.random() * Math.max(20, window.innerHeight - 300);
    releasedSketch.style.left = '0';
    releasedSketch.style.top = '0';
    releasedSketch.append(releasedCanvas);
    document.body.append(releasedSketch);
    const sketch = {
      element: releasedSketch,
      x: startX,
      y: startY,
      velocityX: (Math.random() - 0.5) * 1.8,
      velocityY: -1.5 - Math.random() * 1.5,
      rotation: (Math.random() - 0.5) * 8,
      angularVelocity: (Math.random() - 0.5) * 0.08
    };
    floatingSketches.push(sketch);
    sketch.activeTimer = window.setTimeout(() => fadeSketch(sketch), 30000);
    tabletContext.clearRect(0, 0, tabletCanvas.width, tabletCanvas.height);
  });

  window.addEventListener('resize', () => {
    trimSketches();
    if (!tabletDesk.hidden) resizeTablet();
  });
}

if (projectForm) {
  projectForm.addEventListener('submit', async (event) => {
    event.preventDefault();

    const formData = new FormData(projectForm);
    if (useCmsApi) {
      const imageFiles = formData.getAll('imageFiles[]');
      if (imageFiles.some((file) => file instanceof File && file.size > 0)) formData.delete('image');
      formData.set('showHome', formData.get('showHome') === 'on' ? '1' : '0');
      try {
        await cmsRequest('project', { method: 'POST', body: formData });
        projectForm.reset();
        projectForm.elements.id.value = '';
        document.querySelector('[data-project-form-title]')?.replaceChildren(document.createTextNode('Add project'));
        const cancelProject = document.querySelector('[data-cancel-project]');
        if (cancelProject) cancelProject.hidden = true;
        closeProjectModal();
        await renderProjectList();
        await renderManagedProjectViews();
      } catch (error) {
        window.alert(error.message);
      }
      return;
    }

    const imageFile = formData.get('imageFiles[]');
    const fallbackImage = formData.get('image')?.toString().trim();
    if (imageFile instanceof File && imageFile.size > 0) {
      window.alert('File uploads require the XAMPP/PHP version of the site. Use an image URL for local preview.');
      return;
    }
    if (!fallbackImage) {
      window.alert('Add an image URL for local preview.');
      return;
    }
    const newProject = {
      title: formData.get('title').toString().trim(),
      category: formData.get('category').toString().trim(),
      image: fallbackImage,
      description: formData.get('description').toString().trim(),
      managed: true,
      showHome: formData.get('showHome') === 'on'
    };

    const currentProjects = getStoredData(STORAGE_KEYS.projects, defaultProjects);
    const updatedProjects = [newProject, ...currentProjects];

    saveData(STORAGE_KEYS.projects, updatedProjects);
    renderProjectList();
    renderHomepageGallery();
    renderManagedProjectViews();
      projectForm.reset();
      resetProjectForm();
  });
}

document.querySelector('[data-open-project-modal]')?.addEventListener('click', () => openProjectModal());
document.querySelector('[data-close-project-modal]')?.addEventListener('click', closeProjectModal);
document.querySelector('[data-cancel-project]')?.addEventListener('click', closeProjectModal);

document.querySelectorAll('[data-project-view]').forEach((control) => control.addEventListener('click', () => {
  document.querySelectorAll('[data-project-view]').forEach((item) => item.classList.toggle('is-active', item === control));
  projectList?.classList.toggle('is-list-view', control.dataset.projectView === 'list');
  const listHead = document.querySelector('[data-project-list-head]');
  if (listHead) listHead.hidden = control.dataset.projectView !== 'list';
}));

const themeForm = document.getElementById('theme-form');
const galleryThemePreview = document.querySelector('[data-gallery-theme-preview]');
const normalizeThemeHex = (value) => {
  const input = value.trim();
  return /^#?[\da-f]{6}$/i.test(input) ? `#${input.replace(/^#/, '').toUpperCase()}` : null;
};

const syncThemeTagPreview = () => {
  const preview = document.querySelector('.cms-theme-tag-preview');
  if (!preview || !themeForm) return;
  preview.style.setProperty('--blue-deep', themeForm.elements.accent.value);
  preview.style.setProperty('--panel-strong', themeForm.elements.surface.value);
  preview.style.setProperty('--text', themeForm.elements.text.value);
};

const syncThemeHex = (hex, commit = false) => {
  const color = themeForm.elements[hex.dataset.themeHex];
  const value = normalizeThemeHex(hex.value);
  const message = value ? '' : 'Use six HEX digits, e.g. #445FCA. The swatch keeps the last valid color.';
  hex.setCustomValidity(message);
  hex.setAttribute('aria-invalid', String(!value));
  themeForm.querySelector(`[data-theme-color-error="${hex.dataset.themeHex}"]`).textContent = message;
  if (!value) return false;
  color.value = value.toLowerCase();
  if (commit) hex.value = value;
  return true;
};

const validateThemeColors = () => {
  const fields = [...themeForm.querySelectorAll('[data-theme-hex]')];
  const valid = fields.map((hex) => syncThemeHex(hex, true)).every(Boolean);
  if (!valid) fields.find((hex) => hex.getAttribute('aria-invalid') === 'true')?.focus();
  return valid;
};

const syncThemeColorInput = (event) => {
  const input = event.target;
  if (input.matches('[data-theme-hex]')) {
    syncThemeHex(input, event.type === 'change');
  } else if (input.matches('input[type="color"]')) {
    const hex = themeForm.querySelector(`[data-theme-hex="${input.name}"]`);
    hex.value = input.value.toUpperCase();
    syncThemeHex(hex, true);
  }
};
themeForm?.addEventListener('input', syncThemeColorInput);
themeForm?.addEventListener('change', syncThemeColorInput);
const allowedThemeValues = {
  radius: new Set(['2px', '4px', '8px', '999px']),
  galleryLayout: new Set(['uniform', 'masonry', 'editorial', 'clean']),
  galleryEdge: new Set(['rounded', 'slight', 'square', 'none'])
};
const defaultThemeSettings = {
  accentColor: '#c86f52',
  pageBackground: '#fff0e8',
  surfaceColor: '#fff8f3',
  primaryText: '#3d2925',
  buttonRadius: '999px',
  galleryLayout: 'uniform',
  galleryEdge: 'rounded'
};
let themePreviewReady = false;
let cmsContentEntries = [];

const renderGalleryThemePreview = () => {
  if (!galleryThemePreview) return;
  const previewProjects = cmsProjects.slice(0, 6);
  galleryThemePreview.replaceChildren();
  previewProjects.forEach((project) => {
    const card = document.createElement('article');
    card.className = 'cms-gallery-preview-card';
    if (['standard', 'wide', 'tall', 'featured'].includes(project.displaySize)) {
      card.dataset.displaySize = project.displaySize;
    }
    const image = document.createElement('img');
    image.src = project.image;
    image.alt = project.altText || project.title;
    const copy = document.createElement('div');
    const category = document.createElement('span');
    category.textContent = project.category;
    const title = document.createElement('strong');
    title.textContent = project.title;
    copy.append(category, title);
    card.append(image, copy);
    galleryThemePreview.append(card);
  });
  const note = document.querySelector('[data-gallery-preview-note]');
  if (note) {
    note.textContent = galleryThemePreview.dataset.galleryLayout === 'editorial'
      ? 'Emphasis requires an explicit display size on each project.'
      : `${previewProjects.length} current projects`;
  }
};

const syncAdminAccent = (accent) => {
  if (!themeForm || !/^#[\da-f]{6}$/i.test(accent || '')) return;
  document.body.style.setProperty('--cms-accent', accent);
};

const applyThemePreview = () => {
  if (!themeForm || !galleryThemePreview) return false;
  if (!validateThemeColors()) return false;
  const formData = new FormData(themeForm);
  const values = {
    accentColor: String(formData.get('accent') || ''),
    pageBackground: String(formData.get('background') || ''),
    surfaceColor: String(formData.get('surface') || ''),
    primaryText: String(formData.get('text') || ''),
    buttonRadius: String(formData.get('radius') || ''),
    galleryLayout: String(formData.get('galleryLayout') || ''),
    galleryEdge: String(formData.get('galleryEdge') || '')
  };
  if (![values.accentColor, values.pageBackground, values.surfaceColor, values.primaryText].every((value) => /^#[\da-f]{6}$/i.test(value))) return false;
  if (!allowedThemeValues.radius.has(values.buttonRadius) || !allowedThemeValues.galleryLayout.has(values.galleryLayout) || !allowedThemeValues.galleryEdge.has(values.galleryEdge)) return false;
  syncAdminAccent(values.accentColor);
  // Public preview colors belong to the preview, not the neutral Admin shell.
  galleryThemePreview.style.setProperty('--cms-bg', values.pageBackground);
  galleryThemePreview.style.setProperty('--cms-surface', values.surfaceColor);
  galleryThemePreview.style.setProperty('--cms-text', values.primaryText);
  galleryThemePreview.style.background = 'var(--cms-bg)';
  galleryThemePreview.style.color = 'var(--cms-text)';
  document.body.style.setProperty('--button-radius', values.buttonRadius);
  galleryThemePreview.dataset.galleryLayout = values.galleryLayout;
  galleryThemePreview.dataset.galleryEdge = values.galleryEdge;
  syncThemeTagPreview();
  renderGalleryThemePreview();
  return true;
};

const setThemeControls = (theme) => {
  if (!themeForm) return;
  syncAdminAccent(theme.accentColor);
  themeForm.elements.accent.value = theme.accentColor;
  themeForm.elements.background.value = theme.pageBackground;
  themeForm.elements.surface.value = theme.surfaceColor;
  themeForm.elements.text.value = theme.primaryText;
  themeForm.querySelectorAll('[data-theme-hex]').forEach((hex) => {
    hex.value = themeForm.elements[hex.dataset.themeHex].value.toUpperCase();
    syncThemeHex(hex, true);
  });
  syncThemeTagPreview();
  themeForm.elements.radius.value = theme.buttonRadius;
  themeForm.elements.galleryLayout.value = theme.galleryLayout;
  themeForm.elements.galleryEdge.value = theme.galleryEdge;
  if (galleryThemePreview) {
    galleryThemePreview.dataset.galleryLayout = theme.galleryLayout;
    galleryThemePreview.dataset.galleryEdge = theme.galleryEdge;
  }
  renderGalleryThemePreview();
};

const invalidateThemePreview = () => {
  themePreviewReady = false;
  const publishButton = document.querySelector('[data-theme-publish]');
  const message = document.querySelector('[data-theme-message]');
  if (publishButton) publishButton.disabled = true;
  if (message) message.textContent = 'Settings changed. Select Preview Changes before publishing.';
};

themeForm?.addEventListener('input', invalidateThemePreview);
themeForm?.addEventListener('change', invalidateThemePreview);
themeForm?.addEventListener('submit', (event) => {
  event.preventDefault();
  const message = document.querySelector('[data-theme-message]');
  if (!applyThemePreview()) {
    if (message) message.textContent = 'One or more theme values are invalid.';
    return;
  }
  themePreviewReady = true;
  document.querySelector('[data-theme-publish]').disabled = false;
  if (message) message.textContent = 'Preview is active in this admin session. Save & Publish to update the public site.';
});

const loadAdminTheme = async () => {
  if (!themeForm || !useCmsApi) return;
  const message = document.querySelector('[data-theme-message]');
  try {
    const result = await cmsRequest('theme');
    setThemeControls(result.theme);
    if (message) message.textContent = 'Published settings loaded. Preview Changes applies edits to this admin session.';
  } catch (error) {
    setThemeControls(defaultThemeSettings);
    if (message) message.textContent = 'Published settings unavailable. Default settings are shown.';
  }
};

document.querySelector('[data-theme-publish]')?.addEventListener('click', async () => {
  if (!themePreviewReady || !themeForm) return;
  if (!validateThemeColors()) {
    invalidateThemePreview();
    return;
  }
  const data = new FormData(themeForm);
  const payload = new URLSearchParams({
    accentColor: data.get('accent'),
    pageBackground: data.get('background'),
    surfaceColor: data.get('surface'),
    primaryText: data.get('text'),
    buttonRadius: data.get('radius'),
    galleryLayout: data.get('galleryLayout'),
    galleryEdge: data.get('galleryEdge')
  });
  const message = document.querySelector('[data-theme-message]');
  try {
    await cmsRequest('save-theme', { method: 'POST', body: payload });
    if (message) message.textContent = 'Theme published successfully.';
    themePreviewReady = false;
    document.querySelector('[data-theme-publish]').disabled = true;
  } catch (error) {
    if (message) message.textContent = `Theme was not published: ${error.message}`;
  }
});

document.querySelector('[data-theme-reset]')?.addEventListener('click', async () => {
  const message = document.querySelector('[data-theme-message]');
  try {
    const result = await cmsRequest('reset-theme', { method: 'POST' });
    setThemeControls(result.theme);
    applyThemePreview();
    themePreviewReady = false;
    document.querySelector('[data-theme-publish]').disabled = true;
    if (message) message.textContent = 'Theme defaults restored and published.';
  } catch (error) {
    if (message) message.textContent = `Defaults were not restored: ${error.message}`;
  }
});

const renderAdminContent = async () => {
  const list = document.getElementById('content-list');
  if (!list || !useCmsApi) return false;
  try {
    cmsContentEntries = (await cmsRequest('admin-content')).entries;
    list.replaceChildren();
    if (!cmsContentEntries.length) {
      const empty = document.createElement('div');
      empty.className = 'cms-empty-state';
      const heading = document.createElement('h2');
      heading.textContent = 'No content entries';
      const note = document.createElement('p');
      note.textContent = 'Create a draft or publish a new blog, news, update, or announcement.';
      empty.append(heading, note);
      list.append(empty);
      return true;
    }
    cmsContentEntries.forEach((entry) => {
      const row = document.createElement('article');
      row.className = 'cms-content-row';
      if (entry.coverImage) {
        const image = document.createElement('img');
        image.src = entry.coverImage;
        image.alt = '';
        row.append(image);
      }
      const main = document.createElement('div');
      main.className = 'cms-content-row-main';
      const title = document.createElement('h2');
      title.textContent = entry.title;
      const excerpt = document.createElement('p');
      excerpt.textContent = entry.excerpt;
      const meta = document.createElement('span');
      meta.textContent = `${contentTypeLabels[entry.type] || entry.type} · ${entry.publishDate} · ${entry.cardSize}`;
      const status = document.createElement('span');
      status.className = `cms-content-status is-${entry.status}`;
      status.textContent = entry.status === 'published' ? 'Published' : 'Draft';
      main.append(title, excerpt, meta);
      const actions = document.createElement('div');
      actions.className = 'admin-item-actions';
      const edit = document.createElement('button');
      edit.type = 'button';
      edit.className = 'cms-button';
      edit.dataset.editContent = String(entry.id);
      edit.textContent = 'Edit';
      const remove = document.createElement('button');
      remove.type = 'button';
      remove.className = 'cms-button cms-content-delete';
      remove.dataset.deleteContent = String(entry.id);
      remove.textContent = 'Delete';
      actions.append(status, edit, remove);
      row.append(main, actions);
      list.append(row);
    });
    return true;
  } catch (error) {
    list.textContent = `Content list unavailable: ${error.message}`;
    return false;
  }
};

const contentForm = document.getElementById('content-form');
const contentCoverPreview = document.querySelector('[data-content-cover-preview]');
const contentBlockList = document.querySelector('[data-content-block-list]');
const contentBlockLabels = {
  paragraph: 'Paragraph', heading: 'Heading', image: 'Image', image_caption: 'Image + Caption',
  video: 'YouTube Video', quote: 'Quote', divider: 'Divider', gallery: 'Gallery'
};
// An empty loaded article is intentional. An uninitialized or mismatched editor must omit blocks.
let contentBlockState = { initialized: false, entryId: null, blocks: [] };
let contentSaving = false;
const contentMediaUploadState = new WeakMap();
const pendingContentUploads = new Set();

const syncContentSaveButtons = () => {
  const pending = [...pendingContentUploads].some((upload) => upload.editor === contentBlockState);
  document.querySelectorAll('[data-content-save], [data-content-publish]').forEach((button) => {
    button.disabled = contentSaving || pending;
  });
};

const contentEditorMatchesEntry = () => contentBlockState.initialized
  && contentBlockState.entryId === String(contentForm?.elements.id.value || '');

const normalizeContentYoutubeId = (value) => {
  const input = value.trim();
  if (/^[A-Za-z0-9_-]{11}$/.test(input)) return input;
  try {
    const url = new URL(input);
    if (!['http:', 'https:'].includes(url.protocol) || url.username || url.password) return null;
    const host = url.hostname.toLowerCase();
    let id = null;
    if (['youtu.be', 'www.youtu.be'].includes(host)) id = url.pathname.slice(1);
    if (['youtube.com', 'www.youtube.com', 'm.youtube.com', 'music.youtube.com', 'youtube-nocookie.com', 'www.youtube-nocookie.com'].includes(host)) {
      id = url.pathname === '/watch' ? url.searchParams.get('v') : url.pathname.match(/^\/(?:embed|shorts|live)\/([^/]+)\/?$/)?.[1];
    }
    return id && /^[A-Za-z0-9_-]{11}$/.test(id) ? id : null;
  } catch { return null; }
};

const validContentMediaUrl = (value) => {
  const input = value.trim();
  if (!input || [...input].length > 255 || /[\u0000-\u0020\u007f]/.test(input)) return false;
  if (/^[a-z][a-z0-9+.-]*:/i.test(input) || input.startsWith('//')) {
    try {
      const url = new URL(input);
      return /^https?:\/\/[^/]/i.test(input) && ['http:', 'https:'].includes(url.protocol)
        && Boolean(url.hostname) && !url.username && !url.password && !input.includes('\\');
    } catch { return false; }
  }
  if (!/^[A-Za-z0-9][A-Za-z0-9._~!$&'()*+,;=:@%/?#-]*$/.test(input)) return false;
  let path = input.split('#')[0].split('?')[0];
  if (!path || /%(?![a-f0-9]{2})/i.test(path)) return false;
  for (let pass = 0; pass < 8; pass++) {
    if (path.split('/').some((segment) => segment === '.' || segment === '..') || /[\\\u0000-\u001f\u007f]/.test(path)) return false;
    // Decode bytes, just as PHP rawurldecode does, without rejecting valid UTF-8 filenames.
    const decoded = path.replace(/%([a-f0-9]{2})/gi, (_, hex) => String.fromCharCode(parseInt(hex, 16)));
    if (decoded === path) return true;
    path = decoded;
  }
  return false;
};

const contentBlockNotice = (text) => {
  const notice = document.querySelector('[data-content-block-notice]');
  if (notice) notice.textContent = text;
};

const contentBlockButton = (text, action, label) => {
  const button = document.createElement('button');
  button.type = 'button';
  button.className = 'cms-button cms-button-subtle';
  button.dataset.blockAction = action;
  button.textContent = text;
  button.setAttribute('aria-label', label);
  return button;
};

const contentBlockField = (labelText, field, value, options = {}) => {
  const label = document.createElement('label');
  const caption = document.createElement('span');
  caption.textContent = labelText;
  const input = document.createElement(options.multiline ? 'textarea' : options.level ? 'select' : 'input');
  input.dataset.blockField = field;
  if (options.imageIndex !== undefined) input.dataset.galleryIndex = String(options.imageIndex);
  if (options.level) {
    [2, 3].forEach((level) => {
      const option = document.createElement('option');
      option.value = String(level);
      option.textContent = `H${level}`;
      input.append(option);
    });
  } else {
    if (options.multiline) input.rows = 3;
    else input.type = 'text';
    if (options.max) input.maxLength = options.max;
    if (options.required) input.required = true;
    if (options.media) input.placeholder = 'https://example.com/image.jpg or img/image.jpg';
  }
  input.value = value ?? '';
  label.append(caption, input);
  if (options.media) {
    const help = document.createElement('small');
    help.textContent = 'HTTP(S) URL or site-relative path. Use %20 for spaces.';
    label.append(help);
  }
  return label;
};

const contentImageFields = (target, imageIndex) => {
  const group = document.createElement('div');
  group.className = 'cms-block-media';
  const state = contentMediaUploadState.get(target);
  const uploadLabel = document.createElement('label');
  const uploadTitle = document.createElement('span');
  uploadTitle.textContent = 'Upload Image';
  const upload = document.createElement('input');
  upload.type = 'file';
  upload.accept = 'image/jpeg,image/png,image/gif,image/webp';
  upload.dataset.contentImageUpload = '';
  if (imageIndex !== undefined) upload.dataset.galleryIndex = String(imageIndex);
  upload.disabled = Boolean(state?.pending);
  const help = document.createElement('small');
  help.textContent = 'JPG, PNG, GIF, or WebP. Up to 8MB. Upload starts when you choose a file.';
  uploadLabel.append(uploadTitle, upload, help);
  const or = document.createElement('span');
  or.className = 'cms-media-alternative';
  or.textContent = 'OR';
  const url = contentBlockField('Enter Image URL / path', 'url', target.url, { media: true, required: true, imageIndex });
  url.querySelector('input').disabled = Boolean(state?.pending);
  const message = document.createElement('p');
  message.className = 'cms-media-message';
  message.dataset.contentUploadMessage = '';
  message.setAttribute('role', 'status');
  message.textContent = state?.message || '';
  if (state?.error) message.classList.add('is-error');
  group.append(uploadLabel, or, url, message);
  return group;
};

const renderContentBlocks = () => {
  if (!contentBlockList) return;
  contentBlockList.replaceChildren();
  const ready = contentEditorMatchesEntry();
  const count = document.querySelector('[data-content-block-count]');
  if (count) count.textContent = ready ? `${contentBlockState.blocks.length} / 100 blocks` : 'Not loaded';
  const addButton = document.querySelector('[data-add-content-block]');
  if (addButton) addButton.disabled = !ready || contentBlockState.blocks.length >= 100;
  if (!ready || !contentBlockState.blocks.length) {
    const empty = document.createElement('p');
    empty.className = 'cms-block-empty';
    empty.textContent = ready ? 'No structured blocks. Add a block to begin, or keep using the legacy body.'
      : 'Structured blocks are not loaded. Saving will preserve existing blocks.';
    contentBlockList.append(empty);
    return;
  }
  contentBlockState.blocks.forEach((block, index) => {
    const section = document.createElement('section');
    section.className = 'cms-article-block';
    section.dataset.blockIndex = String(index);
    const header = document.createElement('div');
    header.className = 'cms-block-heading';
    const title = document.createElement('h4');
    title.textContent = `${index + 1}. ${contentBlockLabels[block.type]}`;
    const actions = document.createElement('div');
    actions.className = 'cms-block-actions';
    const up = contentBlockButton('↑ Move Up', 'up', `Move block ${index + 1} up`);
    const down = contentBlockButton('↓ Move Down', 'down', `Move block ${index + 1} down`);
    up.disabled = index === 0;
    down.disabled = index === contentBlockState.blocks.length - 1;
    actions.append(up, down, contentBlockButton('Remove', 'remove', `Remove block ${index + 1}: ${contentBlockLabels[block.type]}`));
    header.append(title, actions);
    const fields = document.createElement('div');
    fields.className = 'cms-block-fields';
    const payload = block.payload;
    const field = (label, key, options) => fields.append(contentBlockField(label, key, payload[key], options));
    if (block.type === 'paragraph') field('Paragraph text', 'text', { multiline: true, max: 20000, required: true });
    if (block.type === 'heading') {
      field('Heading text', 'text', { max: 300, required: true });
      field('Heading level', 'level', { level: true });
    }
    if (['image', 'image_caption'].includes(block.type)) {
      fields.append(contentImageFields(payload));
      field('Alt text', 'alt', { max: 255 });
      if (block.type === 'image_caption') field('Caption', 'caption', { max: 500 });
    }
    if (block.type === 'video') field('YouTube URL or video ID', 'videoId', { required: true });
    if (block.type === 'quote') {
      field('Quote text', 'text', { multiline: true, max: 5000, required: true });
      field('Attribution (optional)', 'attribution', { max: 300 });
    }
    if (block.type === 'divider') {
      const divider = document.createElement('hr');
      fields.append(divider);
    }
    if (block.type === 'gallery') {
      payload.images.forEach((item, imageIndex) => {
        const image = document.createElement('fieldset');
        image.className = 'cms-gallery-image-fields';
        const legend = document.createElement('legend');
        legend.textContent = `Image ${imageIndex + 1}`;
        image.append(legend,
          contentImageFields(item, imageIndex),
          contentBlockField('Alt text', 'alt', item.alt, { max: 255, imageIndex }),
          contentBlockField('Caption (optional)', 'caption', item.caption, { max: 500, imageIndex }));
        const remove = contentBlockButton('Remove image', 'remove-image', `Remove image ${imageIndex + 1} from gallery block ${index + 1}`);
        remove.dataset.galleryIndex = String(imageIndex);
        remove.disabled = payload.images.length === 1;
        image.append(remove);
        fields.append(image);
      });
      const addImage = contentBlockButton(`Add image (${payload.images.length} / 20)`, 'add-image', `Add image to gallery block ${index + 1}`);
      addImage.disabled = payload.images.length >= 20;
      fields.append(addImage);
    }
    section.append(header, fields);
    contentBlockList.append(section);
  });
};

const initializeContentBlocks = (entryId, blocks) => {
  if (!Array.isArray(blocks) || blocks.some((block) => !Object.hasOwn(contentBlockLabels, block.type) || !block.payload
    || (block.type === 'gallery' && !Array.isArray(block.payload.images)))) {
    contentBlockNotice('Could not load structured blocks. Existing blocks will be preserved when saving.');
    return;
  }
  contentBlockState = {
    initialized: true, entryId: String(entryId || ''),
    blocks: blocks.map((block) => ({ type: block.type, payload: JSON.parse(JSON.stringify(block.payload)) }))
  };
  renderContentBlocks();
};

document.querySelector('[data-add-content-block]')?.addEventListener('click', () => {
  if (contentSaving || !contentEditorMatchesEntry() || contentBlockState.blocks.length >= 100) return;
  const type = document.querySelector('[data-content-block-type]').value;
  const defaults = {
    paragraph: { text: '' }, heading: { text: '', level: 2 }, image: { url: '', alt: '' },
    image_caption: { url: '', alt: '', caption: '' }, video: { provider: 'youtube', videoId: '' },
    quote: { text: '', attribution: '' }, divider: {}, gallery: { images: [{ url: '', alt: '' }] }
  };
  if (!Object.hasOwn(defaults, type)) return;
  contentBlockState.blocks.push({ type, payload: defaults[type] });
  renderContentBlocks();
  contentBlockList.lastElementChild.querySelector('input, textarea, select, button')?.focus();
  contentBlockNotice(`${contentBlockLabels[type]} added.`);
});

const updateContentBlockField = (event) => {
  const input = event.target.closest('[data-block-field]');
  if (!input || contentSaving || !contentEditorMatchesEntry()) return;
  const block = contentBlockState.blocks[Number(input.closest('[data-block-index]').dataset.blockIndex)];
  const target = input.hasAttribute('data-gallery-index') ? block.payload.images[Number(input.dataset.galleryIndex)] : block.payload;
  target[input.dataset.blockField] = input.dataset.blockField === 'level' ? Number(input.value) : input.value;
  input.setCustomValidity('');
};
contentBlockList?.addEventListener('input', updateContentBlockField);
contentBlockList?.addEventListener('change', updateContentBlockField);
contentBlockList?.addEventListener('change', async (event) => {
  const input = event.target.closest('[data-content-image-upload]');
  const file = input?.files[0];
  if (!file || contentSaving || !contentEditorMatchesEntry()) return;
  const block = contentBlockState.blocks[Number(input.closest('[data-block-index]').dataset.blockIndex)];
  const target = input.hasAttribute('data-gallery-index') ? block.payload.images[Number(input.dataset.galleryIndex)] : block.payload;
  const editor = contentBlockState;
  const upload = { editor, target };
  const state = { pending: true, message: 'Uploading image…', error: false };
  contentMediaUploadState.set(target, state);
  pendingContentUploads.add(upload);
  renderContentBlocks();
  syncContentSaveButtons();
  try {
    if (!file.size || file.size > 8 * 1024 * 1024) throw new Error('Choose an image file, 8MB or smaller.');
    const payload = new FormData();
    payload.set('imageFile', file);
    const result = await cmsRequest('content-media-upload', { method: 'POST', body: payload });
    if (!validContentMediaUrl(result.url || '')) throw new Error('The upload did not return a valid image path.');
    // Object references keep a late response attached to its original item even after reordering.
    const stillPresent = editor === contentBlockState && contentEditorMatchesEntry() && editor.blocks.some((item) =>
      item.payload === target || (item.type === 'gallery' && item.payload.images.includes(target)));
    if (stillPresent) target.url = result.url;
    state.message = 'Image uploaded. Save the entry to keep this image in the article.';
  } catch (error) {
    state.error = true;
    state.message = error.message;
  } finally {
    state.pending = false;
    pendingContentUploads.delete(upload);
    if (editor === contentBlockState) renderContentBlocks();
    syncContentSaveButtons();
  }
});
contentBlockList?.addEventListener('click', (event) => {
  const button = event.target.closest('[data-block-action]');
  if (!button || button.disabled || contentSaving || !contentEditorMatchesEntry()) return;
  const index = Number(button.closest('[data-block-index]').dataset.blockIndex);
  const blocks = contentBlockState.blocks;
  const action = button.dataset.blockAction;
  let focusIndex = index;
  if (action === 'remove') {
    blocks.splice(index, 1);
    focusIndex = Math.min(index, blocks.length - 1);
  }
  if (action === 'up' || action === 'down') {
    const next = index + (action === 'up' ? -1 : 1);
    if (next < 0 || next >= blocks.length) return;
    [blocks[index], blocks[next]] = [blocks[next], blocks[index]];
    focusIndex = next;
  }
  if (action === 'add-image' && blocks[index].payload.images.length < 20) blocks[index].payload.images.push({ url: '', alt: '' });
  if (action === 'remove-image' && blocks[index].payload.images.length > 1) blocks[index].payload.images.splice(Number(button.dataset.galleryIndex), 1);
  renderContentBlocks();
  const section = contentBlockList.querySelector(`[data-block-index="${focusIndex}"]`);
  if (action === 'add-image') section?.querySelector('.cms-gallery-image-fields:last-of-type input')?.focus();
  else (section?.querySelector(`[data-block-action="${action}"]:not(:disabled)`) || section?.querySelector('button:not(:disabled)') || document.querySelector('[data-add-content-block]'))?.focus();
  contentBlockNotice(!blocks.length ? 'All blocks removed. Saving will clear the structured article; the legacy body remains separate.' : 'Article order and fields updated. Save to keep your changes.');
});

const serializeContentBlocks = () => {
  if (!contentEditorMatchesEntry()) return null;
  return contentBlockState.blocks.map(({ type, payload }) => {
    let data = {};
    if (type === 'paragraph') data = { text: payload.text };
    if (type === 'heading') data = { text: payload.text, level: Number(payload.level) };
    if (type === 'image') data = { url: payload.url, alt: payload.alt };
    if (type === 'image_caption') data = { url: payload.url, alt: payload.alt, caption: payload.caption };
    if (type === 'video') data = { provider: 'youtube', videoId: normalizeContentYoutubeId(payload.videoId) };
    if (type === 'quote') {
      data = { text: payload.text };
      if (payload.attribution !== undefined) data.attribution = payload.attribution;
    }
    if (type === 'gallery') data = { images: payload.images.map((image) => {
      const item = { url: image.url, alt: image.alt };
      if (image.caption !== undefined) item.caption = image.caption;
      return item;
    }) };
    return { type, payload: data };
  });
};

const validateContentEntry = () => {
  const fields = [...contentForm.querySelectorAll('input, textarea, select')];
  fields.forEach((input) => input.setCustomValidity(''));
  const bytes = (value) => new TextEncoder().encode(value.trim()).length;
  const checkText = (input, max, required = false, byteLimit = false) => {
    const value = input.value.trim();
    if (required && !value) input.setCustomValidity('Enter text before saving.');
    else if ((byteLimit ? bytes(value) : [...value].length) > max) input.setCustomValidity(`This field exceeds the ${max} ${byteLimit ? 'byte' : 'character'} limit.`);
  };
  checkText(contentForm.elements.title, 180, true, true);
  checkText(contentForm.elements.body, 100000, true, true);
  checkText(contentForm.elements.excerpt, 500, false, true);
  const date = contentForm.elements.publishDate;
  if (!/^\d{4}-\d{2}-\d{2}$/.test(date.value) || new Date(`${date.value}T00:00:00Z`).toISOString().slice(0, 10) !== date.value) date.setCustomValidity('Enter a valid publish date.');
  ['seoTitle', 'ogTitle', 'metaDescription', 'ogDescription', 'coverAlt'].forEach((key) => {
    checkText(contentForm.elements[key], ['seoTitle', 'ogTitle'].includes(key) ? 180 : key === 'coverAlt' ? 255 : 320);
  });
  const media = (input) => {
    if (!validContentMediaUrl(input.value)) input.setCustomValidity('Enter a valid HTTP(S) URL or site-relative path without directory traversal.');
  };
  if (contentForm.elements.ogImage.value.trim()) media(contentForm.elements.ogImage);
  if (!contentForm.elements.coverImageFile.files.length && contentForm.elements.coverImage.value.trim()) media(contentForm.elements.coverImage);
  if (contentEditorMatchesEntry()) {
    contentBlockList.querySelectorAll('[data-block-field]').forEach((input) => {
      const block = contentBlockState.blocks[Number(input.closest('[data-block-index]').dataset.blockIndex)];
      const field = input.dataset.blockField;
      if (field === 'url') media(input);
      else if (field === 'videoId' && !normalizeContentYoutubeId(input.value)) input.setCustomValidity('Enter a YouTube URL or an 11-character YouTube video ID.');
      else if (field === 'level' && !['2', '3'].includes(input.value)) input.setCustomValidity('Choose H2 or H3.');
      else if (field === 'text') checkText(input, block.type === 'paragraph' ? 20000 : block.type === 'heading' ? 300 : 5000, true);
      else if (field === 'alt') checkText(input, 255);
      else if (field === 'caption') checkText(input, 500);
      else if (field === 'attribution') checkText(input, 300);
    });
  }
  const invalid = fields.find((input) => !input.checkValidity());
  if (invalid) {
    const details = invalid.closest('details');
    if (details) details.open = true;
    document.querySelector('[data-content-form-message]').textContent = invalid.validationMessage;
    invalid.focus();
    invalid.reportValidity();
    return false;
  }
  const blocks = serializeContentBlocks();
  if (blocks && (blocks.length > 100 || blocks.some((block) => block.type === 'gallery' && (block.payload.images.length < 1 || block.payload.images.length > 20)) || bytes(JSON.stringify(blocks)) > 1048576)) {
    document.querySelector('[data-content-form-message]').textContent = 'Use at most 100 blocks, 1–20 images per gallery, and 1 MB of block data.';
    return false;
  }
  return true;
};

const renderContentCoverPreview = (coverImage) => {
  if (!contentCoverPreview) return;
  contentCoverPreview.replaceChildren();
  contentCoverPreview.hidden = !coverImage;
  if (!coverImage) return;
  const image = document.createElement('img');
  image.src = new URL(coverImage, new URL('../', cmsApi)).href;
  image.alt = 'Current saved cover image';
  const note = document.createElement('span');
  note.textContent = 'A cover image is already saved. Leave Upload and External image URL empty to keep it.';
  contentCoverPreview.append(image, note);
};

const resetContentForm = () => {
  contentForm?.reset();
  contentBlockState = { initialized: false, entryId: null, blocks: [] };
  syncContentSaveButtons();
  renderContentBlocks();
  contentBlockNotice('');
  const seo = document.querySelector('.cms-content-seo');
  if (seo) seo.open = false;
  const legacy = document.querySelector('.cms-content-legacy');
  if (legacy) legacy.open = false;
  const currentStatus = document.querySelector('[data-content-current-status]');
  if (currentStatus) currentStatus.textContent = 'New draft';
  if (contentForm) {
    contentForm.elements.id.value = '';
    contentForm.elements.coverImageFile.value = '';
    contentForm.querySelectorAll('input, textarea, select').forEach((input) => input.setCustomValidity(''));
    contentForm.elements.publishDate.value = new Date().toISOString().slice(0, 10);
  }
  document.querySelector('[data-content-form-title]').textContent = 'Create content entry';
  document.querySelector('[data-content-save]').textContent = 'Save Draft';
  document.querySelector('[data-content-form-message]').textContent = '';
  renderContentCoverPreview('');
};

document.querySelector('[data-open-content-editor]')?.addEventListener('click', () => {
  if (contentSaving) return;
  resetContentForm();
  initializeContentBlocks('', []);
  document.querySelector('[data-content-editor]').hidden = false;
});
document.querySelectorAll('[data-close-content-editor]').forEach((button) => button.addEventListener('click', () => {
  if (contentSaving) return;
  document.querySelector('[data-content-editor]').hidden = true;
  resetContentForm();
}));
listContentActions();

function listContentActions() {
  const list = document.getElementById('content-list');
  if (!list) return;
  list.addEventListener('click', async (event) => {
    const editButton = event.target.closest('[data-edit-content]');
    if (editButton) {
      if (contentSaving) return;
      const entry = cmsContentEntries.find((item) => Number(item.id) === Number(editButton.dataset.editContent));
      if (!entry || !contentForm) return;
      resetContentForm();
      contentForm.elements.id.value = entry.id;
      contentForm.elements.title.value = entry.title;
      contentForm.elements.slug.value = entry.slug;
      const hasExternalCover = /^https?:\/\//i.test(entry.coverImage || '');
      contentForm.elements.coverImage.value = hasExternalCover ? entry.coverImage : '';
      renderContentCoverPreview(entry.coverImage || '');
      contentForm.elements.type.value = entry.type;
      contentForm.elements.publishDate.value = entry.publishDate;
      contentForm.elements.excerpt.value = entry.excerpt;
      contentForm.elements.body.value = entry.body;
      contentForm.elements.status.value = entry.status;
      document.querySelector('[data-content-current-status]').textContent = entry.status === 'published' ? 'Published' : 'Draft';
      contentForm.elements.cardSize.value = entry.cardSize;
      contentForm.elements.featured.checked = entry.featured;
      contentForm.elements.showHome.checked = entry.showHome;
      contentForm.elements.showCard.checked = entry.showCard;
      ['seoTitle', 'metaDescription', 'ogTitle', 'ogDescription', 'ogImage', 'coverAlt'].forEach((key) => {
        contentForm.elements[key].value = entry[key] ?? '';
      });
      contentForm.elements.noindex.checked = entry.noindex === true || entry.noindex === 1 || entry.noindex === '1';
      initializeContentBlocks(entry.id, entry.blocks);
      document.querySelector('[data-content-form-title]').textContent = 'Edit content entry';
      document.querySelector('[data-content-save]').textContent = 'Save Draft';
      document.querySelector('[data-content-editor]').hidden = false;
      return;
    }
    const deleteButton = event.target.closest('[data-delete-content]');
    if (!deleteButton || !window.confirm('Delete this content entry?')) return;
    try {
      await cmsRequest('delete-content', { method: 'POST', body: new URLSearchParams({ id: deleteButton.dataset.deleteContent }) });
      await renderAdminContent();
      await renderPublicContent();
    } catch (error) {
      window.alert(error.message);
    }
  });
}

const saveContentEntry = async (status) => {
  if (!contentForm || contentSaving) return;
  if ([...pendingContentUploads].some((upload) => upload.editor === contentBlockState)) {
    document.querySelector('[data-content-form-message]').textContent = 'Wait for the image upload to finish before saving.';
    return;
  }
  if (!validateContentEntry()) return;
  const message = document.querySelector('[data-content-form-message]');
  const payload = new FormData(contentForm);
  const uploadedCover = payload.get('coverImageFile');
  if (uploadedCover instanceof File && uploadedCover.size > 0) payload.set('coverImage', '');
  payload.set('status', status);
  payload.set('featured', contentForm.elements.featured.checked ? '1' : '0');
  payload.set('showHome', contentForm.elements.showHome.checked ? '1' : '0');
  payload.set('showCard', contentForm.elements.showCard.checked ? '1' : '0');
  payload.set('noindex', contentForm.elements.noindex.checked ? '1' : '0');
  const blocks = serializeContentBlocks();
  if (blocks !== null) payload.set('blocks', JSON.stringify(blocks));
  contentSaving = true;
  syncContentSaveButtons();
  if (message) message.textContent = 'Saving content…';
  try {
    const result = await cmsRequest('content-entry', { method: 'POST', body: payload });
    const refreshed = await renderAdminContent();
    const savedEntry = refreshed && cmsContentEntries.find((entry) => Number(entry.id) === Number(result.id));
    if (!savedEntry) {
      if (message) message.textContent = 'Content saved, but its status could not be refreshed. Reload the Content list before editing again.';
      return;
    }
    resetContentForm();
    initializeContentBlocks('', []);
    if (message) message.textContent = savedEntry.status === 'published' ? 'Content published.' : 'Draft saved.';
    await renderPublicContent();
  } catch (error) {
    if (message) message.textContent = error.message;
  } finally {
    contentSaving = false;
    syncContentSaveButtons();
  }
};

contentForm?.addEventListener('submit', (event) => {
  event.preventDefault();
  saveContentEntry('draft');
});
document.querySelector('[data-content-publish]')?.addEventListener('click', () => saveContentEntry('published'));

const renderPublicContent = async () => {
  const section = document.querySelector('[data-home-content]');
  const target = document.querySelector('[data-public-content-list]');
  if (!section || !target || !useCmsApi) return;
  try {
    // Keep API order; this is a short preview, while Stories lists all published summaries.
    const entries = (await cmsRequest('content')).entries
      .filter((entry) => entry.status === 'published' && entry.showHome && entry.showCard && Object.hasOwn(contentTypeLabels, entry.type))
      .slice(0, 6);
    target.replaceChildren();
    entries.forEach((entry) => {
      const card = document.createElement('article');
      card.className = 'home-content-card';
      card.dataset.cardSize = ['standard', 'wide', 'featured'].includes(entry.cardSize) ? entry.cardSize : 'standard';
      if (entry.featured) card.classList.add('is-featured');
      if (entry.coverImage && validContentMediaUrl(entry.coverImage)) {
        const image = document.createElement('img');
        image.src = entry.coverImage;
        image.alt = `Cover image for ${entry.title}`;
        image.loading = 'lazy';
        image.decoding = 'async';
        image.addEventListener('error', () => image.remove(), { once: true });
        card.append(image);
      }
      const meta = document.createElement('div');
      meta.className = 'story-meta';
      const type = document.createElement('span');
      type.textContent = contentTypeLabels[entry.type];
      meta.append(type);
      if (entry.featured) {
        const featured = document.createElement('span');
        featured.className = 'story-featured';
        featured.textContent = 'Featured';
        meta.append(featured);
      }
      const title = document.createElement('h3');
      const link = document.createElement('a');
      link.href = `story.php?slug=${encodeURIComponent(entry.slug)}`;
      link.textContent = entry.title;
      title.append(link);
      card.append(meta, title);
      if (entry.excerpt) {
        const excerpt = document.createElement('p');
        excerpt.textContent = entry.excerpt;
        card.append(excerpt);
      }
      const date = document.createElement('time');
      date.dateTime = entry.publishDate;
      const parsed = /^\d{4}-\d{2}-\d{2}$/.test(entry.publishDate) ? new Date(`${entry.publishDate}T00:00:00Z`) : null;
      date.textContent = parsed && !Number.isNaN(parsed.valueOf())
        ? parsed.toLocaleDateString('en', { month: 'short', day: 'numeric', year: 'numeric', timeZone: 'UTC' })
        : entry.publishDate;
      card.append(date);
      target.append(card);
    });
    section.hidden = entries.length === 0;
  } catch (error) {
    section.hidden = true;
  }
};

// The archive consumes public summary fields and links to the published Story reader by slug.
const initializeStoriesArchive = () => {
  const archive = document.querySelector('[data-stories-archive]');
  if (!archive) return;
  const list = archive.querySelector('[data-stories-list]');
  const results = archive.querySelector('[data-stories-results]');
  const state = archive.querySelector('[data-stories-state]');
  const stateHeading = archive.querySelector('[data-stories-state-heading]');
  const stateCopy = archive.querySelector('[data-stories-state-copy]');
  const count = archive.querySelector('[data-stories-count]');
  const retry = archive.querySelector('[data-stories-retry]');
  const filters = [...archive.querySelectorAll('[data-story-filter]')];
  const types = contentTypeLabels;
  const emptyTypes = { blog: 'blog posts', news: 'news stories', update: 'updates', announcement: 'announcements' };
  let entries = [];
  let activeFilter = 'all';
  let loading = false;

  const showState = (heading, copy, canRetry = false) => {
    stateHeading.textContent = heading;
    stateCopy.textContent = copy;
    retry.hidden = !canRetry;
    state.hidden = false;
  };

  const renderCard = (entry) => {
    const card = document.createElement('article');
    card.className = 'story-card';
    if (entry.featured) card.classList.add('is-featured');
    if (entry.coverImage && validContentMediaUrl(entry.coverImage)) {
      const image = document.createElement('img');
      image.className = 'story-cover';
      image.src = entry.coverImage;
      // The public API has no coverAlt. Use the story title without exposing Admin fields.
      image.alt = `Cover image for ${entry.title}`;
      image.loading = 'lazy';
      image.decoding = 'async';
      image.addEventListener('error', () => image.remove(), { once: true });
      card.append(image);
    }
    const copy = document.createElement('div');
    copy.className = 'story-copy';
    const meta = document.createElement('div');
    meta.className = 'story-meta';
    const type = document.createElement('span');
    type.textContent = types[entry.type];
    meta.append(type);
    if (entry.featured) {
      const featured = document.createElement('span');
      featured.className = 'story-featured';
      featured.textContent = 'Featured';
      meta.append(featured);
    }
    const title = document.createElement('h2');
    const link = document.createElement('a');
    link.href = `story.php?slug=${encodeURIComponent(entry.slug)}`;
    link.textContent = entry.title;
    title.append(link);
    copy.append(meta, title);
    if (entry.excerpt) {
      const excerpt = document.createElement('p');
      excerpt.textContent = entry.excerpt;
      copy.append(excerpt);
    }
    const date = document.createElement('time');
    date.dateTime = entry.publishDate;
    const parsed = /^\d{4}-\d{2}-\d{2}$/.test(entry.publishDate) ? new Date(`${entry.publishDate}T00:00:00Z`) : null;
    date.textContent = parsed && !Number.isNaN(parsed.valueOf())
      ? parsed.toLocaleDateString('en', { month: 'short', day: 'numeric', year: 'numeric', timeZone: 'UTC' })
      : entry.publishDate;
    copy.append(date);
    card.append(copy);
    return card;
  };

  const render = () => {
    const visible = entries.filter((entry) => activeFilter === 'all' || entry.type === activeFilter);
    list.replaceChildren(...visible.map(renderCard));
    count.textContent = `${visible.length} ${visible.length === 1 ? 'story' : 'stories'}`;
    state.hidden = visible.length > 0;
    if (!visible.length) {
      showState(activeFilter === 'all' ? 'Stories are on their way.' : `No ${emptyTypes[activeFilter]} yet.`,
        activeFilter === 'all' ? 'Check back soon for ideas and notes from the studio.' : 'Try another category, or browse all stories.');
    }
  };

  const load = async () => {
    if (loading) return;
    loading = true;
    results.setAttribute('aria-busy', 'true');
    filters.forEach((button) => { button.disabled = true; });
    list.replaceChildren();
    count.textContent = '';
    showState('Gathering stories…', 'Loading the latest notes from the studio.');
    try {
      const data = await cmsRequest('content');
      if (!Array.isArray(data.entries)) throw new Error('Invalid stories response.');
      entries = data.entries.filter((entry) => entry.status === 'published' && Object.hasOwn(types, entry.type))
        .map((entry) => ({
          slug: entry.slug, title: String(entry.title || ''), coverImage: String(entry.coverImage || ''),
          type: entry.type, excerpt: String(entry.excerpt || ''), publishDate: String(entry.publishDate || ''),
          featured: entry.featured === true || entry.featured === 1 || entry.featured === '1'
        }));
      // Preserve the API order; homepage/card visibility and cardSize do not filter the archive.
      filters.forEach((button) => { button.disabled = false; });
      render();
    } catch {
      entries = [];
      showState('Stories are unavailable right now.', 'Please try again in a little while.', true);
    } finally {
      loading = false;
      results.setAttribute('aria-busy', 'false');
    }
  };

  filters.forEach((button) => button.addEventListener('click', () => {
    if (loading || button.disabled) return;
    activeFilter = button.dataset.storyFilter;
    filters.forEach((filter) => {
      const selected = filter === button;
      filter.classList.toggle('is-active', selected);
      filter.setAttribute('aria-pressed', String(selected));
    });
    render();
  }));
  retry.addEventListener('click', load);
  load();
};

const applyPublicTheme = (theme) => {
  const colors = {
    accentColor: '--blue-deep',
    pageBackground: '--bg',
    surfaceColor: '--panel-strong',
    primaryText: '--text'
  };
  Object.entries(colors).forEach(([key, token]) => {
    const value = theme[key];
    if (/^#[\da-f]{6}$/i.test(value || '')) {
      document.documentElement.style.setProperty(token, value.toLowerCase());
      if (key === 'pageBackground') document.documentElement.style.setProperty('--page-background-start', value.toLowerCase());
      if (key === 'accentColor') {
        document.documentElement.style.setProperty('--blue', value.toLowerCase());
        document.documentElement.style.setProperty('--blue-soft', value.toLowerCase());
      }
      if (key === 'surfaceColor') document.documentElement.style.setProperty('--panel', value.toLowerCase());
    }
  });
  syncPublicButtonContrast();
  document.dispatchEvent(new Event('public-theme-applied'));
  if (allowedThemeValues.radius.has(theme.buttonRadius)) document.documentElement.style.setProperty('--button-radius', theme.buttonRadius);
  if (!allowedThemeValues.galleryLayout.has(theme.galleryLayout) || !allowedThemeValues.galleryEdge.has(theme.galleryEdge)) return;
  document.querySelectorAll('.home-gallery, [data-category-projects], .home-content-grid').forEach((gallery) => {
    gallery.dataset.galleryLayout = theme.galleryLayout;
    gallery.dataset.galleryEdge = theme.galleryEdge;
  });
};

const loadPublicTheme = async () => {
  if (!useCmsApi) return;
  try {
    const result = await cmsRequest('theme');
    applyPublicTheme(result.theme);
  } catch (error) {
    return;
  }
};

const socialForm = document.getElementById('social-form');
if (socialForm) {
  socialForm.addEventListener('submit', (event) => {
    event.preventDefault();

    const formData = new FormData(socialForm);
    const socials = {
      instagram: formData.get('instagram')?.toString().trim() || '',
      facebook: formData.get('facebook')?.toString().trim() || '',
      twitter: formData.get('twitter')?.toString().trim() || '',
      youtube: formData.get('youtube')?.toString().trim() || ''
    };

    const filteredSocials = Object.fromEntries(
      Object.entries(socials).filter(([, value]) => value)
    );

    saveData(STORAGE_KEYS.socials, { ...defaultSocials, ...filteredSocials });
    updateSocialLinks();
    socialForm.reset();
  });
}

const contactForm = document.querySelector('.contact-form');
const contactMessage = document.querySelector('[data-contact-message]');
contactForm?.addEventListener('submit', async (event) => {
  event.preventDefault();
  const submitButton = contactForm.querySelector('button[type="submit"]');
  submitButton.disabled = true;
  if (contactMessage) contactMessage.textContent = 'Sending...';
  try {
    await cmsRequest('contact', { method: 'POST', body: new FormData(contactForm) });
    contactForm.reset();
    if (contactMessage) contactMessage.textContent = 'Your inquiry was sent successfully.';
  } catch (error) {
    if (contactMessage) contactMessage.textContent = error.message;
  } finally {
    submitButton.disabled = false;
  }
});

const shopProductsTarget = document.querySelector('[data-shop-products]');
const cartPanel = document.querySelector('[data-cart-panel]');
const cartItemsTarget = document.querySelector('[data-cart-items]');
const cartTotalTarget = document.querySelector('[data-cart-total]');
const cartCountTargets = document.querySelectorAll('[data-cart-count]');
const checkoutForm = document.querySelector('[data-checkout-form]');
const checkoutMessage = document.querySelector('[data-checkout-message]');
const CART_KEY = 'dyndelShopCart';
let shopProducts = [];
let cart = getStoredData(CART_KEY, []);

const saveCart = () => saveData(CART_KEY, cart);
const money = (value) => `$${Number(value).toFixed(2)}`;

const renderCart = () => {
  const detailedCart = cart.map((item) => ({
    ...item,
    product: shopProducts.find((product) => Number(product.id) === Number(item.id))
  })).filter((item) => item.product);
  const count = detailedCart.reduce((sum, item) => sum + item.quantity, 0);
  const total = detailedCart.reduce((sum, item) => sum + Number(item.product.price) * item.quantity, 0);
  cartCountTargets.forEach((target) => { target.textContent = count; });
  if (cartTotalTarget) cartTotalTarget.textContent = money(total);
  if (cartItemsTarget) {
    cartItemsTarget.innerHTML = detailedCart.length ? detailedCart.map(({ product, quantity }) => `
      <div class="shop-cart-item">
        <img src="${product.image}" alt="${product.title}">
        <div><strong>${product.title}</strong><span>${money(product.price)} &times; ${quantity}</span></div>
        <button class="icon-button" type="button" data-remove-cart="${product.id}" aria-label="Remove ${product.title}">&times;</button>
      </div>
    `).join('') : '<p class="shop-empty-cart">Your cart is empty.</p>';
  }
};

const renderShopProducts = () => {
  if (!shopProductsTarget) return;
  shopProductsTarget.innerHTML = shopProducts.map((product) => `
    <article class="shop-product">
      <img src="${product.image}" alt="${product.title}" loading="lazy">
      <div class="shop-product-copy"><p class="eyebrow">Limited edition</p><h2>${product.title}</h2><p>${product.description}</p><div class="shop-product-footer"><strong>${money(product.price)}</strong><button class="btn btn-primary" type="button" data-add-cart="${product.id}" ${Number(product.stock) < 1 ? 'disabled' : ''}>${Number(product.stock) < 1 ? 'Sold out' : 'Add to cart'}</button></div></div>
    </article>
  `).join('');
};

if (shopProductsTarget) {
  cmsRequest('shop').then((data) => {
    shopProducts = data.products;
    renderShopProducts();
    renderCart();
  }).catch((error) => { shopProductsTarget.innerHTML = `<p class="shop-form-message">${error.message}</p>`; });
  document.addEventListener('click', (event) => {
    const addButton = event.target.closest('[data-add-cart]');
    const removeButton = event.target.closest('[data-remove-cart]');
    if (addButton) {
      const id = Number(addButton.dataset.addCart);
      const existing = cart.find((item) => Number(item.id) === id);
      if (existing) existing.quantity += 1;
      else cart.push({ id, quantity: 1 });
      saveCart();
      renderCart();
      if (cartPanel) cartPanel.hidden = false;
    }
    if (removeButton) {
      cart = cart.filter((item) => Number(item.id) !== Number(removeButton.dataset.removeCart));
      saveCart();
      renderCart();
    }
  });
  document.querySelector('[data-open-cart]')?.addEventListener('click', () => { cartPanel.hidden = false; });
  document.querySelector('[data-close-cart]')?.addEventListener('click', () => { cartPanel.hidden = true; });
  const mobileCartButton = document.querySelector('[data-open-cart]');
  if (mobileCartButton && window.matchMedia('(max-width: 600px)').matches) {
    const savedPosition = getStoredData('dyndelMobileCartPosition', null);
    if (savedPosition) {
      mobileCartButton.style.left = `${savedPosition.left}px`;
      mobileCartButton.style.top = `${savedPosition.top}px`;
      mobileCartButton.style.right = 'auto';
      mobileCartButton.style.bottom = 'auto';
    }
    let dragState = null;
    mobileCartButton.addEventListener('pointerdown', (event) => {
      const rect = mobileCartButton.getBoundingClientRect();
      dragState = { startX: event.clientX, startY: event.clientY, left: rect.left, top: rect.top, moved: false };
      mobileCartButton.setPointerCapture(event.pointerId);
    });
    mobileCartButton.addEventListener('pointermove', (event) => {
      if (!dragState) return;
      const deltaX = event.clientX - dragState.startX;
      const deltaY = event.clientY - dragState.startY;
      if (Math.hypot(deltaX, deltaY) > 5) dragState.moved = true;
      if (!dragState.moved) return;
      const left = Math.min(Math.max(8, dragState.left + deltaX), window.innerWidth - mobileCartButton.offsetWidth - 8);
      const top = Math.min(Math.max(8, dragState.top + deltaY), window.innerHeight - mobileCartButton.offsetHeight - 8);
      mobileCartButton.style.left = `${left}px`;
      mobileCartButton.style.top = `${top}px`;
      mobileCartButton.style.right = 'auto';
      mobileCartButton.style.bottom = 'auto';
    });
    mobileCartButton.addEventListener('pointerup', () => {
      if (dragState?.moved) {
        const rect = mobileCartButton.getBoundingClientRect();
        saveData('dyndelMobileCartPosition', { left: rect.left, top: rect.top });
      }
      dragState = null;
    });
    mobileCartButton.addEventListener('pointercancel', () => { dragState = null; });
  }
  checkoutForm?.addEventListener('submit', async (event) => {
    event.preventDefault();
    if (!cart.length) { checkoutMessage.textContent = 'Add an item before checking out.'; return; }
    const formData = new FormData(checkoutForm);
    formData.set('items', JSON.stringify(cart));
    checkoutMessage.textContent = 'Submitting order...';
    try {
      const result = await cmsRequest('order', { method: 'POST', body: formData });
      checkoutMessage.textContent = `Order #${result.order.id} received. We will contact you to arrange payment and shipping.`;
      cart = [];
      saveCart();
      renderCart();
      checkoutForm.reset();
    } catch (error) {
      checkoutMessage.textContent = error.message;
    }
  });
}

renderProjectList();
renderHomepageGallery();
const publicHomepageReady = Promise.allSettled([
  renderManagedProjectViews(),
  loadPublicTheme(),
  renderPublicContent()
]);

// Keep the native Contact fragment aligned while asynchronous homepage layout settles.
const homepageContact = document.body.dataset.page === 'home' ? document.getElementById('contact') : null;
if (homepageContact) {
  const main = homepageContact.closest('main');
  let resolvingContact = window.location.hash === '#contact';
  const alignContact = () => {
    if (!resolvingContact || window.location.hash !== '#contact') return;
    homepageContact.closest('.reveal')?.classList.add('visible');
    const headerHeight = document.querySelector('.header')?.getBoundingClientRect().height || 0;
    homepageContact.style.scrollMarginTop = `${headerHeight + 16}px`;
    homepageContact.scrollIntoView({ behavior: 'instant', block: 'start' });
  };
  const beginContactAlignment = () => {
    resolvingContact = window.location.hash === '#contact';
    alignContact();
  };
  const stopContactAlignment = () => {
    resolvingContact = false;
  };
  window.addEventListener('hashchange', beginContactAlignment);
  document.addEventListener('click', (event) => {
    if (!(event.target instanceof Element) || event.button !== 0 || event.ctrlKey || event.metaKey || event.shiftKey || event.altKey) return;
    const link = event.target.closest('a[href]');
    if (!link || event.defaultPrevented) return;
    const destination = new URL(link.href);
    if (destination.origin === window.location.origin && destination.pathname === window.location.pathname && destination.hash === '#contact') {
      // Also handle clicking Contact again when the fragment has not changed.
      resolvingContact = true;
      alignContact();
    }
  });
  window.addEventListener('wheel', stopContactAlignment, { passive: true });
  window.addEventListener('touchstart', stopContactAlignment, { passive: true });
  window.addEventListener('pointerdown', stopContactAlignment, { passive: true });
  window.addEventListener('keydown', (event) => {
    if (['ArrowUp', 'ArrowDown', 'PageUp', 'PageDown', 'Home', 'End', ' ', 'Tab'].includes(event.key)) stopContactAlignment();
  });
  if (typeof ResizeObserver !== 'undefined') {
    const layoutObserver = new ResizeObserver(alignContact);
    layoutObserver.observe(main);
    const header = document.querySelector('.header');
    if (header) layoutObserver.observe(header);
  }
  main.addEventListener('transitionend', (event) => {
    if (event.target.contains(homepageContact)) alignContact();
  });
  window.addEventListener('load', alignContact, { once: true });
  publicHomepageReady.then(alignContact);
  document.fonts?.ready.then(alignContact);
  alignContact();
}
initializeStoriesArchive();
updateSocialLinks();
updateAdminVisibility();
restoreCmsSession();

const contactCtas = document.querySelectorAll('.contact-cta');
if (contactCtas.length && !window.matchMedia('(prefers-reduced-motion: reduce)').matches) {
  const wiggleContactButton = () => {
    contactCtas.forEach((button) => {
      button.classList.remove('is-wiggling');
      void button.offsetWidth;
      button.classList.add('is-wiggling');
    });

    window.setTimeout(wiggleContactButton, 5200 + Math.random() * 5200);
  };

  window.setTimeout(wiggleContactButton, 2400 + Math.random() * 3000);
}
