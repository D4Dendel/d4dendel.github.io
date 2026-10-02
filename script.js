const menuToggle = document.querySelector('.menu-toggle');
const nav = document.querySelector('.nav');

const pointerCanvas = document.createElement('canvas');
pointerCanvas.className = 'pointer-canvas';
pointerCanvas.setAttribute('aria-hidden', 'true');
document.body.prepend(pointerCanvas);

const canvasContext = pointerCanvas.getContext('2d');
const reducedMotion = window.matchMedia('(prefers-reduced-motion: reduce)');
const isMobileViewport = window.matchMedia('(max-width: 760px)').matches;
const brushColors = [
  { name: 'Coral', value: '243, 168, 137' },
  { name: 'Terracotta', value: '200, 111, 82' },
  { name: 'Blue', value: '93, 145, 183' },
  { name: 'Golden yellow', value: '221, 169, 72' },
  { name: 'Plum', value: '126, 84, 116' }
];
let selectedBrushColor = brushColors[0].value;
let canvasScale = 1;
let lastPointerPosition = null;

const brushPalette = document.createElement('div');
brushPalette.className = 'brush-palette';
brushPalette.setAttribute('aria-label', 'Brush color');
brushPalette.innerHTML = brushColors.map((color, index) => `
  <button class="brush-swatch${index === 0 ? ' is-selected' : ''}" type="button"
    style="--swatch-color: rgb(${color.value})" data-brush-color="${color.value}"
    aria-label="${color.name}" aria-pressed="${index === 0}"></button>
`).join('');
document.body.append(brushPalette);

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
const syncMascotToPalette = () => {
  if (!contactMascot) return;
  const paletteRect = brushPalette.getBoundingClientRect();
  if (!paletteRect.width || window.matchMedia('(max-width: 760px)').matches) return;
  contactMascot.style.bottom = `${window.innerHeight - paletteRect.top}px`;
  contactMascot.style.right = `${window.innerWidth - paletteRect.right}px`;
};

syncMascotToPalette();
window.addEventListener('resize', syncMascotToPalette);

let paletteDrag = null;
let suppressPaletteClick = false;

brushPalette.addEventListener('pointerdown', (event) => {
  if (event.target.closest('.brush-swatch')) return;

  const paletteRect = brushPalette.getBoundingClientRect();
  paletteDrag = {
    pointerId: event.pointerId,
    startX: event.clientX,
    startY: event.clientY,
    left: paletteRect.left,
    top: paletteRect.top,
    moved: false
  };
  brushPalette.setPointerCapture(event.pointerId);
  brushPalette.classList.add('is-dragging');
});

brushPalette.addEventListener('pointermove', (event) => {
  if (!paletteDrag || event.pointerId !== paletteDrag.pointerId) return;

  const moveX = event.clientX - paletteDrag.startX;
  const moveY = event.clientY - paletteDrag.startY;
  paletteDrag.moved = paletteDrag.moved || Math.hypot(moveX, moveY) > 4;
  const maxLeft = window.innerWidth - brushPalette.offsetWidth - 10;
  const maxTop = window.innerHeight - brushPalette.offsetHeight - 10;
  const nextLeft = Math.max(10, Math.min(maxLeft, paletteDrag.left + moveX));
  const nextTop = Math.max(10, Math.min(maxTop, paletteDrag.top + moveY));

  brushPalette.style.left = `${nextLeft}px`;
  brushPalette.style.top = `${nextTop}px`;
  brushPalette.style.right = 'auto';
  brushPalette.style.bottom = 'auto';
  syncMascotToPalette();
});

const stopPaletteDrag = (event) => {
  if (!paletteDrag || event.pointerId !== paletteDrag.pointerId) return;
  suppressPaletteClick = paletteDrag.moved;
  paletteDrag = null;
  brushPalette.classList.remove('is-dragging');
};

brushPalette.addEventListener('pointerup', stopPaletteDrag);
brushPalette.addEventListener('pointercancel', stopPaletteDrag);

brushPalette.addEventListener('click', (event) => {
  if (suppressPaletteClick) {
    suppressPaletteClick = false;
    return;
  }

  const swatch = event.target.closest('[data-brush-color]');
  if (!swatch) return;

  selectedBrushColor = swatch.dataset.brushColor;
  brushPalette.querySelectorAll('.brush-swatch').forEach((button) => {
    const isSelected = button === swatch;
    button.classList.toggle('is-selected', isSelected);
    button.setAttribute('aria-pressed', String(isSelected));
  });
});

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
    const color = selectedBrushColor;

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
const scriptSource = document.querySelector('script[src$="script.js"]')?.src;
const cmsApi = scriptSource ? new URL('api/index.php', scriptSource).href : 'api/index.php';

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
  showHome: project.showHome === undefined ? true : isHomeVisible(project.showHome)
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
  return `
    <article class="gallery-card admin-project-card reveal visible" data-modal-images="${images.join('|')}" data-card-images="${images.join('|')}" data-card-position="0">
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
        showHome: project.showHome === true || project.showHome === 1 || project.showHome === '1'
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

const resetProductForm = () => {
  productForm?.reset();
  if (productForm) productForm.elements.id.value = '';
  document.querySelector('[data-product-form-title]')?.replaceChildren(document.createTextNode('Add shop product'));
  const cancel = document.querySelector('[data-cancel-product]');
  if (cancel) cancel.hidden = true;
};

const renderAdminProducts = async () => {
  if (!adminProductList) return;
  try {
    const products = (await cmsRequest('admin-products')).products;
    const productCount = document.querySelector('[data-dashboard-product-count]');
    if (productCount) productCount.textContent = String(products.length);
    adminProductList.innerHTML = products.map((product) => `
      <article class="project-item">
        <img src="${product.image}" alt="${product.title}">
        <div class="project-copy"><span class="project-badge">${product.sku}</span><h3>${product.title}</h3><p>${money(product.price)} &middot; ${product.stock} in stock</p><div class="admin-item-actions"><button class="btn btn-secondary" type="button" data-edit-product="${product.id}">Edit</button><button class="btn btn-danger" type="button" data-delete-product="${product.id}">Delete</button></div></div>
      </article>
    `).join('');
    adminProductList.querySelectorAll('[data-edit-product]').forEach((button) => button.addEventListener('click', () => {
      const product = products.find((item) => Number(item.id) === Number(button.dataset.editProduct));
      if (!product || !productForm) return;
      productForm.elements.id.value = product.id;
      productForm.elements.sku.value = product.sku;
      productForm.elements.title.value = product.title;
      productForm.elements.image.value = product.image;
      productForm.elements.description.value = product.description;
      productForm.elements.price.value = product.price;
      productForm.elements.stock.value = product.stock;
      document.querySelector('[data-product-form-title]').textContent = 'Edit shop product';
      document.querySelector('[data-cancel-product]').hidden = false;
      productForm.scrollIntoView({ behavior: 'smooth', block: 'start' });
    }));
    adminProductList.querySelectorAll('[data-delete-product]').forEach((button) => button.addEventListener('click', async () => {
      if (!window.confirm('Delete this product?')) return;
      try { await cmsRequest('delete-product', { method: 'POST', body: new URLSearchParams({ id: button.dataset.deleteProduct }) }); await renderAdminProducts(); } catch (error) { window.alert(error.message); }
    }));
  } catch (error) { adminProductList.innerHTML = `<p class="admin-note">${error.message}</p>`; }
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
productForm?.addEventListener('submit', async (event) => {
  event.preventDefault();
  try { await cmsRequest('product', { method: 'POST', body: new FormData(productForm) }); resetProductForm(); await renderAdminProducts(); } catch (error) { window.alert(error.message); }
});

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
    floatingSketches.push({
      element: releasedSketch,
      x: startX,
      y: startY,
      velocityX: (Math.random() - 0.5) * 1.8,
      velocityY: -1.5 - Math.random() * 1.5,
      rotation: (Math.random() - 0.5) * 8,
      angularVelocity: (Math.random() - 0.5) * 0.08
    });
    window.setTimeout(() => {
      releasedSketch.classList.add('is-fading');
      window.setTimeout(() => {
        releasedSketch.remove();
        const sketchIndex = floatingSketches.findIndex((sketch) => sketch.element === releasedSketch);
        if (sketchIndex !== -1) floatingSketches.splice(sketchIndex, 1);
      }, 6000);
    }, 30000);
    tabletContext.clearRect(0, 0, tabletCanvas.width, tabletCanvas.height);
  });

  window.addEventListener('resize', () => {
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
const allowedThemeValues = {
  radius: new Set(['2px', '4px', '8px', '999px']),
  galleryLayout: new Set(['uniform', 'masonry', 'editorial', 'clean']),
  galleryEdge: new Set(['rounded', 'slight', 'square', 'none'])
};

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

const applyThemePreview = () => {
  if (!themeForm) return;
  const formData = new FormData(themeForm);
  const colors = ['accent', 'background', 'surface', 'text'];
  colors.forEach((field) => {
    const value = String(formData.get(field) || '');
    if (/^#[\da-f]{6}$/i.test(value)) document.body.style.setProperty(`--cms-${field === 'accent' ? 'accent' : field === 'background' ? 'bg' : field}`, value);
  });
  ['radius', 'galleryLayout', 'galleryEdge'].forEach((field) => {
    const value = String(formData.get(field) || '');
    if (!allowedThemeValues[field].has(value)) return;
    if (field === 'radius') document.body.style.setProperty('--cms-radius', value);
    else galleryThemePreview.dataset[field === 'galleryLayout' ? 'galleryLayout' : 'galleryEdge'] = value;
  });
  renderGalleryThemePreview();
};

themeForm?.addEventListener('submit', (event) => {
  event.preventDefault();
  applyThemePreview();
});
themeForm?.addEventListener('reset', () => window.setTimeout(() => {
  ['--cms-accent', '--cms-bg', '--cms-surface', '--cms-text', '--cms-radius'].forEach((token) => document.body.style.removeProperty(token));
  if (galleryThemePreview) {
    galleryThemePreview.dataset.galleryLayout = 'uniform';
    galleryThemePreview.dataset.galleryEdge = 'rounded';
  }
  renderGalleryThemePreview();
}, 0));

document.querySelector('[data-open-content-editor]')?.addEventListener('click', () => {
  const editor = document.querySelector('[data-content-editor]');
  if (editor) editor.hidden = false;
});
document.querySelectorAll('[data-close-content-editor]').forEach((button) => button.addEventListener('click', () => {
  const editor = document.querySelector('[data-content-editor]');
  const form = document.getElementById('content-form');
  const message = document.querySelector('[data-content-form-message]');
  if (editor) editor.hidden = true;
  form?.reset();
  if (message) message.textContent = '';
}));
document.getElementById('content-form')?.addEventListener('submit', (event) => {
  event.preventDefault();
  const message = document.querySelector('[data-content-form-message]');
  if (message) message.textContent = 'Nothing was saved. Content storage and API support require approval and implementation.';
});

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
renderManagedProjectViews();
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
