importScripts('https://www.gstatic.com/firebasejs/9.0.0/firebase-app-sw.js');
importScripts('https://www.gstatic.com/firebasejs/9.0.0/firebase-messaging-sw.js');

firebase.initializeApp({
  apiKey: "YOUR_API_KEY",
  projectId: "YOUR_PROJECT_ID",
  messagingSenderId: "YOUR_SENDER_ID",
  appId: "YOUR_APP_ID"
});

const messaging = firebase.messaging();

// هذه الدالة تعمل والموقع مغلق تماماً
messaging.onBackgroundMessage((payload) => {
  const notificationTitle = payload.notification.title;
  const notificationOptions = {
    body: payload.notification.body,
    icon: payload.notification.image || 'uploads/logo.png',
    data: { url: payload.data.link }
  };

  self.registration.showNotification(notificationTitle, notificationOptions);
});