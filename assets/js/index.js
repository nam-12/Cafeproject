// Khởi tạo Toast với thời gian tự động ẩn (delay) 3000ms (3 giây)
const cartToastEl = document.getElementById('cartToast');
let cartToastInstance = null;

if (cartToastEl) {
    cartToastInstance = new bootstrap.Toast(cartToastEl, {
        autohide: true,
        delay: 3000 // Tự động ẩn sau 3 giây
    });
}

function addToCart(id, name, price, stock) {
    fetch('add_to_cart.php', {
        method: 'POST',
        headers: {
            'Content-Type': 'application/x-www-form-urlencoded',
        },
        body: `product_id=${id}&name=${encodeURIComponent(name)}&price=${price}&stock=${stock}`
    })
        .then(response => response.json())
        .then(data => {
            if (data.success) {
                const badge = document.querySelector('.cart-badge-premium');
                if (badge) {
                    badge.textContent = data.cart_count;
                } else if (data.cart_count > 0) {
                    const cartBtn = document.querySelector('.cart-btn-premium');
                    const badgeHTML = `<span class="cart-badge-premium">${data.cart_count}</span>`;
                    cartBtn.insertAdjacentHTML('beforeend', badgeHTML);
                }

                // Sử dụng instance đã khởi tạo
                if (cartToastInstance) {
                    cartToastInstance.show();
                }
            } else {
                alert(data.message || 'Có lỗi xảy ra!');
            }
        })
        .catch(error => {
            console.error('Error:', error);
            alert('Có lỗi xảy ra khi thêm vào giỏ hàng!');
        });
}

// ===================================================
// HÀM XỬ LÝ LỌC, TÌM KIẾM VÀ PHÂN TRANG AJAX
// ===================================================

const productListing = document.getElementById('productListing');
const categoryLinks = document.querySelectorAll('.category-item-premium');
const searchInput = document.getElementById('searchInput');

// Sử dụng biến toàn cục đã được truyền từ index.php
let currentCategoryId = typeof INITIAL_CATEGORY_ID !== 'undefined' ? INITIAL_CATEGORY_ID : '';

/**
 * Hàm lấy sản phẩm từ server (AJAX) — mode replace (dùng khi filter/search)
 */
function fetchProducts(searchQuery, categoryId, page = 1) {
    if (!productListing) return;
    
    // Reset load more state
    loadMorePage = 1;
    
    productListing.innerHTML = '<div class="text-center p-5"><i class="fas fa-spinner fa-spin fa-3x" style="color: #6f4e37;"></i><p class="mt-3">Đang tải sản phẩm...</p></div>';

    let url = `fetch_products.php?search=${encodeURIComponent(searchQuery)}&page=${page}&mode=replace`;
    if (categoryId) {
        url += `&category=${categoryId}`;
    }

    fetch(url)
        .then(response => response.text())
        .then(html => {
            productListing.innerHTML = html;

            // Cập nhật dữ liệu JS
            const dataElement = document.getElementById('ajax_product_data');
            if (dataElement) {
                try {
                    currentProducts = JSON.parse(dataElement.value);
                } catch (e) {
                    console.error("Lỗi parse dữ liệu:", e);
                }
            }

            // Cập nhật metadata
            const metaEl = document.getElementById('replace_meta');
            if (metaEl) {
                try {
                    const meta = JSON.parse(metaEl.textContent);
                    totalProductsCount = meta.total;
                } catch(e) {}
            }
        })
        .catch(error => {
            console.error('Lỗi khi tải sản phẩm:', error);
            productListing.innerHTML = '<div class="alert alert-danger">Không thể tải sản phẩm. Vui lòng thử lại sau.</div>';
        });
}

/**
 * Hàm "Xem thêm sản phẩm" — append thêm cards vào grid hiện tại
 */
function loadMoreProducts() {
    const btn = document.getElementById('btnLoadMore');
    const countEl = document.getElementById('loadMoreCount');
    if (!btn) return;

    // Loading state
    const originalHTML = btn.innerHTML;
    btn.disabled = true;
    btn.innerHTML = '<span class="spinner-border" role="status"></span><span>Đang tải...</span>';

    loadMorePage++;
    const searchQuery = searchInput ? searchInput.value : '';

    let url = `fetch_products.php?search=${encodeURIComponent(searchQuery)}&page=${loadMorePage}&mode=append`;
    if (currentCategoryId) {
        url += `&category=${currentCategoryId}`;
    }

    fetch(url)
        .then(response => response.text())
        .then(html => {
            // Tìm grid container
            let grid = document.getElementById('productGrid');
            if (!grid) {
                grid = productListing.querySelector('.row');
            }

            if (grid) {
                // Tạo temp container để parse HTML
                const temp = document.createElement('div');
                temp.innerHTML = html;

                // Lấy metadata
                const metaEl = temp.querySelector('#append_meta');
                let meta = null;
                if (metaEl) {
                    try {
                        meta = JSON.parse(metaEl.textContent);
                        metaEl.remove();
                    } catch(e) {}
                }

                // Append từng product card vào grid
                const cards = temp.querySelectorAll('.col-lg-3, .col-md-4, .col-sm-6');
                cards.forEach((card, i) => {
                    card.style.animationDelay = (i * 0.08) + 's';
                    grid.appendChild(card);
                });

                // Cập nhật dữ liệu JS
                if (meta && meta.products) {
                    currentProducts = currentProducts.concat(meta.products);
                }

                // Cập nhật UI
                if (meta) {
                    if (countEl) {
                        countEl.textContent = 'Đang hiển thị ' + meta.shown + ' / ' + meta.total + ' sản phẩm';
                    }

                    if (!meta.has_more) {
                        // Ẩn nút, hiện thông báo đã hết
                        btn.style.display = 'none';
                        if (countEl) {
                            countEl.textContent = 'Đã hiển thị tất cả ' + meta.total + ' sản phẩm';
                        }
                    } else {
                        btn.disabled = false;
                        btn.innerHTML = originalHTML;
                    }
                } else {
                    btn.disabled = false;
                    btn.innerHTML = originalHTML;
                }
            }
        })
        .catch(error => {
            console.error('Lỗi load more:', error);
            btn.disabled = false;
            btn.innerHTML = originalHTML;
        });
}

// 1. Xử lý nút Tìm kiếm (Search)
function handleProductFilter(e) {
    e.preventDefault(); 
    const searchQuery = searchInput ? searchInput.value : '';
    
    // Khi tìm kiếm mới, mặc định quay về trang 1
    history.pushState(null, '', `index.php?page=1&search=${encodeURIComponent(searchQuery)}&category=${currentCategoryId}`);

    fetchProducts(searchQuery, currentCategoryId, 1);
}

// 2. Xử lý nút Danh mục (Category)
function handleCategoryClick(element) {
    const categoryId = element.getAttribute('data-category-id') || '';
    
    // Cập nhật UI cho các nút danh mục
    categoryLinks.forEach(link => link.classList.remove('active'));
    element.classList.add('active');

    currentCategoryId = categoryId;
    const searchQuery = searchInput ? searchInput.value : '';

    // Khi đổi danh mục, mặc định quay về trang 1
    history.pushState(null, '', `index.php?page=1&search=${encodeURIComponent(searchQuery)}&category=${currentCategoryId}`);

    fetchProducts(searchQuery, categoryId, 1);
}

// ===================================================
// CÁC HIỆU ỨNG GIAO DIỆN KHÁC
// ===================================================

// Smooth scroll effect
document.querySelectorAll('a[href^="#"]').forEach(anchor => {
    anchor.addEventListener('click', function (e) {
        e.preventDefault();
        const target = document.querySelector(this.getAttribute('href'));
        if (target) {
            target.scrollIntoView({
                behavior: 'smooth',
                block: 'start'
            });
        }
    });
});

// Parallax effect for hero
window.addEventListener('scroll', function () {
    const hero = document.querySelector('.hero-premium');
    if (hero) {
        const scrolled = window.pageYOffset;
        hero.style.transform = `translateY(${scrolled * 0.5}px)`;
    }
});