/**
 * Cafe Shop Payment System - Checkout Logic
 * Tích hợp Xử lý Đơn hàng & Mã giảm giá
 */

// --- HÀM TIỆN ÍCH GIAO DIỆN ---

function showLoading(button, text = 'Đang xử lý...') {
    const originalText = button.innerHTML;
    button.disabled = true;
    button.setAttribute('data-original-text', originalText);
    button.innerHTML = `<i class="fas fa-spinner fa-spin me-2"></i> ${text}`;
}

function hideLoading(button) {
    const originalText = button.getAttribute('data-original-text');
    button.disabled = false;
    if (originalText) {
        button.innerHTML = originalText;
    }
}

function showNotification(message, type = 'info') {
    // Xóa thông báo cũ nếu có
    const oldAlert = document.querySelector('.checkout-alert');
    if (oldAlert) oldAlert.remove();

    const notification = document.createElement('div');
    notification.className = `alert alert-${type === 'error' ? 'danger' : 'success'} alert-dismissible fade show mt-3 checkout-alert`;
    notification.setAttribute('role', 'alert');
    notification.innerHTML = `
        <i class="fas ${type === 'error' ? 'fa-exclamation-circle' : 'fa-check-circle'} me-2"></i>
        ${message}
        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
    `;

    // SỬA LỖI TẠI ĐÂY: Thay insertBefore bằng prepend để an toàn hơn
    const container = document.querySelector('.container');
    if (container) {
        container.prepend(notification); // Đưa thông báo lên đầu thẻ container
        // Cuộn màn hình lên đầu để khách hàng thấy thông báo
        notification.scrollIntoView({ behavior: 'smooth', block: 'center' });
    }

    // Tự động đóng sau 5s
    setTimeout(() => {
        if (notification.parentNode) {
            const alert = new bootstrap.Alert(notification);
            alert.close();
        }
    }, 5000);
}

// Định dạng tiền tệ VNĐ
function formatCurrency(amount) {
    return new Intl.NumberFormat('vi-VN', { style: 'currency', currency: 'VND' }).format(amount);
}

// --- LOGIC MÃ GIẢM GIÁ (COUPON) ---

let isCouponValid = false;

async function applyCoupon(silent = false) {
    const couponInput = document.getElementById('coupon_input');
    const btnApply = document.getElementById('btn_apply_coupon');
    const code = couponInput.value.trim().toUpperCase();

    if (!code) {
        if (!silent) showNotification('Vui lòng nhập mã giảm giá', 'error');
        isCouponValid = false;
        return false;
    }

    if (!silent) showLoading(btnApply, '...');

    try {
        const response = await fetch('check_coupon.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({
                code: code,
                subtotal: cartSubtotal 
            })
        });

        const result = await response.json();

        if (result.success) {
            updateOrderTotal(result.discount_amount);
            if (!silent) showNotification(result.message, 'success');
            couponInput.classList.remove('is-invalid');
            couponInput.classList.add('is-valid');
            isCouponValid = true;
            return true;
        } else {
            if (!silent) showNotification('Voucher của bạn không hợp lệ hoặc đã hết hạn', 'error');
            couponInput.classList.remove('is-valid');
            couponInput.classList.add('is-invalid');
            isCouponValid = false;
            updateOrderTotal(0); 
            return false;
        }
    } catch (error) {
        console.error('Lỗi check coupon:', error);
        if (!silent) showNotification('Không thể kiểm tra mã lúc này', 'error');
        return false;
    } finally {
        if (!silent) hideLoading(btnApply);
    }
}

function updateOrderTotal(discountAmount) {
    const rowDiscount = document.getElementById('row_discount');
    const valDiscount = document.getElementById('val_discount');
    const totalDisplay = document.getElementById('final_total_display');
    const inputFinalTotal = document.getElementById('input_final_total');

    if (discountAmount > 0) {
        rowDiscount.classList.remove('d-none');
        valDiscount.innerText = '-' + formatCurrency(discountAmount);
    } else {
        rowDiscount.classList.add('d-none');
    }

    const newTotal = cartSubtotal + shippingFee - discountAmount;
    const finalTotal = newTotal < 0 ? 0 : newTotal;

    totalDisplay.innerText = formatCurrency(finalTotal);
    if (inputFinalTotal) inputFinalTotal.value = finalTotal;
    
    // Dispatch custom event for IIFE to listen
    document.dispatchEvent(new CustomEvent('couponApplied', {
        detail: { discount: discountAmount }
    }));
}

// --- VALIDATION & SUBMIT ---

function validateCheckoutForm(form) {
    const requiredFields = form.querySelectorAll('[required]');
    let isValid = true;
    let firstInvalidField = null;

    requiredFields.forEach(field => {
        if (!field.value.trim()) {
            isValid = false;
            field.classList.add('is-invalid');
            if (!firstInvalidField) firstInvalidField = field;
        } else {
            field.classList.remove('is-invalid');
        }
    });

    if (!isValid) {
        showNotification('Vui lòng điền đầy đủ thông tin bắt buộc!', 'error');
        if (firstInvalidField) {
            firstInvalidField.focus();
        }
    }
    return isValid;
}

async function handleCheckoutSubmit(event) {
    event.preventDefault(); 
    const form = event.target;
    const submitButton = form.querySelector('.btn-place-order');
    const couponInput = document.getElementById('coupon_input');
    const code = couponInput.value.trim();

    if (!validateCheckoutForm(form)) return;

    if (code !== "") {
        showLoading(submitButton, 'Đang kiểm tra voucher...');
        const isValid = await applyCoupon(true); 
        hideLoading(submitButton);

        if (!isValid) {
            showNotification('Voucher của bạn không hợp lệ hoặc đã hết hạn', 'error');
            couponInput.classList.add('is-invalid');
            couponInput.focus();
            return; 
        }
    }

    showLoading(submitButton, 'Đang đặt hàng...');
    form.submit();
}

// --- GIAO DIỆN THANH TOÁN ---

function highlightSelectedPayment() {
    const paymentOptions = document.querySelectorAll('.payment-option');
    const activeColor = '#a2836e';

    paymentOptions.forEach(option => {
        const radio = option.querySelector('input[type="radio"]');

        const updateUI = () => {
            paymentOptions.forEach(opt => opt.style.borderColor = '#e0e0e0');
            if (radio.checked) option.style.borderColor = activeColor;
        };

        option.addEventListener('click', (e) => {
            if (e.target.tagName !== 'INPUT') {
                radio.checked = true;
                updateUI();
            }
        });

        radio.addEventListener('change', updateUI);
        if (radio.checked) updateUI();
    });
}

// --- KHỞI TẠO ---

document.addEventListener('DOMContentLoaded', function() {
    highlightSelectedPayment();

    const checkoutForm = document.getElementById('checkoutForm');
    if (checkoutForm) {
        checkoutForm.addEventListener('submit', handleCheckoutSubmit);
    }

    const btnApply = document.getElementById('btn_apply_coupon');
    const couponInput = document.getElementById('coupon_input');

    if (btnApply) {
        btnApply.addEventListener('click', () => applyCoupon(false));
    }

    if (couponInput) {
        couponInput.addEventListener('keypress', function(e) {
            if (e.key === 'Enter') {
                e.preventDefault();
                applyCoupon(false);
            }
        });
        couponInput.addEventListener('input', function() {
            this.classList.remove('is-invalid');
            this.classList.remove('is-valid');
        });
    }

    document.querySelectorAll('.form-control[required]').forEach(input => {
        input.addEventListener('input', function() {
            this.classList.remove('is-invalid');
        });
    });
});
