/**
 * assets/js/shipping_v2.js
 * ════════════════════════════════════════════════════════════════
 * Module tính phí ship nâng cấp cho checkout.php
 * Tích hợp GPS + Leaflet Map + Realtime shipping fee
 *
 * TÍNH NĂNG:
 *   1. Autocomplete địa chỉ (gợi ý khi gõ)
 *   2. Nút "Lấy vị trí hiện tại" (GPS → Map → tính phí tự động)
 *   3. Hiển thị bản đồ với 2 markers + route
 *   4. Phí ship realtime khi thay đổi địa chỉ
 *   5. Bảng phí chi tiết + badge provider
 *   6. Xử lý lỗi GPS chi tiết
 *
 * Dependencies: gps_map_service.js (GPSService, MapService)
 * ════════════════════════════════════════════════════════════════
 */
(function () {
    'use strict';

    // ── Cấu hình ────────────────────────────────────────────────
    const API_URL          = 'api_shipping.php';
    const DEBOUNCE_CALC_MS = 800;
    const DEBOUNCE_SUGG_MS = 300;
    const MIN_ADDR_LEN     = 10;
    const MIN_SUGG_LEN     = 4;
    const STORE_NAME       = 'Coffee House';

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
    const suggBox       = document.getElementById('address-suggestions');
    const gpsBtn        = document.getElementById('btn-gps-locate');
    const gpsStatus     = document.getElementById('gps-status');
    const hiddenLat     = document.getElementById('input_customer_lat');
    const hiddenLng     = document.getElementById('input_customer_lng');

    if (!addrInput) return;

    // ── Timers ──────────────────────────────────────────────────
    let timerCalc = null;
    let timerSugg = null;
    let abortCtrl = null;

    // ── OrderState (nếu đã load) ────────────────────────────────
    const State = window.OrderState || null;

    // ── Format VND ──────────────────────────────────────────────
    const fmt = n => new Intl.NumberFormat('vi-VN').format(n) + 'đ';

    // ════════════════════════════════════════════════════════════
    // GPS BUTTON — LẤY VỊ TRÍ HIỆN TẠI
    // ════════════════════════════════════════════════════════════
    if (gpsBtn && window.GPSService) {
        if (!GPSService.supported) {
            gpsBtn.style.display = 'none';
        } else {
            gpsBtn.addEventListener('click', handleGPSClick);
        }
    }

    async function handleGPSClick() {
        gpsBtn.disabled = true;
        gpsBtn.innerHTML = '<i class="fas fa-circle-notch fa-spin"></i> Đang lấy vị trí...';
        setGPSStatus('loading', 'Đang xác định vị trí GPS của bạn...');
        clearAddrError();

        try {
            // 1. Lấy tọa độ GPS
            const pos = await GPSService.getCurrentPosition();
            setGPSStatus('success', `Đã xác định vị trí (±${Math.round(pos.accuracy)}m)`);

            // 2. Lưu tọa độ vào hidden inputs
            if (hiddenLat) hiddenLat.value = pos.lat;
            if (hiddenLng) hiddenLng.value = pos.lng;

            // 3. Reverse geocode → điền địa chỉ
            const address = await GPSService.reverseGeocode(pos.lat, pos.lng);
            if (address) {
                addrInput.value = address;
                if (hiddenAddr) hiddenAddr.value = address;
            }

            // 4. Tính phí ship trực tiếp từ GPS (nhanh hơn vì skip geocode)
            showLoading(true);
            const data = await ShippingCalculator.calculateFromGPS(pos.lat, pos.lng);

            if (data.ok) {
                updateShippingUI(data);
                // 5. Hiển thị bản đồ
                if (window.MapService && data.store_lat && data.store_lng) {
                    await MapService.update(
                        data.store_lat, data.store_lng,
                        pos.lat, pos.lng,
                        data.km, STORE_NAME
                    );
                }
                if (State) State.setShipping(data.shipping_fee, data.km);
                addrInput.classList.remove('is-invalid-addr');
                addrInput.classList.add('is-valid-addr');
            } else {
                showAddrError(data.msg || 'Không tính được phí ship từ GPS');
                resetShippingUI();
            }
        } catch (err) {
            setGPSStatus('error', err.message || 'Lỗi GPS. Vui lòng nhập địa chỉ thủ công.');
            showAddrError(err.message);
        } finally {
            showLoading(false);
            gpsBtn.disabled = false;
            gpsBtn.innerHTML = '<i class="fas fa-crosshairs"></i> Lấy vị trí hiện tại';
        }
    }

    function setGPSStatus(type, msg) {
        if (!gpsStatus) return;
        gpsStatus.className = 'gps-status gps-' + type;
        const icons = { loading: 'fa-circle-notch fa-spin', success: 'fa-check-circle', error: 'fa-exclamation-triangle' };
        gpsStatus.innerHTML = `<i class="fas ${icons[type] || ''}"></i> ${msg}`;
        gpsStatus.classList.remove('d-none');
        // Auto-hide success/error after 8s
        if (type !== 'loading') {
            setTimeout(() => gpsStatus.classList.add('d-none'), 8000);
        }
    }

    // ════════════════════════════════════════════════════════════
    // AUTOCOMPLETE — GỢI Ý ĐỊA CHỈ
    // ════════════════════════════════════════════════════════════
    async function fetchSuggestions(query) {
        if (query.length < MIN_SUGG_LEN) { hideSuggestions(); return; }
        try {
            const res  = await fetch(API_URL, {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ action: 'suggest', query }),
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
            const li = document.createElement('li');
            li.className = 'suggestion-item';
            const q = addrInput.value.trim();
            const idx = item.text.toLowerCase().indexOf(q.toLowerCase());
            if (idx >= 0) {
                li.innerHTML = escHtml(item.text.slice(0, idx))
                    + '<strong>' + escHtml(item.text.slice(idx, idx + q.length)) + '</strong>'
                    + escHtml(item.text.slice(idx + q.length));
            } else {
                li.textContent = item.text;
            }
            li.addEventListener('mousedown', (e) => {
                e.preventDefault();
                selectSuggestion(item.text);
            });
            suggBox.appendChild(li);
        });
        suggBox.style.display = 'block';
    }

    function selectSuggestion(text) {
        addrInput.value = text;
        if (hiddenAddr) hiddenAddr.value = text;
        hideSuggestions();
        clearTimeout(timerCalc);
        fetchShippingFee(text);
    }

    function hideSuggestions() {
        if (suggBox) suggBox.style.display = 'none';
    }

    // ════════════════════════════════════════════════════════════
    // TÍNH PHÍ SHIP (từ địa chỉ text)
    // ════════════════════════════════════════════════════════════
    async function fetchShippingFee(address) {
        if (abortCtrl) abortCtrl.abort();
        abortCtrl = new AbortController();

        showLoading(true);
        clearAddrError();

        try {
            const res = await fetch(API_URL, {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ action: 'calculate', address }),
                signal: abortCtrl.signal,
            });

            if (!res.ok) throw new Error('HTTP ' + res.status);

            const text = await res.text();
            let data;
            try { data = JSON.parse(text); }
            catch (_e) { throw new Error('Invalid JSON'); }

            if (!data.ok && (data.km === undefined || data.km === null || Number(data.km) <= 0)) {
                showAddrError(data.msg || 'Không tính được phí ship');
                resetShippingUI();
                return;
            }

            updateShippingUI(data);

            // Hiển thị bản đồ nếu có tọa độ
            if (window.MapService && data.store_lat && data.customer_lat) {
                await MapService.update(
                    data.store_lat, data.store_lng,
                    data.customer_lat, data.customer_lng,
                    data.km, STORE_NAME
                );
            }

            if (data.fallback) {
                showAddrWarning(data.msg || 'Đang dùng ước lượng phí ship.');
            } else {
                clearAddrError();
                addrInput.classList.remove('is-invalid-addr');
                addrInput.classList.add('is-valid-addr');
            }

            if (State) State.setShipping(data.shipping_fee, data.km);

        } catch (err) {
            if (err.name === 'AbortError') return;
            showAddrError('Lỗi kết nối khi tính phí ship');
            resetShippingUI();
        } finally {
            showLoading(false);
        }
    }

    // ════════════════════════════════════════════════════════════
    // UI HELPERS
    // ════════════════════════════════════════════════════════════
    function updateShippingUI(data) {
        if (!shippingBox) return;
        shippingBox.classList.remove('d-none');

        if (shipKmEl)    shipKmEl.textContent   = data.km > 0 ? `${data.km} km` : '';
        if (shipFeeEl)   shipFeeEl.textContent   = data.fee_text || fmt(data.shipping_fee);
        if (shipNoteEl)  shipNoteEl.textContent   = data.note || '';
        if (shipBreakEl) shipBreakEl.textContent  = data.fee_breakdown || '';

        // Badge provider
        if (shipMethodEl) {
            const m = data.method || '';
            let badge = '';
            if (m.includes('graphhopper') || m.includes('gps_graphhopper')) {
                badge = `<span class="badge-provider google"><i class="fas fa-route"></i> Đường thực tế${data.from_cache ? ' (cache)' : ''}</span>`;
            } else if (m.includes('gps_haversine')) {
                badge = `<span class="badge-provider ors"><i class="fas fa-satellite"></i> GPS ước lượng</span>`;
            } else if (m.includes('haversine')) {
                badge = `<span class="badge-provider osm"><i class="fas fa-map"></i> Ước lượng OSM</span>`;
            } else {
                badge = `<span class="badge-provider osm"><i class="fas fa-map"></i> ${m}</span>`;
            }
            shipMethodEl.innerHTML = badge;
        }

        // Update summary panel
        const summaryVal = document.getElementById('shipping-summary-val');
        if (summaryVal) {
            summaryVal.innerHTML = `<span style="color:var(--primary-color);font-weight:700">${fmt(data.shipping_fee)}</span>`;
        }

        // Update hidden inputs
        const inputFee = document.getElementById('input_client_shipping_fee');
        const inputKm  = document.getElementById('input_client_distance');
        if (inputFee) inputFee.value = data.shipping_fee;
        if (inputKm)  inputKm.value  = data.km;

        // Update total
        updateTotalDisplay(data.shipping_fee);
    }

    function resetShippingUI() {
        if (shippingBox) shippingBox.classList.add('d-none');
        if (shipKmEl)    shipKmEl.textContent   = '';
        if (shipFeeEl)   shipFeeEl.textContent   = '--';
        if (shipNoteEl)  shipNoteEl.textContent  = '';
        if (shipMethodEl) shipMethodEl.innerHTML  = '';
        if (shipBreakEl) shipBreakEl.textContent = '';
        if (State)       State.clearShipping();

        const summaryVal = document.getElementById('shipping-summary-val');
        if (summaryVal) summaryVal.innerHTML = '<span class="text-muted small fst-italic">Nhập địa chỉ để tính</span>';

        const inputFee = document.getElementById('input_client_shipping_fee');
        const inputKm  = document.getElementById('input_client_distance');
        if (inputFee) inputFee.value = 0;
        if (inputKm)  inputKm.value  = 0;

        updateTotalDisplay(0);
    }

    function updateTotalDisplay(shipFee) {
        const subtotalEl = document.getElementById('input_final_total');
        const displayEl  = document.getElementById('final_total_display');
        if (!subtotalEl || !displayEl) return;

        const subtotal = window.cartSubtotal || 0;
        const discount = window._currentDiscount || 0;
        const total = Math.max(0, subtotal + shipFee - discount);
        subtotalEl.value = total;
        displayEl.textContent = fmt(total);
        window.shippingFee = shipFee;
    }

    function showLoading(on) {
        if (shipLoading) shipLoading.classList.toggle('d-none', !on);
        if (on && shippingBox) shippingBox.classList.add('d-none');
    }

    function showAddrError(msg) {
        if (!addrError) return;
        addrError.textContent = msg;
        addrError.classList.remove('d-none', 'text-success', 'text-warning');
        addrError.classList.add('text-danger');
        addrInput.classList.add('is-invalid-addr');
        addrInput.classList.remove('is-valid-addr');
    }

    function showAddrWarning(msg) {
        if (!addrError) return;
        addrError.textContent = msg;
        addrError.classList.remove('d-none', 'text-danger');
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
        if (submitWarning) submitWarning.classList.add('d-none');

        clearTimeout(timerSugg);
        timerSugg = setTimeout(() => fetchSuggestions(val), DEBOUNCE_SUGG_MS);

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

    // Validate trước khi submit
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

    // Coupon event listener
    document.addEventListener('couponApplied', function(e) {
        window._currentDiscount = e.detail?.discount || 0;
        const shipFee = window.shippingFee || 0;
        updateTotalDisplay(shipFee);
    });

    // Tự động tính phí nếu đã có địa chỉ prefill
    const prefill = addrInput.value.trim();
    if (prefill.length >= MIN_ADDR_LEN) {
        fetchShippingFee(prefill);
    }

    // Export
    window.ShippingModule = { fetchShippingFee, resetShippingUI };
})();