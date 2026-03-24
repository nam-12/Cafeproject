/**
 * HIỂN THỊ THÔNG BÁO (TOAST)
 */
function showToast(message, type = 'success') {
    const toastEl = document.getElementById('statusToast');
    const toastMsg = document.getElementById('toastMessage');
    
    // Reset class
    toastEl.classList.remove('bg-success', 'bg-danger', 'bg-warning');
    
    // Thêm class dựa trên loại thông báo
    if (type === 'success') toastEl.classList.add('bg-success');
    else if (type === 'error') toastEl.classList.add('bg-danger');
    else toastEl.classList.add('bg-warning');

    toastMsg.innerText = message;
    const toast = new bootstrap.Toast(toastEl, { delay: 2500 });
    toast.show();
}

/**
 * CẬP NHẬT SỐ LƯỢNG TRÊN TAB
 */
function updateMyTabCount(addCount) {
    const countElement = document.getElementById('myCount');
    if (countElement) {
        const currentCount = parseInt(countElement.innerText) || 0;
        countElement.innerText = currentCount + addCount;
    }
}

/**
 * TỰ ĐỘNG CHÈN VOUCHER MỚI VÀO DANH SÁCH HTML MÀ KHÔNG CẦN F5
 */
function appendCouponToMyList(coupon) {
    const myList = document.getElementById('myCouponsList');
    if (!myList) return;

    // Xóa thông báo "Trống" nếu có
    const emptyState = myList.querySelector('.text-center.py-5');
    if (emptyState) {
        myList.innerHTML = '';
    }

    // Định dạng ngày (d/m/Y)
    const date = new Date(coupon.end_date);
    const day = String(date.getDate()).padStart(2, '0');
    const month = String(date.getMonth() + 1).padStart(2, '0');
    const year = date.getFullYear();
    const formattedDate = `${day}/${month}/${year}`;

    // Tạo HTML cho card voucher mới
    const couponHTML = `
        <div class="col-lg-6 col-xl-4 animate__animated animate__fadeIn">
            <div class="coupon-card">
                <div class="coupon-left" style="background: linear-gradient(135deg, #27ae60, #2ecc71);">
                    <div class="coupon-icon"><i class="fas fa-check-circle"></i></div>
                    <div class="coupon-type">SẴN SÀNG</div>
                </div>
                <div class="coupon-right">
                    <h5 class="coupon-title">${coupon.name}</h5>
                    <div class="code-box">
                        <span class="code-text">${coupon.code}</span>
                        <button class="btn-copy" onclick="copyCoupon('${coupon.code}', this)">SAO CHÉP</button>
                    </div>
                    <div class="coupon-expiry mt-2 small text-danger">
                        <i class="far fa-clock me-1"></i>HSD: ${formattedDate}
                    </div>
                </div>
            </div>
        </div>
    `;

    // Chèn vào đầu danh sách
    myList.insertAdjacentHTML('afterbegin', couponHTML);
}

/**
 * NHẬN 1 VOUCHER
 */
function collectCoupon(couponId, btnElement, isFuture = false) {
    // 1. Nếu là mã chưa diễn ra, đưa ra thông báo và không xử lý tiếp
    if (isFuture) {
        showToast("Mã này hiện chưa đến thời gian diễn ra!", "warning");
        return;
    }

    btnElement.disabled = true;
    const originalText = btnElement.innerText;
    btnElement.innerHTML = '<span class="spinner-border spinner-border-sm"></span>';

    fetch('collect_coupon_process.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ coupon_ids: [couponId] })
    })
    .then(response => response.json())
    .then(data => {
        if (data.success) {
            btnElement.innerText = "ĐÃ THU THẬP";
            btnElement.className = "btn btn-secondary btn-collect";
            btnElement.disabled = true;
            
            if (data.coupons && data.coupons.length > 0) {
                appendCouponToMyList(data.coupons[0]);
            }
            
            showToast("Đã thu thập voucher thành công!");
            updateMyTabCount(1);
        } else {
            btnElement.disabled = false;
            btnElement.innerText = originalText;
            showToast(data.message, 'error');
        }
    })
    .catch(error => {
        console.error('Error:', error);
        btnElement.disabled = false;
        btnElement.innerText = originalText;
    });
}

/**
 * NHẬN TẤT CẢ VOUCHER
 */
function collectAllCoupons() {
    // 2. Lọc bỏ các nút có class 'btn-future' (Mã sắp diễn ra)
    const activeButtons = document.querySelectorAll('.btn-collect:not(:disabled):not(.btn-future)');
    
    const ids = Array.from(activeButtons).map(btn => {
        const match = btn.getAttribute('onclick').match(/\d+/);
        return match ? match[0] : null;
    }).filter(id => id !== null);

    if (ids.length === 0) {
        // Kiểm tra xem có phải do toàn mã "Sắp diễn ra" hay không để báo tin chuẩn hơn
        const futureButtons = document.querySelectorAll('.btn-future');
        if (futureButtons.length > 0) {
            showToast("Hiện chưa có mã nào đang hoạt động để nhận!", "warning");
        } else {
            showToast("Bạn đã thu thập hết mã hiện có!", "warning");
        }
        return;
    }

    const btnAll = document.getElementById('btnCollectAll');
    const originalAllText = btnAll.innerHTML;
    btnAll.disabled = true;
    btnAll.innerHTML = '<span class="spinner-border spinner-border-sm"></span> ĐANG XỬ LÝ...';

    fetch('collect_coupon_process.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ coupon_ids: ids })
    })
    .then(response => response.json())
    .then(data => {
        if (data.success) {
            // Chỉ đổi trạng thái các nút ĐÃ gửi ID đi (nút đang hoạt động)
            activeButtons.forEach(btn => {
                btn.innerText = "ĐÃ THU THẬP";
                btn.className = "btn btn-secondary btn-collect";
                btn.disabled = true;
            });

            if (data.coupons && data.coupons.length > 0) {
                // Đảo ngược danh sách để chèn đúng thứ tự nếu cần
                data.coupons.forEach(cp => appendCouponToMyList(cp));
            }

            showToast(`Thành công! Đã thêm ${data.collected_count} mã vào kho của bạn.`);
            updateMyTabCount(data.collected_count);
            btnAll.innerHTML = '<i class="fas fa-check"></i> ĐÃ NHẬN HẾT';
        } else {
            btnAll.disabled = false;
            btnAll.innerHTML = originalAllText;
            showToast(data.message, 'error');
        }
    })
    .catch(error => {
        console.error('Error:', error);
        btnAll.disabled = false;
        btnAll.innerHTML = originalAllText;
    });
}

/**
 * SAO CHÉP MÃ VÀO BỘ NHỚ TẠM
 */
function copyCoupon(code, btnElement) {
    navigator.clipboard.writeText(code).then(() => {
        const originalText = btnElement.innerText;
        btnElement.innerText = "OK!";
        const originalBg = btnElement.style.background;
        btnElement.style.background = "#27ae60";

        setTimeout(() => {
            btnElement.innerText = originalText;
            btnElement.style.background = originalBg;
        }, 2000);
    });
}