/**
 * ============================================================
 * Searchable Select Component
 * Sistem Penilaian 360 - DPU Bina Marga Jawa Timur
 * 
 * Mengubah setiap elemen <select> native menjadi dropdown modern
 * dengan kolom pencarian (search/filter), navigasi keyboard,
 * dukungan <optgroup>, sinkronisasi dinamis, dan kompatibilitas tema gelap/terang.
 * ============================================================
 */

(function (window, document) {
    'use strict';

    let currentOpen = null;
    const instances = new WeakMap();

    // Utility escape HTML
    function escapeHtml(str) {
        if (!str) return '';
        return String(str)
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;')
            .replace(/'/g, '&#039;');
    }

    class SearchableSelect {
        constructor(selectEl) {
            this.selectEl = selectEl;
            this.id = selectEl.id || 'sel_' + Math.random().toString(36).substring(2, 9);
            this.isOpen = false;
            this.optionsData = [];
            this.highlightedIndex = -1;

            this.initDOM();
            this.hookPropertySetter();
            this.initObserver();
            this.bindEvents();

            this.syncFromSelect();
            instances.set(selectEl, this);
            selectEl.dataset.searchableReady = '1';
        }

        initDOM() {
            // Sembunyikan elemen select asli secara aksesibel
            this.selectEl.classList.add('searchable-native-hidden');
            this.selectEl.setAttribute('tabindex', '-1');
            this.selectEl.setAttribute('aria-hidden', 'true');

            // Pembungkus utama (wrapper)
            this.wrapEl = document.createElement('div');
            this.wrapEl.className = 'searchable-select-wrap';
            
            // Salin style lebar/margin yang relevan dari select asli
            if (this.selectEl.style.maxWidth) this.wrapEl.style.maxWidth = this.selectEl.style.maxWidth;
            if (this.selectEl.style.minWidth) this.wrapEl.style.minWidth = this.selectEl.style.minWidth;
            if (this.selectEl.style.width && this.selectEl.style.width !== '100%') this.wrapEl.style.width = this.selectEl.style.width;
            if (this.selectEl.style.marginBottom) this.wrapEl.style.marginBottom = this.selectEl.style.marginBottom;
            if (this.selectEl.style.flex) this.wrapEl.style.flex = this.selectEl.style.flex;

            // Salin class pendukung (misal mb-0, filter-select, dll)
            Array.from(this.selectEl.classList).forEach(cls => {
                if (cls !== 'searchable-native-hidden' && !cls.startsWith('searchable-')) {
                    this.wrapEl.classList.add(cls);
                }
            });

            // Trigger Button
            this.triggerEl = document.createElement('div');
            this.triggerEl.className = 'searchable-select-trigger';
            this.triggerEl.setAttribute('tabindex', '0');
            this.triggerEl.setAttribute('role', 'combobox');
            this.triggerEl.setAttribute('aria-expanded', 'false');
            this.triggerEl.setAttribute('aria-haspopup', 'listbox');

            this.labelEl = document.createElement('span');
            this.labelEl.className = 'searchable-select-label';

            this.arrowEl = document.createElement('span');
            this.arrowEl.className = 'searchable-select-arrow';
            this.arrowEl.innerHTML = `<svg viewBox="0 0 24 24"><path d="M7 10l5 5 5-5z"/></svg>`;

            this.triggerEl.appendChild(this.labelEl);
            this.triggerEl.appendChild(this.arrowEl);
            this.wrapEl.appendChild(this.triggerEl);

            // Popover Dropdown (Ditempatkan di body agar tidak terpotong oleh overflow:hidden pada tabel/modal)
            this.dropdownEl = document.createElement('div');
            this.dropdownEl.className = 'searchable-select-dropdown';
            this.dropdownEl.setAttribute('role', 'listbox');

            // Header Pencarian
            this.searchWrapEl = document.createElement('div');
            this.searchWrapEl.className = 'searchable-select-search-wrap';

            const searchIcon = document.createElement('span');
            searchIcon.className = 'searchable-select-search-icon';
            searchIcon.innerHTML = `<svg viewBox="0 0 24 24"><path d="M15.5 14h-.79l-.28-.27A6.471 6.471 0 0 0 16 9.5 6.5 6.5 0 1 0 9.5 16c1.61 0 3.09-.59 4.23-1.57l.27.28v.79l5 4.99L20.49 19l-4.99-5zm-6 0C7.01 14 5 11.99 5 9.5S7.01 5 9.5 5 14 7.01 14 9.5 11.99 14 9.5 14z"/></svg>`;

            this.inputEl = document.createElement('input');
            this.inputEl.type = 'text';
            this.inputEl.className = 'searchable-select-input';
            this.inputEl.placeholder = 'Ketik untuk mencari...';
            this.inputEl.autocomplete = 'off';
            this.inputEl.spellcheck = false;

            this.clearBtnEl = document.createElement('button');
            this.clearBtnEl.type = 'button';
            this.clearBtnEl.className = 'searchable-select-clear';
            this.clearBtnEl.title = 'Hapus pencarian';
            this.clearBtnEl.innerHTML = '&times;';

            this.searchWrapEl.appendChild(searchIcon);
            this.searchWrapEl.appendChild(this.inputEl);
            this.searchWrapEl.appendChild(this.clearBtnEl);

            // Daftar Pilihan
            this.optionsListEl = document.createElement('div');
            this.optionsListEl.className = 'searchable-select-options';

            this.emptyEl = document.createElement('div');
            this.emptyEl.className = 'searchable-select-empty';
            this.emptyEl.textContent = 'Tidak ada data yang cocok';
            this.emptyEl.style.display = 'none';

            this.dropdownEl.appendChild(this.searchWrapEl);
            this.dropdownEl.appendChild(this.optionsListEl);
            this.dropdownEl.appendChild(this.emptyEl);

            // Sisipkan wrapper setelah select asli
            if (this.selectEl.parentNode) {
                this.selectEl.parentNode.insertBefore(this.wrapEl, this.selectEl.nextSibling);
            }
            document.body.appendChild(this.dropdownEl);
        }

        // Intercept properti value dan selectedIndex pada elemen select agar perubahan via JS langsung terdeteksi
        hookPropertySetter() {
            const self = this;
            const el = this.selectEl;
            
            // Simpan deskriptor asli dari prototype HTMLSelectElement
            const protoDesc = Object.getOwnPropertyDescriptor(HTMLSelectElement.prototype, 'value');
            if (protoDesc && !el._hookedValue) {
                el._hookedValue = true;
                Object.defineProperty(el, 'value', {
                    get() {
                        return protoDesc.get.call(this);
                    },
                    set(val) {
                        protoDesc.set.call(this, val);
                        self.syncSelection();
                    },
                    configurable: true
                });
            }

            const protoIdxDesc = Object.getOwnPropertyDescriptor(HTMLSelectElement.prototype, 'selectedIndex');
            if (protoIdxDesc && !el._hookedIdx) {
                el._hookedIdx = true;
                Object.defineProperty(el, 'selectedIndex', {
                    get() {
                        return protoIdxDesc.get.call(this);
                    },
                    set(val) {
                        protoIdxDesc.set.call(this, val);
                        self.syncSelection();
                    },
                    configurable: true
                });
            }

            const protoDisabledDesc = Object.getOwnPropertyDescriptor(HTMLSelectElement.prototype, 'disabled');
            if (protoDisabledDesc && !el._hookedDisabled) {
                el._hookedDisabled = true;
                Object.defineProperty(el, 'disabled', {
                    get() {
                        return protoDisabledDesc.get.call(this);
                    },
                    set(val) {
                        protoDisabledDesc.set.call(this, val);
                        self.syncDisabled();
                    },
                    configurable: true
                });
            }

            if (!el._hookedFocus) {
                el._hookedFocus = true;
                el.focus = function(options) {
                    self.triggerEl.focus(options);
                };
            }

            // Hubungkan label[for="selectId"] agar fokus dan membuka dropdown saat diklik
            if (el.id) {
                const labels = document.querySelectorAll(`label[for="${el.id}"]`);
                labels.forEach(lbl => {
                    lbl.addEventListener('click', (e) => {
                        e.preventDefault();
                        self.triggerEl.focus();
                        self.open();
                    });
                });
            }
        }

        // Pantau mutasi elemen select (penambahan option, disabled, perubahan atribut)
        initObserver() {
            this.observer = new MutationObserver((mutations) => {
                let shouldSyncOptions = false;
                let shouldSyncDisabled = false;

                for (const m of mutations) {
                    if (m.type === 'childList') {
                        shouldSyncOptions = true;
                    } else if (m.type === 'attributes') {
                        if (m.attributeName === 'disabled') shouldSyncDisabled = true;
                        if (m.attributeName === 'class' && elContainsInvalid(this.selectEl)) {
                            this.triggerEl.classList.add('is-invalid');
                        }
                    }
                }

                if (shouldSyncOptions) this.syncFromSelect();
                if (shouldSyncDisabled) this.syncDisabled();
            });

            this.observer.observe(this.selectEl, {
                childList: true,
                subtree: true,
                attributes: true,
                attributeFilter: ['disabled', 'class', 'style', 'required']
            });
        }

        bindEvents() {
            // Klik pada trigger
            this.triggerEl.addEventListener('click', (e) => {
                e.preventDefault();
                e.stopPropagation();
                if (this.selectEl.disabled) return;
                this.toggle();
            });

            // Navigasi keyboard pada trigger
            this.triggerEl.addEventListener('keydown', (e) => {
                if (this.selectEl.disabled) return;
                if (e.key === 'Enter' || e.key === ' ' || e.key === 'ArrowDown') {
                    e.preventDefault();
                    this.open();
                }
            });

            // Input pencarian
            this.inputEl.addEventListener('input', () => {
                this.filterOptions(this.inputEl.value);
            });

            this.inputEl.addEventListener('keydown', (e) => {
                this.handleSearchKeydown(e);
            });

            // Tombol hapus pencarian
            this.clearBtnEl.addEventListener('click', (e) => {
                e.stopPropagation();
                this.inputEl.value = '';
                this.filterOptions('');
                this.inputEl.focus();
            });

            // Delegasi klik pada opsi
            this.optionsListEl.addEventListener('click', (e) => {
                const optEl = e.target.closest('.searchable-select-option');
                if (!optEl || optEl.classList.contains('is-disabled')) return;
                const val = optEl.dataset.value;
                this.selectValue(val);
                this.close();
                this.triggerEl.focus();
            });

            // Dengarkan event form reset jika berada di dalam form
            const form = this.selectEl.closest('form');
            if (form) {
                form.addEventListener('reset', () => {
                    setTimeout(() => this.syncSelection(), 20);
                });
            }

            // Tangani event native change (misal dipicu programmatically)
            this.selectEl.addEventListener('change', () => {
                this.syncSelection();
                this.triggerEl.classList.remove('is-invalid');
            });

            // Tangani form validation jika select required tidak terisi
            this.selectEl.addEventListener('invalid', () => {
                this.triggerEl.classList.add('is-invalid');
            });
        }

        syncDisabled() {
            const isDisabled = this.selectEl.disabled;
            this.triggerEl.classList.toggle('is-disabled', isDisabled);
            this.triggerEl.setAttribute('aria-disabled', isDisabled ? 'true' : 'false');
            if (isDisabled && this.isOpen) this.close();
        }

        syncFromSelect() {
            this.syncDisabled();

            if (this.selectEl.style.display === 'none' || this.selectEl.classList.contains('hidden')) {
                this.wrapEl.style.display = 'none';
            } else if (this.wrapEl.style.display === 'none') {
                this.wrapEl.style.display = '';
            }

            this.optionsData = [];

            // Baca seluruh child: optgroup dan option
            const children = Array.from(this.selectEl.children);
            let optIndex = 0;

            children.forEach(child => {
                if (child.tagName === 'OPTGROUP') {
                    const groupLabel = child.label || child.getAttribute('label') || '';
                    const groupOptions = Array.from(child.children).filter(c => c.tagName === 'OPTION');
                    
                    const groupItem = {
                        isGroup: true,
                        label: groupLabel,
                        options: []
                    };

                    groupOptions.forEach(opt => {
                        const item = {
                            isGroup: false,
                            text: opt.textContent.trim(),
                            value: opt.value,
                            disabled: opt.disabled,
                            index: optIndex++,
                            groupLabel: groupLabel,
                            selected: opt.selected,
                            originalEl: opt
                        };
                        groupItem.options.push(item);
                    });

                    this.optionsData.push(groupItem);
                } else if (child.tagName === 'OPTION') {
                    const item = {
                        isGroup: false,
                        text: child.textContent.trim(),
                        value: child.value,
                        disabled: child.disabled,
                        index: optIndex++,
                        groupLabel: null,
                        selected: child.selected,
                        originalEl: child
                    };
                    this.optionsData.push(item);
                }
            });

            this.renderOptionsList();
            this.syncSelection();
        }

        renderOptionsList() {
            this.optionsListEl.innerHTML = '';
            let count = 0;

            this.optionsData.forEach(item => {
                if (item.isGroup) {
                    const groupHeader = document.createElement('div');
                    groupHeader.className = 'searchable-select-optgroup';
                    groupHeader.textContent = item.label;
                    groupHeader.dataset.group = item.label;
                    this.optionsListEl.appendChild(groupHeader);

                    item.options.forEach(opt => {
                        const optEl = this.createOptionElement(opt);
                        this.optionsListEl.appendChild(optEl);
                        count++;
                    });
                } else {
                    const optEl = this.createOptionElement(item);
                    this.optionsListEl.appendChild(optEl);
                    count++;
                }
            });

            // Jika options kosong sama sekali
            if (count === 0) {
                this.emptyEl.textContent = 'Tidak ada pilihan';
                this.emptyEl.style.display = 'block';
            } else {
                this.emptyEl.style.display = 'none';
            }
        }

        createOptionElement(opt) {
            const el = document.createElement('div');
            el.className = 'searchable-select-option';
            el.dataset.value = opt.value;
            el.dataset.index = opt.index;
            if (opt.groupLabel) el.dataset.group = opt.groupLabel;
            if (opt.disabled) el.classList.add('is-disabled');

            const textSpan = document.createElement('span');
            textSpan.className = 'searchable-select-option-text';
            textSpan.textContent = opt.text;

            const checkSpan = document.createElement('span');
            checkSpan.className = 'searchable-select-check';
            checkSpan.innerHTML = `<svg viewBox="0 0 24 24"><path d="M9 16.17L4.83 12l-1.42 1.41L9 19 21 7l-1.41-1.41z"/></svg>`;

            el.appendChild(textSpan);
            el.appendChild(checkSpan);
            return el;
        }

        syncSelection() {
            const selectedOpt = this.selectEl.options[this.selectEl.selectedIndex];
            const currentText = selectedOpt ? selectedOpt.textContent.trim() : '';
            const currentValue = selectedOpt ? selectedOpt.value : '';

            // Perbarui label trigger
            if (!selectedOpt || (currentValue === '' && (!currentText || currentText.startsWith('---') || currentText.startsWith('Pilih')))) {
                this.labelEl.textContent = currentText || 'Pilih opsi...';
                this.labelEl.classList.add('searchable-select-placeholder');
            } else {
                this.labelEl.textContent = currentText;
                this.labelEl.classList.remove('searchable-select-placeholder');
            }

            // Perbarui active state pada options list
            const allOpts = this.optionsListEl.querySelectorAll('.searchable-select-option');
            allOpts.forEach(el => {
                const isMatch = el.dataset.value === currentValue;
                el.classList.toggle('is-selected', isMatch);
                el.setAttribute('aria-selected', isMatch ? 'true' : 'false');
            });
        }

        selectValue(value) {
            if (this.selectEl.value === value) return;
            this.selectEl.value = value;
            this.syncSelection();

            // Picu native events
            this.selectEl.dispatchEvent(new Event('input', { bubbles: true }));
            this.selectEl.dispatchEvent(new Event('change', { bubbles: true }));
        }

        filterOptions(query) {
            const q = query.trim().toLowerCase();
            this.clearBtnEl.classList.toggle('is-visible', q.length > 0);

            const allOptEls = Array.from(this.optionsListEl.querySelectorAll('.searchable-select-option'));
            const allGroupEls = Array.from(this.optionsListEl.querySelectorAll('.searchable-select-optgroup'));

            let visibleCount = 0;
            const visibleGroups = new Set();

            allOptEls.forEach(el => {
                const textEl = el.querySelector('.searchable-select-option-text');
                const rawText = textEl.textContent;
                const lowerText = rawText.toLowerCase();
                const groupName = el.dataset.group || '';
                const lowerGroup = groupName.toLowerCase();

                let isMatch = false;
                if (!q) {
                    isMatch = true;
                } else {
                    // Match text atau group
                    const tokens = q.split(/\s+/).filter(Boolean);
                    isMatch = tokens.every(tok => lowerText.includes(tok) || lowerGroup.includes(tok));
                }

                if (isMatch) {
                    el.style.display = 'flex';
                    visibleCount++;
                    if (groupName) visibleGroups.add(groupName);

                    // Berikan highlight kata kunci jika ada pencarian
                    if (q) {
                        textEl.innerHTML = highlightMatch(rawText, q);
                    } else {
                        textEl.textContent = rawText;
                    }
                } else {
                    el.style.display = 'none';
                    textEl.textContent = rawText;
                }
            });

            // Tampilkan/sembunyikan optgroup header berdasarkan ketersediaan child yang cocok
            allGroupEls.forEach(g => {
                const gName = g.dataset.group;
                if (!q) {
                    g.style.display = 'block';
                } else {
                    g.style.display = visibleGroups.has(gName) ? 'block' : 'none';
                }
            });

            this.emptyEl.style.display = visibleCount === 0 ? 'block' : 'none';
            this.highlightFirstVisible();
        }

        highlightFirstVisible() {
            const visible = Array.from(this.optionsListEl.querySelectorAll('.searchable-select-option'))
                .filter(el => el.style.display !== 'none' && !el.classList.contains('is-disabled'));

            this.optionsListEl.querySelectorAll('.is-highlighted').forEach(el => el.classList.remove('is-highlighted'));
            this.highlightedIndex = -1;

            if (visible.length > 0) {
                // Utamakan yang sedang terseleksi jika terlihat
                const selected = visible.find(el => el.classList.contains('is-selected'));
                const target = selected || visible[0];
                target.classList.add('is-highlighted');
                this.highlightedIndex = visible.indexOf(target);
            }
        }

        handleSearchKeydown(e) {
            const visible = Array.from(this.optionsListEl.querySelectorAll('.searchable-select-option'))
                .filter(el => el.style.display !== 'none' && !el.classList.contains('is-disabled'));

            if (visible.length === 0) {
                if (e.key === 'Escape') {
                    this.close();
                    this.triggerEl.focus();
                }
                return;
            }

            if (e.key === 'ArrowDown') {
                e.preventDefault();
                this.highlightedIndex = (this.highlightedIndex + 1) % visible.length;
                this.updateHighlight(visible);
            } else if (e.key === 'ArrowUp') {
                e.preventDefault();
                this.highlightedIndex = (this.highlightedIndex - 1 + visible.length) % visible.length;
                this.updateHighlight(visible);
            } else if (e.key === 'Enter') {
                e.preventDefault();
                if (this.highlightedIndex >= 0 && this.highlightedIndex < visible.length) {
                    const targetEl = visible[this.highlightedIndex];
                    this.selectValue(targetEl.dataset.value);
                    this.close();
                    this.triggerEl.focus();
                }
            } else if (e.key === 'Escape') {
                e.preventDefault();
                this.close();
                this.triggerEl.focus();
            } else if (e.key === 'Tab') {
                this.close();
            }
        }

        updateHighlight(visibleList) {
            visibleList.forEach((el, i) => {
                el.classList.toggle('is-highlighted', i === this.highlightedIndex);
                if (i === this.highlightedIndex) {
                    el.scrollIntoView({ block: 'nearest' });
                }
            });
        }

        open() {
            if (this.isOpen || this.selectEl.disabled) return;
            if (currentOpen && currentOpen !== this) {
                currentOpen.close();
            }

            this.isOpen = true;
            currentOpen = this;

            this.triggerEl.classList.add('is-open');
            this.triggerEl.setAttribute('aria-expanded', 'true');
            this.dropdownEl.classList.add('is-open');

            this.inputEl.value = '';
            this.filterOptions('');
            this.updatePosition();

            // Focus ke input search
            setTimeout(() => {
                this.inputEl.focus();
            }, 30);
        }

        close() {
            if (!this.isOpen) return;
            this.isOpen = false;
            if (currentOpen === this) currentOpen = null;

            this.triggerEl.classList.remove('is-open');
            this.triggerEl.setAttribute('aria-expanded', 'false');
            this.dropdownEl.classList.remove('is-open');
        }

        toggle() {
            if (this.isOpen) this.close();
            else this.open();
        }

        updatePosition() {
            if (!this.isOpen) return;

            const rect = this.triggerEl.getBoundingClientRect();
            const popoverMaxH = 290;
            const viewH = window.innerHeight;
            const viewW = window.innerWidth;

            const spaceBelow = viewH - rect.bottom;
            const spaceAbove = rect.top;

            const width = Math.max(rect.width, 220);
            this.dropdownEl.style.width = width + 'px';

            // Koordinat horizontal
            let left = rect.left;
            if (left + width > viewW - 12) {
                left = Math.max(12, viewW - width - 12);
            }
            this.dropdownEl.style.left = left + 'px';

            // Koordinat vertikal (buka ke atas jika ruang di bawah tidak cukup)
            if (spaceBelow < popoverMaxH && spaceAbove > spaceBelow) {
                this.dropdownEl.style.top = 'auto';
                this.dropdownEl.style.bottom = (viewH - rect.top + 4) + 'px';
            } else {
                this.dropdownEl.style.bottom = 'auto';
                this.dropdownEl.style.top = (rect.bottom + 4) + 'px';
            }
        }

        destroy() {
            if (this.observer) this.observer.disconnect();
            this.close();
            if (this.dropdownEl && this.dropdownEl.parentNode) {
                this.dropdownEl.parentNode.removeChild(this.dropdownEl);
            }
            if (this.wrapEl && this.wrapEl.parentNode) {
                this.wrapEl.parentNode.removeChild(this.wrapEl);
            }
            this.selectEl.classList.remove('searchable-native-hidden');
            this.selectEl.removeAttribute('tabindex');
            this.selectEl.removeAttribute('aria-hidden');
            delete this.selectEl.dataset.searchableReady;
            instances.delete(this.selectEl);
        }
    }

    // Helper penyorot teks yang cocok
    function highlightMatch(text, query) {
        if (!query) return escapeHtml(text);
        const tokens = query.trim().toLowerCase().split(/\s+/).filter(Boolean);
        if (tokens.length === 0) return escapeHtml(text);

        const pattern = '(' + tokens.map(t => t.replace(/[.*+?^${}()|[\]\\]/g, '\\$&')).join('|') + ')';
        const regex = new RegExp(pattern, 'gi');

        return escapeHtml(text).replace(regex, '<span class="searchable-select-match">$1</span>');
    }

    function elContainsInvalid(selectEl) {
        return selectEl.classList.contains('is-invalid') || selectEl.matches(':invalid');
    }

    // Global listener untuk klik di luar dropdown
    document.addEventListener('click', function (e) {
        if (!currentOpen) return;
        const inWrap = currentOpen.wrapEl.contains(e.target);
        const inDropdown = currentOpen.dropdownEl.contains(e.target);
        if (!inWrap && !inDropdown) {
            currentOpen.close();
        }
    });

    // Global listener untuk resize atau scroll (update posisi agar menempel presisi)
    window.addEventListener('resize', function () {
        if (currentOpen) currentOpen.updatePosition();
    }, { passive: true });

    window.addEventListener('scroll', function (e) {
        if (!currentOpen) return;
        if (currentOpen.dropdownEl.contains(e.target)) return;
        currentOpen.updatePosition();
    }, { passive: true, capture: true });

    // Inisialisasi seluruh elemen select
    function initAll(root = document) {
        const selects = root.querySelectorAll('select:not([data-searchable-ignore]):not([data-searchable-ready])');
        selects.forEach(sel => {
            new SearchableSelect(sel);
        });
    }

    function initOne(selectEl) {
        if (!selectEl || selectEl.dataset.searchableReady) return instances.get(selectEl);
        return new SearchableSelect(selectEl);
    }

    function refreshOne(selectEl) {
        const inst = instances.get(selectEl);
        if (inst) inst.syncFromSelect();
    }

    function refreshAll() {
        document.querySelectorAll('select[data-searchable-ready]').forEach(sel => {
            refreshOne(sel);
        });
    }

    // Observasi DOM dinamis (misal elemen baru yang dimuat via AJAX / modal)
    const domObserver = new MutationObserver(mutations => {
        let hasNewSelect = false;
        for (const m of mutations) {
            for (const node of m.addedNodes) {
                if (node.nodeType === 1) {
                    if (node.tagName === 'SELECT' && !node.dataset.searchableReady && !node.dataset.searchableIgnore) {
                        hasNewSelect = true;
                    } else if (node.querySelector && node.querySelector('select:not([data-searchable-ready]):not([data-searchable-ignore])')) {
                        hasNewSelect = true;
                    }
                }
            }
        }
        if (hasNewSelect) {
            initAll();
        }
    });

    if (document.body) {
        domObserver.observe(document.body, { childList: true, subtree: true });
    } else {
        document.addEventListener('DOMContentLoaded', () => {
            domObserver.observe(document.body, { childList: true, subtree: true });
        });
    }

    // Auto-init saat DOM ready
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', () => initAll());
    } else {
        initAll();
    }

    // Ekspor API global
    window.SearchableSelect = {
        initAll,
        init: initOne,
        refresh: refreshOne,
        refreshAll,
        getInstance: (el) => instances.get(el)
    };

})(window, document);
