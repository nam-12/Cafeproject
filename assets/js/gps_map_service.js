/**
 * assets/js/gps_map_service.js
 * ════════════════════════════════════════════════════════════════
 * GPS + Leaflet Map + Shipping Calculator
 * 
 * Dependencies: Leaflet.js (loaded via CDN in checkout.php)
 * 
 * Modules:
 *   - GPSService:          Xin quyền + lấy vị trí GPS
 *   - MapService:          Hiển thị bản đồ Leaflet + markers + route
 *   - ShippingCalculator:  Gọi API tính phí + cập nhật UI realtime
 * ════════════════════════════════════════════════════════════════
 */
(function () {
    'use strict';

    const API_URL = 'api_shipping.php';

    // ── Store config (sẽ được cập nhật từ API response) ─────────
    let storeCoords = null;  // { lat, lng }
    let customerCoords = null;

    // ── Format VND ──────────────────────────────────────────────
    const fmt = n => new Intl.NumberFormat('vi-VN').format(n) + 'đ';

    // ════════════════════════════════════════════════════════════
    // GPS SERVICE
    // ════════════════════════════════════════════════════════════
    const GPSService = {
        supported: !!navigator.geolocation,

        /**
         * Xin quyền và lấy vị trí hiện tại
         * @returns {Promise<{lat: number, lng: number}>}
         */
        getCurrentPosition() {
            return new Promise((resolve, reject) => {
                if (!this.supported) {
                    reject({ code: 0, message: 'Trình duyệt không hỗ trợ GPS' });
                    return;
                }
                // Kiểm tra HTTPS (GPS yêu cầu secure context)
                if (location.protocol !== 'https:' && location.hostname !== 'localhost' && location.hostname !== '127.0.0.1') {
                    reject({ code: -1, message: 'GPS yêu cầu kết nối HTTPS' });
                    return;
                }

                navigator.geolocation.getCurrentPosition(
                    (pos) => {
                        resolve({
                            lat: pos.coords.latitude,
                            lng: pos.coords.longitude,
                            accuracy: pos.coords.accuracy
                        });
                    },
                    (err) => {
                        reject({
                            code: err.code,
                            message: this.getErrorMessage(err.code)
                        });
                    },
                    {
                        enableHighAccuracy: true,
                        timeout: 15000,
                        maximumAge: 60000
                    }
                );
            });
        },

        /**
         * Trả về thông báo lỗi tiếng Việt theo mã lỗi GPS
         */
        getErrorMessage(code) {
            const messages = {
                0: 'Trình duyệt không hỗ trợ định vị GPS',
                [-1]: 'GPS yêu cầu kết nối HTTPS an toàn',
                1: 'Bạn đã từ chối quyền truy cập vị trí. Vui lòng nhập địa chỉ thủ công.',
                2: 'Không xác định được vị trí. Vui lòng kiểm tra GPS hoặc kết nối mạng.',
                3: 'Hết thời gian lấy vị trí. Vui lòng thử lại.'
            };
            return messages[code] || 'Lỗi không xác định khi lấy vị trí GPS';
        },

        /**
         * Reverse geocode tọa độ → địa chỉ (Nominatim OSM — miễn phí)
         */
        async reverseGeocode(lat, lng) {
            try {
                const url = `https://nominatim.openstreetmap.org/reverse?lat=${lat}&lon=${lng}&format=json&accept-language=vi&zoom=18`;
                const res = await fetch(url, {
                    headers: { 'User-Agent': 'CafeProject/2.0' }
                });
                const data = await res.json();
                return data.display_name || null;
            } catch (_) {
                return null;
            }
        }
    };

    // ════════════════════════════════════════════════════════════
    // MAP SERVICE (Leaflet + OpenStreetMap)
    // ════════════════════════════════════════════════════════════
    const MapService = {
        map: null,
        storeMarker: null,
        customerMarker: null,
        routeLayer: null,
        distanceBadge: null,
        mapContainerId: 'gps-map-container',

        /**
         * Khởi tạo bản đồ Leaflet
         */
        init() {
            const container = document.getElementById(this.mapContainerId);
            if (!container || !window.L) return false;

            // Nếu map đã tạo trước đó thì chỉ cần return
            if (this.map) return true;

            this.map = L.map(this.mapContainerId, {
                zoomControl: true,
                scrollWheelZoom: true,
                attributionControl: true
            }).setView([21.0285, 105.8542], 13); // Default: Hà Nội

            // Tile layer — OpenStreetMap
            L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
                attribution: '© <a href="https://www.openstreetmap.org/copyright">OSM</a>',
                maxZoom: 19
            }).addTo(this.map);

            // Fix Leaflet sizing khi container ẩn → hiện
            setTimeout(() => this.map.invalidateSize(), 300);

            return true;
        },

        /**
         * Đặt marker cửa hàng (icon coffee)
         */
        setStoreMarker(lat, lng, name) {
            if (!this.map) return;
            if (this.storeMarker) this.map.removeLayer(this.storeMarker);

            const storeIcon = L.divIcon({
                html: '<div class="map-marker-store"><i class="fas fa-store"></i></div>',
                className: 'custom-marker-icon',
                iconSize: [36, 36],
                iconAnchor: [18, 36],
                popupAnchor: [0, -36]
            });

            this.storeMarker = L.marker([lat, lng], { icon: storeIcon })
                .addTo(this.map)
                .bindPopup(`<b>☕ ${name || 'Cửa hàng'}</b><br>Điểm giao hàng`);

            storeCoords = { lat, lng };
        },

        /**
         * Đặt marker vị trí khách hàng (icon pin)
         */
        setCustomerMarker(lat, lng) {
            if (!this.map) return;
            if (this.customerMarker) this.map.removeLayer(this.customerMarker);

            const customerIcon = L.divIcon({
                html: '<div class="map-marker-customer"><i class="fas fa-map-marker-alt"></i></div>',
                className: 'custom-marker-icon',
                iconSize: [36, 36],
                iconAnchor: [18, 36],
                popupAnchor: [0, -36]
            });

            this.customerMarker = L.marker([lat, lng], { icon: customerIcon })
                .addTo(this.map)
                .bindPopup('<b>📍 Vị trí của bạn</b>');

            customerCoords = { lat, lng };
        },

        /**
         * Vẽ tuyến đường giữa store và customer
         * Dùng OSRM (miễn phí) để lấy route geometry
         */
        async drawRoute(storeLat, storeLng, custLat, custLng) {
            if (!this.map) return;
            this.clearRoute();

            try {
                // OSRM routing (miễn phí, không cần API key)
                const url = `https://router.project-osrm.org/route/v1/driving/${storeLng},${storeLat};${custLng},${custLat}?overview=full&geometries=geojson`;
                const res = await fetch(url);
                const data = await res.json();

                if (data.routes && data.routes[0]) {
                    const coords = data.routes[0].geometry.coordinates.map(c => [c[1], c[0]]);
                    this.routeLayer = L.polyline(coords, {
                        color: '#6f4e37',
                        weight: 4,
                        opacity: 0.8,
                        dashArray: '10, 6',
                        lineJoin: 'round'
                    }).addTo(this.map);
                }
            } catch (_) {
                // Fallback: vẽ đường thẳng
                this.routeLayer = L.polyline(
                    [[storeLat, storeLng], [custLat, custLng]],
                    { color: '#6f4e37', weight: 3, dashArray: '8, 4', opacity: 0.6 }
                ).addTo(this.map);
            }
        },

        /**
         * Zoom/fit map để thấy cả 2 markers
         */
        fitBounds() {
            if (!this.map || !this.storeMarker || !this.customerMarker) return;
            const group = L.featureGroup([this.storeMarker, this.customerMarker]);
            if (this.routeLayer) group.addLayer(this.routeLayer);
            this.map.fitBounds(group.getBounds().pad(0.15));
        },

        /**
         * Xóa route layer
         */
        clearRoute() {
            if (this.routeLayer && this.map) {
                this.map.removeLayer(this.routeLayer);
                this.routeLayer = null;
            }
        },

        /**
         * Hiển thị badge khoảng cách trên map
         */
        showDistanceBadge(km) {
            const badge = document.getElementById('map-distance-badge');
            if (badge) {
                badge.textContent = `📍 ${km} km`;
                badge.classList.remove('d-none');
            }
        },

        /**
         * Hiện map container (with animation)
         */
        show() {
            const container = document.getElementById(this.mapContainerId);
            const wrapper = document.getElementById('map-wrapper');
            if (wrapper) {
                wrapper.classList.remove('d-none');
                wrapper.classList.add('map-reveal');
            }
            setTimeout(() => {
                if (this.map) this.map.invalidateSize();
            }, 350);
        },

        /**
         * Cập nhật toàn bộ map: markers + route + fit bounds
         */
        async update(storeLat, storeLng, custLat, custLng, km, storeName) {
            if (!this.init()) return;
            this.show();
            this.setStoreMarker(storeLat, storeLng, storeName);
            this.setCustomerMarker(custLat, custLng);
            await this.drawRoute(storeLat, storeLng, custLat, custLng);
            this.fitBounds();
            this.showDistanceBadge(km);
        }
    };

    // ════════════════════════════════════════════════════════════
    // SHIPPING CALCULATOR — tổng hợp GPS + API + UI
    // ════════════════════════════════════════════════════════════
    const ShippingCalculator = {
        /**
         * Tính phí ship từ tọa độ GPS (nhanh, không cần geocode)
         */
        async calculateFromGPS(lat, lng) {
            const res = await fetch(API_URL, {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ action: 'calculate_gps', lat, lng })
            });
            return await res.json();
        },

        /**
         * Tính phí ship từ địa chỉ text (geocode trước)
         */
        async calculateFromAddress(address) {
            const res = await fetch(API_URL, {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ action: 'calculate', address })
            });
            return await res.json();
        }
    };

    // ════════════════════════════════════════════════════════════
    // EXPORT — cho shipping_v2.js và checkout.php sử dụng
    // ════════════════════════════════════════════════════════════
    window.GPSService = GPSService;
    window.MapService = MapService;
    window.ShippingCalculator = ShippingCalculator;

})();
