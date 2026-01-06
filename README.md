# 📱 ملفات iOS للرفع على App Store

## ✅ هذا المجلد يحتوي على:

**ملفات iOS فقط** المطلوبة لرفع التطبيق على App Store.

---

## 📁 محتويات المجلد:

### ملفات الإعدادات:
- ✅ `capacitor.config.json` - إعدادات Capacitor
- ✅ `package.json` - معلومات المشروع
- ✅ `codemagic.yaml` - إعدادات Codemagic (للرفع التلقائي)
- ✅ `exportOptions.plist` - إعدادات تصدير iOS
- ✅ `.gitignore` - ملف استثناءات Git

### مجلدات iOS:
- ✅ `ios/` - جميع ملفات iOS (مستثنى Pods و build)
- ✅ `dist/` - ملفات التوزيع (الموقع)

---

## 🚀 كيفية الرفع على App Store:

### الطريقة 1: استخدام Codemagic (موصى به) ⭐

1. **ارفع محتويات هذا المجلد على GitHub**
2. في Codemagic:
   - أضف المشروع
   - أعد App Store Connect API Key
   - شغّل البناء
3. Codemagic سيرفع التطبيق تلقائياً على App Store Connect

### الطريقة 2: استخدام Xcode (يدوياً)

1. على Mac:
   - افتح `ios/App/App.xcworkspace` في Xcode
   - Product > Archive
   - Distribute App > App Store Connect
   - Upload

---

## 📋 معلومات التطبيق:

| المعلومة | القيمة |
|---------|--------|
| **Bundle ID** | `com.carby.wajabati` |
| **App Name** | تطبيق وجباتي - كاربي |
| **Server URL** | `https://carby.najd-almotatorh.com` |

---

## ⚠️ ملاحظات:

- هذا المجلد يحتوي على **ملفات iOS فقط**
- ملفات PHP موجودة في `dist/` (سيتم تحميلها من الخادم)
- Pods سيتم تثبيته تلقائياً عند البناء
- Build سيتم إنشاؤه تلقائياً

---

## 🔗 بعد الرفع:

1. ✅ التطبيق على App Store Connect
2. ✅ أكمل المعلومات في App Store Connect
3. ✅ Submit for Review
4. ✅ انتظر المراجعة (1-3 أيام)

---

**جاهز للرفع على App Store!** 🚀

