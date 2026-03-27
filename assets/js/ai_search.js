// ============================================================
// assets/js/ai_search.js
// Tìm kiếm AI — debounce, hiển thị kết quả, log click
// ============================================================

(function () {
    'use strict';

    // ── Cấu hình ────────────────────────────────────────────
    const DEBOUNCE_MS = 400;      // Đợi 400ms sau khi ngừng gõ
    const MIN_CHARS   = 2;        // Ký tự tối thiểu mới bắt đầu tìm
    const SEARCH_API  = 'search.php';

    // ── DOM references ──────────────────────────────────────
    const input   = document.getElementById('ai-search-input') || document.getElementById('searchInput');
    const results = document.getElementById('ai-search-results');
    const badge   = document.getElementById('ai-search-badge');
    const reason  = document.getElementById('ai-search-reason');

    if (!input || !results) return; // Không phải trang tìm kiếm

    // ── Debounce helper ─────────────────────────────────────
    function debounce(fn, delay) {
        let timer;
        return function (...args) {
            clearTimeout(timer);
            timer = setTimeout(() => fn.apply(this, args), delay);
        };
    }

    // ── Escape HTML chống XSS ───────────────────────────────
    function escHtml(str) {
        const d = document.createElement('div');
        d.textContent = str || '';
        return d.innerHTML;
    }

    // ── Render kết quả ──────────────────────────────────────
    function renderResults(data) {
        results.innerHTML = '';

        // Badge AI
        if (badge) {
            badge.style.display = data.ai_used ? 'inline-block' : 'none';
        }

        // Lý giải từ AI
        if (reason) {
            if (data.ai_reason && data.ai_used) {
                reason.textContent = '✨ ' + data.ai_reason;
                reason.style.display = 'block';
            } else {
                reason.style.display = 'none';
            }
        }

        // Không có kết quả
        if (!data.products || data.products.length === 0) {
            results.innerHTML = `
                <div class="ai-no-results">
                    <i class="fas fa-search-minus" style="font-size:1.5rem;margin-bottom:6px;display:block;"></i>
                    Không tìm thấy sản phẩm phù hợp với "<strong>${escHtml(input.value)}</strong>"
                </div>`;
            return;
        }

        // Render từng sản phẩm
        data.products.forEach(p => {
            const card      = document.createElement('div');
            card.className  = 'ai-product-card';
            card.dataset.id = p.id;

            // Chuẩn hóa đường dẫn ảnh: mặc định là admin uploads (trên hệ thống hiện tại)
            const imageName = (p.image || '').replace(/^\/?uploads\//i, '');
            const imgSrc = imageName
                ? `../admin/uploads/${encodeURIComponent(imageName)}`
                : '../assets/images/no-image.jpg';

            card.innerHTML = `
                <img src="${imgSrc}"
                     alt="${escHtml(p.name)}"
                     class="ai-product-img"
                     onerror="this.src='../assets/images/no-image.jpg'">
                <div class="ai-product-info">
                    <div class="ai-product-name">${escHtml(p.name)}</div>
                    <div class="ai-product-price">
                        ${Number(p.price).toLocaleString('vi-VN')} VND
                    </div>
                    <div class="ai-product-desc">
                        ${escHtml((p.description || '').substring(0, 80))}
                        ${(p.description || '').length > 80 ? '...' : ''}
                    </div>
                </div>
                <div class="ai-product-arrow">
                    <i class="fas fa-chevron-right" style="color:#9ca3af;font-size:.8rem;"></i>
                </div>`;

            card.addEventListener('click', () => {
                logClick(p.id);
                if (typeof showProductDetail === 'function') {
                    showProductDetail(p.id);
                } else {
                    window.location.href = 'product_detail.php?id=' + p.id;
                }
            });

            results.appendChild(card);
        });
    }

    // ── Gọi API ─────────────────────────────────────────────
    let currentController = null;

    async function doSearch(query) {
        if (query.length < MIN_CHARS) {
            results.style.display = 'none';
            return;
        }

        // Hủy request cũ nếu đang chạy
        if (currentController) currentController.abort();
        currentController = new AbortController();

        // Hiện loading
        results.style.display = 'block';
        results.innerHTML = `
            <div class="ai-loading">
                <div class="ai-spinner"></div>
                Đang tìm kiếm...
            </div>`;

        if (badge) badge.style.display = 'none';
        if (reason) reason.style.display = 'none';

        try {
            const resp = await fetch(
                `${SEARCH_API}?q=${encodeURIComponent(query)}`,
                { signal: currentController.signal }
            );
            if (!resp.ok) throw new Error('HTTP ' + resp.status);
            const data = await resp.json();
            renderResults(data);

        } catch (err) {
            if (err.name !== 'AbortError') {
                results.innerHTML = `
                    <div class="ai-error">
                        <i class="fas fa-exclamation-circle me-1"></i>
                        Lỗi kết nối. Vui lòng thử lại.
                    </div>`;
            }
        }
    }

    // ── Log khi người dùng nhấn vào sản phẩm ───────────────
    function logClick(productId) {
        navigator.sendBeacon(
            `${SEARCH_API}?log_click=1&product_id=${productId}&q=${encodeURIComponent(input.value)}`
        );
    }

    // ── Kết nối sự kiện ─────────────────────────────────────
    input.addEventListener('input', debounce(e => {
        const val = e.target.value.trim();
        if (val.length < MIN_CHARS) {
            results.style.display = 'none';
            if (badge)  badge.style.display  = 'none';
            if (reason) reason.style.display = 'none';
            return;
        }
        doSearch(val);
    }, DEBOUNCE_MS));

    // Ẩn kết quả khi nhấn Escape hoặc click ra ngoài
    document.addEventListener('click', e => {
        if (!input.contains(e.target) && !results.contains(e.target)) {
            results.style.display = 'none';
        }
    });

    input.addEventListener('keydown', e => {
        if (e.key === 'Escape') {
            results.style.display = 'none';
            input.blur();
        }
        // Enter → tìm ngay không chờ debounce
        if (e.key === 'Enter') {
            const val = input.value.trim();
            if (val.length >= MIN_CHARS) doSearch(val);
        }
    });

    // Hiện lại kết quả khi focus vào ô tìm kiếm
    input.addEventListener('focus', () => {
        if (results.innerHTML && input.value.trim().length >= MIN_CHARS) {
            results.style.display = 'block';
        }
    });

})();