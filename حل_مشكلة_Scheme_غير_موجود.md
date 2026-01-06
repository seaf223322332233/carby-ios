# 🔧 حل مشكلة: Cannot initialize iOS project, no schemes available

## ❌ الخطأ:

```
Error: Cannot initialize iOS project, no schemes available for App.xcodeproj
Workflow: Default Workflow (Flutter)
```

---

## 🔍 السبب:

1. **Codemagic ما زال يستخدم Flutter configuration** بدلاً من Advanced
2. Codemagic يحاول استخدام `App.xcodeproj` بدلاً من `App.xcworkspace`
3. المشروع Capacitor يحتاج `App.xcworkspace` (بعد pod install)

---

## ✅ الحل (يجب تنفيذ الخطوتين):

### الخطوة 1: تغيير Build Configuration في Codemagic (مهم جداً!)

**⚠️ بدون هذه الخطوة، لن يعمل البناء!**

1. اذهب إلى **Codemagic** > **Settings**
2. **Build configuration**
3. **غيّر من "Flutter" إلى "Advanced"**
4. **Configuration file:** `codemagic.yaml`
5. **Branch:** `main`
6. **Save**

### الخطوة 2: التحقق من الملفات

تم تحديث `codemagic.yaml` ليتعامل مع هذه المشكلة:

- ✅ إضافة خطوة للتحقق من workspace
- ✅ استخدام المسارات الصحيحة
- ✅ التأكد من وجود scheme

---

## 📋 قائمة التحقق:

- [ ] Build configuration: **Advanced** (ليس Flutter!)
- [ ] Configuration file: `codemagic.yaml`
- [ ] Branch: main
- [ ] `ios/App/App.xcworkspace` موجود
- [ ] `ios/App/Podfile` موجود
- [ ] `exportOptions.plist` موجود

---

## 🆘 إذا استمر الخطأ:

### المشكلة: "no schemes available"

**الحل:**
1. تأكد من تغيير Build configuration إلى Advanced
2. تأكد من وجود `App.xcworkspace` (يتم إنشاؤه بعد `pod install`)
3. تأكد من وجود scheme "App" في Xcode project

### المشكلة: "Workflow: Default Workflow"

**الحل:**
1. **Settings** > **Build configuration**
2. غيّر من **"Flutter"** إلى **"Advanced"**
3. **Save**
4. أعد تشغيل البناء

---

## 📝 ملاحظات:

1. **يجب تغيير Build configuration يدوياً في Codemagic Settings**
2. **بدون هذا التغيير، سيستمر الخطأ**
3. **تم تحديث codemagic.yaml ليتعامل مع المشكلة**

---

**⚠️ أهم خطوة: غيّر Build configuration من Flutter إلى Advanced في Codemagic Settings!**

