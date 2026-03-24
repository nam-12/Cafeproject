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
 * Hàm lấy sản phẩm từ server (AJAX)
 * @param {string} searchQuery - Từ khóa tìm kiếm
 * @param {string} categoryId - ID danh mục
 * @param {number} page - Trang hiện tại
 */
function fetchProducts(searchQuery, categoryId, page = 1) {
    if (!productListing) return;
    
    // Hiển thị hiệu ứng loading
    productListing.innerHTML = '<div class="text-center p-5"><i class="fas fa-spinner fa-spin fa-3x" style="color: #6f4e37;"></i><p class="mt-3">Đang tải sản phẩm...</p></div>';

    // Xây dựng URL cho AJAX bao gồm tham số page
    let url = `fetch_products.php?search=${encodeURIComponent(searchQuery)}&page=${page}`;
    if (categoryId) {
        url += `&category=${categoryId}`;
    }

    fetch(url)
        .then(response => response.text())
        .then(html => {
            // 1. Thay thế nội dung HTML
            productListing.innerHTML = html;

            // 2. CẬP NHẬT DỮ LIỆU JS: Tìm thẻ textarea ẩn và cập nhật biến currentProducts
            const dataElement = document.getElementById('ajax_product_data');
            if (dataElement) {
                try {
                    // Cập nhật biến toàn cục currentProducts với dữ liệu mới
                    currentProducts = JSON.parse(dataElement.value);
                    console.log("Đã cập nhật dữ liệu sản phẩm mới:", currentProducts);
                } catch (e) {
                    console.error("Lỗi khi parse dữ liệu sản phẩm:", e);
                }
            }
        })
        .catch(error => {
            console.error('Lỗi khi tải sản phẩm:', error);
            productListing.innerHTML = '<div class="alert alert-danger">Không thể tải sản phẩm. Vui lòng thử lại sau.</div>';
        });
}

/**
 * Hàm xử lý khi người dùng nhấn chuyển trang
 * @param {number} pageNumber - Số trang được nhấn
 */
function changePage(pageNumber) {
    const searchQuery = searchInput ? searchInput.value : '';
    
    // Cập nhật URL trên thanh địa chỉ trình duyệt để người dùng có thể copy/back
    const newUrl = `index.php?page=${pageNumber}&search=${encodeURIComponent(searchQuery)}&category=${currentCategoryId}`;
    history.pushState(null, '', newUrl);

    // Gọi fetch lấy dữ liệu cho trang mới
    fetchProducts(searchQuery, currentCategoryId, pageNumber);
    
    // Cuộn lên đầu danh sách sản phẩm để người dùng dễ quan sát
    const productSection = document.querySelector('.products-premium');
    if (productSection) {
        productSection.scrollIntoView({ behavior: 'smooth' });
    }
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