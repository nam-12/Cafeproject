/* ===============================
    GLOBAL CACHE & STATE
================================ */
const navbar = document.getElementById('navbar');
const heroContent = document.querySelector('.hero-content');
const nightBg = document.querySelector('.bg-night');
const reveals = document.querySelectorAll('.reveal');
const coffeeBeans = document.querySelectorAll('.coffee-bean');
const scrollToTopBtn = document.getElementById('scrollToTop');

let scrollYPos = 0;
let scrollProgress = 0;
let smoothProgress = 0;
let rafId = null;

/* ===============================
    SCROLL ENGINE
================================ */
window.addEventListener('scroll', () => {
    scrollYPos = window.scrollY;

    const maxScroll = document.documentElement.scrollHeight - window.innerHeight;
    scrollProgress = maxScroll > 0 ? Math.min(scrollYPos / maxScroll, 1) : 0;

    // Sử dụng RequestAnimationFrame để tối ưu hóa hiệu năng (60fps)
    if (!rafId) {
        rafId = requestAnimationFrame(() => {
            updateOnScroll();
            rafId = null;
        });
    }
});

/* ===============================
    MAIN UPDATE LOOP
================================ */
function updateOnScroll() {
    /* ===== SMOOTH PROGRESS (Dùng cho hiệu ứng chuyển cảnh mượt) ===== */
    smoothProgress += (scrollProgress - smoothProgress) * 0.08;

    /* ===== NAVBAR LOGIC ===== */
    if (navbar) {
        navbar.classList.toggle('scrolled', scrollYPos > 50);
    }

    /* ===== SCROLL TO TOP BUTTON ===== */
    if (scrollToTopBtn) {
        if (scrollYPos > 500) {
            scrollToTopBtn.classList.add('visible');
        } else {
            scrollToTopBtn.classList.remove('visible');
        }
    }

    /* ===== CINEMATIC BACKGROUND (Chuyển từ ngày sang đêm) ===== */
    if (nightBg) {
        let darkness;
        if (smoothProgress < 0.25) {
            darkness = smoothProgress * 0.12; // Sáng
        } else if (smoothProgress < 0.55) {
            darkness = 0.03 + (smoothProgress - 0.25) * 1.9; // Hoàng hôn
        } else {
            darkness = 0.62 + (smoothProgress - 0.55) * 3.8; // Đêm sâu
        }
        
        // Khóa độ tối khi chạm đáy trang
        if (scrollProgress >= 0.99) darkness = 1;
        nightBg.style.opacity = Math.min(darkness, 1);
    }

    /* ===== HERO PARALLAX ===== */
    if (heroContent && scrollYPos < window.innerHeight) {
        heroContent.style.transform = `translateY(${scrollYPos * 0.35}px)`;
        heroContent.style.opacity = 1 - (scrollYPos / (window.innerHeight * 0.8));
    }

    /* ===== REVEAL ON SCROLL ===== */
    reveals.forEach(el => {
        const top = el.getBoundingClientRect().top;
        if (top < window.innerHeight - 100) {
            el.classList.add('active');
        }
    });

    /* ===== COFFEE BEANS UPDATE ===== */
    updateCoffeeBeans(smoothProgress, scrollYPos);
}

/* ===============================
    COFFEE BEANS – PARALLAX & COLOR TRANSITION
================================ */
function updateCoffeeBeans(progress, scrollY) {
    // Điểm bắt đầu chuyển màu (từ 20% đến 70% quãng đường cuộn)
    const t = Math.min(Math.max((progress - 0.2) / 0.5, 0), 1);

    const colors = {
        day: { light: [139, 111, 71], dark: [85, 65, 45] },
        night: { light: [255, 215, 120], dark: [180, 130, 60] }
    };

    coffeeBeans.forEach(bean => {
        const depth = parseFloat(bean.dataset.depth || 1);

        /* 1. Parallax & Rotate */
        bean.style.transform = `
            translateY(${-scrollY * depth * 0.15}px)
            rotate(${scrollY * depth * 0.04}deg)
            scale(${depth})
        `;

        /* 2. Color Interpolation (Hòa trộn màu theo scroll) */
        const mix = (start, end) => Math.round(start + (end - start) * t);
        
        const r1 = mix(colors.day.light[0], colors.night.light[0]);
        const g1 = mix(colors.day.light[1], colors.night.light[1]);
        const b1 = mix(colors.day.light[2], colors.night.light[2]);

        const r2 = mix(colors.day.dark[0], colors.night.dark[0]);
        const g2 = mix(colors.day.dark[1], colors.night.dark[1]);
        const b2 = mix(colors.day.dark[2], colors.night.dark[2]);

        bean.style.background = `radial-gradient(circle at 30% 30%, rgb(${r1},${g1},${b1}), rgb(${r2},${g2},${b2}))`;
        bean.style.opacity = 0.2 + t * 0.4;
    });
}

/* ===============================
    3D TILT EFFECT FOR CARDS
================================ */
function initTiltEffect() {
    // Sử dụng event delegation để bắt được cả các card mới khi nhấn "Xem thêm"
    document.addEventListener('mousemove', e => {
        const card = e.target.closest('.tilt-card');
        if (!card) return;

        const rect = card.getBoundingClientRect();
        const x = e.clientX - rect.left;
        const y = e.clientY - rect.top;

        const rotateX = (y - rect.height / 2) / 10;
        const rotateY = (rect.width / 2 - x) / 10;

        card.style.transform = `
            perspective(1000px)
            rotateX(${rotateX}deg)
            rotateY(${rotateY}deg)
            translateY(-15px)
            scale(1.05)
        `;
    });

    document.addEventListener('mouseout', e => {
        const card = e.target.closest('.tilt-card');
        if (card) {
            card.style.transform = 'perspective(1000px) rotateX(0) rotateY(0) translateY(0) scale(1)';
        }
    });
}

/* ===============================
    CINEMATIC SPARKLES (Đom đóm ban đêm)
================================ */
function initSparkles() {
    const sparkleLayer = document.querySelector('.sparkles');
    if (!sparkleLayer) return;

    function createSparkle() {
        if (smoothProgress < 0.4) return; // Chỉ tạo đom đóm khi trời đã tối

        const s = document.createElement('div');
        s.className = 'sparkle';
        const depth = Math.random() * 0.8 + 0.2;

        s.style.setProperty('--size', (1 + depth * 3) + 'px');
        s.style.setProperty('--life', (3 + Math.random() * 4) + 's');
        s.style.setProperty('--dx', (Math.random() * 100 - 50) + 'px');
        s.style.setProperty('--dy', (-80 - Math.random() * 50) + 'px');

        s.style.left = Math.random() * 100 + '%';
        s.style.top = (60 + Math.random() * 40) + '%';

        sparkleLayer.appendChild(s);
        setTimeout(() => s.remove(), 7000);
    }

    setInterval(createSparkle, 450);
}

/* ===============================
    INITIALIZATION
================================ */
document.addEventListener('DOMContentLoaded', () => {
    initTiltEffect();
    initSparkles();

    if (scrollToTopBtn) {
        scrollToTopBtn.addEventListener('click', () => {
            window.scrollTo({
                top: 0,
                behavior: 'smooth' // Tạo hiệu ứng cuộn mượt mà
            });
        });
    }
    
    // Khởi chạy update lần đầu để set vị trí chuẩn
    updateOnScroll();
});