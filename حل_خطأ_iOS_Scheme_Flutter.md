# 🔧 حل خطأ: Cannot initialize iOS project, no schemes available

## ❌ الخطأ:

```
Cannot initialize iOS project, no schemes available for App.xcodeproj
```

## 🔍 السبب:

المشروع Flutter الآن، لكن مجلد `ios` يحتوي على ملفات Capacitor القديمة (`App.xcodeproj`). 
مشروع Flutter يحتاج `Runner.xcodeproj` وليس `App.xcodeproj`.

---

## ✅ الحل:

### تم إصلاح `codemagic.yaml`:

1. ✅ إضافة خطوة `pod install` قبل البناء
2. ✅ استخدام `flutter build ipa` بشكل صحيح
3. ✅ إزالة الإشارات إلى `App.xcodeproj`

### الخطوات المطلوبة في Codemagic:

1. **تأكد من استخدام Flutter configuration** (الآن يعمل!)
2. **استخدم workflow الموجود في codemagic.yaml**
3. **أعد تشغيل البناء**

---

## 📋 قائمة التحقق:

- [x] codemagic.yaml محدث ✅
- [x] إضافة pod install ✅
- [ ] App Store Connect Integration مُعد
- [ ] Integration name: `codemagic`

---

## 🆘 إذا استمر الخطأ:

### المشكلة: "no schemes available for App.xcodeproj"

**الحل:**
1. تأكد من استخدام `codemagic.yaml` المحدث
2. تأكد من وجود `pod install` في scripts
3. أعد تشغيل البناء

---

**تم تحديث codemagic.yaml لإصلاح المشكلة!** ✅

