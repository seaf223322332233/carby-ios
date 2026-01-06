# ⚠️ مهم جداً: إعداد Codemagic

## ❌ المشكلة الحالية:

Codemagic يحاول تثبيت Flutter dependencies، لكن هذا مشروع **Capacitor** وليس Flutter.

## ✅ الحل:

### الخطوة 1: تغيير Build Configuration في Codemagic

1. اذهب إلى **Codemagic** > **Settings**
2. **Build configuration**
3. **غيّر من "Flutter" إلى "Advanced"**
4. اختر: **codemagic.yaml**
5. **Branch:** main
6. **Save**

### الخطوة 2: إعداد App Store Connect Integration

1. **Settings** > **Integrations**
2. **App Store Connect** > **Add integration**
3. أدخل:
   - **Integration name:** `codemagic`
   - **Key ID**
   - **Issuer ID**
   - **Private key** (محتوى ملف .p8)
4. **Save**

### الخطوة 3: إعادة تشغيل البناء

1. **Start new build**
2. اختر **Branch:** main
3. **Start build**

---

## 📋 قائمة التحقق:

- [ ] Build configuration: **Advanced** (ليس Flutter!)
- [ ] Configuration file: `codemagic.yaml`
- [ ] Branch: main
- [ ] App Store Connect Integration مُعد
- [ ] Integration name: `codemagic`

---

## 🆘 إذا استمر الخطأ:

### "Failed to install dependencies for pubspec file"

**السبب:** Codemagic ما زال يستخدم Flutter configuration.

**الحل:**
1. **Settings** > **Build configuration**
2. تأكد من اختيار **"Advanced"** وليس **"Flutter"**
3. **Save**
4. أعد تشغيل البناء

---

**⚠️ بدون تغيير Build configuration إلى Advanced، لن يعمل البناء!**

