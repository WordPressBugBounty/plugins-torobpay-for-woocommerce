var __ = ( window.wp && wp.i18n && wp.i18n.__ ) ? wp.i18n.__ : function( s ) { return s; };

/**
 * Hide the visual settings page loader once client-side init is complete.
 */
function dismissVisualSettingsLoader() {
	var wrap = document.querySelector('.torobpay-visual-settings-wrap');
	var loader = document.getElementById('torobpay-visual-settings-loader');
	if (!wrap || !loader) {
		return;
	}

	var minDisplayMs = 150;
	var startedAt = parseInt(wrap.dataset.loaderStartedAt || String(Date.now()), 10);
	var elapsed = Date.now() - startedAt;
	var delay = Math.max(0, minDisplayMs - elapsed);

	function hide() {
		wrap.removeAttribute('aria-busy');
		loader.setAttribute('aria-hidden', 'true');
		loader.hidden = true;
	}

	if (delay > 0) {
		setTimeout(hide, delay);
	} else {
		hide();
	}
}

/**
 * Horizontal tab overflow for the slider display-location bar.
 * Keeps tabs on one row and moves extras into a menu.
 *
 * @param {HTMLElement|null} tabBar
 * @param {function(string): void} activateTab
 * @return {function(): void}
 */
function initSettingsTabOverflow(tabBar, activateTab) {
	if (!tabBar) {
		return function() {};
	}

	const wrapper = tabBar.querySelector('.nav-tab-wrapper');
	const trigger = tabBar.querySelector('.torobpay-tab-overflow-trigger');
	const menu = tabBar.querySelector('.torobpay-tab-overflow-menu');
	const countEl = tabBar.querySelector('.torobpay-tab-overflow-count');
	const overflowContainer = trigger ? trigger.closest('.torobpay-tab-overflow') : null;

	if (!wrapper || !trigger || !menu) {
		return function() {};
	}

	let resizeTimer = null;

	function setOverflowUi(show) {
		trigger.hidden = !show;
		if (overflowContainer) {
			overflowContainer.hidden = !show;
		}
	}

	function getTabs() {
		return Array.from(wrapper.querySelectorAll('.nav-tab'));
	}

	function closeMenu() {
		if (menu.hidden && trigger.getAttribute('aria-expanded') === 'false') {
			return;
		}
		menu.hidden = true;
		trigger.setAttribute('aria-expanded', 'false');
	}

	function openMenu() {
		menu.hidden = false;
		trigger.setAttribute('aria-expanded', 'true');
	}

	function toggleMenu() {
		if (menu.hidden) {
			openMenu();
		} else {
			closeMenu();
		}
	}

	function measureVisibleWidth(tabWidths, visible) {
		let width = 0;
		tabWidths.forEach(function(w, index) {
			if (visible[index]) {
				width += w;
			}
		});
		return width;
	}

	function applyTabVisibility(tabs, visible) {
		tabs.forEach(function(tab, index) {
			tab.classList.toggle('is-tab-overflow-hidden', !visible[index]);
		});
	}

	function hasClippedTab(tabs, visible) {
		const containerRect = wrapper.getBoundingClientRect();
		return tabs.some(function(tab, index) {
			if (!visible[index]) {
				return false;
			}
			const tabRect = tab.getBoundingClientRect();
			return tabRect.left < containerRect.left - 1 || tabRect.right > containerRect.right + 1;
		});
	}

	function buildOverflowMenu(tabs, hiddenIndexes) {
		menu.innerHTML = '';
		hiddenIndexes.forEach(function(index) {
			const tab = tabs[index];
			const item = document.createElement('button');
			item.type = 'button';
			item.className = 'torobpay-tab-overflow-item';
			item.setAttribute('role', 'menuitem');
			item.dataset.tab = tab.dataset.tab;
			item.innerHTML = tab.innerHTML;
			if (tab.classList.contains('nav-tab-active')) {
				item.classList.add('is-active');
			}
			item.addEventListener('click', function() {
				activateTab(tab.dataset.tab);
				closeMenu();
			});
			menu.appendChild(item);
		});
	}

	function layoutTabs() {
		// Avoid DOM writes while the bar is unmeasured (display:none). Native
		// <select> dropdowns close if anything in the document is mutated.
		if (!tabBar.offsetWidth) {
			return;
		}

		const tabs = getTabs();
		if (!tabs.length) {
			return;
		}

		closeMenu();
		tabs.forEach(function(tab) {
			tab.classList.remove('is-tab-overflow-hidden');
		});
		menu.innerHTML = '';
		setOverflowUi(false);
		if (countEl) {
			countEl.textContent = '';
		}

		const activeTab = wrapper.querySelector('.nav-tab-active');
		const activeIndex = tabs.indexOf(activeTab);
		const visible = tabs.map(function() {
			return true;
		});
		const tabWidths = tabs.map(function(tab) {
			return tab.offsetWidth;
		});

		function getBudget() {
			return wrapper.clientWidth;
		}

		if (getBudget() <= 0) {
			return;
		}

		function visibleWidth() {
			return measureVisibleWidth(tabWidths, visible);
		}

		function hideNextOverflowTab() {
			for (let i = tabs.length - 1; i >= 0; i--) {
				if (!visible[i] || i === activeIndex) {
					continue;
				}
				visible[i] = false;
				return true;
			}
			return false;
		}

		function hideNextUnderflowTab() {
			for (let i = 0; i < tabs.length; i++) {
				if (!visible[i] || i === activeIndex) {
					continue;
				}
				visible[i] = false;
				return true;
			}
			return false;
		}

		applyTabVisibility(tabs, visible);

		if (visibleWidth() <= getBudget() && !hasClippedTab(tabs, visible)) {
			setOverflowUi(false);
			return;
		}

		setOverflowUi(true);
		// Let flex reflow allocate space for the overflow button before measuring.
		void tabBar.offsetWidth;

		while (visibleWidth() > getBudget()) {
			if (!hideNextOverflowTab()) {
				break;
			}
			applyTabVisibility(tabs, visible);
		}

		applyTabVisibility(tabs, visible);
		while (hasClippedTab(tabs, visible)) {
			if (!hideNextOverflowTab() && !hideNextUnderflowTab()) {
				break;
			}
			applyTabVisibility(tabs, visible);
		}

		const hiddenIndexes = [];
		tabs.forEach(function(tab, index) {
			if (!visible[index]) {
				hiddenIndexes.push(index);
			}
		});

		if (!hiddenIndexes.length) {
			setOverflowUi(false);
			applyTabVisibility(tabs, visible);
			return;
		}

		if (countEl) {
			countEl.textContent = String(hiddenIndexes.length);
		}
		buildOverflowMenu(tabs, hiddenIndexes);
	}

	trigger.addEventListener('click', function(e) {
		e.preventDefault();
		e.stopPropagation();
		toggleMenu();
	});

	document.addEventListener('click', function(e) {
		if (e.target.closest && e.target.closest('select, option')) {
			return;
		}
		if (!tabBar.contains(e.target)) {
			closeMenu();
		}
	});

	document.addEventListener('keydown', function(e) {
		if (e.key === 'Escape') {
			closeMenu();
		}
	});

	window.addEventListener('resize', function() {
		clearTimeout(resizeTimer);
		resizeTimer = setTimeout(layoutTabs, 100);
	});

	if (typeof ResizeObserver !== 'undefined') {
		const observer = new ResizeObserver(function() {
			clearTimeout(resizeTimer);
			resizeTimer = setTimeout(layoutTabs, 50);
		});
		observer.observe(tabBar);
	}

	layoutTabs();
	return layoutTabs;
}

document.addEventListener('DOMContentLoaded', function() {
	const tabWrapper = document.querySelector('.torobpay-visual-settings-nav-list');
	const tabContents = document.querySelectorAll('.torobpay-visual-settings-main > .tab-content');

	function getTabs() {
		return tabWrapper ? Array.from(tabWrapper.querySelectorAll('[data-tab]')) : [];
	}

	function activateTab(targetTab) {
		const tabs = getTabs();
		const tabElement = tabWrapper ? tabWrapper.querySelector(`[data-tab="${targetTab}"]`) : null;
		const contentElement = document.getElementById(targetTab);

		if (!tabElement || !contentElement) {
			return;
		}

		tabs.forEach(function(tab) {
			const isActive = tab === tabElement;
			tab.classList.toggle('is-active', isActive);
			tab.setAttribute('aria-selected', isActive ? 'true' : 'false');
			tab.tabIndex = isActive ? 0 : -1;
		});

		tabContents.forEach(function(content) {
			const isActive = content === contentElement;
			content.classList.toggle('active', isActive);
			// Hide with CSS (.tab-content) only. The HTML hidden attribute
			// would exclude inactive fields from form submission; aria-hidden
			// on a panel makes Chrome close native <select> dropdowns.
		});

		localStorage.setItem('torobpay_active_tab', targetTab); // Key name kept for existing saved section preference.
		history.replaceState(null, null, '#' + targetTab);

		if (targetTab === 'widget-settings') {
			updateWidgetPreview();
		} else if (targetTab === 'badge-settings') {
			updateBadgePreview();
		} else if (targetTab === 'marquee-settings') {
			updateMarqueePreview();
		}
	}

	getTabs().forEach(function(tab) {
		tab.addEventListener('click', function(e) {
			e.preventDefault();
			activateTab(this.getAttribute('data-tab'));
		});
	});

	if (tabWrapper) {
		tabWrapper.addEventListener('keydown', function(e) {
			const tabs = getTabs();
			const currentIndex = tabs.indexOf(document.activeElement);
			if (currentIndex < 0) {
				return;
			}

			let nextIndex = currentIndex;
			if (e.key === 'ArrowDown' || e.key === 'ArrowRight') {
				nextIndex = (currentIndex + 1) % tabs.length;
			} else if (e.key === 'ArrowUp' || e.key === 'ArrowLeft') {
				nextIndex = (currentIndex - 1 + tabs.length) % tabs.length;
			} else if (e.key === 'Home') {
				nextIndex = 0;
			} else if (e.key === 'End') {
				nextIndex = tabs.length - 1;
			} else {
				return;
			}

			e.preventDefault();
			tabs[nextIndex].focus();
			activateTab(tabs[nextIndex].getAttribute('data-tab'));
		});
	}

	function isSettingsPanel(id) {
		return Array.prototype.some.call(tabContents, function(panel) {
			return panel.id === id;
		});
	}

	let activeTab = null;

	if (window.location.hash) {
		const hashTab = window.location.hash.substring(1);
		if (isSettingsPanel(hashTab)) {
			activeTab = hashTab;
		}
	}

	if (!activeTab) {
		const storedTab = localStorage.getItem('torobpay_active_tab');
		if (storedTab && isSettingsPanel(storedTab)) {
			activeTab = storedTab;
		}
	}

	if (!activeTab) {
		const firstTab = getTabs()[0];
		activeTab = firstTab ? firstTab.getAttribute('data-tab') : null;
	}

	if (activeTab) {
		activateTab(activeTab);
	}

	// Restore preview panel collapse state before other preview init runs.
	initPreviewPanels();

	initSectionCollapse();

	initDeviceSwitchers();

	initWidgetLayoutPresets();

	updateWidgetPreview();
	updateBadgePreview();
});

// Section collapse functionality
function initSectionCollapse() {
	var STORAGE_KEY = 'torobpay_collapsed_sections';
	var collapsedMap = {};
	try {
		collapsedMap = JSON.parse(localStorage.getItem(STORAGE_KEY) || '{}');
	} catch (e) {
		collapsedMap = {};
	}

	var sections = document.querySelectorAll('.section-title[data-section-key]');
	var seenPerTab = {};

	sections.forEach(function(section) {
		var key = section.getAttribute('data-section-key');
		var tabContent = section.closest('.tab-content');
		var tabId = tabContent ? tabContent.id : 'unknown';

		if (!seenPerTab[tabId]) {
			seenPerTab[tabId] = 0;
		}
		seenPerTab[tabId]++;
		var indexInTab = seenPerTab[tabId];

		var isCollapsed;
		if (key in collapsedMap) {
			isCollapsed = collapsedMap[key];
		} else {
			isCollapsed = (indexInTab > 1);
		}

		if (isCollapsed) {
			section.classList.add('torobpay-section-collapsed');
		}

		// Click handler
		section.addEventListener('click', function() {
			this.classList.toggle('torobpay-section-collapsed');
			collapsedMap[key] = this.classList.contains('torobpay-section-collapsed');
			try {
				localStorage.setItem(STORAGE_KEY, JSON.stringify(collapsedMap));
			} catch (e) {}
		});
	});
}

/**
 * Device switcher — each .torobpay-responsive-field has a group of three buttons
 * (desktop / tablet / mobile) and a matching input (or textarea) per device.
 *
 * Clicking a button makes only that device's field visible.  The other two
 * remain in the DOM so they always POST.  Active device is saved per field-name
 * in localStorage so it survives page navigation.
 */
function initDeviceSwitchers(root) {
	var STORAGE_KEY = 'torobpay_active_devices';
	var saved = {};
	try { saved = JSON.parse(localStorage.getItem(STORAGE_KEY) || '{}'); } catch (e) {}

	var scope = root || document;
	scope.querySelectorAll('.torobpay-responsive-field').forEach(function(field) {
		// Skip fields that were already wired (e.g. on initial page load) so
		// re-initializing a dynamically-added slide never double-binds handlers.
		if (field.dataset.tpRfInit === '1') {
			return;
		}
		field.dataset.tpRfInit = '1';

		var fieldName    = field.dataset.fieldName || '';
		var savedDevice  = saved[fieldName] || 'desktop';

		function applyDevice(device) {
			field.setAttribute('data-active-device', device);

			// Toggle button active states.
			field.querySelectorAll('.torobpay-rf-btn').forEach(function(btn) {
				btn.classList.toggle('is-active', btn.dataset.device === device);
			});

			var hint = field.querySelector('.torobpay-rf-hint');
			if (hint) {
				if (device === 'desktop') {
					hint.textContent = __( 'This CSS applies on all screen sizes.', 'torobpay-for-woocommerce' );
				} else if (device === 'tablet') {
					var tbp = field.dataset.bpTablet || '1024';
					hint.textContent = __( 'Applies only on screens smaller than ', 'torobpay-for-woocommerce' ) + tbp + __( 'px.', 'torobpay-for-woocommerce' );
				} else {
					var mbp = field.dataset.bpMobile || '768';
					hint.textContent = __( 'Applies only on screens smaller than ', 'torobpay-for-woocommerce' ) + mbp + __( 'px.', 'torobpay-for-woocommerce' );
				}
			}

			// Persist choice.
			if (fieldName) {
				saved[fieldName] = device;
				try { localStorage.setItem(STORAGE_KEY, JSON.stringify(saved)); } catch (e) {}
			}
		}

		// Wire buttons.
		field.querySelectorAll('.torobpay-rf-btn').forEach(function(btn) {
			btn.addEventListener('click', function() {
				applyDevice(this.dataset.device);
			});
		});

		function refreshValueBadges() {
			['tablet', 'mobile'].forEach(function(dev) {
				var el  = field.querySelector('[data-device="' + dev + '"].torobpay-rf-input');
				var btn = field.querySelector('.torobpay-rf-btn[data-device="' + dev + '"]');
				if (el && btn) {
					var hasVal = el.tagName === 'TEXTAREA' ? el.value.trim() !== '' : el.value !== '';
					btn.classList.toggle('has-value', hasVal);
				}
			});
		}

		// Re-badge whenever any input/textarea/select changes.
		field.querySelectorAll('.torobpay-rf-input').forEach(function(el) {
			el.addEventListener('input', refreshValueBadges);
			el.addEventListener('change', refreshValueBadges);
		});

		refreshValueBadges();

		// Apply saved (or default) on load.
		applyDevice(savedDevice);
	});
}

// Expose so dynamically-added slide rows (slider admin) can wire their own
// per-device fields after being cloned from the template.
window.torobpayInitDeviceSwitchers = initDeviceSwitchers;

var PREVIEW_COLLAPSED_KEY = 'torobpay_preview_collapsed';

function getPreviewSectionKey(section) {
	if (section.id) {
		return section.id;
	}
	var tab = section.closest('.tab-content');
	var tabId = tab ? tab.id : 'unknown';
	var sectionClasses = ['widget-preview-section', 'badge-preview-section', 'slider-preview-section'];
	for (var i = 0; i < sectionClasses.length; i++) {
		if (section.classList.contains(sectionClasses[i])) {
			return tabId + ':' + sectionClasses[i];
		}
	}
	return tabId + ':preview';
}

function loadPreviewCollapsedMap() {
	try {
		return JSON.parse(localStorage.getItem(PREVIEW_COLLAPSED_KEY) || '{}');
	} catch (e) {
		return {};
	}
}

function applyPreviewCollapsedState(section, collapsed) {
	if (collapsed) {
		section.classList.add('torobpay-preview-collapsed');
	} else {
		section.classList.remove('torobpay-preview-collapsed');
	}
	var icon = section.querySelector('.torobpay-preview-toggle .dashicons');
	if (icon) {
		icon.className = collapsed ?
			'dashicons dashicons-plus' :
			'dashicons dashicons-minus';
	}
}

function savePreviewCollapsedState(key, collapsed) {
	var map = loadPreviewCollapsedMap();
	map[key] = collapsed;
	try {
		localStorage.setItem(PREVIEW_COLLAPSED_KEY, JSON.stringify(map));
	} catch (e) {}
}

function initPreviewPanels() {
	var map = loadPreviewCollapsedMap();
	document.querySelectorAll('.widget-preview-section, .badge-preview-section, .slider-preview-section').forEach(function(section) {
		var key = getPreviewSectionKey(section);
		if (key in map) {
			applyPreviewCollapsedState(section, map[key]);
		}
	});
}

function torobpayTogglePreview(btn) {
	var section = btn.closest('.widget-preview-section, .badge-preview-section, .slider-preview-section');
	if (!section) return;
	var collapsed = !section.classList.contains('torobpay-preview-collapsed');
	applyPreviewCollapsedState(section, collapsed);
	savePreviewCollapsedState(getPreviewSectionKey(section), collapsed);
}

// Widget preview functionality
function updateWidgetPreview() {
	const preview = document.getElementById('torobpay-widget-preview');
	if (!preview) {
		return;
	}
	const logo = document.getElementById('torobpay-widget-logo-preview');
	const media = preview.querySelector('.torobpay-widget-media');
	const backgroundColorEl = document.getElementById('widget_background_color');
	const borderColorEl = document.getElementById('widget_border_color');
	const textColorEl = document.getElementById('widget_text_color');
	const borderSizeEl = document.getElementById('widget_border_size');
	const borderRadiusEl = document.getElementById('widget_border_radius');
	const titleFontSizeEl = document.getElementById('widget_title_font_size');
	const titleFontWeightEl = document.getElementById('widget_title_font_weight');
	const descFontSizeEl = document.getElementById('widget_desc_font_size');
	const descFontWeightEl = document.getElementById('widget_desc_font_weight');
	const logoAnimationEl = document.getElementById('widget_logo_animation');
	const layoutEl = document.getElementById('widget_layout');

	if (!backgroundColorEl || !borderColorEl || !textColorEl) {
		return;
	}

	const backgroundColor = backgroundColorEl.value;
	const borderColor = borderColorEl.value;
	const textColor = textColorEl.value;
	const borderSize = borderSizeEl ? borderSizeEl.value : '1';
	const borderRadius = borderRadiusEl ? borderRadiusEl.value : '16';
	const titleFontSize = titleFontSizeEl ? titleFontSizeEl.value : '14';
	const titleFontWeight = titleFontWeightEl ? titleFontWeightEl.value : '700';
	const descFontSize = descFontSizeEl ? descFontSizeEl.value : '12';
	const descFontWeight = descFontWeightEl ? descFontWeightEl.value : '400';
	const logoAnimation = logoAnimationEl ? logoAnimationEl.value : 'light-sweep';
	const layout = layoutEl ? layoutEl.value : 'classic';

	const layoutPresets = (typeof torobpayWidgetLayouts !== 'undefined' && torobpayWidgetLayouts.presets)
		? torobpayWidgetLayouts.presets
		: {};
	const layoutSwatch = (layoutPresets[layout] && layoutPresets[layout].swatch)
		? layoutPresets[layout].swatch
		: {};
	const accent = layoutSwatch.accent || '#9CE76A';
	const accentDark = layoutSwatch.accent_dark || '#003D01';

	preview.style.setProperty('--torobpay-widget-bg', backgroundColor);
	preview.style.setProperty('--torobpay-widget-border', borderColor);
	preview.style.setProperty('--torobpay-widget-text', textColor);
	preview.style.setProperty('--torobpay-widget-accent', accent);
	preview.style.setProperty('--torobpay-widget-accent-dark', accentDark);
	preview.style.backgroundColor = backgroundColor;
	preview.style.borderColor = borderColor;
	preview.style.borderWidth = borderSize + 'px';
	preview.style.borderRadius = borderRadius + 'px';
	preview.style.setProperty('--torobpay-widget-border-width', borderSize + 'px');
	preview.style.setProperty('--torobpay-widget-radius', borderRadius + 'px');
	preview.style.color = textColor;

	const content = preview.querySelector('.torobpay-widget-content');
	if (content) {
		content.style.color = textColor;
	}

	Array.from(preview.classList).forEach(function(cls) {
		if (cls.indexOf('torobpay-widget--') === 0) {
			preview.classList.remove(cls);
		}
	});
	preview.classList.add('torobpay-widget--' + layout);

	const title = preview.querySelector('.torobpay-widget-title');
	if (title) {
		title.style.fontSize = titleFontSize + 'px';
		title.style.fontWeight = titleFontWeight;
		title.style.color = textColor;
	}

	const desc = preview.querySelector('.torobpay-widget-description');
	if (desc) {
		desc.style.fontSize = descFontSize + 'px';
		desc.style.fontWeight = descFontWeight;
		desc.style.color = textColor;
	}

	if (logo) {
		logo.className = 'torobpay-widget-image';
	}
	if (media) {
		media.className = 'torobpay-widget-media';
	}

	const existingIndicator = preview.querySelector('.torobpay-widget-status-indicator');
	if (existingIndicator) {
		existingIndicator.remove();
	}

	const supportedImageAnimations = ['pulse', 'wobble', 'bounce', 'ping'];
	if (logo && supportedImageAnimations.includes(logoAnimation)) {
		logo.classList.add('torobpay-logo-' + logoAnimation);
	} else if (logoAnimation === 'light-sweep' && media) {
		media.classList.add('torobpay-logo-light-sweep');
	} else if (logoAnimation === 'elastic-spin') {
		if (media) {
			media.classList.add('torobpay-logo-elastic');
		}
		if (logo) {
			logo.classList.add('torobpay-logo-elastic-spin');
		}
	} else if (logoAnimation === 'status-dot') {
		const indicator = document.createElement('div');
		indicator.className = 'torobpay-widget-status-indicator';
		indicator.innerHTML = '<span class="torobpay-status-heartbeat"></span><span class="torobpay-status-dot"></span>';
		preview.appendChild(indicator);
	}

	syncPromoPreview(preview);
}

/**
 * @return {string} marquee|simple
 */
function promoStyleValue() {
	var el = document.getElementById('pdp_promo_style');
	return el && el.value === 'simple' ? 'simple' : 'marquee';
}

/**
 * @param {string} label
 * @param {string} code
 * @param {boolean} hidden
 * @return {HTMLElement}
 */
function promoStyleItem(label, code, hidden) {
	var item = document.createElement('span');
	item.className = 'torobpay-widget-promo-item';
	if (hidden) {
		item.setAttribute('aria-hidden', 'true');
	}
	var labelEl = document.createElement('span');
	labelEl.className = 'torobpay-widget-promo-label';
	labelEl.textContent = label;
	var kbd = document.createElement('kbd');
	kbd.className = 'torobpay-widget-promo-code';
	kbd.dir = 'ltr';
	kbd.textContent = code;
	item.appendChild(labelEl);
	item.appendChild(kbd);
	return item;
}

/**
 * @param {string} label
 * @param {string} code
 * @param {boolean} hideAll
 * @return {HTMLElement}
 */
function promoMarqueeSet(label, code, hideAll) {
	var set = document.createElement('span');
	set.className = 'torobpay-widget-promo-set';
	if (hideAll) {
		set.setAttribute('aria-hidden', 'true');
	}
	for (var i = 0; i < 4; i++) {
		set.appendChild(promoStyleItem(label, code, !hideAll && i > 0));
	}
	return set;
}

/**
 * Live-update the widget preview promo chip from the campaign data attributes.
 *
 * @param {HTMLElement} preview
 */
function syncPromoPreview(preview) {
	if (!preview) {
		return;
	}

	var widget = document.getElementById('pdp_promo_widget');
	var content = preview.querySelector('.torobpay-widget-content');
	var existing = preview.querySelector('.torobpay-widget-promo');
	var code = preview.getAttribute('data-promo-code') || '';
	var show = widget && widget.checked && code;
	var marquee = promoStyleValue() === 'marquee';

	preview.classList.toggle('has-torobpay-promo', !!show);
	preview.classList.toggle('torobpay-promo-marquee', !!show && marquee);

	if (existing) {
		existing.remove();
	}

	if (!show) {
		return;
	}

	var label = preview.getAttribute('data-promo-label') || __('Special TorobPay discount', 'torobpay-for-woocommerce');
	var button = document.createElement('button');
	button.type = 'button';
	button.className = 'torobpay-widget-promo';
	button.tabIndex = -1;

	if (marquee) {
		button.classList.add('torobpay-widget-promo--marquee');
		var track = document.createElement('span');
		track.className = 'torobpay-widget-promo-track';
		track.appendChild(promoMarqueeSet(label, code, false));
		track.appendChild(promoMarqueeSet(label, code, true));
		button.appendChild(track);
		preview.appendChild(button);
		return;
	}

	if (!content) {
		return;
	}

	var labelEl = document.createElement('span');
	labelEl.className = 'torobpay-widget-promo-label';
	labelEl.textContent = label;
	var kbd = document.createElement('kbd');
	kbd.className = 'torobpay-widget-promo-code';
	kbd.dir = 'ltr';
	kbd.textContent = code;
	button.appendChild(labelEl);
	button.appendChild(kbd);
	content.appendChild(button);
}

/**
 * Apply a layout preset: set layout slug + seed recommended form defaults.
 *
 * @param {string} layoutSlug
 */
function applyWidgetLayoutPreset(layoutSlug) {
	const presets = (typeof torobpayWidgetLayouts !== 'undefined' && torobpayWidgetLayouts.presets)
		? torobpayWidgetLayouts.presets
		: {};
	const preset = presets[layoutSlug];
	if (!preset) {
		return;
	}

	const layoutInput = document.getElementById('widget_layout');
	if (layoutInput) {
		layoutInput.value = layoutSlug;
	}

	document.querySelectorAll('.torobpay-layout-preset-card').forEach(function(card) {
		const selected = card.getAttribute('data-layout') === layoutSlug;
		card.classList.toggle('is-selected', selected);
		card.setAttribute('aria-checked', selected ? 'true' : 'false');
	});

	const defaults = preset.defaults || {};
	const fieldMap = {
		background_color: 'widget_background_color',
		border_color: 'widget_border_color',
		text_color: 'widget_text_color',
		border_size: 'widget_border_size',
		border_radius: 'widget_border_radius',
		title_font_size: 'widget_title_font_size',
		title_font_weight: 'widget_title_font_weight',
		desc_font_size: 'widget_desc_font_size',
		desc_font_weight: 'widget_desc_font_weight',
		logo_animation: 'widget_logo_animation'
	};

	Object.keys(fieldMap).forEach(function(key) {
		if (typeof defaults[key] === 'undefined') {
			return;
		}
		const el = document.getElementById(fieldMap[key]);
		if (!el) {
			return;
		}
		el.value = defaults[key];
		el.dispatchEvent(new Event('input', { bubbles: true }));
		el.dispatchEvent(new Event('change', { bubbles: true }));
	});

	updateWidgetPreview();
}

function initWidgetLayoutPresets() {
	const cards = document.querySelectorAll('.torobpay-layout-preset-card');
	if (!cards.length) {
		return;
	}
	cards.forEach(function(card) {
		card.addEventListener('click', function() {
			const layout = this.getAttribute('data-layout');
			if (layout) {
				applyWidgetLayoutPreset(layout);
			}
		});
	});
}

// Badge preview functionality
function updateBadgePreview() {
	const preview = document.getElementById('torobpay-badge-preview');
	if (!preview) return;

	function fieldValue(id, fallback) {
		const el = document.getElementById(id);
		return el ? el.value : fallback;
	}

	const positionHorizontal = fieldValue('badge_position_horizontal', 'right');
	const positionVertical = fieldValue('badge_position_vertical', 'top');
	const offsetHorizontal = fieldValue('badge_offset_horizontal', '10');
	const offsetVertical = fieldValue('badge_offset_vertical', '10');
	const offsetHorizontalUnit = fieldValue('badge_offset_horizontal_unit', 'px');
	const offsetVerticalUnit = fieldValue('badge_offset_vertical_unit', 'px');
	const backgroundColor = fieldValue('badge_background_color', '#9CE76A');
	const borderColor = fieldValue('badge_border_color', '#9CE76A');
	const textColor = fieldValue('badge_text_color', '#003D01');
	const borderRadius = fieldValue('badge_border_radius', '8');
	const fontSize = fieldValue('badge_font_size', '12');
	const fontWeight = fieldValue('badge_font_weight', '600');
	const logoHeight = fieldValue('badge_logo_height', '24');

	preview.style.top = '';
	preview.style.bottom = '';
	preview.style.left = '';
	preview.style.right = '';

	preview.style[positionHorizontal] = offsetHorizontal + offsetHorizontalUnit;
	preview.style[positionVertical] = offsetVertical + offsetVerticalUnit;

	const oppositeHorizontal = positionHorizontal === 'right' ? 'left' : 'right';
	const oppositeVertical = positionVertical === 'top' ? 'bottom' : 'top';
	preview.style[oppositeHorizontal] = 'auto';
	preview.style[oppositeVertical] = 'auto';

	const inner = preview.querySelector('.torobpay-product-badge-inner');
	const paintTarget = inner || preview;
	paintTarget.style.backgroundColor = backgroundColor;
	paintTarget.style.borderColor = borderColor;
	paintTarget.style.borderRadius = borderRadius + 'px';

	const badgeText = preview.querySelector('.torobpay-product-badge-text');
	if (badgeText) {
		badgeText.style.color = textColor;
		badgeText.style.fontSize = fontSize + 'px';
		badgeText.style.fontWeight = fontWeight;
	}

	const badgeIcon = preview.querySelector('.torobpay-product-badge-icon');
	if (badgeIcon) {
		badgeIcon.width = logoHeight;
		badgeIcon.height = logoHeight;
		badgeIcon.style.height = logoHeight + 'px';
	}
}

// Marquee preview functionality
function updateMarqueePreview() {
	const marqueePreview = document.getElementById('torobpay-marquee-preview');
	if (!marqueePreview) return;

	// Read all marquee settings
	const slogan = __( 'Buy in installments with TorobPay — no fees', 'torobpay-for-woocommerce' );
	const bgColor = (document.getElementById('marquee_bg_color') || {}).value || '#ffffff';
	const bgOpacity = parseInt((document.getElementById('marquee_bg_opacity') || {}).value || 22, 10);
	const textColor = (document.getElementById('marquee_text_color') || {}).value || '#003D01';
	const fontSize = parseInt((document.getElementById('marquee_font_size') || {}).value || 11, 10);
	const fontWeight = (document.getElementById('marquee_font_weight') || {}).value || '600';
	const iconSize = parseInt((document.getElementById('marquee_icon_size') || {}).value || 14, 10);
	const radiusTl = parseInt((document.getElementById('marquee_radius_tl') || {}).value || 0, 10);
	const radiusTr = parseInt((document.getElementById('marquee_radius_tr') || {}).value || 0, 10);
	const radiusBr = parseInt((document.getElementById('marquee_radius_br') || {}).value || 0, 10);
	const radiusBl = parseInt((document.getElementById('marquee_radius_bl') || {}).value || 0, 10);
	const height = parseInt((document.getElementById('marquee_height') || {}).value || 26, 10);
	const heightUnit = (document.getElementById('marquee_height_unit') || {}).value || 'px';
	const speed = parseInt((document.getElementById('marquee_scroll_speed') || {}).value || 14, 10);
	const position = (document.getElementById('marquee_position') || {}).value || 'bottom';
	const gap = parseInt((document.getElementById('marquee_gap') || {}).value || 0, 10);
	const zIndex = parseInt((document.getElementById('marquee_z_index') || {}).value || 10, 10);
	const verticalAlign = (document.getElementById('marquee_vertical_align') || {}).value || 'middle';
	const gapUnit = (document.getElementById('marquee_gap_unit') || {}).value || 'px';
	const verticalPadding = parseFloat((document.getElementById('marquee_vertical_padding') || {}).value || 0);
	const verticalPaddingUnit = (document.getElementById('marquee_vertical_padding_unit') || {}).value || 'px';

	// Convert hex + opacity to rgba
	function hexToRgba(hex, opacity) {
		hex = hex.replace('#', '');
		if (hex.length === 3) hex = hex[0] + hex[0] + hex[1] + hex[1] + hex[2] + hex[2];
		const r = parseInt(hex.substring(0, 2), 16);
		const g = parseInt(hex.substring(2, 4), 16);
		const b = parseInt(hex.substring(4, 6), 16);
		const a = Math.round(Math.min(100, Math.max(0, opacity))) / 100;
		return 'rgba(' + r + ',' + g + ',' + b + ',' + a + ')';
	}

	// Apply styles to preview
	marqueePreview.style.display = 'block';
	marqueePreview.style.background = hexToRgba(bgColor, bgOpacity);
	marqueePreview.style.height = height + heightUnit;
	marqueePreview.style.zIndex = zIndex;
	marqueePreview.style.borderRadius = radiusTl + 'px ' + radiusTr + 'px ' + radiusBr + 'px ' + radiusBl + 'px';

	// Position + gap
	if (position === 'top') {
		marqueePreview.style.top = gap + gapUnit;
		marqueePreview.style.bottom = 'auto';
		marqueePreview.style.borderTop = 'none';
		marqueePreview.style.borderBottom = '1px solid rgba(255,255,255,0.35)';
	} else {
		marqueePreview.style.bottom = gap + gapUnit;
		marqueePreview.style.top = 'auto';
		marqueePreview.style.borderBottom = 'none';
		marqueePreview.style.borderTop = '1px solid rgba(255,255,255,0.35)';
	}

	const track = marqueePreview.querySelector('.torobpay-product-marquee-track');
	if (track) {
		track.style.animationName = 'torobpay-marquee-scroll';
		track.style.animationDuration = speed + 's';
		track.style.animationTimingFunction = 'linear';
		track.style.animationIterationCount = 'infinite';
		const alignMap = {
			top: 'flex-start',
			middle: 'center',
			bottom: 'flex-end'
		};
		track.style.alignItems = alignMap[verticalAlign] || 'center';
		// Smart padding based on vertical align direction
		track.style.boxSizing = 'border-box';
		if (verticalAlign === 'top') {
			track.style.paddingTop = verticalPadding + verticalPaddingUnit;
			track.style.paddingBottom = '';
		} else if (verticalAlign === 'bottom') {
			track.style.paddingBottom = verticalPadding + verticalPaddingUnit;
			track.style.paddingTop = '';
		} else {
			track.style.paddingTop = verticalPadding + verticalPaddingUnit;
			track.style.paddingBottom = verticalPadding + verticalPaddingUnit;
		}
	}

	const items = marqueePreview.querySelectorAll('.torobpay-product-marquee-item');
	items.forEach(function(item) {
		item.style.color = textColor;
		item.style.fontSize = fontSize + 'px';
		item.style.fontWeight = fontWeight;
	});

	const logos = marqueePreview.querySelectorAll('.torobpay-product-marquee-logo');
	logos.forEach(function(logo) {
		logo.style.setProperty('height', iconSize + 'px', 'important');
		logo.style.setProperty('width', 'auto', 'important');
		logo.setAttribute('height', iconSize);
		logo.setAttribute('width', iconSize);
	});

	// Update slogan text in preview
	const sloganSpans = marqueePreview.querySelectorAll('.torobpay-marquee-preview-slogan');
	sloganSpans.forEach(function(span) {
		span.textContent = slogan;
	});
}

/**
 * Green slider bounds. PHP localizes the same numbers as torobpayGreenColor.
 *
 * @return {{hue:number,saturationMin:number,saturationMax:number,lightnessMin:number,lightnessMax:number}}
 */
function greenColorLimits() {
	var cfg = window.torobpayGreenColor || {};
	return {
		hue: Number(cfg.hue) || 96,
		saturationMin: Number(cfg.saturationMin) || 35,
		saturationMax: Number(cfg.saturationMax) || 100,
		lightnessMin: Number(cfg.lightnessMin) || 8,
		lightnessMax: Number(cfg.lightnessMax) || 97
	};
}

function normalizeHexColor(color) {
	var value = String(color || '').trim();
	if (!value) {
		return '';
	}
	if (value.charAt(0) !== '#') {
		value = '#' + value;
	}
	var match = value.match(/^#([0-9a-fA-F]{3}|[0-9a-fA-F]{6})$/);
	if (!match) {
		return '';
	}
	var hex = match[1].toLowerCase();
	if (hex.length === 3) {
		hex = hex.charAt(0) + hex.charAt(0) + hex.charAt(1) + hex.charAt(1) + hex.charAt(2) + hex.charAt(2);
	}
	return '#' + hex;
}

function hexToRgb(hex) {
	return [
		parseInt(hex.slice(1, 3), 16),
		parseInt(hex.slice(3, 5), 16),
		parseInt(hex.slice(5, 7), 16)
	];
}

function rgbToHsl(red, green, blue) {
	var r = red / 255;
	var g = green / 255;
	var b = blue / 255;
	var max = Math.max(r, g, b);
	var min = Math.min(r, g, b);
	var l = (max + min) / 2;
	var d = max - min;
	var h = 0;
	var s = 0;
	if (d > 0) {
		s = d / (1 - Math.abs((2 * l) - 1));
		if (max === r) {
			h = ((g - b) / d) % 6;
		} else if (max === g) {
			h = ((b - r) / d) + 2;
		} else {
			h = ((r - g) / d) + 4;
		}
		h *= 60;
		if (h < 0) {
			h += 360;
		}
	}
	return { h: h, s: s * 100, l: l * 100 };
}

function hslToHex(hue, saturation, lightness) {
	var s = saturation / 100;
	var l = lightness / 100;
	var c = (1 - Math.abs((2 * l) - 1)) * s;
	var hp = (((hue % 360) + 360) % 360) / 60;
	var x = c * (1 - Math.abs((hp % 2) - 1));
	var r = 0;
	var g = 0;
	var b = 0;
	if (hp < 1) {
		r = c;
		g = x;
	} else if (hp < 2) {
		r = x;
		g = c;
	} else if (hp < 3) {
		g = c;
		b = x;
	} else if (hp < 4) {
		g = x;
		b = c;
	} else if (hp < 5) {
		r = x;
		b = c;
	} else {
		r = c;
		b = x;
	}
	var m = l - (c / 2);
	function channel(value) {
		var n = Math.round((value + m) * 255);
		if (n < 0) {
			return 0;
		}
		if (n > 255) {
			return 255;
		}
		return n;
	}
	function byte(n) {
		var text = n.toString(16);
		return text.length === 1 ? '0' + text : text;
	}
	return '#' + byte(channel(r)) + byte(channel(g)) + byte(channel(b));
}

function projectHexOntoGreen(hex) {
	var limits = greenColorLimits();
	var rgb = hexToRgb(hex);
	var hsl = rgbToHsl(rgb[0], rgb[1], rgb[2]);
	var s = Math.round(hsl.s);
	var l = Math.round(hsl.l);
	s = Math.min(limits.saturationMax, Math.max(limits.saturationMin, s));
	l = Math.min(limits.lightnessMax, Math.max(limits.lightnessMin, l));
	return hslToHex(limits.hue, s, l);
}

function clampHexToGreen(color, fallback) {
	var hex = normalizeHexColor(color);
	if (!hex) {
		hex = normalizeHexColor(fallback || '#9CE76A');
	}
	if (!hex) {
		hex = '#9ce76a';
	}
	var current = hex;
	var i;
	for (i = 0; i < 4; i++) {
		var next = projectHexOntoGreen(current);
		if (next === current) {
			return current;
		}
		current = next;
	}
	return current;
}

function greenSliderState(color) {
	var limits = greenColorLimits();
	var hex = clampHexToGreen(color, '#9CE76A');
	var rgb = hexToRgb(hex);
	var hsl = rgbToHsl(rgb[0], rgb[1], rgb[2]);
	var s = Math.round(hsl.s);
	var l = Math.round(hsl.l);
	s = Math.min(limits.saturationMax, Math.max(limits.saturationMin, s));
	l = Math.min(limits.lightnessMax, Math.max(limits.lightnessMin, l));
	return { hex: hex, saturation: s, lightness: l };
}

function greenTrackDirection() {
	var dir = (document.documentElement.getAttribute('dir') || '').toLowerCase();
	if (dir === 'rtl' || getComputedStyle(document.documentElement).direction === 'rtl') {
		return 'left';
	}
	return 'right';
}

function hslCss(hue, saturation, lightness) {
	return 'hsl(' + hue + ', ' + saturation + '%, ' + lightness + '%)';
}

function bindGreenColorControl(root) {
	var hidden = root.querySelector('input[type="hidden"]');
	var lightness = root.querySelector('.torobpay-green-color__lightness');
	var saturation = root.querySelector('.torobpay-green-color__saturation');
	var swatch = root.querySelector('.torobpay-green-color__swatch');
	if (!hidden || !lightness || !saturation) {
		return;
	}

	var writing = false;

	function updateTracks() {
		var limits = greenColorLimits();
		var direction = greenTrackDirection();
		var s = Number(saturation.value);
		var l = Number(lightness.value);
		lightness.style.setProperty(
			'--torobpay-green-track',
			'linear-gradient(to ' + direction + ', ' + hslCss(limits.hue, s, limits.lightnessMin) + ', ' + hslCss(limits.hue, s, limits.lightnessMax) + ')'
		);
		saturation.style.setProperty(
			'--torobpay-green-track',
			'linear-gradient(to ' + direction + ', ' + hslCss(limits.hue, limits.saturationMin, l) + ', ' + hslCss(limits.hue, limits.saturationMax, l) + ')'
		);
	}

	function paint(hex) {
		if (swatch) {
			swatch.style.backgroundColor = hex;
		}
		updateTracks();
	}

	function emit(hex) {
		writing = true;
		hidden.value = hex;
		paint(hex);
		hidden.dispatchEvent(new Event('input', { bubbles: true }));
		hidden.dispatchEvent(new Event('change', { bubbles: true }));
		writing = false;
	}

	function syncFromHidden() {
		var state = greenSliderState(hidden.value);
		lightness.value = String(state.lightness);
		saturation.value = String(state.saturation);
		hidden.value = state.hex;
		paint(state.hex);
	}

	function paintFromSliders() {
		var limits = greenColorLimits();
		emit(clampHexToGreen(hslToHex(limits.hue, Number(saturation.value), Number(lightness.value)), '#9CE76A'));
	}

	lightness.addEventListener('input', paintFromSliders);
	saturation.addEventListener('input', paintFromSliders);
	hidden.addEventListener('input', function() {
		if (writing) {
			return;
		}
		syncFromHidden();
	});
	syncFromHidden();
}

function initGreenColorControls() {
	document.querySelectorAll('.torobpay-green-color').forEach(bindGreenColorControl);
}

// Add event listeners for live preview
document.addEventListener('DOMContentLoaded', function() {
	initGreenColorControls();

	const widgetInputs = [
		'widget_background_color',
		'widget_border_color',
		'widget_text_color',
		'widget_border_size',
		'widget_border_radius',
		'widget_title_font_size',
		'widget_title_font_weight',
		'widget_desc_font_size',
		'widget_desc_font_weight',
		'widget_logo_animation',
		'pdp_promo_widget',
		'pdp_promo_style'
	];

	widgetInputs.forEach(function(inputId) {
		const input = document.getElementById(inputId);
		if (input) {
			input.addEventListener('input', updateWidgetPreview);
			input.addEventListener('change', updateWidgetPreview);
		}
	});

	const badgeInputs = [
		'badge_position_horizontal',
		'badge_position_vertical',
		'badge_offset_horizontal',
		'badge_offset_vertical',
		'badge_offset_horizontal_unit',
		'badge_offset_vertical_unit',
		'badge_background_color',
		'badge_border_color',
		'badge_text_color',
		'badge_border_radius',
		'badge_font_size',
		'badge_font_weight',
		'badge_logo_height'
	];

	badgeInputs.forEach(function(inputId) {
		const input = document.getElementById(inputId);
		if (input) {
			input.addEventListener('input', updateBadgePreview);
			input.addEventListener('change', updateBadgePreview);
		}
	});

	const marqueeInputs = [
		'marquee_bg_color', 'marquee_bg_opacity',
		'marquee_text_color', 'marquee_font_size', 'marquee_font_weight',
		'marquee_icon_size', 'marquee_height', 'marquee_height_unit',
		'marquee_position', 'marquee_gap', 'marquee_gap_unit',
		'marquee_z_index', 'marquee_scroll_speed',
		'marquee_vertical_align', 'marquee_vertical_padding', 'marquee_vertical_padding_unit',
		'marquee_radius_tl', 'marquee_radius_tr', 'marquee_radius_br', 'marquee_radius_bl'
	];
	marqueeInputs.forEach(function(inputId) {
		const input = document.getElementById(inputId);
		if (input) {
			input.addEventListener('input', updateMarqueePreview);
			input.addEventListener('change', updateMarqueePreview);
		}
	});

	// Initial preview update
	updateWidgetPreview();
	updateBadgePreview();
	updateMarqueePreview();

	// Live-save display toggles via AJAX
	initDisplayToggles();

	dismissVisualSettingsLoader();
});

function initDisplayToggles() {
	// Lift the loading gate so checkboxes become interactive
	var card = document.querySelector('.torobpay-display-settings-card');
	if (card) card.classList.remove('is-loading');
	var status = document.getElementById('torobpay-display-toggle-status');
	if (status && status.classList.contains('saving')) {
		status.textContent = '';
		status.className = 'torobpay-display-toggle-status';
	}

	var ids = ['torobpay_widget_enabled', 'torobpay_badge_enabled', 'torobpay_marquee_enabled', 'torobpay_topbar_enabled', 'torobpay_slider_enabled'];
	ids.forEach(function(id) {
		var el = document.getElementById(id);
		if (el) {
			el.addEventListener('change', saveDisplayToggles);
		}
	});
}

function saveDisplayToggles() {
	var status = document.getElementById('torobpay-display-toggle-status');
	if (status) {
		status.textContent = __( 'Saving…', 'torobpay-for-woocommerce' );
		status.className = 'torobpay-display-toggle-status saving';
	}

	var data = new FormData();
	data.append('action', 'torobpay_save_display_toggles');
	data.append('nonce', torobpayDisplayToggles.nonce);
	data.append('widget_enabled', document.getElementById('torobpay_widget_enabled').checked ? '1' : '0');
	data.append('badge_enabled', document.getElementById('torobpay_badge_enabled').checked ? '1' : '0');
	data.append('marquee_enabled', document.getElementById('torobpay_marquee_enabled').checked ? '1' : '0');
	data.append('topbar_enabled', document.getElementById('torobpay_topbar_enabled').checked ? '1' : '0');
	var sliderToggle = document.getElementById('torobpay_slider_enabled');
	if (sliderToggle) {
		data.append('slider_enabled', sliderToggle.checked ? '1' : '0');
	}

	fetch(torobpayDisplayToggles.ajaxurl, {
			method: 'POST',
			body: data
		})
		.then(function(res) {
			return res.json();
		})
		.then(function(json) {
			if (status) {
				if (json.success) {
					status.textContent = __( '✓ Saved', 'torobpay-for-woocommerce' );
					status.className = 'torobpay-display-toggle-status saved';
				} else {
					status.textContent = __( 'Error saving', 'torobpay-for-woocommerce' );
					status.className = 'torobpay-display-toggle-status error';
				}
				setTimeout(function() {
					status.textContent = '';
					status.className = 'torobpay-display-toggle-status';
				}, 2500);
			}
			updatePromoNotice();
		})
		.catch(function() {
			if (status) {
				status.textContent = __( 'Connection error', 'torobpay-for-woocommerce' );
				status.className = 'torobpay-display-toggle-status error';
				setTimeout(function() {
					status.textContent = '';
					status.className = 'torobpay-display-toggle-status';
				}, 2500);
			}
		});
}

function updatePromoNotice() {
	var tagMap = {
		'torobpay_widget_enabled': __( 'Widget', 'torobpay-for-woocommerce' ),
		'torobpay_badge_enabled': __( 'Badge', 'torobpay-for-woocommerce' ),
		'torobpay_marquee_enabled': __( 'Marquee', 'torobpay-for-woocommerce' ),
		'torobpay_topbar_enabled': __( 'Top bar', 'torobpay-for-woocommerce' ),
		'torobpay_slider_enabled': __( 'Slider', 'torobpay-for-woocommerce' )
	};
	var disabledItems = [];

	Object.keys(tagMap).forEach(function(id) {
		var checkbox = document.getElementById(id);
		var isEnabled = checkbox && checkbox.checked;
		if (!isEnabled) {
			disabledItems.push(tagMap[id]);
		}
	});

	var notice = document.querySelector('.torobpay-display-promo-notice');

	// If everything is enabled, remove notice if it exists.
	if (disabledItems.length === 0) {
		if (notice) {
			notice.remove();
		}
		return;
	}

	if (!notice) {
		var displayCard = document.querySelector('.torobpay-display-settings-card');
		if (!displayCard || !displayCard.parentNode) {
			return;
		}

		notice = document.createElement('div');
		notice.className = 'torobpay-promo-notice torobpay-display-promo-notice';
		// TODO: Review for DRY implementation by reusing a single promo-notice template between PHP and JS.
		notice.innerHTML = '<span class="dashicons dashicons-chart-line torobpay-promo-notice-icon"></span>' +
			'<div class="torobpay-promo-notice-body">' +
			'<strong>' + __( 'Enabling TorobPay elements can increase sales', 'torobpay-for-woocommerce' ) + '</strong>' +
			'<p>' + __( 'Showing the widget, badge, and marquee informs shoppers about fee-free installment payments and can improve conversion.', 'torobpay-for-woocommerce' ) + '</p>' +
			'<div class="torobpay-promo-notice-tags"></div>' +
			'</div>';

		displayCard.parentNode.insertBefore(notice, displayCard);
	}

	var tagsContainer = notice.querySelector('.torobpay-promo-notice-tags');
	if (!tagsContainer) {
		tagsContainer = document.createElement('div');
		tagsContainer.className = 'torobpay-promo-notice-tags';
		notice.appendChild(tagsContainer);
	}

	tagsContainer.innerHTML = '';
	disabledItems.forEach(function(label) {
		var tag = document.createElement('span');
		tag.className = 'torobpay-promo-notice-tag';
		tag.innerHTML = '<span class="dashicons dashicons-hidden"></span> ' + label + __( ' is disabled', 'torobpay-for-woocommerce' );
		tagsContainer.appendChild(tag);
	});
}

(function initTopbarBgIconPicker() {
	if (typeof wp === 'undefined' || !wp.media) {
		return;
	}

	var allowedMimes = { 'image/png': true, 'image/svg+xml': true };

	function updateTopbarBgIconCard(url) {
		var card = document.querySelector('.torobpay-topbar-bg-icon-card');
		if (!card) {
			return;
		}
		var preview = card.querySelector('.torobpay-topbar-bg-icon-preview');
		if (url) {
			card.classList.remove('is-empty');
			if (preview) {
				preview.src = url;
			}
		} else {
			card.classList.add('is-empty');
			if (preview) {
				preview.removeAttribute('src');
			}
		}
	}

	document.addEventListener('click', function (e) {
		var selectBtn = e.target.closest('.torobpay-topbar-bg-icon-select');
		if (selectBtn) {
			e.preventDefault();
			var card = selectBtn.closest('.torobpay-topbar-bg-icon-card');
			if (!card) {
				return;
			}
			var frame = wp.media({
				title: __( 'Select background logo', 'torobpay-for-woocommerce' ),
				button: { text: __( 'Use this logo', 'torobpay-for-woocommerce' ) },
				library: { type: 'image' },
				multiple: false,
			});
			frame.on('select', function () {
				var attachment = frame.state().get('selection').first().toJSON();
				if (!allowedMimes[attachment.mime]) {
					window.alert(__( 'Only PNG or SVG files are allowed.', 'torobpay-for-woocommerce' ));
					return;
				}
				var urlInput = card.querySelector('.torobpay-topbar-bg-icon-url');
				var idInput = card.querySelector('.torobpay-topbar-bg-icon-id');
				if (urlInput) {
					urlInput.value = attachment.url;
				}
				if (idInput) {
					idInput.value = attachment.id;
				}
				updateTopbarBgIconCard(attachment.url);
			});
			frame.open();
			return;
		}

		var removeBtn = e.target.closest('.torobpay-topbar-bg-icon-remove');
		if (removeBtn) {
			e.preventDefault();
			var removeCard = removeBtn.closest('.torobpay-topbar-bg-icon-card');
			if (!removeCard) {
				return;
			}
			var urlField = removeCard.querySelector('.torobpay-topbar-bg-icon-url');
			var idField = removeCard.querySelector('.torobpay-topbar-bg-icon-id');
			if (urlField) {
				urlField.value = '';
			}
			if (idField) {
				idField.value = '';
			}
			updateTopbarBgIconCard('');
		}
	});
})();
