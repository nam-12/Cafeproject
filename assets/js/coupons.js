
// Toggle hiển thị select apply_to_ids
function toggleApplyTo(selectElement, prefix = '') {
    const value = selectElement.value;
    const container = document.getElementById(prefix ? `${prefix}_applyToIdsContainer` : 'applyToIdsContainer');
    const select = document.getElementById(prefix ? `${prefix}_applyToIdsSelect` : 'applyToIdsSelect');
    
    if (value === 'all') {
        container.classList.add('d-none');
        select.innerHTML = '';
    } else {
        container.classList.remove('d-none');
        
        // Populate options
        let options = '';
        if (value === 'category') {
            categories.forEach(cat => {
                options += `<option value="${cat.id}">${cat.name}</option>`;
            });
        } else if (value === 'product') {
            products.forEach(prod => {
                options += `<option value="${prod.id}">${prod.name}</option>`;
            });
        }
        select.innerHTML = options;
    }
}

// Edit coupon
function editCoupon(coupon) {
    // Set basic fields
    document.getElementById('edit_id').value = coupon.id;
    document.getElementById('edit_code').value = coupon.code;
    document.getElementById('edit_name').value = coupon.name;
    document.getElementById('edit_description').value = coupon.description || '';
    document.getElementById('edit_discount_type').value = coupon.discount_type;
    document.getElementById('edit_discount_value').value = coupon.discount_value;
    document.getElementById('edit_max_discount').value = coupon.max_discount || '';
    document.getElementById('edit_min_order_value').value = coupon.min_order_value;
    document.getElementById('edit_max_usage').value = coupon.max_usage || '';
    document.getElementById('edit_max_usage_per_user').value = coupon.max_usage_per_user;
    document.getElementById('edit_apply_to').value = coupon.apply_to;
    
    // Format dates
    const startDate = new Date(coupon.start_date);
    const endDate = new Date(coupon.end_date);
    document.getElementById('edit_start_date').value = formatDateTimeLocal(startDate);
    document.getElementById('edit_end_date').value = formatDateTimeLocal(endDate);
    document.getElementById('edit_status').value = coupon.status;
    
    // Handle apply_to_ids
    const applyToSelect = document.getElementById('edit_apply_to');
    toggleApplyTo(applyToSelect, 'edit');
    
    if (coupon.apply_to !== 'all' && coupon.apply_to_ids) {
        setTimeout(() => {
            const idsSelect = document.getElementById('edit_applyToIdsSelect');
            let ids;
            try {
                ids = JSON.parse(coupon.apply_to_ids);
            } catch (e) {
                ids = [];
            }
            
            Array.from(idsSelect.options).forEach(option => {
                if (ids.includes(parseInt(option.value))) {
                    option.selected = true;
                }
            });
        }, 100);
    }
    
    // Show modal
    const modal = new bootstrap.Modal(document.getElementById('editCouponModal'));
    modal.show();
}

// Format date for datetime-local input
function formatDateTimeLocal(date) {
    const year = date.getFullYear();
    const month = String(date.getMonth() + 1).padStart(2, '0');
    const day = String(date.getDate()).padStart(2, '0');
    const hours = String(date.getHours()).padStart(2, '0');
    const minutes = String(date.getMinutes()).padStart(2, '0');
    return `${year}-${month}-${day}T${hours}:${minutes}`;
}

// Auto dismiss alerts
document.addEventListener('DOMContentLoaded', function() {
    const alerts = document.querySelectorAll('.alert');
    alerts.forEach(alert => {
        setTimeout(() => {
            const bsAlert = new bootstrap.Alert(alert);
            bsAlert.close();
        }, 5000);
    });
    
    // Auto uppercase code input
    const codeInputs = document.querySelectorAll('input[name="code"]');
    codeInputs.forEach(input => {
        input.addEventListener('input', function() {
            this.value = this.value.toUpperCase();
        });
    });
});

// Validate form before submit
function validateCouponForm(formId) {
    const form = document.getElementById(formId);
    
    form.addEventListener('submit', function(e) {
        const discountType = form.querySelector('[name="discount_type"]').value;
        const discountValue = parseFloat(form.querySelector('[name="discount_value"]').value);
        const startDate = new Date(form.querySelector('[name="start_date"]').value);
        const endDate = new Date(form.querySelector('[name="end_date"]').value);
        
        // Validate discount value
        if (discountType === 'percentage' && (discountValue <= 0 || discountValue > 100)) {
            e.preventDefault();
            alert('Giá trị giảm giá phần trăm phải từ 0-100%');
            return false;
        }
        
        // Validate dates
        if (endDate <= startDate) {
            e.preventDefault();
            alert('Ngày kết thúc phải sau ngày bắt đầu');
            return false;
        }
        
        return true;
    });
}

// Copy coupon code to clipboard
function copyCouponCode(code) {
    navigator.clipboard.writeText(code).then(() => {
        // Show tooltip
        const tooltip = document.createElement('div');
        tooltip.className = 'copy-tooltip';
        tooltip.textContent = 'Đã sao chép!';
        document.body.appendChild(tooltip);
        
        setTimeout(() => {
            tooltip.remove();
        }, 2000);
    });
}

// Add click event to coupon codes
document.addEventListener('DOMContentLoaded', function() {
    const couponCodes = document.querySelectorAll('.coupon-code');
    couponCodes.forEach(code => {
        code.style.cursor = 'pointer';
        code.title = 'Click để sao chép';
        code.addEventListener('click', function() {
            const codeText = this.querySelector('strong').textContent;
            copyCouponCode(codeText);
        });
    });
});

// Filter table
function filterTable() {
    const searchInput = document.querySelector('[name="search"]');
    const statusSelect = document.querySelector('[name="status"]');
    const table = document.querySelector('.table tbody');
    const rows = table.querySelectorAll('tr');
    
    const searchTerm = searchInput.value.toLowerCase();
    const statusFilter = statusSelect.value;
    
    rows.forEach(row => {
        const code = row.querySelector('.coupon-code strong').textContent.toLowerCase();
        const name = row.querySelector('.coupon-name').textContent.toLowerCase();
        const status = row.querySelector('td:nth-child(7) .badge');
        const statusValue = status.classList.contains('bg-success') ? 'active' : 
                          status.classList.contains('bg-secondary') ? 'inactive' : 'expired';
        
        const matchesSearch = code.includes(searchTerm) || name.includes(searchTerm);
        const matchesStatus = !statusFilter || statusValue === statusFilter;
        
        if (matchesSearch && matchesStatus) {
            row.style.display = '';
        } else {
            row.style.display = 'none';
        }
    });
}

// Export coupons to CSV
function exportCoupons() {
    const table = document.querySelector('.table');
    let csv = [];
    const rows = table.querySelectorAll('tr');
    
    rows.forEach(row => {
        const cols = row.querySelectorAll('td, th');
        const csvRow = [];
        cols.forEach(col => {
            csvRow.push('"' + col.textContent.trim().replace(/"/g, '""') + '"');
        });
        csv.push(csvRow.join(','));
    });
    
    const csvContent = csv.join('\n');
    const blob = new Blob(['\ufeff' + csvContent], { type: 'text/csv;charset=utf-8;' });
    const link = document.createElement('a');
    const url = URL.createObjectURL(blob);
    
    link.setAttribute('href', url);
    link.setAttribute('download', 'coupons_' + new Date().getTime() + '.csv');
    link.style.visibility = 'hidden';
    document.body.appendChild(link);
    link.click();
    document.body.removeChild(link);
}

// Print coupon list
function printCoupons() {
    window.print();
}

// Confirm delete
document.addEventListener('DOMContentLoaded', function() {
    const deleteLinks = document.querySelectorAll('a[href*="delete="]');
    deleteLinks.forEach(link => {
        link.addEventListener('click', function(e) {
            if (!confirm('Bạn có chắc chắn muốn xóa mã giảm giá này?\n\nLưu ý: Các đơn hàng đã sử dụng mã này sẽ không bị ảnh hưởng.')) {
                e.preventDefault();
            }
        });
    });
});