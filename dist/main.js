// main.js - المرحلة الأولى: التفاعلات الأساسية
document.addEventListener('DOMContentLoaded', function() {
    
    // تبديل السايد بار على الهواتف
    const menuToggle = document.querySelector('.menu-toggle');
    const sidebar = document.querySelector('.modern-sidebar');
    
    if (menuToggle) {
        menuToggle.addEventListener('click', function() {
            sidebar.classList.toggle('active');
        });
    }
    
    // إغلاق السايد بار عند النقر خارجها (على الهواتف)
    document.addEventListener('click', function(event) {
        if (window.innerWidth <= 1024) {
            if (sidebar && sidebar.classList.contains('active') && 
                !sidebar.contains(event.target) && 
                !menuToggle.contains(event.target)) {
                sidebar.classList.remove('active');
            }
        }
    });
    
    // إضافة تأثيرات عند التمرير
    window.addEventListener('scroll', function() {
        const cards = document.querySelectorAll('.card');
        cards.forEach(card => {
            const cardTop = card.getBoundingClientRect().top;
            const windowHeight = window.innerHeight;
            
            if (cardTop < windowHeight - 100) {
                card.classList.add('animated');
            }
        });
    });
    
    // تحديث عداد السلة
    function updateCartCount() {
        fetch('get_cart_count.php')
            .then(response => response.json())
            .then(data => {
                const cartElements = document.querySelectorAll('.cart-count');
                cartElements.forEach(el => {
                    el.textContent = data.count || 0;
                    if (data.count > 0) {
                        el.classList.add('has-items');
                    } else {
                        el.classList.remove('has-items');
                    }
                });
            });
    }
    
    // تحديث التبويب النشط
    function setActiveTab() {
        const currentPage = window.location.pathname.split('/').pop();
        const menuItems = document.querySelectorAll('.menu-item');
        menuItems.forEach(item => {
            const href = item.getAttribute('href');
            if (href === currentPage) {
                item.classList.add('active');
            } else {
                item.classList.remove('active');
            }
        });
    }
    
    // تهيئة
    updateCartCount();
    setActiveTab();
    
    // إضافة تأثيرات للصور
    const images = document.querySelectorAll('img');
    images.forEach(img => {
        img.addEventListener('load', function() {
            this.classList.add('loaded');
        });
        
        // إضافة صورة افتراضية عند الخطأ
        img.addEventListener('error', function() {
            this.src = 'uploads/meals/placeholder.png';
        });
    });
    
    // إشعارات مؤقتة
    window.showToast = function(message, type = 'success') {
        const toast = document.createElement('div');
        toast.className = `toast toast-${type}`;
        toast.innerHTML = `
            <div class="toast-icon">
                <i class="fas fa-${type === 'success' ? 'check-circle' : 'exclamation-circle'}"></i>
            </div>
            <div class="toast-content">${message}</div>
            <button class="toast-close"><i class="fas fa-times"></i></button>
        `;
        
        document.body.appendChild(toast);
        
        setTimeout(() => {
            toast.classList.add('show');
        }, 10);
        
        setTimeout(() => {
            toast.classList.remove('show');
            setTimeout(() => {
                toast.remove();
            }, 300);
        }, 3000);
        
        // زر الإغلاق
        toast.querySelector('.toast-close').addEventListener('click', function() {
            toast.classList.remove('show');
            setTimeout(() => {
                toast.remove();
            }, 300);
        });
    };
});



// main.js - إضافة وظائف جديدة للمرحلة الثانية

// ... [الكود الحالي] ...

// إضافة هذه الوظائف في نهاية الملف:

// إدارة تبويبات الأيام
function initDayTabs() {
    const dayTabs = document.querySelectorAll('.category-tab');
    if (dayTabs.length > 0) {
        dayTabs.forEach(tab => {
            tab.addEventListener('click', function() {
                const day = this.id.replace('tab_', '');
                openDayTab(day);
            });
        });
    }
}

// تأثيرات لاختيار الوجبات
function initMealSelection() {
    const mealCards = document.querySelectorAll('.meal-selector');
    mealCards.forEach(card => {
        card.addEventListener('click', function(e) {
            // إذا كان النقر على المدخل نفسه، لا نفعل شيئاً
            if (e.target.tagName === 'INPUT') return;
            
            const input = this.querySelector('input');
            if (input.type === 'radio') {
                // لإلغاء اختيار الراديو، يجب إضافة خاصية خاصة
                if (input.checked) {
                    input.checked = false;
                } else {
                    input.checked = true;
                }
            } else {
                // Checkbox
                input.checked = !input.checked;
            }
            
            // trigger change event
            input.dispatchEvent(new Event('change'));
            
            // تأثير بصري
            this.classList.add('pulse');
            setTimeout(() => {
                this.classList.remove('pulse');
            }, 300);
        });
    });
}

// تحميل الصور مع تأثيرات
function lazyLoadImages() {
    const images = document.querySelectorAll('img[data-src]');
    const imageObserver = new IntersectionObserver((entries, observer) => {
        entries.forEach(entry => {
            if (entry.isIntersecting) {
                const img = entry.target;
                img.src = img.dataset.src;
                img.classList.add('loaded');
                observer.unobserve(img);
            }
        });
    }, { rootMargin: '50px 0px' });
    
    images.forEach(img => imageObserver.observe(img));
}

// عرض تلميحات (Tooltips)
function initTooltips() {
    const tooltipElements = document.querySelectorAll('[data-tooltip]');
    tooltipElements.forEach(el => {
        el.addEventListener('mouseenter', function(e) {
            const tooltip = document.createElement('div');
            tooltip.className = 'tooltip';
            tooltip.textContent = this.dataset.tooltip;
            document.body.appendChild(tooltip);
            
            const rect = this.getBoundingClientRect();
            tooltip.style.position = 'fixed';
            tooltip.style.top = (rect.top - tooltip.offsetHeight - 10) + 'px';
            tooltip.style.left = (rect.left + rect.width / 2 - tooltip.offsetWidth / 2) + 'px';
            
            this._tooltip = tooltip;
        });
        
        el.addEventListener('mouseleave', function() {
            if (this._tooltip) {
                this._tooltip.remove();
                delete this._tooltip;
            }
        });
    });
}

// عرض شريط التقدم للحفظ
function showProgressBar() {
    const forms = document.querySelectorAll('form');
    forms.forEach(form => {
        form.addEventListener('submit', function(e) {
            const submitBtn = this.querySelector('button[type="submit"]');
            if (submitBtn) {
                submitBtn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> جاري الحفظ...';
                submitBtn.disabled = true;
            }
        });
    });
}

// إضافة CSS للأنماط الجديدة
function addDynamicStyles() {
    const style = document.createElement('style');
    style.textContent = `
        .pulse {
            animation: pulseEffect 0.3s ease;
        }
        
        @keyframes pulseEffect {
            0% { transform: scale(1); }
            50% { transform: scale(1.05); }
            100% { transform: scale(1); }
        }
        
        .tooltip {
            background: rgba(0,0,0,0.8);
            color: white;
            padding: 8px 12px;
            border-radius: 6px;
            font-size: 14px;
            z-index: 9999;
            pointer-events: none;
            max-width: 200px;
            text-align: center;
        }
        
        .meal-option-card {
            cursor: pointer;
        }
        
        .meal-option-card:hover {
            transform: translateY(-5px);
        }
        
        .off-day {
            opacity: 0.7;
        }
        
        .off-day:hover {
            opacity: 1;
        }
    `;
    document.head.appendChild(style);
}

// تحديث التهيئة عند تحميل الصفحة
document.addEventListener('DOMContentLoaded', function() {
    // ... [الكود الحالي] ...
    
    // إضافة الوظائف الجديدة
    initDayTabs();
    initMealSelection();
    lazyLoadImages();
    initTooltips();
    showProgressBar();
    addDynamicStyles();
    
    // إضافة تأثيرات عند التمرير
    const observerOptions = {
        threshold: 0.1,
        rootMargin: '0px 0px -50px 0px'
    };
    
    const observer = new IntersectionObserver((entries) => {
        entries.forEach(entry => {
            if (entry.isIntersecting) {
                entry.target.classList.add('animate-in');
            }
        });
    }, observerOptions);
    
    document.querySelectorAll('.card, .meal-option-card, .stat-card').forEach(el => {
        observer.observe(el);
    });
});

// دالة مساعدة: فتح تبويب اليوم
function openDayTab(dayName) {
    const contents = document.querySelectorAll('.day-tab-content');
    const tabs = document.querySelectorAll('.category-tab');
    
    contents.forEach(content => content.classList.remove('active'));
    tabs.forEach(tab => tab.classList.remove('active'));
    
    const content = document.getElementById('content_' + dayName);
    const tab = document.getElementById('tab_' + dayName);
    
    if (content) content.classList.add('active');
    if (tab) tab.classList.add('active');
}