/**
 * Searchable Dropdown Combobox Widget
 * Transforms any <select class="searchable-select"> into an interactive searchable dropdown
 * with autocomplete suggestions and keyboard navigation while retaining the native select.
 */
document.addEventListener('DOMContentLoaded', function () {
    initSearchableDropdowns();
});

function initSearchableDropdowns() {
    var selects = document.querySelectorAll('select.searchable-select, select[data-searchable="true"]');
    selects.forEach(function (select) {
        if (select.dataset.searchableInitialized === 'true') return;
        select.dataset.searchableInitialized = 'true';

        // Hide native select
        select.style.display = 'none';

        // Create combobox container
        var wrap = document.createElement('div');
        wrap.className = 'searchable-combobox-wrap';

        var input = document.createElement('input');
        input.type = 'text';
        input.className = 'searchable-combobox-input';
        input.placeholder = select.getAttribute('placeholder') || 'Ketik untuk mencari atau klik untuk memilih naskah...';
        input.autocomplete = 'off';

        var arrow = document.createElement('span');
        arrow.className = 'searchable-combobox-arrow';
        arrow.innerHTML = '&#9662;'; // ▾

        var dropdown = document.createElement('div');
        dropdown.className = 'searchable-combobox-list';

        wrap.appendChild(input);
        wrap.appendChild(arrow);
        wrap.appendChild(dropdown);
        select.parentNode.insertBefore(wrap, select);

        var activeIndex = -1;

        // Populate options from select
        function renderOptions(filterText) {
            dropdown.innerHTML = '';
            activeIndex = -1;
            var query = (filterText || '').trim().toLowerCase();
            var count = 0;

            var options = select.querySelectorAll('option');
            options.forEach(function (opt) {
                var val = opt.value;
                if (!val) return; // Skip placeholder

                var label = opt.textContent.trim();
                var sub = opt.getAttribute('data-sub') || '';
                var fullText = (label + ' ' + sub).toLowerCase();

                if (query === '' || fullText.indexOf(query) !== -1) {
                    count++;
                    var item = document.createElement('div');
                    item.className = 'searchable-combobox-option';
                    item.dataset.value = val;

                    var titleDiv = document.createElement('div');
                    titleDiv.className = 'opt-title';
                    titleDiv.textContent = label;
                    item.appendChild(titleDiv);

                    if (sub) {
                        var subDiv = document.createElement('div');
                        subDiv.className = 'opt-sub';
                        subDiv.textContent = sub;
                        item.appendChild(subDiv);
                    }

                    item.addEventListener('click', function (e) {
                        e.stopPropagation();
                        chooseOption(val, label);
                    });

                    dropdown.appendChild(item);
                }
            });

            if (count === 0) {
                var empty = document.createElement('div');
                empty.className = 'searchable-combobox-empty';
                empty.textContent = 'Tidak ada naskah yang cocok dengan pencarian.';
                dropdown.appendChild(empty);
            }
        }

        function chooseOption(val, label) {
            select.value = val;
            input.value = label;
            wrap.classList.remove('open');
            var event = new Event('change', { bubbles: true });
            select.dispatchEvent(event);
        }

        // Set initial value if an option is selected
        var selectedOpt = select.options[select.selectedIndex];
        if (selectedOpt && selectedOpt.value) {
            input.value = selectedOpt.textContent.trim();
        }

        // Event listeners
        input.addEventListener('focus', function () {
            renderOptions('');
            wrap.classList.add('open');
        });

        input.addEventListener('input', function () {
            renderOptions(input.value);
            wrap.classList.add('open');
        });

        // Keyboard navigation
        input.addEventListener('keydown', function (e) {
            var items = dropdown.querySelectorAll('.searchable-combobox-option');
            if (!wrap.classList.contains('open') && (e.key === 'ArrowDown' || e.key === 'ArrowUp')) {
                renderOptions(input.value);
                wrap.classList.add('open');
                return;
            }

            if (e.key === 'ArrowDown') {
                e.preventDefault();
                if (items.length === 0) return;
                activeIndex = (activeIndex + 1) % items.length;
                updateActiveItem(items);
            } else if (e.key === 'ArrowUp') {
                e.preventDefault();
                if (items.length === 0) return;
                activeIndex = (activeIndex - 1 + items.length) % items.length;
                updateActiveItem(items);
            } else if (e.key === 'Enter') {
                if (wrap.classList.contains('open') && activeIndex >= 0 && activeIndex < items.length) {
                    e.preventDefault();
                    var chosen = items[activeIndex];
                    chooseOption(chosen.dataset.value, chosen.querySelector('.opt-title').textContent.trim());
                }
            } else if (e.key === 'Escape') {
                wrap.classList.remove('open');
            }
        });

        function updateActiveItem(items) {
            items.forEach(function (it, idx) {
                if (idx === activeIndex) {
                    it.classList.add('active');
                    it.scrollIntoView({ block: 'nearest' });
                } else {
                    it.classList.remove('active');
                }
            });
        }

        wrap.addEventListener('click', function (e) {
            if (e.target === arrow) {
                if (wrap.classList.contains('open')) {
                    wrap.classList.remove('open');
                } else {
                    renderOptions('');
                    wrap.classList.add('open');
                    input.focus();
                }
            }
        });

        // Close when clicking outside
        document.addEventListener('click', function (e) {
            if (!wrap.contains(e.target)) {
                wrap.classList.remove('open');
                // Restore label if input was left dirty without selecting
                var cur = select.options[select.selectedIndex];
                if (cur && cur.value) {
                    input.value = cur.textContent.trim();
                } else if (!select.value) {
                    input.value = '';
                }
            }
        });
    });
}
