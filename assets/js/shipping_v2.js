/**
 * assets/js/shipping_v2.js
 * Module tính phí ship nâng cấp cho checkout.php
 *
 * TÍNH NĂNG MỚI so với phiên bản cũ:
 *   1. Autocomplete địa chỉ (gợi ý khi gõ)
 *   2. Nút "Dùng vị trí hiện tại" (Geolocation API)
 *   3. Hiển thị tên provider đã dùng (Google/ORS/Ước lượng)
 *   4. Hiển thị bảng phí chi tiết
 *   5. Debounce riêng cho autocomplete (200ms) và tính phí (800ms)
 *   6. Xử lý lỗi chi tiết theo từng trường hợp
 */

(function () {
    'use strict';

    // ── Cấu hình ────────────────────────────────────────────────
    const API_URL          = 'api_shipping.php';
    const DEBOUNCE_CALC_MS = 800;   // Đợi sau khi ngừng gõ để tính phí
    const DEBOUNCE_SUGG_MS = 300;   // Đợi sau khi gõ để lấy gợi ý
    const MIN_ADDR_LEN     = 10;    // Độ dài tối thiểu để tính phí
    const MIN_SUGG_LEN     = 4;     // Độ dài tối thiểu để gợi ý

    // ── DOM refs ────────────────────────────────────────────────
    const addrInput     = document.getElementById('delivery_address');
    const hiddenAddr    = document.getElementById('hidden_shipping_address');
    const shippingBox   = document.getElementById('shipping-box');
    const shipKmEl      = document.getElementById('ship-km');
    const shipFeeEl     = document.getElementById('ship-fee');
    const shipNoteEl    = document.getElementById('ship-note');
    const shipMethodEl  = document.getElementById('ship-method');
    const shipBreakEl   = document.getElementById('ship-breakdown');
    const shipLoading   = document.getElementById('shipping-loading');
    const addrError     = document.getElementById('address-error');
    const submitWarning = document.getElementById('submit-addr-warning');
    const geoBtn        = document.getElementById('btn-use-location');
    const suggBox       = document.getElementById('address-suggestions');

    if (!addrInput) return;

    // ── Timers ──────────────────────────────────────────────────
    let timerCalc = null;
    let timerSugg = null;
    let abortCtrl = null;

    // ── OrderState (nếu đã load shipping_state.js) ──────────────
    const State = window.OrderState || null;

    // ── Format VND ──────────────────────────────────────────────
    const fmt = n => new Intl.NumberFormat('vi-VN').format(n) + 'đ';

    // ════════════════════════════════════════════════════════════
    // AUTOCOMPLETE — GỢI Ý ĐỊA CHỈ
    // ════════════════════════════════════════════════════════════
    async function fetchSuggestions(query) {
        if (query.length < MIN_SUGG_LEN) { hideSuggestions(); return; }

        try {
            const res  = await fetch(API_URL, {
                method:  'POST',
                headers: { 'Content-Type': 'application/json' },
                body:    JSON.stringify({ action: 'suggest', query }),
            });
            const data = await res.json();

            if (data.ok && data.suggestions.length > 0) {
                renderSuggestions(data.suggestions);
            } else {
                hideSuggestions();
            }
        } catch (_) { hideSuggestions(); }
    }

    function renderSuggestions(list) {
        if (!suggBox) return;
        suggBox.innerHTML = '';

        list.forEach(item => {
            const li    = document.createElement('li');
            li.className = 'suggestion-item';

            // Highlight phần khớp với text đã gõ
            const q    = addrInput.value.trim();
            const idx  = item.text.toLowerCase().indexOf(q.toLowerCase());
            if (idx >= 0) {
                li.innerHTML = escHtml(item.text.slice(0, idx))
                    + '<strong>' + escHtml(item.text.slice(idx, idx + q.length)) + '</strong>'
                    + escHtml(item.text.slice(idx + q.length));
            } else {
                li.textContent = item.text;
            }

            li.addEventListener('mousedown', (e) => {
                e.preventDefault(); // Giữ focus không rời input
                selectSuggestion(item.text);
            });

            suggBox.appendChild(li);
        });

        suggBox.style.display = 'block';
    }

    function selectSuggestion(text) {
        addrInput.value  = text;
        if (hiddenAddr) hiddenAddr.value = text;
        hideSuggestions();
        // Tính phí ngay khi chọn gợi ý
        clearTimeout(timerCalc);
        fetchShippingFee(text);
    }

    function hideSuggestions() {
        if (suggBox) suggBox.style.display = 'none';
    }

    // ════════════════════════════════════════════════════════════
    // GEOLOCATION — NÚT "DÙNG VỊ TRÍ HIỆN TẠI"
    // ════════════════════════════════════════════════════════════
    if (geoBtn) {
        if (!navigator.geolocation) {
            geoBtn.style.display = 'none';
        } else {
            geoBtn.addEventListener('click', () => {
                geoBtn.disabled     = true;
                geoBtn.innerHTML    = '<i class="fas fa-circle-notch fa-spin"></i>';
                addrError.textContent = '';
                addrError.classList.add('d-none');

                navigator.geolocation.getCurrentPosition(
                    async (pos) => {
                        const lat = pos.coords.latitude;
                        const lng = pos.coords.longitude;

                        // Reverse geocode → lấy địa chỉ từ tọa độ
                        const address = await reverseGeocode(lat, lng);
                        if (address) {
                            addrInput.value          = address;
                            if (hiddenAddr) hiddenAddr.value = address;
                            fetchShippingFee(address);
                        } else {
                            showAddrError('Không xác định được địa chỉ từ vị trí của bạn');
                        }
                        geoBtn.disabled  = false;
                        geoBtn.innerHTML = '<i class="fas fa-location-arrow"></i>';
                    },
                    (err) => {
                        const msgs = {
                            1: 'Bạn đã từ chối quyền truy cập vị trí',
                            2: 'Không xác định được vị trí',
                            3: 'Hết thời gian lấy vị trí',
                        };
                        showAddrError(msgs[err.code] || 'Lỗi lấy vị trí');
                        geoBtn.disabled  = false;
                        geoBtn.innerHTML = '<i class="fas fa-location-arrow"></i>';
                    },
                    { timeout: 10000, maximumAge: 60000 }
                );
            });
        }
    }

    // Reverse geocode tọa độ → địa chỉ (Nominatim)
    async function reverseGeocode(lat, lng) {
        try {
            const url  = `https://nominatim.openstreetmap.org/reverse?lat=${lat}&lon=${lng}&format=json&accept-language=vi`;
            const res  = await fetch(url, {
                headers: { 'User-Agent': 'CafeProject/2.0' },
            });
            const data = await res.json();
            return data.display_name || null;
        } catch (_) { return null; }
    }

    // ════════════════════════════════════════════════════════════
    // TÍNH PHÍ SHIP
    // ════════════════════════════════════════════════════════════
    async function fetchShippingFee(address) {
        if (abortCtrl) abortCtrl.abort();
        abortCtrl = new AbortController();

        showLoading(true);
        clearAddrError();

        try {
            const res = await fetch(API_URL, {
                method:  'POST',
                headers: { 'Content-Type': 'application/json' },
                body:    JSON.stringify({ action: 'calculate', address }),
                signal:  abortCtrl.signal,
            });

            if (!res.ok) {
            const text = await res.text();
            console.error('[Shipping API Non-OK]', res.status, text);
            throw new Error('HTTP ' + res.status);
        }

            const text = await res.text();
            let data;
            try {
                data = JSON.parse(text);
            } catch (_e) {
                console.error('[Shipping API Invalid JSON]', text);
                throw new Error('Invalid JSON response from Shipping API');
            }

            if (!data.ok && (data.km === undefined || data.km === null || Number(data.km) <= 0)) {
                showAddrError(data.msg || 'Không tính được phí ship');
                resetShippingUI();
                return;
            }

            // Cập nhật UI phí ship
            updateShippingUI(data);

            if (data.fallback) {
                // fallback chỉ cảnh báo, vẫn cho phép đặt hàng
                showAddrWarning(data.msg || 'Đang dùng ước lượng phí ship (không xác định chính xác địa chỉ).');
            } else {
                clearAddrError();
                addrInput.classList.remove('is-invalid-addr');
                addrInput.classList.add('is-valid-addr');
            }


            // Cập nhật OrderState nếu có
            if (State) State.setShipping(data.shipping_fee, data.km);

            addrInput.classList.remove('is-invalid-addr');
            addrInput.classList.add('is-valid-addr');

        } catch (err) {
            if (err.name === 'AbortError') return;
            showAddrError('Lỗi kết nối khi tính phí ship');
            resetShippingUI();
        } finally {
            showLoading(false);
        }
    }

    function updateShippingUI(data) {
        if (!shippingBox) return;

        shippingBox.classList.remove('d-none');

        if (shipKmEl)     shipKmEl.textContent    = data.km > 0 ? `${data.km} km` : '';
        if (shipFeeEl)    shipFeeEl.textContent    = data.fee_text || fmt(data.shipping_fee);
        if (shipNoteEl)   shipNoteEl.textContent   = data.note    || '';
        if (shipBreakEl)  shipBreakEl.textContent  = data.fee_breakdown || '';

        // Badge provider (Google / ORS / Ước lượng)
        if (shipMethodEl) {
            const method  = data.method || '';
            const isCached = data.from_cache;
            let badge = '';

            if (method.includes('google_maps')) {
                badge = `<span class="badge-provider google">
                    <i class="fas fa-map-marker-alt"></i> Google Maps
                    ${isCached ? '(cache)' : ''}
                </span>`;
            } else if (method.includes('openrouteservice')) {
                badge = `<span class="badge-provider ors">
                    <i class="fas fa-route"></i> OpenRouteService
                    ${isCached ? '(cache)' : ''}
                </span>`;
            } else {
                badge = `<span class="badge-provider osm">
                    <i class="fas fa-map"></i> Ước lượng OSM
                </span>`;
            }
            shipMethodEl.innerHTML = badge;
        }
    }

    function resetShippingUI() {
        if (shippingBox)  shippingBox.classList.add('d-none');
        if (shipKmEl)     shipKmEl.textContent   = '';
        if (shipFeeEl)    shipFeeEl.textContent   = '--';
        if (shipNoteEl)   shipNoteEl.textContent  = '';
        if (shipMethodEl) shipMethodEl.innerHTML  = '';
        if (shipBreakEl)  shipBreakEl.textContent = '';
        if (State)        State.clearShipping();
    }

    function showLoading(on) {
        if (shipLoading) shipLoading.classList.toggle('d-none', !on);
        if (on && shippingBox) shippingBox.classList.add('d-none');
    }

    function showAddrError(msg) {
        if (!addrError) return;
        addrError.textContent = msg;
        addrError.classList.remove('d-none');
        addrError.classList.remove('text-success');
        addrError.classList.add('text-danger');
        addrInput.classList.add('is-invalid-addr');
        addrInput.classList.remove('is-valid-addr');
    }

    function showAddrWarning(msg) {
        if (!addrError) return;
        addrError.textContent = msg;
        addrError.classList.remove('d-none');
        addrError.classList.remove('text-danger');
        addrError.classList.add('text-warning');
        addrInput.classList.remove('is-invalid-addr');
        addrInput.classList.add('is-valid-addr');
    }

    function clearAddrError() {
        if (addrError) {
            addrError.classList.add('d-none');
            addrError.textContent = '';
        }
    }

    function escHtml(s) {
        const d = document.createElement('div');
        d.textContent = s;
        return d.innerHTML;
    }

    // ════════════════════════════════════════════════════════════
    // EVENT LISTENERS
    // ════════════════════════════════════════════════════════════
    addrInput.addEventListener('input', function () {
        const val = this.value.trim();
        if (hiddenAddr) hiddenAddr.value = val;

        // Reset cảnh báo
        if (submitWarning) submitWarning.classList.add('d-none');

        // Autocomplete (debounce ngắn)
        clearTimeout(timerSugg);
        timerSugg = setTimeout(() => fetchSuggestions(val), DEBOUNCE_SUGG_MS);

        // Tính phí (debounce dài hơn)
        clearTimeout(timerCalc);
        if (val.length < MIN_ADDR_LEN) {
            resetShippingUI();
            clearAddrError();
            addrInput.classList.remove('is-valid-addr', 'is-invalid-addr');
            return;
        }
        timerCalc = setTimeout(() => fetchShippingFee(val), DEBOUNCE_CALC_MS);
    });

    // Ẩn gợi ý khi click ra ngoài
    document.addEventListener('click', (e) => {
        if (!addrInput.contains(e.target) && suggBox && !suggBox.contains(e.target)) {
            hideSuggestions();
        }
    });

    // Điều hướng gợi ý bằng bàn phím
    addrInput.addEventListener('keydown', (e) => {
        if (!suggBox || suggBox.style.display === 'none') return;
        const items = suggBox.querySelectorAll('.suggestion-item');
        const active = suggBox.querySelector('.suggestion-item.active');
        let idx = active ? [...items].indexOf(active) : -1;

        if (e.key === 'ArrowDown') {
            e.preventDefault();
            if (active) active.classList.remove('active');
            idx = (idx + 1) % items.length;
            items[idx].classList.add('active');
        } else if (e.key === 'ArrowUp') {
            e.preventDefault();
            if (active) active.classList.remove('active');
            idx = (idx - 1 + items.length) % items.length;
            items[idx].classList.add('active');
        } else if (e.key === 'Enter' && active) {
            e.preventDefault();
            selectSuggestion(active.textContent);
        } else if (e.key === 'Escape') {
            hideSuggestions();
        }
    });

    // Validate trước khi submit form
    const form = document.getElementById('checkoutForm');
    if (form) {
        form.addEventListener('submit', function (e) {
            const val = addrInput.value.trim();
            if (val.length < MIN_ADDR_LEN) {
                e.preventDefault();
                if (submitWarning) submitWarning.classList.remove('d-none');
                showAddrError('⚠️ Vui lòng nhập địa chỉ giao hàng đầy đủ!');
                addrInput.scrollIntoView({ behavior: 'smooth', block: 'center' });
                addrInput.focus();
            } else {
                if (hiddenAddr) hiddenAddr.value = val;
            }
        });
    }

    // Tự động tính phí nếu đã có địa chỉ prefill
    const prefill = addrInput.value.trim();
    if (prefill.length >= MIN_ADDR_LEN) {
        fetchShippingFee(prefill);
    }

    // Export để checkout.php dùng
    window.ShippingModule = { fetchShippingFee, resetShippingUI };

})();