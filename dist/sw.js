// sw.js - Service Worker للتطبيق (PWA) - دعم الإشعارات والتخزين المؤقت
// ====================================================================

const CACHE_NAME = 'carby-app-v1';
const RUNTIME_CACHE = 'carby-runtime-v1';

// الصفحات والأصول الأساسية للتطبيق
const STATIC_ASSETS = [
  '/',
  '/client_dashboard.php',
  '/login.php',
  '/client_style.css',
  '/client_footer_nav.php',
  '/uploads/logo.png'
];

// تثبيت Service Worker
self.addEventListener('install', (event) => {
  console.log('[SW] Installing Service Worker...');
  event.waitUntil(
    caches.open(CACHE_NAME)
      .then((cache) => {
        console.log('[SW] Caching static assets');
        return cache.addAll(STATIC_ASSETS).catch(err => {
          console.log('[SW] Error caching:', err);
        });
      })
      .then(() => self.skipWaiting())
  );
});

// تفعيل Service Worker
self.addEventListener('activate', (event) => {
  console.log('[SW] Activating Service Worker...');
  event.waitUntil(
    caches.keys().then((cacheNames) => {
      return Promise.all(
        cacheNames.map((cacheName) => {
          if (cacheName !== CACHE_NAME && cacheName !== RUNTIME_CACHE) {
            console.log('[SW] Deleting old cache:', cacheName);
            return caches.delete(cacheName);
          }
        })
      );
    }).then(() => self.clients.claim())
  );
});

// معالجة الطلبات (Network First Strategy)
self.addEventListener('fetch', (event) => {
  // تجاهل الطلبات غير GET
  if (event.request.method !== 'GET') {
    return;
  }

  // تجاهل الطلبات من خارج الموقع
  if (!event.request.url.startsWith(self.location.origin)) {
    return;
  }

  event.respondWith(
    caches.open(RUNTIME_CACHE).then((cache) => {
      return fetch(event.request)
        .then((response) => {
          // تخزين الاستجابة في الكاش
          if (response.status === 200) {
            cache.put(event.request, response.clone());
          }
          return response;
        })
        .catch(() => {
          // في حالة فشل الاتصال، جلب من الكاش
          return cache.match(event.request).then((cachedResponse) => {
            if (cachedResponse) {
              return cachedResponse;
            }
            // إذا لم يكن موجوداً في الكاش، إرجاع صفحة offline
            if (event.request.destination === 'document') {
              return new Response('الرجاء التحقق من اتصال الإنترنت', {
                headers: { 'Content-Type': 'text/html; charset=utf-8' }
              });
            }
          });
        });
    })
  );
});

// معالجة الإشعارات الدافعة (Push Notifications)
self.addEventListener('push', (event) => {
  console.log('[SW] Push notification received');
  
  let data = {
    title: 'إشعار من تطبيق وجباتي',
    body: 'لديك إشعار جديد',
    icon: '/uploads/logo.png',
    badge: '/uploads/logo.png',
    data: { url: '/client_dashboard.php' }
  };

  if (event.data) {
    try {
      const payload = event.data.json();
      data = {
        title: payload.title || data.title,
        body: payload.body || payload.message || data.body,
        icon: payload.icon || payload.image || data.icon,
        badge: payload.badge || data.badge,
        data: {
          url: payload.url || payload.link || data.data.url
        },
        requireInteraction: payload.requireInteraction || false,
        tag: payload.tag || 'default',
        vibrate: payload.vibrate || [200, 100, 200]
      };
    } catch (e) {
      // إذا لم يكن JSON، استخدم النص
      data.body = event.data.text();
    }
  }

  event.waitUntil(
    self.registration.showNotification(data.title, {
      body: data.body,
      icon: data.icon,
      badge: data.badge,
      data: data.data,
      requireInteraction: data.requireInteraction,
      tag: data.tag,
      vibrate: data.vibrate,
      actions: [
        {
          action: 'open',
          title: 'فتح',
          icon: '/uploads/logo.png'
        },
        {
          action: 'close',
          title: 'إغلاق'
        }
      ]
    })
  );
});

// معالجة النقر على الإشعارات
self.addEventListener('notificationclick', (event) => {
  console.log('[SW] Notification clicked');
  event.notification.close();

  const urlToOpen = event.notification.data.url || '/client_dashboard.php';
  const action = event.action;

  if (action === 'close') {
    return;
  }

  event.waitUntil(
    clients.matchAll({
      type: 'window',
      includeUncontrolled: true
    }).then((clientList) => {
      // البحث عن نافذة مفتوحة بالفعل
      for (let i = 0; i < clientList.length; i++) {
        const client = clientList[i];
        if (client.url === urlToOpen && 'focus' in client) {
          return client.focus();
        }
      }
      // إذا لم تكن هناك نافذة مفتوحة، افتح واحدة جديدة
      if (clients.openWindow) {
        return clients.openWindow(urlToOpen);
      }
    })
  );
});

// معالجة رسائل من الصفحات
self.addEventListener('message', (event) => {
  console.log('[SW] Message received:', event.data);
  
  if (event.data && event.data.type === 'SKIP_WAITING') {
    self.skipWaiting();
  }
  
  if (event.data && event.data.type === 'CACHE_URLS') {
    event.waitUntil(
      caches.open(RUNTIME_CACHE).then((cache) => {
        return cache.addAll(event.data.urls);
      })
    );
  }
});

// تحديث دوري للكاش (كل 24 ساعة)
setInterval(() => {
  caches.open(CACHE_NAME).then((cache) => {
    cache.addAll(STATIC_ASSETS).catch(err => {
      console.log('[SW] Background cache update error:', err);
    });
  });
}, 86400000); // 24 ساعة
