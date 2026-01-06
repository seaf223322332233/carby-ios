# ⚠️ مهم جداً: إصلاح مشكلة Codemagic

## ❌ المشكلة الحالية:

```
Workflow: Default Workflow
Flutter tag: 3.40.0-0.2.pre
== Install Flutter dependencies ==
Failed to install dependencies for pubspec file
```

**السبب:** Codemagic ما زال يستخدم **"Default Workflow"** (Flutter) بدلاً من `codemagic.yaml`.

---

## ✅ الحل (يجب تنفيذه في Codemagic):

### الخطوة 1: فتح Codemagic Settings

1. اذهب إلى: https://codemagic.io
2. سجل الدخول
3. اختر تطبيق **carby-ios**
4. اضغط على **"Settings"** (أيقونة الترس ⚙️ في الأعلى)

### الخطوة 2: تغيير Build Configuration

1. في القائمة الجانبية، اضغط على **"Build configuration"**
2. ستجد قسم **"Configuration type"** أو **"Build configuration"**
3. **غيّر من "Flutter" إلى "Advanced"** أو **"codemagic.yaml"**
4. في حقل **"Configuration file"**:
   - **File path:** `codemagic.yaml`
   - **Branch:** `main`
5. اضغط **"Save"** أو **"Update"**

### الخطوة 3: التحقق من التغيير

بعد الحفظ، يجب أن ترى:
- ✅ **Configuration type:** Advanced (ليس Flutter!)
- ✅ **Configuration file:** codemagic.yaml
- ✅ **Branch:** main

### الخطوة 4: إعداد App Store Connect Integration

1. في **Settings**، اضغط على **"Integrations"**
2. ابحث عن **"App Store Connect"**
3. اضغط **"Add integration"** أو **"Connect"**
4. أدخل:
   - **Integration name:** `codemagic` (يجب أن يكون هذا الاسم بالضبط!)
   - **Key ID:** (من App Store Connect)
   - **Issuer ID:** (من App Store Connect)
   - **Private key:** (محتوى ملف .p8)
5. اضغط **"Save"**

### الخطوة 5: إعادة تشغيل البناء

1. اذهب إلى صفحة **"Builds"**
2. اضغط **"Start new build"**
3. اختر:
   - **Branch:** `main`
   - **Workflow:** `ios-workflow` (يجب أن يظهر الآن!)
4. اضغط **"Start build"**

---

## 🔍 كيف تعرف أن الإعدادات صحيحة؟

### ✅ إعدادات صحيحة (بعد التغيير):

- **Workflow:** `ios-workflow` أو `iOS Workflow - تطبيق وجباتي`
- **Configuration:** `Advanced` أو `codemagic.yaml`
- **لا يوجد:** `Flutter tag` أو `Flutter channel`

### ❌ إعدادات خاطئة (قبل التغيير):

- **Workflow:** `Default Workflow`
- **Configuration:** `Flutter`
- **يظهر:** `Flutter tag: 3.40.0-0.2.pre`
- **يظهر:** `== Install Flutter dependencies ==`

---

## 📋 قائمة التحقق:

قبل إعادة تشغيل البناء:

- [ ] ✅ Build configuration: **Advanced** (ليس Flutter!)
- [ ] ✅ Configuration file: `codemagic.yaml`
- [ ] ✅ Branch: main
- [ ] ✅ App Store Connect Integration مُعد
- [ ] ✅ Integration name: `codemagic`

---

## 🆘 إذا استمر الخطأ:

### المشكلة: "Workflow: Default Workflow"

**السبب:** لم يتم تغيير Build configuration.

**الحل:**
1. **Settings** > **Build configuration**
2. تأكد من اختيار **"Advanced"** وليس **"Flutter"**
3. **Configuration file:** `codemagic.yaml`
4. **Save**
5. أعد تشغيل البناء

### المشكلة: "Flutter tag" أو "Install Flutter dependencies"

**السبب:** Codemagic ما زال يستخدم Flutter configuration.

**الحل:**
1. **Settings** > **Build configuration**
2. غيّر من **"Flutter"** إلى **"Advanced"**
3. **Save**
4. أعد تشغيل البناء

---

## 📝 ملاحظات مهمة:

1. **يجب تغيير Build configuration يدوياً في Codemagic Settings**
2. **بدون هذا التغيير، لن يعمل البناء أبداً**
3. **codemagic.yaml موجود في GitHub، لكن Codemagic لا يستخدمه تلقائياً**
4. **يجب اختيار "Advanced" configuration يدوياً**

---

## 🎯 الخطوات السريعة:

1. **Codemagic** > **Settings** > **Build configuration**
2. **غيّر من "Flutter" إلى "Advanced"**
3. **Configuration file:** `codemagic.yaml`
4. **Save**
5. **Start new build** > **main** > **Start**

---

**⚠️ بدون تغيير Build configuration إلى Advanced في Codemagic Settings، لن يعمل البناء أبداً!**

**الآن اذهب إلى Codemagic Settings وغيّر Build configuration من Flutter إلى Advanced!**

