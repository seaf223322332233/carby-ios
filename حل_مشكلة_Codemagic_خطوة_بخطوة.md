# 🔧 حل مشكلة Codemagic - خطوة بخطوة

## ❌ المشكلة الحالية:

```
Failed to install dependencies for pubspec file
Directory was not found
Workflow: Default Workflow (Flutter)
```

**السبب:** Codemagic ما زال يستخدم **"Default Workflow"** الذي يبحث عن Flutter، لكن مشروعك هو **Capacitor**.

---

## ✅ الحل الكامل:

### الخطوة 1: فتح Codemagic Settings

1. اذهب إلى **Codemagic** (https://codemagic.io)
2. اختر تطبيق **carby-ios**
3. اضغط على **"Settings"** (أيقونة الترس ⚙️ في الأعلى)

### الخطوة 2: تغيير Build Configuration

1. في القائمة الجانبية، اضغط على **"Build configuration"** (أو **"Configuration"**)
2. ستجد قسم **"Configuration type"** أو **"Build configuration"**
3. **غيّر من "Flutter" إلى "Advanced"** أو **"codemagic.yaml"**
4. في حقل **"Configuration file"**، تأكد من:
   - **File path:** `codemagic.yaml`
   - **Branch:** `main`
5. اضغط **"Save"** أو **"Update"**

### الخطوة 3: إعداد App Store Connect Integration

**مهم جداً - بدون هذا لن يعمل البناء:**

1. في **Settings**، اضغط على **"Integrations"**
2. ابحث عن **"App Store Connect"**
3. اضغط **"Add integration"** أو **"Connect"**
4. أدخل المعلومات التالية:
   - **Integration name:** `codemagic` (يجب أن يكون هذا الاسم بالضبط!)
   - **Key ID:** (من App Store Connect)
   - **Issuer ID:** (من App Store Connect)
   - **Private key:** (انسخ محتوى ملف .p8 كاملاً)
5. اضغط **"Save"** أو **"Connect"**

### الخطوة 4: التحقق من الإعدادات

قبل إعادة تشغيل البناء، تأكد من:

- [ ] Build configuration: **Advanced** (ليس Flutter!)
- [ ] Configuration file: `codemagic.yaml`
- [ ] Branch: main
- [ ] App Store Connect Integration موجود ومُعد
- [ ] Integration name: `codemagic`

### الخطوة 5: إعادة تشغيل البناء

1. اذهب إلى صفحة **"Builds"**
2. اضغط **"Start new build"**
3. اختر:
   - **Branch:** `main`
   - **Workflow:** `ios-workflow` (يجب أن يظهر الآن!)
4. اضغط **"Start build"**

---

## 📸 كيف تعرف أن الإعدادات صحيحة؟

### ✅ إعدادات صحيحة:

- **Workflow:** `ios-workflow` أو `iOS Workflow - تطبيق وجباتي`
- **Configuration:** `Advanced` أو `codemagic.yaml`
- **لا يوجد:** `Flutter channel: stable`

### ❌ إعدادات خاطئة:

- **Workflow:** `Default Workflow`
- **Configuration:** `Flutter`
- **يظهر:** `Flutter channel: stable`

---

## 🆘 إذا استمر الخطأ:

### المشكلة 1: "Failed to install dependencies for pubspec file"

**السبب:** Codemagic ما زال يستخدم Flutter configuration.

**الحل:**
1. **Settings** > **Build configuration**
2. تأكد من اختيار **"Advanced"** وليس **"Flutter"**
3. **Save**
4. أعد تشغيل البناء

### المشكلة 2: "App Store Connect integration does not exist"

**السبب:** Integration غير مُعد أو الاسم خاطئ.

**الحل:**
1. **Settings** > **Integrations**
2. أضف App Store Connect Integration
3. اسم Integration: `codemagic` (يجب أن يكون بالضبط!)
4. **Save**

### المشكلة 3: "No workflow selected" أو "Workflow not found"

**السبب:** codemagic.yaml غير موجود أو في مكان خاطئ.

**الحل:**
1. تأكد من وجود `codemagic.yaml` في جذر المشروع
2. تأكد من أن الملف موجود في GitHub
3. في Codemagic Settings، تأكد من:
   - **Configuration file:** `codemagic.yaml`
   - **Branch:** main

---

## 📋 قائمة التحقق النهائية:

قبل إعادة تشغيل البناء:

- [ ] ✅ Build configuration: **Advanced**
- [ ] ✅ Configuration file: `codemagic.yaml`
- [ ] ✅ Branch: main
- [ ] ✅ App Store Connect Integration مُعد
- [ ] ✅ Integration name: `codemagic`
- [ ] ✅ codemagic.yaml موجود في GitHub

---

## 🎯 الخطوات السريعة:

1. **Settings** > **Build configuration** > **Advanced** > **Save**
2. **Settings** > **Integrations** > **App Store Connect** > **Add** > **Save**
3. **Start new build** > **main** > **Start**

---

**⚠️ بدون تغيير Build configuration إلى Advanced، لن يعمل البناء أبداً!**

**الآن اذهب إلى Codemagic Settings وغيّر Build configuration من Flutter إلى Advanced!**

